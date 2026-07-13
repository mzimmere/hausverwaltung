<?php
/**
 * Gemeinsame Kostenberechnung – von der echten Jahresabrechnung
 * (pages/abrechnung.php) UND vom laufenden Kosten-Tacho verwendet,
 * damit beide garantiert dieselben Zahlen liefern und nicht
 * auseinanderlaufen.
 */

/**
 * Ermittelt den Beginn des aktuell laufenden Wirtschaftsjahres für ein Haus.
 * Beispiel: Wirtschaftsjahr beginnt am 01.07. → am 10.03.2026 wäre der
 * aktuelle Start der 01.07.2025 (letzter Beginn, der nicht in der Zukunft liegt).
 * Bei Standard 01.01. entspricht das einfach dem Kalenderjahresbeginn.
 */
function aktuellerWirtschaftsjahrStart(int $startMonat, int $startTag, ?DateTime $referenz = null): DateTime {
    $referenz = $referenz ?? new DateTime('today');
    $jahr = (int)$referenz->format('Y');
    $kandidat = DateTime::createFromFormat('Y-n-j', "$jahr-$startMonat-$startTag");
    if ($kandidat > $referenz) {
        $kandidat = DateTime::createFromFormat('Y-n-j', ($jahr - 1) . "-$startMonat-$startTag");
    }
    return $kandidat;
}

/**
 * Anfang und Ende des Wirtschaftsjahres, das im angegebenen Kalenderjahr
 * ENDET (gleiche Konvention wie in abrechnung.php: eine Abrechnung wird im
 * "Endjahr" eingeordnet). Bei Standard 01.01. ist das schlicht Jahresanfang
 * bis Jahresende. Bei z.B. Start 01.07. wäre für $jahr=2026: 01.07.2025 –
 * 30.06.2026.
 */
function wirtschaftsjahrZeitraumFuerJahr(int $startMonat, int $startTag, int $jahr): array {
    $startJahr = ($startMonat === 1 && $startTag === 1) ? $jahr : $jahr - 1;
    $von = DateTime::createFromFormat('Y-n-j', "$startJahr-$startMonat-$startTag");
    $bis = (clone $von)->modify('+1 year')->modify('-1 day');
    return [$von, $bis];
}

/** Anzahl Tage zwischen zwei Daten, beide Tage inklusive */
function tageZwischen(DateTime $von, DateTime $bis): int {
    return (int)$von->diff($bis)->days + 1;
}

/** Kostenanteil einer Wohnung für einen Abschnitt berechnen */
function berechneKostenanteil(
    string $schluessel,
    float $gesamtBetrag,
    float $zeitanteil,
    float $wohnflaeche,
    float $gesamtFlaeche,
    int $personen,
    int $gesamtPersonen,
    float $verbrauchAnteil,   // bereits 0..1
    int $anzahlWohnungen
): float {
    switch ($schluessel) {
        case 'WOHNFLAECHE':
            $anteil = ($gesamtFlaeche > 0 ? $wohnflaeche / $gesamtFlaeche : 0) * $zeitanteil;
            break;
        case 'GLEICHANTEIL':
            $anteil = (1 / max(1, $anzahlWohnungen)) * $zeitanteil;
            break;
        case 'PERSONEN':
            $anteil = ($gesamtPersonen > 0 ? $personen / $gesamtPersonen : 0) * $zeitanteil;
            break;
        case 'VERBRAUCH':
            $anteil = $verbrauchAnteil; // bereits zeitlich/verbrauchsbezogen vorberechnet
            break;
        default:
            $anteil = 0;
    }
    return round($gesamtBetrag * $anteil, 2);
}

