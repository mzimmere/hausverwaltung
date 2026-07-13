<?php
/**
 * Mein Konto & Benutzerverwaltung.
 * - Jeder Benutzer: eigenes Passwort ändern
 * - Nur Admin: Benutzer anlegen (ohne Startpasswort – stattdessen
 *   Einladungslink), Benutzer sperren/aktivieren, Passwort per
 *   Zurücksetzen-Link neu vergeben lassen
 * Rollen: admin | leser | hausmeister
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';
$pageTitle = 'Konto';
$basePath  = '../';
$benutzer  = aktuellerBenutzer();

$rollen = [
    'admin'       => 'Admin (alles bearbeiten)',
    'leser'       => 'Leser (nur ansehen)',
    'hausmeister' => 'Hausmeister (nur Rechnungen einreichen)',
    'mieter'      => 'Mieter (nur eigene Wohnung einsehen)',
];

// Wohnungen für die Mieter-Zuordnung (nur Admin braucht sie)
$wohnungenListe = istAdmin()
    ? $db->query("SELECT id, bezeichnung, mieter_name FROM wohnungen WHERE aktiv=1 ORDER BY id")->fetchAll()
    : [];

// Immobilien für die Hausmeister-/Leser-Zuordnung (nur Admin braucht sie)
$objekteListe = istAdmin()
    ? $db->query("SELECT id, name FROM objekt WHERE aktiv=1 ORDER BY sortierung, id")->fetchAll()
    : [];
$rollenMitObjektBindung = ['hausmeister', 'leser'];

// Basis-Adresse für Einladungslinks (z. B. https://192.168.2.242/hausverwaltung/)
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$basisUrl = $scheme . '://' . $_SERVER['HTTP_HOST']
          . rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/') . '/';

$neuerLink = null; // wird nach Link-Erzeugung angezeigt

// ── Eigenes Passwort ändern ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['passwort_alt'])) {
    csrfPruefen();
    $altPass  = $_POST['passwort_alt']  ?? '';
    $neuPass  = $_POST['passwort_neu']  ?? '';
    $neuPass2 = $_POST['passwort_neu2'] ?? '';

    $row = $db->prepare("SELECT passwort FROM benutzer WHERE id=?");
    $row->execute([$benutzer['id']]);
    $row = $row->fetch();

    if (!password_verify($altPass, $row['passwort'])) {
        $errorMsg = 'Das aktuelle Passwort ist falsch.';
    } elseif (strlen($neuPass) < 8) {
        $errorMsg = 'Das neue Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($neuPass !== $neuPass2) {
        $errorMsg = 'Die neuen Passwörter stimmen nicht überein.';
    } else {
        $hash = password_hash($neuPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("UPDATE benutzer SET passwort=? WHERE id=?")->execute([$hash, $benutzer['id']]);
        $successMsg = 'Passwort erfolgreich geändert.';
    }
}

// ── Neuen Benutzer anlegen (ohne Passwort → Einladungslink) ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['neuer_benutzer']) && istAdmin()) {
    csrfPruefen();
    $neuBenutzername = trim($_POST['neu_benutzername'] ?? '');
    $neuName         = trim($_POST['neu_name'] ?? '');
    $neuRolle        = array_key_exists($_POST['neu_rolle'] ?? '', $rollen) ? $_POST['neu_rolle'] : 'leser';
    $neuWohnungId    = ($neuRolle === 'mieter' && ($_POST['neu_wohnung_id'] ?? '') !== '')
                        ? (int)$_POST['neu_wohnung_id'] : null;
    $neuObjektId     = (in_array($neuRolle, $rollenMitObjektBindung, true) && ($_POST['neu_objekt_id'] ?? '') !== '')
                        ? (int)$_POST['neu_objekt_id'] : null;

    if ($neuBenutzername === '') {
        $errorMsg = 'Bitte einen Benutzernamen angeben.';
    } elseif ($neuRolle === 'mieter' && !$neuWohnungId) {
        $errorMsg = 'Bitte für die Rolle „Mieter" eine Wohnung zuordnen.';
    } elseif (in_array($neuRolle, $rollenMitObjektBindung, true) && !$neuObjektId) {
        $errorMsg = 'Bitte für die Rolle „' . $rollen[$neuRolle] . '" eine Immobilie zuordnen.';
    } else {
        $token = bin2hex(random_bytes(32));
        try {
            $db->prepare("
                INSERT INTO benutzer (benutzername, passwort, name, rolle, wohnung_id, objekt_id, aktiv, setup_token, setup_gueltig_bis)
                VALUES (?, '', ?, ?, ?, ?, 1, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
            ")->execute([$neuBenutzername, $neuName, $neuRolle, $neuWohnungId, $neuObjektId, $token]);
            protokolliere('benutzer', 'anlegen', (int)$db->lastInsertId(), "Benutzer \"$neuBenutzername\" angelegt (Rolle: $neuRolle)");
            $neuerLink = [
                'name' => $neuName ?: $neuBenutzername,
                'url'  => $basisUrl . 'setup.php?token=' . $token,
                'art'  => 'Einladung',
            ];
            $successMsg = "Benutzer \"$neuBenutzername\" angelegt – Einladungslink unten kopieren und zuschicken.";
        } catch (Exception $e) {
            $errorMsg = 'Benutzername bereits vergeben.';
        }
    }
}

// ── Zurücksetzen-Link für bestehenden Benutzer erzeugen ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['linkfuer']) && istAdmin()) {
    csrfPruefen();
    $lid  = (int)$_POST['linkfuer'];
    $stmt = $db->prepare("SELECT id, benutzername, name FROM benutzer WHERE id=?");
    $stmt->execute([$lid]);
    if ($ziel = $stmt->fetch()) {
        $token = bin2hex(random_bytes(32));
        $db->prepare("
            UPDATE benutzer SET setup_token=?, setup_gueltig_bis=DATE_ADD(NOW(), INTERVAL 7 DAY)
            WHERE id=?
        ")->execute([$token, $lid]);
        $neuerLink = [
            'name' => $ziel['name'] ?: $ziel['benutzername'],
            'url'  => $basisUrl . 'setup.php?token=' . $token,
            'art'  => 'Passwort zurücksetzen',
        ];
        $successMsg = 'Link erzeugt – unten kopieren und zuschicken. Das bisherige Passwort bleibt gültig, bis über den Link ein neues gesetzt wird.';
    }
}

// ── Benutzer bearbeiten: Name, Rolle, Wohnung (nur Admin) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_benutzer']) && istAdmin()) {
    csrfPruefen();
    $eid         = (int)$_POST['edit_id'];
    $editName    = trim($_POST['edit_name'] ?? '');
    $editRolle   = array_key_exists($_POST['edit_rolle'] ?? '', $rollen) ? $_POST['edit_rolle'] : 'leser';
    $editWohnung = ($editRolle === 'mieter' && ($_POST['edit_wohnung_id'] ?? '') !== '')
                    ? (int)$_POST['edit_wohnung_id'] : null;
    $editObjekt  = (in_array($editRolle, $rollenMitObjektBindung, true) && ($_POST['edit_objekt_id'] ?? '') !== '')
                    ? (int)$_POST['edit_objekt_id'] : null;

    if ($eid === (int)$benutzer['id'] && $editRolle !== 'admin') {
        $errorMsg = 'Du kannst dir nicht selbst die Admin-Rolle entziehen.';
    } elseif ($editRolle === 'mieter' && !$editWohnung) {
        $errorMsg = 'Bitte für die Rolle „Mieter" eine Wohnung zuordnen.';
    } elseif (in_array($editRolle, $rollenMitObjektBindung, true) && !$editObjekt) {
        $errorMsg = 'Bitte für die Rolle „' . $rollen[$editRolle] . '" eine Immobilie zuordnen.';
    } else {
        $db->prepare("UPDATE benutzer SET name=?, rolle=?, wohnung_id=?, objekt_id=? WHERE id=?")
           ->execute([$editName, $editRolle, $editWohnung, $editObjekt, $eid]);
        protokolliere('benutzer', 'aendern', $eid, "Benutzer bearbeitet (Rolle: $editRolle)");
        header('Location: passwort.php?geaendert=1'); exit;
    }
}
if (isset($_GET['geaendert'])) {
    $successMsg = 'Benutzer aktualisiert. Hinweis: Änderungen an Rolle oder Wohnung wirken ab der nächsten Anmeldung des Benutzers.';
}

// Zu bearbeitenden Benutzer laden (?edit=ID)
$bearbeite = null;
if (isset($_GET['edit']) && istAdmin()) {
    $stmt = $db->prepare("SELECT * FROM benutzer WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $bearbeite = $stmt->fetch();
}

// ── Benutzer de-/aktivieren ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle']) && istAdmin()) {
    csrfPruefen();
    $tid = (int)$_POST['toggle'];
    if ($tid !== (int)$benutzer['id']) { // sich selbst nicht deaktivieren
        $db->prepare("UPDATE benutzer SET aktiv = 1 - aktiv WHERE id=?")->execute([$tid]);
        protokolliere('benutzer', 'aendern', $tid, 'Benutzer gesperrt/aktiviert');
    }
    header('Location: passwort.php'); exit;
}

// Benutzerliste (nur Admin)
$alleBenutzer = [];
if (istAdmin()) {
    $alleBenutzer = $db->query("
        SELECT b.id, b.benutzername, b.name, b.rolle, b.aktiv, b.letzter_login, b.letzte_aktivitaet,
               b.setup_token IS NOT NULL AND b.setup_gueltig_bis > NOW() AS link_offen,
               b.setup_gueltig_bis,
               w.bezeichnung AS wohnung,
               o.name AS objekt
        FROM benutzer b
        LEFT JOIN wohnungen w ON b.wohnung_id = w.id
        LEFT JOIN objekt o ON b.objekt_id = o.id
        ORDER BY b.id
    ")->fetchAll();
}

include '../assets/header.php';
?>
<div class="page-header"><h1>Mein Konto<?= istAdmin() ? ' &amp; Benutzerverwaltung' : '' ?></h1></div>

<?php if ($neuerLink): ?>
<div class="card" style="border-left:4px solid var(--success)">
    <h2>🔗 <?= htmlspecialchars($neuerLink['art']) ?> für <?= htmlspecialchars($neuerLink['name']) ?></h2>
    <p style="margin-bottom:.75rem;color:var(--muted);font-size:.9rem">
        Diesen Link kopieren und z. B. per WhatsApp oder Mail zuschicken. Der Empfänger legt darüber
        sein Passwort selbst fest. Der Link ist <strong>einmal gültig</strong> und läuft in <strong>7 Tagen</strong> ab.
    </p>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <input type="text" id="einladungsLink" readonly value="<?= htmlspecialchars($neuerLink['url']) ?>"
               style="flex:1;min-width:260px;border:1px solid var(--border);border-radius:5px;padding:.5rem .75rem;font-size:.85rem;background:var(--input-bg);color:var(--text)">
        <button type="button" class="btn btn-primary" onclick="linkKopieren()">Kopieren</button>
    </div>
</div>
<script>
function linkKopieren() {
    var feld = document.getElementById('einladungsLink');
    feld.select();
    feld.setSelectionRange(0, 99999);
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(feld.value).then(function () { linkKopiertOk(); });
    } else {
        document.execCommand('copy');
        linkKopiertOk();
    }
}
function linkKopiertOk() {
    var btn = document.querySelector('button[onclick="linkKopieren()"]');
    btn.textContent = '✓ Kopiert';
    setTimeout(function () { btn.textContent = 'Kopieren'; }, 2000);
}
</script>
<?php endif; ?>

<div class="card">
    <h2>Passwort ändern – <?= htmlspecialchars($benutzer['name']) ?></h2>
    <form method="post" style="max-width:420px">
        <?= csrfFeld() ?>
        <div class="form-group">
            <label>Aktuelles Passwort *</label>
            <input type="password" name="passwort_alt" required autocomplete="current-password">
        </div>
        <div class="form-group" style="margin-top:.75rem">
            <label>Neues Passwort * (min. 8 Zeichen)</label>
            <input type="password" name="passwort_neu" required minlength="8" autocomplete="new-password">
        </div>
        <div class="form-group" style="margin-top:.75rem">
            <label>Neues Passwort wiederholen *</label>
            <input type="password" name="passwort_neu2" required minlength="8" autocomplete="new-password">
        </div>
        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary">Passwort speichern</button>
        </div>
    </form>
</div>

<?php if (istAdmin()): ?>
<?php if ($bearbeite): ?>
<div class="card" style="border-left:4px solid var(--primary)">
    <h2>Benutzer bearbeiten: <?= htmlspecialchars($bearbeite['benutzername']) ?></h2>
    <form method="post">
        <input type="hidden" name="edit_benutzer" value="1">
        <input type="hidden" name="edit_id" value="<?= $bearbeite['id'] ?>">
        <?= csrfFeld() ?>
        <div class="form-grid">
            <div class="form-group">
                <label>Anzeigename</label>
                <input type="text" name="edit_name" value="<?= htmlspecialchars($bearbeite['name']) ?>">
            </div>
            <div class="form-group">
                <label>Rolle</label>
                <select name="edit_rolle" id="editRolle" onchange="editWohnungUmschalten()">
                    <?php foreach ($rollen as $wert => $label): ?>
                    <option value="<?= $wert ?>"<?= $bearbeite['rolle'] === $wert ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="editWohnungFeld" style="display:<?= $bearbeite['rolle'] === 'mieter' ? '' : 'none' ?>">
                <label>Wohnung (für Mieter) *</label>
                <select name="edit_wohnung_id">
                    <option value="">-- bitte wählen --</option>
                    <?php foreach ($wohnungenListe as $w): ?>
                    <option value="<?= $w['id'] ?>"<?= (int)($bearbeite['wohnung_id'] ?? 0) === (int)$w['id'] ? ' selected' : '' ?>><?= htmlspecialchars($w['bezeichnung']) ?> – <?= htmlspecialchars($w['mieter_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="editObjektFeld" style="display:<?= in_array($bearbeite['rolle'], $rollenMitObjektBindung, true) ? '' : 'none' ?>">
                <label>Immobilie (für Hausmeister/Leser) *</label>
                <select name="edit_objekt_id">
                    <option value="">-- bitte wählen --</option>
                    <?php foreach ($objekteListe as $o): ?>
                    <option value="<?= $o['id'] ?>"<?= (int)($bearbeite['objekt_id'] ?? 0) === (int)$o['id'] ? ' selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--muted)">Sieht ausschließlich Daten dieser einen Immobilie.</small>
            </div>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary">Änderungen speichern</button>
            <a href="passwort.php" class="btn" style="background:#e2e8f0;color:#333">Abbrechen</a>
        </div>
    </form>
    <script>
    function editWohnungUmschalten() {
        var rolle = document.getElementById('editRolle').value;
        document.getElementById('editWohnungFeld').style.display = (rolle === 'mieter') ? '' : 'none';
        document.getElementById('editObjektFeld').style.display = (rolle === 'hausmeister' || rolle === 'leser') ? '' : 'none';
    }
    </script>
</div>
<?php endif; ?>

<div class="card">
    <h2>Neuen Benutzer anlegen</h2>
    <p style="margin-bottom:1rem;color:var(--muted);font-size:.9rem">
        Ohne Startpasswort – nach dem Anlegen erhältst du einen Einladungslink,
        über den der Benutzer sein Passwort selbst festlegt.
    </p>
    <form method="post">
        <input type="hidden" name="neuer_benutzer" value="1">
        <?= csrfFeld() ?>
        <div class="form-grid">
            <div class="form-group">
                <label>Benutzername *</label>
                <input type="text" name="neu_benutzername" required>
            </div>
            <div class="form-group">
                <label>Anzeigename</label>
                <input type="text" name="neu_name" placeholder="z.B. Max Mustermann">
            </div>
            <div class="form-group">
                <label>Rolle</label>
                <select name="neu_rolle" id="neuRolle" onchange="wohnungFeldUmschalten()">
                    <?php foreach ($rollen as $wert => $label): ?>
                    <option value="<?= $wert ?>"<?= $wert === 'leser' ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="wohnungFeld" style="display:none">
                <label>Wohnung (für Mieter) *</label>
                <select name="neu_wohnung_id">
                    <option value="">-- bitte wählen --</option>
                    <?php foreach ($wohnungenListe as $w): ?>
                    <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['bezeichnung']) ?> – <?= htmlspecialchars($w['mieter_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="neuObjektFeld" style="display:none">
                <label>Immobilie (für Hausmeister/Leser) *</label>
                <select name="neu_objekt_id">
                    <option value="">-- bitte wählen --</option>
                    <?php foreach ($objekteListe as $o): ?>
                    <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--muted)">Sieht ausschließlich Daten dieser einen Immobilie.</small>
            </div>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary">Benutzer anlegen &amp; Einladungslink erzeugen</button>
        </div>
    </form>
    <script>
    function wohnungFeldUmschalten() {
        var rolle = document.getElementById('neuRolle').value;
        document.getElementById('wohnungFeld').style.display = (rolle === 'mieter') ? '' : 'none';
        document.getElementById('neuObjektFeld').style.display = (rolle === 'hausmeister' || rolle === 'leser') ? '' : 'none';
    }
    </script>
</div>

<div class="card">
    <h2>Alle Benutzer (<?= count($alleBenutzer) ?>)</h2>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Benutzername</th><th>Name</th><th>Rolle</th><th>Status</th><th>Online</th><th>Letzter Login</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($alleBenutzer as $b): ?>
        <tr>
            <td><?= htmlspecialchars($b['benutzername']) ?><?= (int)$b['id'] === (int)$benutzer['id'] ? ' <span style="color:var(--muted);font-size:.78rem">(du)</span>' : '' ?></td>
            <td><?= htmlspecialchars($b['name']) ?></td>
            <td>
                <?php if ($b['rolle'] === 'admin'): ?>
                <span class="badge badge-info">Admin</span>
                <?php elseif ($b['rolle'] === 'hausmeister'): ?>
                <span class="badge badge-warning">Hausmeister</span>
                <?php if ($b['objekt']): ?><span style="font-size:.78rem;color:var(--muted)"><?= htmlspecialchars($b['objekt']) ?></span><?php endif; ?>
                <?php elseif ($b['rolle'] === 'mieter'): ?>
                <span class="badge badge-success">Mieter</span>
                <?php if ($b['wohnung']): ?><span style="font-size:.78rem;color:var(--muted)"><?= htmlspecialchars($b['wohnung']) ?></span><?php endif; ?>
                <?php else: ?>
                <span class="badge badge-info" style="opacity:.75">Leser</span>
                <?php if ($b['objekt']): ?><span style="font-size:.78rem;color:var(--muted)"><?= htmlspecialchars($b['objekt']) ?></span><?php endif; ?>
                <?php endif; ?>
            </td>
            <td>
                <?= $b['aktiv'] ? '<span class="badge badge-success">aktiv</span>' : '<span class="badge badge-danger">gesperrt</span>' ?>
                <?php if ($b['link_offen']): ?>
                <span class="badge badge-warning" title="Gültig bis <?= date('d.m.Y H:i', strtotime($b['setup_gueltig_bis'])) ?> Uhr">Link offen</span>
                <?php endif; ?>
            </td>
            <td>
                <?php
                $aktivVor = $b['letzte_aktivitaet'] ? (time() - strtotime($b['letzte_aktivitaet'])) : null;
                if ($aktivVor !== null && $aktivVor <= 180): ?>
                <span class="badge badge-success" title="Zuletzt aktiv um <?= date('H:i:s', strtotime($b['letzte_aktivitaet'])) ?> Uhr">🟢 Online</span>
                <?php elseif ($b['letzte_aktivitaet']): ?>
                <span style="color:var(--muted);font-size:.85rem">zuletzt aktiv<br><?= date('d.m.Y H:i', strtotime($b['letzte_aktivitaet'])) ?></span>
                <?php else: ?>
                <span style="color:var(--muted)">–</span>
                <?php endif; ?>
            </td>
            <td><?= $b['letzter_login'] ? date('d.m.Y H:i', strtotime($b['letzter_login'])) : '–' ?></td>
            <td style="white-space:nowrap">
                <a href="?edit=<?= $b['id'] ?>" class="btn btn-sm" style="background:#e2e8f0;color:#333" title="Rolle / Wohnung / Name ändern">✏️</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Neuen Link für <?= htmlspecialchars($b['benutzername']) ?> erzeugen?<?= $b['link_offen'] ? ' Der bisherige offene Link wird dabei ungültig.' : '' ?>')"><?= csrfFeld() ?><input type="hidden" name="linkfuer" value="<?= $b['id'] ?>"><button type="submit" class="btn btn-sm btn-primary">🔗 Link</button></form>
                <?php if ((int)$b['id'] !== (int)$benutzer['id']): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('<?= $b['aktiv'] ? 'Benutzer sperren?' : 'Benutzer aktivieren?' ?>')"><?= csrfFeld() ?><input type="hidden" name="toggle" value="<?= $b['id'] ?>"><button type="submit" class="btn btn-sm <?= $b['aktiv'] ? 'btn-danger' : 'btn-success' ?>"><?= $b['aktiv'] ? 'Sperren' : 'Aktivieren' ?></button></form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php include '../assets/footer.php'; ?>
