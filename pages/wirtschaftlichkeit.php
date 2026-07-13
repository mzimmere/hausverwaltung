<?php
/**
 * Wirtschaftlichkeit – NUR für den Eigentümer.
 * Erfasst Mieteinnahmen und nicht umlegbare Kosten.
 * WICHTIG: Diese Daten fließen NIEMALS in die Nebenkostenabrechnung der Mieter ein!
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

$pageTitle = 'Wirtschaftlichkeit';
$basePath  = '../';

$objektId = aktivesObjekt();

// ── Mieteinnahme speichern ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'miete_save') {
    leserSchreibschutz();
    csrfPruefen();
    $wohnungId = (int)$_POST['wohnung_id'];
    $datum     = $_POST['datum'];
    $betrag    = str_replace(',', '.', $_POST['betrag']);
    $monat     = (int)date('n', strtotime($datum));
    $jahr      = (int)date('Y', strtotime($datum));
    $beschr    = trim($_POST['beschreibung']);

    $stmt = $db->prepare("INSERT INTO mieteinnahmen (objekt_id, wohnung_id, datum, betrag, jahr, monat, beschreibung) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$objektId, $wohnungId, $datum, $betrag, $jahr, $monat, $beschr]);
    protokolliere('mieteinnahmen', 'anlegen', (int)$db->lastInsertId(), 'Mieteinnahme über ' . number_format($betrag, 2, ',', '.') . ' €');
    $successMsg = 'Mieteinnahme gespeichert.';
}

// ── Eigentümerkosten speichern ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'kosten_save') {
    leserSchreibschutz();
    csrfPruefen();
    $katId   = (int)$_POST['kategorie_id'];
    $datum   = $_POST['datum'];
    $betrag  = str_replace(',', '.', $_POST['betrag']);
    $jahr    = (int)date('Y', strtotime($datum));
    $beschr  = trim($_POST['beschreibung']);
    $dateiname = '';

    if (!empty($_FILES['beleg']['name'])) {
        $zielDir = UPLOAD_DIR . 'eigentuemerkosten/' . $jahr . '/';
        if (!is_dir($zielDir)) mkdir($zielDir, 0777, true);
        $dateiname = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['beleg']['name']);
        move_uploaded_file($_FILES['beleg']['tmp_name'], $zielDir . $dateiname);
    }

    $stmt = $db->prepare("INSERT INTO eigentuemerkosten (objekt_id, kategorie_id, datum, betrag, jahr, beschreibung, dateiname) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$objektId, $katId, $datum, $betrag, $jahr, $beschr, $dateiname]);
    protokolliere('eigentuemerkosten', 'anlegen', (int)$db->lastInsertId(), 'Kosten über ' . number_format($betrag, 2, ',', '.') . ' €');
    $successMsg = 'Kosten gespeichert.';
}

// ── Löschen ───────────────────────────────────────────────────
if (isset($_GET['delete_miete'])) {
    leserSchreibschutz();
    $delId = (int)$_GET['delete_miete'];
    $db->prepare("DELETE FROM mieteinnahmen WHERE id=? AND objekt_id=?")->execute([$delId, $objektId]);
    protokolliere('mieteinnahmen', 'loeschen', $delId, 'Mieteinnahme gelöscht');
    header('Location: wirtschaftlichkeit.php?jahr=' . ($_GET['jahr'] ?? date('Y'))); exit;
}
if (isset($_GET['delete_kosten'])) {
    leserSchreibschutz();
    $delId = (int)$_GET['delete_kosten'];
    $db->prepare("DELETE FROM eigentuemerkosten WHERE id=? AND objekt_id=?")->execute([$delId, $objektId]);
    protokolliere('eigentuemerkosten', 'loeschen', $delId, 'Kosten gelöscht');
    header('Location: wirtschaftlichkeit.php?jahr=' . ($_GET['jahr'] ?? date('Y'))); exit;
}

// ── Daten laden ───────────────────────────────────────────────
$filterJahr = (int)($_GET['jahr'] ?? date('Y'));
$stmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=? ORDER BY id");
$stmt->execute([$objektId]);
$wohnungen  = $stmt->fetchAll();
$kategorien = $db->query("SELECT * FROM eigentuemerkosten_kategorien ORDER BY id")->fetchAll();

$mieteinnahmen = $db->prepare("
    SELECT m.*, w.bezeichnung AS wohnung
    FROM mieteinnahmen m JOIN wohnungen w ON m.wohnung_id = w.id
    WHERE m.jahr = ? AND m.objekt_id = ? ORDER BY m.datum DESC
");
$mieteinnahmen->execute([$filterJahr, $objektId]);
$mieteinnahmen = $mieteinnahmen->fetchAll();
$summeMiete = array_sum(array_column($mieteinnahmen, 'betrag'));

$eigKosten = $db->prepare("
    SELECT e.*, k.bezeichnung AS kategorie
    FROM eigentuemerkosten e JOIN eigentuemerkosten_kategorien k ON e.kategorie_id = k.id
    WHERE e.jahr = ? AND e.objekt_id = ? ORDER BY e.datum DESC
");
$eigKosten->execute([$filterJahr, $objektId]);
$eigKosten = $eigKosten->fetchAll();
$summeEigKosten = array_sum(array_column($eigKosten, 'betrag'));

// NK-Nachzahlungen/-Erstattungen – durchlaufender Posten, KEINE steuerpflichtige Mieteinnahme
$nkZahlungen = $db->prepare("
    SELECT nz.*, w.bezeichnung AS wohnung
    FROM nk_zahlungen nz JOIN wohnungen w ON nz.wohnung_id = w.id
    WHERE YEAR(nz.datum) = ? AND nz.objekt_id = ? ORDER BY nz.datum DESC
");
$nkZahlungen->execute([$filterJahr, $objektId]);
$nkZahlungen = $nkZahlungen->fetchAll();
$summeNkNachzahlung = array_sum(array_map(fn($z) => $z['typ']==='Nachzahlung' ? $z['betrag'] : 0, $nkZahlungen));
$summeNkErstattung  = array_sum(array_map(fn($z) => $z['typ']==='Erstattung'  ? $z['betrag'] : 0, $nkZahlungen));
$nkSaldoNetto = $summeNkNachzahlung - $summeNkErstattung; // sollte sich langfristig auf ~0 ausgleichen

// Umlegbare Kosten desselben Jahres (zur Einordnung, nicht zur Abrechnung)
$stmt = $db->prepare("SELECT COALESCE(SUM(betrag),0) FROM rechnungen WHERE jahr=? AND objekt_id=?");
$stmt->execute([$filterJahr, $objektId]);
$summeUmlegbar = (float)$stmt->fetchColumn();

$gesamtKostenHaus = $summeEigKosten + $summeUmlegbar; // Vollkosten, unabhängig wer zahlt
$ergebnisVorUmlage = $summeMiete - $summeEigKosten;     // reiner Eigentümer-Cashflow (steuerpflichtig)
$ergebnisGesamt    = $summeMiete - $gesamtKostenHaus;   // theoretisch falls nichts umgelegt würde

include '../assets/header.php';
?>

<div class="page-header">
    <h1>Wirtschaftlichkeit (nur für Sie als Eigentümer)</h1>
    <div>
        <?php foreach ([date('Y')-2, date('Y')-1, date('Y')] as $j): ?>
        <a href="?jahr=<?= $j ?>" class="btn btn-sm <?= $j==$filterJahr ? 'btn-primary' : '' ?>"
           style="<?= $j!=$filterJahr ? 'background:#e2e8f0;color:#333' : '' ?>"><?= $j ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="alert alert-info">
    ℹ️ Diese Seite ist <strong>ausschließlich für Sie als Eigentümer</strong> sichtbar.
    Mieteinnahmen und hier erfasste Kosten fließen <strong>nicht</strong> in die Nebenkostenabrechnung
    der Mieter ein und werden auf keiner Mieter-PDF angezeigt.
</div>

<!-- KPIs -->
<div class="dashboard-grid">
    <div class="kpi-card success">
        <div class="kpi-label">Mieteinnahmen <?= $filterJahr ?></div>
        <div class="kpi-value"><?= number_format($summeMiete,0,',','.') ?> €</div>
    </div>
    <div class="kpi-card danger">
        <div class="kpi-label">Nicht umlegbare Kosten</div>
        <div class="kpi-value"><?= number_format($summeEigKosten,0,',','.') ?> €</div>
    </div>
    <div class="kpi-card accent">
        <div class="kpi-label">Umlegbare Kosten (Info)</div>
        <div class="kpi-value"><?= number_format($summeUmlegbar,0,',','.') ?> €</div>
    </div>
    <div class="kpi-card <?= $ergebnisVorUmlage >= 0 ? 'success' : 'danger' ?>">
        <div class="kpi-label">Ergebnis (Miete − eigene Kosten)</div>
        <div class="kpi-value"><?= number_format($ergebnisVorUmlage,0,',','.') ?> €</div>
    </div>
</div>

<div class="card">
    <h2>Einordnung <?= $filterJahr ?></h2>
    <div class="table-wrap"><table>
        <tbody>
        <tr>
            <td>Mieteinnahmen (Kaltmiete, ohne NK-Vorauszahlung)</td>
            <td class="text-right" style="color:var(--success);font-weight:700">+ <?= number_format($summeMiete,2,',','.') ?> €</td>
        </tr>
        <tr>
            <td>Nicht umlegbare Kosten (Instandhaltung, Verwaltung, Zinsen ...)</td>
            <td class="text-right" style="color:var(--danger)">− <?= number_format($summeEigKosten,2,',','.') ?> €</td>
        </tr>
        <tr style="font-weight:700;background:#f0f5fb;border-top:2px solid var(--primary)">
            <td>Ihr wirtschaftliches Ergebnis (vor Steuern)</td>
            <td class="text-right <?= $ergebnisVorUmlage >= 0 ? 'negativ' : 'positiv' ?>">
                <?= number_format($ergebnisVorUmlage,2,',','.') ?> €
                <span class="badge <?= $ergebnisVorUmlage >= 0 ? 'badge-success' : 'badge-danger' ?>">
                    <?= $ergebnisVorUmlage >= 0 ? 'Überschuss' : 'Defizit' ?>
                </span>
            </td>
        </tr>
        </tbody>
    </table></div>
    <p style="margin-top:.75rem;color:var(--muted);font-size:.85rem">
        Zur Einordnung: Die umlegbaren Betriebskosten (<?= number_format($summeUmlegbar,2,',','.') ?> €)
        werden vollständig auf die Mieter umgelegt und sind hier nur informativ aufgeführt –
        sie wirken sich wirtschaftlich nicht auf Sie aus, solange die Vorauszahlungen kostendeckend sind.
    </p>
</div>

<!-- ═══════════ NK-NACHZAHLUNGEN / ERSTATTUNGEN (durchlaufender Posten) ═══════════ -->
<div class="card" style="border-left:4px solid #718096">
    <h2>🔄 Nebenkosten-Nachzahlungen / -Erstattungen <?= $filterJahr ?></h2>
    <p style="margin-bottom:1rem;color:var(--muted);font-size:.85rem">
        <strong>Durchlaufender Posten – keine steuerpflichtige Mieteinnahme.</strong>
        Diese Beträge fließen <strong>nicht</strong> in Ihr wirtschaftliches Ergebnis oben ein
        und werden bewusst separat ausgewiesen.
    </p>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1rem">
        <div style="text-align:center;padding:1rem;background:#f8fafc;border-radius:8px">
            <div style="font-size:.8rem;color:var(--muted);text-transform:uppercase">Erhaltene Nachzahlungen</div>
            <div style="font-size:1.4rem;font-weight:700;color:var(--text)"><?= number_format($summeNkNachzahlung,2,',','.') ?> €</div>
        </div>
        <div style="text-align:center;padding:1rem;background:#f8fafc;border-radius:8px">
            <div style="font-size:.8rem;color:var(--muted);text-transform:uppercase">Gezahlte Erstattungen</div>
            <div style="font-size:1.4rem;font-weight:700;color:var(--text)"><?= number_format($summeNkErstattung,2,',','.') ?> €</div>
        </div>
        <div style="text-align:center;padding:1rem;background:#f8fafc;border-radius:8px">
            <div style="font-size:.8rem;color:var(--muted);text-transform:uppercase">Saldo (Durchlaufposten)</div>
            <div style="font-size:1.4rem;font-weight:700;color:var(--text)"><?= number_format($nkSaldoNetto,2,',','.') ?> €</div>
        </div>
    </div>
    <?php if ($nkZahlungen): ?>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Datum</th><th>Wohnung</th><th>Typ</th><th>Beschreibung</th><th class="text-right">Betrag</th></tr></thead>
        <tbody>
        <?php foreach ($nkZahlungen as $z): ?>
        <tr>
            <td><?= date('d.m.Y', strtotime($z['datum'])) ?></td>
            <td><?= htmlspecialchars($z['wohnung']) ?></td>
            <td><span class="badge <?= $z['typ']==='Nachzahlung' ? 'badge-danger' : 'badge-success' ?>"><?= $z['typ'] ?></span></td>
            <td><?= htmlspecialchars($z['beschreibung']) ?></td>
            <td class="text-right"><?= number_format($z['betrag'],2,',','.') ?> €</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine NK-Zahlungen für <?= $filterJahr ?> erfasst.</p>
    <?php endif; ?>
    <div style="margin-top:.75rem">
        <a href="nk_zahlungen.php?jahr=<?= $filterJahr ?>" class="btn btn-sm" style="background:#718096;color:#fff">NK-Zahlungen erfassen →</a>
    </div>
</div>

<?php if (!istNurLesend()): ?>
<!-- ═══════════ MIETEINNAHMEN ═══════════ -->
<div class="card">
    <h2>💰 Mieteinnahme erfassen</h2>
    <form method="post">
        <?= csrfFeld() ?>
        <input type="hidden" name="action" value="miete_save">
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
                <label>Datum *</label>
                <input type="date" name="datum" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Betrag (Kaltmiete, €) *</label>
                <input type="text" name="betrag" placeholder="650.00" required>
            </div>
            <div class="form-group">
                <label>Beschreibung</label>
                <input type="text" name="beschreibung" placeholder="z.B. Miete Juli">
            </div>
        </div>
        <div style="margin-top:1rem"><button type="submit" class="btn btn-success">Mieteinnahme speichern</button></div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Mieteinnahmen <?= $filterJahr ?> – Summe: <?= number_format($summeMiete,2,',','.') ?> €</h2>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Datum</th><th>Wohnung</th><th>Beschreibung</th><th class="text-right">Betrag</th><?= istNurLesend() ? '' : '<th></th>' ?></tr></thead>
        <tbody>
        <?php foreach ($mieteinnahmen as $m): ?>
        <tr>
            <td><?= date('d.m.Y', strtotime($m['datum'])) ?></td>
            <td><?= htmlspecialchars($m['wohnung']) ?></td>
            <td><?= htmlspecialchars($m['beschreibung']) ?></td>
            <td class="text-right" style="color:var(--success)"><?= number_format($m['betrag'],2,',','.') ?> €</td>
            <?php if (!istNurLesend()): ?>
            <td><a href="?delete_miete=<?= $m['id'] ?>&jahr=<?= $filterJahr ?>" class="btn btn-sm btn-danger" onclick="return confirm('Löschen?')">✕</a></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$mieteinnahmen): ?><tr><td colspan="5" class="text-center" style="color:var(--muted)">Keine Einträge</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php if (!istNurLesend()): ?>
<!-- ═══════════ EIGENTÜMERKOSTEN ═══════════ -->
<div class="card">
    <h2>🧾 Nicht umlegbare Kosten erfassen</h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrfFeld() ?>
        <input type="hidden" name="action" value="kosten_save">
        <div class="form-grid">
            <div class="form-group">
                <label>Kategorie *</label>
                <select name="kategorie_id" required>
                    <?php foreach ($kategorien as $k): ?>
                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['bezeichnung']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Datum *</label>
                <input type="date" name="datum" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Betrag (€) *</label>
                <input type="text" name="betrag" placeholder="450.00" required>
            </div>
            <div class="form-group">
                <label>Beschreibung</label>
                <input type="text" name="beschreibung" placeholder="z.B. Reparatur Dachrinne">
            </div>
            <div class="form-group">
                <label>Beleg hochladen (optional)</label>
                <input type="file" name="beleg" accept=".pdf,.jpg,.jpeg,.png">
            </div>
        </div>
        <div style="margin-top:1rem"><button type="submit" class="btn btn-danger">Kosten speichern</button></div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Nicht umlegbare Kosten <?= $filterJahr ?> – Summe: <?= number_format($summeEigKosten,2,',','.') ?> €</h2>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Datum</th><th>Kategorie</th><th>Beschreibung</th><th class="text-right">Betrag</th><th>Beleg</th><?= istNurLesend() ? '' : '<th></th>' ?></tr></thead>
        <tbody>
        <?php foreach ($eigKosten as $e): ?>
        <tr>
            <td><?= date('d.m.Y', strtotime($e['datum'])) ?></td>
            <td><span class="badge badge-warning"><?= htmlspecialchars($e['kategorie']) ?></span></td>
            <td><?= htmlspecialchars($e['beschreibung']) ?></td>
            <td class="text-right" style="color:var(--danger)"><?= number_format($e['betrag'],2,',','.') ?> €</td>
            <td><?php if ($e['dateiname']): ?><a href="../uploads/eigentuemerkosten/<?= $e['jahr'].'/'.$e['dateiname'] ?>" target="_blank" class="btn btn-sm" style="background:#e2e8f0">📄</a><?php endif; ?></td>
            <?php if (!istNurLesend()): ?>
            <td><a href="?delete_kosten=<?= $e['id'] ?>&jahr=<?= $filterJahr ?>" class="btn btn-sm btn-danger" onclick="return confirm('Löschen?')">✕</a></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$eigKosten): ?><tr><td colspan="6" class="text-center" style="color:var(--muted)">Keine Einträge</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php include '../assets/footer.php'; ?>
