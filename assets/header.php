<?php
// Login-Schutz – jede Seite lädt diesen Header
require_once ($basePath ?? '') . 'config/auth.php';
requireLogin($basePath ?? '');
$user = aktuellerBenutzer();

// ------------------------------------------------------------
// Online-Status: letzte Aktivität in der DB festhalten, damit der
// Admin sehen kann, wer gerade online ist. Höchstens einmal pro
// Minute geschrieben, nicht bei jedem einzelnen Seitenaufruf.
// ------------------------------------------------------------
if (isset($db) && !empty($_SESSION['benutzer_id'])) {
    $jetztZeit = time();
    if (empty($_SESSION['letzte_aktivitaet_db']) || $jetztZeit - $_SESSION['letzte_aktivitaet_db'] > 60) {
        try {
            $db->prepare("UPDATE benutzer SET letzte_aktivitaet = NOW() WHERE id = ?")
               ->execute([$_SESSION['benutzer_id']]);
            $_SESSION['letzte_aktivitaet_db'] = $jetztZeit;
        } catch (Throwable $t) {
            // Spalte evtl. noch nicht angelegt (Migration nicht ausgeführt)
        }
    }
}

// ------------------------------------------------------------
// Aktives Objekt (Immobilie) – Umschalter in der Leiste
// ------------------------------------------------------------
if (isset($_GET['objekt_wechsel']) && isset($db)) {
    setzeAktivesObjekt((int)$_GET['objekt_wechsel']);
    // sauber ohne den Parameter neu laden
    $ziel = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $ziel);
    exit;
}

$objektListe = [];
$aktObjekt   = null;
if (isset($db)) {
    try {
        $objektListe = $db->query("SELECT id, name FROM objekt WHERE aktiv = 1 ORDER BY sortierung, id")->fetchAll();
        // Falls das gemerkte Objekt nicht (mehr) existiert: auf das erste zurückfallen
        $ids = array_column($objektListe, 'id');
        if ($objektListe && !in_array(aktivesObjekt(), array_map('intval', $ids), true)) {
            setzeAktivesObjekt((int)$objektListe[0]['id']);
        }
        foreach ($objektListe as $o) {
            if ((int)$o['id'] === aktivesObjekt()) { $aktObjekt = $o; break; }
        }
    } catch (Throwable $t) {
        // Tabelle/Spalten evtl. noch nicht vorhanden – Umschalter bleibt aus
    }
}

// ------------------------------------------------------------
// Hausmeister-Sperre: Diese Rolle darf ausschließlich die
// Einreichungsseite (plus Konto) nutzen. Da jede Seite diesen
// Header lädt, greift die Sperre zentral für das ganze System.
// ------------------------------------------------------------
if (($user['rolle'] ?? '') === 'hausmeister') {
    $hmErlaubt = ['einreichung.php', 'einreichung_datei.php', 'wartung.php', 'datei.php', 'passwort.php'];
    if (!in_array(basename($_SERVER['PHP_SELF']), $hmErlaubt, true)) {
        header('Location: ' . ($basePath ?? '') . 'pages/einreichung.php');
        exit;
    }
}

// Mieter-Sperre: nur die eigene Wohnungs-Ansicht, Einreichung und Konto
if (($user['rolle'] ?? '') === 'mieter') {
    $mieterErlaubt = ['meine_wohnung.php', 'einreichung.php', 'einreichung_datei.php', 'datei.php', 'passwort.php'];
    if (!in_array(basename($_SERVER['PHP_SELF']), $mieterErlaubt, true)) {
        header('Location: ' . ($basePath ?? '') . 'pages/meine_wohnung.php');
        exit;
    }
}

// Aktive Seite für die Hervorhebung in der Navigation
$currentPage = basename($_SERVER['PHP_SELF']);

