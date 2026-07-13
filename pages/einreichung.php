<?php
/**
 * Rechnung einreichen – Seite für den Hausmeister.
 * Er lädt die Rechnung hoch (PDF/Bild), gibt Datum, Betrag und einen
 * Vermerk an. Die Einreichung landet in der Tabelle `einreichungen`,
 * getrennt vom restlichen System. Erst wenn der Admin auf der
 * Freigabe-Seite freigibt, entsteht daraus eine echte Rechnung.
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';
$pageTitle = 'Rechnung einreichen';
$basePath  = '../';

$user = aktuellerBenutzer();
// Zugriff: Hausmeister, Mieter und Admin (Admin z. B. zum Testen)
if (!in_array($user['rolle'], ['hausmeister', 'mieter', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

$objektId = aktivesObjekt();

$einreichungDir = UPLOAD_RECHNUNGEN . 'einreichungen/';
$erlaubteExt    = ['pdf', 'jpg', 'jpeg', 'png'];
$maxGroesse     = 20 * 1024 * 1024; // 20 MB

// ── Einreichung speichern ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfPruefen();
    $datum   = $_POST['datum'] ?? '';
    $betrag  = (float)str_replace(',', '.', str_replace('.', '', $_POST['betrag'] ?? ''));
    // Falls ohne Tausenderpunkt eingegeben (z. B. "123.45"), zweiter Versuch:
    if ($betrag <= 0) {
        $betrag = (float)str_replace(',', '.', $_POST['betrag'] ?? '');
    }
    $vermerk = trim($_POST['vermerk'] ?? '');
    $art     = in_array($_POST['art'] ?? '', ['umlegbar', 'nicht_umlegbar', 'unklar'], true) ? $_POST['art'] : 'unklar';

    if (!$datum || $betrag <= 0 || $vermerk === '') {
        $errorMsg = 'Bitte Datum, Betrag und Vermerk ausfüllen.';
    } elseif (empty($_FILES['datei']['name']) || $_FILES['datei']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Bitte eine Datei auswählen (PDF oder Foto).';
    } elseif ($_FILES['datei']['size'] > $maxGroesse) {
        $errorMsg = 'Die Datei ist zu groß (maximal 20 MB).';
    } else {
        $ext = strtolower(pathinfo($_FILES['datei']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $erlaubteExt, true)) {
            $errorMsg = 'Nur PDF, JPG oder PNG sind erlaubt.';
        } else {
            if (!is_dir($einreichungDir)) mkdir($einreichungDir, 0777, true);
            // Zufälliger Dateiname – nicht erratbar, Original bleibt gespeichert
            $dateiname = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($_FILES['datei']['tmp_name'], $einreichungDir . $dateiname)) {
                $stmt = $db->prepare("
                    INSERT INTO einreichungen (objekt_id, benutzer_id, datum, betrag_eingereicht, vermerk, art, dateipfad, original_name)
                    VALUES (?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $objektId, $user['id'], $datum, $betrag, $vermerk, $art,
                    'einreichungen/' . $dateiname,
                    $_FILES['datei']['name'],
                ]);
                $successMsg = 'Rechnung eingereicht – sie wird nun geprüft.';
            } else {
                $errorMsg = 'Die Datei konnte nicht gespeichert werden.';
            }
        }
    }
}

// Eigene Einreichungen laden
$stmt = $db->prepare("SELECT * FROM einreichungen WHERE benutzer_id = ? ORDER BY eingereicht_am DESC");
$stmt->execute([$user['id']]);
$meineEinreichungen = $stmt->fetchAll();

// Status-Updates jetzt als gelesen markieren (das "Neu"-Badge in der
// Leiste verschwindet damit; die Hervorhebung unten bleibt für diesen Aufruf)
$db->prepare("UPDATE einreichungen SET ungelesen = 0 WHERE benutzer_id = ? AND ungelesen = 1")
   ->execute([$user['id']]);

include '../assets/header.php';
?>
<div class="page-header"><h1>Rechnung einreichen</h1></div>

<div class="card">
    <h2>Neue Rechnung hochladen</h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrfFeld() ?>
        <div class="form-grid">
            <div class="form-group">
                <label>Rechnungsdatum *</label>
                <input type="date" name="datum" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Betrag (€) *</label>
                <input type="text" name="betrag" placeholder="z. B. 123,45" required>
            </div>
            <div class="form-group" style="grid-column: 1 / -1">
                <label>Vermerk * <span style="font-weight:400;color:var(--muted)">(Was wurde gekauft / erledigt? Für welches Haus / welchen Bereich?)</span></label>
                <textarea name="vermerk" required placeholder="z. B. Streusalz und Besen für den Winterdienst, Baumarkt"></textarea>
            </div>
            <div class="form-group">
                <label>Rechnung als Datei * <span style="font-weight:400;color:var(--muted)">(PDF oder Foto, max. 20 MB)</span></label>
                <input type="file" name="datei" accept=".pdf,.jpg,.jpeg,.png" required>
            </div>
            <div class="form-group" style="grid-column: 1 / -1">
                <label>Art der Ausgabe <span style="font-weight:400;color:var(--muted)">(falls bekannt – hilft bei der Freigabe, ist nicht bindend)</span></label>
                <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:.4rem">
                    <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;cursor:pointer">
                        <input type="radio" name="art" value="umlegbar" checked> Umlegbare Betriebskosten (wird auf die Mieter verteilt)
                    </label>
                    <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;cursor:pointer">
                        <input type="radio" name="art" value="nicht_umlegbar"> Nur für den Eigentümer (z.B. größere Reparatur)
                    </label>
                    <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;cursor:pointer">
                        <input type="radio" name="art" value="unklar"> Weiß ich nicht – bitte bei Freigabe entscheiden
                    </label>
                </div>
            </div>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary">Rechnung einreichen</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>Meine Einreichungen (<?= count($meineEinreichungen) ?>)</h2>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Eingereicht</th><th>Rechnungsdatum</th><th class="text-right">Betrag</th><th>Vermerk</th><th>Status</th><th>Datei</th></tr></thead>
        <tbody>
        <?php foreach ($meineEinreichungen as $e): ?>
        <tr>
            <td><?= date('d.m.Y', strtotime($e['eingereicht_am'])) ?></td>
            <td><?= date('d.m.Y', strtotime($e['datum'])) ?></td>
            <td class="text-right"><?= number_format($e['betrag_eingereicht'], 2, ',', '.') ?> &euro;</td>
            <td>
                <?= htmlspecialchars($e['vermerk']) ?>
                <?php if ($e['art'] === 'nicht_umlegbar'): ?><br><span class="badge badge-warning" style="margin-top:.2rem">nicht umlegbar</span>
                <?php elseif ($e['art'] === 'umlegbar'): ?><br><span class="badge badge-info" style="margin-top:.2rem">umlegbar</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($e['ungelesen'])): ?>
                <span class="badge badge-warning">Neu</span>
                <?php endif; ?>
                <?php if ($e['status'] === 'eingereicht'): ?>
                    <span class="badge badge-info">Wird geprüft</span>
                <?php elseif ($e['status'] === 'freigegeben'): ?>
                    <span class="badge badge-success">Freigegeben</span>
                <?php elseif ($e['status'] === 'ueberwiesen'): ?>
                    <span class="badge badge-success">✓ Überwiesen</span>
                <?php else: ?>
                    <span class="badge badge-danger" title="<?= htmlspecialchars($e['ablehnungsgrund']) ?>">Abgelehnt</span>
                    <?php if ($e['ablehnungsgrund']): ?>
                    <div style="font-size:.78rem;color:var(--muted);margin-top:.2rem"><?= htmlspecialchars($e['ablehnungsgrund']) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (($e['nachricht'] ?? '') !== ''): ?>
                <div style="font-size:.78rem;color:var(--muted);margin-top:.25rem">💬 <?= htmlspecialchars($e['nachricht']) ?></div>
                <?php endif; ?>
            </td>
            <td><a href="einreichung_datei.php?id=<?= $e['id'] ?>" target="_blank" class="btn btn-sm" style="background:#e2e8f0">📄</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$meineEinreichungen): ?><tr><td colspan="6" class="text-center" style="color:var(--muted)">Noch keine Einreichungen</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php include '../assets/footer.php'; ?>
