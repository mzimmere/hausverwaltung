<?php
require_once __DIR__ . '/config.php';

try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('<div style="font-family:sans-serif;padding:20px;color:#c0392b;">
         <h2>Datenbankfehler</h2>
         <p>Verbindung fehlgeschlagen: ' . htmlspecialchars($e->getMessage()) . '</p>
         <p>Bitte prüfen Sie die Datei <code>config/config.php</code>.</p>
         </div>');
}

require_once __DIR__ . '/../includes/migrationen.php';
migrationen_ausfuehren($db);