// ------------------------------------------------------------
// Changelog – hier bei jeder neuen Version einen Eintrag ergänzen
// (neueste Version immer oben – sie bestimmt automatisch die
// Versions-Badge in der Leiste und den CSS-Cache-Buster)
// ------------------------------------------------------------
$changelog = [
    [
        'version' => 'v57',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Navigationsleiste: Konto/Abmelden-Bereich bleibt jetzt fest unten stehen, nur die Menüpunkte darüber scrollen bei vielen Einträgen',
        ],
    ],
    [
        'version' => 'v56',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Komplettes Redesign im Google-Material-Design-3-Look: Navigation ist jetzt eine Seitenleiste statt der oberen Icon-Leiste, neues Farb-/Formensystem (Karten, Buttons, Tabellen, Chips) auf jeder Seite, Kosten-Tacho zeigt jetzt einen Fortschrittsring statt Zeiger-Tacho',
        ],
    ],
    [
        'version' => 'v55',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Kosten-Tacho: fester grün/gelb/roter Zonenhintergrund statt nur eingefärbtem Fortschrittsbogen – die Zone ist jetzt auch ohne Nadel/Zahl auf einen Blick erkennbar',
        ],
    ],
    [
        'version' => 'v54',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Kosten-Tacho: zwei Vergleichsbalken ("Bezahlt" / "Verbraucht") ergänzt, damit das Verhältnis von Vorauszahlung zu laufenden Kosten auch als Länge sichtbar ist, nicht nur als Prozentzahl',
        ],
    ],
    [
        'version' => 'v53',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Synology-Paket: Update-Sync kann jetzt automatisch laufen (kein SSH-Schritt mehr nötig), sobald der Web-Ordner einmalig für die Gruppe "http" freigegeben wurde (Anleitung erscheint nach Installation/Update). Ohne diese Freigabe funktioniert der bisherige manuelle SSH-Sync weiterhin unverändert.',
        ],
    ],
    [
        'version' => 'v52',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Neue SQL-Migrationen werden ab jetzt automatisch angewendet – kein manuelles Einspielen in phpMyAdmin mehr nötig. Behebt auch den Fehler beim Speichern der Abrechnungs-Vorlage (fehlende Spalten wurden nicht automatisch angelegt)',
        ],
    ],
    [
        'version' => 'v51',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Synology-Paket: fehlende "preupgrade"/"postupgrade"-Skripte ergänzt – möglicher Grund, warum "Aktualisieren" im Paket-Zentrum bisher mit "Ungültiges Dateiformat" scheiterte, obwohl Neuinstallationen funktionierten (noch nicht auf echter Hardware bestätigt)',
        ],
    ],
    [
        'version' => 'v50',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Abrechnung: Bankverbindung, Zahlungsziel (Tage) und persönlicher Vorlagentext sind jetzt je Haus einstellbar (Einstellungen → Abrechnungs-Vorlage) und erscheinen auf der Nebenkostenabrechnung',
            'Fehler behoben: die PDF-Abrechnung zeigte bei mehreren Häusern immer die Verwalterdaten von Haus 1 – zeigt jetzt korrekt die Daten des Hauses, zu dem die jeweilige Abrechnung gehört',
        ],
    ],
    [
        'version' => 'v49',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Synology-Paket: Installations-/Update-Anleitung korrigiert – der Paketordner ist entgegen der vorherigen Anleitung NICHT über File Station erreichbar, sondern nur per SSH; auf echter Hardware getestet und bestätigt funktionierend',
        ],
    ],
    [
        'version' => 'v48',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Synology-Paket: "conf/resource" (automatische Web-Station-Registrierung) entfernt, da sich das Format als nicht funktionierend herausgestellt hat ("Ungültiges Dateiformat") – stattdessen liegen die Dateien nach der Installation in einem eigenen Paketordner bereit und werden per einmaligem manuellen Kopieren (File Station) in den Web-Ordner übernommen; funktioniert damit auch zum Aktualisieren einer bestehenden manuellen Installation',
        ],
    ],
    [
        'version' => 'v47',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Synology-Paket: eigene Datenbank/eigener Datenbank-Benutzer ("hausverwaltung_paket") statt "hausverwaltung" – vermeidet, dass der Einrichtungsschritt versehentlich in eine bestehende gleichnamige Produktivdatenbank auf demselben MariaDB-Server schreibt (kritischer Fix, betrifft nur die Synology-Paket-Installation)',
        ],
    ],
    [
        'version' => 'v46',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Backup: kann jetzt optional auch alle hochgeladenen Dateien (Verträge, Rechnungen, Übergabeprotokolle) und das Hausfoto mitsichern (als .zip statt .sql) – nützlich z.B. beim Umzug auf eine neue Installation',
        ],
    ],
    [
        'version' => 'v45',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Synology-Paket komplett auf DSM7-konforme Root-freie Installation umgebaut (läuft als eingeschränkter Paket-Nutzer statt root) – vorherige Version wurde von DSM 7 wegen Root-Rechten blockiert',
            'Achtung: dadurch legt das Paket jetzt eine eigenständige, neue Installation an (eigener Ordner unter web_packages, eigene Datenbank) statt eine bestehende manuelle Installation zu übernehmen',
        ],
    ],
    [
        'version' => 'v44',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Synology-Paket ist jetzt auch als Paketquelle verfügbar (Paket-Zentrum → Einstellungen → Paketquellen) – zeigt Updates direkt im Paket-Zentrum an, kein manueller Download mehr nötig',
            'Projekt liegt jetzt auf GitHub (öffentlich, ohne echte Zugangsdaten/Nutzdaten) – Grundlage für automatisierte Releases',
        ],
    ],
    [
        'version' => 'v43',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Update-Hinweis: prüft einmal täglich im Hintergrund, ob eine neuere Version verfügbar ist, und zeigt das Admins dezent neben der Versionsnummer an (kein automatischer Download, nur ein Hinweis)',
        ],
    ],
    [
        'version' => 'v42',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Lizenzvertrag (EULA.md) ergänzt – regelt Nutzungsumfang, Weitergabeverbot und Datenschutz-Verantwortung; wird bei der Synology-Paketinstallation automatisch als Lizenztext angezeigt',
        ],
    ],
    [
        'version' => 'v41',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Installer: Synology-Paket (.spk) für „Manuell installieren" im Paket-Zentrum sowie start.bat/start.sh für den lokalen Betrieb auf einem PC über Docker – siehe INSTALLATION.md',
            'Datenbank: mehrere Tabellen/Spalten, die produktiv schon genutzt wurden aber nie in eine Migrationsdatei geschrieben waren (Einreichungen, Versorger, Wartungsaufgaben, Kaution, Nachzahlungen, Mieter-Login-Felder), in sql/migration_fehlende_tabellen_und_spalten.sql nachgetragen',
            'config.php liest Datenbank-Zugangsdaten jetzt zuerst aus Umgebungsvariablen (für Docker), bestehende native Installation bleibt unverändert',
        ],
    ],
    [
        'version' => 'v40',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Rechnungen: beim normalen Erfassen können jetzt zusätzliche Positionen ergänzt werden, falls ein Teil der Rechnung nicht umlegbar ist (z.B. anteilige Reparatur) – die Hauptrechnung mit Umlage/Einzel/Gruppe-Zuordnung bleibt dabei unverändert',
        ],
    ],
    [
        'version' => 'v39',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Einreichung: Einreicher kann schon angeben, ob es sich um umlegbare Betriebskosten oder nicht umlegbare Eigentümerkosten handelt (unverbindliche Einschätzung)',
            'Freigabe: eine Einreichung lässt sich jetzt auf mehrere Positionen aufteilen – jede Position wird einzeln als umlegbare Rechnung (mit Kostenart) oder nicht umlegbare Eigentümerkosten (mit Kategorie) angelegt',
        ],
    ],
    [
        'version' => 'v38',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Benutzerverwaltung: neue Spalte „Online" zeigt, wer gerade aktiv ist (grüner Punkt) bzw. wann zuletzt',
        ],
    ],
    [
        'version' => 'v37',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Kosten-Tacho animiert jetzt beim Laden: Zeiger dreht langsam hoch, Bogen zeichnet sich mit, dabei leichter Neon-Glow in der Statusfarbe',
        ],
    ],
    [
        'version' => 'v36',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Neu: alle Auflistungen (Rechnungen, Dokumente, Wohnungen, Zählerstände, Benutzer, Audit-Log, u.v.m.) sind jetzt sortierbar per Klick auf die Spaltenüberschrift',
            'Neu: Lupen-Symbol über jeder Tabelle öffnet ein Suchfeld, das die Zeilen live filtert',
        ],
    ],
    [
        'version' => 'v35',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Bugfix: Zähler-Zähler „Anfangsstand (01.01.)" / „Endstand (31.12.)" waren fest auf das Kalenderjahr bezogen – zeigen jetzt das tatsächliche Wirtschaftsjahr des Hauses',
            'Nav-Zähler „Neue Einreichungen" zählte bisher über alle Häuser statt nur das aktive',
        ],
    ],
    [
        'version' => 'v34',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Neu: Kosten-Tacho je Wohnung – zeigt die bisherigen laufenden Kosten im Vergleich zur bisher geleisteten Vorauszahlung',
            'Mieter sehen ihren eigenen Tacho unter „Meine Wohnung", der Admin alle Wohnungen auf einen Blick unter „Wohnungen"',
            'Hinweis direkt am Tacho: enthält noch keine Heizkostenabrechnung, nur laufende Kosten seit Jahresbeginn',
            'Wirtschaftsjahr-Start jetzt pro Haus einstellbar (Einstellungen) – der Tacho rechnet ab diesem Datum statt fest ab dem 1. Januar',
        ],
    ],
    [
        'version' => 'v33',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Rolle „Leser" ist jetzt wirklich nur lesend – alle Anlege-/Änderungs-/Löschformulare und -Aktionen sind für sie serverseitig gesperrt, nicht nur im Menü versteckt',
            'CSRF-Schutz ergänzt: Abrechnung, Mieterwechsel und Backup (Erstellen/Hochladen/Wiederherstellen) hatten bislang keinen',
            'Neues Audit-Log: erfasst wer wann was angelegt, geändert oder gelöscht hat – einsehbar unter Einstellungen → Audit-Log',
        ],
    ],
    [
        'version' => 'v32',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Sicherheitsfix: 14 Verwaltungsseiten (u. a. Rechnungen, Wohnungen, Wirtschaftlichkeit, Kaution, Backup) prüften bisher nur den Login, nicht die Rolle – per direktem Aufruf waren sie auch für Mieter/Hausmeister erreichbar',
            'Backup-Seite (Datenbank-Export/-Wiederherstellung inkl. Passwort-Hashes) jetzt strikt auf Admin beschränkt',
        ],
    ],
    [
        'version' => 'v31',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Mehrere Immobilien (Schritt 3, letzte Seiten): Einreichungen und Freigabe sind jetzt ebenfalls je Haus getrennt',
            'Bugfix: Freigabe legte Rechnungen bisher ohne Hauszuordnung an',
        ],
    ],
    [
        'version' => 'v30',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Mehrere Immobilien (Schritt 2): Rücklagen, wiederkehrende Kosten, Kaution, NK-Zahlungen, Gutschriften und Wirtschaftlichkeit sind jetzt ebenfalls je Haus getrennt',
            'Dashboard, Vorauszahlungen und Mieterwechsel zeigen jetzt ebenfalls nur noch das gerade ausgewählte Haus',
            'Einstellungen: neue Immobilien lassen sich jetzt anlegen, deaktivieren und dort wechseln',
            'Einstellungen bearbeiten jetzt das gerade ausgewählte Haus statt immer Haus 1',
            'Zugriffstrennung: Hausmeister und Leser werden jetzt fest einer Immobilie zugeordnet und sehen ausschließlich deren Daten – nur der Admin kann zwischen Häusern wechseln',
        ],
    ],
    [
        'version' => 'v29',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Grundlage für mehrere Immobilien gelegt (Schritt 1 von mehreren)',
            'Objekt-Umschalter in der Leiste – erscheint, sobald ein zweites Haus angelegt ist',
            'Alle bestehenden Daten bleiben dem bisherigen Haus zugeordnet',
        ],
    ],
    [
        'version' => 'v28',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Versorger-Modul: Strom, Wasser und Abwasser mit Abschlägen und Jahresabrechnung',
            'Abschlagszahlungen erfassen und Summe je Jahr im Blick behalten',
            'Jahresabrechnung des Versorgers per Klick als umzulegende Rechnung übernehmen',
            'Wasser und Abwasser getrennt (verschiedene Kommunen), beide über die Wasserzähler verteilt',
        ],
    ],
    [
        'version' => 'v27',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Hausbild als Hintergrund der Startseite – in den Einstellungen hochladbar und löschbar',
            'Einstellungen: Login-Schutz, Admin-Beschränkung und CSRF-Schutz ergänzt',
        ],
    ],
    [
        'version' => 'v26',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Einreichungen: neuer Status „Überwiesen" für erstattete Auslagen',
            'Optionale Nachricht an den Einreicher bei Freigabe, Ablehnung und Überweisung',
            'Neu-Benachrichtigung: rotes Badge am Einreichungs-Icon bei Status-Updates',
        ],
    ],
    [
        'version' => 'v25',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Wartungsplan: konfigurierbare Wert-Abfrage beim Abhaken (z. B. Heizöl-Füllstand)',
            'Letzter gemeldeter Wert wird bei wiederkehrenden Aufgaben angezeigt',
            'Erledigte Aufgaben zeigen den eingetragenen Wert im Verlauf',
        ],
    ],
    [
        'version' => 'v24',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Dokumente: Wohnungszuordnung und Sichtbarkeit nachträglich direkt in der Liste änderbar',
            'Benutzerverwaltung: Rolle, Wohnung und Anzeigename bestehender Benutzer bearbeitbar',
            'Schutz: Admin kann sich nicht selbst die Admin-Rolle entziehen',
        ],
    ],
    [
        'version' => 'v23',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Wartungsplan: Aufgaben mit Fälligkeit und Wiederholung, Hausmeister hakt ab',
            'Wiederkehrende Aufgaben legen sich nach dem Abhaken automatisch neu an',
            'Dokumente können für Mieter (je Wohnung) oder den Hausmeister freigegeben werden',
            'Zähler für offene Wartungsaufgaben in der Navigationsleiste',
        ],
    ],
    [
        'version' => 'v22',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Sicherheitspaket: Dokumente und Rechnungsdateien nur noch mit Login abrufbar',
            'CSRF-Schutz für alle Formulare und Lösch-Aktionen',
            'Lösch-Aktionen von Links auf geschützte Formulare umgestellt',
            'Session-Cookie wird bei HTTPS nur noch verschlüsselt übertragen',
            'Neue Uploads erhalten nicht erratbare Dateinamen',
        ],
    ],
    [
        'version' => 'v21',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Freigabe-Zähler aktualisiert sich jetzt live (alle 60 Sekunden im Hintergrund)',
            'Sicherheitsfix: Login-Prüfung auf Dokumente-, Zähler- und Vorauszahlungsseite vor der Datenverarbeitung',
        ],
    ],
    [
        'version' => 'v20',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Mieter-Zugang: eigene Wohnung einsehen – Stammdaten, Vorauszahlungen, Zählerstände',
            'Jahresabrechnungen mit PDF-Download für den Mieter',
            'Mieter können Rechnungen einreichen (gleiche Freigabe wie beim Hausmeister)',
            'Benutzerverwaltung: Rolle „Mieter" mit Wohnungszuordnung',
        ],
    ],
    [
        'version' => 'v19',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Einladungslinks: neue Benutzer legen ihr Passwort selbst über einen Link fest',
            'Passwort-Zurücksetzen-Link pro Benutzer in der Benutzerverwaltung',
            'Rolle „Hausmeister" in der Benutzerverwaltung wählbar',
        ],
    ],
    [
        'version' => 'v18',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Hausmeister-Zugang: Rechnungen mit Betrag, Vermerk und Datei einreichen',
            'Neue Freigabe-Seite für den Admin – Betrag, Datum und Zuordnung anpassbar',
            'Zähler in der Navigationsleiste zeigt offene Einreichungen an',
            'Hausmeister sieht ausschließlich seine Einreichungsseite',
        ],
    ],
    [
        'version' => 'v17',
        'datum'   => 'Juli 2026',
        'punkte'  => [
            'Neue dunkle Navigationsleiste mit Icon-Menü und Tooltips',
            'Changelog als Popup über die Versionsnummer aufrufbar',
            'Darkmode als Option – umschaltbar über das Mond/Sonne-Symbol',
            'Diagramm-Balken mit Aufbau-Animation und Neon-Glow (neuer Standard)',
            'Login-Fehler behoben: Weiterleitung führte auf „indexphp" statt index.php',
        ],
    ],
    [
        'version' => 'v16',
        'datum'   => '2026',
        'punkte'  => [
            'Rücklagen-Seite korrigiert',
            'Wiederkehrende Kosten mit Betrag-pro-Wohnung-Verteilung',
        ],
    ],
    // Ältere Versionen bei Bedarf hier ergänzen …
];

