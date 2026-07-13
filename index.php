<?php
require_once 'config/config.php';
require_once 'config/auth.php';
requireLogin('');
require_once 'config/database.php';

$pageTitle = 'Dashboard';
$basePath  = '';
$jetzigesJahr = (int)date('Y');

$objektId = aktivesObjekt();

$stmt = $db->prepare("SELECT COUNT(*) FROM wohnungen WHERE aktiv=1 AND objekt_id=?");
$stmt->execute([$objektId]);
$anzahlWohnungen = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM dokumente WHERE objekt_id=?");
$stmt->execute([$objektId]);
$anzahlDokumente = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COALESCE(SUM(betrag),0) FROM rechnungen WHERE jahr=YEAR(NOW()) AND objekt_id=?");
$stmt->execute([$objektId]);
$gesamtkosten = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN typ='Einlage' THEN betrag ELSE -betrag END),0) FROM ruecklagen WHERE objekt_id=?");
$stmt->execute([$objektId]);
$ruecklagen = $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT r.datum, r.betrag, r.beschreibung, k.bezeichnung AS kostenart
    FROM rechnungen r
    JOIN kostenarten k ON r.kostenart_id = k.id
    WHERE r.objekt_id = ?
    ORDER BY r.datum DESC LIMIT 5
");
$stmt->execute([$objektId]);
$letzteRechnungen = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT w.bezeichnung, w.mieter_name, a.saldo, a.erstellt_am
    FROM abrechnungen a
    JOIN wohnungen w ON a.wohnung_id = w.id
    WHERE a.jahr = YEAR(NOW()) AND a.objekt_id = ?
    ORDER BY w.id
");
$stmt->execute([$objektId]);
$abrechnungen = $stmt->fetchAll();

// ── Wirtschaftlichkeit: Mieteinnahmen vs. nicht umlegbare Kosten ──
$stmt = $db->prepare("SELECT COALESCE(SUM(betrag),0) FROM mieteinnahmen WHERE jahr=? AND objekt_id=?");
$stmt->execute([$jetzigesJahr, $objektId]);
$summeMiete = (float)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COALESCE(SUM(betrag),0) FROM eigentuemerkosten WHERE jahr=? AND objekt_id=?");
$stmt->execute([$jetzigesJahr, $objektId]);
$summeEigKosten = (float)$stmt->fetchColumn();

$ergebnis = $summeMiete - $summeEigKosten;

// Letzte 6 Monate für Mini-Trend (Miete vs. Eigentümerkosten)
$monatsKuerzel = [1=>'Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
$monatsDaten = [];
for ($i = 5; $i >= 0; $i--) {
    $monat = date('Y-m', strtotime("-$i months"));
    $j = (int)substr($monat, 0, 4);
    $m = (int)substr($monat, 5, 2);

    $stmt1 = $db->prepare("SELECT COALESCE(SUM(betrag),0) FROM mieteinnahmen WHERE jahr=? AND monat=? AND objekt_id=?");
    $stmt1->execute([$j, $m, $objektId]);
    $mieteWert = (float)$stmt1->fetchColumn();

    $stmt2 = $db->prepare("SELECT COALESCE(SUM(betrag),0) FROM eigentuemerkosten WHERE jahr=? AND MONTH(datum)=? AND objekt_id=?");
    $stmt2->execute([$j, $m, $objektId]);
    $kostenWert = (float)$stmt2->fetchColumn();

    $monatsDaten[] = ['label' => $monatsKuerzel[$m], 'miete' => $mieteWert, 'kosten' => $kostenWert];
}
$maxMonatswert = max(1, max(array_column($monatsDaten, 'miete')), max(array_column($monatsDaten, 'kosten')));

// Hausbild als Hintergrund, falls vorhanden (assets/haus.jpg / .png / .jpeg / .webp)
$hausBild = null;
foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
    if (is_file(__DIR__ . '/assets/haus.' . $ext)) {
        // Cache-Buster über die Änderungszeit, damit ein neues Bild sofort erscheint
        $hausBild = 'assets/haus.' . $ext . '?v=' . filemtime(__DIR__ . '/assets/haus.' . $ext);
        break;
    }
}

