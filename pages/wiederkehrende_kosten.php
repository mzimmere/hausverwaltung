<?php
/**
 * Wiederkehrende Gruppenkosten – z.B. Hausmeister, Treppenhausreinigung,
 * die dauerhaft auf eine selbst gewählte Gruppe von Wohnungen läuft,
 * ohne dass jeden Monat eine einzelne Rechnung erfasst werden muss.
 * Wird automatisch zeitanteilig in jede Abrechnung übernommen.
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

$pageTitle = 'Wiederkehrende Kosten';
$basePath  = '../';

$objektId = aktivesObjekt();

// ── Speichern ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    leserSchreibschutz();
    csrfPruefen();
    $kostenartId = (int)$_POST['kostenart_id'];
    $bezeich     = trim($_POST['bezeichnung']);
    $betrag      = str_replace(',', '.', $_POST['betrag_pro_monat']);
    $von         = $_POST['gueltig_von'];
    $bis         = $_POST['gueltig_bis'] !== '' ? $_POST['gueltig_bis'] : null;
    $notiz       = trim($_POST['notiz']);

    $verteilModus = $_POST['verteil_modus'] ?? 'prozent'; // prozent | volle_summe
    $gewaehlte    = $_POST['wohnung'] ?? [];

    if ($verteilModus === 'volle_summe') {
        $angehakt = array_filter($gewaehlte, fn($w) => (int)$w > 0);
        if (empty($angehakt)) {
            $errorMsg = "Bitte mindestens eine Wohnung auswählen.";
        } else {
            $stmt = $db->prepare("
                INSERT INTO wiederkehrende_kosten (objekt_id, kostenart_id, bezeichnung, betrag_pro_monat, gueltig_von, gueltig_bis, notiz)
                VALUES (?,?,?,?,?,?,?)
            ");
            $stmt->execute([$objektId, $kostenartId, $bezeich, $betrag, $von, $bis, $notiz]);
            $wkId = $db->lastInsertId();

            $insW = $db->prepare("INSERT INTO wiederkehrende_kosten_wohnungen (wiederkehrende_kosten_id, wohnung_id, anteil) VALUES (?,?,?)");
            foreach ($angehakt as $wId) {
                $insW->execute([$wkId, (int)$wId, 1.0]);
            }
            protokolliere('wiederkehrende_kosten', 'anlegen', (int)$wkId, "\"$bezeich\" angelegt");
            $successMsg = "Wiederkehrende Kosten \"$bezeich\" angelegt. Jede der " . count($angehakt) . " ausgewählten Wohnungen zahlt den vollen Betrag von " . number_format($betrag, 2, ',', '.') . " € / Monat.";
        }
    } else {
        $anteile = $_POST['anteil'] ?? [];
        $summeAnteile = 0;
        foreach ($gewaehlte as $idx => $wId) {
            if ((int)$wId && isset($anteile[$idx])) {
                $summeAnteile += (float)str_replace(',', '.', $anteile[$idx]);
            }
        }

        if (round($summeAnteile, 2) != 100.0) {
            $errorMsg = "Die Anteile ergeben " . number_format($summeAnteile, 1, ',', '.') . " % statt 100 %. Bitte korrigieren.";
        } else {
            $stmt = $db->prepare("
                INSERT INTO wiederkehrende_kosten (objekt_id, kostenart_id, bezeichnung, betrag_pro_monat, gueltig_von, gueltig_bis, notiz)
                VALUES (?,?,?,?,?,?,?)
            ");
            $stmt->execute([$objektId, $kostenartId, $bezeich, $betrag, $von, $bis, $notiz]);
            $wkId = $db->lastInsertId();

            $insW = $db->prepare("INSERT INTO wiederkehrende_kosten_wohnungen (wiederkehrende_kosten_id, wohnung_id, anteil) VALUES (?,?,?)");
            foreach ($gewaehlte as $idx => $wId) {
                $wId = (int)$wId;
                $proz = isset($anteile[$idx]) ? (float)str_replace(',', '.', $anteile[$idx]) : 0;
                if ($wId && $proz > 0) {
                    $insW->execute([$wkId, $wId, $proz / 100]);
                }
            }
            protokolliere('wiederkehrende_kosten', 'anlegen', (int)$wkId, "\"$bezeich\" angelegt");
            $successMsg = "Wiederkehrende Kosten \"$bezeich\" angelegt. Werden ab sofort automatisch in jede Abrechnung übernommen.";
        }
    }
}

// ── Beenden / Löschen ──────────────────────────────────────────
if (isset($_GET['beenden'])) {
    leserSchreibschutz();
    $beendenId = (int)$_GET['beenden'];
    $db->prepare("UPDATE wiederkehrende_kosten SET gueltig_bis = CURDATE() WHERE id=? AND objekt_id=?")->execute([$beendenId, $objektId]);
    protokolliere('wiederkehrende_kosten', 'aendern', $beendenId, 'zum heutigen Datum beendet');
    $successMsg = 'Wiederkehrende Kosten wurden zum heutigen Datum beendet.';
}
if (isset($_GET['delete'])) {
    leserSchreibschutz();
    $delId = (int)$_GET['delete'];
    $db->prepare("DELETE FROM wiederkehrende_kosten WHERE id=? AND objekt_id=?")->execute([$delId, $objektId]); // CASCADE löscht Zuordnungen mit
    protokolliere('wiederkehrende_kosten', 'loeschen', $delId, 'gelöscht');
    $successMsg = 'Wiederkehrende Kosten gelöscht.';
}
if (isset($_GET['toggle'])) {
    leserSchreibschutz();
    $toggleId = (int)$_GET['toggle'];
    $db->prepare("UPDATE wiederkehrende_kosten SET aktiv = 1 - aktiv WHERE id=? AND objekt_id=?")->execute([$toggleId, $objektId]);
    protokolliere('wiederkehrende_kosten', 'aendern', $toggleId, 'aktiv/inaktiv umgeschaltet');
}

// ── Daten laden ──────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=? ORDER BY id");
$stmt->execute([$objektId]);
$wohnungen   = $stmt->fetchAll();
$kostenarten = $db->query("SELECT * FROM kostenarten WHERE aktiv=1 ORDER BY bezeichnung")->fetchAll();

$stmt = $db->prepare("
    SELECT wk.*, k.bezeichnung AS kostenart
    FROM wiederkehrende_kosten wk JOIN kostenarten k ON wk.kostenart_id = k.id
    WHERE wk.objekt_id=?
    ORDER BY wk.aktiv DESC, wk.gueltig_von DESC
");
$stmt->execute([$objektId]);
$alleWk = $stmt->fetchAll();

$zuordnungenJeWk = [];
if ($alleWk) {
    $ids = array_column($alleWk, 'id');
    $platzhalter = implode(',', array_fill(0, count($ids), '?'));
    $zStmt = $db->prepare("
        SELECT wkw.wiederkehrende_kosten_id, wkw.anteil, w.bezeichnung AS wohnung
        FROM wiederkehrende_kosten_wohnungen wkw JOIN wohnungen w ON wkw.wohnung_id = w.id
        WHERE wkw.wiederkehrende_kosten_id IN ($platzhalter)
        ORDER BY w.id
    ");
    $zStmt->execute($ids);
    foreach ($zStmt->fetchAll() as $z) {
        $zuordnungenJeWk[$z['wiederkehrende_kosten_id']][] = $z;
    }
}

include '../assets/header.php';
?>

<div class="page-header">
    <h1>Wiederkehrende Kosten (mehrere Wohnungen)</h1>
</div>

<div class="alert alert-info">
    ℹ️ <strong>Beispiel:</strong> Hausmeister kostet 200 €/Monat, aber nur EG und OG zahlen dafür
    (DG hat eine eigene Vereinbarung). Hier einmal einrichten – die Kosten werden automatisch
    zeitanteilig in jede künftige Abrechnung übernommen, ohne dass Sie jeden Monat eine
    einzelne Rechnung erfassen müssen.
</div>

<?php if (!istNurLesend()): ?>
<div class="card">
    <h2>Neue wiederkehrende Kosten anlegen</h2>
    <form method="post">
        <?= csrfFeld() ?>
        <input type="hidden" name="action" value="save">
        <div class="form-grid">
            <div class="form-group">
                <label>Kostenart *</label>
                <select name="kostenart_id" required>
                    <option value="">-- bitte wählen --</option>
                    <?php foreach ($kostenarten as $k): ?>
                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['bezeichnung']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Bezeichnung *</label>
                <input type="text" name="bezeichnung" placeholder="z.B. Hausmeister EG+OG" required>
            </div>
            <div class="form-group">
                <label>Betrag pro Monat (€) *</label>
                <input type="text" name="betrag_pro_monat" placeholder="200.00" required>
            </div>
            <div class="form-group">
                <label>Gültig ab *</label>
                <input type="date" name="gueltig_von" value="<?= date('Y-01-01') ?>" required>
            </div>
            <div class="form-group">
                <label>Gültig bis (optional)</label>
                <input type="date" name="gueltig_bis">
                <small style="color:var(--muted)">Leer lassen = läuft weiter bis auf Widerruf</small>
            </div>
            <div class="form-group">
                <label>Notiz</label>
                <input type="text" name="notiz" placeholder="z.B. Vereinbarung vom ...">
            </div>
        </div>

        <div style="margin-top:1.25rem;padding:1rem;background:#f8fafc;border-radius:8px">
            <label style="display:block;margin-bottom:.6rem;font-weight:600;font-size:.85rem;color:var(--muted)">
                Welche Wohnungen sind beteiligt?
            </label>

            <div style="display:flex;gap:1.5rem;margin-bottom:1rem;flex-wrap:wrap">
                <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;cursor:pointer">
                    <input type="radio" name="verteil_modus" value="prozent" checked onchange="wkVerteilModusAendern()">
                    Betrag aufteilen (Anteile in % geben zusammen 100 %)
                </label>
                <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;cursor:pointer">
                    <input type="radio" name="verteil_modus" value="volle_summe" onchange="wkVerteilModusAendern()">
                    Jede Wohnung zahlt den vollen Betrag
                </label>
            </div>

            <p id="wk-hinweis-volle-summe" style="display:none;margin-bottom:.6rem;color:#856404;font-size:.85rem;background:#fff3cd;padding:.5rem .75rem;border-radius:6px">
                Beispiel: 200 €/Monat eingetragen, EG + OG angekreuzt → EG zahlt 200 €/Monat
                <strong>und</strong> OG zahlt ebenfalls 200 €/Monat.
            </p>

            <table style="width:100%;max-width:480px">
                <?php foreach ($wohnungen as $w): ?>
                <tr>
                    <td style="padding:.3rem 0">
                        <label style="display:flex;align-items:center;gap:.5rem;font-weight:400;cursor:pointer">
                            <input type="checkbox" name="wohnung[]" value="<?= $w['id'] ?>" class="wk-checkbox">
                            <?= htmlspecialchars($w['bezeichnung']) ?> – <?= htmlspecialchars($w['mieter_name']) ?>
                        </label>
                    </td>
                    <td class="wk-anteil-spalte" style="padding:.3rem 0;width:110px">
                        <input type="text" name="anteil[]" placeholder="%" style="width:80px;padding:.4rem">
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <button type="button" id="wk-btn-gleich-verteilen" class="btn btn-sm" style="background:#e2e8f0;margin-top:.5rem" onclick="wkGleichVerteilen()">Gleich verteilen (auf angehakte Wohnungen)</button>
        </div>

        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary">Wiederkehrende Kosten anlegen</button>
        </div>
    </form>
</div>

<script>
function wkVerteilModusAendern() {
    const modus = document.querySelector('input[name="verteil_modus"]:checked').value;
    const volleSumme = (modus === 'volle_summe');
    document.getElementById('wk-hinweis-volle-summe').style.display = volleSumme ? 'block' : 'none';
    document.querySelectorAll('.wk-anteil-spalte').forEach(td => td.style.display = volleSumme ? 'none' : '');
    document.getElementById('wk-btn-gleich-verteilen').style.display = volleSumme ? 'none' : '';
}
function wkGleichVerteilen() {
    const checkboxen = document.querySelectorAll('.wk-checkbox:checked');
    if (checkboxen.length === 0) { alert('Bitte zuerst Wohnungen ankreuzen.'); return; }
    const anteil = (100 / checkboxen.length).toFixed(2);
    document.querySelectorAll('.wk-checkbox').forEach(cb => {
        const feld = cb.closest('tr').querySelector('input[name="anteil[]"]');
        feld.value = cb.checked ? anteil : '';
    });
}
</script>
<?php endif; ?>

<div class="card">
    <h2>Alle wiederkehrenden Kosten</h2>
    <?php if ($alleWk): ?>
    <div class="table-wrap">
    <table class="sortable">
        <thead>
            <tr>
                <th>Bezeichnung</th>
                <th>Kostenart</th>
                <th>Wohnungen / Anteile</th>
                <th class="text-right">€ / Monat</th>
                <th>Zeitraum</th>
                <th>Status</th>
                <?= istNurLesend() ? '' : '<th></th>' ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($alleWk as $wk): ?>
        <tr>
            <td>
                <?= htmlspecialchars($wk['bezeichnung']) ?>
                <?php if ($wk['notiz']): ?><br><small style="color:var(--muted)"><?= htmlspecialchars($wk['notiz']) ?></small><?php endif; ?>
            </td>
            <td><?= htmlspecialchars($wk['kostenart']) ?></td>
            <td>
                <?php foreach (($zuordnungenJeWk[$wk['id']] ?? []) as $z): ?>
                <span class="badge badge-info" style="margin:1px"><?= htmlspecialchars($z['wohnung']) ?> <?= ((float)$z['anteil'] >= 1.0) ? 'voller Betrag' : round($z['anteil']*100,1) . '%' ?></span>
                <?php endforeach; ?>
            </td>
            <td class="text-right"><?= number_format($wk['betrag_pro_monat'],2,',','.') ?> €</td>
            <td>
                <?= date('d.m.Y', strtotime($wk['gueltig_von'])) ?> –
                <?= $wk['gueltig_bis'] ? date('d.m.Y', strtotime($wk['gueltig_bis'])) : 'laufend' ?>
            </td>
            <td>
                <span class="badge <?= $wk['aktiv'] ? 'badge-success' : 'badge-danger' ?>">
                    <?= $wk['aktiv'] ? 'aktiv' : 'deaktiviert' ?>
                </span>
            </td>
            <?php if (!istNurLesend()): ?>
            <td>
                <div class="btn-group">
                    <?php if (!$wk['gueltig_bis']): ?>
                    <a href="?beenden=<?= $wk['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Zum heutigen Datum beenden?')">Beenden</a>
                    <?php endif; ?>
                    <a href="?toggle=<?= $wk['id'] ?>" class="btn btn-sm" style="background:#e2e8f0"><?= $wk['aktiv'] ? 'Deaktivieren' : 'Aktivieren' ?></a>
                    <a href="?delete=<?= $wk['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Wirklich endgültig löschen?')">✕</a>
                </div>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine wiederkehrenden Kosten erfasst.</p>
    <?php endif; ?>
</div>

<?php include '../assets/footer.php'; ?>
