<?php
/**
 * Instandhaltungsrücklage – Einlagen und Entnahmen erfassen.
 * Dient der Eigentümer-Übersicht, wie viel Rücklage für künftige
 * größere Instandhaltungen/Modernisierungen zur Verfügung steht.
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

$pageTitle = 'Rücklagen';
$basePath  = '../';

$objektId = aktivesObjekt();

// ── Buchung speichern ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    leserSchreibschutz();
    csrfPruefen();
    $datum  = $_POST['datum'];
    $betrag = str_replace(',', '.', $_POST['betrag']);
    $beschr = trim($_POST['beschreibung']);
    $typ    = $_POST['typ'] === 'Entnahme' ? 'Entnahme' : 'Einlage';

    $stmt = $db->prepare("INSERT INTO ruecklagen (objekt_id, datum, betrag, beschreibung, typ) VALUES (?,?,?,?,?)");
    $stmt->execute([$objektId, $datum, $betrag, $beschr, $typ]);
    protokolliere('ruecklagen', 'anlegen', (int)$db->lastInsertId(), "$typ über " . number_format($betrag, 2, ',', '.') . " €");
    $successMsg = "$typ über " . number_format($betrag, 2, ',', '.') . " € erfasst.";
}

if (isset($_GET['delete'])) {
    leserSchreibschutz();
    $delId = (int)$_GET['delete'];
    protokolliere('ruecklagen', 'loeschen', $delId, 'Rücklagenbuchung gelöscht');
    $db->prepare("DELETE FROM ruecklagen WHERE id=? AND objekt_id=?")->execute([$delId, $objektId]);
    header('Location: ruecklagen.php'); exit;
}

// ── Daten laden ──────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM ruecklagen WHERE objekt_id=? ORDER BY datum DESC");
$stmt->execute([$objektId]);
$buchungen = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT COALESCE(SUM(CASE WHEN typ='Einlage' THEN betrag ELSE -betrag END),0)
    FROM ruecklagen WHERE objekt_id=?
");
$stmt->execute([$objektId]);
$saldo = (float)$stmt->fetchColumn();

$summeEinlagen  = (float)array_sum(array_map(fn($b) => $b['typ']==='Einlage'  ? $b['betrag'] : 0, $buchungen));
$summeEntnahmen = (float)array_sum(array_map(fn($b) => $b['typ']==='Entnahme' ? $b['betrag'] : 0, $buchungen));

include '../assets/header.php';
?>

<div class="page-header">
    <h1>💰 Instandhaltungsrücklage</h1>
</div>

<div class="dashboard-grid">
    <div class="kpi-card success">
        <div class="kpi-label">Aktueller Rücklagenstand</div>
        <div class="kpi-value"><?= number_format($saldo,0,',','.') ?> €</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Eingezahlt (gesamt)</div>
        <div class="kpi-value" style="color:var(--success)">+<?= number_format($summeEinlagen,0,',','.') ?> €</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Entnommen (gesamt)</div>
        <div class="kpi-value" style="color:var(--danger)">−<?= number_format($summeEntnahmen,0,',','.') ?> €</div>
    </div>
</div>

<?php if (!istNurLesend()): ?>
<div class="card">
    <h2>Neue Buchung erfassen</h2>
    <form method="post">
        <?= csrfFeld() ?>
        <input type="hidden" name="action" value="save">
        <div class="form-grid">
            <div class="form-group">
                <label>Typ *</label>
                <select name="typ" required>
                    <option value="Einlage">Einlage (Geld in die Rücklage)</option>
                    <option value="Entnahme">Entnahme (Geld aus der Rücklage)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Datum *</label>
                <input type="date" name="datum" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Betrag (€) *</label>
                <input type="text" name="betrag" placeholder="500.00" required>
            </div>
            <div class="form-group">
                <label>Beschreibung</label>
                <input type="text" name="beschreibung" placeholder="z.B. monatliche Zuführung, oder Dachreparatur">
            </div>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary">Buchung speichern</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Alle Buchungen (<?= count($buchungen) ?>)</h2>
    <?php if ($buchungen): ?>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Datum</th><th>Typ</th><th>Beschreibung</th><th class="text-right">Betrag</th><?= istNurLesend() ? '' : '<th></th>' ?></tr></thead>
        <tbody>
        <?php foreach ($buchungen as $b): ?>
        <tr>
            <td><?= date('d.m.Y', strtotime($b['datum'])) ?></td>
            <td>
                <span class="badge <?= $b['typ']==='Einlage' ? 'badge-success' : 'badge-danger' ?>">
                    <?= $b['typ'] ?>
                </span>
            </td>
            <td><?= htmlspecialchars($b['beschreibung'] ?? '') ?></td>
            <td class="text-right" style="font-weight:700;color:<?= $b['typ']==='Einlage' ? 'var(--success)' : 'var(--danger)' ?>">
                <?= $b['typ']==='Einlage' ? '+' : '−' ?><?= number_format($b['betrag'],2,',','.') ?> €
            </td>
            <?php if (!istNurLesend()): ?>
            <td><a href="?delete=<?= $b['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Buchung löschen?')">✕</a></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine Rücklagen-Buchungen erfasst.</p>
    <?php endif; ?>
</div>

<?php include '../assets/footer.php'; ?>
