-- ============================================================
-- Migration: Nachtrag fehlender Tabellen/Spalten
-- Einmalig ausführen in phpMyAdmin oder MariaDB-Konsole
--
-- Diese Tabellen/Spalten sind auf der produktiven Installation
-- bereits im Einsatz (Einreichungen, Versorger, Wartungsaufgaben,
-- Kaution, Nachzahlungen/Erstattungen, Mieter-Login, Zählerstände
-- bei Mieterwechsel), wurden damals aber direkt in der Datenbank
-- angelegt statt über eine eigene Migrationsdatei - sie fehlten
-- dadurch bisher im sql/-Ordner. Für neue Installationen
-- (Synology-Paket, Docker) wird das hier nachgeholt. Auf der
-- bestehenden Installation ist dies dank IF NOT EXISTS ein
-- reines No-Op.
--
-- WICHTIG:
-- - Nach migration_mieterwechsel.sql ausführen (erweitert dessen
--   Tabelle "mieterwechsel" um weitere Spalten).
-- - Vor migration_mandantenfaehigkeit.sql und vor
--   migration_einreichung_positionen.sql ausführen, da beide u.a.
--   die Tabelle "einreichungen" per ALTER TABLE erweitern.
-- ============================================================

USE hausverwaltung;

-- ── Rechnungseinreichung durch Hausmeister/Mieter ──────────────
CREATE TABLE IF NOT EXISTS einreichungen (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    benutzer_id        INT NOT NULL,
    datum              DATE NOT NULL,
    betrag_eingereicht DECIMAL(10,2) NOT NULL,
    vermerk            VARCHAR(500) NOT NULL DEFAULT '',
    dateipfad          VARCHAR(300) NOT NULL,
    original_name      VARCHAR(255) NOT NULL DEFAULT '',
    status             ENUM('eingereicht','freigegeben','ueberwiesen','abgelehnt') NOT NULL DEFAULT 'eingereicht',
    ablehnungsgrund    VARCHAR(500) NOT NULL DEFAULT '',
    rechnung_id        INT NULL,
    eingereicht_am     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    bearbeitet_am      TIMESTAMP NULL DEFAULT NULL,
    nachricht          VARCHAR(500) NOT NULL DEFAULT '',
    ungelesen          TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_einreichung_status (status),
    KEY fk_einreichung_benutzer (benutzer_id),
    KEY fk_einreichung_rechnung (rechnung_id),
    CONSTRAINT fk_einreichung_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer(id),
    CONSTRAINT fk_einreichung_rechnung FOREIGN KEY (rechnung_id) REFERENCES rechnungen(id) ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4;

-- ── Benutzer: Mieter/Hausmeister-Login + Selbst-Registrierung ──
ALTER TABLE benutzer
    MODIFY COLUMN rolle VARCHAR(20) NOT NULL DEFAULT 'leser';
ALTER TABLE benutzer
    ADD COLUMN IF NOT EXISTS wohnung_id INT NULL,
    ADD COLUMN IF NOT EXISTS setup_token VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS setup_gueltig_bis DATETIME NULL,
    ADD KEY IF NOT EXISTS fk_benutzer_wohnung (wohnung_id),
    ADD KEY IF NOT EXISTS idx_benutzer_setup_token (setup_token);
-- FK erst nach den Spalten setzen (falls sie neu angelegt wurden)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'benutzer' AND CONSTRAINT_NAME = 'fk_benutzer_wohnung');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE benutzer ADD CONSTRAINT fk_benutzer_wohnung FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Mieterwechsel: zusätzliche Zählerstände + Protokoll-Datei ──
ALTER TABLE mieterwechsel
    ADD COLUMN IF NOT EXISTS protokoll_dateiname VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS zaehler_kaltwasser DECIMAL(10,3) NULL,
    ADD COLUMN IF NOT EXISTS zaehler_warmwasser DECIMAL(10,3) NULL,
    ADD COLUMN IF NOT EXISTS zaehler_strom DECIMAL(10,3) NULL;

-- ── Nachzahlungen/Erstattungen aus der Jahresabrechnung ────────
CREATE TABLE IF NOT EXISTS nk_zahlungen (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    abrechnung_id  INT NOT NULL,
    wohnung_id     INT NOT NULL,
    typ            ENUM('Nachzahlung','Erstattung') NOT NULL,
    datum          DATE NOT NULL,
    betrag         DECIMAL(10,2) NOT NULL,
    beschreibung   VARCHAR(255) NULL,
    erstellt_am    DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_nkz_abrechnung (abrechnung_id),
    KEY idx_nkz_wohnung (wohnung_id),
    CONSTRAINT nk_zahlungen_ibfk_1 FOREIGN KEY (abrechnung_id) REFERENCES abrechnungen(id),
    CONSTRAINT nk_zahlungen_ibfk_2 FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
) DEFAULT CHARSET=utf8mb3;

