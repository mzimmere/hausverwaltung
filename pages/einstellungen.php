<?php
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';
$pageTitle = 'Einstellungen';
$basePath  = '../';

// Nur Admins dürfen die Einstellungen bearbeiten
if (!istAdmin()) {
    header('Location: ../index.php');
    exit;
}

$objektId = aktivesObjekt();

// Hausbild-Verzeichnis (fester Basisname, Endung je nach Upload)
$assetsDir   = dirname(__DIR__) . '/assets/';
$erlaubteExt = ['jpg', 'jpeg', 'png', 'webp'];

// vorhandenes Hausbild suchen
function findeHausbild(string $assetsDir, array $exts): ?string {
    foreach ($exts as $e) {
        if (is_file($assetsDir . 'haus.' . $e)) return 'haus.' . $e;
    }
    return null;
}

// ── Objektdaten speichern ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['objekt_speichern'])) {
    csrfPruefen();
    $wjMonat = max(1, min(12, (int)($_POST['wj_monat'] ?? 1)));
    $wjTag   = max(1, min(31, (int)($_POST['wj_tag'] ?? 1)));
    $stmt = $db->prepare("UPDATE objekt SET name=?,strasse=?,plz=?,ort=?,baujahr=?,verwalter_name=?,verwalter_strasse=?,verwalter_plz=?,verwalter_ort=?,verwalter_telefon=?,verwalter_email=?,wirtschaftsjahr_start_monat=?,wirtschaftsjahr_start_tag=? WHERE id=?");
    $stmt->execute([$_POST['name'],$_POST['strasse'],$_POST['plz'],$_POST['ort'],$_POST['baujahr']?:null,$_POST['vname'],$_POST['vstrasse'],$_POST['vplz'],$_POST['vort'],$_POST['vtelefon'],$_POST['vemail'],$wjMonat,$wjTag,$objektId]);
    $successMsg = 'Einstellungen gespeichert.';
}

// ── Neue Immobilie anlegen ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['objekt_neu'])) {
    csrfPruefen();
    $neuName = trim($_POST['neu_name']);
    if ($neuName === '') {
        $errorMsg = 'Bitte einen Namen für die neue Immobilie angeben.';
    } else {
        $naechsteSortierung = (int)$db->query("SELECT COALESCE(MAX(sortierung),0)+1 FROM objekt")->fetchColumn();
        $stmt = $db->prepare("INSERT INTO objekt (name, strasse, plz, ort, baujahr, sortierung) VALUES (?,?,?,?,?,?)");
        $stmt->execute([
            $neuName,
            trim($_POST['neu_strasse']) ?: null,
            trim($_POST['neu_plz']) ?: null,
            trim($_POST['neu_ort']) ?: null,
            $_POST['neu_baujahr'] ?: null,
            $naechsteSortierung,
        ]);
        setzeAktivesObjekt((int)$db->lastInsertId());
        header('Location: einstellungen.php');
        exit;
    }
}

// ── Immobilie aktivieren / deaktivieren ──────────────────────
if (isset($_GET['objekt_toggle'])) {
    $toggleId = (int)$_GET['objekt_toggle'];
    $stmt = $db->prepare("SELECT aktiv FROM objekt WHERE id=?");
    $stmt->execute([$toggleId]);
    $warAktiv = (bool)$stmt->fetchColumn();
    $anzahlAktiv = (int)$db->query("SELECT COUNT(*) FROM objekt WHERE aktiv=1")->fetchColumn();

    if ($warAktiv && $anzahlAktiv <= 1) {
        $errorMsg = 'Die letzte aktive Immobilie kann nicht deaktiviert werden.';
    } else {
        $db->prepare("UPDATE objekt SET aktiv = 1 - aktiv WHERE id=?")->execute([$toggleId]);
        $successMsg = 'Immobilie aktualisiert.';
    }
}

// ── Hausbild hochladen ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hausbild_upload'])) {
    csrfPruefen();
    if (empty($_FILES['hausbild']['name']) || $_FILES['hausbild']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Bitte eine Bilddatei auswählen.';
    } elseif ($_FILES['hausbild']['size'] > 15 * 1024 * 1024) {
        $errorMsg = 'Das Bild ist zu groß (maximal 15 MB).';
    } else {
        $ext = strtolower(pathinfo($_FILES['hausbild']['name'], PATHINFO_EXTENSION));
        // echten Bildtyp prüfen, nicht nur die Endung
        $info = @getimagesize($_FILES['hausbild']['tmp_name']);
        if (!in_array($ext, $erlaubteExt, true) || $info === false) {
            $errorMsg = 'Nur Bilddateien sind erlaubt (JPG, PNG oder WEBP).';
        } else {
            // alle bisherigen Hausbilder entfernen (auch andere Endungen)
            foreach ($erlaubteExt as $e) {
                if (is_file($assetsDir . 'haus.' . $e)) unlink($assetsDir . 'haus.' . $e);
            }
            if (move_uploaded_file($_FILES['hausbild']['tmp_name'], $assetsDir . 'haus.' . $ext)) {
                $successMsg = 'Hausbild gespeichert – es erscheint jetzt auf der Startseite.';
            } else {
                $errorMsg = 'Das Bild konnte nicht gespeichert werden. Bitte Schreibrechte im assets-Ordner prüfen.';
            }
        }
    }
}