// Versions-Badge = Version des neuesten Changelog-Eintrags
$appVersion = $changelog[0]['version'] ?? 'v17';

// Update-Hinweis: prüft höchstens 1x pro Tag (gecacht in einer Datei),
// mit kurzem Timeout, und scheitert immer lautlos - darf den Seitenaufbau
// nie verzögern oder blockieren. Nur für Admins relevant.
$updateVerfuegbar = null;
if (istAdmin() && defined('UPDATE_CHECK_URL') && UPDATE_CHECK_URL !== '') {
    $updateCacheDatei = UPLOAD_DIR . '.update_check.json';
    $updateCache = null;
    if (is_file($updateCacheDatei)) {
        $updateCache = json_decode((string)@file_get_contents($updateCacheDatei), true);
    }
    $jetzt = time();
    if (!is_array($updateCache) || ($jetzt - ($updateCache['geprueft_am'] ?? 0)) > 86400) {
        $updateCache = ['geprueft_am' => $jetzt, 'version' => null, 'hinweis' => ''];
        $kontext = stream_context_create(['http' => ['timeout' => 2], 'https' => ['timeout' => 2]]);
        $antwort = @file_get_contents(UPDATE_CHECK_URL, false, $kontext);
        if ($antwort !== false) {
            $daten = json_decode($antwort, true);
            if (is_array($daten) && !empty($daten['version'])) {
                $updateCache['version'] = $daten['version'];
                $updateCache['hinweis'] = $daten['hinweis'] ?? '';
            }
        }
        @file_put_contents($updateCacheDatei, json_encode($updateCache));
    }
    if (!empty($updateCache['version']) && (int)ltrim($updateCache['version'], 'v') > (int)ltrim($appVersion, 'v')) {
        $updateVerfuegbar = $updateCache;
    }
}

