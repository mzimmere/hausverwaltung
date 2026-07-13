<?php
/**
 * Backup & Datensicherung.
 * Exportiert die Datenbank rein über PHP/PDO (kein exec(), kein mysqldump nötig).
 * Das funktioniert zuverlässig auf Synology, wo exec() oft deaktiviert ist
 * oder mysqldump nicht im PATH des Webservers liegt.
 */
require_once '../config/config.php';
require_once '../config/auth.php';
requireLogin('../');
require_once '../config/database.php';

// Vollzugriff auf die gesamte Datenbank (inkl. Passwort-Hashes aller Nutzer
// und aller Häuser) – ausschließlich Admin, auch "Leser" bleibt hier draußen.
if (!istAdmin()) {
    header('Location: ../index.php');
    exit;
}

$pageTitle = 'Backup';
$basePath  = '../';

/**
 * Erstellt einen vollständigen SQL-Dump aller Tabellen rein über PDO.
 * Schreibt Struktur (CREATE TABLE) und Daten (INSERT) für jede Tabelle.
 *
 * $mitDateien = true: statt einer .sql wird eine .zip geschrieben, die
 * zusätzlich den kompletten uploads/-Ordner und das Hausfoto enthält.
 */
function erstelleBackup(PDO $db, string $zielDatei, bool $mitDateien = false): array
{
    $tabellen = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $sql = "-- Hausverwaltung Backup\n-- Erstellt: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tabellen as $tabelle) {
        // Struktur
        $create = $db->query("SHOW CREATE TABLE `$tabelle`")->fetch();
        $sql .= "DROP TABLE IF EXISTS `$tabelle`;\n";
        $sql .= $create['Create Table'] . ";\n\n";

        // Daten
        $rows = $db->query("SELECT * FROM `$tabelle`")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $spalten = array_keys($rows[0]);
            $spaltenListe = '`' . implode('`,`', $spalten) . '`';
            $sql .= "INSERT INTO `$tabelle` ($spaltenListe) VALUES\n";

            $zeilenSql = [];
            foreach ($rows as $row) {
                $werte = array_map(function ($wert) use ($db) {
                    if ($wert === null) return 'NULL';
                    return $db->quote($wert);
                }, $row);
                $zeilenSql[] = '(' . implode(',', $werte) . ')';
            }
            $sql .= implode(",\n", $zeilenSql) . ";\n\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    if (!$mitDateien) {
        $erfolg = file_put_contents($zielDatei, $sql);
        return [
            'erfolg' => $erfolg !== false,
            'tabellen' => count($tabellen),
            'groesse' => $erfolg !== false ? filesize($zielDatei) : 0,
        ];
    }

    $zip = new ZipArchive();
    if ($zip->open($zielDatei, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['erfolg' => false, 'tabellen' => count($tabellen), 'groesse' => 0];
    }
    $zip->addFromString('backup.sql', $sql);

    if (is_dir(UPLOAD_DIR)) {
        $eintraege = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(UPLOAD_DIR, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($eintraege as $datei) {
            $relativ = 'uploads/' . substr($datei->getPathname(), strlen(UPLOAD_DIR));
            $zip->addFile($datei->getPathname(), str_replace('\\', '/', $relativ));
        }
    }
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $bild = __DIR__ . '/../assets/haus.' . $ext;
        if (is_file($bild)) {
            $zip->addFile($bild, 'assets/haus.' . $ext);
            break;
        }
    }
    $zip->close();

    return [
        'erfolg' => is_file($zielDatei),
        'tabellen' => count($tabellen),
        'groesse' => is_file($zielDatei) ? filesize($zielDatei) : 0,
    ];
}

