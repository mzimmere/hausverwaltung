<?php
/**
 * Mieterwechsel – Verwaltung von Wohnungsübergaben während des Jahres.
 * Die taggenaue Abrechnung erfolgt automatisch in abrechnung.php über
 * den Wasserstand in der Tabelle "wasserablesungen".
 *
 * Zusätzlich: Übergabeprotokoll-Upload und rein dokumentarische
 * Zählerstände (Kaltwasser, Warmwasser, Strom) – diese drei fließen
 * NICHT in die Nebenkostenabrechnung ein, sondern dienen nur als
 * Nachweis/Dokumentation für den Übergabezeitpunkt.
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

$pageTitle = 'Mieterwechsel';
$basePath  = '../';

$objektId = aktivesObjekt();

// ── Speichern ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    leserSchreibschutz();
    csrfPruefen();

    if ($_POST['action'] === 'save') {
        $wohnungId    = (int)$_POST['wohnung_id'];
        $datum        = $_POST['uebergabe_datum'];
        $altName      = trim($_POST['mieter_alt_name']);
        $altPersonen  = (int)$_POST['mieter_alt_personen'];
        $neuName      = trim($_POST['mieter_neu_name']);
        $neuPersonen  = (int)$_POST['mieter_neu_personen'];
        $zaehler      = $_POST['zaehler_wasser'] !== '' ? str_replace(',','.',$_POST['zaehler_wasser']) : null;
        $notiz        = trim($_POST['notiz']);

        // Rein dokumentarische Zählerstände (fließen NICHT in die Abrechnung ein)
        $zKalt  = $_POST['zaehler_kaltwasser'] !== '' ? str_replace(',','.',$_POST['zaehler_kaltwasser']) : null;
        $zWarm  = $_POST['zaehler_warmwasser'] !== '' ? str_replace(',','.',$_POST['zaehler_warmwasser']) : null;
        $zStrom = $_POST['zaehler_strom']      !== '' ? str_replace(',','.',$_POST['zaehler_strom'])      : null;

        // Übergabeprotokoll-Upload
        $protokollDateiname = null;
        if (!empty($_FILES['protokoll']['name'])) {
            $jahr = (int)date('Y', strtotime($datum));
            $zielDir = UPLOAD_DIR . 'uebergabeprotokolle/' . $jahr . '/';
            if (!is_dir($zielDir)) mkdir($zielDir, 0777, true);
            $protokollDateiname = $jahr . '/' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['protokoll']['name']);
            move_uploaded_file($_FILES['protokoll']['tmp_name'], UPLOAD_DIR . 'uebergabeprotokolle/' . $protokollDateiname);
        }

        // Mieterwechsel speichern
        $stmt = $db->prepare("
            INSERT INTO mieterwechsel
                (wohnung_id, uebergabe_datum, mieter_alt_name, mieter_alt_personen,
                 mieter_neu_name, mieter_neu_personen, zaehler_wasser,
                 protokoll_dateiname, zaehler_kaltwasser, zaehler_warmwasser, zaehler_strom, notiz)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $wohnungId, $datum, $altName, $altPersonen, $neuName, $neuPersonen, $zaehler,
            $protokollDateiname, $zKalt, $zWarm, $zStrom, $notiz
        ]);

        // Wohnung aktualisieren: neuer Mieter
        $db->prepare("UPDATE wohnungen SET mieter_name=?, mieter_seit=?, personen=? WHERE id=?")
           ->execute([$neuName, $datum, $neuPersonen, $wohnungId]);

        // Zählerstand bei Übergabe als Zwischen-Ablesung speichern (Ende Alt + Anfang Neu)
        // Dies ist der EINZIGE Wert, der in die Abrechnung einfließt.
        if ($zaehler !== null) {
            $jahr = (int)date('Y', strtotime($datum));
            $db->prepare("INSERT INTO wasserablesungen (wohnung_id, datum, stand, typ, jahr) VALUES (?,?,?,'Uebergabe',?)")
               ->execute([$wohnungId, $datum, $zaehler, $jahr]);
        }

        protokolliere('mieterwechsel', 'anlegen', (int)$db->lastInsertId(), "Mieterwechsel angelegt, neuer Mieter: $neuName");
        $successMsg = "Mieterwechsel gespeichert. Neuer Mieter: $neuName. Die Abrechnung wird automatisch taggenau aufgeteilt.";
    }

    if ($_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare("
            DELETE FROM mieterwechsel
            WHERE id=? AND wohnung_id IN (SELECT id FROM wohnungen WHERE objekt_id=?)
        ")->execute([$id, $objektId]);
        protokolliere('mieterwechsel', 'loeschen', $id, 'Mieterwechsel gelöscht');
        $successMsg = 'Mieterwechsel gelöscht.';
    }
}

// ── Daten laden ──────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM wohnungen WHERE aktiv=1 AND objekt_id=? ORDER BY id");
$stmt->execute([$objektId]);
$wohnungen = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT m.*, w.bezeichnung AS wohnung, w.wohnflaeche
    FROM mieterwechsel m
    JOIN wohnungen w ON m.wohnung_id = w.id
    WHERE w.objekt_id = ?
    ORDER BY m.uebergabe_datum DESC
");
$stmt->execute([$objektId]);
$wechsel = $stmt->fetchAll();

include '../assets/header.php';
?>

<div class="page-header">
    <h1>Mieterwechsel</h1>
</div>

<div class="alert alert-info">
    <strong>So funktioniert die Abrechnung bei Mieterwechsel:</strong><br>
    Erfassen Sie hier Datum und Daten beider Mieter. Die Jahresabrechnung teilt alle Kosten
    dann <strong>automatisch taggenau</strong> auf: Mieter A erhält eine Abrechnung für seinen
    Zeitraum (01.01.–Übergabe), Mieter B für den Rest (Übergabe+1 bis 31.12.).
    Verbrauchskosten (Wasser) werden anhand des Übergabe-Zählerstands aufgeteilt.
</div>

<!-- Formular -->
<?php if (!istNurLesend()): ?>
<div class="card">
    <h2>Neuen Mieterwechsel erfassen</h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrfFeld() ?>
        <input type="hidden" name="action" value="save">

        <div class="form-group" style="margin-bottom:1rem">
            <label>Wohnung *</label>
            <select name="wohnung_id" required style="max-width:300px">
                <option value="">– bitte wählen –</option>
                <?php foreach ($wohnungen as $w): ?>
                <option value="<?= $w['id'] ?>">
                    <?= htmlspecialchars($w['bezeichnung']) ?> –
                    <?= htmlspecialchars($w['mieter_name'] ?? 'kein Mieter') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom:1.5rem">
            <label>Übergabedatum (letzter Tag des ausziehenden Mieters) *</label>
            <input type="date" name="uebergabe_datum" value="<?= date('Y-m-d') ?>" required style="max-width:220px">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem">

            <div style="background:#fff8f0;border:2px solid #e8a020;border-radius:8px;padding:1.25rem">
                <h3 style="color:#c07010;margin-bottom:1rem">📤 Ausziehender Mieter</h3>
                <div class="form-group">
                    <label>Name des alten Mieters *</label>
                    <input type="text" name="mieter_alt_name" placeholder="z.B. Familie Müller" required>
                </div>
                <div class="form-group">
                    <label>Personen</label>
                    <input type="number" name="mieter_alt_personen" value="2" min="1">
                </div>
            </div>

            <div style="background:#f0fff4;border:2px solid #27ae60;border-radius:8px;padding:1.25rem">
                <h3 style="color:#1a7a40;margin-bottom:1rem">📥 Einziehender Mieter</h3>
                <div class="form-group">
                    <label>Name des neuen Mieters *</label>
                    <input type="text" name="mieter_neu_name" placeholder="z.B. Familie Wagner" required>
                </div>
                <div class="form-group">
                    <label>Personen</label>
                    <input type="number" name="mieter_neu_personen" value="2" min="1">
                </div>
            </div>

        </div>

        <div style="margin-top:1.5rem;background:#f4f6f9;border-radius:8px;padding:1.25rem">
            <h3 style="margin-bottom:1rem;font-size:1rem">Übergabe-Zählerstand (für die Abrechnung)</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>Wasserstand bei Übergabe (m³)</label>
                    <input type="text" name="zaehler_wasser" placeholder="1234.567">
                    <small style="color:var(--muted)">Empfohlen: Ablesung am Übergabetag. Fließt in die Abrechnung ein.</small>
                </div>
                <div class="form-group">
                    <label>Notiz</label>
                    <input type="text" name="notiz" placeholder="z.B. Anmerkungen zur Übergabe">
                </div>
            </div>
        </div>

        <div style="margin-top:1.25rem;background:#f8fafc;border-radius:8px;padding:1.25rem">
            <h3 style="margin-bottom:.25rem;font-size:1rem">Weitere Zählerstände (nur Dokumentation)</h3>
            <p style="margin-bottom:1rem;color:var(--muted);font-size:.85rem">
                Diese Werte werden nur gespeichert und angezeigt – sie fließen
                <strong>nicht</strong> in die Nebenkostenabrechnung ein.
            </p>
            <div class="form-grid">
                <div class="form-group">
                    <label>Kaltwasser</label>
                    <input type="text" name="zaehler_kaltwasser" placeholder="z.B. 845.120">
                </div>
                <div class="form-group">
                    <label>Warmwasser</label>
                    <input type="text" name="zaehler_warmwasser" placeholder="z.B. 312.450">
                </div>
                <div class="form-group">
                    <label>Strom</label>
                    <input type="text" name="zaehler_strom" placeholder="z.B. 24580.5">
                </div>
            </div>
        </div>

        <div style="margin-top:1.25rem;background:#f8fafc;border-radius:8px;padding:1.25rem">
            <h3 style="margin-bottom:.5rem;font-size:1rem">Übergabeprotokoll</h3>
            <div class="form-group" style="max-width:400px">
                <label>Datei hochladen (PDF, JPG, PNG)</label>
                <input type="file" name="protokoll" accept=".pdf,.jpg,.jpeg,.png">
            </div>
        </div>

        <div style="margin-top:1.25rem">
            <button type="submit" class="btn btn-primary">Mieterwechsel speichern</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Liste -->
<div class="card">
    <h2>Erfasste Mieterwechsel</h2>
    <?php if ($wechsel): ?>
    <div class="table-wrap">
    <table class="sortable">
        <thead>
            <tr>
                <th>Wohnung</th>
                <th>Übergabe</th>
                <th>Alter Mieter</th>
                <th>Neuer Mieter</th>
                <th>Tage alt / neu</th>
                <th>Zähler (Abrechnung)</th>
                <th>Zähler (Doku)</th>
                <th>Protokoll</th>
                <th>Notiz</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($wechsel as $w):
            $datum     = new DateTime($w['uebergabe_datum']);
            $jahr      = (int)$datum->format('Y');
            $jahresAnf = new DateTime("$jahr-01-01");
            $jahresEnd = new DateTime("$jahr-12-31");

            $tageAlt = (int)$jahresAnf->diff($datum)->days + 1;
            $datumNeu = clone $datum;
            $datumNeu->modify('+1 day');
            $tageNeu  = (int)$datumNeu->diff($jahresEnd)->days + 1;
            $tageGes  = $jahr % 4 === 0 ? 366 : 365;
        ?>
        <tr>
            <td><?= htmlspecialchars($w['wohnung']) ?></td>
            <td><?= date('d.m.Y', strtotime($w['uebergabe_datum'])) ?></td>
            <td>
                <span class="badge badge-warning"><?= htmlspecialchars($w['mieter_alt_name']) ?></span><br>
                <small><?= $w['mieter_alt_personen'] ?> Pers.</small>
            </td>
            <td>
                <span class="badge badge-success"><?= htmlspecialchars($w['mieter_neu_name']) ?></span><br>
                <small><?= $w['mieter_neu_personen'] ?> Pers.</small>
            </td>
            <td class="text-center">
                <small>
                    <strong><?= $tageAlt ?></strong> / <strong><?= $tageNeu ?></strong> Tage<br>
                    <span style="color:var(--muted)">(<?= round($tageAlt/$tageGes*100,1) ?> % / <?= round($tageNeu/$tageGes*100,1) ?> %)</span>
                </small>
            </td>
            <td class="text-right">
                <?= $w['zaehler_wasser'] ? number_format($w['zaehler_wasser'],3,',','.').' m³' : '–' ?>
            </td>
            <td>
                <small style="color:var(--muted)">
                    <?php if ($w['zaehler_kaltwasser']): ?>KW: <?= number_format($w['zaehler_kaltwasser'],3,',','.') ?><br><?php endif; ?>
                    <?php if ($w['zaehler_warmwasser']): ?>WW: <?= number_format($w['zaehler_warmwasser'],3,',','.') ?><br><?php endif; ?>
                    <?php if ($w['zaehler_strom']): ?>Strom: <?= number_format($w['zaehler_strom'],3,',','.') ?><?php endif; ?>
                    <?php if (!$w['zaehler_kaltwasser'] && !$w['zaehler_warmwasser'] && !$w['zaehler_strom']): ?>–<?php endif; ?>
                </small>
            </td>
            <td>
                <?php if ($w['protokoll_dateiname']): ?>
                <a href="../uploads/uebergabeprotokolle/<?= $w['protokoll_dateiname'] ?>" target="_blank" class="btn btn-sm" style="background:#e2e8f0">📄 ansehen</a>
                <?php else: ?>
                <span style="color:var(--muted)">–</span>
                <?php endif; ?>
            </td>
            <td><small><?= htmlspecialchars($w['notiz'] ?? '') ?></small></td>
            <td>
                <?php if (!istNurLesend()): ?>
                <form method="post" style="display:inline">
                    <?= csrfFeld() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id"     value="<?= $w['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"
                        onclick="return confirm('Wechsel löschen? Die Wohnungsdaten bleiben erhalten.')">✕</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine Mieterwechsel erfasst.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Beispiel: So sieht die Abrechnung aus</h2>
    <p style="color:var(--muted);margin-bottom:1rem">
        Übergabe am <strong>30. Juni</strong> (Tag 181 von 365):
    </p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Mieter</th><th>Zeitraum</th><th>Anteil</th><th>Beispiel Grundsteuer (1.200 €)</th></tr></thead>
        <tbody>
        <tr>
            <td><span class="badge badge-warning">Familie Müller (alt)</span></td>
            <td>01.01. – 30.06. (181 Tage)</td>
            <td>181/365 = <strong>49,6 %</strong></td>
            <td><strong>595,07 €</strong></td>
        </tr>
        <tr>
            <td><span class="badge badge-success">Familie Wagner (neu)</span></td>
            <td>01.07. – 31.12. (184 Tage)</td>
            <td>184/365 = <strong>50,4 %</strong></td>
            <td><strong>604,93 €</strong></td>
        </tr>
        </tbody>
    </table>
    </div>
    <p style="margin-top:.75rem;color:var(--muted);font-size:.9rem">
        Bei Verbrauchskosten (Wasser) gilt der Zählerstand am Übergabetag – nicht der Zeitanteil.
        Jeder Mieter bekommt eine separate PDF-Abrechnung.
        Kaltwasser-, Warmwasser- und Stromzähler sowie das Übergabeprotokoll dienen nur der
        Dokumentation und haben keinen Einfluss auf die Berechnung.
    </p>
</div>

<?php include '../assets/footer.php'; ?>
