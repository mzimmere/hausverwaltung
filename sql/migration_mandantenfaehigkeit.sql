-- ============================================================
-- Migration: Mandantenfähigkeit (mehrere Objekte/Häuser)
-- Einmalig ausführen in phpMyAdmin oder MariaDB-Konsole
--
-- Ergänzt objekt_id auf allen Tabellen, die pro Haus getrennt
-- geführt werden. Bestandsdaten bekommen DEFAULT 1, damit sie
-- automatisch dem ursprünglichen Haus (objekt.id = 1) zugeordnet
-- bleiben. ADD COLUMN IF NOT EXISTS macht das Skript gefahrlos
-- wiederholbar, falls einzelne Spalten schon manuell angelegt
-- wurden.
-- ============================================================

USE hausverwaltung;

-- assets/header.php fragt bei jedem Seitenaufruf `objekt.aktiv` und
-- `objekt.sortierung` ab (für den Objekt-Umschalter) – beide Spalten
-- fehlten bislang komplett.
ALTER TABLE objekt
    ADD COLUMN IF NOT EXISTS aktiv TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS sortierung INT NOT NULL DEFAULT 0;
UPDATE objekt SET sortierung = id WHERE sortierung = 0;

-- Wirtschaftsjahr-Start je Haus (Standard 1. Januar = Kalenderjahr).
-- Wird vom Kosten-Tacho genutzt, um den "laufenden Zeitraum seit
-- Wirtschaftsjahresbeginn" korrekt zu bestimmen, statt fest den 1.1. anzunehmen.
ALTER TABLE objekt
    ADD COLUMN IF NOT EXISTS wirtschaftsjahr_start_monat TINYINT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS wirtschaftsjahr_start_tag   TINYINT NOT NULL DEFAULT 1;

ALTER TABLE wohnungen
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_wohnungen_objekt (objekt_id);

ALTER TABLE rechnungen
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_rechnungen_objekt (objekt_id);

ALTER TABLE versorger
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_versorger_objekt (objekt_id);

ALTER TABLE zeitraum_vorlagen
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_zeitraum_vorlagen_objekt (objekt_id);

ALTER TABLE abrechnungen
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_abrechnungen_objekt (objekt_id);

ALTER TABLE dokumente
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_dokumente_objekt (objekt_id);

ALTER TABLE wartungsaufgaben
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_wartung_objekt (objekt_id);

ALTER TABLE ruecklagen
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_ruecklagen_objekt (objekt_id);

ALTER TABLE wiederkehrende_kosten
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_wiederkehrende_kosten_objekt (objekt_id);

ALTER TABLE kautionen
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_kautionen_objekt (objekt_id);

ALTER TABLE gutschriften
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_gutschriften_objekt (objekt_id);

ALTER TABLE mieteinnahmen
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_mieteinnahmen_objekt (objekt_id);

ALTER TABLE nk_zahlungen
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_nk_zahlungen_objekt (objekt_id);

ALTER TABLE eigentuemerkosten
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_eigentuemerkosten_objekt (objekt_id);

ALTER TABLE einreichungen
    ADD COLUMN IF NOT EXISTS objekt_id INT NOT NULL DEFAULT 1,
    ADD KEY IF NOT EXISTS idx_einreichungen_objekt (objekt_id);

-- Zugriffstrennung: Hausmeister und Leser werden fest einem Haus
-- zugeordnet (NULL = Admin, uneingeschränkt/umschaltbar). Mieter
-- brauchen keine eigene Spalte – ihr Haus ergibt sich automatisch
-- aus wohnungen.objekt_id über ihre zugeordnete Wohnung.
ALTER TABLE benutzer
    ADD COLUMN IF NOT EXISTS objekt_id INT NULL,
    ADD KEY IF NOT EXISTS idx_benutzer_objekt (objekt_id);
UPDATE benutzer SET objekt_id = 1 WHERE rolle IN ('hausmeister','leser') AND objekt_id IS NULL;

-- Audit-Log: wer hat wann was geändert/gelöscht (wichtig sobald mehr
-- als eine Person Zugriff auf Finanzdaten hat).
CREATE TABLE IF NOT EXISTS audit_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    objekt_id     INT NOT NULL,
    benutzer_id   INT NULL,
    benutzer_name VARCHAR(200) NOT NULL DEFAULT '',
    bereich       VARCHAR(50) NOT NULL,
    aktion        VARCHAR(20) NOT NULL,
    datensatz_id  INT NULL,
    beschreibung  VARCHAR(500) NOT NULL DEFAULT '',
    erstellt_am   DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_objekt (objekt_id),
    KEY idx_audit_erstellt (erstellt_am)
);
