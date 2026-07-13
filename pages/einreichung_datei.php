<?php
/**
 * Liefert die Datei einer Einreichung aus – nur mit Login.
 * Sehen darf sie: der Admin oder der Hausmeister, der sie
 * selbst eingereicht hat.
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';

$user = aktuellerBenutzer();

$stmt = $db->prepare("SELECT * FROM einreichungen WHERE id = ?");
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$e = $stmt->fetch();

if (!$e || (!istAdmin() && (int)$e['benutzer_id'] !== (int)$user['id'])) {
    http_response_code(403);
    exit('Kein Zugriff.');
}

// Pfad absichern (kein Verzeichniswechsel möglich)
$pfad = str_replace(['..', '\\'], '', $e['dateipfad']);
$datei = UPLOAD_RECHNUNGEN . $pfad;

if (!$pfad || !is_file($datei)) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

$ext = strtolower(pathinfo($datei, PATHINFO_EXTENSION));
$mime = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($datei));
header('Content-Disposition: inline; filename="' . basename($e['original_name'] ?: $datei) . '"');
header('X-Content-Type-Options: nosniff');
readfile($datei);
exit;
