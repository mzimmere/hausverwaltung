<?php
/**
 * Kautionsverwaltung.
 * Kaution ist treuhänderisch verwaltetes Fremdgeld – KEIN steuerpflichtiger
 * Ertrag und KEINE Mieteinnahme. Wird daher komplett getrennt erfasst.
 *
 * Ablauf: Kaution bei Einzug anlegen → bei Auszug abrechnen
 * (Rückzahlung abzüglich begründeter Einbehalte wie Schäden oder
 * offene Nebenkosten-Nachzahlungen).
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

$pageTitle = 'Kaution';
$basePath  = '../';

$objektId = aktivesObjekt();

// ── Neue Kaution anlegen ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'kaution_save') {
    leserSchreibschutz();
    csrfPruefen();
    $wohnungId = (int)$_POST['wohnung_id'];
    $mieter    = trim($_POST['mieter_name']);
    $betrag    = str_replace(',', '.', $_POST['betrag']);
    $datum     = $_POST['datum_einzahlung'];
    $anlage    = trim($_POST['anlageform']);
    $notiz     = trim($_POST['notiz']);

    $stmt = $db->prepare("
        INSERT INTO kautionen (objekt_id, wohnung_id, mieter_name, betrag, datum_einzahlung, anlageform, notiz)
        VALUES (?,?,?,?,?,?,?)
    ");
    $stmt->execute([$objektId, $wohnungId, $mieter, $betrag, $datum, $anlage, $notiz]);
    protokolliere('kaution', 'anlegen', (int)$db->lastInsertId(), "Kaution für $mieter über " . number_format($betrag, 2, ',', '.') . " €");
    $successMsg = "Kaution über " . number_format($betrag, 2, ',', '.') . " € für $mieter erfasst.";
}

// ── Kautionsabrechnung bei Auszug speichern ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'abrechnung_save') {
    leserSchreibschutz();
    csrfPruefen();
    $kautionId = (int)$_POST['kaution_id'];
    $datum     = $_POST['datum_abrechnung'];
    $notiz     = trim($_POST['notiz']);

    $kaution = $db->prepare("SELECT * FROM kautionen WHERE id=? AND objekt_id=?");
    $kaution->execute([$kautionId, $objektId]);
    $kaution = $kaution->fetch();

    if (!$kaution) {
        $errorMsg = 'Kaution nicht gefunden.';
    } else {
        // Einbehalte sammeln
        $bezeichnungen = $_POST['einbehalt_bezeichnung'] ?? [];
        $betraege      = $_POST['einbehalt_betrag']      ?? [];
        $summeEinbehalte = 0;
        $einbehalte = [];
        foreach ($bezeichnungen as $idx => $bez) {
            $bez = trim($bez);
            $bet = isset($betraege[$idx]) ? (float)str_replace(',', '.', $betraege[$idx]) : 0;
            if ($bez !== '' && $bet > 0) {
                $einbehalte[] = ['bezeichnung' => $bez, 'betrag' => $bet];
                $summeEinbehalte += $bet;
            }
        }

        $betragZurueck = round((float)$kaution['betrag'] - $summeEinbehalte, 2);
        if ($betragZurueck < 0) $betragZurueck = 0; // Kaution kann nicht negativ ausgezahlt werden

        $insA = $db->prepare("
            INSERT INTO kautionsabrechnung (kaution_id, datum_abrechnung, betrag_zurueck, notiz)
            VALUES (?,?,?,?)
        ");
        $insA->execute([$kautionId, $datum, $betragZurueck, $notiz]);
        $abrId = $db->lastInsertId();

        $insE = $db->prepare("INSERT INTO kaution_einbehalte (kautionsabrechnung_id, bezeichnung, betrag) VALUES (?,?,?)");
        foreach ($einbehalte as $e) {
            $insE->execute([$abrId, $e['bezeichnung'], $e['betrag']]);
        }

        $db->prepare("UPDATE kautionen SET status='abgerechnet' WHERE id=? AND objekt_id=?")->execute([$kautionId, $objektId]);
        protokolliere('kaution', 'aendern', $kautionId, 'Kaution abgerechnet, Rückzahlung ' . number_format($betragZurueck, 2, ',', '.') . ' €');

        $successMsg = "Kaution abgerechnet: " . number_format($betragZurueck, 2, ',', '.') . " € werden zurückgezahlt"
            . ($summeEinbehalte > 0 ? " (Einbehalte: " . number_format($summeEinbehalte, 2, ',', '.') . " €)." : ".");
    }
}

if (isset($_GET['delete'])) {
    leserSchreibschutz();
    $delId = (int)$_GET['delete'];
    protokolliere('kaution', 'loeschen', $delId, 'Kaution gelöscht');
    $db->prepare("DELETE FROM kautionen WHERE id=? AND objekt_id=?")->execute([$delId, $objektId]);
    header('Location: kaution.php'); exit;
}

// ── Daten laden ──────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=? ORDER BY id");
$stmt->execute([$objektId]);
$wohnungen = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT k.*, w.bezeichnung AS wohnung
    FROM kautionen k JOIN wohnungen w ON k.wohnung_id = w.id
    WHERE k.objekt_id=?
    ORDER BY k.status ASC, k.datum_einzahlung DESC
");
$stmt->execute([$objektId]);
$alleKautionen = $stmt->fetchAll();

// Abrechnungen je Kaution vorladen
$abrechnungJeKaution = [];
$einbehalteJeAbrechnung = [];
if ($alleKautionen) {
    $ids = array_column($alleKautionen, 'id');
    $platzhalter = implode(',', array_fill(0, count($ids), '?'));
    $aStmt = $db->prepare("SELECT * FROM kautionsabrechnung WHERE kaution_id IN ($platzhalter)");
    $aStmt->execute($ids);
    $abrechnungen = $aStmt->fetchAll();
    foreach ($abrechnungen as $a) {
        $abrechnungJeKaution[$a['kaution_id']] = $a;
    }

    if ($abrechnungen) {
        $abrIds = array_column($abrechnungen, 'id');
        $platzhalter2 = implode(',', array_fill(0, count($abrIds), '?'));
        $eStmt = $db->prepare("SELECT * FROM kaution_einbehalte WHERE kautionsabrechnung_id IN ($platzhalter2)");
        $eStmt->execute($abrIds);
        foreach ($eStmt->fetchAll() as $e) {
            $einbehalteJeAbrechnung[$e['kautionsabrechnung_id']][] = $e;
        }
    }
}

// Welche Kaution soll gerade abgerechnet werden? (per Klick auf "Abrechnen")
$abrechnenId = (int)($_GET['abrechnen'] ?? 0);
$abrechnenKaution = null;
if ($abrechnenId) {
    $stmt = $db->prepare("SELECT k.*, w.bezeichnung AS wohnung FROM kautionen k JOIN wohnungen w ON k.wohnung_id=w.id WHERE k.id=? AND k.objekt_id=?");
    $stmt->execute([$abrechnenId, $objektId]);
    $abrechnenKaution = $stmt->fetch();
}

// Noch offene NK-Salden für diese Wohnung (Hilfe bei der Einbehalt-Begründung)
$offeneNk = [];
if ($abrechnenKaution) {
    $nkStmt = $db->prepare("
        SELECT a.id, a.zeitraum_von, a.zeitraum_bis, a.saldo,
            (SELECT COALESCE(SUM(CASE WHEN typ='Nachzahlung' THEN betrag ELSE -betrag END),0) FROM nk_zahlungen WHERE abrechnung_id=a.id) AS bereits_erfasst
        FROM abrechnungen a
        WHERE a.wohnung_id = ? AND a.objekt_id = ? AND a.saldo > 0
    ");
    $nkStmt->execute([$abrechnenKaution['wohnung_id'], $objektId]);
    foreach ($nkStmt->fetchAll() as $nk) {
        $offen = round($nk['saldo'] - $nk['bereits_erfasst'], 2);
        if ($offen > 0.01) $offeneNk[] = $nk + ['offen' => $offen];
    }
}

include '../assets/header.php';
?>

<div class="page-header">
    <h1>Kautionsverwaltung</h1>
</div>

<div class="alert alert-info">
    ℹ️ <strong>Hinweis:</strong> Die Kaution ist treuhänderisch verwaltetes Fremdgeld des Mieters –
    <strong>keine steuerpflichtige Mieteinnahme</strong>. Sie wird daher komplett getrennt von
    Mieteinnahmen und Nebenkosten verwaltet.
</div>

<?php if ($abrechnenKaution && !istNurLesend()): ?>
<!-- ═══════════ KAUTION ABRECHNEN (bei Auszug) ═══════════ -->
<div class="card" style="border-left:4px solid #e8a020">
    <h2>Kaution abrechnen – <?= htmlspecialchars($abrechnenKaution['wohnung']) ?> / <?= htmlspecialchars($abrechnenKaution['mieter_name']) ?></h2>
    <p style="margin-bottom:1rem;color:var(--muted)">
        Eingezahlte Kaution: <strong><?= number_format($abrechnenKaution['betrag'],2,',','.') ?> €</strong>
        am <?= date('d.m.Y', strtotime($abrechnenKaution['datum_einzahlung'])) ?>
    </p>

    <?php if ($offeneNk): ?>
    <div class="alert alert-info" style="margin-bottom:1rem">
        <strong>Hinweis:</strong> Für diese Wohnung gibt es noch offene Nebenkosten-Nachzahlungen:
        <?php foreach ($offeneNk as $nk): ?>
        <br>• <?= date('d.m.Y', strtotime($nk['zeitraum_von'])) ?> – <?= date('d.m.Y', strtotime($nk['zeitraum_bis'])) ?>:
        <strong><?= number_format($nk['offen'],2,',','.') ?> €</strong> offen
        <?php endforeach; ?>
        <br><small>Sie können diesen Betrag unten als Einbehalt aufnehmen, falls die Nachzahlung über die Kaution verrechnet werden soll.</small>
    </div>
    <?php endif; ?>

    <form method="post" id="kautionAbrechnenForm">
        <?= csrfFeld() ?>
        <input type="hidden" name="action" value="abrechnung_save">
        <input type="hidden" name="kaution_id" value="<?= $abrechnenKaution['id'] ?>">

        <div class="form-grid" style="margin-bottom:1.25rem">
            <div class="form-group">
                <label>Datum der Abrechnung *</label>
                <input type="date" name="datum_abrechnung" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <label style="display:block;margin-bottom:.6rem;font-weight:600;font-size:.85rem;color:var(--muted)">
            Einbehalte (optional) – z.B. Schäden, offene Nachzahlungen
        </label>
        <table id="einbehalteTabelle" style="width:100%;max-width:560px;margin-bottom:.75rem">
            <tr>
                <td><input type="text" name="einbehalt_bezeichnung[]" placeholder="z.B. Schaden Bodenbelag" style="width:100%;padding:.4rem"></td>
                <td style="width:120px"><input type="text" name="einbehalt_betrag[]" placeholder="€" style="width:100px;padding:.4rem"></td>
            </tr>
        </table>
        <button type="button" class="btn btn-sm" style="background:#e2e8f0;margin-bottom:1rem" onclick="zeileHinzufuegen()">+ Weitere Position</button>

        <div class="form-group" style="max-width:480px;margin-bottom:1.25rem">
            <label>Notiz</label>
            <input type="text" name="notiz" placeholder="Optionale Notiz zur Abrechnung">
        </div>

        <div id="vorschauErgebnis" style="padding:1rem;background:#f8fafc;border-radius:8px;margin-bottom:1.25rem;font-size:.95rem">
            Kaution: <strong><?= number_format($abrechnenKaution['betrag'],2,',','.') ?> €</strong> −
            Einbehalte: <strong id="vorschauEinbehalte">0,00 €</strong> =
            Rückzahlung: <strong id="vorschauRueckzahlung" style="color:var(--success)"><?= number_format($abrechnenKaution['betrag'],2,',','.') ?> €</strong>
        </div>

        <button type="submit" class="btn btn-primary">Kaution abrechnen &amp; abschließen</button>
        <a href="kaution.php" class="btn btn-sm" style="background:#e2e8f0">Abbrechen</a>
    </form>
</div>

<script>
function zeileHinzufuegen() {
    const tabelle = document.getElementById('einbehalteTabelle');
    const zeile = document.createElement('tr');
    zeile.innerHTML = `
        <td><input type="text" name="einbehalt_bezeichnung[]" placeholder="Bezeichnung" style="width:100%;padding:.4rem" oninput="vorschauAktualisieren()"></td>
        <td style="width:120px"><input type="text" name="einbehalt_betrag[]" placeholder="€" style="width:100px;padding:.4rem" oninput="vorschauAktualisieren()"></td>
    `;
    tabelle.appendChild(zeile);
}
function vorschauAktualisieren() {
    const kaution = <?= (float)$abrechnenKaution['betrag'] ?>;
    let summe = 0;
    document.querySelectorAll('input[name="einbehalt_betrag[]"]').forEach(f => {
        const v = parseFloat((f.value || '0').replace(',', '.'));
        if (!isNaN(v)) summe += v;
    });
    const rueck = Math.max(0, kaution - summe);
    document.getElementById('vorschauEinbehalte').textContent = summe.toFixed(2).replace('.', ',') + ' €';
    document.getElementById('vorschauRueckzahlung').textContent = rueck.toFixed(2).replace('.', ',') + ' €';
}
document.querySelectorAll('input[name="einbehalt_betrag[]"]').forEach(f => f.addEventListener('input', vorschauAktualisieren));
</script>

<?php else: ?>

<!-- ═══════════ NEUE KAUTION ANLEGEN ═══════════ -->
<?php if (!istNurLesend()): ?>
<div class="card">
    <h2>Neue Kaution erfassen</h2>
    <form method="post">
        <?= csrfFeld() ?>
        <input type="hidden" name="action" value="kaution_save">
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
                <label>Mieter (Name) *</label>
                <input type="text" name="mieter_name" placeholder="z.B. Familie Müller" required>
            </div>
            <div class="form-group">
                <label>Betrag (€) *</label>
                <input type="text" name="betrag" placeholder="1500.00" required>
            </div>
            <div class="form-group">
                <label>Datum der Einzahlung *</label>
                <input type="date" name="datum_einzahlung" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Anlageform (optional)</label>
                <input type="text" name="anlageform" placeholder="z.B. Mietkautionskonto Sparkasse, IBAN ...">
            </div>
            <div class="form-group">
                <label>Notiz</label>
                <input type="text" name="notiz" placeholder="Optionale Notiz">
            </div>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary">Kaution erfassen</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Alle Kautionen</h2>
    <?php if ($alleKautionen): ?>
    <div class="table-wrap">
    <table class="sortable">
        <thead>
            <tr>
                <th>Wohnung</th><th>Mieter</th><th class="text-right">Betrag</th>
                <th>Einzahlung</th><th>Anlageform</th><th>Status</th><?= istNurLesend() ? '' : '<th></th>' ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($alleKautionen as $k): ?>
        <tr>
            <td><?= htmlspecialchars($k['wohnung']) ?></td>
            <td><?= htmlspecialchars($k['mieter_name']) ?></td>
            <td class="text-right"><?= number_format($k['betrag'],2,',','.') ?> €</td>
            <td><?= date('d.m.Y', strtotime($k['datum_einzahlung'])) ?></td>
            <td><small><?= htmlspecialchars($k['anlageform'] ?? '–') ?></small></td>
            <td>
                <?php if ($k['status'] === 'abgerechnet'): ?>
                <span class="badge badge-success">abgerechnet</span>
                <?php else: ?>
                <span class="badge badge-warning">aktiv</span>
                <?php endif; ?>
            </td>
            <?php if (!istNurLesend()): ?>
            <td>
                <?php if ($k['status'] === 'aktiv'): ?>
                <a href="?abrechnen=<?= $k['id'] ?>" class="btn btn-sm btn-accent">Abrechnen (Auszug)</a>
                <?php else: ?>
                <a href="?delete=<?= $k['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Kaution endgültig löschen?')">✕</a>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php if (!empty($abrechnungJeKaution[$k['id']])):
            $abr = $abrechnungJeKaution[$k['id']];
        ?>
        <tr style="background:#f8fafc">
            <td colspan="7" style="padding:.5rem 1rem 1rem">
                <small style="color:var(--muted)">
                    Abgerechnet am <?= date('d.m.Y', strtotime($abr['datum_abrechnung'])) ?>:
                    Rückzahlung <strong style="color:var(--success)"><?= number_format($abr['betrag_zurueck'],2,',','.') ?> €</strong>
                    <?php if (!empty($einbehalteJeAbrechnung[$abr['id']])): ?>
                    <br>Einbehalte:
                    <?php foreach ($einbehalteJeAbrechnung[$abr['id']] as $e): ?>
                    <span class="badge badge-danger" style="margin:1px"><?= htmlspecialchars($e['bezeichnung']) ?>: <?= number_format($e['betrag'],2,',','.') ?> €</span>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </small>
            </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine Kautionen erfasst.</p>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php include '../assets/footer.php'; ?>