-- ── Versorger / Energieanbieter-Verwaltung ─────────────────────
CREATE TABLE IF NOT EXISTS versorger (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150) NOT NULL,
    sparte        ENUM('strom','wasser','abwasser','sonstiges') NOT NULL DEFAULT 'sonstiges',
    kostenart_id  INT NULL,
    rhythmus      ENUM('monatlich','vierteljaehrlich','jaehrlich') NOT NULL DEFAULT 'monatlich',
    abschlag      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    kundennummer  VARCHAR(80) NOT NULL DEFAULT '',
    notiz         VARCHAR(300) NOT NULL DEFAULT '',
    aktiv         TINYINT(1) NOT NULL DEFAULT 1,
    erstellt_am   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY fk_versorger_kostenart (kostenart_id),
    CONSTRAINT fk_versorger_kostenart FOREIGN KEY (kostenart_id) REFERENCES kostenarten(id) ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS versorger_abrechnungen (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    versorger_id    INT NOT NULL,
    zeitraum_von    DATE NOT NULL,
    zeitraum_bis    DATE NOT NULL,
    gesamtkosten    DECIMAL(10,2) NOT NULL,
    verbrauch       VARCHAR(80) NOT NULL DEFAULT '',
    abschlaege      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notiz           VARCHAR(300) NOT NULL DEFAULT '',
    rechnung_id     INT NULL,
    uebernommen_am  DATETIME NULL,
    erstellt_am     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY fk_vabr_versorger (versorger_id),
    KEY fk_vabr_rechnung (rechnung_id),
    CONSTRAINT fk_vabr_versorger FOREIGN KEY (versorger_id) REFERENCES versorger(id) ON DELETE CASCADE,
    CONSTRAINT fk_vabr_rechnung FOREIGN KEY (rechnung_id) REFERENCES rechnungen(id) ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS versorger_zahlungen (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    versorger_id  INT NOT NULL,
    datum         DATE NOT NULL,
    betrag        DECIMAL(10,2) NOT NULL,
    notiz         VARCHAR(200) NOT NULL DEFAULT '',
    erstellt_am   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY fk_zahlung_versorger (versorger_id),
    CONSTRAINT fk_zahlung_versorger FOREIGN KEY (versorger_id) REFERENCES versorger(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4;

-- ── Wartungsaufgaben (wiederkehrende Erinnerungen) ─────────────
CREATE TABLE IF NOT EXISTS wartungsaufgaben (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    titel             VARCHAR(200) NOT NULL,
    beschreibung      VARCHAR(1000) NOT NULL DEFAULT '',
    faellig_am        DATE NULL,
    intervall_monate  INT NOT NULL DEFAULT 0,
    status            ENUM('offen','erledigt') NOT NULL DEFAULT 'offen',
    erledigt_am       DATETIME NULL,
    erledigt_von      INT NULL,
    bemerkung         VARCHAR(500) NOT NULL DEFAULT '',
    erstellt_am       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    wert_label        VARCHAR(100) NOT NULL DEFAULT '',
    wert              VARCHAR(100) NOT NULL DEFAULT '',
    KEY idx_wartung_status (status),
    KEY fk_wartung_benutzer (erledigt_von),
    CONSTRAINT fk_wartung_benutzer FOREIGN KEY (erledigt_von) REFERENCES benutzer(id)
) DEFAULT CHARSET=utf8mb4;

-- ── Kaution ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS kautionen (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id        INT NOT NULL,
    mieter_name       VARCHAR(200) NOT NULL,
    betrag            DECIMAL(10,2) NOT NULL,
    datum_einzahlung  DATE NOT NULL,
    anlageform        VARCHAR(150) NULL,
    notiz             VARCHAR(255) NULL,
    status            ENUM('aktiv','abgerechnet') NOT NULL DEFAULT 'aktiv',
    erstellt_am       DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_kaution_wohnung (wohnung_id),
    CONSTRAINT kautionen_ibfk_1 FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
) DEFAULT CHARSET=utf8mb3;

CREATE TABLE IF NOT EXISTS kautionsabrechnung (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    kaution_id        INT NOT NULL,
    datum_abrechnung  DATE NOT NULL,
    betrag_zurueck    DECIMAL(10,2) NOT NULL,
    notiz             VARCHAR(255) NULL,
    erstellt_am       DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY kaution_id (kaution_id),
    CONSTRAINT kautionsabrechnung_ibfk_1 FOREIGN KEY (kaution_id) REFERENCES kautionen(id)
) DEFAULT CHARSET=utf8mb3;

CREATE TABLE IF NOT EXISTS kaution_einbehalte (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    kautionsabrechnung_id    INT NOT NULL,
    bezeichnung              VARCHAR(255) NOT NULL,
    betrag                   DECIMAL(10,2) NOT NULL,
    KEY kautionsabrechnung_id (kautionsabrechnung_id),
    CONSTRAINT kaution_einbehalte_ibfk_1 FOREIGN KEY (kautionsabrechnung_id) REFERENCES kautionsabrechnung(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb3;
