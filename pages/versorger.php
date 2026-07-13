<?php
/**
 * Versorger – Strom / Wasser / Abwasser.
 * Erfasst laufende Abschlagszahlungen (Liquiditäts-Übersicht) und die
 * Jahresabrechnung des Versorgers. Per Klick wird aus der Jahres-
 * abrechnung eine normale Rechnung erzeugt (mit der hinterlegten
 * Kostenart), die dann durch die bestehende Abrechnungslogik auf die
 * Mieter umgelegt wird – Strom nach Umlage, Wasser/Abwasser nach den
 * Wasserzählern. Die Kernabrechnung bleibt unverändert.
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';
$pageTitle = 'Versorger';
$basePath  = '../';

if (!istAdmin()) {
    header('Location: ../index.php');
    exit;
}

$objektId = aktivesObjekt();

$sparten = [
    'strom'     => 'Strom (Allgemeinstrom)',
    'wasser'    => 'Wasser (Frischwasser)',
    'abwasser'  => 'Abwasser',
    'sonstiges' => 'Sonstiges',
];
$rhythmen = [
    'monatlich'         => 'monatlich',
    'vierteljaehrlich'  => 'vierteljährlich',
    'jaehrlich'         => 'jährlich',
];
$zahlungenProJahr = ['monatlich' => 12, 'vierteljaehrlich' => 4, 'jaehrlich' => 1];

$kostenarten = $db->query("SELECT id, bezeichnung FROM kostenarten WHERE aktiv=1 ORDER BY bezeichnung")->fetchAll();

// ── Versorger anlegen ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['neuer_versorger'])) {
    csrfPruefen();
    $name    = trim($_POST['name'] ?? '');
    $sparte  = array_key_exists($_POST['sparte'] ?? '', $sparten) ? $_POST['sparte'] : 'sonstiges';
    $kostId  = $_POST['kostenart_id'] !== '' ? (int)$_POST['kostenart_id'] : null;
    $rhyth   = array_key_exists($_POST['rhythmus'] ?? '', $rhythmen) ? $_POST['rhythmus'] : 'monatlich';
    $abschl  = (float)str_replace(',', '.', $_POST['abschlag'] ?? '0');
    $kdnr    = trim($_POST['kundennummer'] ?? '');
    $notiz   = trim($_POST['notiz'] ?? '');

    if ($name === '') {
        $errorMsg = 'Bitte einen Namen angeben.';
    } else {
        $db->prepare("
            INSERT INTO versorger (objekt_id, name, sparte, kostenart_id, rhythmus, abschlag, kundennummer, notiz)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([$objektId, $name, $sparte, $kostId, $rhyth, $abschl, $kdnr, $notiz]);
        $successMsg = 'Versorger angelegt.';
    }
}

// ── Versorger aktiv/inaktiv ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_versorger'])) {
    csrfPruefen();
    $db->prepare("UPDATE versorger SET aktiv = 1 - aktiv WHERE id = ? AND objekt_id = ?")->execute([(int)$_POST['toggle_versorger'], $objektId]);
    header('Location: versorger.php'); exit;
}

// ── Abschlagszahlung erfassen ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['neue_zahlung'])) {
    csrfPruefen();
    $vid    = (int)$_POST['versorger_id'];
    $datum  = $_POST['datum'] ?? '';
    $betrag = (float)str_replace(',', '.', $_POST['betrag'] ?? '0');
    $notiz  = trim($_POST['notiz'] ?? '');
    $chkV = $db->prepare("SELECT COUNT(*) FROM versorger WHERE id=? AND objekt_id=?");
    $chkV->execute([$vid, $objektId]);
    if ($vid && $datum && $betrag > 0 && $chkV->fetchColumn()) {
        $db->prepare("INSERT INTO versorger_zahlungen (versorger_id, datum, betrag, notiz) VALUES (?,?,?,?)")
           ->execute([$vid, $datum, $betrag, $notiz]);
        $successMsg = 'Zahlung erfasst.';
    } else {
        $errorMsg = 'Bitte Datum und Betrag angeben.';
    }
}

// ── Zahlung löschen ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_zahlung'])) {
    csrfPruefen();
    $db->prepare("
        DELETE z FROM versorger_zahlungen z
        JOIN versorger v ON z.versorger_id = v.id
        WHERE z.id = ? AND v.objekt_id = ?
    ")->execute([(int)$_POST['delete_zahlung'], $objektId]);
    header('Location: versorger.php?v=' . (int)$_POST['zurueck_versorger']); exit;
}

// ── Jahresabrechnung des Versorgers erfassen ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['neue_abrechnung'])) {
    csrfPruefen();
    $vid     = (int)$_POST['versorger_id'];
    $von     = $_POST['zeitraum_von'] ?? '';
    $bis     = $_POST['zeitraum_bis'] ?? '';
    $gesamt  = (float)str_replace(',', '.', $_POST['gesamtkosten'] ?? '0');
    $verbr   = trim($_POST['verbrauch'] ?? '');
    $abschl  = (float)str_replace(',', '.', $_POST['abschlaege'] ?? '0');
    $notiz   = trim($_POST['notiz'] ?? '');
    $chkV2 = $db->prepare("SELECT COUNT(*) FROM versorger WHERE id=? AND objekt_id=?");
    $chkV2->execute([$vid, $objektId]);
    if ($vid && $von && $bis && $gesamt > 0 && $chkV2->fetchColumn()) {
        $db->prepare("
            INSERT INTO versorger_abrechnungen (versorger_id, zeitraum_von, zeitraum_bis, gesamtkosten, verbrauch, abschlaege, notiz)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$vid, $von, $bis, $gesamt, $verbr, $abschl, $notiz]);
        $successMsg = 'Jahresabrechnung erfasst. Du kannst sie jetzt in die Nebenkostenabrechnung übernehmen.';
    } else {
        $errorMsg = 'Bitte Zeitraum und Gesamtkosten angeben.';
    }
}

// ── Jahresabrechnung in eine Rechnung übernehmen ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['uebernehmen_id'])) {
    csrfPruefen();
    $aid = (int)$_POST['uebernehmen_id'];
    $stmt = $db->prepare("
        SELECT va.*, v.name AS versorger_name, v.kostenart_id
        FROM versorger_abrechnungen va
        JOIN versorger v ON va.versorger_id = v.id
        WHERE va.id = ? AND va.rechnung_id IS NULL AND v.objekt_id = ?
    ");
    $stmt->execute([$aid, $objektId]);
    $va = $stmt->fetch();

    if (!$va) {
        $errorMsg = 'Abrechnung nicht gefunden oder bereits übernommen.';
    } elseif (!$va['kostenart_id']) {
        $errorMsg = 'Diesem Versorger ist keine Kostenart zugeordnet – bitte zuerst beim Versorger eine Kostenart hinterlegen.';
    } else {
        // Als umzulegende Rechnung anlegen (wohnung_id NULL → greift die Umlage/Verbrauchsverteilung)
        // Rechnungsdatum = Ende des Abrechnungszeitraums, damit sie im richtigen Zeitraum landet
        $jahr = (int)date('Y', strtotime($va['zeitraum_bis']));
        $beschreibung = 'Jahresabrechnung ' . $va['versorger_name'] . ' ('
            . date('d.m.Y', strtotime($va['zeitraum_von'])) . '–' . date('d.m.Y', strtotime($va['zeitraum_bis'])) . ')';
        $db->prepare("
            INSERT INTO rechnungen (objekt_id, kostenart_id, wohnung_id, datum, betrag, jahr, beschreibung, dateiname)
            VALUES (?, ?, NULL, ?, ?, ?, ?, '')
        ")->execute([$objektId, $va['kostenart_id'], $va['zeitraum_bis'], $va['gesamtkosten'], $jahr, $beschreibung]);
        $rechnungId = $db->lastInsertId();

        $db->prepare("UPDATE versorger_abrechnungen SET rechnung_id = ?, uebernommen_am = NOW() WHERE id = ?")
           ->execute([$rechnungId, $aid]);
        $successMsg = 'Übernommen: Die Kosten liegen jetzt als umzulegende Rechnung vor und fließen in die Abrechnung des Zeitraums ein.';
    }
}

// ── Übernahme rückgängig (Rechnung wieder entfernen) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ruecknahme_id'])) {
    csrfPruefen();
    $aid = (int)$_POST['ruecknahme_id'];
    $stmt = $db->prepare("
        SELECT va.rechnung_id FROM versorger_abrechnungen va
        JOIN versorger v ON va.versorger_id = v.id
        WHERE va.id = ? AND v.objekt_id = ?
    ");
    $stmt->execute([$aid, $objektId]);
    if ($rid = $stmt->fetchColumn()) {
        $db->prepare("DELETE FROM rechnungen WHERE id = ?")->execute([(int)$rid]);
        $db->prepare("UPDATE versorger_abrechnungen SET rechnung_id = NULL, uebernommen_am = NULL WHERE id = ?")->execute([$aid]);
        $successMsg = 'Übernahme rückgängig gemacht – die Rechnung wurde wieder entfernt.';
    }
}

// ── Daten laden ──────────────────────────────────────────────
$vlStmt = $db->prepare("
    SELECT v.*, k.bezeichnung AS kostenart_bez
    FROM versorger v
    LEFT JOIN kostenarten k ON v.kostenart_id = k.id
    WHERE v.objekt_id = ?
    ORDER BY v.aktiv DESC, v.sparte, v.name
");
$vlStmt->execute([$objektId]);
$versorgerListe = $vlStmt->fetchAll();

// Detailansicht eines Versorgers (Zahlungen + Abrechnungen)
$detail = null;
$detailZahlungen = [];
$detailAbrechnungen = [];
$summeZahlungenJahr = [];
if (isset($_GET['v'])) {
    $stmt = $db->prepare("SELECT v.*, k.bezeichnung AS kostenart_bez FROM versorger v LEFT JOIN kostenarten k ON v.kostenart_id=k.id WHERE v.id=? AND v.objekt_id=?");
    $stmt->execute([(int)$_GET['v'], $objektId]);
    $detail = $stmt->fetch();
    if ($detail) {
        $stmt = $db->prepare("SELECT * FROM versorger_zahlungen WHERE versorger_id=? ORDER BY datum DESC");
        $stmt->execute([$detail['id']]);
        $detailZahlungen = $stmt->fetchAll();

        // Summe der Abschläge je Kalenderjahr
        foreach ($detailZahlungen as $z) {
            $j = (int)date('Y', strtotime($z['datum']));
            $summeZahlungenJahr[$j] = ($summeZahlungenJahr[$j] ?? 0) + (float)$z['betrag'];
        }

        $stmt = $db->prepare("SELECT * FROM versorger_abrechnungen WHERE versorger_id=? ORDER BY zeitraum_bis DESC");
        $stmt->execute([$detail['id']]);
        $detailAbrechnungen = $stmt->fetchAll();
    }
}

include '../assets/header.php';
?>
<div class="page-header"><h1>Versorger – Strom, Wasser, Abwasser</h1></div>

<?php if (!$detail): ?>
<!-- ═══════════ ÜBERSICHT ═══════════ -->
<div class="card">
    <h2>Versorger anlegen</h2>
    <form method="post">
        <input type="hidden" name="neuer_versorger" value="1">
        <?= csrfFeld() ?>
        <div class="form-grid">
            <div class="form-group">
                <label>Name *</label>
                <input type="text" name="name" placeholder="z. B. Stadtwerke Marktredwitz" required>
            </div>
            <div class="form-group">
                <label>Sparte</label>
                <select name="sparte">
                    <?php foreach ($sparten as $wert => $label): ?>
                    <option value="<?= $wert ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Kostenart (für die Umlage) *</label>
                <select name="kostenart_id">
                    <option value="">-- bitte wählen --</option>
                    <?php foreach ($kostenarten as $k): ?>
                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['bezeichnung']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Zahlungsrhythmus</label>
                <select name="rhythmus">
                    <?php foreach ($rhythmen as $wert => $label): ?>
                    <option value="<?= $wert ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Erwarteter Abschlag (€)</label>
                <input type="text" name="abschlag" placeholder="z. B. 120,00">
            </div>
            <div class="form-group">
                <label>Kundennummer</label>
                <input type="text" name="kundennummer">
            </div>
            <div class="form-group" style="grid-column: 1 / -1">
                <label>Notiz</label>
                <input type="text" name="notiz" placeholder="z. B. Zählernummer, Ansprechpartner">
            </div>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary">Versorger anlegen</button>
        </div>
    </form>
    <p style="margin-top:.75rem;color:var(--muted);font-size:.85rem">
        Tipp: Wasser und Abwasser als zwei getrennte Versorger anlegen (verschiedene Kommunen),
        beide mit der jeweiligen Kostenart – die Verteilung nach Wasserzählern übernimmt danach
        automatisch die Jahresabrechnung.
    </p>
</div>

<div class="card">
    <h2>Meine Versorger (<?= count($versorgerListe) ?>)</h2>
    <?php if ($versorgerListe): ?>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Name</th><th>Sparte</th><th>Kostenart</th><th>Rhythmus</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($versorgerListe as $v): ?>
        <tr<?= $v['aktiv'] ? '' : ' style="opacity:.5"' ?>>
            <td><strong><?= htmlspecialchars($v['name']) ?></strong><?= $v['kundennummer'] ? '<br><small style="color:var(--muted)">Kd.-Nr. ' . htmlspecialchars($v['kundennummer']) . '</small>' : '' ?></td>
            <td><?= htmlspecialchars($sparten[$v['sparte']] ?? $v['sparte']) ?></td>
            <td>
                <?php if ($v['kostenart_bez']): ?>
                <span class="badge badge-info"><?= htmlspecialchars($v['kostenart_bez']) ?></span>
                <?php else: ?>
                <span class="badge badge-warning">keine</span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($rhythmen[$v['rhythmus']] ?? $v['rhythmus']) ?></td>
            <td><?= $v['aktiv'] ? '<span class="badge badge-success">aktiv</span>' : '<span class="badge badge-danger">inaktiv</span>' ?></td>
            <td style="white-space:nowrap">
                <a href="?v=<?= $v['id'] ?>" class="btn btn-sm btn-primary">Zahlungen &amp; Abrechnung</a>
                <form method="post" style="display:inline" onsubmit="return confirm('<?= $v['aktiv'] ? 'Versorger als inaktiv markieren?' : 'Wieder aktivieren?' ?>')">
                    <?= csrfFeld() ?>
                    <input type="hidden" name="toggle_versorger" value="<?= $v['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:#e2e8f0;color:#333"><?= $v['aktiv'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine Versorger angelegt.</p>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ═══════════ DETAIL EINES VERSORGERS ═══════════ -->
<div style="margin-bottom:1rem">
    <a href="versorger.php" class="btn btn-sm" style="background:#e2e8f0;color:#333">← Alle Versorger</a>
</div>

<div class="card" style="border-left:4px solid var(--primary)">
    <h2><?= htmlspecialchars($detail['name']) ?>
        <span style="font-size:.8rem;font-weight:400;color:var(--muted)">
            – <?= htmlspecialchars($sparten[$detail['sparte']] ?? $detail['sparte']) ?>,
            <?= htmlspecialchars($rhythmen[$detail['rhythmus']] ?? $detail['rhythmus']) ?>
        </span>
    </h2>
    <div style="font-size:.9rem;color:var(--muted)">
        Kostenart für die Umlage:
        <?= $detail['kostenart_bez'] ? '<strong>' . htmlspecialchars($detail['kostenart_bez']) . '</strong>' : '<span style="color:var(--danger)">noch keine zugeordnet</span>' ?>
        <?php if ($detail['notiz']): ?> &nbsp;|&nbsp; <?= htmlspecialchars($detail['notiz']) ?><?php endif; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;flex-wrap:wrap">

<!-- Abschlagszahlungen -->
<div class="card">
    <h2>Abschlagszahlungen erfassen</h2>
    <form method="post">
        <input type="hidden" name="neue_zahlung" value="1">
        <input type="hidden" name="versorger_id" value="<?= $detail['id'] ?>">
        <?= csrfFeld() ?>
        <div class="form-grid">
            <div class="form-group"><label>Datum *</label><input type="date" name="datum" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-group"><label>Betrag (€) *</label><input type="text" name="betrag" value="<?= $detail['abschlag'] > 0 ? number_format($detail['abschlag'],2,',','') : '' ?>" required></div>
            <div class="form-group" style="grid-column:1 / -1"><label>Notiz</label><input type="text" name="notiz" placeholder="optional"></div>
        </div>
        <div style="margin-top:1rem"><button type="submit" class="btn btn-primary">Zahlung erfassen</button></div>
    </form>

    <?php if ($summeZahlungenJahr): ?>
    <div style="margin-top:1.25rem">
        <h3 style="font-size:.95rem;color:var(--primary);margin-bottom:.5rem">Summe Abschläge je Jahr</h3>
        <div class="table-wrap"><table class="sortable">
            <thead><tr><th>Jahr</th><th class="text-right">Summe</th></tr></thead>
            <tbody>
            <?php krsort($summeZahlungenJahr); foreach ($summeZahlungenJahr as $jahr => $summe): ?>
            <tr><td><?= $jahr ?></td><td class="text-right"><strong><?= number_format($summe,2,',','.') ?> €</strong></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <?php if ($detailZahlungen): ?>
    <div style="margin-top:1.25rem">
        <h3 style="font-size:.95rem;color:var(--primary);margin-bottom:.5rem">Einzelne Zahlungen</h3>
        <div class="table-wrap"><table class="sortable">
            <thead><tr><th>Datum</th><th class="text-right">Betrag</th><th>Notiz</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($detailZahlungen as $z): ?>
            <tr>
                <td><?= date('d.m.Y', strtotime($z['datum'])) ?></td>
                <td class="text-right"><?= number_format($z['betrag'],2,',','.') ?> €</td>
                <td><?= htmlspecialchars($z['notiz']) ?></td>
                <td>
                    <form method="post" style="display:inline" onsubmit="return confirm('Zahlung löschen?')">
                        <?= csrfFeld() ?>
                        <input type="hidden" name="delete_zahlung" value="<?= $z['id'] ?>">
                        <input type="hidden" name="zurueck_versorger" value="<?= $detail['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">✕</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>
</div>

<!-- Jahresabrechnung -->
<div class="card">
    <h2>Jahresabrechnung des Versorgers</h2>
    <form method="post">
        <input type="hidden" name="neue_abrechnung" value="1">
        <input type="hidden" name="versorger_id" value="<?= $detail['id'] ?>">
        <?= csrfFeld() ?>
        <div class="form-grid">
            <div class="form-group"><label>Zeitraum von *</label><input type="date" name="zeitraum_von" value="<?= (date('Y')-1) ?>-01-01" required></div>
            <div class="form-group"><label>Zeitraum bis *</label><input type="date" name="zeitraum_bis" value="<?= (date('Y')-1) ?>-12-31" required></div>
            <div class="form-group"><label>Gesamtkosten laut Abrechnung (€) *</label><input type="text" name="gesamtkosten" placeholder="z. B. 1.480,00" required></div>
            <div class="form-group"><label>Verbrauch (Info)</label><input type="text" name="verbrauch" placeholder="z. B. 1.240 kWh / 180 m³"></div>
            <div class="form-group"><label>Geleistete Abschläge (€)</label><input type="text" name="abschlaege" placeholder="optional"></div>
            <div class="form-group" style="grid-column:1 / -1"><label>Notiz</label><input type="text" name="notiz" placeholder="optional"></div>
        </div>
        <div style="margin-top:1rem"><button type="submit" class="btn btn-accent">Jahresabrechnung speichern</button></div>
    </form>
    <p style="margin-top:.75rem;color:var(--muted);font-size:.85rem">
        Es werden die <strong>Gesamtkosten</strong> auf die Mieter umgelegt (nicht deine Abschläge).
        Nach dem Speichern kannst du die Abrechnung unten mit einem Klick in die Nebenkostenabrechnung übernehmen.
    </p>

    <?php if ($detailAbrechnungen): ?>
    <div style="margin-top:1.25rem">
        <h3 style="font-size:.95rem;color:var(--primary);margin-bottom:.5rem">Erfasste Jahresabrechnungen</h3>
        <div class="table-wrap"><table class="sortable">
            <thead><tr><th>Zeitraum</th><th class="text-right">Gesamtkosten</th><th>Verbrauch</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($detailAbrechnungen as $va):
                // Summe der Abschläge im Zeitraum zum Vergleich
                $vergleich = 0;
                foreach ($detailZahlungen as $z) {
                    if ($z['datum'] >= $va['zeitraum_von'] && $z['datum'] <= $va['zeitraum_bis']) $vergleich += (float)$z['betrag'];
                }
                $diff = (float)$va['gesamtkosten'] - $vergleich;
            ?>
            <tr>
                <td>
                    <?= date('d.m.Y', strtotime($va['zeitraum_von'])) ?><br>
                    <?= date('d.m.Y', strtotime($va['zeitraum_bis'])) ?>
                </td>
                <td class="text-right"><strong><?= number_format($va['gesamtkosten'],2,',','.') ?> €</strong>
                    <?php if ($vergleich > 0): ?>
                    <br><small style="color:var(--muted)">Abschläge: <?= number_format($vergleich,2,',','.') ?> €<br>
                    <?= $diff > 0 ? 'Nachzahlung ' : 'Guthaben ' ?><?= number_format(abs($diff),2,',','.') ?> €</small>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($va['verbrauch'] ?: '–') ?></td>
                <td>
                    <?php if ($va['rechnung_id']): ?>
                    <span class="badge badge-success">übernommen</span>
                    <?php else: ?>
                    <span class="badge badge-warning">offen</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                    <?php if (!$va['rechnung_id']): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Gesamtkosten als umzulegende Rechnung übernehmen?')">
                        <?= csrfFeld() ?>
                        <input type="hidden" name="uebernehmen_id" value="<?= $va['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-success">In Abrechnung übernehmen</button>
                    </form>
                    <?php else: ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Übernahme rückgängig machen? Die erzeugte Rechnung wird gelöscht.')">
                        <?= csrfFeld() ?>
                        <input type="hidden" name="ruecknahme_id" value="<?= $va['id'] ?>">
                        <button type="submit" class="btn btn-sm" style="background:#e2e8f0;color:#333">Rückgängig</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>
</div>

</div>
<?php endif; ?>

<?php include '../assets/footer.php'; ?>
