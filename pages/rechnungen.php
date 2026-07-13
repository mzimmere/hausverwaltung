<?php
/**
 * Rechnungen erfassen.
 * Drei Modi für die Kostenzuordnung:
 * 1. Umlage (Standard)     – wird nach Umlageschlüssel der Kostenart auf alle Wohnungen verteilt
 * 2. Eine Wohnung          – komplett einer einzigen Wohnung zugeordnet (z.B. eigener Grundsteuerbescheid)
 * 3. Mehrere Wohnungen     – frei wählbare Gruppe von Wohnungen mit individuellen Anteilen
 *    (z.B. Hausmeister nur für EG+OG, DG hat eigene Vereinbarung)
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

$pageTitle = 'Rechnungen';
$basePath  = '../';

$objektId = aktivesObjekt();

// ── Rechnung speichern ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    leserSchreibschutz();
    csrfPruefen();
    $kostenartId  = (int)$_POST['kostenart_id'];
    $zuordnung    = $_POST['zuordnung'] ?? 'umlage'; // umlage | einzel | gruppe
    $wohnungId    = ($zuordnung === 'einzel' && $_POST['wohnung_id'] !== '') ? (int)$_POST['wohnung_id'] : null;
    $datum        = $_POST['datum'];
    $betrag       = str_replace(',', '.', $_POST['betrag']);
    $jahr         = (int)$_POST['jahr'];
    $beschreibung = trim($_POST['beschreibung']);
    $dateiname    = '';

    if (!empty($_FILES['rechnung']['name'])) {
        $ziel_dir = UPLOAD_RECHNUNGEN . $jahr . '/';
        if (!is_dir($ziel_dir)) mkdir($ziel_dir, 0777, true);
        $dateiname = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['rechnung']['name']);
        move_uploaded_file($_FILES['rechnung']['tmp_name'], $ziel_dir . $dateiname);
    }

    $stmt = $db->prepare("INSERT INTO rechnungen (objekt_id, kostenart_id, wohnung_id, datum, betrag, jahr, beschreibung, dateiname) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$objektId, $kostenartId, $wohnungId, $datum, $betrag, $jahr, $beschreibung, $dateiname]);
    $rechnungId = $db->lastInsertId();
    protokolliere('rechnungen', 'anlegen', (int)$rechnungId, 'Rechnung über ' . number_format($betrag, 2, ',', '.') . ' €');

    if ($zuordnung === 'gruppe') {
        // Gruppen-Zuordnung: mehrere Wohnungen
        $verteilModus = $_POST['verteil_modus'] ?? 'prozent'; // prozent | volle_summe
        $gewaehlte    = $_POST['gruppe_wohnung'] ?? [];        // Array von wohnung_id

        $insRW = $db->prepare("INSERT INTO rechnung_wohnungen (rechnung_id, wohnung_id, anteil) VALUES (?,?,?)");

        if ($verteilModus === 'volle_summe') {
            // Jede ausgewählte Wohnung bekommt den VOLLEN Betrag (anteil = 1.0 je Wohnung)
            $angehakt = array_filter($gewaehlte, fn($w) => (int)$w > 0);
            foreach ($angehakt as $wId) {
                $insRW->execute([$rechnungId, (int)$wId, 1.0]);
            }
            $successMsg = 'Rechnung gespeichert – jede der ' . count($angehakt) . ' ausgewählten Wohnungen erhält den vollen Betrag von '
                . number_format($betrag, 2, ',', '.') . ' €.';
        } else {
            // Prozentual aufgeteilt (Summe muss 100 % ergeben)
            $anteile = $_POST['gruppe_anteil'] ?? []; // Array von Prozentwerten (Index passend zu gewaehlte)
            $summeAnteile = 0;
            foreach ($gewaehlte as $idx => $wId) {
                $wId = (int)$wId;
                $proz = isset($anteile[$idx]) ? (float)str_replace(',', '.', $anteile[$idx]) : 0;
                if ($wId && $proz > 0) {
                    $insRW->execute([$rechnungId, $wId, $proz / 100]);
                    $summeAnteile += $proz;
                }
            }
            if (round($summeAnteile, 2) != 100.0) {
                $errorMsg = "Achtung: Die Anteile ergeben " . number_format($summeAnteile, 1, ',', '.') . " % statt 100 %. Bitte die Rechnung korrigieren oder löschen.";
            } else {
                $successMsg = 'Rechnung gespeichert – aufgeteilt auf ' . count($gewaehlte) . ' ausgewählte Wohnungen.';
            }
        }
    } else {
        $successMsg = $wohnungId
            ? 'Rechnung gespeichert – wird direkt dieser Wohnung zugeordnet (keine Umlage).'
            : 'Rechnung gespeichert.';
    }

    // ── Zusätzliche Positionen (optional): z.B. ein Teil der Rechnung ist
    // nicht umlegbar. Läuft unabhängig von der Zuordnung oben, betrifft
    // nur zusätzliche Beträge, nicht die soeben gespeicherte Hauptrechnung.
    $zusatzPositionen = [];
    foreach (($_POST['pos'] ?? []) as $p) {
        $typ = ($p['typ'] ?? '') === 'nicht_umlegbar' ? 'nicht_umlegbar' : 'umlegbar';
        $posBetrag = (float)str_replace(',', '.', $p['betrag'] ?? '0');
        if ($posBetrag <= 0) continue;
        if ($typ === 'umlegbar') {
            $posKostenartId = (int)($p['kostenart_id'] ?? 0);
            if (!$posKostenartId) continue;
            $zusatzPositionen[] = ['typ' => 'umlegbar', 'betrag' => $posBetrag, 'kostenart_id' => $posKostenartId];
        } else {
            $posKategorieId = (int)($p['kategorie_id'] ?? 0);
            if (!$posKategorieId) continue;
            $zusatzPositionen[] = ['typ' => 'nicht_umlegbar', 'betrag' => $posBetrag, 'kategorie_id' => $posKategorieId];
        }
    }
    if ($zusatzPositionen) {
        foreach ($zusatzPositionen as $p) {
            if ($p['typ'] === 'umlegbar') {
                // Teilt sich denselben Beleg wie die Hauptrechnung (falls vorhanden) – unproblematisch.
                $stmt = $db->prepare("INSERT INTO rechnungen (objekt_id, kostenart_id, wohnung_id, datum, betrag, jahr, beschreibung, dateiname) VALUES (?,?,NULL,?,?,?,?,?)");
                $stmt->execute([$objektId, $p['kostenart_id'], $datum, $p['betrag'], $jahr, $beschreibung . ' (zusätzliche Position)', $dateiname]);
                $zusatzId = (int)$db->lastInsertId();
                protokolliere('rechnungen', 'anlegen', $zusatzId, 'Zusätzliche Position über ' . number_format($p['betrag'], 2, ',', '.') . ' € (umlegbar)');
            } else {
                $posDateiname = '';
                if ($dateiname !== '') {
                    $zielDirEk = UPLOAD_DIR . 'eigentuemerkosten/' . $jahr . '/';
                    if (!is_dir($zielDirEk)) mkdir($zielDirEk, 0777, true);
                    $posDateiname = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $dateiname;
                    copy($ziel_dir . $dateiname, $zielDirEk . $posDateiname);
                }
                $stmt = $db->prepare("INSERT INTO eigentuemerkosten (objekt_id, kategorie_id, datum, betrag, jahr, beschreibung, dateiname) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$objektId, $p['kategorie_id'], $datum, $p['betrag'], $jahr, $beschreibung . ' (zusätzliche Position)', $posDateiname]);
                $zusatzId = (int)$db->lastInsertId();
                protokolliere('eigentuemerkosten', 'anlegen', $zusatzId, 'Zusätzliche Position über ' . number_format($p['betrag'], 2, ',', '.') . ' € (nicht umlegbar)');
            }
        }
        $successMsg .= ' Zusätzlich ' . count($zusatzPositionen) . ' weitere Position(en) angelegt.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    leserSchreibschutz();
    csrfPruefen();
    $delId = (int)$_POST['delete_id'];
    $db->prepare("DELETE FROM rechnungen WHERE id=? AND objekt_id=?")->execute([$delId, $objektId]); // rechnung_wohnungen löscht sich per CASCADE mit
    protokolliere('rechnungen', 'loeschen', $delId, 'Rechnung gelöscht');
    header('Location: rechnungen.php?jahr=' . (int)($_POST['jahr'] ?? date('Y'))); exit;
}

$filterJahr  = (int)($_GET['jahr'] ?? date('Y'));
$kostenarten = $db->query("SELECT * FROM kostenarten WHERE aktiv=1 ORDER BY bezeichnung")->fetchAll();
$kategorien  = $db->query("SELECT * FROM eigentuemerkosten_kategorien ORDER BY bezeichnung")->fetchAll();
$wStmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=? ORDER BY id");
$wStmt->execute([$objektId]);
$wohnungen = $wStmt->fetchAll();

$rechnungen = $db->prepare("
    SELECT r.*, k.bezeichnung AS kostenart, w.bezeichnung AS wohnung
    FROM rechnungen r
    JOIN kostenarten k ON r.kostenart_id = k.id
    LEFT JOIN wohnungen w ON r.wohnung_id = w.id
    WHERE r.jahr = ? AND r.objekt_id = ? ORDER BY r.datum DESC
");
$rechnungen->execute([$filterJahr, $objektId]);
$rechnungen = $rechnungen->fetchAll();
$summe = array_sum(array_column($rechnungen, 'betrag'));

// Gruppen-Zuordnungen je Rechnung vorladen (für die Anzeige)
$gruppenJeRechnung = [];
if ($rechnungen) {
    $ids = array_column($rechnungen, 'id');
    $platzhalter = implode(',', array_fill(0, count($ids), '?'));
    $gStmt = $db->prepare("
        SELECT rw.rechnung_id, rw.anteil, w.bezeichnung AS wohnung
        FROM rechnung_wohnungen rw JOIN wohnungen w ON rw.wohnung_id = w.id
        WHERE rw.rechnung_id IN ($platzhalter)
    ");
    $gStmt->execute($ids);
    foreach ($gStmt->fetchAll() as $g) {
        $gruppenJeRechnung[$g['rechnung_id']][] = $g;
    }
}

include '../assets/header.php';
?>
<div class="page-header">
    <h1>Rechnungen</h1>
    <div>
        <?php foreach ([date('Y')-1, date('Y'), date('Y')+1] as $j): ?>
        <a href="?jahr=<?= $j ?>" class="btn btn-sm <?= $j==$filterJahr ? 'btn-primary' : '' ?>" style="<?= $j!=$filterJahr ? 'background:#e2e8f0;color:#333' : '' ?>"><?= $j ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!istNurLesend()): ?>
<div class="card">
    <h2>Neue Rechnung erfassen</h2>
    <form method="post" enctype="multipart/form-data" id="rechnungForm">
        <input type="hidden" name="action" value="save">
        <?= csrfFeld() ?>
        <div class="form-grid">
            <div class="form-group">
                <label>Kostenart *</label>
                <select name="kostenart_id" required>
                    <option value="">-- bitte w&auml;hlen --</option>
                    <?php foreach ($kostenarten as $k): ?>
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
                <input type="text" name="betrag" placeholder="1234.56" required>
            </div>
            <div class="form-group">
                <label>Jahr *</label>
                <input type="number" name="jahr" value="<?= date('Y') ?>" required>
            </div>
            <div class="form-group">
                <label>Beschreibung</label>
                <input type="text" name="beschreibung" placeholder="Optionale Notiz">
            </div>
            <div class="form-group">
                <label>Rechnung hochladen (PDF/Bild)</label>
                <input type="file" name="rechnung" accept=".pdf,.jpg,.jpeg,.png">
            </div>
        </div>

        <!-- ── Zuordnung wählen ── -->
        <div style="margin-top:1.25rem;padding:1rem;background:#fff8f0;border:1px solid #e8a020;border-radius:8px">
            <label style="color:#c07010;display:block;margin-bottom:.6rem">Wie soll diese Rechnung zugeordnet werden?</label>

            <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:1rem">
                <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;cursor:pointer">
                    <input type="radio" name="zuordnung" value="umlage" checked onchange="zuordnungAendern()"> Umlage (Standard, nach Umlageschlüssel)
                </label>
                <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;cursor:pointer">
                    <input type="radio" name="zuordnung" value="einzel" onchange="zuordnungAendern()"> Nur eine Wohnung
                </label>
                <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;cursor:pointer">
                    <input type="radio" name="zuordnung" value="gruppe" onchange="zuordnungAendern()"> Mehrere ausgewählte Wohnungen
                </label>
            </div>

            <!-- Modus: Eine Wohnung -->
            <div id="modus-einzel" style="display:none">
                <div class="form-group" style="max-width:340px">
                    <label>Welche Wohnung?</label>
                    <select name="wohnung_id">
                        <option value="">-- bitte wählen --</option>
                        <?php foreach ($wohnungen as $w): ?>
                        <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['bezeichnung']) ?> – <?= htmlspecialchars($w['mieter_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p style="margin-top:.5rem;color:#856404;font-size:.85rem">
                    Beispiel: eigener Grundsteuerbescheid pro Wohnung. Die Kosten werden
                    <strong>ausschließlich dieser Wohnung</strong> berechnet.
                </p>
            </div>

            <!-- Modus: Mehrere Wohnungen -->
            <div id="modus-gruppe" style="display:none">
                <p style="margin-bottom:.6rem;color:#856404;font-size:.85rem">
                    Beispiel: Hausmeister/Treppenhausreinigung nur für EG und OG, nicht DG.
                </p>

                <div style="display:flex;gap:1.5rem;margin-bottom:1rem;flex-wrap:wrap">
                    <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;cursor:pointer">
                        <input type="radio" name="verteil_modus" value="prozent" checked onchange="verteilModusAendern()">
                        Betrag aufteilen (Anteile in % geben zusammen 100 %)
                    </label>
                    <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;cursor:pointer">
                        <input type="radio" name="verteil_modus" value="volle_summe" onchange="verteilModusAendern()">
                        Jede Wohnung bekommt den vollen Betrag
                    </label>
                </div>

                <p id="hinweis-volle-summe" style="display:none;margin-bottom:.6rem;color:#856404;font-size:.85rem;background:#fff3cd;padding:.5rem .75rem;border-radius:6px">
                    Beispiel: 600 € eingetragen, EG + OG angekreuzt → EG bekommt 600 €
                    <strong>und</strong> OG bekommt ebenfalls 600 € (insgesamt 1.200 € Kosten im Haus).
                </p>

                <table style="width:100%;max-width:480px">
                    <?php foreach ($wohnungen as $w): ?>
                    <tr>
                        <td style="padding:.3rem 0">
                            <label style="display:flex;align-items:center;gap:.5rem;font-weight:400;cursor:pointer">
                                <input type="checkbox" name="gruppe_wohnung[]" value="<?= $w['id'] ?>" class="gruppe-checkbox">
                                <?= htmlspecialchars($w['bezeichnung']) ?> – <?= htmlspecialchars($w['mieter_name']) ?>
                            </label>
                        </td>
                        <td class="gruppe-anteil-spalte" style="padding:.3rem 0;width:110px">
                            <input type="text" name="gruppe_anteil[]" placeholder="%" style="width:80px;padding:.4rem">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <button type="button" id="btn-gleich-verteilen" class="btn btn-sm" style="background:#e2e8f0;margin-top:.5rem" onclick="gleichVerteilen()">Gleich verteilen (auf angehakte Wohnungen)</button>
            </div>
        </div>

        <!-- ── Zusätzliche Positionen (optional): z.B. ein Teil ist nicht umlegbar ── -->
        <div style="margin-top:1.25rem;padding:1rem;background:var(--tile-neutral);border:1px solid var(--border);border-radius:8px">
            <label style="display:block;margin-bottom:.4rem;font-weight:600">
                Betrifft diese Rechnung teilweise etwas anderes? <span style="font-weight:400;color:var(--muted)">(optional – z.B. ein nicht umlegbarer Anteil)</span>
            </label>
            <p style="margin-bottom:.6rem;color:var(--muted);font-size:.85rem">
                Die Rechnung oben bleibt unverändert. Hier können zusätzliche Beträge separat als
                weitere umlegbare Rechnung (eigene Kostenart) oder als nicht umlegbare Eigentümerkosten
                (eigene Kategorie) angelegt werden – z.B. wenn eine Rechnung teils Betriebskosten,
                teils eine größere Reparatur enthält.
            </p>

            <div id="positionenListe"></div>
            <button type="button" class="btn btn-sm" style="background:#e2e8f0" onclick="positionHinzufuegen()">+ Zusätzliche Position</button>
        </div>

        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary">Rechnung speichern</button>
        </div>
    </form>

    <template id="positionVorlage">
        <div class="position-zeile" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;padding:.75rem;background:var(--card-bg);border:1px solid var(--border);border-radius:6px;margin-bottom:.6rem;margin-top:.6rem">
            <div class="form-group" style="margin:0;width:120px">
                <label style="font-size:.78rem">Betrag (€) *</label>
                <input type="text" class="pos-betrag" name="pos[__IDX__][betrag]" required>
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
                <select class="pos-kostenart" name="pos[__IDX__][kostenart_id]" required>
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
            <button type="button" class="btn btn-sm btn-danger" style="align-self:center" onclick="this.closest('.position-zeile').remove()" title="Position entfernen">✕</button>
        </div>
    </template>
</div>
<?php endif; ?>

<script>
function zuordnungAendern() {
    const modus = document.querySelector('input[name="zuordnung"]:checked').value;
    document.getElementById('modus-einzel').style.display = (modus === 'einzel') ? 'block' : 'none';
    document.getElementById('modus-gruppe').style.display = (modus === 'gruppe') ? 'block' : 'none';
}
function verteilModusAendern() {
    const modus = document.querySelector('input[name="verteil_modus"]:checked').value;
    const volleSumme = (modus === 'volle_summe');
    document.getElementById('hinweis-volle-summe').style.display = volleSumme ? 'block' : 'none';
    document.querySelectorAll('.gruppe-anteil-spalte').forEach(td => td.style.display = volleSumme ? 'none' : '');
    document.getElementById('btn-gleich-verteilen').style.display = volleSumme ? 'none' : '';
}
function gleichVerteilen() {
    const checkboxen = document.querySelectorAll('.gruppe-checkbox:checked');
    if (checkboxen.length === 0) { alert('Bitte zuerst Wohnungen ankreuzen.'); return; }
    const anteil = (100 / checkboxen.length).toFixed(2);
    document.querySelectorAll('.gruppe-checkbox').forEach(cb => {
        const anteilFeld = cb.closest('tr').querySelector('input[name="gruppe_anteil[]"]');
        anteilFeld.value = cb.checked ? anteil : '';
    });
}

let posZaehler = 0;
function positionHinzufuegen() {
    const vorlage = document.getElementById('positionVorlage');
    const knoten = vorlage.content.cloneNode(true);
    const idx = posZaehler++;
    knoten.querySelectorAll('[name]').forEach(el => { el.name = el.name.replace('__IDX__', idx); });
    document.getElementById('positionenListe').appendChild(knoten);
}
function positionTypAendern(select) {
    const zeile = select.closest('.position-zeile');
    const nichtUmlegbar = select.value === 'nicht_umlegbar';
    zeile.querySelector('.pos-kostenart-feld').style.display = nichtUmlegbar ? 'none' : '';
    zeile.querySelector('.pos-kategorie-feld').style.display = nichtUmlegbar ? '' : 'none';
    zeile.querySelector('.pos-kostenart').required = !nichtUmlegbar;
    zeile.querySelector('.pos-kategorie').required = nichtUmlegbar;
}
</script>

<div class="card">
    <h2>Rechnungen <?= $filterJahr ?> – Gesamt: <?= number_format($summe,2,',','.') ?> &euro;</h2>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Datum</th><th>Kostenart</th><th>Zuordnung</th><th>Beschreibung</th><th class="text-right">Betrag</th><th>Datei</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rechnungen as $r): ?>
        <tr>
            <td><?= date('d.m.Y', strtotime($r['datum'])) ?></td>
            <td><?= htmlspecialchars($r['kostenart']) ?></td>
            <td>
                <?php if (!empty($gruppenJeRechnung[$r['id']])): ?>
                <span class="badge badge-warning" title="<?php
                    $teile = [];
                    foreach ($gruppenJeRechnung[$r['id']] as $g) {
                        $anteilText = ((float)$g['anteil'] >= 1.0) ? 'voller Betrag' : round($g['anteil']*100,1) . '%';
                        $teile[] = htmlspecialchars($g['wohnung']) . ' (' . $anteilText . ')';
                    }
                    echo implode(', ', $teile);
                ?>">Gruppe: <?= count($gruppenJeRechnung[$r['id']]) ?> Wohnungen</span>
                <?php elseif ($r['wohnung']): ?>
                <span class="badge badge-warning">nur <?= htmlspecialchars($r['wohnung']) ?></span>
                <?php else: ?>
                <span class="badge badge-info">umgelegt</span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($r['beschreibung']) ?></td>
            <td class="text-right"><?= number_format($r['betrag'],2,',','.') ?> &euro;</td>
            <td><?php if ($r['dateiname']): ?><a href="datei.php?typ=rechnung&id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm" style="background:#e2e8f0">📄</a><?php endif; ?></td>
            <td><?php if (!istNurLesend()): ?><form method="post" style="display:inline" onsubmit="return confirm('L&ouml;schen?')"><?= csrfFeld() ?><input type="hidden" name="delete_id" value="<?= $r['id'] ?>"><input type="hidden" name="jahr" value="<?= $filterJahr ?>"><button type="submit" class="btn btn-sm btn-danger">✕</button></form><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rechnungen): ?><tr><td colspan="7" class="text-center" style="color:var(--muted)">Keine Rechnungen</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php include '../assets/footer.php'; ?>
