<?php
/**
 * Dokumentensafe – private Ablage je Wohnung, ausschließlich für den
 * Mieter selbst. Kategorien legt sich der Mieter frei selbst an
 * (z.B. Belege, Bedienungsanleitungen, Bilder) - komplett getrennt
 * von der offiziellen Dokumentenverwaltung des Vermieters und ohne
 * jede Bedeutung für die Nebenkostenabrechnung.
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';
$pageTitle = 'Dokumentensafe';
$basePath  = '../';

$user = aktuellerBenutzer();
if ($user['rolle'] !== 'mieter') {
    header('Location: ../index.php');
    exit;
}

$wohnungId = (int)($user['wohnung_id'] ?? 0);
if ($wohnungId <= 0) {
    header('Location: ../index.php');
    exit;
}

$erlaubteExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

// ── Neue Kategorie anlegen ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kategorie_neu'])) {
    csrfPruefen();
    $name = trim($_POST['kategorie_neu']);
    if ($name === '') {
        $errorMsg = 'Bitte einen Namen für die Kategorie angeben.';
    } else {
        $sortStmt = $db->prepare("SELECT COALESCE(MAX(sortierung),0)+1 FROM dokumentensafe_kategorien WHERE wohnung_id=?");
        $sortStmt->execute([$wohnungId]);
        $naechsteSort = (int)$sortStmt->fetchColumn();
        $stmt = $db->prepare("INSERT INTO dokumentensafe_kategorien (wohnung_id, bezeichnung, sortierung) VALUES (?,?,?)");
        $stmt->execute([$wohnungId, $name, $naechsteSort]);
        $successMsg = 'Kategorie angelegt.';
    }
}

// ── Kategorie umbenennen ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kategorie_umbenennen_id'])) {
    csrfPruefen();
    $kid  = (int)$_POST['kategorie_umbenennen_id'];
    $name = trim($_POST['kategorie_neuer_name'] ?? '');
    if ($name !== '') {
        $db->prepare("UPDATE dokumentensafe_kategorien SET bezeichnung=? WHERE id=? AND wohnung_id=?")
           ->execute([$name, $kid, $wohnungId]);
        $successMsg = 'Kategorie umbenannt.';
    }
}

// ── Kategorie löschen (nur wenn leer) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kategorie_loeschen_id'])) {
    csrfPruefen();
    $kid = (int)$_POST['kategorie_loeschen_id'];
    $anzahlStmt = $db->prepare("SELECT COUNT(*) FROM dokumentensafe WHERE kategorie_id=? AND wohnung_id=?");
    $anzahlStmt->execute([$kid, $wohnungId]);
    if ((int)$anzahlStmt->fetchColumn() > 0) {
        $errorMsg = 'Diese Kategorie enthält noch Dokumente – erst diese löschen oder verschieben.';
    } else {
        $db->prepare("DELETE FROM dokumentensafe_kategorien WHERE id=? AND wohnung_id=?")->execute([$kid, $wohnungId]);
        $successMsg = 'Kategorie gelöscht.';
    }
}

// ── Dokument hochladen ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dok_hochladen'])) {
    csrfPruefen();
    $katId   = (int)($_POST['kategorie_id'] ?? 0);
    $bezeich = trim($_POST['bezeichnung'] ?? '');
    $katStmt = $db->prepare("SELECT id FROM dokumentensafe_kategorien WHERE id=? AND wohnung_id=?");
    $katStmt->execute([$katId, $wohnungId]);
    if (!$katStmt->fetch()) {
        $errorMsg = 'Bitte eine gültige Kategorie wählen.';
    } elseif ($bezeich === '') {
        $errorMsg = 'Bitte eine Bezeichnung angeben.';
    } elseif (empty($_FILES['datei']['name'])) {
        $errorMsg = 'Bitte eine Datei auswählen.';
    } else {
        $ext = strtolower(pathinfo($_FILES['datei']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $erlaubteExt, true)) {
            $errorMsg = 'Dieser Dateityp wird nicht unterstützt.';
        } else {
            $zielDir = UPLOAD_DOKUMENTENSAFE . $wohnungId . '/';
            if (!is_dir($zielDir)) mkdir($zielDir, 0777, true);
            $dateiname = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['datei']['name']);
            move_uploaded_file($_FILES['datei']['tmp_name'], $zielDir . $dateiname);
            $stmt = $db->prepare("INSERT INTO dokumentensafe (wohnung_id, kategorie_id, bezeichnung, dateiname, hochgeladen_von) VALUES (?,?,?,?,?)");
            $stmt->execute([$wohnungId, $katId, $bezeich, $wohnungId . '/' . $dateiname, $user['id']]);
            $successMsg = 'Dokument hochgeladen.';
        }
    }
}

// ── Dokument löschen ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dok_loeschen_id'])) {
    csrfPruefen();
    $did = (int)$_POST['dok_loeschen_id'];
    $stmt = $db->prepare("SELECT dateiname FROM dokumentensafe WHERE id=? AND wohnung_id=?");
    $stmt->execute([$did, $wohnungId]);
    if ($row = $stmt->fetch()) {
        $pfad = UPLOAD_DOKUMENTENSAFE . str_replace(['..', '\\'], '', $row['dateiname']);
        if (is_file($pfad)) @unlink($pfad);
        $db->prepare("DELETE FROM dokumentensafe WHERE id=? AND wohnung_id=?")->execute([$did, $wohnungId]);
        $successMsg = 'Dokument gelöscht.';
    }
}

// ── Starter-Kategorien einmalig anlegen, falls noch keine existieren ──
$anzKatStmt = $db->prepare("SELECT COUNT(*) FROM dokumentensafe_kategorien WHERE wohnung_id=?");
$anzKatStmt->execute([$wohnungId]);
if ((int)$anzKatStmt->fetchColumn() === 0) {
    $seedStmt = $db->prepare("INSERT INTO dokumentensafe_kategorien (wohnung_id, bezeichnung, sortierung) VALUES (?,?,?)");
    foreach (['Belege', 'Bedienungsanleitungen', 'Bilder'] as $i => $name) {
        $seedStmt->execute([$wohnungId, $name, $i]);
    }
}

// ── Daten für die Anzeige laden ───────────────────────────────
$katStmt = $db->prepare("SELECT * FROM dokumentensafe_kategorien WHERE wohnung_id=? ORDER BY sortierung, id");
$katStmt->execute([$wohnungId]);
$kategorien = $katStmt->fetchAll();

$dokStmt = $db->prepare("SELECT * FROM dokumentensafe WHERE wohnung_id=? ORDER BY hochgeladen_am DESC");
$dokStmt->execute([$wohnungId]);
$dokumenteAlle = $dokStmt->fetchAll();
$dokumenteJeKategorie = [];
foreach ($dokumenteAlle as $d) {
    $dokumenteJeKategorie[(int)$d['kategorie_id']][] = $d;
}

include '../assets/header.php';
?>
<div class="page-header"><h1>Dokumentensafe</h1></div>
<p style="color:var(--muted);font-size:.85rem;margin:-.5rem 0 1.25rem">
    Dein privater Ablageort für deine Wohnung – z.B. Kaufbelege, Bedienungsanleitungen oder Fotos.
    Lege dir beliebig viele eigene Kategorien an. Diese Ablage ist nur für dich sichtbar und hat
    keinerlei Bedeutung für die Nebenkostenabrechnung.
</p>

<?php if (!empty($successMsg)): ?><div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
<?php if (!empty($errorMsg)): ?><div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>

<div class="card">
    <h2>Neue Kategorie anlegen</h2>
    <form method="post" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
        <?= csrfFeld() ?>
        <div class="form-group" style="flex:1;min-width:200px">
            <label>Name der Kategorie</label>
            <input type="text" name="kategorie_neu" placeholder="z.B. Versicherung, Möbel, …" required>
        </div>
        <div><button type="submit" class="btn btn-primary">+ Anlegen</button></div>
    </form>
</div>

<div class="card">
    <h2>Dokument hochladen</h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrfFeld() ?>
        <input type="hidden" name="dok_hochladen" value="1">
        <div class="form-grid">
            <div class="form-group">
                <label>Kategorie *</label>
                <select name="kategorie_id" required>
                    <?php foreach ($kategorien as $k): ?>
                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['bezeichnung']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Bezeichnung *</label><input type="text" name="bezeichnung" placeholder="z.B. Kaufbeleg Waschmaschine" required></div>
            <div class="form-group"><label>Datei *</label><input type="file" name="datei" required></div>
        </div>
        <div style="margin-top:1rem"><button type="submit" class="btn btn-primary">Hochladen</button></div>
    </form>
</div>

<?php foreach ($kategorien as $k):
    $dokumenteHier = $dokumenteJeKategorie[(int)$k['id']] ?? [];
?>
<div class="card">
    <h2 style="display:flex;align-items:center;justify-content:space-between;gap:1rem">
        <span><?= htmlspecialchars($k['bezeichnung']) ?> <span style="color:var(--muted);font-weight:400;font-size:.85rem">(<?= count($dokumenteHier) ?>)</span></span>
        <span style="display:flex;gap:.4rem;flex-shrink:0">
            <button type="button" class="btn btn-sm" style="background:var(--card-bg-high);color:var(--text)"
                    onclick="document.getElementById('umbenennen-<?= $k['id'] ?>').style.display='flex'">✏️ Umbenennen</button>
            <?php if (!$dokumenteHier): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Kategorie „<?= htmlspecialchars(addslashes($k['bezeichnung'])) ?>“ wirklich löschen?')">
                <?= csrfFeld() ?>
                <input type="hidden" name="kategorie_loeschen_id" value="<?= $k['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">✕ Löschen</button>
            </form>
            <?php endif; ?>
        </span>
    </h2>
    <form method="post" id="umbenennen-<?= $k['id'] ?>" style="display:none;gap:.5rem;margin-bottom:1rem;align-items:end">
        <?= csrfFeld() ?>
        <input type="hidden" name="kategorie_umbenennen_id" value="<?= $k['id'] ?>">
        <div class="form-group" style="flex:1"><label>Neuer Name</label><input type="text" name="kategorie_neuer_name" value="<?= htmlspecialchars($k['bezeichnung']) ?>" required></div>
        <div><button type="submit" class="btn btn-sm btn-primary">Speichern</button></div>
    </form>

    <?php if ($dokumenteHier): ?>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Bezeichnung</th><th>Datum</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($dokumenteHier as $d): ?>
        <tr>
            <td><?= htmlspecialchars($d['bezeichnung']) ?></td>
            <td><?= date('d.m.Y', strtotime($d['hochgeladen_am'])) ?></td>
            <td>
                <a href="datei.php?typ=safe&id=<?= $d['id'] ?>" target="_blank" class="btn btn-sm" style="background:var(--card-bg-high);color:var(--text)">📄 Ansehen</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Dokument löschen?')">
                    <?= csrfFeld() ?>
                    <input type="hidden" name="dok_loeschen_id" value="<?= $d['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">✕</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted);font-size:.88rem">Noch keine Dokumente in dieser Kategorie.</p>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php include '../assets/footer.php'; ?>
