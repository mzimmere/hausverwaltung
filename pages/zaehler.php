<?php
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';
require_once '../includes/kostenberechnung.php';

$user = aktuellerBenutzer();
if (!in_array($user['rolle'], ['admin', 'leser'], true)) {
    header('Location: ../index.php');
    exit;
}

$pageTitle = 'Zählerstände';
$basePath  = '../';

$objektId = aktivesObjekt();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wohnung_id'])) {
    leserSchreibschutz();
    csrfPruefen();
    // Nur Ablesungen für Wohnungen des aktiven Objekts zulassen
    $chk = $db->prepare("SELECT COUNT(*) FROM wohnungen WHERE id=? AND objekt_id=?");
    $chk->execute([(int)$_POST['wohnung_id'], $objektId]);
    if ($chk->fetchColumn()) {
        $stmt = $db->prepare("INSERT INTO wasserablesungen (wohnung_id, datum, stand, typ, jahr) VALUES (?,?,?,?,?)");
        $stmt->execute([(int)$_POST['wohnung_id'], $_POST['datum'], str_replace(',','.',$_POST['stand']), $_POST['typ'], (int)$_POST['jahr']]);
        protokolliere('zaehler', 'anlegen', (int)$db->lastInsertId(), 'Zählerstand erfasst');
        $successMsg = 'Zählerstand gespeichert.';
    } else {
        $errorMsg = 'Ungültige Wohnung.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    leserSchreibschutz();
    csrfPruefen();
    $delId = (int)$_POST['delete_id'];
    $db->prepare("
        DELETE z FROM wasserablesungen z
        JOIN wohnungen w ON z.wohnung_id = w.id
        WHERE z.id = ? AND w.objekt_id = ?
    ")->execute([$delId, $objektId]);
    protokolliere('zaehler', 'loeschen', $delId, 'Zählerstand gelöscht');
    header('Location: zaehler.php?jahr=' . (int)($_POST['jahr'] ?? date('Y'))); exit;
}

$filterJahr = (int)($_GET['jahr'] ?? date('Y'));

$objStmt = $db->prepare("SELECT wirtschaftsjahr_start_monat, wirtschaftsjahr_start_tag FROM objekt WHERE id=?");
$objStmt->execute([$objektId]);
$wj = $objStmt->fetch();
[$wjVon, $wjBis] = wirtschaftsjahrZeitraumFuerJahr((int)($wj['wirtschaftsjahr_start_monat'] ?? 1), (int)($wj['wirtschaftsjahr_start_tag'] ?? 1), $filterJahr);

$wStmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=? ORDER BY id");
$wStmt->execute([$objektId]);
$wohnungen = $wStmt->fetchAll();

$ablesungen = $db->prepare("
    SELECT z.*, w.bezeichnung AS wohnung
    FROM wasserablesungen z JOIN wohnungen w ON z.wohnung_id = w.id
    WHERE z.jahr = ? AND w.objekt_id = ? ORDER BY w.id, z.typ
");
$ablesungen->execute([$filterJahr, $objektId]);
$ablesungen = $ablesungen->fetchAll();

// Verbrauch berechnen
$verbrauch = [];
foreach ($wohnungen as $w) {
    $anfang = null; $ende = null;
    foreach ($ablesungen as $a) {
        if ($a['wohnung_id'] == $w['id']) {
            if ($a['typ'] === 'Anfang') $anfang = $a['stand'];
            if ($a['typ'] === 'Ende')   $ende   = $a['stand'];
        }
    }
    $verbrauch[$w['id']] = ($anfang !== null && $ende !== null) ? round($ende - $anfang, 3) : null;
}

include '../assets/header.php';
?>
<div class="page-header">
    <h1>Zählerstände Wasser</h1>
    <div>
        <?php foreach ([date('Y')-1, date('Y')] as $j): ?>
        <a href="?jahr=<?= $j ?>" class="btn btn-sm <?= $j==$filterJahr ? 'btn-primary' : '' ?>" style="<?= $j!=$filterJahr ? 'background:#e2e8f0;color:#333' : '' ?>"><?= $j ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!istNurLesend()): ?>
<div class="card">
    <h2>Ablesung erfassen</h2>
    <form method="post">
        <?= csrfFeld() ?>
        <div class="form-grid">
            <div class="form-group">
                <label>Wohnung *</label>
                <select name="wohnung_id" required>
                    <?php foreach ($wohnungen as $w): ?>
                    <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['bezeichnung']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Typ *</label>
                <select name="typ" id="zaehlerTyp" required onchange="zaehlerDatumVorschlagen()"
                        data-wj-von="<?= $wjVon->format('Y-m-d') ?>" data-wj-bis="<?= $wjBis->format('Y-m-d') ?>">
                    <option value="Anfang">Anfangsstand (<?= $wjVon->format('d.m.') ?>, Wirtschaftsjahresbeginn)</option>
                    <option value="Ende">Endstand (<?= $wjBis->format('d.m.') ?>, Wirtschaftsjahresende)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Stand (m³) *</label>
                <input type="text" name="stand" placeholder="1234.567" required>
            </div>
            <div class="form-group">
                <label>Datum *</label>
                <input type="date" name="datum" id="zaehlerDatum" value="<?= $wjVon->format('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Jahr *</label>
                <input type="number" name="jahr" value="<?= $filterJahr ?>" required>
                <small style="color:var(--muted)">Wirtschaftsjahr, das in diesem Jahr endet</small>
            </div>
        </div>
        <div style="margin-top:1rem"><button type="submit" class="btn btn-primary">Speichern</button></div>
    </form>
</div>
<script>
function zaehlerDatumVorschlagen() {
    const sel = document.getElementById('zaehlerTyp');
    const datum = document.getElementById('zaehlerDatum');
    datum.value = sel.value === 'Ende' ? sel.dataset.wjBis : sel.dataset.wjVon;
}
</script>
<?php endif; ?>

<div class="card">
    <h2>Verbrauchsübersicht <?= $filterJahr ?></h2>
    <p style="color:var(--muted);font-size:.85rem;margin-bottom:.75rem">
        Wirtschaftsjahr: <?= $wjVon->format('d.m.Y') ?> – <?= $wjBis->format('d.m.Y') ?>
        <?php if ($wjVon->format('m-d') !== '01-01'): ?> (einstellbar unter <a href="einstellungen.php">Einstellungen</a>)<?php endif; ?>
    </p>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Wohnung</th><th class="text-right">Anfangsstand</th><th class="text-right">Endstand</th><th class="text-right">Verbrauch m³</th></tr></thead>
        <tbody>
        <?php foreach ($wohnungen as $w):
            $a = array_filter($ablesungen, fn($x) => $x['wohnung_id']==$w['id']);
            $anfangRow = current(array_filter($a, fn($x) => $x['typ']==='Anfang')) ?: null;
            $endeRow   = current(array_filter($a, fn($x) => $x['typ']==='Ende'))   ?: null;
        ?>
        <tr>
            <td><?= htmlspecialchars($w['bezeichnung']) ?> – <?= htmlspecialchars($w['mieter_name']) ?></td>
            <td class="text-right">
                <?= $anfangRow ? number_format($anfangRow['stand'],3,',','.') . ' m³' : '<span style="color:var(--muted)">fehlt</span>' ?>
                <?php if ($anfangRow && !istNurLesend()): ?><form method="post" style="display:inline;margin-left:.5rem" onsubmit="return confirm('Löschen?')"><?= csrfFeld() ?><input type="hidden" name="delete_id" value="<?= $anfangRow['id'] ?>"><input type="hidden" name="jahr" value="<?= $filterJahr ?>"><button type="submit" class="btn btn-sm btn-danger">✕</button></form><?php endif; ?>
            </td>
            <td class="text-right">
                <?= $endeRow ? number_format($endeRow['stand'],3,',','.') . ' m³' : '<span style="color:var(--muted)">fehlt</span>' ?>
                <?php if ($endeRow && !istNurLesend()): ?><form method="post" style="display:inline;margin-left:.5rem" onsubmit="return confirm('Löschen?')"><?= csrfFeld() ?><input type="hidden" name="delete_id" value="<?= $endeRow['id'] ?>"><input type="hidden" name="jahr" value="<?= $filterJahr ?>"><button type="submit" class="btn btn-sm btn-danger">✕</button></form><?php endif; ?>
            </td>
            <td class="text-right">
                <?= $verbrauch[$w['id']] !== null ? '<strong>'.number_format($verbrauch[$w['id']],3,',','.').' m³</strong>' : '<span style="color:var(--danger)">unvollständig</span>' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php include '../assets/footer.php'; ?>
