<?php
/**
 * PASSWORT RESET TOOL
 * ============================================================
 * Nur nutzen wenn der Login nicht funktioniert!
 * 
 * 1. Diese Datei auf die Synology hochladen (ins Hausverwaltungs-Verzeichnis)
 * 2. Im Browser aufrufen: http://IHRE-NAS-IP/hausverwaltung/passwort_reset.php
 * 3. Neues Passwort setzen
 * 4. DIESE DATEI DANACH SOFORT LÖSCHEN!
 * ============================================================
 */

// Einfacher Schutz: nur aus dem lokalen Netzwerk erreichbar
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$erlaubt = (
    str_starts_with($ip, '192.168.') ||
    str_starts_with($ip, '10.')      ||
    str_starts_with($ip, '172.')     ||
    $ip === '127.0.0.1'              ||
    $ip === '::1'
);
if (!$erlaubt) {
    http_response_code(403);
    die('Zugriff nur aus dem lokalen Netzwerk erlaubt.');
}

$nachricht = '';
$sql_ausgabe = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $neuesPasswort = $_POST['passwort'] ?? '';
    $benutzername  = trim($_POST['benutzername'] ?? 'admin');

    if (strlen($neuesPasswort) < 6) {
        $nachricht = 'error:Passwort muss mindestens 6 Zeichen haben.';
    } else {
        $hash = password_hash($neuesPasswort, PASSWORD_BCRYPT, ['cost' => 12]);
        
        // Direkt in DB schreiben falls config vorhanden
        $config = __DIR__ . '/config/config.php';
        if (file_exists($config)) {
            require_once $config;
            try {
                $db = new PDO(
                    "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
                    DB_USER, DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                // Benutzer anlegen falls nicht vorhanden
                $exists = $db->prepare("SELECT COUNT(*) FROM benutzer WHERE benutzername=?");
                $exists->execute([$benutzername]);
                if ($exists->fetchColumn() == 0) {
                    $db->prepare("INSERT INTO benutzer (benutzername, passwort, name, rolle) VALUES (?,?,?,?)")
                       ->execute([$benutzername, $hash, 'Administrator', 'admin']);
                    $nachricht = 'success:Benutzer "' . htmlspecialchars($benutzername) . '" wurde neu angelegt mit dem angegebenen Passwort.';
                } else {
                    $db->prepare("UPDATE benutzer SET passwort=?, aktiv=1 WHERE benutzername=?")
                       ->execute([$hash, $benutzername]);
                    $nachricht = 'success:Passwort für "' . htmlspecialchars($benutzername) . '" wurde erfolgreich geändert!';
                }
            } catch (Exception $e) {
                $sql_ausgabe = "UPDATE benutzer SET passwort = '" . $hash . "' WHERE benutzername = '" . $benutzername . "';";
                $nachricht = 'info:Datenbankfehler: ' . $e->getMessage() . ' – Bitte dieses SQL manuell in phpMyAdmin ausführen:';
            }
        } else {
            $sql_ausgabe = "UPDATE benutzer SET passwort = '" . $hash . "' WHERE benutzername = '" . $benutzername . "';";
            $nachricht = 'info:config.php nicht gefunden. Bitte dieses SQL manuell in phpMyAdmin ausführen:';
        }
    }
}

[$typ, $msg] = $nachricht ? explode(':', $nachricht, 2) : ['', ''];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Passwort Reset – Hausverwaltung</title>
<style>
  body{font-family:Arial,sans-serif;background:#1a3a55;min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0}
  .card{background:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.4);max-width:480px;width:100%;overflow:hidden}
  .card-header{background:#c0392b;color:#fff;padding:1.5rem 2rem;text-align:center}
  .card-header h1{margin:0;font-size:1.3rem}
  .card-header p{margin:.3rem 0 0;opacity:.85;font-size:.9rem}
  .card-body{padding:2rem}
  label{display:block;font-size:.85rem;font-weight:600;color:#555;margin-bottom:.35rem}
  input{width:100%;box-sizing:border-box;border:2px solid #e2e8f0;border-radius:6px;padding:.65rem .9rem;font-size:1rem;margin-bottom:1rem}
  input:focus{outline:none;border-color:#2c5f8a}
  button{width:100%;padding:.8rem;background:#2c5f8a;color:#fff;border:none;border-radius:6px;font-size:1rem;font-weight:700;cursor:pointer}
  .success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:.85rem;border-radius:6px;margin-bottom:1rem}
  .error  {background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:.85rem;border-radius:6px;margin-bottom:1rem}
  .info   {background:#cce5ff;color:#004085;border:1px solid #b8daff;padding:.85rem;border-radius:6px;margin-bottom:1rem}
  .sql-box{background:#1e1e1e;color:#4fc3f7;padding:1rem;border-radius:6px;font-size:.85rem;word-break:break-all;margin-top:.5rem;font-family:monospace}
  .warning{background:#fff3cd;color:#856404;border:1px solid #ffeeba;padding:.75rem;border-radius:6px;font-size:.85rem;margin-top:1rem}
</style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <h1>🔑 Passwort Reset</h1>
    <p>Hausverwaltung – Notfall-Zugang</p>
  </div>
  <div class="card-body">

  <?php if ($typ === 'success'): ?>
    <div class="success">✅ <?= $msg ?></div>
    <p style="text-align:center">
      <a href="login.php" style="color:#2c5f8a;font-weight:700">→ Jetzt anmelden</a>
    </p>
    <div class="warning">⚠️ <strong>Sicherheitshinweis:</strong> Bitte löschen Sie die Datei <code>passwort_reset.php</code> jetzt sofort vom Server!</div>
  <?php else: ?>
    <?php if ($typ === 'error'): ?><div class="error">❌ <?= $msg ?></div><?php endif; ?>
    <?php if ($typ === 'info'):  ?>
      <div class="info">ℹ️ <?= $msg ?></div>
      <?php if ($sql_ausgabe): ?>
      <div class="sql-box"><?= htmlspecialchars($sql_ausgabe) ?></div>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post">
      <label>Benutzername</label>
      <input type="text" name="benutzername" value="admin">
      <label>Neues Passwort</label>
      <input type="password" name="passwort" placeholder="Mindestens 6 Zeichen" autofocus>
      <button type="submit">Passwort setzen</button>
    </form>
    <div class="warning" style="margin-top:1rem">⚠️ Diese Datei nach der Nutzung sofort löschen!</div>
  <?php endif; ?>

  </div>
</div>
</body>
</html>
