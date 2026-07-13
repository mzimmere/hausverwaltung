<?php
/**
 * Nebenkosten-Nachzahlungen / -Erstattungen erfassen.
 *
 * WICHTIG (steuerlich): Nebenkosten sind durchlaufende Posten, KEINE
 * steuerpflichtige Mieteinnahme. Diese Seite erfasst deshalb bewusst
 * getrennt von "Mieteinnahmen" (Wirtschaftlichkeit), wann ein Mieter
 * tatsächlich eine Nachzahlung geleistet oder ein Guthaben erstattet
 * bekommen hat – unabhängig vom berechneten Soll-Saldo der Abrechnung.
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

$pageTitle = 'NK-Zahlungen';
$basePath  = '../';

$objektId = aktivesObjekt();

// ── Zahlung speichern ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    leserSchreibschutz();
    csrfPruefen();
    $abrId  = (int)$_POST['abrechnung_id'];
    $typ    = $_POST['typ'] === 'Erstattung' ? 'Erstattung' : 'Nachzahlung';
    $datum  = $_POST['datum'];
    $betrag = str_replace(',', '.', $_POST['betrag']);
    $beschr = trim($_POST['beschreibung']);

    $abr = $db->prepare("SELECT wohnung_id FROM abrechnungen WHERE id=? AND objekt_id=?");
    $abr->execute([$abrId, $objektId]);
    $wohnungId = $abr->fetchColumn();

    if ($wohnungId) {
        $stmt = $db->prepare("INSERT INTO nk_zahlungen (objekt_id, abrechnung_id, wohnung_id, typ, datum, betrag, beschreibung) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$objektId, $abrId, $wohnungId, $typ, $datum, $betrag, $beschr]);
        protokolliere('nk_zahlungen', 'anlegen', (int)$db->lastInsertId(), "$typ über " . number_format($betrag, 2, ',', '.') . " €");
        $successMsg = "$typ über " . number_format($betrag, 2, ',', '.') . " € erfasst.";
    } else {
        $errorMsg = 'Abrechnung nicht gefunden.';
    }
}

if (isset($_GET['delete'])) {
    leserSchreibschutz();
    $delId = (int)$_GET['delete'];
    protokolliere('nk_zahlungen', 'loeschen', $delId, 'NK-Zahlung gelöscht');
    $db->prepare("DELETE FROM nk_zahlungen WHERE id=? AND objekt_id=?")->execute([$delId, $objektId]);
    header('Location: nk_zahlungen.php'); exit;
}

// ── Offene Salden (Abrechnungen mit Saldo != 0, noch nicht voll abgeglichen) ──
$filterJahr = (int)($_GET['jahr'] ?? date('Y'));

$abrechnungen = $db->prepare("
    SELECT a.*, w.bezeichnung AS wohnung_bez, w.mieter_name
    FROM abrechnungen a JOIN wohnungen w ON a.wohnung_id = w.id
    WHERE a.jahr = ? AND a.objekt_id = ? AND a.saldo != 0
    ORDER BY w.id, a.zeitraum_von
");
$abrechnungen->execute([$filterJahr, $objektId]);
$abrechnungen = $abrechnungen->fetchAll();

// Bereits erfasste Zahlungen je Abrechnung laden
$zahlungenJeAbr = [];
if ($abrechnungen) {
    $ids = array_column($abrechnungen, 'id');
    $platzhalter = implode(',', array_fill(0, count($ids), '?'));
    $zStmt = $db->prepare("SELECT * FROM nk_zahlungen WHERE abrechnung_id IN ($platzhalter) ORDER BY datum");
    $zStmt->execute($ids);
    foreach ($zStmt->fetchAll() as $z) {
        $zahlungenJeAbr[$z['abrechnung_id']][] = $z;
    }
}

// Gesamtsummen für die Übersicht oben
$summeOffenNachzahlung = 0;
$summeOffenErstattung  = 0;
foreach ($abrechnungen as $a) {
    $bereitsErfasst = 0;
    foreach (($zahlungenJeAbr[$a['id']] ?? []) as $z) {
        $bereitsErfasst += ($z['typ'] === 'Nachzahlung') ? $z['betrag'] : -$z['betrag'];
    }
    $offen = $a['saldo'] - $bereitsErfasst;
    if ($offen > 0) $summeOffenNachzahlung += $offen;
    if ($offen < 0) $summeOffenErstattung  += abs($offen);
}

include '../assets/header.php';
?>

<div class="page-header">
    <h1>Nebenkosten-Nachzahlungen / -Erstattungen</h1>
    <div>
        <?php foreach ([date('Y')-2, date('Y')-1, date('Y')] as $j): ?>
        <a href="?jahr=<?= $j ?>" class="btn btn-sm <?= $j==$filterJahr ? 'btn-primary' : '' ?>"
           style="<?= $j!=$filterJahr ? 'background:#e2e8f0;color:#333' : '' ?>"><?= $j ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="alert alert-info">
    ℹ️ <strong>Steuerlich wichtig:</strong> Nebenkosten-Nachzahlungen und -Erstattungen sind
    durchlaufende Posten und keine steuerpflichtige Mieteinnahme. Sie werden deshalb hier
    getrennt von den Mieteinnahmen erfasst – mit dem Datum, an dem das Geld tatsächlich
    geflossen ist (nicht dem berechneten Abrechnungsdatum).
</div>

<div class="dashboard-grid">
    <div class="kpi-card danger">
        <div class="kpi-label">Noch offene Nachzahlungen</div>
        <div class="kpi-value"><?= number_format($summeOffenNachzahlung,0,',','.') ?> €</div>
    </div>
    <div class="kpi-card success">
        <div class="kpi-label">Noch offene Erstattungen</div>
        <div class="kpi-value"><?= number_format($summeOffenErstattung,0,',','.') ?> €</div>
    </div>
</div>

<?php if ($abrechnungen): ?>
<?php foreach ($abrechnungen as $a):
    $bereitsErfasst = 0;
    foreach (($zahlungenJeAbr[$a['id']] ?? []) as $z) {
        $bereitsErfasst += ($z['typ'] === 'Nachzahlung') ? $z['betrag'] : -$z['betrag'];
    }
    $offen = round($a['saldo'] - $bereitsErfasst, 2);
    $sollTyp = $a['saldo'] > 0 ? 'Nachzahlung' : 'Erstattung';
?>
<div class="card">
    <h2>
        <?= htmlspecialchars($a['wohnung_bez']) ?> – <?= htmlspecialchars($a['mieter_name']) ?>
        <span style="font-size:.85rem;color:var(--muted);font-weight:400">
            (<?= date('d.m.Y', strtotime($a['zeitraum_von'])) ?> – <?= date('d.m.Y', strtotime($a['zeitraum_bis'])) ?>)
        </span>
    </h2>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1rem">
        <div style="text-align:center;padding:.75rem;background:#f8fafc;border-radius:8px">
            <div style="font-size:.75rem;color:var(--muted);text-transform:uppercase">Laut Abrechnung</div>
            <div style="font-size:1.3rem;font-weight:700" class="<?= $a['saldo']>0 ? 'positiv' : 'negativ' ?>">
                <?= number_format(abs($a['saldo']),2,',','.') ?> € <?= $sollTyp ?>
            </div>
        </div>
        <div style="text-align:center;padding:.75rem;background:#f8fafc;border-radius:8px">
            <div style="font-size:.75rem;color:var(--muted);text-transform:uppercase">Bereits erfasst</div>
            <div style="font-size:1.3rem;font-weight:700;color:var(--muted)">
                <?= number_format(abs($bereitsErfasst),2,',','.') ?> €
            </div>
        </div>
        <div style="text-align:center;padding:.75rem;background:<?= abs($offen) < 0.01 ? '#f0fff4' : '#fff8f0' ?>;border-radius:8px">
            <div style="font-size:.75rem;color:var(--muted);text-transform:uppercase">Noch offen</div>
            <div style="font-size:1.3rem;font-weight:700;color:<?= abs($offen) < 0.01 ? 'var(--success)' : '#c07010' ?>">
                <?= abs($offen) < 0.01 ? '✓ ausgeglichen' : number_format(abs($offen),2,',','.') . ' €' ?>
            </div>
        </div>
    </div>

    <?php if (!empty($zahlungenJeAbr[$a['id']])): ?>
    <table style="width:100%;margin-bottom:1rem;font-size:.9rem">
        <?php foreach ($zahlungenJeAbr[$a['id']] as $z): ?>
        <tr>
            <td style="padding:.3rem 0"><?= date('d.m.Y', strtotime($z['datum'])) ?></td>
            <td style="padding:.3rem 0"><span class="badge <?= $z['typ']==='Nachzahlung' ? 'badge-danger' : 'badge-success' ?>"><?= $z['typ'] ?></span></td>
            <td style="padding:.3rem 0"><?= htmlspecialchars($z['beschreibung']) ?></td>
            <td style="padding:.3rem 0;text-align:right;font-weight:600"><?= number_format($z['betrag'],2,',','.') ?> €</td>
            <td style="padding:.3rem 0;text-align:right"><?php if (!istNurLesend()): ?><a href="?delete=<?= $z['id'] ?>&jahr=<?= $filterJahr ?>" class="btn btn-sm btn-danger" onclick="return confirm('Löschen?')">✕</a><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if (abs($offen) > 0.01 && !istNurLesend()): ?>
    <form method="post" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;background:#f8fafc;padding:.75rem;border-radius:8px">
        <?= csrfFeld() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="abrechnung_id" value="<?= $a['id'] ?>">
        <div class="form-group" style="margin:0">
            <label style="font-size:.8rem">Typ</label>
            <select name="typ" style="padding:.4rem">
                <option value="Nachzahlung" <?= $sollTyp==='Nachzahlung' ? 'selected' : '' ?>>Nachzahlung erhalten</option>
                <option value="Erstattung" <?= $sollTyp==='Erstattung' ? 'selected' : '' ?>>Erstattung gezahlt</option>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label style="font-size:.8rem">Datum</label>
            <input type="date" name="datum" value="<?= date('Y-m-d') ?>" style="padding:.4rem">
        </div>
        <div class="form-group" style="margin:0">
            <label style="font-size:.8rem">Betrag (€)</label>
            <input type="text" name="betrag" value="<?= number_format(abs($offen),2,'.','') ?>" style="width:100px;padding:.4rem">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:150px">
            <label style="font-size:.8rem">Notiz</label>
            <input type="text" name="beschreibung" placeholder="z.B. per Überweisung" style="padding:.4rem;width:100%">
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Erfassen</button>
    </form>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="card">
    <p style="color:var(--muted)">Keine offenen Abrechnungssalden für <?= $filterJahr ?>. Zuerst eine Jahresabrechnung berechnen.</p>
</div>
<?php endif; ?>

<?php include '../assets/footer.php'; ?>