// Navigationspunkte: Datei => [Beschriftung, SVG-Icon, optionale Zusatzklasse]
$bp = $basePath ?? '';
$navItems = [
    ['index.php', 'Dashboard',
        '<path d="m3 9.5 9-7 9 7V20a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22v-8h6v8"/>', ''],
    ['wohnungen.php', 'Wohnungen',
        '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>', ''],
    ['rechnungen.php', 'Rechnungen',
        '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7.5h8M8 11.5h8M8 15.5h5"/>', ''],
    ['zaehler.php', 'Zählerstände',
        '<path d="M12 2.7s6.5 7.3 6.5 11.8a6.5 6.5 0 1 1-13 0C5.5 10 12 2.7 12 2.7Z"/>', ''],
    ['vorauszahlungen.php', 'Vorauszahlungen',
        '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>', ''],
    ['mieterwechsel.php', 'Mieterwechsel',
        '<path d="m8 3-4 4 4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/>', ''],
    ['kaution.php', 'Kaution',
        '<path d="M12 22s8-3.6 8-9.5V5l-8-3-8 3v7.5C4 18.4 12 22 12 22Z"/><path d="m9 11.5 2 2 4-4"/>', ''],
    ['gutschriften.php', 'Gutschriften',
        '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>', ''],
    ['wiederkehrende_kosten.php', 'Wiederk. Kosten',
        '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/>', ''],
    ['abrechnung.php', 'Abrechnung',
        '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M8.5 6.5h7"/><path d="M8.5 11h.01M12 11h.01M15.5 11h.01M8.5 14.5h.01M12 14.5h.01M15.5 14.5h.01M8.5 18h.01M12 18h.01M15.5 18h.01"/>', ''],
    ['nk_zahlungen.php', 'NK-Zahlungen',
        '<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2H5"/><circle cx="16.5" cy="13.5" r="1"/>', ''],
    ['wirtschaftlichkeit.php', 'Wirtschaftlichkeit',
        '<path d="M3 3v18h18"/><path d="M8 17v-5"/><path d="M13 17V8"/><path d="M18 17V5"/>', 'nav-icon-gold'],
    ['ruecklagen.php', 'Rücklagen',
        '<line x1="3" y1="22" x2="21" y2="22"/><line x1="6" y1="18.5" x2="6" y2="11"/><line x1="10" y1="18.5" x2="10" y2="11"/><line x1="14" y1="18.5" x2="14" y2="11"/><line x1="18" y1="18.5" x2="18" y2="11"/><path d="M12 2l9 5.5H3z"/>', ''],
    ['dokumente.php', 'Dokumente',
        '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9L9.2 3.9A2 2 0 0 0 7.5 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/>', ''],
    ['einstellungen.php', 'Einstellungen',
        '<circle cx="12" cy="12" r="3"/><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>', ''],
];

