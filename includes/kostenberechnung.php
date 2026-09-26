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

/**
 * Kostenanteil einer Wohnung für einen Abschnitt berechnen.
 * PERSONEN wird NICHT hier behandelt - dafür braucht es die historische
 * Personenzahl ALLER Wohnungen im Haus (siehe personenAnteilSegmentiert),
 * nicht nur der einen, deshalb wird dieser Schlüssel von den Aufrufstellen
 * (abrechnung.php, berechneLaufendeKosten) vorher gesondert behandelt.
 */
function berechneKostenanteil(
    string $schluessel,
    float $gesamtBetrag,
    float $zeitanteil,
    float $wohnflaeche,
    float $gesamtFlaeche,
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
        case 'VERBRAUCH':
            $anteil = $verbrauchAnteil; // bereits zeitlich/verbrauchsbezogen vorberechnet
            break;
        default:
            $anteil = 0;
    }
    return round($gesamtBetrag * $anteil, 2);
}

/**
 * Personenzahl einer Wohnung an einem bestimmten Datum, anhand ihrer
 * Mieterwechsel-Historie ermittelt - NICHT anhand des aktuellen Live-Werts
 * in der Wohnungen-Tabelle, der immer nur den heutigen Stand zeigt.
 * $wechselHistorie muss aufsteigend nach uebergabe_datum sortiert sein
 * (siehe ladeWechselHistorienJeWohnung).
 */
function personenAmDatum(array $wechselHistorie, DateTime $datum, int $liveWertFallback): int {
    if (empty($wechselHistorie)) {
        return $liveWertFallback;
    }
    $ersterWechsel = new DateTime($wechselHistorie[0]['uebergabe_datum']);
    if ($datum < $ersterWechsel) {
        return (int)$wechselHistorie[0]['mieter_alt_personen'];
    }
    $personen = (int)$wechselHistorie[0]['mieter_alt_personen'];
    foreach ($wechselHistorie as $wechsel) {
        if ($datum >= new DateTime($wechsel['uebergabe_datum'])) {
            $personen = (int)$wechsel['mieter_neu_personen'];
        }
    }
    return $personen;
}

/**
 * Lädt für alle übergebenen Wohnungen einmalig die komplette Mieterwechsel-
 * Historie (aufsteigend sortiert) - damit personenAmDatum()/
 * personenAnteilSegmentiert() nicht bei jedem Aufruf neu aus der
 * Datenbank lesen müssen.
 */
function ladeWechselHistorienJeWohnung(PDO $db, array $wohnungen): array {
    $historien = [];
    $stmt = $db->prepare("SELECT * FROM mieterwechsel WHERE wohnung_id=? ORDER BY uebergabe_datum ASC");
    foreach ($wohnungen as $w) {
        $stmt->execute([(int)$w['id']]);
        $historien[(int)$w['id']] = $stmt->fetchAll();
    }
    return $historien;
}

/**
 * PERSONEN-Anteil einer Wohnung an einem Abschnitt [$segVon,$segBis] eines
 * Gesamtzeitraums - korrekt über ALLE Mieterwechsel im ganzen Haus hinweg,
 * nicht nur die der betrachteten Wohnung selbst. Der einfache Ansatz (feste
 * Gesamt-Personenzahl aus dem heutigen Stand aller Wohnungen) wäre falsch,
 * sobald eine ANDERE Wohnung im Haus zwischenzeitlich einen Mieterwechsel
 * mit geänderter Personenzahl hatte und eine ältere Abrechnung neu
 * berechnet wird.
 *
 * $gesamtTageNormierung ist die Gesamtlänge des KOMPLETTEN Abrechnungs-
 * zeitraums (nicht nur dieses Abschnitts) - der Rückgabewert ist relativ
 * dazu, damit sich die Anteile mehrerer Abschnitte einer Wohnung korrekt
 * zum vollen Anteil aufsummieren.
 */
