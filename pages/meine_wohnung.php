<?php
/**
 * Meine Wohnung – Ansicht für Mieter.
 * Rein lesend: Stammdaten, Vorauszahlungen, Zählerstände und
 * Jahresabrechnungen (mit PDF-Download) ausschließlich der
 * Wohnung, die dem Benutzerkonto zugeordnet ist.
 * Jede Abfrage ist hart auf diese wohnung_id eingeschränkt.
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';
require_once '../includes/kostenberechnung.php';
require_once '../includes/tacho.php';
$pageTitle = 'Meine Wohnung';
$basePath  = '../';

$user = aktuellerBenutzer();
if ($user['rolle'] !== 'mieter') {
    header('Location: ../index.php');
    exit;
}

$wohnungId = (int)($user['wohnung_id'] ?? 0);
$wohnung   = null;
if ($wohnungId > 0) {
    $stmt = $db->prepare("SELECT * FROM wohnungen WHERE id = ?");
    $stmt->execute([$wohnungId]);
    $wohnung = $stmt->fetch();
}

$meinTacho = null;
$tachoVon  = null;
if ($wohnung) {
    $objStmt = $db->prepare("SELECT wirtschaftsjahr_start_monat, wirtschaftsjahr_start_tag FROM objekt WHERE id=?");
    $objStmt->execute([(int)$wohnung['objekt_id']]);
    $wj = $objStmt->fetch();
    $tachoVon = aktuellerWirtschaftsjahrStart((int)($wj['wirtschaftsjahr_start_monat'] ?? 1), (int)($wj['wirtschaftsjahr_start_tag'] ?? 1));

    $tachoAlle = berechneLaufendeKosten($db, (int)$wohnung['objekt_id'], $tachoVon->format('Y-m-d'), date('Y-m-d'));
    $meinTacho = $tachoAlle[$wohnungId] ?? null;
}

$vorauszahlungen = [];
$zaehlerJahre    = [];
$abrechnungen    = [];
$meineDokumente  = [];

if ($wohnung) {
    // Vorauszahlungen
    $stmt = $db->prepare("SELECT * FROM vorauszahlungen WHERE wohnung_id = ? ORDER BY jahr DESC");
    $stmt->execute([$wohnungId]);
    $vorauszahlungen = $stmt->fetchAll();

    // Zählerstände, gruppiert nach Jahr (Anfang/Ende/Verbrauch)
    $stmt = $db->prepare("SELECT * FROM wasserablesungen WHERE wohnung_id = ? ORDER BY jahr DESC, typ");
    $stmt->execute([$wohnungId]);
    foreach ($stmt->fetchAll() as $z) {
        $j = $z['jahr'];
        if (!isset($zaehlerJahre[$j])) $zaehlerJahre[$j] = ['anfang' => null, 'ende' => null];
        if ($z['typ'] === 'Anfang') $zaehlerJahre[$j]['anfang'] = $z;
        if ($z['typ'] === 'Ende')   $zaehlerJahre[$j]['ende']   = $z;
    }

    // Jahresabrechnungen
    $stmt = $db->prepare("SELECT * FROM abrechnungen WHERE wohnung_id = ? ORDER BY zeitraum_bis DESC, id DESC");
    $stmt->execute([$wohnungId]);
    $abrechnungen = $stmt->fetchAll();

    // Für den Mieter freigegebene Dokumente dieser Wohnung
    $stmt = $db->prepare("
        SELECT d.*, dk.bezeichnung AS kategorie
        FROM dokumente d
        LEFT JOIN dokument_kategorien dk ON d.kategorie_id = dk.id
        WHERE d.wohnung_id = ? AND d.freigabe = 'mieter'
        ORDER BY d.hochgeladen_am DESC
    ");
    $stmt->execute([$wohnungId]);
    $meineDokumente = $stmt->fetchAll();
}

include '../assets/header.php';
?>
<div class="page-header"><h1>Meine Wohnung</h1></div>

<?php if (!$wohnung): ?>
<div class="card">
    <div class="alert alert-info">
        Deinem Konto ist noch keine Wohnung zugeordnet. Bitte wende dich an die Hausverwaltung.
    </div>
</div>
<?php else: ?>

<div class="dashboard-grid">
    <div class="kpi-card">
        <div class="kpi-label">Wohnung</div>
        <div class="kpi-value" style="font-size:1.4rem"><?= htmlspecialchars($wohnung['bezeichnung']) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Wohnfläche</div>
        <div class="kpi-value"><?= number_format($wohnung['wohnflaeche'], 0, ',', '.') ?> m²</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Personen</div>
        <div class="kpi-value"><?= (int)$wohnung['personen'] ?></div>
    </div>
    <div class="kpi-card accent">
        <div class="kpi-label">Aktueller Abschlag</div>
        <div class="kpi-value" style="font-size:1.5rem">
            <?php
            $aktuellerAbschlag = null;
            foreach ($vorauszahlungen as $v) {
                if ((int)$v['jahr'] === (int)date('Y')) { $aktuellerAbschlag = $v['monatlicher_abschlag']; break; }
            }
            echo $aktuellerAbschlag !== null
                ? number_format($aktuellerAbschlag, 2, ',', '.') . ' €'
                : '–';
            ?>
        </div>
        <div style="font-size:.75rem;color:var(--muted)">pro Monat, <?= date('Y') ?></div>
    </div>
</div>

<p style="color:var(--muted);font-size:.85rem;margin:-.5rem 0 1.25rem">
    Alle Angaben dienen deiner Übersicht. Falls etwas nicht stimmt, melde dich bitte bei der Hausverwaltung –
    ändern kannst du hier nichts.
</p>

<?php if ($meinTacho): ?>
<div class="card">
    <h2>🔧 Kosten-Tacho</h2>
    <p style="color:var(--muted);font-size:.85rem;margin-bottom:.5rem">
        Zeitraum: seit <?= $tachoVon->format('d.m.Y') ?> (Wirtschaftsjahresbeginn) bis heute
    </p>
    <?= kostenTachoHtml($meinTacho['kosten'], $meinTacho['vorauszahlung'], $meinTacho['prozent']) ?>
    <?= kostenTachoHinweis() ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Jahresabrechnungen</h2>
    <?php if ($abrechnungen): ?>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Zeitraum</th><th class="text-right">Gesamtkosten</th><th class="text-right">Vorauszahlungen</th><th class="text-right">Gutschrift</th><th class="text-right">Ergebnis</th><th>PDF</th></tr></thead>
        <tbody>
        <?php foreach ($abrechnungen as $a): ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($a['bezeichnung']) ?></strong><br>
                <small style="color:var(--muted)">
                    <?= date('d.m.Y', strtotime($a['zeitraum_von'])) ?> – <?= date('d.m.Y', strtotime($a['zeitraum_bis'])) ?>
                </small>
            </td>
            <td class="text-right"><?= number_format($a['gesamtkosten'], 2, ',', '.') ?> &euro;</td>
            <td class="text-right"><?= number_format($a['vorauszahlungen'], 2, ',', '.') ?> &euro;</td>
            <td class="text-right"><?= $a['gutschrift'] > 0 ? '−' . number_format($a['gutschrift'], 2, ',', '.') . ' €' : '–' ?></td>
            <td class="text-right <?= $a['saldo'] > 0 ? 'positiv' : 'negativ' ?>">
                <?= number_format(abs($a['saldo']), 2, ',', '.') ?> &euro;
                <span class="badge <?= $a['saldo'] > 0 ? 'badge-danger' : 'badge-success' ?>">
                    <?= $a['saldo'] > 0 ? 'Nachzahlung' : 'Guthaben' ?>
                </span>
            </td>
            <td><a href="../pdf/abrechnung.php?abrechnung=<?= $a['id'] ?>" target="_blank" class="btn btn-sm btn-primary">📄 Herunterladen</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine Abrechnung vorhanden.</p>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;flex-wrap:wrap">

<div class="card">
    <h2>Vorauszahlungen / Abschläge</h2>
    <?php if ($vorauszahlungen): ?>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Jahr</th><th class="text-right">Monatl. Abschlag</th><th class="text-right">Jahresbetrag (12×)</th></tr></thead>
        <tbody>
        <?php foreach ($vorauszahlungen as $v): ?>
        <tr>
            <td><?= (int)$v['jahr'] ?></td>
            <td class="text-right"><?= number_format($v['monatlicher_abschlag'], 2, ',', '.') ?> &euro;</td>
            <td class="text-right"><strong><?= number_format($v['monatlicher_abschlag'] * 12, 2, ',', '.') ?> &euro;</strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine Vorauszahlungen hinterlegt.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Zählerstände Wasser</h2>
    <?php if ($zaehlerJahre): ?>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Jahr</th><th class="text-right">Anfangsstand</th><th class="text-right">Endstand</th><th class="text-right">Verbrauch</th></tr></thead>
        <tbody>
        <?php foreach ($zaehlerJahre as $jahr => $z):
            $verbrauch = ($z['anfang'] !== null && $z['ende'] !== null)
                ? round($z['ende']['stand'] - $z['anfang']['stand'], 3) : null;
        ?>
        <tr>
            <td><?= (int)$jahr ?></td>
            <td class="text-right"><?= $z['anfang'] ? number_format($z['anfang']['stand'], 3, ',', '.') . ' m³' : '<span style="color:var(--muted)">–</span>' ?></td>
            <td class="text-right"><?= $z['ende'] ? number_format($z['ende']['stand'], 3, ',', '.') . ' m³' : '<span style="color:var(--muted)">–</span>' ?></td>
            <td class="text-right"><?= $verbrauch !== null ? '<strong>' . number_format($verbrauch, 3, ',', '.') . ' m³</strong>' : '<span style="color:var(--muted)">unvollständig</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine Zählerstände erfasst.</p>
    <?php endif; ?>
</div>

</div>

<?php if ($meineDokumente): ?>
<div class="card">
    <h2>Dokumente zu deiner Wohnung</h2>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Bezeichnung</th><th>Kategorie</th><th>Datum</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($meineDokumente as $d): ?>
        <tr>
            <td><?= htmlspecialchars($d['bezeichnung']) ?></td>
            <td><span class="badge badge-info"><?= htmlspecialchars($d['kategorie']) ?></span></td>
            <td><?= date('d.m.Y', strtotime($d['hochgeladen_am'])) ?></td>
            <td><a href="datei.php?typ=dokument&id=<?= $d['id'] ?>" target="_blank" class="btn btn-sm btn-primary">📄 Öffnen</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="card">
    <h2>Rechnung einreichen</h2>
    <p style="color:var(--muted);font-size:.9rem;margin-bottom:.75rem">
        Du hast eine Rechnung, die die Hausverwaltung betrifft (z. B. eine Auslage)?
        Reiche sie hier mit Foto oder PDF ein – sie wird geprüft und du siehst dort auch den Status.
    </p>
    <a href="einreichung.php" class="btn btn-primary">Zur Einreichung →</a>
</div>

<?php endif; ?>

<?php include '../assets/footer.php'; ?>
