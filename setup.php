<?php
/**
 * Passwort selbst setzen – per Einladungs- oder Zurücksetzen-Link.
 * Diese Seite ist ohne Login erreichbar; sie prüft ausschließlich
 * den Token aus dem Link. Der Token ist einmal gültig und läuft
 * nach 7 Tagen ab (wird beim Erzeugen in passwort.php gesetzt).
 */
require_once 'config/config.php';
require_once 'config/database.php';

$token   = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? $_POST['token'] ?? '');
$fehler  = '';
$fertig  = false;
$konto   = null;

if (strlen($token) === 64) {
    $stmt = $db->prepare("
        SELECT id, benutzername, name
        FROM benutzer
        WHERE setup_token = ? AND setup_gueltig_bis > NOW() AND aktiv = 1
    ");
    $stmt->execute([$token]);
    $konto = $stmt->fetch();
}

if (!$konto) {
    $fehler = 'Dieser Link ist ungültig oder abgelaufen. Bitte einen neuen Link anfordern.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw1 = $_POST['passwort']  ?? '';
    $pw2 = $_POST['passwort2'] ?? '';

    if (strlen($pw1) < 8) {
        $fehler = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($pw1 !== $pw2) {
        $fehler = 'Die Passwörter stimmen nicht überein.';
    } else {
        $hash = password_hash($pw1, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("
            UPDATE benutzer
            SET passwort = ?, setup_token = NULL, setup_gueltig_bis = NULL
            WHERE id = ?
        ")->execute([$hash, $konto['id']]);
        $fertig = true;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort festlegen – <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #1a3a55 0%, #2c5f8a 50%, #1a3a55 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .setup-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
        }
        .setup-header {
            background: linear-gradient(135deg, #2c5f8a, #1a3a55);
            color: #fff;
            padding: 2rem;
            text-align: center;
        }
        .setup-header h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: .25rem; }
        .setup-header p { font-size: .9rem; opacity: .8; }
        .setup-body { padding: 2rem; }
        .konto-info {
            background: #f0f5fb;
            border: 1px solid #cfe0f2;
            border-radius: 8px;
            padding: .75rem 1rem;
            margin-bottom: 1.25rem;
            font-size: .92rem;
            color: #2c5f8a;
        }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label {
            display: block; font-size: .85rem; font-weight: 600;
            color: #555; margin-bottom: .4rem;
        }
        .form-group input {
            width: 100%; border: 2px solid #e2e8f0; border-radius: 8px;
            padding: .7rem .85rem; font-size: 1rem; color: #2d3748;
            background: #f8fafc; transition: border-color .2s, box-shadow .2s;
        }
        .form-group input:focus {
            outline: none; border-color: #2c5f8a;
            box-shadow: 0 0 0 3px rgba(44,95,138,.15); background: #fff;
        }
        .btn-setup {
            width: 100%; padding: .85rem;
            background: linear-gradient(135deg, #2c5f8a, #3a7abf);
            color: #fff; border: none; border-radius: 8px;
            font-size: 1rem; font-weight: 700; cursor: pointer;
        }
        .btn-setup:hover { opacity: .9; }
        .alert-error, .alert-ok {
            border-radius: 8px; padding: .85rem 1rem;
            font-size: .92rem; margin-bottom: 1.25rem;
        }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-ok    { background: #f0fff4; border: 1px solid #b7e4c7; color: #1e6b3a; }
        .login-link {
            display: block; text-align: center; margin-top: .5rem;
            color: #2c5f8a; font-weight: 600; text-decoration: none;
        }
    </style>
</head>
<body>

<div class="setup-card">
    <div class="setup-header">
        <h1><?= htmlspecialchars(APP_NAME) ?></h1>
        <p>Passwort festlegen</p>
    </div>
    <div class="setup-body">

        <?php if ($fertig): ?>
            <div class="alert-ok">✓ Passwort gespeichert. Sie können sich jetzt anmelden.</div>
            <a href="login.php" class="btn-setup" style="display:block;text-align:center;text-decoration:none">Zur Anmeldung →</a>

        <?php elseif (!$konto): ?>
            <div class="alert-error">⚠️ <?= htmlspecialchars($fehler) ?></div>

        <?php else: ?>
            <div class="konto-info">
                Konto: <strong><?= htmlspecialchars($konto['name'] ?: $konto['benutzername']) ?></strong>
                (Benutzername: <strong><?= htmlspecialchars($konto['benutzername']) ?></strong>)
            </div>

            <?php if ($fehler): ?>
            <div class="alert-error">⚠️ <?= htmlspecialchars($fehler) ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <label>Neues Passwort (min. 8 Zeichen)</label>
                    <input type="password" name="passwort" minlength="8" required autofocus autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label>Passwort wiederholen</label>
                    <input type="password" name="passwort2" minlength="8" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn-setup">Passwort speichern</button>
            </form>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