/**
 * Führt einen SQL-Dump (als String) aus, Statement für Statement.
 *
 * WICHTIG: CREATE/DROP TABLE lösen in MariaDB einen impliziten Commit aus
 * und können NICHT zurückgerollt werden. Die Transaktion schützt daher nur
 * die INSERT-Daten innerhalb einer bereits neu angelegten Tabelle, nicht
 * den Tabellenaufbau selbst. Deshalb wird vorher immer automatisch ein
 * Sicherheits-Backup angelegt, das im Fehlerfall manuell zurückgespielt
 * werden kann.
 */
function spieleSqlStringEin(PDO $db, string $inhalt): array
{
    // Statements anhand von ";\n" trennen – funktioniert zuverlässig mit den
    // eigenen Backups dieser App, da Werte über PDO::quote() escaped wurden.
    $statements = preg_split('/;\s*\n/', $inhalt);

    $ausgefuehrt = 0;
    try {
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) continue;
            $db->exec($stmt);
            $ausgefuehrt++;
        }
        return ['erfolg' => true, 'statements' => $ausgefuehrt];
    } catch (Exception $e) {
        return ['erfolg' => false, 'fehler' => $e->getMessage(), 'statements' => $ausgefuehrt];
    }
}

/** Räumt ein temporäres Verzeichnis samt Inhalt rekursiv weg. */
function loescheVerzeichnisRekursiv(string $pfad): void
{
    if (!is_dir($pfad)) return;
    $eintraege = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pfad, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($eintraege as $eintrag) {
        $eintrag->isDir() ? rmdir($eintrag->getPathname()) : unlink($eintrag->getPathname());
    }
    rmdir($pfad);
}

/** Kopiert ein Verzeichnis rekursiv (überschreibt vorhandene Dateien). */
function kopiereVerzeichnisRekursiv(string $von, string $nach): void
{
    if (!is_dir($nach)) mkdir($nach, 0777, true);
    $eintraege = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($von, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($eintraege as $eintrag) {
        $ziel = $nach . '/' . substr($eintrag->getPathname(), strlen($von) + 1);
        if ($eintrag->isDir()) {
            if (!is_dir($ziel)) mkdir($ziel, 0777, true);
        } else {
            copy($eintrag->getPathname(), $ziel);
        }
    }
}

/**
 * Spielt ein zuvor mit erstelleBackup() erzeugtes Backup wieder ein -
 * erkennt anhand der Dateiendung, ob es sich um eine reine .sql oder ein
 * .zip (inkl. Dateien) handelt.
 */
function spieleBackupEin(PDO $db, string $quellDatei): array
{
    if (str_ends_with(strtolower($quellDatei), '.zip')) {
        $zip = new ZipArchive();
        if ($zip->open($quellDatei) !== true) {
            return ['erfolg' => false, 'fehler' => 'ZIP-Datei konnte nicht geöffnet werden.'];
        }
        $sqlInhalt = $zip->getFromName('backup.sql');
        if ($sqlInhalt === false) {
            $zip->close();
            return ['erfolg' => false, 'fehler' => 'In dieser ZIP-Datei wurde keine backup.sql gefunden.'];
        }

        $ergebnis = spieleSqlStringEin($db, $sqlInhalt);
        if (!$ergebnis['erfolg']) {
            $zip->close();
            return $ergebnis;
        }

        $tempDir = sys_get_temp_dir() . '/hv_restore_' . uniqid();
        mkdir($tempDir, 0777, true);
        $zip->extractTo($tempDir);
        $zip->close();

        if (is_dir($tempDir . '/uploads')) {
            kopiereVerzeichnisRekursiv($tempDir . '/uploads', rtrim(UPLOAD_DIR, '/'));
        }
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $quelle = $tempDir . '/assets/haus.' . $ext;
            if (is_file($quelle)) {
                copy($quelle, __DIR__ . '/../assets/haus.' . $ext);
            }
        }
        loescheVerzeichnisRekursiv($tempDir);

        return $ergebnis;
    }

    $inhalt = file_get_contents($quellDatei);
    if ($inhalt === false) {
        return ['erfolg' => false, 'fehler' => 'Datei konnte nicht gelesen werden.'];
    }
    return spieleSqlStringEin($db, $inhalt);
}

