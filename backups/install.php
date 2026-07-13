<?php
/**
 * ============================================================
 * INSTALLATIONS-ASSISTENT
 * Aufruf: http://IHR-NAS/hausverwaltung/install.php
 * WICHTIG: Nach der Installation diese Datei löschen!
 * ============================================================
 */
$step = (int)($_POST['step'] ?? 0);
$msg  = '';
$ok   = true;

function testDbConnection($host, $port, $user, $pass, $name) {
    try {
        new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return true;
    } catch (Exception $e) {
        return $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Hausverwaltung – Installation</title>
<style>
  body{font-family:Arial,sans-serif;background:#f4f6f9;margin:0;padding:20px}
  .box{max-width:700px;margin:40px auto;background:#fff;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.1);overflow:hidden}
  .box-header{background:#2c5f8a;color:#fff;padding:24px 32px}
  .box-header h1{margin:0;font-size:1.5rem}
  .box-body{padding:32px}
  label{display:block;font-size:.85rem;font-weight:600;color:#555;margin-bottom:4px}
  input{width:100%;box-sizing:border-box;border:1px solid #ddd;border-radius:5px;padding:8px 12px;font-size:.95rem;margin-bottom:16px}
  button{background:#2c5f8a;color:#fff;border:none;padding:10px 28px;border-radius:5px;font-size:1rem;cursor:pointer}
  .success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;border-radius:6px;padding:12px 16px;margin:12px 0}
  .error  {background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:6px;padding:12px 16px;margin:12px 0}
  .info   {background:#cce5ff;color:#004085;border:1px solid #b8daff;border-radius:6px;padding:12px 16px;margin:12px 0}
  .step-indicator{color:#cce0f5;font-size:.9rem;margin-top:4px}
  code{background:#f0f0f0;padding:2px 6px;border-radius:3px;font-size:.9em}
</style>
</head>
<body>
<div class="box">
  <div class="box-header">
    <h1>🏠 Hausverwaltung – Installations-Assistent</h1>
    <div class="step-indicator">Schritt <?= $step+1 ?> von 3</div>
  </div>
  <div class="box-body">

<?php if ($step === 0): ?>
  <h2>Schritt 1: Systemprüfung</h2>
  <?php
    $checks = [
        'PHP >= 7.4'   => version_compare(PHP_VERSION, '7.4', '>='),
        'PDO vorhanden' => extension_loaded('pdo'),
        'PDO MySQL'    => extension_loaded('pdo_mysql'),
        'FileInfo'     => extension_loaded('fileinfo'),
        'uploads/ beschreibbar' => is_writable(__DIR__ . '/uploads'),
        'backups/ beschreibbar' => is_writable(__DIR__ . '/backups'),
    ];
    $allOk = true;
    foreach ($checks as $label => $result) {
        $allOk = $allOk && $result;
        echo '<div class="'.($result ? 'success' : 'error').'">'.($result ? '✅' : '❌').' '.$label.'</div>';
    }
    if (!$allOk) echo '<div class="error">Bitte die oben markierten Probleme beheben, bevor Sie fortfahren.</div>';
  ?>
  <?php if ($allOk): ?>
  <form method="post"><input type="hidden" name="step" value="1"><button type="submit">Weiter →</button></form>
  <?php endif; ?>

<?php elseif ($step === 1): ?>
  <h2>Schritt 2: Datenbankverbindung</h2>
  <?php
  if (isset($_POST['db_host'])) {
      $result = testDbConnection($_POST['db_host'], $_POST['db_port'], $_POST['db_user'], $_POST['db_pass'], $_POST['db_name']);
      if ($result === true) {
          // Config schreiben
          $cfg = file_get_contents(__DIR__ . '/config/config.php');
          $cfg = preg_replace("/define\('DB_HOST'.+?;/", "define('DB_HOST', '".addslashes($_POST['db_host'])."');", $cfg);
          $cfg = preg_replace("/define\('DB_PORT'.+?;/", "define('DB_PORT', '".addslashes($_POST['db_port'])."');", $cfg);
          $cfg = preg_replace("/define\('DB_NAME'.+?;/", "define('DB_NAME', '".addslashes($_POST['db_name'])."');", $cfg);
          $cfg = preg_replace("/define\('DB_USER'.+?;/", "define('DB_USER', '".addslashes($_POST['db_user'])."');", $cfg);
          $cfg = preg_replace("/define\('DB_PASS'.+?;/", "define('DB_PASS', '".addslashes($_POST['db_pass'])."');", $cfg);
          file_put_contents(__DIR__ . '/config/config.php', $cfg);
          echo '<div class="success">✅ Verbindung erfolgreich – Konfiguration gespeichert.</div>';
          echo '<form method="post"><input type="hidden" name="step" value="2"><button>Datenbank installieren →</button></form>';
      } else {
          echo '<div class="error">❌ Verbindung fehlgeschlagen: '.$result.'</div>';
      }
  }
  ?>
  <form method="post">
    <input type="hidden" name="step" value="1">
    <label>Datenbankhost</label><input name="db_host" value="localhost">
    <label>Port</label><input name="db_port" value="3306">
    <label>Datenbankname</label><input name="db_name" value="hausverwaltung">
    <label>Benutzer</label><input name="db_user" value="hausverwaltung">
    <label>Passwort</label><input name="db_pass" type="password" placeholder="Ihr Datenbankpasswort">
    <button type="submit">Verbindung testen</button>
  </form>
  <div class="info" style="margin-top:16px">
    <strong>Hinweis Synology:</strong> Datenbank und Benutzer müssen vorher in <em>phpMyAdmin</em> oder dem <em>MariaDB-Paket</em> angelegt werden.<br>
    SQL: <code>CREATE DATABASE hausverwaltung CHARACTER SET utf8mb4;</code><br>
    <code>CREATE USER 'hausverwaltung'@'localhost' IDENTIFIED BY 'IhrPasswort';</code><br>
    <code>GRANT ALL ON hausverwaltung.* TO 'hausverwaltung'@'localhost';</code>
  </div>

<?php elseif ($step === 2): ?>
  <h2>Schritt 3: Tabellen anlegen</h2>
  <?php
    require_once __DIR__ . '/config/config.php';
    try {
        $db = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
        // Split auf ; und einzeln ausführen
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $query) {
            if ($query) $db->exec($query);
        }
        echo '<div class="success">✅ Alle Tabellen erfolgreich angelegt!</div>';
        echo '<div class="success">✅ Beispieldaten (3 Wohnungen, Kostenarten) wurden eingerichtet.</div>';
        echo '<div class="info"><strong>Installation abgeschlossen!</strong><br>
            Bitte löschen Sie jetzt die Datei <code>install.php</code> und öffnen Sie die Anwendung.<br><br>
            <a href="index.php" style="background:#27ae60;color:#fff;padding:10px 24px;text-decoration:none;border-radius:5px;display:inline-block">🏠 Zur Hausverwaltung →</a>
        </div>';
    } catch (Exception $e) {
        echo '<div class="error">❌ Fehler beim Anlegen der Tabellen:<br>'.$e->getMessage().'</div>';
    }
  ?>
<?php endif; ?>

  </div>
</div>
</body>
</html>
