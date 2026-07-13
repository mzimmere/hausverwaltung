<?php
/**
 * Authentifizierung – in jede Seite einbinden (passiert über header.php)
 * Prüft ob eine gültige Session vorliegt, sonst → Login.
 * Enthält außerdem den CSRF-Schutz für alle Formulare.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
        // Cookie nur über HTTPS übertragen (automatisch erkannt,
        // damit ein evtl. HTTP-Zugriff im Notfall weiter funktioniert)
        'cookie_secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
}

// Session-Timeout: 8 Stunden
define('SESSION_TIMEOUT', 8 * 60 * 60);

function istEingeloggt(): bool {
    if (empty($_SESSION['benutzer_id'])) return false;
    if (empty($_SESSION['letzte_aktivitaet'])) return false;
    if (time() - $_SESSION['letzte_aktivitaet'] > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['letzte_aktivitaet'] = time();
    return true;
}

function requireLogin(string $basePath = ''): void {
    if (!istEingeloggt()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . $basePath . 'login.php' . ($redirect ? '?weiter=' . $redirect : ''));
        exit;
    }
}

function aktuellerBenutzer(): array {
    return [
        'id'         => $_SESSION['benutzer_id']         ?? 0,
        'name'       => $_SESSION['benutzer_name']       ?? '',
        'rolle'      => $_SESSION['benutzer_rolle']      ?? 'leser',
        'wohnung_id' => $_SESSION['benutzer_wohnung_id'] ?? null,
        'objekt_id'  => $_SESSION['benutzer_objekt_id']  ?? null,
    ];
}

function istAdmin(): bool {
    return ($_SESSION['benutzer_rolle'] ?? '') === 'admin';
}

// ── Aktives Objekt (Immobilie) ───────────────────────────────
// Nur der Admin darf frei zwischen Häusern umschalten (in der
// Session gemerkt). Alle anderen Rollen sind fest an genau ein
// Haus gebunden, damit niemand versehentlich oder absichtlich
// Daten eines anderen Hauses sieht:
//   - Mieter:      ergibt sich automatisch aus der eigenen Wohnung
//   - Hausmeister/Leser: fest über benutzer.objekt_id zugeordnet
// Beides wird bei jedem Aufruf frisch bestimmt, nicht nur beim Login,
// damit eine spätere Änderung durch den Admin sofort wirkt.

function aktivesObjekt(): int {
    global $db;
    $rolle = $_SESSION['benutzer_rolle'] ?? '';

    if ($rolle === 'mieter') {
        $wohnungId = $_SESSION['benutzer_wohnung_id'] ?? null;
        if ($wohnungId && isset($db)) {
            $stmt = $db->prepare("SELECT objekt_id FROM wohnungen WHERE id=?");
            $stmt->execute([$wohnungId]);
            $oid = $stmt->fetchColumn();
            if ($oid) return (int)$oid;
        }
        return 1;
    }

    if ($rolle === 'hausmeister' || $rolle === 'leser') {
        return (int)($_SESSION['benutzer_objekt_id'] ?? 1);
    }

    // Admin: frei über den Umschalter wählbar
    return (int)($_SESSION['aktives_objekt'] ?? 1);
}

function setzeAktivesObjekt(int $objektId): void {
    // Nur der Admin darf das Haus wechseln – bei allen anderen Rollen
    // wird das Ziel ignoriert, auch bei einem manuell aufgerufenen Link.
    if ($objektId > 0 && ($_SESSION['benutzer_rolle'] ?? '') === 'admin') {
        $_SESSION['aktives_objekt'] = $objektId;
    }
}

// ── CSRF-Schutz ──────────────────────────────────────────────
// Verhindert, dass ein präparierter fremder Link/eine fremde Seite
// im eingeloggten Browser Aktionen auslöst (z. B. etwas löscht).
// Verwendung: csrfFeld() in jedes Formular ausgeben,
// csrfPruefen() am Anfang jeder POST-Verarbeitung aufrufen.

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfFeld(): string {
    return '<input type="hidden" name="csrf" value="' . csrfToken() . '">';
}

function csrfPruefen(): void {
    if (!hash_equals(csrfToken(), $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Ungültige Anfrage – bitte die Seite neu laden und erneut versuchen.');
    }
}

// ── Leser-Sperre ─────────────────────────────────────────────
// Die Rolle "leser" darf laut Namen nur ansehen. Der Zugriff auf die
// Verwaltungsseiten selbst ist zwar erlaubt (siehe Rollen-Guards in
// den einzelnen Seiten), aber jede Schreib-/Löschaktion muss zusätzlich
// hier abgefangen werden. Aufruf ganz am Anfang jedes POST-Blocks und
// jedes GET-basierten Lösch-/Umschalt-Links.

function istNurLesend(): bool {
    return ($_SESSION['benutzer_rolle'] ?? '') === 'leser';
}

function leserSchreibschutz(): void {
    if (istNurLesend()) {
        http_response_code(403);
        exit('Als Leser können Sie hier nur ansehen, keine Änderungen vornehmen.');
    }
}

// ── Audit-Log ────────────────────────────────────────────────
// Protokolliert wer wann was geändert hat (wichtig sobald mehr als
// eine Person Zugriff auf Finanzdaten hat). Absichtlich defensiv:
// ein Logging-Fehler (z. B. Tabelle fehlt noch) darf die eigentliche
// Aktion nie verhindern.

function protokolliere(string $bereich, string $aktion, ?int $datensatzId, string $beschreibung = ''): void {
    global $db;
    if (!isset($db)) return;
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (objekt_id, benutzer_id, benutzer_name, bereich, aktion, datensatz_id, beschreibung)
            VALUES (?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            aktivesObjekt(),
            $_SESSION['benutzer_id']   ?? null,
            $_SESSION['benutzer_name'] ?? '',
            $bereich,
            $aktion,
            $datensatzId,
            $beschreibung,
        ]);
    } catch (Throwable $t) {
        // Tabelle evtl. noch nicht angelegt (Migration nicht ausgeführt) –
        // Protokollierung darf die App nie blockieren.
    }
}