/** Wasserverbrauch einer Wohnung im Zeitraum: letzter Stand - erster Stand */
function verbrauchImZeitraum(PDO $db, int $wohnungId, string $von, string $bis): float {
    $stmt = $db->prepare("
        SELECT stand FROM wasserablesungen
        WHERE wohnung_id = ? AND datum <= ?
        ORDER BY datum ASC LIMIT 1
    ");
    $stmt->execute([$wohnungId, $von]);
    $anfangVorher = $stmt->fetchColumn();

    $stmt2 = $db->prepare("
        SELECT stand FROM wasserablesungen
        WHERE wohnung_id = ? AND datum >= ?
        ORDER BY datum ASC LIMIT 1
    ");
    $stmt2->execute([$wohnungId, $von]);
    $anfangNachher = $stmt2->fetchColumn();

    $anfang = $anfangVorher !== false ? $anfangVorher : $anfangNachher;

    $stmt3 = $db->prepare("
        SELECT stand FROM wasserablesungen
        WHERE wohnung_id = ? AND datum <= ?
        ORDER BY datum DESC LIMIT 1
    ");
    $stmt3->execute([$wohnungId, $bis]);
    $ende = $stmt3->fetchColumn();

    if ($anfang === false || $ende === false) return 0.0;
    return max(0, (float)$ende - (float)$anfang);
}

/**
 * Sammelt alle Kostenkomponenten für einen Zeitraum eines Objekts:
 *   - alleKosten: umzulegende Kosten auf Kostenart-Ebene (mit Umlageschlüssel)
 *   - direktKostenJeWohnung: direkt/gruppen-zugeordnete Rechnungen UND
 *     wiederkehrende Gruppenkosten, bereits zeitanteilig auf Überlappung
 *     mit dem Zeitraum umgerechnet
 *   - verbrauchJeWohnung / gesamtVerbrauch: Wasserverbrauch im Zeitraum
 */
function sammleKostenkomponenten(PDO $db, int $objektId, string $von, string $bis): array {
    $kostenStmt = $db->prepare("
        SELECT r.betrag, k.id AS kid, k.bezeichnung, u.code AS schluessel
        FROM rechnungen r
        JOIN kostenarten k ON r.kostenart_id = k.id
        JOIN umlageschluessel u ON k.umlageschluessel_id = u.id
        WHERE r.datum BETWEEN ? AND ? AND r.wohnung_id IS NULL AND r.objekt_id = ?
          AND r.id NOT IN (SELECT DISTINCT rechnung_id FROM rechnung_wohnungen)
    ");
    $kostenStmt->execute([$von, $bis, $objektId]);
    $alleKosten = $kostenStmt->fetchAll();

    // Direkt zugeordnete Kosten je Wohnung (z.B. eigener Grundsteuerbescheid)
    $direktStmt = $db->prepare("
        SELECT r.wohnung_id, r.betrag, k.bezeichnung
        FROM rechnungen r
        JOIN kostenarten k ON r.kostenart_id = k.id
        WHERE r.datum BETWEEN ? AND ? AND r.wohnung_id IS NOT NULL AND r.objekt_id = ?
    ");
    $direktStmt->execute([$von, $bis, $objektId]);
    $direktKostenJeWohnung = [];
    foreach ($direktStmt->fetchAll() as $dk) {
        $direktKostenJeWohnung[$dk['wohnung_id']][] = [
            'bezeichnung' => $dk['bezeichnung'] . ' (direkt zugeordnet)',
            'betrag' => $dk['betrag'],
        ];
    }

    // Gruppen-zugeordnete Rechnungen (mehrere ausgewählte Wohnungen, freier Anteil)
    $gruppeStmt = $db->prepare("
        SELECT rw.wohnung_id, rw.anteil, r.betrag, k.bezeichnung
        FROM rechnung_wohnungen rw
        JOIN rechnungen r ON rw.rechnung_id = r.id
        JOIN kostenarten k ON r.kostenart_id = k.id
        WHERE r.datum BETWEEN ? AND ? AND r.objekt_id = ?
    ");
    $gruppeStmt->execute([$von, $bis, $objektId]);
    foreach ($gruppeStmt->fetchAll() as $gk) {
        $anteilLabel = ((float)$gk['anteil'] >= 1.0)
            ? '(voller Betrag)'
            : '(Gruppe, ' . round($gk['anteil']*100,1) . '%)';
        $direktKostenJeWohnung[$gk['wohnung_id']][] = [
            'bezeichnung' => $gk['bezeichnung'] . ' ' . $anteilLabel,
            'betrag' => round((float)$gk['betrag'] * (float)$gk['anteil'], 2),
        ];
    }

    // Wiederkehrende Gruppenkosten (z.B. Hausmeister EG+OG, dauerhaft) – zeitanteilig nach Überlappung
    $wkStmt = $db->prepare("
        SELECT wk.*, k.bezeichnung AS kostenart_bez
        FROM wiederkehrende_kosten wk
        JOIN kostenarten k ON wk.kostenart_id = k.id
        WHERE wk.aktiv = 1 AND wk.objekt_id = ?
    ");
    $wkStmt->execute([$objektId]);
    foreach ($wkStmt->fetchAll() as $wk) {
        $wkVon = new DateTime(max($wk['gueltig_von'], $von));
        $wkBis = $wk['gueltig_bis'] ? new DateTime(min($wk['gueltig_bis'], $bis)) : new DateTime($bis);
        if ($wkVon > $wkBis) continue;

        $tageWk = tageZwischen($wkVon, $wkBis);
        $gesamtbetragWk = round((float)$wk['betrag_pro_monat'] * ($tageWk / 30.44), 2);

        $beteiligte = $db->prepare("SELECT wohnung_id, anteil FROM wiederkehrende_kosten_wohnungen WHERE wiederkehrende_kosten_id=?");
        $beteiligte->execute([$wk['id']]);
        foreach ($beteiligte->fetchAll() as $b) {
            $direktKostenJeWohnung[$b['wohnung_id']][] = [
                'bezeichnung' => $wk['bezeichnung'] . ' (' . $wk['kostenart_bez'] . ')',
                'betrag' => round($gesamtbetragWk * (float)$b['anteil'], 2),
            ];
        }
    }

    $wStmt = $db->prepare("SELECT id FROM wohnungen WHERE aktiv=1 AND objekt_id=?");
    $wStmt->execute([$objektId]);
    $wohnungIds = $wStmt->fetchAll(PDO::FETCH_COLUMN);

    $verbrauchJeWohnung = [];
    $gesamtVerbrauch = 0;
    foreach ($wohnungIds as $wid) {
        $v = verbrauchImZeitraum($db, (int)$wid, $von, $bis);
        $verbrauchJeWohnung[$wid] = $v;
        $gesamtVerbrauch += $v;
    }

    return [
        'alleKosten' => $alleKosten,
        'direktKostenJeWohnung' => $direktKostenJeWohnung,
        'verbrauchJeWohnung' => $verbrauchJeWohnung,
        'gesamtVerbrauch' => $gesamtVerbrauch,
    ];
}

/**
 * Kosten-Tacho: anteilige LAUFENDE Kosten je Wohnung seit einem Startdatum
 * (i. d. R. 1.1. des laufenden Jahres) bis heute, verglichen mit der bislang
 * geleisteten Vorauszahlung. Bewusst OHNE Heizkosten (die kommen erst mit
 * dem separaten Heizkosten-Import zur echten Jahresabrechnung) und OHNE
 * Anspruch auf zeitgenaue Cent-Genauigkeit einer echten Abrechnung – dient
 * nur als laufende Orientierung, nicht als verbindliche Abrechnung.
 *
 * Nutzt dieselben Formeln (berechneKostenanteil, Gutschriften-Überlappung,
 * Vorauszahlungs-Hochrechnung) wie pages/abrechnung.php.
 */
function berechneLaufendeKosten(PDO $db, int $objektId, string $von, string $bis): array {
    $vonDt = new DateTime($von);
    $bisDt = new DateTime($bis);
    $tageGesamt = tageZwischen($vonDt, $bisDt);
    $jahr = (int)$bisDt->format('Y');

    $wStmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=?");
    $wStmt->execute([$objektId]);
    $wohnungen = $wStmt->fetchAll();
    $gesamtFlaeche   = array_sum(array_column($wohnungen, 'wohnflaeche'));
    $anzahlWohnungen = count($wohnungen);
    $gesamtPersonen  = array_sum(array_column($wohnungen, 'personen'));

    $komponenten = sammleKostenkomponenten($db, $objektId, $von, $bis);
    $alleKosten            = $komponenten['alleKosten'];
    $direktKostenJeWohnung = $komponenten['direktKostenJeWohnung'];
    $verbrauchJeWohnung    = $komponenten['verbrauchJeWohnung'];
    $gesamtVerbrauch       = $komponenten['gesamtVerbrauch'];

    $ergebnis = [];

    foreach ($wohnungen as $w) {
        // Mieterwechsel-Abschnitte wie in der echten Abrechnung (wichtig für
        // Umlageschlüssel PERSONEN, falls im Zeitraum ein Wechsel stattfand)
        $wechselStmt = $db->prepare("
            SELECT * FROM mieterwechsel
            WHERE wohnung_id = ? AND uebergabe_datum BETWEEN ? AND ?
            ORDER BY uebergabe_datum ASC
        ");
        $wechselStmt->execute([$w['id'], $von, $bis]);
        $wechselliste = $wechselStmt->fetchAll();

        if (empty($wechselliste)) {
            $abschnitte = [[
                'personen'   => $w['personen'],
                'zeitanteil' => 1.0,
            ]];
        } else {
            $abschnitte = [];
            $aktVon = clone $vonDt;
            foreach ($wechselliste as $wechsel) {
                $ueberg = new DateTime($wechsel['uebergabe_datum']);
                $tage = tageZwischen($aktVon, $ueberg);
                $abschnitte[] = ['personen' => $wechsel['mieter_alt_personen'], 'zeitanteil' => $tage / $tageGesamt];
                $aktVon = clone $ueberg;
                $aktVon->modify('+1 day');
            }
            $tageRest = tageZwischen($aktVon, $bisDt);
            $letzter = end($wechselliste);
            $abschnitte[] = ['personen' => $letzter['mieter_neu_personen'], 'zeitanteil' => $tageRest / $tageGesamt];
        }

        $verbrauchWohnung = $verbrauchJeWohnung[$w['id']] ?? 0;
        $gesamtKosten = 0;

        foreach ($abschnitte as $abschnitt) {
            $zeitanteil = $abschnitt['zeitanteil'];
            foreach ($alleKosten as $k) {
                $verbrauchAnteilWohnung = $gesamtVerbrauch > 0
                    ? ($verbrauchWohnung * $zeitanteil) / $gesamtVerbrauch
                    : 0;
                $gesamtKosten += berechneKostenanteil(
                    $k['schluessel'], $k['betrag'], $zeitanteil,
                    $w['wohnflaeche'], $gesamtFlaeche,
                    $abschnitt['personen'], $gesamtPersonen,
                    $verbrauchAnteilWohnung, $anzahlWohnungen
                );
            }
        }

        // Direkt/Gruppen/wiederkehrende Kosten: liegen bereits als Ganzes im Zeitraum
        foreach (($direktKostenJeWohnung[$w['id']] ?? []) as $dk) {
            $gesamtKosten += (float)$dk['betrag'];
        }

        // Gutschriften abziehen (zeitanteilig nach Überlappung mit dem Zeitraum)
        $gsStmt = $db->prepare("
            SELECT * FROM gutschriften
            WHERE wohnung_id = ? AND aktiv = 1
              AND gueltig_von <= ? AND (gueltig_bis IS NULL OR gueltig_bis >= ?)
        ");
        $gsStmt->execute([$w['id'], $bis, $von]);
        foreach ($gsStmt->fetchAll() as $gs) {
            $gsVon = new DateTime(max($gs['gueltig_von'], $von));
            $gsBis = $gs['gueltig_bis'] ? new DateTime(min($gs['gueltig_bis'], $bis)) : clone $bisDt;
            if ($gsVon > $gsBis) continue;
            $tageUeberlappung = tageZwischen($gsVon, $gsBis);
            $gesamtKosten -= round((float)$gs['betrag_pro_monat'] * ($tageUeberlappung / 30.44), 2);
        }

        // Bislang geleistete Vorauszahlung, zeitanteilig über den ganzen Zeitraum hochgerechnet
        $v = $db->prepare("SELECT COALESCE(monatlicher_abschlag,0) FROM vorauszahlungen WHERE wohnung_id=? AND jahr=?");
        $v->execute([$w['id'], $jahr]);
        $abschlag = (float)$v->fetchColumn();
        $vorauszahlung = round($abschlag * ($tageGesamt / 30.44), 2);

        $ergebnis[$w['id']] = [
            'kosten'         => round($gesamtKosten, 2),
            'vorauszahlung'  => $vorauszahlung,
            'prozent'        => $vorauszahlung > 0 ? round($gesamtKosten / $vorauszahlung * 100, 1) : ($gesamtKosten > 0 ? 999.0 : 0.0),
        ];
    }

    return $ergebnis;
}
