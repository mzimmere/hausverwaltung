<?php
// Fuehrt neue sql/migration_*.sql-Dateien automatisch aus, damit nach einem
// Code-Update (z.B. per SSH-Sync oder Synology Package Center) nicht mehr
// zusaetzlich manuell ein SQL-Skript in phpMyAdmin eingespielt werden muss.
// Wird bei jedem Request aus config/database.php aufgerufen; bereits
// angewendete Dateien werden in schema_migrations gemerkt und uebersprungen.

// Migrationen, die auf bestehenden Installationen bereits manuell eingespielt
// wurden, BEVOR es diesen automatischen Runner gab. Manche dieser Dateien
// enthalten nicht absicherte Anweisungen (INSERT ohne IGNORE, ADD COLUMN/INDEX
// ohne IF NOT EXISTS) - ein erneutes Ausfuehren wuerde entweder Duplikate
// anlegen oder mit einem Fehler abbrechen. Deshalb werden sie beim allerersten
// Lauf nur als "bereits erledigt" vermerkt, nicht ausgefuehrt. Neue Dateien
// (nicht in dieser Liste) werden immer wirklich ausgefuehrt.
const MIGRATIONEN_BASELINE = [
    'migration_direktzuordnung.sql',
    'migration_einreichung_positionen.sql',
    'migration_fehlende_tabellen_und_spalten.sql',
    'migration_freier_zeitraum.sql',
    'migration_gruppenkosten.sql',
    'migration_gutschriften.sql',
    'migration_login.sql',
    'migration_mandantenfaehigkeit.sql',
    'migration_mieterwechsel.sql',
    'migration_online_status.sql',
    'migration_wirtschaftlichkeit.sql',
    'migration_zeitraum_vorlagen.sql',
];

function migrationen_ausfuehren(PDO $db): void
{
    try {
        $tabelleNeuAngelegt = $db->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn() === false;
        $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            dateiname      VARCHAR(255) PRIMARY KEY,
            angewendet_am  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        if ($tabelleNeuAngelegt) {
            $stmt = $db->prepare("INSERT IGNORE INTO schema_migrations (dateiname) VALUES (?)");
            foreach (MIGRATIONEN_BASELINE as $name) {
                $stmt->execute([$name]);
            }
        }

        $bereitsAngewendet = $db->query("SELECT dateiname FROM schema_migrations")
            ->fetchAll(PDO::FETCH_COLUMN);
        $bereitsAngewendet = array_flip($bereitsAngewendet);
    } catch (Throwable $e) {
        error_log('Migrationen: Vorbereitung fehlgeschlagen - ' . $e->getMessage());
        return;
    }

    $dateien = glob(__DIR__ . '/../sql/migration_*.sql') ?: [];
    sort($dateien);

    foreach ($dateien as $pfad) {
        $name = basename($pfad);
        if (isset($bereitsAngewendet[$name])) {
            continue;
        }

        try {
            $inhalt = file_get_contents($pfad);
            $statements = migrationen_in_statements_zerlegen($inhalt);

            foreach ($statements as $sql) {
                try {
                    $db->exec($sql);
                } catch (PDOException $e) {
                    // Spalte/Index/Tabelle/Eintrag existiert schon - kann bei
                    // teilweise manuell vorbereiteten Installationen passieren,
                    // ist kein echter Fehler.
                    if (in_array((int)$e->errorInfo[1], [1050, 1060, 1061, 1062], true)) {
                        continue;
                    }
                    throw $e;
                }
            }

            $stmt = $db->prepare("INSERT INTO schema_migrations (dateiname) VALUES (?)");
            $stmt->execute([$name]);
        } catch (Throwable $e) {
            // Nicht die Seite zum Absturz bringen - lieber die Funktion aus
            // dieser Migration bleibt vorerst unvollstaendig, als eine leere
            // Fehlerseite fuer die ganze Anwendung zu erzeugen.
            error_log("Migration $name fehlgeschlagen: " . $e->getMessage());
        }
    }
}

function migrationen_in_statements_zerlegen(string $inhalt): array
{
    $zeilen = preg_split('/\r\n|\r|\n/', $inhalt);
    $bereinigt = [];
    foreach ($zeilen as $zeile) {
        $trim = trim($zeile);
        if ($trim === '' || str_starts_with($trim, '--') || preg_match('/^USE\s+\S+;?$/i', $trim)) {
            continue;
        }
        $bereinigt[] = $zeile;
    }

    $statements = array_map('trim', explode(';', implode("\n", $bereinigt)));
    return array_filter($statements, fn($s) => $s !== '');
}