// ── Externe Backup-Datei hochladen (z.B. von einem anderen Gerät) ──
if (isset($_POST['upload_backup']) && !empty($_FILES['backup_datei']['name'])) {
    csrfPruefen();
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0777, true);
    $zielName = date('Y-m-d_His') . '_hochgeladen_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['backup_datei']['name']);
    $zielPfad = BACKUP_DIR . $zielName;
    if (move_uploaded_file($_FILES['backup_datei']['tmp_name'], $zielPfad)) {
        protokolliere('backup', 'anlegen', null, "Backup-Datei \"$zielName\" hochgeladen");
        $successMsg = 'Datei "' . $zielName . '" hochgeladen. Sie können sie jetzt unten wiederherstellen.';
    } else {
        $errorMsg = 'Hochladen fehlgeschlagen.';
    }
}

if (isset($_POST['backup'])) {
    csrfPruefen();
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0777, true);
    $mitDateien = !empty($_POST['mit_dateien']);
    $endung = $mitDateien ? '.zip' : '.sql';
    $datei = BACKUP_DIR . date('Y-m-d_His') . '_hausverwaltung' . $endung;

    try {
        $result = erstelleBackup($db, $datei, $mitDateien);
        if ($result['erfolg']) {
            protokolliere('backup', 'anlegen', null, 'Backup erstellt: ' . basename($datei));
            $successMsg = 'Backup erstellt: ' . basename($datei)
                . ' (' . $result['tabellen'] . ' Tabellen, '
                . number_format($result['groesse']/1024, 1, ',', '.') . ' KB'
                . ($mitDateien ? ', inkl. Dateien' : '') . ')';
        } else {
            $errorMsg = 'Backup fehlgeschlagen: Datei konnte nicht geschrieben werden. '
                . 'Prüfen Sie die Schreibrechte des Verzeichnisses backups/.';
        }
    } catch (Exception $e) {
        $errorMsg = 'Backup fehlgeschlagen: ' . $e->getMessage();
    }
}

// ── Backup wiederherstellen (überschreibt alle aktuellen Daten!) ──
if (isset($_POST['restore'])) {
    csrfPruefen();
    $datei = BACKUP_DIR . basename($_POST['restore_datei']);
    $bestaetigung = trim($_POST['restore_bestaetigung'] ?? '');

    if ($bestaetigung !== 'WIEDERHERSTELLEN') {
        $errorMsg = 'Wiederherstellung abgebrochen: Bitte exakt "WIEDERHERSTELLEN" eingeben, um zu bestätigen.';
    } elseif (!file_exists($datei)) {
        $errorMsg = 'Backup-Datei nicht gefunden.';
    } else {
        // Sicherheitsnetz: vor dem Restore automatisch ein Backup des aktuellen Stands anlegen
        // (inkl. Dateien, damit bei einem ZIP-Restore auch die Uploads zurückgeholt werden können)
        $sicherungVorRestore = BACKUP_DIR . date('Y-m-d_His') . '_vor_wiederherstellung.zip';
        erstelleBackup($db, $sicherungVorRestore, true);

        $result = spieleBackupEin($db, $datei);
        if ($result['erfolg']) {
            protokolliere('backup', 'aendern', null, 'Backup "' . basename($datei) . '" wiederhergestellt (' . ($result['statements'] ?? 0) . ' Anweisungen)');
            $successMsg = 'Backup "' . basename($datei) . '" wurde erfolgreich wiederhergestellt ('
                . $result['statements'] . ' Anweisungen ausgeführt). '
                . 'Der vorherige Stand wurde zusätzlich gesichert als "' . basename($sicherungVorRestore) . '".';
        } else {
            $errorMsg = 'Wiederherstellung fehlgeschlagen nach ' . ($result['statements'] ?? 0) . ' Anweisungen: '
                . htmlspecialchars($result['fehler'] ?? 'Unbekannter Fehler')
                . ' Die Datenbank kann sich jetzt in einem unvollständigen Zwischenzustand befinden. '
                . 'Bitte spielen Sie zur Sicherheit das automatisch angelegte Backup "' . basename($sicherungVorRestore) . '" wieder ein, um den vorherigen Stand wiederherzustellen.';
        }
    }
}

