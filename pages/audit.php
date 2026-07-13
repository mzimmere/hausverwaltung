<?php
/**
 * Audit-Log – wer hat wann was geändert/gelöscht.
 * Nur für Admins. Zeigt die letzten Einträge für das aktive Haus.
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';

if (!istAdmin()) {
    header('Location: ../index.php');
    exit;
}

$pageTitle = 'Audit-Log';
$basePath  = '../';

$objektId = aktivesObjekt();

$bereichFilter = trim($_GET['bereich'] ?? '');

$sql = "SELECT * FROM audit_log WHERE objekt_id = ?";
$params = [$objektId];
if ($bereichFilter !== '') {
    $sql .= " AND bereich = ?";
    $params[] = $bereichFilter;
}
$sql .= " ORDER BY erstellt_am DESC LIMIT 300";

$eintraege = [];
$bereiche  = [];
try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $eintraege = $stmt->fetchAll();

    $bereiche = $db->prepare("SELECT DISTINCT bereich FROM audit_log WHERE objekt_id = ? ORDER BY bereich");
    $bereiche->execute([$objektId]);
    $bereiche = $bereiche->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $t) {
    $errorMsg = 'Audit-Log-Tabelle noch nicht angelegt. Bitte die Migration sql/migration_mandantenfaehigkeit.sql ausführen.';
}

$aktionLabels = [
    'anlegen'  => ['Angelegt', 'badge-success'],
    'aendern'  => ['Geändert', 'badge-info'],
    'loeschen' => ['Gelöscht', 'badge-danger'],
];

include '../assets/header.php';
?>
<div class="page-header">
    <h1>Audit-Log</h1>
</div>

<div class="alert alert-info">
    ℹ️ Zeigt die letzten 300 Änderungen für das aktuell ausgewählte Haus – wer hat wann was
    angelegt, geändert oder gelöscht. Nur für den Admin sichtbar.
</div>

<div class="card">
    <h2>Verlauf<?= $bereichFilter ? ' – ' . htmlspecialchars($bereichFilter) : '' ?></h2>

    <?php if ($bereiche): ?>
    <div class="btn-group" style="margin-bottom:1rem;flex-wrap:wrap">
        <a href="audit.php" class="btn btn-sm <?= $bereichFilter === '' ? 'btn-primary' : '' ?>" style="<?= $bereichFilter !== '' ? 'background:#e2e8f0;color:#333' : '' ?>">Alle</a>
        <?php foreach ($bereiche as $b): ?>
        <a href="?bereich=<?= urlencode($b) ?>" class="btn btn-sm <?= $bereichFilter === $b ? 'btn-primary' : '' ?>" style="<?= $bereichFilter !== $b ? 'background:#e2e8f0;color:#333' : '' ?>"><?= htmlspecialchars($b) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($eintraege): ?>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Datum</th><th>Benutzer</th><th>Bereich</th><th>Aktion</th><th>Beschreibung</th></tr></thead>
        <tbody>
        <?php foreach ($eintraege as $e):
            $label = $aktionLabels[$e['aktion']] ?? [$e['aktion'], 'badge-info'];
        ?>
        <tr>
            <td><?= date('d.m.Y H:i', strtotime($e['erstellt_am'])) ?></td>
            <td><?= htmlspecialchars($e['benutzer_name'] ?: '–') ?></td>
            <td><span class="badge badge-info"><?= htmlspecialchars($e['bereich']) ?></span></td>
            <td><span class="badge <?= $label[1] ?>"><?= htmlspecialchars($label[0]) ?></span></td>
            <td><?= htmlspecialchars($e['beschreibung']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine Einträge vorhanden.</p>
    <?php endif; ?>
</div>

<?php include '../assets/footer.php'; ?>