function personenAnteilSegmentiert(
    array $wohnungen,
    array $wechselHistorienJeWohnung,
    int $wohnungId,
    DateTime $segVon,
    DateTime $segBis,
    int $gesamtTageNormierung
): float {
    if ($segVon > $segBis || $gesamtTageNormierung <= 0) {
        return 0.0;
    }

    // Umbruchpunkte innerhalb des Abschnitts sammeln: der Tag NACH jedem
    // Mieterwechsel-Datum einer BELIEBIGEN Wohnung im Haus, an dem sich
    // personenAmDatum() für irgendeine Wohnung ändern könnte.
    $segBisPlus1 = (clone $segBis)->modify('+1 day');
    $punkte = [clone $segVon, $segBisPlus1];
    foreach ($wechselHistorienJeWohnung as $historie) {
        foreach ($historie as $wechsel) {
            $tagDanach = new DateTime($wechsel['uebergabe_datum']);
            $tagDanach->modify('+1 day');
            if ($tagDanach > $segVon && $tagDanach < $segBisPlus1) {
                $punkte[] = $tagDanach;
            }
        }
    }
    usort($punkte, fn($a, $b) => $a <=> $b);
    $eindeutig = [];
    foreach ($punkte as $p) {
        $eindeutig[$p->format('Y-m-d')] = $p;
    }
    $punkte = array_values($eindeutig);

    $summeAnteil = 0.0;
    for ($i = 0; $i < count($punkte) - 1; $i++) {
        $subVon = $punkte[$i];
        $subBis = (clone $punkte[$i + 1])->modify('-1 day');
        if ($subVon > $subBis) continue;

        $tageSub = tageZwischen($subVon, $subBis);

        $gesamtPersonen = 0;
        $personenWohnung = 0;
        foreach ($wohnungen as $w) {
            $wid = (int)$w['id'];
            $p = personenAmDatum($wechselHistorienJeWohnung[$wid] ?? [], $subVon, (int)$w['personen']);
            $gesamtPersonen += $p;
            if ($wid === $wohnungId) $personenWohnung = $p;
        }

        if ($gesamtPersonen > 0) {
            $summeAnteil += ($personenWohnung / $gesamtPersonen) * ($tageSub / $gesamtTageNormierung);
        }
    }

    return $summeAnteil;
}

