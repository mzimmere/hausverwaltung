<?php
/**
 * Fotoalbum – vom Vermieter gepflegte Bildersammlung fürs ganze Haus,
 * in frei anlegbaren Kategorien. Pro Foto legt die Verwaltung fest,
 * welche Wohnungen (Mieter) es sehen dürfen.
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';
require_once '../includes/bildkonvertierung.php';
$pageTitle = 'Fotoalbum';
$basePath  = '../';

$user = aktuellerBenutzer();
if (!in_array($user['rolle'], ['admin', 'leser', 'mieter'], true)) {
    header('Location: ../index.php');
    exit;
}

$erlaubteExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'dng'];

// ============================================================
// Mieter: reine Ansicht der für die eigene Wohnung freigegebenen Fotos
// ============================================================
if ($user['rolle'] === 'mieter') {
    $wohnungId = (int)($user['wohnung_id'] ?? 0);
    $bilder = [];
    if ($wohnungId > 0) {
        $stmt = $db->prepare("
            SELECT fb.*, fk.bezeichnung AS kategorie
            FROM fotoalbum_bilder fb
            JOIN fotoalbum_sichtbarkeit fs ON fs.bild_id = fb.id AND fs.wohnung_id = ?
            JOIN fotoalbum_kategorien fk ON fb.kategorie_id = fk.id
            ORDER BY fb.hochgeladen_am DESC
        ");
        $stmt->execute([$wohnungId]);
        $bilder = $stmt->fetchAll();
    }
    $bilderJeKategorie = [];
    foreach ($bilder as $b) {
        $bilderJeKategorie[$b['kategorie']][] = $b;
    }

    include '../assets/header.php';
    ?>
    <div class="page-header"><h1>Fotoalbum</h1></div>
    <?php if (!$bilder): ?>
    <div class="card"><p style="color:var(--muted)">Noch keine Fotos für dich freigegeben.</p></div>
    <?php endif; ?>
    <?php foreach ($bilderJeKategorie as $katName => $katBilder): ?>
    <div class="card">
        <h2><?= htmlspecialchars($katName) ?></h2>
        <div class="fotoalbum-grid">
            <?php foreach ($katBilder as $b):
                $hatVorschau = $b['vorschau_dateiname'] || istBrowserAnzeigbaresBild($b['dateiname']);
            ?>
            <a href="datei.php?typ=fotoalbum&id=<?= $b['id'] ?>" target="_blank" class="fotoalbum-kachel">
                <?php if ($hatVorschau): ?>
                <img src="datei.php?typ=fotoalbum&id=<?= $b['id'] ?>&vorschau=1" alt="<?= htmlspecialchars($b['bezeichnung']) ?>" loading="lazy">
                <?php else: ?>
                <span class="fotoalbum-keine-vorschau">📷<small><?= strtoupper(pathinfo($b['dateiname'], PATHINFO_EXTENSION)) ?></small></span>
                <?php endif; ?>
                <span class="fotoalbum-kachel-titel"><?= htmlspecialchars($b['bezeichnung']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php include '../assets/footer.php'; ?>
    <?php
    exit;
}

// ============================================================
// Admin/Leser: Verwaltung
// ============================================================
$objektId = aktivesObjekt();

$wStmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=? ORDER BY id");
$wStmt->execute([$objektId]);
$wohnungen = $wStmt->fetchAll();

// ── Neue Kategorie ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kategorie_neu'])) {
    leserSchreibschutz();
    csrfPruefen();
    $name = trim($_POST['kategorie_neu']);
    if ($name === '') {
        $errorMsg = 'Bitte einen Namen für die Kategorie angeben.';
    } else {
        $sortStmt = $db->prepare("SELECT COALESCE(MAX(sortierung),0)+1 FROM fotoalbum_kategorien WHERE objekt_id=?");
        $sortStmt->execute([$objektId]);
        $naechsteSort = (int)$sortStmt->fetchColumn();
        $db->prepare("INSERT INTO fotoalbum_kategorien (objekt_id, bezeichnung, sortierung) VALUES (?,?,?)")
           ->execute([$objektId, $name, $naechsteSort]);
        $successMsg = 'Kategorie angelegt.';
    }
}

// ── Kategorie umbenennen ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kategorie_umbenennen_id'])) {
    leserSchreibschutz();
    csrfPruefen();
    $kid  = (int)$_POST['kategorie_umbenennen_id'];
    $name = trim($_POST['kategorie_neuer_name'] ?? '');
    if ($name !== '') {
        $db->prepare("UPDATE fotoalbum_kategorien SET bezeichnung=? WHERE id=? AND objekt_id=?")
           ->execute([$name, $kid, $objektId]);
        $successMsg = 'Kategorie umbenannt.';
    }
}

// ── Kategorie löschen (nur wenn leer) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kategorie_loeschen_id'])) {
    leserSchreibschutz();
    csrfPruefen();
    $kid = (int)$_POST['kategorie_loeschen_id'];
    $anzahlStmt = $db->prepare("SELECT COUNT(*) FROM fotoalbum_bilder WHERE kategorie_id=? AND objekt_id=?");
    $anzahlStmt->execute([$kid, $objektId]);
    if ((int)$anzahlStmt->fetchColumn() > 0) {
        $errorMsg = 'Diese Kategorie enthält noch Fotos – erst diese löschen oder verschieben.';
    } else {
        $db->prepare("DELETE FROM fotoalbum_kategorien WHERE id=? AND objekt_id=?")->execute([$kid, $objektId]);
        $successMsg = 'Kategorie gelöscht.';
    }
}

// ── Foto hochladen ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bild_hochladen'])) {
    leserSchreibschutz();
    csrfPruefen();
    $katId   = (int)($_POST['kategorie_id'] ?? 0);
    $bezeich = trim($_POST['bezeichnung'] ?? '');
    $sichtbarFuer = array_map('intval', $_POST['wohnung_ids'] ?? []);
    $katStmt = $db->prepare("SELECT id FROM fotoalbum_kategorien WHERE id=? AND objekt_id=?");
    $katStmt->execute([$katId, $objektId]);
    if (!$katStmt->fetch()) {
        $errorMsg = 'Bitte eine gültige Kategorie wählen.';
    } elseif ($bezeich === '') {
        $errorMsg = 'Bitte eine Bezeichnung angeben.';
    } elseif (in_array($_FILES['bild']['error'] ?? UPLOAD_ERR_OK, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        $errorMsg = 'Die Datei ist zu groß für die Server-Konfiguration (betrifft v.a. DNG-Rohdaten).';
    } elseif (empty($_FILES['bild']['name'])) {
        $errorMsg = 'Bitte ein Bild auswählen.';
    } else {
        $ext = strtolower(pathinfo($_FILES['bild']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $erlaubteExt, true)) {
            $errorMsg = 'Dieser Dateityp wird nicht unterstützt (nur Bilder, auch HEIC/DNG).';
        } else {
            $zielDir = UPLOAD_FOTOALBUM . $objektId . '/';
            if (!is_dir($zielDir)) mkdir($zielDir, 0777, true);
            $basisname = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
            $dateiname = $basisname . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['bild']['name']);
            move_uploaded_file($_FILES['bild']['tmp_name'], $zielDir . $dateiname);

            // Für Formate, die Browser nicht direkt anzeigen können (HEIC/DNG),
            // zusätzlich ein JPEG-Vorschaubild erzeugen, falls der Server das
            // unterstützt. Original bleibt in jedem Fall erhalten.
            $vorschauDateiname = null;
            if (in_array($ext, NICHT_BROWSER_ANZEIGBARE_BILDFORMATE, true)) {
                $vorschauName = $basisname . '_vorschau.jpg';
                if (bildVorschauErzeugen($zielDir . $dateiname, $zielDir . $vorschauName)) {
                    $vorschauDateiname = $objektId . '/' . $vorschauName;
                }
            }

            $stmt = $db->prepare("INSERT INTO fotoalbum_bilder (objekt_id, kategorie_id, bezeichnung, dateiname, vorschau_dateiname, hochgeladen_von) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$objektId, $katId, $bezeich, $objektId . '/' . $dateiname, $vorschauDateiname, $user['id']]);
            $neuId = (int)$db->lastInsertId();
            if ($sichtbarFuer) {
                $sichtStmt = $db->prepare("INSERT INTO fotoalbum_sichtbarkeit (bild_id, wohnung_id) VALUES (?,?)");
                $wohnungIds = array_column($wohnungen, 'id');
                foreach ($sichtbarFuer as $wid) {
                    if (in_array($wid, $wohnungIds, true)) $sichtStmt->execute([$neuId, $wid]);
                }
            }
            protokolliere('fotoalbum', 'anlegen', $neuId, "Foto \"$bezeich\" hochgeladen");
            $successMsg = 'Foto hochgeladen.';
        }
    }
}

// ── Sichtbarkeit eines Fotos ändern ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bild_sichtbarkeit_id'])) {
    leserSchreibschutz();
    csrfPruefen();
    $bid = (int)$_POST['bild_sichtbarkeit_id'];
    $pruefStmt = $db->prepare("SELECT id FROM fotoalbum_bilder WHERE id=? AND objekt_id=?");
    $pruefStmt->execute([$bid, $objektId]);
    if ($pruefStmt->fetch()) {
        $db->prepare("DELETE FROM fotoalbum_sichtbarkeit WHERE bild_id=?")->execute([$bid]);
        $sichtbarFuer = array_map('intval', $_POST['wohnung_ids'] ?? []);
        $wohnungIds = array_column($wohnungen, 'id');
        $sichtStmt = $db->prepare("INSERT INTO fotoalbum_sichtbarkeit (bild_id, wohnung_id) VALUES (?,?)");
        foreach ($sichtbarFuer as $wid) {
            if (in_array($wid, $wohnungIds, true)) $sichtStmt->execute([$bid, $wid]);
        }
        $successMsg = 'Sichtbarkeit gespeichert.';
    }
}

// ── Foto löschen ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bild_loeschen_id'])) {
    leserSchreibschutz();
    csrfPruefen();
    $bid = (int)$_POST['bild_loeschen_id'];
    $stmt = $db->prepare("SELECT dateiname FROM fotoalbum_bilder WHERE id=? AND objekt_id=?");
    $stmt->execute([$bid, $objektId]);
    if ($row = $stmt->fetch()) {
        $pfad = UPLOAD_FOTOALBUM . str_replace(['..', '\\'], '', $row['dateiname']);
        if (is_file($pfad)) @unlink($pfad);
        $db->prepare("DELETE FROM fotoalbum_bilder WHERE id=? AND objekt_id=?")->execute([$bid, $objektId]);
        protokolliere('fotoalbum', 'loeschen', $bid, 'Foto gelöscht');
        $successMsg = 'Foto gelöscht.';
    }
}

// ── Starter-Kategorien einmalig, falls noch keine existieren ──
$anzKatStmt = $db->prepare("SELECT COUNT(*) FROM fotoalbum_kategorien WHERE objekt_id=?");
$anzKatStmt->execute([$objektId]);
if ((int)$anzKatStmt->fetchColumn() === 0) {
    $seedStmt = $db->prepare("INSERT INTO fotoalbum_kategorien (objekt_id, bezeichnung, sortierung) VALUES (?,?,?)");
    foreach (['Außenbereich', 'Gemeinschaftsräume', 'Sonstiges'] as $i => $name) {
        $seedStmt->execute([$objektId, $name, $i]);
    }
}

// ── Daten für die Anzeige ──────────────────────────────────────
$katStmt = $db->prepare("SELECT * FROM fotoalbum_kategorien WHERE objekt_id=? ORDER BY sortierung, id");
$katStmt->execute([$objektId]);
$kategorien = $katStmt->fetchAll();

$bStmt = $db->prepare("SELECT * FROM fotoalbum_bilder WHERE objekt_id=? ORDER BY hochgeladen_am DESC");
$bStmt->execute([$objektId]);
$bilderAlle = $bStmt->fetchAll();

$sichtbarkeitJeBild = [];
if ($bilderAlle) {
    $bildIds = array_column($bilderAlle, 'id');
    $platzhalter = implode(',', array_fill(0, count($bildIds), '?'));
    $sichtStmt = $db->prepare("SELECT bild_id, wohnung_id FROM fotoalbum_sichtbarkeit WHERE bild_id IN ($platzhalter)");
    $sichtStmt->execute($bildIds);
    foreach ($sichtStmt->fetchAll() as $row) {
        $sichtbarkeitJeBild[(int)$row['bild_id']][] = (int)$row['wohnung_id'];
    }
}

$bilderJeKategorie = [];
foreach ($bilderAlle as $b) {
    $bilderJeKategorie[(int)$b['kategorie_id']][] = $b;
}

include '../assets/header.php';
?>
<div class="page-header"><h1>Fotoalbum</h1></div>
<p style="color:var(--muted);font-size:.85rem;margin:-.5rem 0 1.25rem">
    Vom Vermieter gepflegtes Fotoalbum fürs ganze Haus. Pro Foto legst du fest, welche Wohnungen (Mieter) es sehen dürfen.
    Auch HEIC (iPhone-Fotos) und DNG (Kamera-Rohdaten) können hochgeladen werden – für die Vorschau wird automatisch ein
    JPEG erzeugt, sofern der Server das unterstützt; falls nicht, wird nur das 📷-Symbol angezeigt, das Original bleibt
    aber immer per Klick als Download verfügbar.
</p>

<?php if (!istNurLesend()): ?>
<div class="card">
    <h2>Neue Kategorie anlegen</h2>
    <form method="post" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
        <?= csrfFeld() ?>
        <div class="form-group" style="flex:1;min-width:200px">
            <label>Name der Kategorie</label>
            <input type="text" name="kategorie_neu" placeholder="z.B. Baumaßnahmen 2026" required>
        </div>
        <div><button type="submit" class="btn btn-primary">+ Anlegen</button></div>
    </form>
</div>

<div class="card">
    <h2>Foto hochladen</h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrfFeld() ?>
        <input type="hidden" name="bild_hochladen" value="1">
        <div class="form-grid">
            <div class="form-group">
                <label>Kategorie *</label>
                <select name="kategorie_id" required>
                    <?php foreach ($kategorien as $k): ?>
                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['bezeichnung']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Bezeichnung *</label><input type="text" name="bezeichnung" placeholder="z.B. Neuer Anstrich Treppenhaus" required></div>
            <div class="form-group"><label>Bild *</label><input type="file" name="bild" accept="image/*,.heic,.heif,.dng" required></div>
        </div>
        <div class="form-group" style="margin-top:1rem">
            <label>Sichtbar für</label>
            <div style="display:flex;flex-wrap:wrap;gap:.4rem 1.2rem;margin-top:.3rem">
                <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;cursor:pointer">
                    <input type="checkbox" onclick="document.querySelectorAll('.fa-upload-wohnung').forEach(c=>c.checked=this.checked)"> <strong>Alle Mieter</strong>
                </label>
                <?php foreach ($wohnungen as $w): ?>
                <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;cursor:pointer">
                    <input type="checkbox" class="fa-upload-wohnung" name="wohnung_ids[]" value="<?= $w['id'] ?>"> <?= htmlspecialchars($w['bezeichnung']) ?><?= $w['mieter_name'] ? ' – ' . htmlspecialchars($w['mieter_name']) : '' ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="margin-top:1rem"><button type="submit" class="btn btn-primary">Hochladen</button></div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Alle Fotos (<?= count($bilderAlle) ?>)</h2>
    <?php if (!$kategorien): ?><p style="color:var(--muted)">Keine Kategorien</p><?php endif; ?>
    <?php foreach ($kategorien as $k):
        $bilderHier = $bilderJeKategorie[(int)$k['id']] ?? [];
    ?>
    <details class="dok-gruppe">
        <summary class="dok-gruppe-titel">
            <?= htmlspecialchars($k['bezeichnung']) ?>
            <span class="dok-gruppe-anzahl"><?= count($bilderHier) ?></span>
        </summary>
        <div style="padding:.75rem 1rem 1rem">
            <?php if (!istNurLesend()): ?>
            <div style="display:flex;justify-content:flex-end;gap:.4rem;margin-bottom:.75rem">
                <button type="button" class="btn btn-sm" style="background:var(--card-bg-high);color:var(--text)"
                        onclick="document.getElementById('fa-umbenennen-<?= $k['id'] ?>').style.display='flex'">✏️ Umbenennen</button>
                <?php if (!$bilderHier): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Kategorie „<?= htmlspecialchars(addslashes($k['bezeichnung'])) ?>“ wirklich löschen?')">
                    <?= csrfFeld() ?><input type="hidden" name="kategorie_loeschen_id" value="<?= $k['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">✕ Löschen</button>
                </form>
                <?php endif; ?>
            </div>
            <form method="post" id="fa-umbenennen-<?= $k['id'] ?>" style="display:none;gap:.5rem;margin-bottom:1rem;align-items:end">
                <?= csrfFeld() ?><input type="hidden" name="kategorie_umbenennen_id" value="<?= $k['id'] ?>">
                <div class="form-group" style="flex:1"><label>Neuer Name</label><input type="text" name="kategorie_neuer_name" value="<?= htmlspecialchars($k['bezeichnung']) ?>" required></div>
                <div><button type="submit" class="btn btn-sm btn-primary">Speichern</button></div>
            </form>
            <?php endif; ?>

            <?php if ($bilderHier): ?>
            <div class="fotoalbum-grid">
                <?php foreach ($bilderHier as $b):
                    $sichtbarIds = $sichtbarkeitJeBild[(int)$b['id']] ?? [];
                    $sichtbarNamen = array_map(fn($w) => $w['bezeichnung'], array_filter($wohnungen, fn($w) => in_array((int)$w['id'], $sichtbarIds, true)));
                    $hatVorschau = $b['vorschau_dateiname'] || istBrowserAnzeigbaresBild($b['dateiname']);
                ?>
                <div class="fotoalbum-kachel">
                    <a href="datei.php?typ=fotoalbum&id=<?= $b['id'] ?>" target="_blank">
                        <?php if ($hatVorschau): ?>
                        <img src="datei.php?typ=fotoalbum&id=<?= $b['id'] ?>&vorschau=1" alt="<?= htmlspecialchars($b['bezeichnung']) ?>" loading="lazy">
                        <?php else: ?>
                        <span class="fotoalbum-keine-vorschau">📷<small><?= strtoupper(pathinfo($b['dateiname'], PATHINFO_EXTENSION)) ?></small></span>
                        <?php endif; ?>
                    </a>
                    <span class="fotoalbum-kachel-titel"><?= htmlspecialchars($b['bezeichnung']) ?></span>
                    <span style="font-size:.72rem;color:var(--muted);display:block;padding:0 .1rem">
                        <?= $sichtbarNamen ? 'Sichtbar für: ' . htmlspecialchars(implode(', ', $sichtbarNamen)) : 'Für niemanden freigegeben' ?>
                    </span>
                    <?php if (!istNurLesend()): ?>
                    <div style="display:flex;gap:.3rem;margin-top:.4rem">
                        <button type="button" class="btn btn-sm" style="background:var(--card-bg-high);color:var(--text);flex:1"
                                onclick="document.getElementById('fa-sicht-<?= $b['id'] ?>').style.display='block'">Sichtbarkeit</button>
                        <form method="post" onsubmit="return confirm('Foto löschen?')">
                            <?= csrfFeld() ?><input type="hidden" name="bild_loeschen_id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">✕</button>
                        </form>
                    </div>
                    <form method="post" id="fa-sicht-<?= $b['id'] ?>" style="display:none;margin-top:.5rem;padding:.6rem;background:var(--card-bg-high);border-radius:8px">
                        <?= csrfFeld() ?><input type="hidden" name="bild_sichtbarkeit_id" value="<?= $b['id'] ?>">
                        <div style="display:flex;flex-direction:column;gap:.3rem;margin-bottom:.5rem">
                            <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.82rem;cursor:pointer">
                                <input type="checkbox" onclick="this.closest('form').querySelectorAll('.fa-edit-wohnung').forEach(c=>c.checked=this.checked)"> <strong>Alle Mieter</strong>
                            </label>
                            <?php foreach ($wohnungen as $w): ?>
                            <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.82rem;cursor:pointer">
                                <input type="checkbox" class="fa-edit-wohnung" name="wohnung_ids[]" value="<?= $w['id'] ?>"<?= in_array((int)$w['id'], $sichtbarIds, true) ? ' checked' : '' ?>>
                                <?= htmlspecialchars($w['bezeichnung']) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary">Speichern</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color:var(--muted);font-size:.88rem">Noch keine Fotos in dieser Kategorie.</p>
            <?php endif; ?>
        </div>
    </details>
    <?php endforeach; ?>
</div>

<?php include '../assets/footer.php'; ?>
