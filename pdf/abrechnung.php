<?php
/**
 * Nebenkostenabrechnung – Druckansicht / PDF
 * Funktioniert ohne DomPDF direkt als druckbare HTML-Seite.
 * Browser: Strg+P → "Als PDF speichern"
 */

require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';

// ── Rollenprüfung ────────────────────────────────────────────
// Hausmeister haben mit Abrechnungen nichts zu tun → kein Zugriff.
// Mieter dürfen ausschließlich Abrechnungen der eigenen Wohnung
// abrufen (Prüfung unten, sobald die Abrechnung geladen ist).
$pdfUser = aktuellerBenutzer();
if ($pdfUser['rolle'] === 'hausmeister') {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:2rem">Kein Zugriff.</p>');
}

$jahr       = (int)($_GET['jahr']        ?? date('Y') - 1);
$wohnungId  = (int)($_GET['wohnung']     ?? 0);
$abrId      = (int)($_GET['abrechnung']  ?? 0);

// ── Daten laden ──────────────────────────────────────────────
if ($abrId) {
    $abrechnung = $db->prepare("SELECT a.*, w.bezeichnung, w.mieter_name, w.wohnflaeche, w.etage
        FROM abrechnungen a JOIN wohnungen w ON a.wohnung_id = w.id WHERE a.id = ?");
    $abrechnung->execute([$abrId]);
} elseif ($wohnungId) {
    $abrechnung = $db->prepare("SELECT a.*, w.bezeichnung, w.mieter_name, w.wohnflaeche, w.etage
        FROM abrechnungen a JOIN wohnungen w ON a.wohnung_id = w.id
        WHERE a.wohnung_id = ? AND a.jahr = ? LIMIT 1");
    $abrechnung->execute([$wohnungId, $jahr]);
} else {
    die('<p style="font-family:sans-serif;padding:2rem">Fehler: Keine Abrechnung angegeben.</p>');
}

$abrechnung = $abrechnung->fetch();

if (!$abrechnung) {
    die('<p style="font-family:sans-serif;padding:2rem">Keine Abrechnung gefunden – bitte zuerst die Jahresabrechnung berechnen.</p>');
}

// Mieter: nur die eigene Wohnung – deckt beide Aufrufwege ab
// (?abrechnung=ID und ?wohnung=ID&jahr=JJJJ)
if ($pdfUser['rolle'] === 'mieter'
    && (int)$abrechnung['wohnung_id'] !== (int)($pdfUser['wohnung_id'] ?? 0)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:2rem">Kein Zugriff.</p>');
}

$positionen = $db->prepare("SELECT * FROM abrechnungspositionen WHERE abrechnung_id = ? ORDER BY id");
$positionen->execute([$abrechnung['id']]);
$positionen = $positionen->fetchAll();

$objekt = $db->query("SELECT * FROM objekt WHERE id = 1")->fetch();

// ── Zeitraum ermitteln ───────────────────────────────────────
$vonDatum = '01.01.' . $abrechnung['jahr'];
$bisDatum = '31.12.' . $abrechnung['jahr'];
if (!empty($positionen[0]['zeitraum_von'])) {
    $alleVon  = array_filter(array_column($positionen, 'zeitraum_von'));
    $alleBis  = array_filter(array_column($positionen, 'zeitraum_bis'));
    if ($alleVon) $vonDatum = date('d.m.Y', strtotime(min($alleVon)));
    if ($alleBis) $bisDatum = date('d.m.Y', strtotime(max($alleBis)));
}

// Mieter aus Positionen (bei Mieterwechsel steht er dort)
$mieterName = $positionen[0]['mieter_name'] ?? $abrechnung['mieter_name'] ?? '';

// ── Positionen HTML ──────────────────────────────────────────
$posHtml = '';
foreach ($positionen as $p) {
    $zeitraum = '';
    if (!empty($p['zeitraum_von'])) {
        $zeitraum = '<span class="muted"> (' . date('d.m.', strtotime($p['zeitraum_von']))
                  . '–' . date('d.m.Y', strtotime($p['zeitraum_bis'])) . ')</span>';
    }
    $posHtml .= '<tr>
        <td>' . htmlspecialchars($p['kostenart']) . $zeitraum . '</td>
        <td class="right">' . number_format($p['betrag'], 2, ',', '.') . ' &euro;</td>
    </tr>';
}

$saldoBetrag = abs($abrechnung['saldo']);
$istNachzahlung = $abrechnung['saldo'] > 0;
$saldoLabel  = $istNachzahlung ? 'Nachzahlung' : 'Guthaben';
$saldoFarbe  = $istNachzahlung ? '#c0392b' : '#27ae60';

$verwalterAdresse = trim(
    ($objekt['verwalter_strasse'] ?? '') . ', ' .
    ($objekt['verwalter_plz']     ?? '') . ' ' .
    ($objekt['verwalter_ort']     ?? ''),
    ', '
);

?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Nebenkostenabrechnung <?= $abrechnung['jahr'] ?> – <?= htmlspecialchars($abrechnung['bezeichnung']) ?></title>
<style>
/* ── Basis ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 13px;
    color: #1a1a1a;
    background: #f0f2f5;
    padding: 2rem;
}

/* ── Druckschaltflächen ── */
.screen-bar {
    max-width: 740px;
    margin: 0 auto 1.5rem;
    display: flex;
    gap: .75rem;
    align-items: center;
}
.btn {
    display: inline-block;
    padding: .55rem 1.2rem;
    border-radius: 6px;
    font-size: .9rem;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    border: none;
}
.btn-print  { background: #2c5f8a; color: #fff; }
.btn-back   { background: #e2e8f0; color: #333; }
.hint { font-size: .82rem; color: #666; margin-left: auto; }

/* ── Dokument-Karte ── */
.dokument {
    max-width: 740px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid #d0d7de;
    border-radius: 4px;
    padding: 48px 52px;
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
}

/* ── Absender / Kopf ── */
.absender { font-size: 11px; color: #555; margin-bottom: 24px; line-height: 1.6; }
.absender strong { font-size: 13px; color: #1a1a1a; }

.trennlinie { border: none; border-top: 2px solid #2c5f8a; margin: 0 0 20px; }

/* ── Empfänger-Block ── */
.empfaenger { margin-bottom: 28px; }
.empfaenger-label { font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 4px; }
.empfaenger-name  { font-size: 15px; font-weight: 700; }
.empfaenger-info  { font-size: 12px; color: #444; margin-top: 2px; }

/* ── Betreff ── */
.betreff {
    font-size: 16px;
    font-weight: 700;
    color: #2c5f8a;
    margin-bottom: 6px;
}
.betreff-sub { font-size: 12px; color: #555; margin-bottom: 24px; }

/* ── Tabelle ── */
table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }
thead th {
    background: #2c5f8a;
    color: #fff;
    padding: 7px 10px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
}
thead th.right { text-align: right; }
tbody td { padding: 6px 10px; border-bottom: 1px solid #eee; font-size: 12.5px; vertical-align: top; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: #f8fafc; }
.right { text-align: right; white-space: nowrap; }
.muted { color: #888; font-size: 11px; }

/* ── Zusammenfassung ── */
.zusammenfassung {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1.5rem;
}
.zusammenfassung td {
    padding: 7px 10px;
    font-size: 13px;
    border-bottom: 1px solid #eee;
}
.zusammenfassung tr:last-child td {
    border-bottom: none;
    border-top: 2px solid #2c5f8a;
    font-size: 15px;
    font-weight: 700;
    padding-top: 10px;
}

/* ── Saldo-Box ── */
.saldo-box {
    border: 2px solid;
    border-radius: 6px;
    padding: 14px 18px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.saldo-label { font-size: 14px; font-weight: 700; }
.saldo-betrag { font-size: 22px; font-weight: 700; }

/* ── Fußzeile ── */
.fusszeile {
    margin-top: 2rem;
    padding-top: 12px;
    border-top: 1px solid #ddd;
    font-size: 10.5px;
    color: #888;
    line-height: 1.7;
}

/* ── Drucken ── */
@media print {
    body { background: #fff; padding: 0; }
    .screen-bar { display: none; }
    .dokument {
        max-width: 100%;
        border: none;
        box-shadow: none;
        padding: 1cm 1.5cm;
    }
    tbody tr:hover td { background: none; }
}
</style>
</head>
<body>

<!-- Schaltflächen (nur am Bildschirm) -->
<div class="screen-bar">
    <button class="btn btn-print" onclick="window.print()">🖨️ Drucken / Als PDF speichern</button>
    <?php if ($pdfUser['rolle'] === 'mieter'): ?>
    <a href="../pages/meine_wohnung.php" class="btn btn-back">← Zurück</a>
    <?php else: ?>
    <a href="../pages/abrechnung.php?jahr=<?= $abrechnung['jahr'] ?>" class="btn btn-back">← Zurück</a>
    <?php endif; ?>
    <span class="hint">Tipp: Im Druckdialog „Als PDF speichern" wählen</span>
</div>

<!-- Dokument -->
<div class="dokument">

    <!-- Absender -->
    <div class="absender">
        <strong><?= htmlspecialchars($objekt['verwalter_name'] ?? 'Hausverwaltung') ?></strong><br>
        <?php if ($verwalterAdresse): ?>
        <?= htmlspecialchars($verwalterAdresse) ?><br>
        <?php endif; ?>
        <?php if (!empty($objekt['verwalter_telefon'])): ?>
        Tel.: <?= htmlspecialchars($objekt['verwalter_telefon']) ?><?php if (!empty($objekt['verwalter_email'])): ?> &nbsp;|&nbsp; <?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($objekt['verwalter_email'])): ?>
        E-Mail: <?= htmlspecialchars($objekt['verwalter_email']) ?>
        <?php endif; ?>
    </div>

    <hr class="trennlinie">

    <!-- Empfänger -->
    <div class="empfaenger">
        <div class="empfaenger-label">Mieter</div>
        <div class="empfaenger-name"><?= htmlspecialchars($mieterName ?: $abrechnung['mieter_name']) ?></div>
        <div class="empfaenger-info">
            Wohnung <?= htmlspecialchars($abrechnung['bezeichnung']) ?>
            <?php if (!empty($abrechnung['etage'])): ?> · <?= htmlspecialchars($abrechnung['etage']) ?><?php endif; ?>
            · <?= number_format($abrechnung['wohnflaeche'], 2, ',', '.') ?> m²
        </div>
    </div>

    <!-- Betreff -->
    <div class="betreff">Nebenkostenabrechnung <?= $abrechnung['jahr'] ?></div>
    <div class="betreff-sub">
        Abrechnungszeitraum: <?= $vonDatum ?> – <?= $bisDatum ?> &nbsp;|&nbsp;
        Objekt: <?= htmlspecialchars($objekt['name'] ?? '') ?>
    </div>

    <!-- Kostenpositionen -->
    <table>
        <thead>
            <tr>
                <th>Kostenart</th>
                <th class="right">Ihr Anteil</th>
            </tr>
        </thead>
        <tbody>
            <?= $posHtml ?>
        </tbody>
    </table>

    <!-- Zusammenfassung -->
    <table class="zusammenfassung">
        <tr>
            <td>Gesamtkosten Ihrer Wohnung</td>
            <td class="right"><?= number_format($abrechnung['gesamtkosten'], 2, ',', '.') ?> &euro;</td>
        </tr>
        <tr>
            <td>Geleistete Vorauszahlungen</td>
            <td class="right"><?= number_format($abrechnung['vorauszahlungen'], 2, ',', '.') ?> &euro;</td>
        </tr>
        <tr>
            <td style="color:<?= $saldoFarbe ?>"><?= $saldoLabel ?></td>
            <td class="right" style="color:<?= $saldoFarbe ?>">
                <?= number_format($saldoBetrag, 2, ',', '.') ?> &euro;
            </td>
        </tr>
    </table>

    <!-- Saldo-Box -->
    <div class="saldo-box" style="border-color:<?= $saldoFarbe ?>;background:<?= $istNachzahlung ? '#fef2f2' : '#f0fff4' ?>">
        <div>
            <div class="saldo-label" style="color:<?= $saldoFarbe ?>"><?= $saldoLabel ?></div>
            <div style="font-size:11px;color:#666;margin-top:2px">
                <?= $istNachzahlung
                    ? 'Bitte überweisen Sie den Betrag innerhalb von 30 Tagen.'
                    : 'Der Betrag wird mit der nächsten Vorauszahlung verrechnet.' ?>
            </div>
        </div>
        <div class="saldo-betrag" style="color:<?= $saldoFarbe ?>">
            <?= number_format($saldoBetrag, 2, ',', '.') ?> &euro;
        </div>
    </div>

    <!-- Fußzeile -->
    <div class="fusszeile">
        Diese Nebenkostenabrechnung wurde automatisch durch die Hausverwaltungssoftware erstellt
        und ist gemäß § 556 BGB innerhalb von 12 Monaten nach Ende des Abrechnungszeitraums zu erstellen.<br>
        Objekt: <?= htmlspecialchars($objekt['name'] ?? '') ?> &nbsp;|&nbsp;
        Erstellt am: <?= date('d.m.Y') ?> &nbsp;|&nbsp;
        <?= htmlspecialchars($objekt['verwalter_name'] ?? '') ?>
    </div>

</div><!-- .dokument -->

</body>
</html>
