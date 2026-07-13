<?php
/**
 * Wartungsplan – Aufgaben für den Hausmeister.
 * Admin:       Aufgaben anlegen (einmalig oder wiederkehrend),
 *              löschen, Status und Verlauf sehen, selbst abhaken.
 * Hausmeister: offene Aufgaben sehen und mit optionaler Bemerkung
 *              abhaken; außerdem für ihn freigegebene Dokumente
 *              (z. B. Wartungsanleitungen) einsehen.
 * Wiederkehrend: Beim Abhaken einer Aufgabe mit Intervall wird
 * automatisch die nächste Aufgabe mit neuer Fälligkeit angelegt.
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';
$pageTitle = 'Wartungsplan';
$basePath  = '../';

$user = aktuellerBenutzer();
if (!in_array($user['rolle'], ['admin', 'hausmeister'], true)) {
    header('Location: ../index.php');
    exit;
}
$istHausmeister = ($user['rolle'] === 'hausmeister');

$objektId = aktivesObjekt();

$intervalle = [
    0  => 'einmalig',
    1  => 'monatlich',
    3  => 'alle 3 Monate',
    6  => 'halbjährlich',
    12 => 'jährlich',
    24 => 'alle 2 Jahre',
];

// ── Aufgabe anlegen (nur Admin) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['neue_aufgabe']) && istAdmin()) {
    csrfPruefen();
    $titel     = trim($_POST['titel'] ?? '');
    $beschr    = trim($_POST['beschreibung'] ?? '');
    $faellig   = $_POST['faellig_am'] !== '' ? $_POST['faellig_am'] : null;
    $intervall = array_key_exists((int)$_POST['intervall'], $intervalle) ? (int)$_POST['intervall'] : 0;

    $wertLabel = trim($_POST['wert_label'] ?? '');

    if ($titel === '') {
        $errorMsg = 'Bitte einen Titel angeben.';
    } else {
        $db->prepare("
            INSERT INTO wartungsaufgaben (objekt_id, titel, beschreibung, faellig_am, intervall_monate, wert_label)
            VALUES (?,?,?,?,?,?)
        ")->execute([$objektId, $titel, $beschr, $faellig, $intervall, $wertLabel]);
        $successMsg = 'Aufgabe angelegt.';
    }
}

// ── Aufgabe abhaken (Admin und Hausmeister) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['erledigen_id'])) {
    csrfPruefen();
    $aId = (int)$_POST['erledigen_id'];
    $stmt = $db->prepare("SELECT * FROM wartungsaufgaben WHERE id = ? AND status = 'offen' AND objekt_id = ?");
    $stmt->execute([$aId, $objektId]);
    if ($aufgabe = $stmt->fetch()) {
        $wert = trim($_POST['wert'] ?? '');
        if ($aufgabe['wert_label'] !== '' && $wert === '') {
            $errorMsg = 'Bitte auch „' . htmlspecialchars($aufgabe['wert_label']) . '“ mit angeben.';
        } else {
            $db->prepare("
                UPDATE wartungsaufgaben
                SET status = 'erledigt', erledigt_am = NOW(), erledigt_von = ?, bemerkung = ?, wert = ?
                WHERE id = ?
            ")->execute([$user['id'], trim($_POST['bemerkung'] ?? ''), $wert, $aId]);

            // Wiederkehrende Aufgabe: nächste Fälligkeit automatisch anlegen
            // (die Wert-Abfrage wird mit übernommen)
            if ((int)$aufgabe['intervall_monate'] > 0) {
                $basis = $aufgabe['faellig_am'] ?: date('Y-m-d');
                $naechste = date('Y-m-d', strtotime($basis . ' +' . (int)$aufgabe['intervall_monate'] . ' months'));
                // Falls die Aufgabe lange überfällig war: vom heutigen Datum aus rechnen
                if ($naechste <= date('Y-m-d')) {
                    $naechste = date('Y-m-d', strtotime('+' . (int)$aufgabe['intervall_monate'] . ' months'));
                }
                $db->prepare("
                    INSERT INTO wartungsaufgaben (objekt_id, titel, beschreibung, faellig_am, intervall_monate, wert_label)
                    VALUES (?,?,?,?,?,?)
                ")->execute([$objektId, $aufgabe['titel'], $aufgabe['beschreibung'], $naechste, (int)$aufgabe['intervall_monate'], $aufgabe['wert_label']]);
            }
            header('Location: wartung.php?ok=1');
            exit;
        }
    }
}
if (isset($_GET['ok'])) $successMsg = 'Aufgabe als erledigt markiert.';

// ── Aufgabe löschen (nur Admin) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id']) && istAdmin()) {
    csrfPruefen();
    $db->prepare("DELETE FROM wartungsaufgaben WHERE id = ? AND objekt_id = ?")->execute([(int)$_POST['delete_id'], $objektId]);
    header('Location: wartung.php');
    exit;
}

// ── Daten laden ──────────────────────────────────────────────
$offStmt = $db->prepare("
    SELECT * FROM wartungsaufgaben
    WHERE status = 'offen' AND objekt_id = ?
    ORDER BY faellig_am IS NULL, faellig_am, id
");
$offStmt->execute([$objektId]);
$offene = $offStmt->fetchAll();

$erlStmt = $db->prepare("
    SELECT a.*, b.name AS erlediger
    FROM wartungsaufgaben a
    LEFT JOIN benutzer b ON a.erledigt_von = b.id
    WHERE a.status = 'erledigt' AND a.objekt_id = ?
    ORDER BY a.erledigt_am DESC
    LIMIT 15
");
$erlStmt->execute([$objektId]);
$erledigte = $erlStmt->fetchAll();

// Dokumente für den Hausmeister (Anleitungen etc.)
$hmDokumente = [];
if ($istHausmeister) {
    $hmStmt = $db->prepare("
        SELECT d.*, dk.bezeichnung AS kategorie
        FROM dokumente d
        LEFT JOIN dokument_kategorien dk ON d.kategorie_id = dk.id
        WHERE d.freigabe = 'hausmeister' AND d.objekt_id = ?
        ORDER BY d.hochgeladen_am DESC
    ");
    $hmStmt->execute([$objektId]);
    $hmDokumente = $hmStmt->fetchAll();
}

// Letzter gemeldeter Wert je Aufgaben-Titel (für wiederkehrende Aufgaben)
$letzteWerte = [];
$lwStmt = $db->prepare("
    SELECT titel, wert, erledigt_am
    FROM wartungsaufgaben
    WHERE status = 'erledigt' AND wert <> '' AND objekt_id = ?
    ORDER BY erledigt_am ASC
");
$lwStmt->execute([$objektId]);
foreach ($lwStmt as $lw) {
    $letzteWerte[$lw['titel']] = $lw; // aufsteigend sortiert – der letzte Eintrag gewinnt
}

$heute = date('Y-m-d');
$baldGrenze = date('Y-m-d', strtotime('+7 days'));

include '../assets/header.php';
?>
<div class="page-header"><h1>Wartungsplan</h1></div>

<?php if (istAdmin()): ?>
<div class="card">
    <h2>Neue Aufgabe anlegen</h2>
    <form method="post">
        <input type="hidden" name="neue_aufgabe" value="1">
        <?= csrfFeld() ?>
        <div class="form-grid">
            <div class="form-group">
                <label>Titel *</label>
                <input type="text" name="titel" placeholder="z. B. Heizungsreinigung" required>
            </div>
            <div class="form-group">
                <label>Fällig am</label>
                <input type="date" name="faellig_am">
            </div>
            <div class="form-group">
                <label>Wiederholung</label>
                <select name="intervall">
                    <?php foreach ($intervalle as $iWert => $iLabel): ?>
                    <option value="<?= $iWert ?>"><?= htmlspecialchars($iLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Wert-Abfrage beim Abhaken <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
                <input type="text" name="wert_label" placeholder="z. B. Füllstand in Litern">
            </div>
            <div class="form-group" style="grid-column: 1 / -1">
                <label>Beschreibung / Hinweise für den Hausmeister</label>
                <textarea name="beschreibung" placeholder="z. B. Brenner und Filter reinigen, Wartungsheft im Heizungskeller ausfüllen"></textarea>
            </div>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary">Aufgabe anlegen</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Offene Aufgaben (<?= count($offene) ?>)</h2>
    <?php if ($offene): ?>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Aufgabe</th><th>Fällig</th><th>Wiederholung</th><th style="min-width:260px">Erledigen</th><?= istAdmin() ? '<th></th>' : '' ?></tr></thead>
        <tbody>
        <?php foreach ($offene as $a): ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($a['titel']) ?></strong>
                <?php if ($a['beschreibung']): ?>
                <div style="font-size:.82rem;color:var(--muted);margin-top:.2rem"><?= nl2br(htmlspecialchars($a['beschreibung'])) ?></div>
                <?php endif; ?>
                <?php if ($a['wert_label'] !== '' && isset($letzteWerte[$a['titel']])): ?>
                <div style="font-size:.82rem;margin-top:.3rem">
                    <span class="badge badge-info">Letzter Wert: <?= htmlspecialchars($letzteWerte[$a['titel']]['wert']) ?>
                    (<?= date('d.m.Y', strtotime($letzteWerte[$a['titel']]['erledigt_am'])) ?>)</span>
                </div>
                <?php endif; ?>
            </td>
            <td style="white-space:nowrap">
                <?php if ($a['faellig_am']): ?>
                    <?= date('d.m.Y', strtotime($a['faellig_am'])) ?><br>
                    <?php if ($a['faellig_am'] < $heute): ?>
                    <span class="badge badge-danger">überfällig</span>
                    <?php elseif ($a['faellig_am'] <= $baldGrenze): ?>
                    <span class="badge badge-warning">bald fällig</span>
                    <?php endif; ?>
                <?php else: ?>
                <span style="color:var(--muted)">–</span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($intervalle[(int)$a['intervall_monate']] ?? $a['intervall_monate'] . ' Monate') ?></td>
            <td>
                <form method="post" style="display:flex;gap:.4rem;flex-wrap:wrap" onsubmit="return confirm('Aufgabe als erledigt abhaken?')">
                    <?= csrfFeld() ?>
                    <input type="hidden" name="erledigen_id" value="<?= $a['id'] ?>">
                    <?php if ($a['wert_label'] !== ''): ?>
                    <input type="text" name="wert" required placeholder="<?= htmlspecialchars($a['wert_label']) ?> *"
                           style="flex:1;min-width:130px;border:1px solid #e8a020;border-radius:5px;padding:.35rem .6rem;font-size:.85rem;background:var(--input-bg);color:var(--text)">
                    <?php endif; ?>
                    <input type="text" name="bemerkung" placeholder="Bemerkung (optional)"
                           style="flex:1;min-width:140px;border:1px solid var(--border);border-radius:5px;padding:.35rem .6rem;font-size:.85rem;background:var(--input-bg);color:var(--text)">
                    <button type="submit" class="btn btn-sm btn-success">✓ Erledigt</button>
                </form>
            </td>
            <?php if (istAdmin()): ?>
            <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Aufgabe löschen?')">
                    <?= csrfFeld() ?>
                    <input type="hidden" name="delete_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">✕</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted)">Keine offenen Aufgaben – alles erledigt 👍</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Zuletzt erledigt</h2>
    <?php if ($erledigte): ?>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Aufgabe</th><th>Erledigt am</th><th>Von</th><th>Wert</th><th>Bemerkung</th></tr></thead>
        <tbody>
        <?php foreach ($erledigte as $a): ?>
        <tr>
            <td><?= htmlspecialchars($a['titel']) ?></td>
            <td><?= $a['erledigt_am'] ? date('d.m.Y H:i', strtotime($a['erledigt_am'])) : '–' ?></td>
            <td><?= htmlspecialchars($a['erlediger'] ?? '–') ?></td>
            <td><?= $a['wert'] !== '' ? '<strong>' . htmlspecialchars($a['wert']) . '</strong>' : '–' ?></td>
            <td><?= htmlspecialchars($a['bemerkung']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine erledigten Aufgaben.</p>
    <?php endif; ?>
</div>

<?php if ($istHausmeister && $hmDokumente): ?>
<div class="card">
    <h2>Dokumente &amp; Anleitungen für dich</h2>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Bezeichnung</th><th>Kategorie</th><th>Datum</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($hmDokumente as $d): ?>
        <tr>
            <td><?= htmlspecialchars($d['bezeichnung']) ?></td>
            <td><span class="badge badge-info"><?= htmlspecialchars($d['kategorie']) ?></span></td>
            <td><?= date('d.m.Y', strtotime($d['hochgeladen_am'])) ?></td>
            <td><a href="datei.php?typ=dokument&id=<?= $d['id'] ?>" target="_blank" class="btn btn-sm btn-primary">📄 Öffnen</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php include '../assets/footer.php'; ?>
