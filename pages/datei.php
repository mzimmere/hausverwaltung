<?php
/**
 * Geschützter Datei-Abruf für Dokumente und Rechnungsdateien.
 *   datei.php?typ=dokument&id=…   → Datei aus der Dokumentenverwaltung
 *   datei.php?typ=rechnung&id=…   → hochgeladene Rechnung
 * Zugriffsregeln:
 *   Admin/Leser:  alles
 *   Mieter:       nur Dokumente der eigenen Wohnung mit Freigabe 'mieter'
 *   Hausmeister:  nur Dokumente mit Freigabe 'hausmeister'
 *   Rechnungsdateien: ausschließlich Admin/Leser
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';

$user = aktuellerBenutzer();
$typ  = $_GET['typ'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

$datei = null;

if ($typ === 'dokument') {
    $stmt = $db->prepare("SELECT dateiname, wohnung_id, freigabe, objekt_id FROM dokumente WHERE id = ?");
    $stmt->execute([$id]);
    if ($row = $stmt->fetch()) {
        // Rollenprüfung – Admin sieht alles, alle anderen Rollen nur ihr eigenes Haus
        $erlaubt = true;
        if ($user['rolle'] === 'mieter') {
            $erlaubt = ($row['freigabe'] === 'mieter'
                && (int)$row['wohnung_id'] === (int)($user['wohnung_id'] ?? 0));
        } elseif ($user['rolle'] === 'hausmeister') {
            $erlaubt = ($row['freigabe'] === 'hausmeister' && (int)$row['objekt_id'] === aktivesObjekt());
        } elseif ($user['rolle'] === 'leser') {
            $erlaubt = ((int)$row['objekt_id'] === aktivesObjekt());
        }
        if (!$erlaubt) {
            http_response_code(403);
            exit('Kein Zugriff.');
        }
        $datei = UPLOAD_DOKUMENTE . str_replace(['..', '\\'], '', $row['dateiname']);
    }
} elseif ($typ === 'rechnung') {
    if (in_array($user['rolle'], ['mieter', 'hausmeister'], true)) {
        http_response_code(403);
        exit('Kein Zugriff.');
    }
    $stmt = $db->prepare("SELECT dateiname, jahr, objekt_id FROM rechnungen WHERE id = ?");
    $stmt->execute([$id]);
    if (($row = $stmt->fetch()) && $row['dateiname'] !== '') {
        if ($user['rolle'] === 'leser' && (int)$row['objekt_id'] !== aktivesObjekt()) {
            http_response_code(403);
            exit('Kein Zugriff.');
        }
        $datei = UPLOAD_RECHNUNGEN . (int)$row['jahr'] . '/' . str_replace(['..', '\\', '/'], '', $row['dateiname']);
    }
}

if (!$datei || !is_file($datei)) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

$ext  = strtolower(pathinfo($datei, PATHINFO_EXTENSION));
$mime = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'txt'  => 'text/plain',
][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($datei));
header('Content-Disposition: inline; filename="' . basename($datei) . '"');
header('X-Content-Type-Options: nosniff');
readfile($datei);
exit;
