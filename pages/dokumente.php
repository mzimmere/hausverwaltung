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

$pageTitle = 'Dokumente';
$basePath  = '../';

$objektId = aktivesObjekt();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kategorie_id'])) {
    leserSchreibschutz();
    csrfPruefen();
    $katId    = (int)$_POST['kategorie_id'];
    $wohnId   = $_POST['wohnung_id'] !== '' ? (int)$_POST['wohnung_id'] : null;
    $bezeich  = trim($_POST['bezeichnung']);
    $jahr     = $_POST['jahr'] !== '' ? (int)$_POST['jahr'] : null;
    $freigabe = in_array($_POST['freigabe'] ?? '', ['verwaltung','mieter','hausmeister'], true)
                ? $_POST['freigabe'] : 'verwaltung';
    if ($freigabe === 'mieter' && !$wohnId) {
        $errorMsg = 'Für die Sichtbarkeit „Mieter" bitte eine Wohnung zuordnen.';
    } elseif (!empty($_FILES['datei']['name'])) {
        $ext     = pathinfo($_FILES['datei']['name'], PATHINFO_EXTENSION);
        $zielDir = UPLOAD_DOKUMENTE . ($jahr ?? 'allgemein') . '/';
        if (!is_dir($zielDir)) mkdir($zielDir, 0777, true);
        $dateiname = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/','_',$_FILES['datei']['name']);
        move_uploaded_file($_FILES['datei']['tmp_name'], $zielDir . $dateiname);
        $stmt = $db->prepare("INSERT INTO dokumente (objekt_id,kategorie_id,wohnung_id,bezeichnung,dateiname,jahr,freigabe) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$objektId, $katId, $wohnId, $bezeich, ($jahr ?? 'allgemein').'/'.$dateiname, $jahr, $freigabe]);
        protokolliere('dokumente', 'anlegen', (int)$db->lastInsertId(), "Dokument \"$bezeich\" hochgeladen");
        $successMsg = 'Dokument hochgeladen.';
    } else {
        $errorMsg = 'Bitte eine Datei auswählen.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    leserSchreibschutz();
    csrfPruefen();
    $delId = (int)$_POST['delete_id'];
    $db->prepare("DELETE FROM dokumente WHERE id=? AND objekt_id=?")->execute([$delId, $objektId]);
    protokolliere('dokumente', 'loeschen', $delId, 'Dokument gelöscht');
    header('Location: dokumente.php'); exit;
}

// Zuordnung / Sichtbarkeit nachträglich ändern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    leserSchreibschutz();
    csrfPruefen();
    $eid      = (int)$_POST['edit_id'];
    $wohnId   = $_POST['wohnung_id'] !== '' ? (int)$_POST['wohnung_id'] : null;
    $freigabe = in_array($_POST['freigabe'] ?? '', ['verwaltung','mieter','hausmeister'], true)
                ? $_POST['freigabe'] : 'verwaltung';
    if ($freigabe === 'mieter' && !$wohnId) {
        $errorMsg = 'Für die Sichtbarkeit „Mieter" bitte eine Wohnung zuordnen.';
    } else {
        $db->prepare("UPDATE dokumente SET wohnung_id=?, freigabe=? WHERE id=? AND objekt_id=?")
           ->execute([$wohnId, $freigabe, $eid, $objektId]);
        protokolliere('dokumente', 'aendern', $eid, 'Zuordnung/Sichtbarkeit geändert');
        $successMsg = 'Zuordnung gespeichert.';
    }
}