// ── Hausbild löschen ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hausbild_loeschen'])) {
    csrfPruefen();
    foreach ($erlaubteExt as $e) {
        if (is_file($assetsDir . 'haus.' . $e)) unlink($assetsDir . 'haus.' . $e);
    }
    $successMsg = 'Hausbild entfernt.';
}

$stmt = $db->prepare("SELECT * FROM objekt WHERE id=?");
$stmt->execute([$objektId]);
$obj = $stmt->fetch();
$alleObjekte = $db->query("SELECT * FROM objekt ORDER BY sortierung, id")->fetchAll();
$hausbild = findeHausbild($assetsDir, $erlaubteExt);
include '../assets/header.php';
?>
<div class="page-header"><h1>Einstellungen</h1></div>

<div class="card">
    <h2>Immobilien verwalten</h2>
    <p style="color:var(--muted);font-size:.9rem;margin-bottom:1rem">
        Jede Immobilie führt ihre eigenen Wohnungen, Rechnungen, Abrechnungen usw. getrennt.
        Über „Wechseln" bearbeiten Sie die Objektdaten weiter unten für das jeweilige Haus.
    </p>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Name</th><th>Ort</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($alleObjekte as $o): ?>
        <tr<?= (int)$o['id'] === $objektId ? ' style="background:#f0f5fb"' : '' ?>>
            <td><?= htmlspecialchars($o['name']) ?><?= (int)$o['id'] === $objektId ? ' <strong>(aktiv ausgewählt)</strong>' : '' ?></td>
            <td><?= htmlspecialchars($o['ort'] ?? '–') ?></td>
            <td>
                <span class="badge <?= $o['aktiv'] ? 'badge-success' : 'badge-danger' ?>">
                    <?= $o['aktiv'] ? 'aktiv' : 'deaktiviert' ?>
                </span>
            </td>
            <td>
                <div class="btn-group">
                    <?php if ((int)$o['id'] !== $objektId): ?>
                    <a href="?objekt_wechsel=<?= $o['id'] ?>" class="btn btn-sm btn-primary">Wechseln</a>
                    <?php endif; ?>
                    <a href="?objekt_toggle=<?= $o['id'] ?>" class="btn btn-sm" style="background:#e2e8f0"
                       onclick="return confirm('<?= $o['aktiv'] ? 'Immobilie deaktivieren? Sie verschwindet dann aus dem Umschalter, Daten bleiben erhalten.' : 'Immobilie wieder aktivieren?' ?>')">
                        <?= $o['aktiv'] ? 'Deaktivieren' : 'Aktivieren' ?>
                    </a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>

    <form method="post" style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--border,#e2e8f0)">
        <?= csrfFeld() ?>
        <input type="hidden" name="objekt_neu" value="1">
        <label style="display:block;margin-bottom:.6rem;font-weight:600;font-size:.85rem;color:var(--muted)">Neue Immobilie anlegen</label>
        <div class="form-grid">
            <div class="form-group"><label>Name *</label><input type="text" name="neu_name" placeholder="z.B. Musterstraße 5" required></div>
            <div class="form-group"><label>Straße</label><input type="text" name="neu_strasse"></div>
            <div class="form-group"><label>PLZ</label><input type="text" name="neu_plz"></div>
            <div class="form-group"><label>Ort</label><input type="text" name="neu_ort"></div>
            <div class="form-group"><label>Baujahr</label><input type="number" name="neu_baujahr"></div>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary">Immobilie anlegen</button>
        </div>
    </form>
</div>

<form method="post">
<?= csrfFeld() ?>
<input type="hidden" name="objekt_speichern" value="1">
<div class="card">
    <h2>Objektdaten</h2>
    <div class="form-grid">
        <div class="form-group"><label>Objektname</label><input type="text" name="name" value="<?= htmlspecialchars($obj['name'] ?? '') ?>"></div>
        <div class="form-group"><label>Straße</label><input type="text" name="strasse" value="<?= htmlspecialchars($obj['strasse'] ?? '') ?>"></div>
        <div class="form-group"><label>PLZ</label><input type="text" name="plz" value="<?= htmlspecialchars($obj['plz'] ?? '') ?>"></div>
        <div class="form-group"><label>Ort</label><input type="text" name="ort" value="<?= htmlspecialchars($obj['ort'] ?? '') ?>"></div>
        <div class="form-group"><label>Baujahr</label><input type="number" name="baujahr" value="<?= $obj['baujahr'] ?? '' ?>"></div>
    </div>