/** Wasserverbrauch einer Wohnung im Zeitraum: letzter Stand - erster Stand */
function verbrauchImZeitraum(PDO $db, int $wohnungId, string $von, string $bis): float {
    // Letzter Stand VOR/AM Zeitraumbeginn (nicht der älteste je erfasste!) -
    // sonst würde bei mehreren historischen Ständen der Verbrauch bereits
    // abgerechneter Vorjahre nochmal mitgezählt.
    $stmt = $db->prepare("
        SELECT stand FROM wasserablesungen
        WHERE wohnung_id = ? AND datum <= ?
        ORDER BY datum DESC LIMIT 1
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
 * Vorauszahlung für einen Datumsbereich - korrekt auch über Kalenderjahres-
 * grenzen hinweg. Die Tabelle "vorauszahlungen" speichert den monatlichen
 * Abschlag je Kalenderjahr; bei einem Wirtschaftsjahr, das nicht am 1.1.
 * beginnt, kann ein Abrechnungszeitraum zwei Kalenderjahre überspannen. Ein
 * einzelner Lookup nach nur einem Jahr würde dann fälschlich den kompletten
 * Zeitraum mit dem Abschlag nur eines der beiden Jahre bewerten, falls sich
 * der Abschlag zwischen den Jahren geändert hat.
 */
function vorauszahlungFuerZeitraum(PDO $db, int $wohnungId, DateTime $von, DateTime $bis): float {
    $stmt = $db->prepare("SELECT COALESCE(monatlicher_abschlag,0) FROM vorauszahlungen WHERE wohnung_id=? AND jahr=?");
    $summe = 0.0;
    $aktVon = clone $von;
    while ($aktVon <= $bis) {
        $jahrEnde = new DateTime($aktVon->format('Y') . '-12-31');
        $segmentBis = $jahrEnde < $bis ? $jahrEnde : clone $bis;
        $stmt->execute([$wohnungId, (int)$aktVon->format('Y')]);
        $abschlag = (float)$stmt->fetchColumn();
        $summe += $abschlag * (tageZwischen($aktVon, $segmentBis) / 30.44);
        $aktVon = clone $segmentBis;
        $aktVon->modify('+1 day');
    }
    return round($summe, 2);
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

    $wStmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=?");
    $wStmt->execute([$objektId]);
    $wohnungen = $wStmt->fetchAll();
    $gesamtFlaeche   = array_sum(array_column($wohnungen, 'wohnflaeche'));
    $anzahlWohnungen = count($wohnungen);
    // Für PERSONEN: komplette Mieterwechsel-Historie ALLER Wohnungen einmalig
    // laden, damit die Gesamt-Personenzahl je Abschnitt historisch korrekt
    // ermittelt werden kann (siehe personenAnteilSegmentiert), nicht nur
    // anhand des heutigen Live-Standes.
    $wechselHistorienJeWohnung = ladeWechselHistorienJeWohnung($db, $wohnungen);

    $komponenten = sammleKostenkomponenten($db, $objektId, $von, $bis);
    $alleKosten            = $komponenten['alleKosten'];
    $direktKostenJeWohnung = $komponenten['direktKostenJeWohnung'];
    $verbrauchJeWohnung    = $komponenten['verbrauchJeWohnung'];
    $gesamtVerbrauch       = $komponenten['gesamtVerbrauch'];

    $ergebnis = [];

    foreach ($wohnungen as $w) {
        // Mieterwechsel-Abschnitte wie in der echten Abrechnung (wichtig für
        // die Zuordnung von Kosten/Zeitanteilen bei einem Wechsel im Zeitraum)
        $wechselStmt = $db->prepare("
            SELECT * FROM mieterwechsel
            WHERE wohnung_id = ? AND uebergabe_datum BETWEEN ? AND ?
            ORDER BY uebergabe_datum ASC
        ");
        $wechselStmt->execute([$w['id'], $von, $bis]);
        $wechselliste = $wechselStmt->fetchAll();

        if (empty($wechselliste)) {
            $abschnitte = [[
                'von' => clone $vonDt, 'bis' => clone $bisDt, 'zeitanteil' => 1.0,
            ]];
        } else {
            $abschnitte = [];
            $aktVon = clone $vonDt;
            foreach ($wechselliste as $wechsel) {
                $ueberg = new DateTime($wechsel['uebergabe_datum']);
                $tage = tageZwischen($aktVon, $ueberg);
                $abschnitte[] = ['von' => clone $aktVon, 'bis' => clone $ueberg, 'zeitanteil' => $tage / $tageGesamt];
                $aktVon = clone $ueberg;
                $aktVon->modify('+1 day');
            }
            $tageRest = tageZwischen($aktVon, $bisDt);
            $abschnitte[] = ['von' => clone $aktVon, 'bis' => clone $bisDt, 'zeitanteil' => $tageRest / $tageGesamt];
        }

        $verbrauchWohnung = $verbrauchJeWohnung[$w['id']] ?? 0;
        $gesamtKosten = 0;

        foreach ($abschnitte as $abschnitt) {
            $zeitanteil = $abschnitt['zeitanteil'];
            foreach ($alleKosten as $k) {
                if ($k['schluessel'] === 'PERSONEN') {
                    $anteil = personenAnteilSegmentiert(
                        $wohnungen, $wechselHistorienJeWohnung, (int)$w['id'],
                        $abschnitt['von'], $abschnitt['bis'], $tageGesamt
                    );
                    $gesamtKosten += round($k['betrag'] * $anteil, 2);
                    continue;
                }
                $verbrauchAnteilWohnung = $gesamtVerbrauch > 0
                    ? ($verbrauchWohnung * $zeitanteil) / $gesamtVerbrauch
                    : 0;
                $gesamtKosten += berechneKostenanteil(
                    $k['schluessel'], $k['betrag'], $zeitanteil,
                    $w['wohnflaeche'], $gesamtFlaeche,
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
        // (korrekt auch über Kalenderjahresgrenzen hinweg, siehe vorauszahlungFuerZeitraum)
        $vorauszahlung = vorauszahlungFuerZeitraum($db, (int)$w['id'], $vonDt, $bisDt);

        $ergebnis[$w['id']] = [
            'kosten'         => round($gesamtKosten, 2),
            'vorauszahlung'  => $vorauszahlung,
            'prozent'        => $vorauszahlung > 0 ? round($gesamtKosten / $vorauszahlung * 100, 1) : ($gesamtKosten > 0 ? 999.0 : 0.0),
        ];
    }

    return $ergebnis;
}