if (isset($_GET['download'])) {
    $file = BACKUP_DIR . basename($_GET['download']);
    if (file_exists($file)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file); exit;
    }
}

if (isset($_GET['delete'])) {
    $file = BACKUP_DIR . basename($_GET['delete']);
    if (file_exists($file)) {
        unlink($file);
        protokolliere('backup', 'loeschen', null, 'Backup-Datei "' . basename($file) . '" gelöscht');
    }
    header('Location: backup.php'); exit;
}

// Backups auflisten
$backups = [];
if (is_dir(BACKUP_DIR)) {
    foreach (array_merge(glob(BACKUP_DIR . '*.sql'), glob(BACKUP_DIR . '*.zip')) as $f) {
        $backups[] = [
            'name' => basename($f),
            'size' => filesize($f),
            'datum' => filemtime($f),
            'mitDateien' => str_ends_with(strtolower($f), '.zip'),
        ];
    }
    usort($backups, fn($a,$b) => $b['datum'] - $a['datum']);
}

include '../assets/header.php';
?>
<div class="page-header"><h1>Backup &amp; Datensicherung</h1></div>

<div class="card">
    <h2>Neues Backup erstellen</h2>
    <p style="color:var(--muted);margin-bottom:1rem">
        Erstellt einen vollständigen Export aller Daten direkt über PHP (kein <code>mysqldump</code> nötig –
        funktioniert auch wenn <code>exec()</code> auf dem Server deaktiviert ist).
        Die Datei wird im Verzeichnis <code>backups/</code> gespeichert.
    </p>
    <form method="post">
        <?= csrfFeld() ?>
        <label style="display:flex;align-items:center;gap:.5rem;font-weight:400;margin-bottom:1rem;cursor:pointer">
            <input type="checkbox" name="mit_dateien" value="1">
            Auch hochgeladene Dateien (Verträge, Rechnungen, Übergabeprotokolle) und das Hausfoto ins Backup aufnehmen
            <span style="color:var(--muted);font-weight:400">(erzeugt eine .zip statt .sql, kann je nach Datenmenge etwas dauern)</span>
        </label>
        <button type="submit" name="backup" class="btn btn-success">💾 Backup jetzt erstellen</button>
    </form>
</div>

<div class="card">
    <h2>Backup-Datei hochladen</h2>
    <p style="color:var(--muted);margin-bottom:1rem">
        Falls Sie ein Backup von einem anderen Gerät wiederherstellen möchten, laden Sie die <code>.sql</code>-
        oder <code>.zip</code>-Datei hier hoch.
    </p>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
        <?= csrfFeld() ?>
        <input type="file" name="backup_datei" accept=".sql,.zip" required>
        <button type="submit" name="upload_backup" class="btn btn-primary">Hochladen</button>
    </form>
</div>

