<?php
/**
 * Freigabe eingereichter Rechnungen – nur für Admins.
 * Liste aller offenen Einreichungen; per Klick öffnet sich die
 * Detailansicht. Der eingereichte Betrag kann dabei auf mehrere
 * Positionen aufgeteilt werden – jede Position ist entweder
 * "umlegbar" (bekommt eine Kostenart, wird nach Umlageschlüssel auf
 * alle Wohnungen verteilt -> landet in `rechnungen`) oder
 * "nicht umlegbar" (bekommt eine Eigentümerkosten-Kategorie -> landet
 * in `eigentuemerkosten`, taucht nur bei wirtschaftlichkeit.php auf).
 * Jede erzeugte Position wird zusätzlich in `einreichung_positionen`
 * nachvollziehbar mit der Einreichung verknüpft.
 * Der ursprünglich eingereichte Betrag bleibt zur Nachvollziehbarkeit
 * in der Einreichung gespeichert; die hochgeladene Datei landet als
 * "kanonische" Kopie im Rechnungsordner (einreichung_datei.php liest
 * von dort), nicht-umlegbare Positionen bekommen zusätzlich eine
 * eigene Kopie im Eigentümerkosten-Belegordner.
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';
$pageTitle = 'Freigabe';
$basePath  = '../';

if (!istAdmin()) {
    header('Location: ../index.php');
    exit;
}

$objektId = aktivesObjekt();

// Meldungen nach Redirect
if (isset($_GET['ok']))          $successMsg = 'Rechnung freigegeben und angelegt.';
if (isset($_GET['abgelehnt']))   $successMsg = 'Einreichung abgelehnt.';
if (isset($_GET['ueberwiesen'])) $successMsg = 'Als überwiesen markiert – der Einreicher sieht die Info bei seiner nächsten Anmeldung.';

// ── Freigeben (ggf. aufgeteilt in mehrere Positionen) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'freigeben') {
    csrfPruefen();
    $eId = (int)$_POST['einreichung_id'];
    $stmt = $db->prepare("SELECT * FROM einreichungen WHERE id = ? AND objekt_id = ? AND status = 'eingereicht'");
    $stmt->execute([$eId, $objektId]);
    $e = $stmt->fetch();

    if (!$e) {
        $errorMsg = 'Einreichung nicht gefunden oder bereits bearbeitet.';
    } else {
        $datum = $_POST['datum'] ?? '';
        $jahr  = (int)($_POST['jahr'] ?? 0);
        $beschreibungBasis = trim($_POST['beschreibung'] ?? '');

        // Positionen einsammeln und validieren: jede braucht Betrag>0 und
        // je nach Typ eine Kostenart (umlegbar) oder Kategorie (nicht umlegbar).
        $positionen = [];
        foreach (($_POST['pos'] ?? []) as $p) {
            $typ = ($p['typ'] ?? '') === 'nicht_umlegbar' ? 'nicht_umlegbar' : 'umlegbar';
            $betrag = (float)str_replace(',', '.', $p['betrag'] ?? '0');
            if ($betrag <= 0) continue;
            if ($typ === 'umlegbar') {
                $kostenartId = (int)($p['kostenart_id'] ?? 0);
                if (!$kostenartId) continue;
                $positionen[] = ['typ' => 'umlegbar', 'betrag' => $betrag, 'kostenart_id' => $kostenartId];
            } else {
                $kategorieId = (int)($p['kategorie_id'] ?? 0);
                if (!$kategorieId) continue;
                $positionen[] = ['typ' => 'nicht_umlegbar', 'betrag' => $betrag, 'kategorie_id' => $kategorieId];
            }
        }

        if (!$datum || !$jahr || empty($positionen)) {
            $errorMsg = 'Bitte Datum, Jahr und mindestens eine gültige Position (Betrag + Kostenart bzw. Kategorie) angeben.';
        } else {
            // Datei aus dem Einreichungs-Ordner in den Rechnungsordner verschieben –
            // das bleibt die "kanonische" Kopie, die einreichung_datei.php ausliefert.
            $quelle = UPLOAD_RECHNUNGEN . $e['dateipfad'];
            $quelleVorhanden = $e['dateipfad'] && is_file($quelle);
            $kanonischerDateiname = '';
            $kanonischerPfad = $e['dateipfad'];

            if ($quelleVorhanden) {
                $zielDirKanonisch = UPLOAD_RECHNUNGEN . $jahr . '/';
                if (!is_dir($zielDirKanonisch)) mkdir($zielDirKanonisch, 0777, true);
                $basisName = $e['original_name'] !== '' ? $e['original_name'] : basename($e['dateipfad']);
                $kanonischerDateiname = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $basisName);
                $kanonischesZiel = $zielDirKanonisch . $kanonischerDateiname;
                if (!rename($quelle, $kanonischesZiel)) {
                    copy($quelle, $kanonischesZiel);
                    @unlink($quelle);
                }
                $kanonischerPfad = $jahr . '/' . $kanonischerDateiname;
            }

            $ersteRechnungId = null;
            $anzahl = count($positionen);
            foreach ($positionen as $idx => $p) {
                $beschreibung = $beschreibungBasis !== '' ? $beschreibungBasis : $e['vermerk'];
                if ($anzahl > 1) {
                    $beschreibung .= ' (Position ' . ($idx + 1) . '/' . $anzahl . ')';
                }

                if ($p['typ'] === 'umlegbar') {
                    // Liegt bereits am richtigen Ort (UPLOAD_RECHNUNGEN/jahr/) – mehrere
                    // Positionen dürfen sich denselben Beleg teilen, das ist unproblematisch.
                    $dateiname = $kanonischerDateiname;

                    $stmt = $db->prepare("INSERT INTO rechnungen (objekt_id, kostenart_id, wohnung_id, datum, betrag, jahr, beschreibung, dateiname) VALUES (?,?,NULL,?,?,?,?,?)");
                    $stmt->execute([$objektId, $p['kostenart_id'], $datum, $p['betrag'], $jahr, $beschreibung, $dateiname]);
                    $zielId = (int)$db->lastInsertId();
                    if ($ersteRechnungId === null) $ersteRechnungId = $zielId;

                    $db->prepare("INSERT INTO einreichung_positionen (einreichung_id, ziel_typ, ziel_id, betrag) VALUES (?, 'rechnung', ?, ?)")
                       ->execute([$eId, $zielId, $p['betrag']]);
                    protokolliere('rechnungen', 'anlegen', $zielId, 'Aus Einreichung #' . $eId . ' freigegeben: ' . number_format($p['betrag'], 2, ',', '.') . ' € (umlegbar)');
                } else {
                    // Nicht umlegbar: eigene Kopie in den Eigentümerkosten-Belegordner,
                    // da wirtschaftlichkeit.php Belege dort erwartet.
                    $dateiname = '';
                    if ($quelleVorhanden) {
                        $zielDirEk = UPLOAD_DIR . 'eigentuemerkosten/' . $jahr . '/';
                        if (!is_dir($zielDirEk)) mkdir($zielDirEk, 0777, true);
                        $dateiname = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $basisName ?? 'beleg');
                        copy(UPLOAD_RECHNUNGEN . $kanonischerPfad, $zielDirEk . $dateiname);
                    }

                    $stmt = $db->prepare("INSERT INTO eigentuemerkosten (objekt_id, kategorie_id, datum, betrag, jahr, beschreibung, dateiname) VALUES (?,?,?,?,?,?,?)");
                    $stmt->execute([$objektId, $p['kategorie_id'], $datum, $p['betrag'], $jahr, $beschreibung, $dateiname]);
                    $zielId = (int)$db->lastInsertId();

                    $db->prepare("INSERT INTO einreichung_positionen (einreichung_id, ziel_typ, ziel_id, betrag) VALUES (?, 'eigentuemerkosten', ?, ?)")
                       ->execute([$eId, $zielId, $p['betrag']]);
                    protokolliere('eigentuemerkosten', 'anlegen', $zielId, 'Aus Einreichung #' . $eId . ' freigegeben: ' . number_format($p['betrag'], 2, ',', '.') . ' € (nicht umlegbar)');
                }
            }

            $summePositionen = array_sum(array_column($positionen, 'betrag'));
            if (round($summePositionen - (float)$e['betrag_eingereicht'], 2) !== 0.0) {
                $successMsg = 'Freigegeben – Achtung: Die Positionen ergeben ' . number_format($summePositionen, 2, ',', '.')
                    . ' € statt der eingereichten ' . number_format($e['betrag_eingereicht'], 2, ',', '.') . ' €. Bitte bei Bedarf einzeln korrigieren.';
            }

            $db->prepare("
                UPDATE einreichungen
                SET status = 'freigegeben', rechnung_id = ?, dateipfad = ?, nachricht = ?, ungelesen = 1, bearbeitet_am = NOW()
                WHERE id = ?
            ")->execute([$ersteRechnungId, $kanonischerPfad, trim($_POST['nachricht'] ?? ''), $eId]);
            protokolliere('einreichungen', 'aendern', $eId, "Einreichung freigegeben, $anzahl Position(en) angelegt");

            if (empty($successMsg)) {
                header('Location: freigabe.php?ok=1');
                exit;
            }
        }
    }
}

// ── Ablehnen ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ablehnen') {
    csrfPruefen();
    $eId   = (int)$_POST['einreichung_id'];
    $grund = trim($_POST['ablehnungsgrund'] ?? '');
    if ($grund === '') {
        $errorMsg = 'Bitte einen kurzen Ablehnungsgrund angeben – der Hausmeister sieht ihn bei seiner Einreichung.';
    } else {
        $db->prepare("
            UPDATE einreichungen
            SET status = 'abgelehnt', ablehnungsgrund = ?, ungelesen = 1, bearbeitet_am = NOW()
            WHERE id = ? AND objekt_id = ? AND status = 'eingereicht'
        ")->execute([$grund, $eId, $objektId]);
        protokolliere('einreichungen', 'aendern', $eId, "Einreichung abgelehnt: $grund");
        header('Location: freigabe.php?abgelehnt=1');
        exit;
    }
}

// ── Als überwiesen markieren ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ueberweisen') {
    csrfPruefen();
    $eId       = (int)$_POST['einreichung_id'];
    $nachricht = trim($_POST['nachricht'] ?? '');
    $stmt = $db->prepare("
        UPDATE einreichungen
        SET status = 'ueberwiesen', ungelesen = 1, bearbeitet_am = NOW(),
            nachricht = IF(? <> '', ?, nachricht)
        WHERE id = ? AND objekt_id = ? AND status = 'freigegeben'
    ");
    $stmt->execute([$nachricht, $nachricht, $eId, $objektId]);
    protokolliere('einreichungen', 'aendern', $eId, 'Einreichung als überwiesen markiert');
    header('Location: freigabe.php?ueberwiesen=1');
    exit;
}

// ── Daten laden ──────────────────────────────────────────────
$detail = null;
if (isset($_GET['id'])) {
    $stmt = $db->prepare("
        SELECT e.*, b.name AS einreicher
        FROM einreichungen e JOIN benutzer b ON e.benutzer_id = b.id
        WHERE e.id = ? AND e.objekt_id = ?
    ");
    $stmt->execute([(int)$_GET['id'], $objektId]);
    $detail = $stmt->fetch();
}

$stmt = $db->prepare("
    SELECT e.*, b.name AS einreicher
    FROM einreichungen e JOIN benutzer b ON e.benutzer_id = b.id
    WHERE e.status = 'eingereicht' AND e.objekt_id = ?
    ORDER BY e.eingereicht_am ASC
");
$stmt->execute([$objektId]);
$offene = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT e.*, b.name AS einreicher
    FROM einreichungen e JOIN benutzer b ON e.benutzer_id = b.id
    WHERE e.status <> 'eingereicht' AND e.objekt_id = ?
    ORDER BY e.bearbeitet_am DESC
    LIMIT 10
");
$stmt->execute([$objektId]);
$erledigte = $stmt->fetchAll();

$kostenarten = $db->query("SELECT * FROM kostenarten WHERE aktiv=1 ORDER BY bezeichnung")->fetchAll();
$kategorien  = $db->query("SELECT * FROM eigentuemerkosten_kategorien ORDER BY bezeichnung")->fetchAll();
$stmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=? ORDER BY id");
$stmt->execute([$objektId]);
$wohnungen = $stmt->fetchAll();

include '../assets/header.php';
?>
<div class="page-header"><h1>Freigabe eingereichter Rechnungen</h1></div>

<?php if ($detail && $detail['status'] === 'eingereicht'): ?>
<!-- ═══════════ DETAIL: Prüfen & Freigeben ═══════════ -->
<div class="card" style="border-left:4px solid var(--primary)">
    <h2>Einreichung prüfen</h2>

    <div class="stat-grid" style="max-width:720px">
        <div class="stat-tile">
            <div class="stat-label">Eingereicht von</div>
            <div style="font-weight:700;margin-top:.3rem"><?= htmlspecialchars($detail['einreicher']) ?></div>
            <div style="font-size:.78rem;color:var(--muted)"><?= date('d.m.Y H:i', strtotime($detail['eingereicht_am'])) ?> Uhr</div>
        </div>
        <div class="stat-tile">
            <div class="stat-label">Eingereichter Betrag</div>
            <div class="stat-value" style="font-size:1.3rem"><?= number_format($detail['betrag_eingereicht'], 2, ',', '.') ?> &euro;</div>
            <div style="font-size:.78rem;color:var(--muted)">bleibt zur Nachvollziehbarkeit gespeichert</div>
        </div>
        <div class="stat-tile">
            <div class="stat-label">Datei</div>
            <div style="margin-top:.4rem">
                <a href="einreichung_datei.php?id=<?= $detail['id'] ?>" target="_blank" class="btn btn-sm btn-primary">📄 Rechnung ansehen</a>
            </div>
            <div style="font-size:.78rem;color:var(--muted);margin-top:.3rem"><?= htmlspecialchars($detail['original_name']) ?></div>
        </div>
        <div class="stat-tile">
            <div class="stat-label">Einschätzung des Einreichers</div>
            <div style="margin-top:.4rem">
                <?php if ($detail['art'] === 'umlegbar'): ?><span class="badge badge-info">umlegbar</span>
                <?php elseif ($detail['art'] === 'nicht_umlegbar'): ?><span class="badge badge-warning">nicht umlegbar</span>
                <?php else: ?><span class="badge badge-info" style="opacity:.6">unklar</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="alert alert-info" style="margin-top:.5rem">
        <strong>Vermerk des Hausmeisters:</strong> <?= htmlspecialchars($detail['vermerk']) ?>
    </div>

    <form method="post" id="freigabeForm" style="margin-top:1rem">
        <input type="hidden" name="action" value="freigeben">
        <?= csrfFeld() ?>
        <input type="hidden" name="einreichung_id" value="<?= $detail['id'] ?>">
        <div class="form-grid">
            <div class="form-group">
                <label>Datum *</label>
                <input type="date" name="datum" value="<?= htmlspecialchars($detail['datum']) ?>" required>
            </div>
            <div class="form-group">
                <label>Jahr *</label>
                <input type="number" name="jahr" value="<?= (int)date('Y', strtotime($detail['datum'])) ?>" required>
            </div>
            <div class="form-group" style="grid-column: 1 / -1">
                <label>Beschreibung</label>
                <input type="text" name="beschreibung"
                       value="<?= htmlspecialchars($detail['vermerk'] . ' (eingereicht von ' . $detail['einreicher'] . ')') ?>">
            </div>
            <div class="form-group" style="grid-column: 1 / -1">
                <label>Nachricht an den Einreicher <span style="font-weight:400;color:var(--muted)">(optional – sieht er bei seiner Einreichung)</span></label>
                <input type="text" name="nachricht" placeholder="z. B. Betrag wird in den nächsten Tagen überwiesen">
            </div>
        </div>

        <!-- ── Positionen: umlegbar (Kostenart) oder nicht umlegbar (Kategorie) ── -->
        <div style="margin-top:1.25rem;padding:1rem;background:var(--tile-neutral);border:1px solid #e8a020;border-radius:8px">
            <label style="color:#c07010;display:block;margin-bottom:.6rem">
                Wie soll dieser Betrag zugeordnet werden? Bei Bedarf auf mehrere Positionen aufteilen.
            </label>

            <div id="positionenListe"></div>

            <button type="button" class="btn btn-sm" style="background:#e2e8f0;margin-top:.25rem" onclick="positionHinzufuegen()">+ Weitere Position</button>

            <div id="positionenSumme" style="margin-top:.75rem;font-size:.9rem"></div>
        </div>

        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-success">✓ Freigeben &amp; anlegen</button>
            <a href="freigabe.php" class="btn" style="background:#e2e8f0;color:#333">Zurück zur Liste</a>
        </div>
    </form>

    <template id="positionVorlage">
        <div class="position-zeile" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;padding:.75rem;background:var(--card-bg);border:1px solid var(--border);border-radius:6px;margin-bottom:.6rem">
            <div class="form-group" style="margin:0;width:120px">
                <label style="font-size:.78rem">Betrag (€) *</label>
                <input type="text" class="pos-betrag" name="pos[__IDX__][betrag]" required oninput="positionenSummeAktualisieren()">
            </div>
            <div class="form-group" style="margin:0;width:190px">
                <label style="font-size:.78rem">Typ *</label>
                <select class="pos-typ" name="pos[__IDX__][typ]" onchange="positionTypAendern(this)">
                    <option value="umlegbar">Umlegbar (Betriebskosten)</option>
                    <option value="nicht_umlegbar">Nicht umlegbar (Eigentümer)</option>
                </select>
            </div>
            <div class="form-group pos-kostenart-feld" style="margin:0;flex:1;min-width:180px">
                <label style="font-size:.78rem">Kostenart *</label>
                <select class="pos-kostenart" name="pos[__IDX__][kostenart_id]">
                    <option value="">-- bitte wählen --</option>
                    <?php foreach ($kostenarten as $k): ?>
                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['bezeichnung']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group pos-kategorie-feld" style="margin:0;flex:1;min-width:180px;display:none">
                <label style="font-size:.78rem">Kategorie *</label>
                <select class="pos-kategorie" name="pos[__IDX__][kategorie_id]">
                    <option value="">-- bitte wählen --</option>
                    <?php foreach ($kategorien as $k): ?>
                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['bezeichnung']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="btn btn-sm btn-danger" style="align-self:center" onclick="positionEntfernen(this)" title="Position entfernen">✕</button>
        </div>
    </template>

    <hr style="margin:1.5rem 0;border:none;border-top:1px solid var(--border)">

    <form method="post" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="action" value="ablehnen">
        <?= csrfFeld() ?>
        <input type="hidden" name="einreichung_id" value="<?= $detail['id'] ?>">
        <div class="form-group" style="flex:1;min-width:260px">
            <label>Ablehnungsgrund <span style="font-weight:400;color:var(--muted)">(sieht der Hausmeister)</span></label>
            <input type="text" name="ablehnungsgrund" placeholder="z. B. Rechnung unleserlich, bitte neu fotografieren">
        </div>
        <button type="submit" class="btn btn-danger" onclick="return confirm('Einreichung wirklich ablehnen?')">Ablehnen</button>
    </form>
</div>

<script>
let posZaehler = 0;
const posZielBetrag = <?= (float)$detail['betrag_eingereicht'] ?>;

function positionHinzufuegen(vorbefuellterBetrag, vorbefuellterTyp) {
    const vorlage = document.getElementById('positionVorlage');
    const knoten = vorlage.content.cloneNode(true);
    const idx = posZaehler++;
    knoten.querySelectorAll('[name]').forEach(el => { el.name = el.name.replace('__IDX__', idx); });
    if (vorbefuellterBetrag !== undefined) knoten.querySelector('.pos-betrag').value = vorbefuellterBetrag;
    if (vorbefuellterTyp !== undefined) knoten.querySelector('.pos-typ').value = vorbefuellterTyp;
    document.getElementById('positionenListe').appendChild(knoten);
    const zeilen = document.querySelectorAll('#positionenListe .position-zeile');
    positionTypAendern(zeilen[zeilen.length - 1].querySelector('.pos-typ'));
    positionenSummeAktualisieren();
}

function positionTypAendern(select) {
    const zeile = select.closest('.position-zeile');
    const nichtUmlegbar = select.value === 'nicht_umlegbar';
    zeile.querySelector('.pos-kostenart-feld').style.display = nichtUmlegbar ? 'none' : '';
    zeile.querySelector('.pos-kategorie-feld').style.display = nichtUmlegbar ? '' : 'none';
    zeile.querySelector('.pos-kostenart').required = !nichtUmlegbar;
    zeile.querySelector('.pos-kategorie').required = nichtUmlegbar;
}

function positionEntfernen(btn) {
    const liste = document.getElementById('positionenListe');
    if (liste.children.length <= 1) { alert('Mindestens eine Position wird benötigt.'); return; }
    btn.closest('.position-zeile').remove();
    positionenSummeAktualisieren();
}

function positionenSummeAktualisieren() {
    let summe = 0;
    document.querySelectorAll('.pos-betrag').forEach(f => {
        const v = parseFloat((f.value || '0').replace(',', '.'));
        if (!isNaN(v)) summe += v;
    });
    const diff = Math.abs(summe - posZielBetrag);
    const farbe = diff < 0.01 ? 'var(--success)' : '#c07010';
    const anzeige = document.getElementById('positionenSumme');
    anzeige.innerHTML = 'Positionen ergeben: <strong style="color:' + farbe + '">' + summe.toFixed(2).replace('.', ',') + ' €</strong>'
        + ' von ' + posZielBetrag.toFixed(2).replace('.', ',') + ' € eingereicht'
        + (diff >= 0.01 ? ' <span style="color:#c07010">(Differenz: ' + diff.toFixed(2).replace('.', ',') + ' €)</span>' : ' ✓');
}

positionHinzufuegen(
    '<?= number_format($detail['betrag_eingereicht'], 2, ',', '') ?>',
    '<?= $detail['art'] === 'nicht_umlegbar' ? 'nicht_umlegbar' : 'umlegbar' ?>'
);
</script>
<?php endif; ?>

<!-- ═══════════ OFFENE EINREICHUNGEN ═══════════ -->
<div class="card">
    <h2>Offen zur Freigabe (<?= count($offene) ?>)</h2>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Eingereicht</th><th>Von</th><th>Rechnungsdatum</th><th class="text-right">Betrag</th><th>Vermerk</th><th>Art</th><th>Datei</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($offene as $e): ?>
        <tr>
            <td><?= date('d.m.Y', strtotime($e['eingereicht_am'])) ?></td>
            <td><?= htmlspecialchars($e['einreicher']) ?></td>
            <td><?= date('d.m.Y', strtotime($e['datum'])) ?></td>
            <td class="text-right"><?= number_format($e['betrag_eingereicht'], 2, ',', '.') ?> &euro;</td>
            <td><?= htmlspecialchars($e['vermerk']) ?></td>
            <td>
                <?php if ($e['art'] === 'umlegbar'): ?><span class="badge badge-info">umlegbar</span>
                <?php elseif ($e['art'] === 'nicht_umlegbar'): ?><span class="badge badge-warning">nicht umlegbar</span>
                <?php else: ?><span style="color:var(--muted)">unklar</span>
                <?php endif; ?>
            </td>
            <td><a href="einreichung_datei.php?id=<?= $e['id'] ?>" target="_blank" class="btn btn-sm" style="background:#e2e8f0">📄</a></td>
            <td><a href="freigabe.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-primary">Prüfen &amp; freigeben</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$offene): ?><tr><td colspan="8" class="text-center" style="color:var(--muted)">Nichts offen – alles erledigt 👍</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<!-- ═══════════ ZULETZT BEARBEITET ═══════════ -->
<div class="card">
    <h2>Zuletzt bearbeitet</h2>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Bearbeitet</th><th>Von</th><th class="text-right">Betrag (eingereicht)</th><th>Vermerk</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($erledigte as $e): ?>
        <tr>
            <td><?= $e['bearbeitet_am'] ? date('d.m.Y', strtotime($e['bearbeitet_am'])) : '–' ?></td>
            <td><?= htmlspecialchars($e['einreicher']) ?></td>
            <td class="text-right"><?= number_format($e['betrag_eingereicht'], 2, ',', '.') ?> &euro;</td>
            <td><?= htmlspecialchars($e['vermerk']) ?></td>
            <td>
                <?php if ($e['status'] === 'freigegeben'): ?>
                <span class="badge badge-info">Freigegeben</span>
                <form method="post" style="display:flex;gap:.3rem;margin-top:.4rem;flex-wrap:wrap" onsubmit="return confirm('Als überwiesen markieren?')">
                    <?= csrfFeld() ?>
                    <input type="hidden" name="action" value="ueberweisen">
                    <input type="hidden" name="einreichung_id" value="<?= $e['id'] ?>">
                    <input type="text" name="nachricht" placeholder="Nachricht (optional)"
                           style="min-width:140px;border:1px solid var(--border);border-radius:5px;padding:.3rem .5rem;font-size:.8rem;background:var(--input-bg);color:var(--text)">
                    <button type="submit" class="btn btn-sm btn-success">€ Überwiesen</button>
                </form>
                <?php elseif ($e['status'] === 'ueberwiesen'): ?>
                <span class="badge badge-success">Überwiesen</span>
                <?php else: ?>
                <span class="badge badge-danger" title="<?= htmlspecialchars($e['ablehnungsgrund']) ?>">Abgelehnt</span>
                <?php endif; ?>
                <?php if ($e['nachricht'] !== ''): ?>
                <div style="font-size:.78rem;color:var(--muted);margin-top:.25rem">💬 <?= htmlspecialchars($e['nachricht']) ?></div>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$erledigte): ?><tr><td colspan="5" class="text-center" style="color:var(--muted)">Noch keine bearbeiteten Einreichungen</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php include '../assets/footer.php'; ?>
