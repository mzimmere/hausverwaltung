<?php
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';

$user = aktuellerBenutzer();
if (!in_array($user['rolle'], ['admin', 'leser'], true)) {
    header('Location: ../index.php');
    exit;
}

$pageTitle = 'Vorauszahlungen';
$basePath  = '../';

$objektId = aktivesObjekt();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    leserSchreibschutz();
    csrfPruefen();
    $stmt = $db->prepare("INSERT INTO vorauszahlungen (wohnung_id, jahr, monatlicher_abschlag) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE monatlicher_abschlag=VALUES(monatlicher_abschlag)");
    $stmt->execute([(int)$_POST['wohnung_id'], (int)$_POST['jahr'], str_replace(',','.',$_POST['abschlag'])]);
    protokolliere('vorauszahlungen', 'aendern', (int)$_POST['wohnung_id'], 'Abschlag für Jahr ' . (int)$_POST['jahr'] . ' gesetzt');
    $successMsg = 'Vorauszahlung gespeichert.';
}

$filterJahr = (int)($_GET['jahr'] ?? date('Y'));
$stmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=? ORDER BY id");
$stmt->execute([$objektId]);
$wohnungen = $stmt->fetchAll();
$vzRows     = $db->prepare("SELECT * FROM vorauszahlungen WHERE jahr=?");
$vzRows->execute([$filterJahr]);
$vzMap = [];
foreach ($vzRows->fetchAll() as $v) $vzMap[$v['wohnung_id']] = $v;

include '../assets/header.php';
?>
<div class="page-header"><h1>Vorauszahlungen / Abschläge</h1></div>

<?php if (!istNurLesend()): ?>
<div class="card">
    <h2>Monatlichen Abschlag festlegen</h2>
    <form method="post">
        <?= csrfFeld() ?>
        <div class="form-grid">
            <div class="form-group">
                <label>Wohnung *</label>
                <select name="wohnung_id" required>
                    <?php foreach ($wohnungen as $w): ?>
                    <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['bezeichnung']) ?> – <?= htmlspecialchars($w['mieter_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Jahr *</label>
                <input type="number" name="jahr" value="<?= date('Y') ?>" required>
            </div>
            <div class="form-group">
                <label>Monatlicher Abschlag (€) *</label>
                <input type="text" name="abschlag" placeholder="250.00" required>
            </div>
        </div>
        <div style="margin-top:1rem"><button type="submit" class="btn btn-primary">Speichern</button></div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Übersicht <?= $filterJahr ?></h2>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Wohnung</th><th>Mieter</th><th class="text-right">Monatl. Abschlag</th><th class="text-right">Jahresbetrag (12×)</th></tr></thead>
        <tbody>
        <?php $gesamt = 0; foreach ($wohnungen as $w):
            $vz = $vzMap[$w['id']] ?? null;
            $jahresbetrag = $vz ? $vz['monatlicher_abschlag'] * 12 : 0;
            $gesamt += $jahresbetrag;
        ?>
        <tr>
            <td><?= htmlspecialchars($w['bezeichnung']) ?></td>
            <td><?= htmlspecialchars($w['mieter_name']) ?></td>
            <td class="text-right"><?= $vz ? number_format($vz['monatlicher_abschlag'],2,',','.').' &euro;' : '<span style="color:var(--danger)">nicht gesetzt</span>' ?></td>
            <td class="text-right"><?= $vz ? '<strong>'.number_format($jahresbetrag,2,',','.').' &euro;</strong>' : '–' ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="background:#f0f5fb;font-weight:700">
            <td colspan="3">Gesamt Vorauszahlungen</td>
            <td class="text-right"><?= number_format($gesamt,2,',','.') ?> &euro;</td>
        </tr>
        </tbody>
    </table></div>
</div>

<?php include '../assets/footer.php'; ?>