// ------------------------------------------------------------
// Navigation an die Rolle anpassen
// ------------------------------------------------------------
if (($user['rolle'] ?? '') === 'hausmeister') {
    // Hausmeister sieht Wartungsplan und Einreichungsseite
    $navItems = [
        ['wartung.php', 'Wartungsplan',
            '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 13.5 2 2 4-4"/>', ''],
        ['einreichung.php', 'Rechnung einreichen',
            '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>', ''],
    ];
} elseif (($user['rolle'] ?? '') === 'mieter') {
    // Mieter sieht seine Wohnung und die Einreichung
    $navItems = [
        ['meine_wohnung.php', 'Meine Wohnung',
            '<path d="m3 9.5 9-7 9 7V20a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22v-8h6v8"/>', ''],
        ['einreichung.php', 'Rechnung einreichen',
            '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>', ''],
    ];
} elseif (function_exists('istAdmin') && istAdmin()) {
    // Admin: Freigabe- und Wartungs-Seite direkt hinter den Rechnungen einfügen
    array_splice($navItems, 3, 0, [
        [
            'freigabe.php', 'Freigabe',
            '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/>', '',
        ],
        [
            'wartung.php', 'Wartungsplan',
            '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 13.5 2 2 4-4"/>', '',
        ],
        [
            'versorger.php', 'Versorger',
            '<path d="M18 16.5a3 3 0 1 0-6 0"/><path d="M15 13.5V9"/><path d="M4 22V10a2 2 0 0 1 2-2h4"/><path d="M10 22V6a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16"/><path d="M2 22h20"/><path d="M13 8h2M13 12h2"/>', '',
        ],
    ]);
}

// Offene Einreichungen zählen (für den Zähler am Freigabe-Icon) – nur fürs aktive Haus
$offeneEinreichungen = 0;
if (function_exists('istAdmin') && istAdmin() && isset($db)) {
    try {
        $stmtOe = $db->prepare("SELECT COUNT(*) FROM einreichungen WHERE status = 'eingereicht' AND objekt_id = ?");
        $stmtOe->execute([aktivesObjekt()]);
        $offeneEinreichungen = (int)$stmtOe->fetchColumn();
    } catch (Throwable $t) {
        // Tabelle existiert evtl. noch nicht – Zähler bleibt einfach aus
    }
}