<div class="card">
    <h2>Vorhandene Backups (<?= count($backups) ?>)</h2>
    <?php if ($backups): ?>
    <div class="table-wrap"><table class="sortable">
        <thead><tr><th>Datei</th><th class="text-right">Größe</th><th>Erstellt</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
        <tr>
            <td>
                <?= htmlspecialchars($b['name']) ?>
                <?php if ($b['mitDateien']): ?><br><span class="badge badge-info" style="margin-top:.2rem">inkl. Dateien</span><?php endif; ?>
            </td>
            <td class="text-right"><?= number_format($b['size']/1024,1,',','.') ?> KB</td>
            <td><?= date('d.m.Y H:i', $b['datum']) ?></td>
            <td>
                <div class="btn-group">
                    <a href="?download=<?= urlencode($b['name']) ?>" class="btn btn-sm btn-primary">⬇ Download</a>
                    <button type="button" class="btn btn-sm btn-accent" onclick="restoreModalOeffnen('<?= htmlspecialchars($b['name'], ENT_QUOTES) ?>')">↺ Wiederherstellen</button>
                    <a href="?delete=<?= urlencode($b['name']) ?>"  class="btn btn-sm btn-danger"  onclick="return confirm('Backup löschen?')">✕</a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p style="color:var(--muted)">Noch keine Backups vorhanden.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Hinweise zur Wiederherstellung</h2>
    <ul style="color:var(--muted);font-size:.9rem;line-height:1.7;padding-left:1.2rem">
        <li>Eine Wiederherstellung <strong>überschreibt alle aktuellen Daten</strong> in der Datenbank
            (bei einem Backup „inkl. Dateien" zusätzlich auch die hochgeladenen Dateien und das Hausfoto).</li>
        <li>Vor jeder Wiederherstellung wird automatisch ein Sicherheits-Backup des aktuellen Stands
            angelegt (inkl. Dateien, unabhängig davon, ob das wiederherzustellende Backup selbst Dateien enthält).</li>
        <li>Bei einem Fehler mitten in der Wiederherstellung kann die Datenbank in einem unvollständigen
            Zwischenzustand verbleiben. Spielen Sie in diesem Fall das automatisch erstellte Sicherheits-Backup
            erneut über diese Seite ein, um den vorherigen Stand wiederherzustellen.</li>
        <li>Eine reine <code>.sql</code>-Datei kann alternativ auch klassisch über phpMyAdmin →
            „Importieren" eingespielt werden. Eine <code>.zip</code>-Datei („inkl. Dateien") funktioniert
            nur über diese Seite hier, da phpMyAdmin keine Dateien auspacken kann.</li>
    </ul>
</div>

<!-- ── Restore-Bestätigungsmodal ── -->
<div id="restoreModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:10px;max-width:480px;width:90%;padding:1.75rem;box-shadow:0 10px 40px rgba(0,0,0,.3)">
        <h2 style="color:var(--danger);margin-bottom:1rem">⚠️ Backup wiederherstellen</h2>
        <p style="margin-bottom:1rem">
            Sie sind dabei, <strong id="restoreDateiName"></strong> wiederherzustellen.
            <strong>Alle aktuellen Daten werden dabei überschrieben.</strong>
        </p>
        <p style="margin-bottom:1rem;color:var(--muted);font-size:.9rem">
            Ein Sicherheits-Backup des aktuellen Stands wird automatisch vorher angelegt
            (falls etwas schiefgeht, kann dieses erneut eingespielt werden).
        </p>
        <form method="post">
            <?= csrfFeld() ?>
            <input type="hidden" name="restore_datei" id="restoreDateiInput">
            <div class="form-group" style="margin-bottom:1rem">
                <label>Zum Bestätigen geben Sie <strong>WIEDERHERSTELLEN</strong> ein:</label>
                <input type="text" name="restore_bestaetigung" autocomplete="off" required>
            </div>
            <div class="btn-group">
                <button type="submit" name="restore" class="btn btn-danger">Jetzt wiederherstellen</button>
                <button type="button" class="btn btn-sm" style="background:#e2e8f0" onclick="restoreModalSchliessen()">Abbrechen</button>
            </div>
        </form>
    </div>
</div>

<script>
function restoreModalOeffnen(dateiname) {
    document.getElementById('restoreDateiName').textContent = dateiname;
    document.getElementById('restoreDateiInput').value = dateiname;
    document.getElementById('restoreModal').style.display = 'flex';
}
function restoreModalSchliessen() {
    document.getElementById('restoreModal').style.display = 'none';
}
</script>

<?php include '../assets/footer.php'; ?>
