<?php
require_once 'config/config.php';
require_once 'config/auth.php';

// Bereits eingeloggt → weiter
if (istEingeloggt()) {
    header('Location: index.php');
    exit;
}

$fehler  = '';

// Hausbild als Hintergrund, falls in den Einstellungen hochgeladen
$loginBild = null;
foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
    if (is_file(__DIR__ . '/assets/haus.' . $ext)) {
        $loginBild = 'assets/haus.' . $ext . '?v=' . filemtime(__DIR__ . '/assets/haus.' . $ext);
        break;
    }
}

// Verwalter-Kontakt für den Hinweis unten auf der Seite - aus der
// Datenbank statt fest im Code, damit jede Installation ihre eigenen
// Kontaktdaten zeigt (siehe Einstellungen).
$verwalter = null;
try {
    require_once 'config/database.php';
    $verwalter = $db->query("SELECT verwalter_name, verwalter_strasse, verwalter_plz, verwalter_ort FROM objekt ORDER BY id LIMIT 1")->fetch();
} catch (Throwable $t) {
    $verwalter = null;
}

// Weiterleitungsziel bereinigen – der Punkt muss erlaubt sein (index.php!),
// sonst wird daraus "indexphp" und die Weiterleitung läuft ins Leere
$weiter = preg_replace('/[^a-zA-Z0-9\/_\-?=&.]/', '', $_GET['weiter'] ?? $_POST['weiter'] ?? '');
// Sicherheitsnetz: keine Pfad-Tricks und keine externen Ziele zulassen
if (strpos($weiter, '..') !== false || strpos($weiter, '//') === 0) {
    $weiter = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/database.php';

    $benutzername = trim($_POST['benutzername'] ?? '');
    $passwort     = $_POST['passwort'] ?? '';

    if ($benutzername && $passwort) {
        $stmt = $db->prepare("SELECT * FROM benutzer WHERE benutzername = ? AND aktiv = 1");
        $stmt->execute([$benutzername]);
        $benutzer = $stmt->fetch();

        if ($benutzer && password_verify($passwort, $benutzer['passwort'])) {
            // Session erneuern (Session-Fixation verhindern)
            session_regenerate_id(true);

            $_SESSION['benutzer_id']    = $benutzer['id'];
            $_SESSION['benutzer_name']  = $benutzer['name'] ?: $benutzer['benutzername'];
            $_SESSION['benutzer_rolle'] = $benutzer['rolle'];
            $_SESSION['benutzer_wohnung_id'] = $benutzer['wohnung_id'] ?? null;
            $_SESSION['benutzer_objekt_id']  = $benutzer['objekt_id'] ?? null;
            $_SESSION['letzte_aktivitaet'] = time();

            // Letzten Login speichern
            $db->prepare("UPDATE benutzer SET letzter_login = NOW() WHERE id = ?")
               ->execute([$benutzer['id']]);

            header('Location: ' . ($weiter ?: 'index.php'));
            exit;
        } else {
            // Kurze Verzögerung gegen Brute-Force
            sleep(1);
            $fehler = 'Benutzername oder Passwort falsch.';
        }
    } else {
        $fehler = 'Bitte alle Felder ausfüllen.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anmelden – <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #1a3a55 0%, #2c5f8a 50%, #1a3a55 100%);
<?php if ($loginBild): ?>
            background:
                linear-gradient(135deg, rgba(26,58,85,.82), rgba(44,95,138,.72), rgba(26,58,85,.82)),
                url('<?= htmlspecialchars($loginBild) ?>') center/cover no-repeat;
<?php endif; ?>
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .login-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #2c5f8a, #1a3a55);
            color: #fff;
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .login-icon {
            font-size: 3.5rem;
            display: block;
            margin-bottom: .5rem;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,.3));
        }
        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: .25rem;
        }
        .login-header p {
            font-size: .9rem;
            opacity: .8;
        }
        .login-body { padding: 2rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label {
            display: block;
            font-size: .85rem;
            font-weight: 600;
            color: #555;
            margin-bottom: .4rem;
        }
        .input-wrap { position: relative; }
        .input-wrap .icon {
            position: absolute;
            left: .85rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.1rem;
            pointer-events: none;
        }
        .form-group input {
            width: 100%;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: .7rem .75rem .7rem 2.6rem;
            font-size: 1rem;
            color: #2d3748;
            transition: border-color .2s, box-shadow .2s;
            background: #f8fafc;
        }
        .form-group input:focus {
            outline: none;
            border-color: #2c5f8a;
            box-shadow: 0 0 0 3px rgba(44,95,138,.15);
            background: #fff;
        }
        .btn-login {
            width: 100%;
            padding: .85rem;
            background: linear-gradient(135deg, #2c5f8a, #3a7abf);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity .2s, transform .1s;
            letter-spacing: .02em;
        }
        .btn-login:hover  { opacity: .9; }
        .btn-login:active { transform: scale(.99); }
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            border-radius: 8px;
            padding: .75rem 1rem;
            font-size: .9rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        .hint {
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            font-size: .8rem;
            color: #94a3b8;
        }
        .hint strong { color: #64748b; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <span class="login-icon">🏠</span>
        <h1><?= htmlspecialchars(APP_NAME) ?></h1>
        <p>Bitte melden Sie sich an</p>
    </div>

    <div class="login-body">
        <?php if ($fehler): ?>
        <div class="alert-error">⚠️ <?= htmlspecialchars($fehler) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <?php if ($weiter): ?>
            <input type="hidden" name="weiter" value="<?= htmlspecialchars($weiter) ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="benutzername">Benutzername</label>
                <div class="input-wrap">
                    <span class="icon">👤</span>
                    <input type="text" id="benutzername" name="benutzername"
                           value="<?= htmlspecialchars($_POST['benutzername'] ?? '') ?>"
                           autocomplete="username"
                           autofocus required>
                </div>
            </div>

            <div class="form-group">
                <label for="passwort">Passwort</label>
                <div class="input-wrap">
                    <span class="icon">🔒</span>
                    <input type="password" id="passwort" name="passwort"
                           autocomplete="current-password" required>
                </div>
            </div>

            <button type="submit" class="btn-login">Anmelden →</button>
        </form>

        <?php if ($verwalter && $verwalter['verwalter_name']): ?>
        <div class="hint">
            <?= htmlspecialchars($verwalter['verwalter_name']) ?><br>
            <?php if ($verwalter['verwalter_strasse']): ?><?= htmlspecialchars($verwalter['verwalter_strasse']) ?><br><?php endif; ?>
            <?php if ($verwalter['verwalter_plz'] || $verwalter['verwalter_ort']): ?><?= htmlspecialchars(trim($verwalter['verwalter_plz'] . ' ' . $verwalter['verwalter_ort'])) ?><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
