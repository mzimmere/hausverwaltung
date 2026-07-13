<?php
/**
 * Jahresabrechnung mit FREI WÄHLBAREM Abrechnungszeitraum.
 * Unterstützt: Kalenderjahr, Wirtschaftsjahr, beliebige Zeiträume,
 * sowie Mieterwechsel innerhalb des gewählten Zeitraums.
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';

$user = aktuellerBenutzer();
if (!in_array($user['rolle'], ['admin', 'leser'], true)) {
    header('Location: ../index.php');
    exit;
}

$pageTitle = 'Abrechnung';
$basePath  = '../';

$objektId = aktivesObjekt();

require_once '../includes/kostenberechnung.php';

// ── Eigene Zeitraum-Vorlagen (aus DB, vom Nutzer verwaltet) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage_speichern'])) {
    leserSchreibschutz();
    csrfPruefen();
    $vLabel = trim($_POST['vorlage_label']);
    $vVon   = $_POST['vorlage_von'];
    $vBis   = $_POST['vorlage_bis'];
    if ($vLabel && $vVon && $vBis) {
        $maxSort = (int)$db->query("SELECT COALESCE(MAX(sortierung),0) FROM zeitraum_vorlagen WHERE objekt_id=$objektId")->fetchColumn();
        $db->prepare("INSERT INTO zeitraum_vorlagen (objekt_id, label, von_datum, bis_datum, sortierung) VALUES (?,?,?,?,?)")
           ->execute([$objektId, $vLabel, $vVon, $vBis, $maxSort + 1]);
        protokolliere('zeitraum_vorlagen', 'anlegen', (int)$db->lastInsertId(), "Vorlage \"$vLabel\" angelegt");
        $successMsg = "Vorlage \"$vLabel\" gespeichert.";
    }
}
if (isset($_GET['vorlage_loeschen'])) {
    leserSchreibschutz();
    $vlId = (int)$_GET['vorlage_loeschen'];
    $db->prepare("DELETE FROM zeitraum_vorlagen WHERE id=? AND objekt_id=?")->execute([$vlId, $objektId]);
    protokolliere('zeitraum_vorlagen', 'loeschen', $vlId, 'Vorlage gelöscht');
    header('Location: abrechnung.php'); exit;
}

$vStmt = $db->prepare("SELECT * FROM zeitraum_vorlagen WHERE objekt_id=? ORDER BY sortierung, id");
$vStmt->execute([$objektId]);
$vorschlaege = $vStmt->fetchAll();

// ═══════════════════════════════════════════════════════════════
// ABRECHNUNG ERSTELLEN
// ═══════════════════════════════════════════════════════════════
$berechnungsFehler = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['erstellen'])) {
    leserSchreibschutz();
    csrfPruefen();
    $zVon = $_POST['zeitraum_von'] ?? '';
    $zBis = $_POST['zeitraum_bis'] ?? '';
    $bezeichnung = trim($_POST['bezeichnung'] ?? '');

    $vonDt = DateTime::createFromFormat('Y-m-d', $zVon);
    $bisDt = DateTime::createFromFormat('Y-m-d', $zBis);

    if (!$vonDt || !$bisDt) {
        $berechnungsFehler = 'Bitte gültiges Start- und Enddatum angeben.';
    } elseif ($vonDt > $bisDt) {
        $berechnungsFehler = 'Das Startdatum muss vor dem Enddatum liegen.';
    } else {
        $tageGesamt = tageZwischen($vonDt, $bisDt);
        $anzeigeJahr = (int)$bisDt->format('Y'); // Abrechnung wird im "Endjahr" eingeordnet
        if (!$bezeichnung) {
            $bezeichnung = $vonDt->format('d.m.Y') . ' – ' . $bisDt->format('d.m.Y');
        }

        $wStmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=?");
        $wStmt->execute([$objektId]);
        $wohnungen = $wStmt->fetchAll();
        $gesamtFlaeche   = array_sum(array_column($wohnungen, 'wohnflaeche'));
        $anzahlWohnungen = count($wohnungen);
        $gesamtPersonen  = array_sum(array_column($wohnungen, 'personen'));

        // Kosten: alle Rechnungen, deren Datum im gewählten Zeitraum liegt
        // → getrennt nach: wird umgelegt / direkt einer Wohnung / Gruppe mehrerer Wohnungen
        // (Umlage-Kosten, direkte/Gruppen-Kosten, wiederkehrende Kosten, Wasserverbrauch –
        // dieselbe Logik wie beim Kosten-Tacho, aus includes/kostenberechnung.php)
        $komponenten = sammleKostenkomponenten($db, $objektId, $zVon, $zBis);
        $alleKosten            = $komponenten['alleKosten'];
        $direktKostenJeWohnung = $komponenten['direktKostenJeWohnung'];
        $verbrauchJeWohnung    = $komponenten['verbrauchJeWohnung'];
        $gesamtVerbrauch       = $komponenten['gesamtVerbrauch'];

        // Alte Abrechnungen mit identischem Zeitraum löschen (Neuberechnung)
        $alt = $db->prepare("SELECT id FROM abrechnungen WHERE zeitraum_von=? AND zeitraum_bis=? AND objekt_id=?");
        $alt->execute([$zVon, $zBis, $objektId]);
        foreach ($alt->fetchAll() as $a) {
            $db->prepare("DELETE FROM abrechnungspositionen WHERE abrechnung_id=?")->execute([$a['id']]);
            $db->prepare("DELETE FROM abrechnungen WHERE id=?")->execute([$a['id']]);
        }

        foreach ($wohnungen as $w) {

            // ── Mieterwechsel innerhalb des Zeitraums? ────────
            $wechselStmt = $db->prepare("
                SELECT * FROM mieterwechsel
                WHERE wohnung_id = ? AND uebergabe_datum BETWEEN ? AND ?
                ORDER BY uebergabe_datum ASC
            ");
            $wechselStmt->execute([$w['id'], $zVon, $zBis]);
            $wechselliste = $wechselStmt->fetchAll();

            if (empty($wechselliste)) {
                $abschnitte = [[
                    'mieter_name' => $w['mieter_name'],
                    'personen'    => $w['personen'],
                    'von'         => clone $vonDt,
                    'bis'         => clone $bisDt,
                    'zeitanteil'  => 1.0,
                ]];
            } else {
                $abschnitte = [];
                $aktVon = clone $vonDt;
                foreach ($wechselliste as $wechsel) {
                    $ueberg = new DateTime($wechsel['uebergabe_datum']);
                    $tage = tageZwischen($aktVon, $ueberg);
                    $abschnitte[] = [
                        'mieter_name' => $wechsel['mieter_alt_name'],
                        'personen'    => $wechsel['mieter_alt_personen'],
                        'von'         => clone $aktVon,
                        'bis'         => clone $ueberg,
                        'zeitanteil'  => $tage / $tageGesamt,
                    ];
                    $aktVon = clone $ueberg;
                    $aktVon->modify('+1 day');
                }
                $tageRest = tageZwischen($aktVon, $bisDt);
                $letzter = end($wechselliste);
                $abschnitte[] = [
                    'mieter_name' => $letzter['mieter_neu_name'],
                    'personen'    => $letzter['mieter_neu_personen'],
                    'von'         => clone $aktVon,
                    'bis'         => clone $bisDt,
                    'zeitanteil'  => $tageRest / $tageGesamt,
                ];
            }

            // Heizkosten im Zeitraum
            $h = $db->prepare("
                SELECT COALESCE(SUM(betrag),0) FROM heizkosten_import
                WHERE wohnung_id = ? AND jahr = ?
            ");
            $h->execute([$w['id'], $anzeigeJahr]);
            $heizkosten = (float)$h->fetchColumn();

            $verbrauchWohnung = $verbrauchJeWohnung[$w['id']];

            foreach ($abschnitte as $abschnitt) {
                $gesamtKosten = 0;
                $positionen   = [];
                $zeitanteil   = $abschnitt['zeitanteil'];

                foreach ($alleKosten as $k) {
                    $verbrauchAnteilWohnung = $gesamtVerbrauch > 0
                        ? ($verbrauchWohnung * $zeitanteil) / $gesamtVerbrauch
                        : 0;

                    $kostenanteil = berechneKostenanteil(
                        $k['schluessel'], $k['betrag'], $zeitanteil,
                        $w['wohnflaeche'], $gesamtFlaeche,
                        $abschnitt['personen'], $gesamtPersonen,
                        $verbrauchAnteilWohnung, $anzahlWohnungen
                    );
                    if ($kostenanteil != 0) {
                        $gesamtKosten += $kostenanteil;
                        $positionen[] = [
                            'kid' => $k['kid'], 'bezeichnung' => $k['bezeichnung'],
                            'betrag' => $kostenanteil,
                            'von' => $abschnitt['von']->format('Y-m-d'),
                            'bis' => $abschnitt['bis']->format('Y-m-d'),
                            'mieter' => $abschnitt['mieter_name'],
                        ];
                    }
                }

                if ($heizkosten > 0) {
                    $hzAnteil = round($heizkosten * $zeitanteil, 2);
                    $gesamtKosten += $hzAnteil;
                    $positionen[] = [
                        'kid' => null, 'bezeichnung' => 'Heizkosten (direkt)',
                        'betrag' => $hzAnteil,
                        'von' => $abschnitt['von']->format('Y-m-d'),
                        'bis' => $abschnitt['bis']->format('Y-m-d'),
                        'mieter' => $abschnitt['mieter_name'],
                    ];
                }

                // ── Nicht umlagefähige Einzelkosten dieser Wohnung ──
                // (eigener Grundsteuerbescheid, Gruppenrechnung, oder wiederkehrende Gruppenkosten
                //  wie Hausmeister nur für bestimmte Wohnungen). Werden NICHT nach Umlageschlüssel
                // verteilt, sondern fließen zeitanteilig komplett in diese eine Abrechnung ein.
                if (!empty($direktKostenJeWohnung[$w['id']])) {
                    foreach ($direktKostenJeWohnung[$w['id']] as $dk) {
                        $dkAnteil = round((float)$dk['betrag'] * $zeitanteil, 2);
                        $gesamtKosten += $dkAnteil;
                        $positionen[] = [
                            'kid' => null,
                            'bezeichnung' => $dk['bezeichnung'],
                            'betrag' => $dkAnteil,
                            'von' => $abschnitt['von']->format('Y-m-d'),
                            'bis' => $abschnitt['bis']->format('Y-m-d'),
                            'mieter' => $abschnitt['mieter_name'],
                        ];
                    }
                }

                // Vorauszahlungen: monatlicher Abschlag × Monate im Zeitraum (taggenau approximiert)
                $v = $db->prepare("SELECT COALESCE(monatlicher_abschlag,0) FROM vorauszahlungen WHERE wohnung_id=? AND jahr=?");
                $v->execute([$w['id'], $anzeigeJahr]);
                $abschlag = (float)$v->fetchColumn();
                // Monatsanteil = Tage des Abschnitts / 30,44 (Durchschnittsmonat)
                $tageAbschnitt = tageZwischen($abschnitt['von'], $abschnitt['bis']);
                $vorauszahlung = round($abschlag * ($tageAbschnitt / 30.44), 2);

                // ── Gutschriften: zeitanteilig nach Überlappung mit dem Abschnitt ──
                $gutschriftGesamt = 0;
                $gsStmt = $db->prepare("
                    SELECT * FROM gutschriften
                    WHERE wohnung_id = ? AND aktiv = 1
                      AND gueltig_von <= ?
                      AND (gueltig_bis IS NULL OR gueltig_bis >= ?)
                ");
                $gsStmt->execute([$w['id'], $abschnitt['bis']->format('Y-m-d'), $abschnitt['von']->format('Y-m-d')]);
                foreach ($gsStmt->fetchAll() as $gs) {
                    // Überlappungszeitraum: später Start, früheres Ende
                    $gsVon = new DateTime(max($gs['gueltig_von'], $abschnitt['von']->format('Y-m-d')));
                    $gsBis = $gs['gueltig_bis']
                        ? new DateTime(min($gs['gueltig_bis'], $abschnitt['bis']->format('Y-m-d')))
                        : clone $abschnitt['bis'];
                    if ($gsVon > $gsBis) continue; // keine echte Überlappung

                    $tageUeberlappung = tageZwischen($gsVon, $gsBis);
                    $gsBetrag = round((float)$gs['betrag_pro_monat'] * ($tageUeberlappung / 30.44), 2);
                    $gutschriftGesamt += $gsBetrag;

                    $positionen[] = [
                        'kid' => null,
                        'bezeichnung' => 'Gutschrift: ' . $gs['bezeichnung'],
                        'betrag' => -$gsBetrag, // negativ = Gutschrift
                        'von' => $gsVon->format('Y-m-d'),
                        'bis' => $gsBis->format('Y-m-d'),
                        'mieter' => $abschnitt['mieter_name'],
                        'ist_gutschrift' => 1,
                    ];
                }

                $saldo = round($gesamtKosten - $vorauszahlung - $gutschriftGesamt, 2);

                $ins = $db->prepare("
                    INSERT INTO abrechnungen
                        (objekt_id, wohnung_id, jahr, zeitraum_von, zeitraum_bis, bezeichnung,
                         gesamtkosten, vorauszahlungen, gutschrift, saldo)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ");
                $ins->execute([
                    $objektId, $w['id'], $anzeigeJahr, $abschnitt['von']->format('Y-m-d'), $abschnitt['bis']->format('Y-m-d'),
                    $bezeichnung, $gesamtKosten, $vorauszahlung, $gutschriftGesamt, $saldo
                ]);
                $abrId = $db->lastInsertId();

                $insP = $db->prepare("
                    INSERT INTO abrechnungspositionen
                        (abrechnung_id, kostenart_id, kostenart, betrag, zeitraum_von, zeitraum_bis, mieter_name, ist_gutschrift)
                    VALUES (?,?,?,?,?,?,?,?)
                ");
                foreach ($positionen as $p) {
                    $insP->execute([
                        $abrId, $p['kid'], $p['bezeichnung'], $p['betrag'], $p['von'], $p['bis'], $p['mieter'],
                        $p['ist_gutschrift'] ?? 0
                    ]);
                }
            }
        }
        protokolliere('abrechnung', 'anlegen', null, "Abrechnung \"$bezeichnung\" berechnet ($zVon bis $zBis)");
        $successMsg = "Abrechnung \"$bezeichnung\" wurde berechnet ($tageGesamt Tage).";
    }
}

// ── Filter: alle vorhandenen Zeiträume zur Auswahl ───────────
$vzStmt = $db->prepare("
    SELECT DISTINCT zeitraum_von, zeitraum_bis, bezeichnung
    FROM abrechnungen
    WHERE objekt_id = ?
    ORDER BY zeitraum_von DESC
");
$vzStmt->execute([$objektId]);
$vorhandeneZeitraeume = $vzStmt->fetchAll();

$filterVon = $_GET['von'] ?? ($vorhandeneZeitraeume[0]['zeitraum_von'] ?? date('Y') . '-01-01');
$filterBis = $_GET['bis'] ?? ($vorhandeneZeitraeume[0]['zeitraum_bis'] ?? date('Y') . '-12-31');

$abrechnungen = $db->prepare("
    SELECT a.*, w.bezeichnung AS wohnung_bez, w.mieter_name, w.wohnflaeche
    FROM abrechnungen a JOIN wohnungen w ON a.wohnung_id = w.id
    WHERE a.zeitraum_von = ? AND a.zeitraum_bis = ? AND a.objekt_id = ?
    ORDER BY w.id, a.id
");
$abrechnungen->execute([$filterVon, $filterBis, $objektId]);
$abrechnungen = $abrechnungen->fetchAll();

include '../assets/header.php';
?>

<div class="page-header">
    <h1>Abrechnung</h1>
</div>

<?php if ($berechnungsFehler): ?>
<div class="alert alert-error"><?= htmlspecialchars($berechnungsFehler) ?></div>
<?php endif; ?>

<?php if (!istNurLesend()): ?>
<div class="card">
    <h2>Neue Abrechnung erstellen</h2>

    <div style="margin-bottom:1.25rem">
        <label style="font-size:.85rem;font-weight:600;color:var(--muted);display:block;margin-bottom:.5rem">
            Schnellauswahl
        </label>
        <div class="btn-group">
            <?php foreach ($vorschlaege as $v): ?>
            <span style="display:inline-flex;align-items:center;gap:2px">
                <button type="button" class="btn btn-sm" style="background:#e2e8f0;color:#333"
                    onclick="document.getElementById('zv').value='<?= $v['von_datum'] ?>';document.getElementById('zb').value='<?= $v['bis_datum'] ?>';document.getElementById('bez').value='<?= htmlspecialchars($v['label']) ?>';">
                    <?= htmlspecialchars($v['label']) ?>
                </button>
                <a href="?vorlage_loeschen=<?= $v['id'] ?>" onclick="return confirm('Vorlage \'<?= htmlspecialchars($v['label']) ?>\' löschen?')"
                   style="color:var(--danger);text-decoration:none;font-size:.8rem;padding:0 .3rem" title="Vorlage löschen">✕</a>
            </span>
            <?php endforeach; ?>
        </div>

        <details style="margin-top:.75rem">
            <summary style="cursor:pointer;font-size:.85rem;color:var(--primary)">+ Eigene Vorlage anlegen</summary>
            <form method="post" style="margin-top:.75rem;padding:1rem;background:#f8fafc;border-radius:8px">
                <?= csrfFeld() ?>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Name der Vorlage *</label>
                        <input type="text" name="vorlage_label" placeholder="z.B. Wirtschaftsjahr 24/25" required>
                    </div>
                    <div class="form-group">
                        <label>Von *</label>
                        <input type="date" name="vorlage_von" required>
                    </div>
                    <div class="form-group">
                        <label>Bis *</label>
                        <input type="date" name="vorlage_bis" required>
                    </div>
                </div>
                <div style="margin-top:.75rem">
                    <button type="submit" name="vorlage_speichern" class="btn btn-sm btn-primary">Vorlage speichern</button>
                </div>
                <p style="margin-top:.5rem;color:var(--muted);font-size:.8rem">
                    Die Vorlage merkt sich genau dieses Datum als Button für später – praktisch für wiederkehrende
                    Zeiträume wie ein individuelles Wirtschaftsjahr. Im nächsten Jahr legst du einfach eine neue an.
                </p>
            </form>
        </details>
    </div>

    <form method="post">
        <?= csrfFeld() ?>
        <div class="form-grid">
            <div class="form-group">
                <label>Zeitraum von *</label>
                <input type="date" id="zv" name="zeitraum_von" value="<?= date('Y') - 1 ?>-01-01" required>
            </div>
            <div class="form-group">
                <label>Zeitraum bis *</label>
                <input type="date" id="zb" name="zeitraum_bis" value="<?= date('Y') - 1 ?>-12-31" required>
            </div>
            <div class="form-group">
                <label>Bezeichnung (optional)</label>
                <input type="text" id="bez" name="bezeichnung" placeholder="wird automatisch erzeugt wenn leer">
            </div>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" name="erstellen" class="btn btn-accent">Abrechnung berechnen</button>
        </div>
        <p style="margin-top:.75rem;color:var(--muted);font-size:.85rem">
            Beliebiger Zeitraum möglich – Kalenderjahr, Wirtschaftsjahr, Teiljahr beim Einzug usw.
            Kosten werden anhand des Rechnungsdatums dem Zeitraum zugeordnet.
        </p>
    </form>
</div>
<?php endif; ?>

<?php if ($vorhandeneZeitraeume): ?>
<div class="card">
    <h2>Vorhandene Abrechnungszeiträume</h2>
    <div class="btn-group">
        <?php foreach ($vorhandeneZeitraeume as $z): ?>
        <a href="?von=<?= $z['zeitraum_von'] ?>&bis=<?= $z['zeitraum_bis'] ?>"
           class="btn btn-sm <?= ($z['zeitraum_von']==$filterVon && $z['zeitraum_bis']==$filterBis) ? 'btn-primary' : '' ?>"
           style="<?= !($z['zeitraum_von']==$filterVon && $z['zeitraum_bis']==$filterBis) ? 'background:#e2e8f0;color:#333' : '' ?>">
            <?= htmlspecialchars($z['bezeichnung']) ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($abrechnungen): ?>
<div class="card">
    <h2>Ergebnis: <?= htmlspecialchars($abrechnungen[0]['bezeichnung']) ?></h2>
    <p style="color:var(--muted);margin-bottom:1rem">
        <?= date('d.m.Y', strtotime($filterVon)) ?> – <?= date('d.m.Y', strtotime($filterBis)) ?>
    </p>
    <div class="table-wrap">
    <table>
        <thead>
            <tr><th>Wohnung</th><th>Mieter / Zeitraum</th><th class="text-right">Gesamtkosten</th><th class="text-right">Vorauszahlungen</th><th class="text-right">Gutschrift</th><th class="text-right">Saldo</th><th></th></tr>
        </thead>
        <tbody>
        <?php $letzteWohnungId = null; foreach ($abrechnungen as $a):
            if ($a['wohnung_id'] !== $letzteWohnungId):
                $letzteWohnungId = $a['wohnung_id'];
        ?>
        <tr style="background:#e8f0f8">
            <td colspan="6" style="font-weight:700;color:var(--primary);padding:.5rem 1rem">
                🏠 Wohnung <?= htmlspecialchars($a['wohnung_bez']) ?> (<?= number_format($a['wohnflaeche'],0,',','.') ?> m²)
            </td>
        </tr>
        <?php endif; ?>
        <tr>
            <td></td>
            <td>
                <strong><?= htmlspecialchars($a['mieter_name'] ?? '') ?></strong><br>
                <small style="color:var(--muted)">
                    <?= date('d.m.Y', strtotime($a['zeitraum_von'])) ?> – <?= date('d.m.Y', strtotime($a['zeitraum_bis'])) ?>
                </small>
            </td>
            <td class="text-right"><?= number_format($a['gesamtkosten'],2,',','.') ?> €</td>
            <td class="text-right"><?= number_format($a['vorauszahlungen'],2,',','.') ?> €</td>
            <td class="text-right" style="<?= $a['gutschrift'] > 0 ? 'color:var(--success);font-weight:700' : 'color:var(--muted)' ?>">
                <?= $a['gutschrift'] > 0 ? '−' . number_format($a['gutschrift'],2,',','.') . ' €' : '–' ?>
            </td>
            <td class="text-right <?= $a['saldo'] > 0 ? 'positiv' : 'negativ' ?>">
                <?= number_format(abs($a['saldo']),2,',','.') ?> €
                <span class="badge <?= $a['saldo'] > 0 ? 'badge-danger' : 'badge-success' ?>">
                    <?= $a['saldo'] > 0 ? 'Nachzahlung' : 'Guthaben' ?>
                </span>
            </td>
            <td><a href="../pdf/abrechnung.php?abrechnung=<?= $a['id'] ?>" class="btn btn-sm btn-primary" target="_blank">PDF</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h2>Einzelpositionen</h2>
    <?php foreach ($abrechnungen as $a):
        $pos = $db->prepare("SELECT * FROM abrechnungspositionen WHERE abrechnung_id=?");
        $pos->execute([$a['id']]);
        $positionen = $pos->fetchAll();
    ?>
    <div style="margin:1rem 0 .5rem;padding:.5rem 1rem;background:#f0f5fb;border-radius:6px">
        <strong style="color:var(--primary)"><?= htmlspecialchars($a['wohnung_bez']) ?> – <?= htmlspecialchars($a['mieter_name']) ?></strong>
        <span style="color:var(--muted);font-size:.9rem;margin-left:.5rem">
            <?= date('d.m.Y', strtotime($a['zeitraum_von'])) ?> – <?= date('d.m.Y', strtotime($a['zeitraum_bis'])) ?>
        </span>
    </div>
    <div class="table-wrap">
    <table class="sortable">
        <thead><tr><th>Kostenart</th><th class="text-right">Betrag</th></tr></thead>
        <tbody>
        <?php foreach ($positionen as $p):
            if ($p['ist_gutschrift']): ?>
        <tr style="background:#f0fff4">
            <td style="color:var(--success)">💚 <?= htmlspecialchars($p['kostenart']) ?></td>
            <td class="text-right" style="color:var(--success);font-weight:700"><?= number_format($p['betrag'],2,',','.') ?> €</td>
        </tr>
        <?php else: ?>
        <tr><td><?= htmlspecialchars($p['kostenart']) ?></td><td class="text-right"><?= number_format($p['betrag'],2,',','.') ?> €</td></tr>
        <?php endif; endforeach; ?>
        <tr style="font-weight:700;background:#f0f5fb">
            <td>Zwischensumme Kosten</td>
            <td class="text-right"><?= number_format($a['gesamtkosten'],2,',','.') ?> €</td>
        </tr>
        <tr>
            <td>Vorauszahlungen</td>
            <td class="text-right">− <?= number_format($a['vorauszahlungen'],2,',','.') ?> €</td>
        </tr>
        <?php if ($a['gutschrift'] > 0): ?>
        <tr>
            <td style="color:var(--success)">Gutschrift</td>
            <td class="text-right" style="color:var(--success)">− <?= number_format($a['gutschrift'],2,',','.') ?> €</td>
        </tr>
        <?php endif; ?>
        <tr style="font-weight:700;border-top:2px solid var(--primary)">
            <td><?= $a['saldo'] > 0 ? 'Nachzahlung' : 'Guthaben' ?></td>
            <td class="text-right <?= $a['saldo'] > 0 ? 'positiv' : 'negativ' ?>"><?= number_format(abs($a['saldo']),2,',','.') ?> €</td>
        </tr>
        </tbody>
    </table>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card">
    <p style="color:var(--muted)">Noch keine Abrechnung für diesen Zeitraum vorhanden.</p>
</div>
<?php endif; ?>

<?php include '../assets/footer.php'; ?>