include 'assets/header.php';
?>

<?php if ($hausBild): ?>
<style>
/* Großflächiger Haus-Hintergrund auf der Startseite */
body.dashboard-mit-bild {
    background-image:
        linear-gradient(rgba(244,246,249,.86), rgba(244,246,249,.94)),
        url('<?= htmlspecialchars($hausBild) ?>');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    background-repeat: no-repeat;
}
:root[data-theme="dark"] body.dashboard-mit-bild,
body.dashboard-mit-bild.theme-dark {
    background-image:
        linear-gradient(rgba(16,21,31,.88), rgba(16,21,31,.95)),
        url('<?= htmlspecialchars($hausBild) ?>');
}
/* Darkmode-Schleier zuverlässig auch ohne data-theme am body */
:root[data-theme="dark"] body.dashboard-mit-bild {
    background-image:
        linear-gradient(rgba(16,21,31,.88), rgba(16,21,31,.95)),
        url('<?= htmlspecialchars($hausBild) ?>');
}
</style>
<script>document.body.classList.add('dashboard-mit-bild');</script>
<?php endif; ?>

<div class="page-header">
    <h1>🏠 Dashboard <?= date('Y') ?></h1>
</div>

<div class="dashboard-grid">
    <div class="kpi-card">
        <div class="kpi-label">Wohnungen</div>
        <div class="kpi-value"><?= $anzahlWohnungen ?></div>
    </div>
    <div class="kpi-card accent">
        <div class="kpi-label">Kosten <?= date('Y') ?></div>
        <div class="kpi-value"><?= number_format($gesamtkosten, 0, ',', '.') ?> &euro;</div>
    </div>
    <div class="kpi-card success">
        <div class="kpi-label">R&uuml;cklagen</div>
        <div class="kpi-value"><?= number_format($ruecklagen, 0, ',', '.') ?> &euro;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Dokumente</div>
        <div class="kpi-value"><?= $anzahlDokumente ?></div>
    </div>
</div>

<!-- ═══════════ WIRTSCHAFTLICHKEIT (nur Eigentümer) ═══════════ -->
<div class="card" style="border-left:4px solid #e8a020">
    <h2>📊 Wirtschaftlichkeit <?= $jetzigesJahr ?> <span style="color:var(--muted);font-size:.8rem;font-weight:400">– nur für Sie sichtbar</span></h2>

    <div class="stat-grid">
        <div class="stat-tile stat-tile-pos">
            <div class="stat-label">Mieteinnahmen</div>
            <div class="stat-value" style="color:var(--success)">+<?= number_format($summeMiete,0,',','.') ?> &euro;</div>
        </div>
        <div class="stat-tile stat-tile-neg">
            <div class="stat-label">Nicht umlegbare Kosten</div>
            <div class="stat-value" style="color:var(--danger)">−<?= number_format($summeEigKosten,0,',','.') ?> &euro;</div>
        </div>
        <div class="stat-tile <?= $ergebnis>=0 ? 'stat-tile-pos' : 'stat-tile-neg' ?>">
            <div class="stat-label">Ergebnis</div>
            <div class="stat-value" style="color:<?= $ergebnis>=0 ? 'var(--success)' : 'var(--danger)' ?>">
                <?= number_format($ergebnis,0,',','.') ?> &euro;
            </div>
        </div>
    </div>

    <!-- Mini-Balkendiagramm letzte 6 Monate (Chart-Standard: animiert + Neon-Glow) -->
    <div class="chart">
        <?php $barIndex = 0; foreach ($monatsDaten as $md):
            $hMiete  = max(2, round(($md['miete']  / $maxMonatswert) * 100));
            $hKosten = max(2, round(($md['kosten'] / $maxMonatswert) * 100));
        ?>
        <div class="chart-group">
            <div class="chart-bar"
                 style="--bar-h:<?= $hMiete ?>px; --bar-color:var(--success); --bar-i:<?= $barIndex ?>"
                 title="Miete: <?= number_format($md['miete'],2,',','.') ?> €"></div>
            <div class="chart-bar"
                 style="--bar-h:<?= $hKosten ?>px; --bar-color:var(--danger); --bar-i:<?= $barIndex + 1 ?>"
                 title="Kosten: <?= number_format($md['kosten'],2,',','.') ?> €"></div>
        </div>
        <?php $barIndex += 2; endforeach; ?>
    </div>
    <div class="chart-labels">
        <?php foreach ($monatsDaten as $md): ?>
        <div class="chart-label"><?= $md['label'] ?></div>
        <?php endforeach; ?>
    </div>
    <div class="chart-legend">
        <span><i style="background:var(--success)"></i>Mieteinnahmen</span>
        <span><i style="background:var(--danger)"></i>Nicht umlegbare Kosten</span>
    </div>

    <div style="margin-top:1rem">
        <a href="pages/wirtschaftlichkeit.php" class="btn btn-sm" style="background:#e8a020;color:#fff">Details &amp; Erfassung →</a>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;flex-wrap:wrap;">