// Ungelesene Status-Updates zu eigenen Einreichungen (Hausmeister/Mieter)
$ungeleseneInfos = 0;
if (isset($db) && in_array(($user['rolle'] ?? ''), ['hausmeister', 'mieter'], true)) {
    try {
        $stmtUi = $db->prepare("SELECT COUNT(*) FROM einreichungen WHERE benutzer_id = ? AND ungelesen = 1");
        $stmtUi->execute([$user['id']]);
        $ungeleseneInfos = (int)$stmtUi->fetchColumn();
    } catch (Throwable $t) {
        // Spalte/Tabelle existiert evtl. noch nicht – Zähler bleibt aus
    }
}

// Offene Wartungsaufgaben zählen (Zähler am Wartungs-Icon)
$offeneAufgaben = 0;
if (isset($db) && (($user['rolle'] ?? '') === 'hausmeister' || (function_exists('istAdmin') && istAdmin()))) {
    try {
        $offeneAufgaben = (int)$db->query(
            "SELECT COUNT(*) FROM wartungsaufgaben WHERE status = 'offen'"
        )->fetchColumn();
    } catch (Throwable $t) {
        // Tabelle existiert evtl. noch nicht – Zähler bleibt einfach aus
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?> – <?= APP_NAME ?></title>
    <script>
    // Darkmode sofort anwenden (vor dem ersten Rendern), damit nichts aufblitzt
    (function () {
        try {
            if (localStorage.getItem('hv-theme') === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        } catch (e) {}
    })();
    </script>
    <link rel="stylesheet" href="<?= $bp ?>assets/css/style.css?v=<?= urlencode($appVersion) ?>">
    <style>
    /* ============================================================
       Navigation Drawer + App Bar (Material Design 3)
       Eigene Klassennamen (.drawer…/.appbar…) – kollidiert nicht mit style.css
       ============================================================ */
    .app-shell { display: flex; min-height: 100vh; }

    .drawer {
        width: 280px; flex-shrink: 0;
        background: var(--card-bg);
        border-right: 1px solid var(--border);
        display: flex; flex-direction: column;
        position: sticky; top: 0; height: 100vh;
    }
    .drawer-head { padding: 1.1rem 1.2rem .9rem; flex-shrink: 0; }
    .drawer-brand { display: flex; align-items: center; gap: .6rem; font-weight: 500; font-size: 1.05rem; color: var(--text); }
    .drawer-brand svg { width: 26px; height: 26px; color: var(--primary); flex-shrink: 0; }
    .drawer-meta { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; margin-top: .55rem; }
    .drawer-version-badge {
        font-size: .68rem; font-weight: 600; font-family: inherit; color: var(--muted);
        background: var(--card-bg-high); border: 1px solid var(--border);
        border-radius: 999px; padding: .12rem .55rem; cursor: pointer;
        transition: color .15s, border-color .15s;
    }
    .drawer-version-badge:hover { color: var(--primary); border-color: var(--primary); }
    .drawer-house {
        margin-top: .65rem; display: flex; align-items: center; gap: .4rem;
        background: var(--card-bg-high); border: 1px solid var(--border); border-radius: 12px;
        padding: .35rem .5rem .35rem .7rem;
    }
    .drawer-house-icon { font-size: .9rem; }
    .drawer-house select {
        background: transparent; border: none; color: var(--text); font: inherit; font-size: .82rem;
        font-weight: 500; padding: .1rem; cursor: pointer; outline: none; flex: 1; min-width: 0;
    }
    .drawer-house select option { color: #000; }
    .drawer-house-name { font-size: .82rem; font-weight: 500; color: var(--muted); }

    .drawer-nav { list-style: none; padding: .5rem .75rem 1rem; flex: 1; min-height: 0; overflow-y: auto; }
    .drawer-nav li { margin: 0 0 2px; opacity: 0; animation: drawerItemIn .32s ease-out forwards; }
    .drawer-item {
        position: relative; display: flex; align-items: center; gap: .9rem;
        padding: .6rem .9rem; border-radius: 24px; color: var(--muted);
        text-decoration: none; font-size: .86rem; font-weight: 500;
        transition: background .15s, color .15s;
    }
    .drawer-item svg { width: 20px; height: 20px; flex-shrink: 0; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .drawer-item span.label { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .drawer-item:hover { background: var(--card-bg-high); color: var(--text); }
    .drawer-item.active { background: var(--primary-container); color: var(--on-primary-container); font-weight: 600; }
    .drawer-item-gold { color: #8a6300; }
    :root[data-theme="dark"] .drawer-item-gold { color: #ffb95c; }
    .drawer-item-gold:hover { background: var(--accent-container); color: var(--accent); }
    .drawer-item-gold.active { background: var(--accent-container); color: var(--accent); }
    .nav-count {
        min-width: 18px; height: 18px; padding: 0 5px; background: var(--danger); color: #fff;
        font-size: .66rem; font-weight: 700; line-height: 18px; text-align: center; border-radius: 999px; flex-shrink: 0;
    }

    @keyframes drawerItemIn { from { opacity: 0; transform: translateX(-6px); } to { opacity: 1; transform: translateX(0); } }
    @media (prefers-reduced-motion: reduce) {
        .drawer-nav li { opacity: 1; animation: none; }
        .drawer-item, .drawer-version-badge { transition: none; }
    }

    .drawer-foot { padding: .8rem 1.2rem 1.1rem; border-top: 1px solid var(--border); display: flex; align-items: center; gap: .6rem; flex-shrink: 0; }
    .drawer-avatar {
        width: 34px; height: 34px; border-radius: 999px; background: var(--primary); color: var(--on-primary);
        display: flex; align-items: center; justify-content: center; font-size: .78rem; font-weight: 600; flex-shrink: 0;
    }
    .drawer-user { min-width: 0; flex: 1; }
    .drawer-user-name { display: block; font-size: .82rem; font-weight: 500; color: var(--text); text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .drawer-user-name:hover { color: var(--primary); }
    .drawer-logout { font-size: .76rem; color: var(--muted); text-decoration: none; }
    .drawer-logout:hover { color: var(--danger); }

    /* App Bar */
    .app-col { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .appbar {
        height: 60px; flex-shrink: 0; display: flex; align-items: center; gap: .75rem;
        padding: 0 1.5rem; background: var(--bg); border-bottom: 1px solid var(--border);
        position: sticky; top: 0; z-index: 50;
    }
    .appbar-title { font-size: 1.1rem; font-weight: 500; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .appbar-spacer { flex: 1; }
    .appbar-icon-btn {
        width: 38px; height: 38px; border-radius: 999px; border: none; background: none; cursor: pointer;
        display: flex; align-items: center; justify-content: center; color: var(--muted); transition: background .15s, color .15s; flex-shrink: 0;
    }
    .appbar-icon-btn:hover { background: var(--card-bg-high); color: var(--text); }
    .appbar-icon-btn svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .appbar-menu-btn { display: none; }

    /* Changelog-Popup (M3 Dialog) */
    .changelog-overlay {
        position: fixed;
        inset: 0;
        z-index: 900;
        display: none;
        align-items: flex-start;
        justify-content: center;
        padding: 9vh 1rem 2rem;
        background: rgba(0,0,0,.4);
        backdrop-filter: blur(2px);
    }
    .changelog-overlay.open { display: flex; }
    .changelog-modal {
        width: 100%;
        max-width: 520px;
        max-height: 74vh;
        overflow-y: auto;
        background: var(--card-bg);
        color: var(--text);
        border-radius: 28px;
        box-shadow: 0 8px 28px rgba(0,0,0,.3);
        animation: changelogIn .18s ease-out;
    }
    @keyframes changelogIn { from { opacity: 0; transform: translateY(-6px); } }
    .changelog-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1.2rem 1.4rem .8rem;
        position: sticky;
        top: 0;
        background: var(--card-bg);
    }
    .changelog-head h2 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 500;
        color: var(--text);
    }
    .changelog-close {
        background: none;
        border: none;
        color: var(--muted);
        font-size: 1.3rem;
        line-height: 1;
        cursor: pointer;
        padding: 0.2rem 0.5rem;
        border-radius: 999px;
    }
    .changelog-close:hover { color: var(--text); background: var(--card-bg-high); }
    .changelog-body { padding: .4rem 1.4rem 1.4rem; }
    .changelog-entry { margin-bottom: 1.1rem; }
    .changelog-entry:last-child { margin-bottom: 0; }
    .changelog-version {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        margin-bottom: 0.4rem;
    }
    .changelog-version .cl-badge {
        font-size: 0.7rem;
        font-weight: 700;
        color: var(--primary);
        background: var(--primary-container);
        border-radius: 999px;
        padding: 0.08rem 0.6rem;
    }
    .changelog-version .cl-datum { font-size: 0.76rem; color: var(--muted); }
    .changelog-entry ul {
        margin: 0;
        padding-left: 1.15rem;
        font-size: 0.85rem;
        line-height: 1.55;
        color: var(--muted);
    }

    @media (max-width: 900px) {
        .drawer {
            position: fixed; left: 0; top: 0; z-index: 400;
            transform: translateX(-100%); transition: transform .2s;
            box-shadow: 0 0 30px rgba(0,0,0,.35);
        }
        .drawer.open { transform: translateX(0); }
        .appbar-menu-btn { display: flex; }
    }
    </style>
</head>
<body>
<div class="app-shell">
<nav class="drawer" id="drawer">
    <div class="drawer-head">
        <div class="drawer-brand">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3 2 11h3v9h5v-6h4v6h5v-9h3z"/></svg>
            <?= htmlspecialchars(APP_NAME) ?>
        </div>
        <div class="drawer-meta">
            <button type="button" class="drawer-version-badge" id="changelogBadge"
                    title="Changelog anzeigen" aria-haspopup="dialog"><?= htmlspecialchars($appVersion) ?></button>
            <?php if ($updateVerfuegbar): ?>
            <span class="badge badge-warning" title="<?= htmlspecialchars($updateVerfuegbar['hinweis'] ?? '') ?>">Update <?= htmlspecialchars($updateVerfuegbar['version']) ?></span>
            <?php endif; ?>
        </div>
        <?php if (istAdmin() && count($objektListe) > 1): ?>
        <div class="drawer-house">
            <span class="drawer-house-icon" aria-hidden="true">🏠</span>
            <select onchange="if(this.value)window.location='?objekt_wechsel='+this.value" aria-label="Immobilie wechseln">
                <?php foreach ($objektListe as $o): ?>
                <option value="<?= $o['id'] ?>"<?= (int)$o['id'] === aktivesObjekt() ? ' selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php elseif ($aktObjekt): ?>
        <div class="drawer-house"><span class="drawer-house-icon" aria-hidden="true">🏠</span><span class="drawer-house-name"><?= htmlspecialchars($aktObjekt['name']) ?></span></div>
        <?php endif; ?>
    </div>
    <ul class="drawer-nav">
        <?php foreach ($navItems as $i => [$file, $label, $icon, $extra]):
            $href   = $file === 'index.php' ? $bp . 'index.php' : $bp . 'pages/' . $file;
            $active = $currentPage === $file ? ' active' : '';
            $goldClass = $extra === 'nav-icon-gold' ? ' drawer-item-gold' : '';
        ?>
        <li style="animation-delay: <?= $i * 20 ?>ms">
            <a href="<?= $href ?>" class="drawer-item<?= $goldClass ?><?= $active ?>"
               data-nav="<?= htmlspecialchars($file) ?>" aria-label="<?= htmlspecialchars($label) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg>
                <span class="label"><?= htmlspecialchars($label) ?></span>
                <?php if ($file === 'freigabe.php' && $offeneEinreichungen > 0): ?>
                <span class="nav-count"><?= $offeneEinreichungen ?></span>
                <?php elseif ($file === 'wartung.php' && $offeneAufgaben > 0): ?>
                <span class="nav-count"><?= $offeneAufgaben ?></span>
                <?php elseif ($file === 'einreichung.php' && $ungeleseneInfos > 0): ?>
                <span class="nav-count"><?= $ungeleseneInfos ?></span>
                <?php endif; ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="drawer-foot">
        <div class="drawer-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($user['name'] ?? '?', 0, 1))) ?></div>
        <div class="drawer-user">
            <a href="<?= $bp ?>pages/passwort.php" class="drawer-user-name" title="Konto verwalten"><?= htmlspecialchars($user['name']) ?></a>
            <a href="<?= $bp ?>logout.php" class="drawer-logout">Abmelden</a>
        </div>
    </div>
</nav>
<div class="app-col">
<header class="appbar">
    <button type="button" class="appbar-icon-btn appbar-menu-btn" id="drawerToggle" aria-label="Menü">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <?php
    $aktivLabel = APP_NAME;
    foreach ($navItems as [$navFile, $navLabel]) {
        if ($navFile === $currentPage) { $aktivLabel = $navLabel; break; }
    }
    ?>
    <span class="appbar-title"><?= htmlspecialchars($aktivLabel) ?></span>
    <span class="appbar-spacer"></span>
    <button type="button" class="appbar-icon-btn" id="themeToggle" aria-label="Farbschema umschalten" title="Hell / Dunkel">
        <svg id="themeIconMoon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
        <svg id="themeIconSun" viewBox="0 0 24 24" aria-hidden="true" style="display:none"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
    </button>
</header>

<!-- Changelog-Popup -->
<div class="changelog-overlay" id="changelogOverlay" role="dialog" aria-modal="true" aria-labelledby="changelogTitle">
    <div class="changelog-modal">
        <div class="changelog-head">
            <h2 id="changelogTitle">Changelog – <?= htmlspecialchars(APP_NAME) ?></h2>
            <button type="button" class="changelog-close" id="changelogClose" aria-label="Schließen">&times;</button>
        </div>
        <div class="changelog-body">
            <?php foreach ($changelog as $eintrag): ?>
            <div class="changelog-entry">
                <div class="changelog-version">
                    <span class="cl-badge"><?= htmlspecialchars($eintrag['version']) ?></span>
                    <span class="cl-datum"><?= htmlspecialchars($eintrag['datum']) ?></span>
                </div>
                <ul>
                    <?php foreach ($eintrag['punkte'] as $punkt): ?>
                    <li><?= htmlspecialchars($punkt) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script>
(function () {
    var overlay = document.getElementById('changelogOverlay');
    var badge   = document.getElementById('changelogBadge');
    var close   = document.getElementById('changelogClose');
    if (!overlay || !badge) return;
    function open()  { overlay.classList.add('open'); }
    function hide()  { overlay.classList.remove('open'); }
    badge.addEventListener('click', open);
    close.addEventListener('click', hide);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) hide(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) hide();
    });
})();

// Mobile: Navigation Drawer per Menü-Button ein-/ausblenden
(function () {
    var toggle = document.getElementById('drawerToggle');
    var drawer = document.getElementById('drawer');
    if (!toggle || !drawer) return;
    toggle.addEventListener('click', function () { drawer.classList.toggle('open'); });
    document.addEventListener('click', function (e) {
        if (drawer.classList.contains('open') && !drawer.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
            drawer.classList.remove('open');
        }
    });
})();

// Darkmode-Umschalter – Wahl wird im Browser gespeichert (localStorage)
(function () {
    var toggle = document.getElementById('themeToggle');
    if (!toggle) return;
    var sun  = document.getElementById('themeIconSun');
    var moon = document.getElementById('themeIconMoon');
    var root = document.documentElement;

    function updateIcon() {
        var dark = root.getAttribute('data-theme') === 'dark';
        sun.style.display  = dark ? '' : 'none';
        moon.style.display = dark ? 'none' : '';
    }
    updateIcon();

    toggle.addEventListener('click', function () {
        var dark = root.getAttribute('data-theme') === 'dark';
        if (dark) {
            root.removeAttribute('data-theme');
        } else {
            root.setAttribute('data-theme', 'dark');
        }
        try { localStorage.setItem('hv-theme', dark ? 'light' : 'dark'); } catch (e) {}
        updateIcon();
    });
})();

// Live-Zähler: fragt alle 60 Sekunden nach offenen Einreichungen
// (nur wenn das Freigabe-Icon vorhanden ist, also nur beim Admin)
(function () {
    var freigabe = document.querySelector('a[data-nav="freigabe.php"]');
    if (!freigabe || !window.fetch) return;

    function setzeZaehler(anzahl) {
        var badge = freigabe.querySelector('.nav-count');
        if (anzahl > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'nav-count';
                freigabe.appendChild(badge);
            }
            badge.textContent = anzahl;
        } else if (badge) {
            badge.remove();
        }
    }

    function aktualisieren() {
        if (document.hidden) return; // im Hintergrund-Tab nicht abfragen
        fetch('<?= $bp ?>pages/einreichung_status.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d) setzeZaehler(d.offen); })
            .catch(function () { /* Netzwerkfehler ignorieren */ });
    }

    setInterval(aktualisieren, 60000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) aktualisieren();
    });
})();
</script>
<main class="container">
    <?php if (!empty($successMsg)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>