// Vom Mieter freigegebene Dokumentensafe-Einträge (privat, außer explizit freigegeben)
$safeStmt = $db->prepare("
    SELECT ds.*, w.bezeichnung AS wohnung, dsk.bezeichnung AS kategorie
    FROM dokumentensafe ds
    JOIN wohnungen w ON ds.wohnung_id = w.id
    JOIN dokumentensafe_kategorien dsk ON ds.kategorie_id = dsk.id
    WHERE ds.freigegeben = 1 AND w.objekt_id = ?
    ORDER BY ds.hochgeladen_am DESC
");
$safeStmt->execute([$objektId]);
$freigegebeneSafeDokumente = $safeStmt->fetchAll();

$kategorien = $db->query("SELECT * FROM dokument_kategorien")->fetchAll();
$wStmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=? ORDER BY id");
$wStmt->execute([$objektId]);
$wohnungen = $wStmt->fetchAll();
$dStmt = $db->prepare("
    SELECT d.*, dk.bezeichnung AS kategorie, w.bezeichnung AS wohnung
    FROM dokumente d
    LEFT JOIN dokument_kategorien dk ON d.kategorie_id = dk.id
    LEFT JOIN wohnungen w ON d.wohnung_id = w.id
    WHERE d.objekt_id = ?
    ORDER BY d.hochgeladen_am DESC
");
$dStmt->execute([$objektId]);
$dokumente = $dStmt->fetchAll();

// Für die Übersicht nach Wohnung gruppieren (aufklappbar) - "Allgemein /
// Objekt" (kein wohnung_id) zuerst, danach jede Wohnung mit Dokumenten.
$dokumenteJeWohnung = [];
foreach ($dokumente as $d) {
    $dokumenteJeWohnung[(int)($d['wohnung_id'] ?? 0)][] = $d;
}
$dokumentGruppen = [];
if (isset($dokumenteJeWohnung[0])) {
    $dokumentGruppen[] = ['name' => 'Allgemein / Objekt', 'dokumente' => $dokumenteJeWohnung[0]];
}
foreach ($wohnungen as $w) {
    if (isset($dokumenteJeWohnung[(int)$w['id']])) {
        $dokumentGruppen[] = ['name' => $w['bezeichnung'], 'dokumente' => $dokumenteJeWohnung[(int)$w['id']]];
    }
}

include '../assets/header.php';
?>
<div class="page-header"><h1>Dokumentenverwaltung</h1></div>

<?php if (!istNurLesend()): ?>
<div class="card">
    <h2>Dokument hochladen</h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrfFeld() ?>
        <div class="form-grid">
            <div class="form-group"><label>Bezeichnung *</label><input type="text" name="bezeichnung" required></div>
            <div class="form-group">
                <label>Kategorie *</label>
                <select name="kategorie_id" required>
                    <?php foreach ($kategorien as $k): ?><option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['bezeichnung']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Wohnung (optional)</label>
                <select name="wohnung_id">
                    <option value="">Alle / Objekt</option>
                    <?php foreach ($wohnungen as $w): ?><option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['bezeichnung']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Sichtbar für</label>
                <select name="freigabe">
                    <option value="verwaltung">Nur Hausverwaltung (Standard)</option>
                    <option value="mieter">+ Mieter der zugeordneten Wohnung</option>
                    <option value="hausmeister">+ Hausmeister</option>
                </select>
            </div>
            <div class="form-group"><label>Jahr (optional)</label><input type="number" name="jahr" placeholder="<?= date('Y') ?>"></div>
            <div class="form-group"><label>Datei *</label><input type="file" name="datei" required></div>
        </div>
        <div style="margin-top:1rem"><button type="submit" class="btn btn-primary">Hochladen</button></div>
    </form>
</div>
<?php endif; ?>

<?php if ($freigegebeneSafeDokumente): ?>
<div class="card">
    <h2>Vom Mieter freigegeben (<?= count($freigegebeneSafeDokumente) ?>)</h2>
    <p style="color:var(--muted);font-size:.85rem;margin-bottom:.75rem">
        Einzelne Dokumente/Bilder aus dem privaten Dokumentensafe der Mieter, die diese ausdrücklich für dich freigegeben haben.
    </p>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Bezeichnung</th><th>Wohnung</th><th>Kategorie</th><th>Datum</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($freigegebeneSafeDokumente as $d): ?>
        <tr>
            <td><?= htmlspecialchars($d['bezeichnung']) ?></td>
            <td><?= htmlspecialchars($d['wohnung']) ?></td>
            <td><span class="badge badge-info"><?= htmlspecialchars($d['kategorie']) ?></span></td>
            <td><?= date('d.m.Y', strtotime($d['hochgeladen_am'])) ?></td>
            <td><a href="datei.php?typ=safe&id=<?= $d['id'] ?>" target="_blank" class="btn btn-sm" style="background:var(--card-bg-high);color:var(--text)">📄 Ansehen</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="card">
    <h2>Alle Dokumente (<?= count($dokumente) ?>)</h2>
    <?php if (!$dokumentGruppen): ?>
    <p style="color:var(--muted)">Keine Dokumente</p>
    <?php endif; ?>
    <?php foreach ($dokumentGruppen as $gruppe): ?>
    <details class="dok-gruppe">
        <summary class="dok-gruppe-titel">
            🏠 <?= htmlspecialchars($gruppe['name']) ?>
            <span class="dok-gruppe-anzahl"><?= count($gruppe['dokumente']) ?></span>
        </summary>
        <div class="table-wrap"><table class="sortable">
            <thead><tr><th>Bezeichnung</th><th>Kategorie</th><th><?= istNurLesend() ? 'Sichtbar für' : 'Wohnung / Sichtbar für' ?></th><th>Jahr</th><th>Datum</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($gruppe['dokumente'] as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['bezeichnung']) ?></td>
                <td><span class="badge badge-info"><?= htmlspecialchars($d['kategorie']) ?></span></td>
                <?php if (istNurLesend()): ?>
                <td><?= htmlspecialchars(ucfirst($d['freigabe'] ?? 'verwaltung')) ?></td>
                <?php else: ?>
                <td>
                    <select name="wohnung_id" form="doku-edit-<?= $d['id'] ?>"
                            style="border:1px solid var(--border);border-radius:5px;padding:.3rem .4rem;font-size:.82rem;background:var(--input-bg);color:var(--text);max-width:150px">
                        <option value="">Alle / Objekt</option>
                        <?php foreach ($wohnungen as $w): ?>
                        <option value="<?= $w['id'] ?>"<?= (int)($d['wohnung_id'] ?? 0) === (int)$w['id'] ? ' selected' : '' ?>><?= htmlspecialchars($w['bezeichnung']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="freigabe" form="doku-edit-<?= $d['id'] ?>"
                            style="border:1px solid var(--border);border-radius:5px;padding:.3rem .4rem;font-size:.82rem;background:var(--input-bg);color:var(--text);margin-top:.3rem">
                        <option value="verwaltung"<?= ($d['freigabe'] ?? 'verwaltung') === 'verwaltung' ? ' selected' : '' ?>>Verwaltung</option>
                        <option value="mieter"<?= ($d['freigabe'] ?? '') === 'mieter' ? ' selected' : '' ?>>+ Mieter</option>
                        <option value="hausmeister"<?= ($d['freigabe'] ?? '') === 'hausmeister' ? ' selected' : '' ?>>+ Hausmeister</option>
                    </select>
                </td>
                <?php endif; ?>
                <td><?= $d['jahr'] ?? '–' ?></td>
                <td><?= date('d.m.Y', strtotime($d['hochgeladen_am'])) ?></td>
                <td>
                    <?php if (!istNurLesend()): ?>
                    <form method="post" id="doku-edit-<?= $d['id'] ?>" style="display:inline"><?= csrfFeld() ?><input type="hidden" name="edit_id" value="<?= $d['id'] ?>"><button type="submit" class="btn btn-sm btn-success" title="Zuordnung speichern">💾</button></form>
                    <?php endif; ?>
                    <a href="datei.php?typ=dokument&id=<?= $d['id'] ?>" target="_blank" class="btn btn-sm" style="background:var(--card-bg-high);color:var(--text)">📄</a>
                    <?php if (!istNurLesend()): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Löschen?')"><?= csrfFeld() ?><input type="hidden" name="delete_id" value="<?= $d['id'] ?>"><button type="submit" class="btn btn-sm btn-danger">✕</button></form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </details>
    <?php endforeach; ?>
</div>

<?php include '../assets/footer.php'; ?>
