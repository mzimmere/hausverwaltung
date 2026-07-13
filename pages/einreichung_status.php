<?php
/**
 * Liefert die Anzahl offener Einreichungen als JSON –
 * wird von der Navigationsleiste im Hintergrund abgefragt,
 * damit der Zähler am Freigabe-Icon live aktuell bleibt.
 */
require_once '../config/config.php';
require_once '../config/auth.php';
require_once '../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!istEingeloggt() || !istAdmin()) {
    http_response_code(403);
    echo json_encode(['offen' => 0]);
    exit;
}

$offen = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM einreichungen WHERE status = 'eingereicht' AND objekt_id = ?");
    $stmt->execute([aktivesObjekt()]);
    $offen = (int)$stmt->fetchColumn();
} catch (Throwable $t) {
    // Tabelle existiert evtl. noch nicht
}

echo json_encode(['offen' => $offen]);
