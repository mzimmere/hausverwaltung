<?php
// ============================================================
// Konfiguration – Vorlage. Kopieren nach config.php und anpassen!
// (config.php selbst ist bewusst nicht Teil dieses Repos, siehe
// .gitignore – dort stehen die echten Zugangsdaten.)
// ============================================================

// Datenbankverbindung
// Liest zuerst Umgebungsvariablen (z. B. aus docker-compose.yml oder dem
// Synology-Paket-Installer) und fällt sonst auf die untenstehenden festen
// Werte zurück.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'hausverwaltung');
define('DB_USER', getenv('DB_USER') ?: 'hausverwaltung');   // Datenbankbenutzer
define('DB_PASS', getenv('DB_PASS') ?: 'BITTE-AENDERN');    // Bitte ändern!

// Anwendungseinstellungen
define('APP_NAME', 'Hausverwaltung');
define('APP_VERSION', '1.0');
define('APP_URL', '');  // z.B. http://192.168.1.100:8080

// Update-Hinweis (optional): öffentlich erreichbare JSON-Datei mit der
// jeweils neuesten Versionsnummer. Leer lassen, um die Prüfung abzuschalten.
define('UPDATE_CHECK_URL', 'https://hausverwaltung-updatecheck-mzimmere.vercel.app/version.json');

// Upload-Verzeichnis
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_ABRECHNUNGEN', UPLOAD_DIR . 'abrechnungen/');
define('UPLOAD_RECHNUNGEN', UPLOAD_DIR . 'rechnungen/');
define('UPLOAD_DOKUMENTE', UPLOAD_DIR . 'dokumente/');

// Backup-Verzeichnis
define('BACKUP_DIR', __DIR__ . '/../backups/');

// Zeitzone
date_default_timezone_set('Europe/Berlin');

// Fehlerausgabe (für Produktion auf 0 setzen)
ini_set('display_errors', 0);
error_reporting(E_ALL);