</div>
<div class="card">
    <h2>Wirtschaftsjahr</h2>
    <p style="color:var(--muted);font-size:.9rem;margin-bottom:1rem">
        Ab wann läuft das Wirtschaftsjahr dieses Hauses? Standard ist der 1. Januar (Kalenderjahr).
        Wird für den Kosten-Tacho verwendet, damit „laufende Kosten" den richtigen Zeitraum meint.
    </p>
    <div class="form-grid">
        <div class="form-group">
            <label>Beginnt am</label>
            <div style="display:flex;gap:.5rem;align-items:center">
                <input type="number" name="wj_tag" value="<?= $obj['wirtschaftsjahr_start_tag'] ?? 1 ?>" min="1" max="31" style="width:70px">
                <select name="wj_monat">
                    <?php
                    $monatsNamen = [1=>'Januar',2=>'Februar',3=>'März',4=>'April',5=>'Mai',6=>'Juni',7=>'Juli',8=>'August',9=>'September',10=>'Oktober',11=>'November',12=>'Dezember'];
                    $aktMonat = (int)($obj['wirtschaftsjahr_start_monat'] ?? 1);
                    foreach ($monatsNamen as $num => $name): ?>
                    <option value="<?= $num ?>"<?= $aktMonat === $num ? ' selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <h2>Verwalter / Erscheint auf PDFs</h2>
    <div class="form-grid">
        <div class="form-group"><label>Name</label><input type="text" name="vname" value="<?= htmlspecialchars($obj['verwalter_name'] ?? '') ?>"></div>
        <div class="form-group"><label>Straße</label><input type="text" name="vstrasse" value="<?= htmlspecialchars($obj['verwalter_strasse'] ?? '') ?>"></div>
        <div class="form-group"><label>PLZ</label><input type="text" name="vplz" value="<?= htmlspecialchars($obj['verwalter_plz'] ?? '') ?>"></div>
        <div class="form-group"><label>Ort</label><input type="text" name="vort" value="<?= htmlspecialchars($obj['verwalter_ort'] ?? '') ?>"></div>
        <div class="form-group"><label>Telefon</label><input type="tel" name="vtelefon" value="<?= htmlspecialchars($obj['verwalter_telefon'] ?? '') ?>"></div>
        <div class="form-group"><label>E-Mail</label><input type="email" name="vemail" value="<?= htmlspecialchars($obj['verwalter_email'] ?? '') ?>"></div>
    </div>
</div>
<div style="padding:0 0 1.5rem"><button type="submit" class="btn btn-primary">Einstellungen speichern</button></div>
</form>

<div class="card">
    <h2>Audit-Log</h2>
    <p style="color:var(--muted);font-size:.9rem;margin-bottom:1rem">
        Wer hat wann was angelegt, geändert oder gelöscht – für das aktuell ausgewählte Haus.
    </p>
    <a href="audit.php" class="btn btn-sm btn-primary">Verlauf ansehen →</a>
</div>

<div class="card">
    <h2>Hausbild (Hintergrund der Startseite)</h2>
    <p style="color:var(--muted);font-size:.9rem;margin-bottom:1rem">
        Dieses Bild erscheint als großflächiger Hintergrund auf dem Dashboard.
        Am besten ein Querformat-Foto (z. B. 1600×900). Erlaubt: JPG, PNG, WEBP, max. 15 MB.
    </p>

    <?php if ($hausbild): ?>
    <div style="margin-bottom:1rem">
        <img src="../assets/<?= htmlspecialchars($hausbild) ?>?v=<?= filemtime($assetsDir . $hausbild) ?>"
             alt="Aktuelles Hausbild"
             style="max-width:100%;width:420px;border-radius:10px;box-shadow:var(--shadow);display:block">
    </div>
    <form method="post" style="display:inline" onsubmit="return confirm('Hausbild wirklich entfernen?')">
        <?= csrfFeld() ?>
        <input type="hidden" name="hausbild_loeschen" value="1">
        <button type="submit" class="btn btn-danger btn-sm">Hausbild entfernen</button>
    </form>
    <span style="color:var(--muted);font-size:.85rem;margin-left:.5rem">Zum Austauschen einfach ein neues Bild hochladen.</span>
    <?php else: ?>
    <p style="color:var(--muted);margin-bottom:1rem">Aktuell ist kein Hausbild hinterlegt – die Startseite zeigt den normalen Kopf.</p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="margin-top:1rem">
        <?= csrfFeld() ?>
        <input type="hidden" name="hausbild_upload" value="1">
        <div class="form-group" style="max-width:420px">
            <label><?= $hausbild ? 'Neues Bild hochladen' : 'Bild hochladen' ?></label>
            <input type="file" name="hausbild" accept=".jpg,.jpeg,.png,.webp" required>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary"><?= $hausbild ? 'Bild ersetzen' : 'Bild hochladen' ?></button>
        </div>
    </form>
</div>

<?php include '../assets/footer.php'; ?>