<div class="card">
    <h2>Letzte Rechnungen</h2>
    <?php if ($letzteRechnungen): ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Datum</th><th>Kostenart</th><th class="text-right">Betrag</th></tr></thead>
        <tbody>
        <?php foreach ($letzteRechnungen as $r): ?>
        <tr>
            <td><?= date('d.m.Y', strtotime($r['datum'])) ?></td>
            <td><?= htmlspecialchars($r['kostenart']) ?></td>
            <td class="text-right"><?= number_format($r['betrag'], 2, ',', '.') ?> &euro;</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine Rechnungen erfasst.</p>
    <?php endif; ?>
    <div style="margin-top:.75rem"><a href="pages/rechnungen.php" class="btn btn-primary btn-sm">Alle Rechnungen &rarr;</a></div>
</div>

<div class="card">
    <h2>Abrechnung <?= date('Y') ?></h2>
    <?php if ($abrechnungen): ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Wohnung</th><th>Mieter</th><th class="text-right">Saldo</th></tr></thead>
        <tbody>
        <?php foreach ($abrechnungen as $a): ?>
        <tr>
            <td><?= htmlspecialchars($a['bezeichnung']) ?></td>
            <td><?= htmlspecialchars($a['mieter_name']) ?></td>
            <td class="text-right <?= $a['saldo'] > 0 ? 'positiv' : 'negativ' ?>">
                <?= number_format($a['saldo'], 2, ',', '.') ?> &euro;
                <?= $a['saldo'] > 0 ? '(Nachzahlung)' : '(Guthaben)' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine Abrechnung f&uuml;r <?= date('Y') ?> erstellt.</p>
    <?php endif; ?>
    <div style="margin-top:.75rem"><a href="pages/abrechnung.php" class="btn btn-accent btn-sm">Abrechnung erstellen &rarr;</a></div>
</div>

</div>

<div class="card">
    <h2>Schnellzugriff</h2>
    <div class="btn-group">
        <a href="pages/rechnungen.php" class="btn btn-primary">+ Rechnung erfassen</a>
        <a href="pages/zaehler.php"    class="btn btn-primary">+ Z&auml;hlerstand</a>
        <a href="pages/wirtschaftlichkeit.php" class="btn" style="background:#e8a020;color:#fff">+ Miete / Eigentümerkosten</a>
        <a href="pages/abrechnung.php" class="btn btn-accent">Jahresabrechnung</a>
        <a href="pages/dokumente.php"  class="btn btn-success">Dokumente</a>
        <a href="pages/backup.php"     class="btn btn-success">Backup</a>
    </div>
</div>

<?php include 'assets/footer.php'; ?>
