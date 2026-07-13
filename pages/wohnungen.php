<?php
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';
require_once '../includes/kostenberechnung.php';
require_once '../includes/tacho.php';

$user = aktuellerBenutzer();
if (!in_array($user['rolle'], ['admin', 'leser'], true)) {
    header('Location: ../index.php');
    exit;
}

$pageTitle = 'Wohnungen';
$basePath  = '../';

$objektId = aktivesObjekt();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'save') {
        leserSchreibschutz();
        csrfPruefen();
        if (!empty($_POST['id'])) {
            // Nur bearbeiten, wenn die Wohnung zum aktiven Objekt gehört
            $stmt = $db->prepare("UPDATE wohnungen SET bezeichnung=?,etage=?,wohnflaeche=?,mieter_name=?,mieter_seit=?,personen=? WHERE id=? AND objekt_id=?");
            $stmt->execute([$_POST['bezeichnung'], $_POST['etage'], str_replace(',','.',$_POST['wohnflaeche']), $_POST['mieter_name'], $_POST['mieter_seit']?:null, (int)$_POST['personen'], (int)$_POST['id'], $objektId]);
            protokolliere('wohnungen', 'aendern', (int)$_POST['id'], 'Wohnung "' . $_POST['bezeichnung'] . '" geändert');
        } else {
            // Neue Wohnung im aktiven Objekt anlegen
            $stmt = $db->prepare("INSERT INTO wohnungen (objekt_id,bezeichnung,etage,wohnflaeche,mieter_name,mieter_seit,personen) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$objektId, $_POST['bezeichnung'], $_POST['etage'], str_replace(',','.',$_POST['wohnflaeche']), $_POST['mieter_name'], $_POST['mieter_seit']?:null, (int)$_POST['personen']]);
            protokolliere('wohnungen', 'anlegen', (int)$db->lastInsertId(), 'Wohnung "' . $_POST['bezeichnung'] . '" angelegt');
        }
        $successMsg = 'Wohnung gespeichert.';
    }
}

$editId = (!istNurLesend()) ? (int)($_GET['edit'] ?? 0) : 0;
$editRow = null;
if ($editId) {
    // Bearbeiten nur für Wohnungen des aktiven Objekts
    $stmt = $db->prepare("SELECT * FROM wohnungen WHERE id=? AND objekt_id=?");
    $stmt->execute([$editId, $objektId]);
    $editRow = $stmt->fetch();
}

$stmt = $db->prepare("SELECT * FROM wohnungen WHERE objekt_id=? ORDER BY id");
$stmt->execute([$objektId]);
$wohnungen = $stmt->fetchAll();
$gesamtFlaeche = array_sum(array_column(array_filter($wohnungen, fn($w)=>$w['aktiv']), 'wohnflaeche'));

$objStmt = $db->prepare("SELECT wirtschaftsjahr_start_monat, wirtschaftsjahr_start_tag FROM objekt WHERE id=?");
$objStmt->execute([$objektId]);
$wj = $objStmt->fetch();
$tachoVon = aktuellerWirtschaftsjahrStart((int)($wj['wirtschaftsjahr_start_monat'] ?? 1), (int)($wj['wirtschaftsjahr_start_tag'] ?? 1));

$tachoJeWohnung = berechneLaufendeKosten($db, $objektId, $tachoVon->format('Y-m-d'), date('Y-m-d'));

include '../assets/header.php';
?>
<div class="page-header"><h1>Wohnungen / Stammdaten</h1></div>

<?php if ($wohnungen): ?>
<div class="card">
    <h2>🔧 Kosten-Tacho – je Wohnung</h2>
    <p style="color:var(--muted);font-size:.85rem;margin-bottom:.5rem">
        Zeitraum: seit <?= $tachoVon->format('d.m.Y') ?> (Wirtschaftsjahresbeginn) bis heute –
        einstellbar unter <a href="einstellungen.php">Einstellungen</a>
    </p>
    <div class="kosten-tacho-grid">
        <?php foreach ($wohnungen as $w):
            $t = $tachoJeWohnung[$w['id']] ?? null;
            if (!$t) continue;
        ?>
        <?= kostenTachoHtml($t['kosten'], $t['vorauszahlung'], $t['prozent'], $w['bezeichnung'] . ' – ' . $w['mieter_name']) ?>
        <?php endforeach; ?>
    </div>
    <?= kostenTachoHinweis() ?>
</div>
<?php endif; ?>

<?php if (!istNurLesend()): ?>
<div class="card">
    <h2><?= $editRow ? 'Wohnung bearbeiten' : 'Neue Wohnung anlegen' ?></h2>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <?= csrfFeld() ?>
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= $editRow['id'] ?>"><?php endif; ?>
        <div class="form-grid">
            <div class="form-group"><label>Bezeichnung *</label><input type="text" name="bezeichnung" value="<?= htmlspecialchars($editRow['bezeichnung'] ?? '') ?>" placeholder="EG, OG, DG..." required></div>
            <div class="form-group"><label>Etage</label><input type="text" name="etage" value="<?= htmlspecialchars($editRow['etage'] ?? '') ?>" placeholder="Erdgeschoss"></div>
            <div class="form-group"><label>Wohnfläche (m²) *</label><input type="text" name="wohnflaeche" value="<?= $editRow['wohnflaeche'] ?? '' ?>" required></div>
            <div class="form-group"><label>Mieter</label><input type="text" name="mieter_name" value="<?= htmlspecialchars($editRow['mieter_name'] ?? '') ?>"></div>
            <div class="form-group"><label>Mieter seit</label><input type="date" name="mieter_seit" value="<?= $editRow['mieter_seit'] ?? '' ?>"></div>
            <div class="form-group"><label>Personen</label><input type="number" name="personen" value="<?= $editRow['personen'] ?? 2 ?>" min="1"></div>
        </div>
        <div style="margin-top:1rem"><button type="submit" class="btn btn-primary">Speichern</button></div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Alle Wohnungen – Gesamtfläche: <?= number_format($gesamtFlaeche,2,',','.') ?> m²</h2>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Bez.</th><th>Etage</th><th class="text-right">Fläche</th><th class="text-right">Anteil</th><th>Mieter</th><th>Personen</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($wohnungen as $w): ?>
        <tr>
            <td><?= htmlspecialchars($w['bezeichnung']) ?></td>
            <td><?= htmlspecialchars($w['etage']) ?></td>
            <td class="text-right"><?= number_format($w['wohnflaeche'],2,',','.') ?> m²</td>
            <td class="text-right"><?= $gesamtFlaeche > 0 ? number_format($w['wohnflaeche']/$gesamtFlaeche*100,1,',','.').' %' : '–' ?></td>
            <td><?= htmlspecialchars($w['mieter_name']) ?></td>
            <td class="text-center"><?= $w['personen'] ?></td>
            <td><?php if (!istNurLesend()): ?><a href="?edit=<?= $w['id'] ?>" class="btn btn-sm btn-primary">✏️</a><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php include '../assets/footer.php'; ?>
