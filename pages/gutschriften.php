<?php
/**
 * Gutschriften / individuelle Rabatte je Wohnung.
 * Beispiel: Hausmeister wohnt im Haus und erbringt selbst die
 * Hausmeister- bzw. Treppenhausreinigungsleistung → erhält dafür
 * eine monatliche Gutschrift, die direkt vom Saldo abgezogen wird.
 *
 * WICHTIG: Die Gutschrift wirkt sich NUR auf den Saldo dieser einen
 * Wohnung aus. Sie verändert NICHT die Kostenanteile der anderen
 * Wohnungen – die Umlage bleibt unberührt.
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

$pageTitle = 'Gutschriften';
$basePath  = '../';

$objektId = aktivesObjekt();

// ── Speichern ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    leserSchreibschutz();
    csrfPruefen();
    $wohnungId = (int)$_POST['wohnung_id'];
    $bezeich   = trim($_POST['bezeichnung']);
    $betrag    = str_replace(',', '.', $_POST['betrag_pro_monat']);
    $von       = $_POST['gueltig_von'];
    $bis       = $_POST['gueltig_bis'] !== '' ? $_POST['gueltig_bis'] : null;
    $notiz     = trim($_POST['notiz']);

    $stmt = $db->prepare("
        INSERT INTO gutschriften (objekt_id, wohnung_id, bezeichnung, betrag_pro_monat, gueltig_von, gueltig_bis, notiz)
        VALUES (?,?,?,?,?,?,?)
    ");
    $stmt->execute([$objektId, $wohnungId, $bezeich, $betrag, $von, $bis, $notiz]);
    protokolliere('gutschriften', 'anlegen', (int)$db->lastInsertId(), "\"$bezeich\" angelegt");
    $successMsg = 'Gutschrift gespeichert. Sie wird bei der nächsten Abrechnung automatisch berücksichtigt.';
}

// ── Beenden / Deaktivieren ────────────────────────────────────
if (isset($_GET['beenden'])) {
    leserSchreibschutz();
    $beendenId = (int)$_GET['beenden'];
    $db->prepare("UPDATE gutschriften SET gueltig_bis = CURDATE() WHERE id=? AND objekt_id=?")->execute([$beendenId, $objektId]);
    protokolliere('gutschriften', 'aendern', $beendenId, 'zum heutigen Datum beendet');
    $successMsg = 'Gutschrift wurde zum heutigen Datum beendet.';
}
if (isset($_GET['delete'])) {
    leserSchreibschutz();
    $delId = (int)$_GET['delete'];
    $db->prepare("DELETE FROM gutschriften WHERE id=? AND objekt_id=?")->execute([$delId, $objektId]);
    protokolliere('gutschriften', 'loeschen', $delId, 'gelöscht');
    $successMsg = 'Gutschrift gelöscht.';
}
if (isset($_GET['toggle'])) {
    leserSchreibschutz();
    $toggleId = (int)$_GET['toggle'];
    $db->prepare("UPDATE gutschriften SET aktiv = 1 - aktiv WHERE id=? AND objekt_id=?")->execute([$toggleId, $objektId]);
    protokolliere('gutschriften', 'aendern', $toggleId, 'aktiv/inaktiv umgeschaltet');
}

// ── Daten laden ──────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=? ORDER BY id");
$stmt->execute([$objektId]);
$wohnungen = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT g.*, w.bezeichnung AS wohnung, w.mieter_name
    FROM gutschriften g JOIN wohnungen w ON g.wohnung_id = w.id
    WHERE g.objekt_id=?
    ORDER BY g.aktiv DESC, g.gueltig_von DESC
");
$stmt->execute([$objektId]);
$gutschriften = $stmt->fetchAll();

include '../assets/header.php';
?>

<div class="page-header">
    <h1>Gutschriften / Individuelle Rabatte</h1>
</div>

<div class="alert alert-info">
    ℹ️ <strong>Beispiel:</strong> Der Hausmeister wohnt im Haus und erbringt die Hausmeister-
    und Treppenhausreinigungsleistung selbst. Statt diese Position aus der Umlage zu nehmen
    (was die anderen Mieter unfair belasten würde), wird sie ihm <strong>persönlich gutgeschrieben</strong> –
    er zahlt also weiterhin seinen regulären Kostenanteil, bekommt aber zusätzlich diesen
    Betrag auf seinem Saldo erstattet. Die Berechnung der anderen Wohnungen bleibt davon unberührt.
</div>

<?php if (!istNurLesend()): ?>
<div class="card">
    <h2>Neue Gutschrift anlegen</h2>
    <form method="post">
        <?= csrfFeld() ?>
        <input type="hidden" name="action" value="save">
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
                <label>Bezeichnung *</label>
                <input type="text" name="bezeichnung" placeholder="z.B. Hausmeistertätigkeit" required>
            </div>
            <div class="form-group">
                <label>Betrag pro Monat (€) *</label>
                <input type="text" name="betrag_pro_monat" placeholder="50.00" required>
            </div>
            <div class="form-group">
                <label>Gültig ab *</label>
                <input type="date" name="gueltig_von" value="<?= date('Y-01-01') ?>" required>
            </div>
            <div class="form-group">
                <label>Gültig bis (optional)</label>
                <input type="date" name="gueltig_bis">
                <small style="color:var(--muted)">Leer lassen = bis auf Widerruf</small>
            </div>
            <div class="form-group">
                <label>Notiz</label>
                <input type="text" name="notiz" placeholder="z.B. Vereinbarung vom ...">
            </div>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary">Gutschrift anlegen</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Alle Gutschriften</h2>
    <?php if ($gutschriften): ?>
    <div class="table-wrap">
    <table class="sortable">
        <thead>
            <tr>
                <th>Wohnung</th>
                <th>Bezeichnung</th>
                <th class="text-right">€ / Monat</th>
                <th>Zeitraum</th>
                <th>Status</th>
                <?= istNurLesend() ? '' : '<th></th>' ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($gutschriften as $g): ?>
        <tr>
            <td><?= htmlspecialchars($g['wohnung']) ?> – <?= htmlspecialchars($g['mieter_name']) ?></td>
            <td>
                <?= htmlspecialchars($g['bezeichnung']) ?>
                <?php if ($g['notiz']): ?><br><small style="color:var(--muted)"><?= htmlspecialchars($g['notiz']) ?></small><?php endif; ?>
            </td>
            <td class="text-right" style="color:var(--success);font-weight:700">−<?= number_format($g['betrag_pro_monat'],2,',','.') ?> €</td>
            <td>
                <?= date('d.m.Y', strtotime($g['gueltig_von'])) ?> –
                <?= $g['gueltig_bis'] ? date('d.m.Y', strtotime($g['gueltig_bis'])) : 'laufend' ?>
            </td>
            <td>
                <span class="badge <?= $g['aktiv'] ? 'badge-success' : 'badge-danger' ?>">
                    <?= $g['aktiv'] ? 'aktiv' : 'deaktiviert' ?>
                </span>
            </td>
            <?php if (!istNurLesend()): ?>
            <td>
                <div class="btn-group">
                    <?php if (!$g['gueltig_bis']): ?>
                    <a href="?beenden=<?= $g['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Gutschrift zum heutigen Datum beenden?')">Beenden</a>
                    <?php endif; ?>
                    <a href="?toggle=<?= $g['id'] ?>" class="btn btn-sm" style="background:#e2e8f0"><?= $g['aktiv'] ? 'Deaktivieren' : 'Aktivieren' ?></a>
                    <a href="?delete=<?= $g['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Wirklich endgültig löschen?')">✕</a>
                </div>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine Gutschriften erfasst.</p>
    <?php endif; ?>
</div>

<?php include '../assets/footer.php'; ?>
