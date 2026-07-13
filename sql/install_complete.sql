-- ============================================================
-- install_complete.sql - Komplettes Schema für eine NEUE Installation
-- Automatisch zusammengestellt aus schema.sql + allen benötigten
-- Migrationen, in sicherer Reihenfolge. NICHT für bestehende
-- Installationen verwenden (dafür die einzelnen migration_*.sql
-- Dateien einzeln in phpMyAdmin ausführen, wie bisher).
--
-- Enthält bewusst NICHT: migration_login.sql, migration_zeitraum_vorlagen.sql,
-- migration_wirtschaftlichkeit.sql - deren Tabellen/Daten sind bereits
-- vollständig in schema.sql enthalten, ein erneutes Ausführen würde
-- Beispieldaten duplizieren.
-- ============================================================


-- ############################################################
-- Quelle: schema.sql
-- ############################################################
-- ============================================================
-- Hausverwaltung - Datenbankschema
-- Für 3-Parteien-Haus auf Synology NAS
-- ============================================================

CREATE DATABASE IF NOT EXISTS hausverwaltung
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE hausverwaltung;

-- ------------------------------------------------------------
-- Objektstammdaten
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS objekt (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL DEFAULT 'Mein 3-Parteien-Haus',
    strasse VARCHAR(200),
    plz VARCHAR(10),
    ort VARCHAR(100),
    baujahr INT,
    wohnflaeche_gesamt DECIMAL(8,2),
    verwalter_name VARCHAR(200),
    verwalter_strasse VARCHAR(200),
    verwalter_plz VARCHAR(10),
    verwalter_ort VARCHAR(100),
    verwalter_telefon VARCHAR(50),
    verwalter_email VARCHAR(150)
);

INSERT INTO objekt (name, wohnflaeche_gesamt, verwalter_name)
VALUES ('Mein 3-Parteien-Haus', 267.00, 'Hausverwaltung');

-- ------------------------------------------------------------
-- Umlageschlüssel
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS umlageschluessel (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bezeichnung VARCHAR(50) NOT NULL,
    code VARCHAR(30) NOT NULL UNIQUE
);

INSERT INTO umlageschluessel (bezeichnung, code) VALUES
('Wohnfläche', 'WOHNFLAECHE'),
('Gleiche Anteile', 'GLEICHANTEIL'),
('Verbrauch', 'VERBRAUCH'),
('Personen', 'PERSONEN');

-- ------------------------------------------------------------
-- Kostenarten
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kostenarten (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bezeichnung VARCHAR(100) NOT NULL,
    umlageschluessel_id INT NOT NULL,
    aktiv TINYINT(1) DEFAULT 1,
    FOREIGN KEY (umlageschluessel_id) REFERENCES umlageschluessel(id)
);

INSERT INTO kostenarten (bezeichnung, umlageschluessel_id) VALUES
('Grundsteuer',         1),
('Gebäudeversicherung', 1),
('Müllabfuhr',          2),
('Wasser/Abwasser',     3),
('Heizkosten',          3),
('Allgemeinstrom',      1),
('Hausmeister',         1),
('Aufzug',              2),
('Schornsteinfeger',    1),
('Straßenreinigung',    1);

-- ------------------------------------------------------------
-- Wohnungen
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wohnungen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bezeichnung VARCHAR(50) NOT NULL,
    etage VARCHAR(50),
    wohnflaeche DECIMAL(8,2) NOT NULL,
    mieter_name VARCHAR(200),
    mieter_seit DATE,
    personen INT DEFAULT 2,
    aktiv TINYINT(1) DEFAULT 1
);

INSERT INTO wohnungen (bezeichnung, etage, wohnflaeche, mieter_name, personen) VALUES
('EG', 'Erdgeschoss',   100.00, 'Familie Müller',  3),
('OG', 'Obergeschoss',  100.00, 'Familie Schmidt', 2),
('DG', 'Dachgeschoss',   67.00, 'Familie Meier',   2);

-- ------------------------------------------------------------
-- Rechnungen
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rechnungen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kostenart_id INT NOT NULL,
    wohnung_id INT NULL,           -- NULL = wird umgelegt; gesetzt = direkt dieser Wohnung zugeordnet
    datum DATE NOT NULL,
    betrag DECIMAL(10,2) NOT NULL,
    jahr INT NOT NULL,
    beschreibung VARCHAR(255),
    dateiname VARCHAR(255),
    FOREIGN KEY (kostenart_id) REFERENCES kostenarten(id),
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);

-- ------------------------------------------------------------
-- Wasserablesungen
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wasserablesungen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id INT NOT NULL,
    datum DATE NOT NULL,
    stand DECIMAL(10,3) NOT NULL,
    typ ENUM('Anfang','Ende') NOT NULL,
    jahr INT NOT NULL,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);

-- ------------------------------------------------------------
-- Heizkosten-Import (von externem Abrechnungsunternehmen)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS heizkosten_import (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id INT NOT NULL,
    jahr INT NOT NULL,
    betrag DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);

-- ------------------------------------------------------------
-- Vorauszahlungen
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vorauszahlungen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id INT NOT NULL,
    jahr INT NOT NULL,
    gueltig_von DATE NULL,
    gueltig_bis DATE NULL,
    monatlicher_abschlag DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id),
    UNIQUE KEY unique_vz (wohnung_id, jahr)
);

-- ------------------------------------------------------------
-- Abrechnungen (Jahresabschluss)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS abrechnungen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id INT NOT NULL,
    jahr INT NOT NULL,                    -- Anzeigejahr (für Filter/Sortierung)
    zeitraum_von DATE NOT NULL,           -- freier Abrechnungsbeginn
    zeitraum_bis DATE NOT NULL,           -- freies Abrechnungsende
    bezeichnung VARCHAR(100),             -- z.B. "Kalenderjahr 2026" oder "01.03.-28.02."
    gesamtkosten DECIMAL(10,2) NOT NULL DEFAULT 0,
    vorauszahlungen DECIMAL(10,2) NOT NULL DEFAULT 0,
    gutschrift DECIMAL(10,2) NOT NULL DEFAULT 0,
    saldo DECIMAL(10,2) NOT NULL DEFAULT 0,
    erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
    -- Kein UNIQUE mehr: mehrere Zeiträume pro Wohnung möglich (z.B. Wirtschaftsjahr + Sonderabrechnung)
);

-- ------------------------------------------------------------
-- Abrechnungspositionen
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS abrechnungspositionen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    abrechnung_id INT NOT NULL,
    kostenart_id INT,
    kostenart VARCHAR(100) NOT NULL,
    betrag DECIMAL(10,2) NOT NULL,
    zeitraum_von DATE NULL,
    zeitraum_bis DATE NULL,
    mieter_name VARCHAR(200) NULL,
    ist_gutschrift TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (abrechnung_id) REFERENCES abrechnungen(id),
    FOREIGN KEY (kostenart_id) REFERENCES kostenarten(id)
);

-- ------------------------------------------------------------
-- Dokument-Kategorien
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dokument_kategorien (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bezeichnung VARCHAR(100) NOT NULL
);

INSERT INTO dokument_kategorien (bezeichnung) VALUES
('Rechnung'),
('Abrechnung'),
('Vertrag'),
('Versicherung'),
('Sonstiges');

-- ------------------------------------------------------------
-- Dokumente
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dokumente (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kategorie_id INT,
    wohnung_id INT,
    bezeichnung VARCHAR(255) NOT NULL,
    dateiname VARCHAR(255) NOT NULL,
    jahr INT,
    hochgeladen_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kategorie_id) REFERENCES dokument_kategorien(id),
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);

-- ------------------------------------------------------------
-- Rücklagen
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ruecklagen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    datum DATE NOT NULL,
    betrag DECIMAL(10,2) NOT NULL,
    beschreibung VARCHAR(255),
    typ ENUM('Einlage','Entnahme') NOT NULL
);

-- ------------------------------------------------------------
-- Benutzer (Login)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS benutzer (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    benutzername VARCHAR(100) NOT NULL UNIQUE,
    passwort     VARCHAR(255) NOT NULL,  -- password_hash()
    name         VARCHAR(200),
    rolle        ENUM('admin','leser') DEFAULT 'admin',
    aktiv        TINYINT(1) DEFAULT 1,
    letzter_login DATETIME,
    erstellt_am  DATETIME DEFAULT CURRENT_TIMESTAMP
);
-- Standard-Admin: Benutzer "admin", Passwort "hausverwaltung"
-- (Bitte nach erstem Login ändern!)
INSERT IGNORE INTO benutzer (benutzername, passwort, name, rolle)
VALUES ('admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin');

-- Hinweis: Standard-Login
-- Benutzername: admin
-- Passwort:     hausverwaltung
-- Bitte nach dem ersten Login unter "Einstellungen → Passwort ändern" ändern!

-- ------------------------------------------------------------
-- Mieteinnahmen (NUR Eigentümer-Dashboard, nicht Teil der Nebenkostenabrechnung)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mieteinnahmen (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id  INT NOT NULL,
    datum       DATE NOT NULL,
    betrag      DECIMAL(10,2) NOT NULL,
    jahr        INT NOT NULL,
    monat       TINYINT NOT NULL,
    beschreibung VARCHAR(255),
    erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);

-- ------------------------------------------------------------
-- Eigentümerkosten-Kategorien (nicht umlegbar)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS eigentuemerkosten_kategorien (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    bezeichnung VARCHAR(100) NOT NULL
);

INSERT INTO eigentuemerkosten_kategorien (bezeichnung) VALUES
('Instandhaltung/Reparatur'),
('Verwaltergebühr'),
('Finanzierung/Zinsen'),
('Modernisierung'),
('Rechtsberatung/Steuerberatung'),
('Leerstand/Mietausfall'),
('Sonstiges (nicht umlegbar)');

-- ------------------------------------------------------------
-- Eigentümerkosten (NUR Eigentümer-Dashboard, NICHT umlegbar)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS eigentuemerkosten (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    kategorie_id  INT NOT NULL,
    datum         DATE NOT NULL,
    betrag        DECIMAL(10,2) NOT NULL,
    jahr          INT NOT NULL,
    beschreibung  VARCHAR(255),
    dateiname     VARCHAR(255),
    erstellt_am   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kategorie_id) REFERENCES eigentuemerkosten_kategorien(id)
);

-- ------------------------------------------------------------
-- Gutschriften / individuelle Rabatte je Wohnung
-- (z.B. Hausmeistertätigkeit, Treppenhausreinigung durch Mieter)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gutschriften (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id       INT NOT NULL,
    bezeichnung      VARCHAR(150) NOT NULL,
    betrag_pro_monat DECIMAL(10,2) NOT NULL,
    gueltig_von      DATE NOT NULL,
    gueltig_bis      DATE NULL,
    notiz            VARCHAR(255),
    aktiv            TINYINT(1) DEFAULT 1,
    erstellt_am      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);

-- ------------------------------------------------------------
-- Eigene Zeitraum-Vorlagen für die Schnellauswahl bei Abrechnungen
-- Einfaches Modell: feste Von-/Bis-Daten, die der Nutzer selbst pflegt
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zeitraum_vorlagen (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    label       VARCHAR(100) NOT NULL,
    von_datum   DATE NOT NULL,
    bis_datum   DATE NOT NULL,
    sortierung  INT NOT NULL DEFAULT 0,
    erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Beispiel-Vorlagen zum Start (Kalenderjahr Vorjahr / aktuelles Jahr)
INSERT INTO zeitraum_vorlagen (label, von_datum, bis_datum, sortierung) VALUES
(CONCAT('Kalenderjahr ', YEAR(CURDATE())-1), CONCAT(YEAR(CURDATE())-1,'-01-01'), CONCAT(YEAR(CURDATE())-1,'-12-31'), 1),
(CONCAT('Kalenderjahr ', YEAR(CURDATE())),   CONCAT(YEAR(CURDATE()),'-01-01'),   CONCAT(YEAR(CURDATE()),'-12-31'),   2);

-- ------------------------------------------------------------
-- Mehrfachzuordnung einer Rechnung auf mehrere Wohnungen
-- (Vorrang vor der einfachen rechnungen.wohnung_id Spalte)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rechnung_wohnungen (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    rechnung_id  INT NOT NULL,
    wohnung_id   INT NOT NULL,
    anteil       DECIMAL(6,4) NOT NULL,
    FOREIGN KEY (rechnung_id) REFERENCES rechnungen(id) ON DELETE CASCADE,
    FOREIGN KEY (wohnung_id)  REFERENCES wohnungen(id)
);

-- ------------------------------------------------------------
-- Wiederkehrende Gruppenkosten (z.B. Hausmeister, dauerhaft)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wiederkehrende_kosten (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    kostenart_id     INT NOT NULL,
    bezeichnung      VARCHAR(150) NOT NULL,
    betrag_pro_monat DECIMAL(10,2) NOT NULL,
    gueltig_von      DATE NOT NULL,
    gueltig_bis      DATE NULL,
    aktiv            TINYINT(1) DEFAULT 1,
    notiz            VARCHAR(255),
    erstellt_am      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kostenart_id) REFERENCES kostenarten(id)
);

CREATE TABLE IF NOT EXISTS wiederkehrende_kosten_wohnungen (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    wiederkehrende_kosten_id INT NOT NULL,
    wohnung_id               INT NOT NULL,
    anteil                   DECIMAL(6,4) NOT NULL,
    FOREIGN KEY (wiederkehrende_kosten_id) REFERENCES wiederkehrende_kosten(id) ON DELETE CASCADE,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);

-- ############################################################
-- Quelle: migration_mieterwechsel.sql
-- ############################################################
-- ============================================================
-- Migration: Mieterwechsel während des Jahres
-- Einmalig ausführen in phpMyAdmin oder MariaDB-Konsole
-- ============================================================

USE hausverwaltung;

-- Tabelle für Mieterwechsel (ein Eintrag pro Übergabe)
CREATE TABLE IF NOT EXISTS mieterwechsel (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id          INT NOT NULL,
    uebergabe_datum     DATE NOT NULL,           -- Tag der Übergabe (letzter Tag Alt-Mieter)
    -- Ausziehender Mieter
    mieter_alt_name     VARCHAR(200),
    mieter_alt_personen INT DEFAULT 2,
    -- Einziehender Mieter
    mieter_neu_name     VARCHAR(200),
    mieter_neu_personen INT DEFAULT 2,
    -- Zählerstand bei Übergabe (Wasser)
    zaehler_wasser      DECIMAL(10,3),
    -- Notizen (z.B. Übergabeprotokoll-Nummer)
    notiz               TEXT,
    erstellt_am         DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);

-- Vorauszahlungen brauchen jetzt auch Mieter-Info für getrennte Abrechnung
-- (optional: wird bei Mieterwechsel automatisch aufgeteilt)
ALTER TABLE vorauszahlungen
    ADD COLUMN IF NOT EXISTS mieter_name    VARCHAR(200) NULL,
    ADD COLUMN IF NOT EXISTS gueltig_ab     DATE NULL,
    ADD COLUMN IF NOT EXISTS gueltig_bis    DATE NULL;

-- Abrechnungspositionen: Zeitraum ergänzen für Mieterwechsel-Ausweis
ALTER TABLE abrechnungspositionen
    ADD COLUMN IF NOT EXISTS zeitraum_von   DATE NULL,
    ADD COLUMN IF NOT EXISTS zeitraum_bis   DATE NULL,
    ADD COLUMN IF NOT EXISTS mieter_name    VARCHAR(200) NULL;

-- ############################################################
-- Quelle: migration_fehlende_tabellen_und_spalten.sql
-- ############################################################
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

-- ############################################################
-- Quelle: migration_direktzuordnung.sql
-- ############################################################
-- ============================================================
-- Migration: Direkte Zuordnung von Rechnungen zu einzelnen Wohnungen
-- z.B. wenn jede Wohnung einen eigenen Grundsteuerbescheid bekommt
-- Einmalig in phpMyAdmin ausführen
-- ============================================================
USE hausverwaltung;

ALTER TABLE rechnungen
    ADD COLUMN IF NOT EXISTS wohnung_id INT NULL AFTER kostenart_id;

ALTER TABLE rechnungen
    ADD CONSTRAINT fk_rechnungen_wohnung
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id);

-- wohnung_id = NULL  → Rechnung wird wie bisher nach Umlageschlüssel verteilt
-- wohnung_id = X     → Rechnung wird komplett und ausschließlich Wohnung X zugeordnet

-- ############################################################
-- Quelle: migration_gruppenkosten.sql
-- ############################################################
-- ============================================================
-- Migration: Kosten für mehrere ausgewählte Wohnungen
-- (z.B. Hausmeister, Treppenhausreinigung für EG+OG, nicht DG)
--
-- Zwei Bausteine:
-- 1. Einzelne Rechnung auf mehrere frei wählbare Wohnungen verteilen
-- 2. Wiederkehrende Vereinbarung (läuft automatisch über mehrere Jahre)
-- Einmalig in phpMyAdmin ausführen
-- ============================================================
USE hausverwaltung;

-- ── Baustein 1: Verknüpfungstabelle für Mehrfachzuordnung ──────
-- Eine Rechnung kann über diese Tabelle mehreren Wohnungen mit
-- jeweils frei wählbarem Anteil zugeordnet werden.
-- Wenn ein Eintrag hier existiert, hat das Vorrang vor der einfachen
-- wohnung_id-Spalte in der Tabelle "rechnungen".
CREATE TABLE IF NOT EXISTS rechnung_wohnungen (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    rechnung_id  INT NOT NULL,
    wohnung_id   INT NOT NULL,
    anteil       DECIMAL(6,4) NOT NULL,   -- z.B. 0.5000 = 50 %, Summe aller Anteile einer Rechnung = 1.0
    FOREIGN KEY (rechnung_id) REFERENCES rechnungen(id) ON DELETE CASCADE,
    FOREIGN KEY (wohnung_id)  REFERENCES wohnungen(id)
);
ALTER TABLE rechnung_wohnungen ADD INDEX idx_rw_rechnung (rechnung_id);
ALTER TABLE rechnung_wohnungen ADD INDEX idx_rw_wohnung (wohnung_id);

-- ── Baustein 2: Wiederkehrende Gruppenkosten ───────────────────
-- Dauerhafte Vereinbarung, die sich die Abrechnung automatisch zieht,
-- ohne dass jeden Monat eine Rechnung erfasst werden muss.
CREATE TABLE IF NOT EXISTS wiederkehrende_kosten (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    kostenart_id     INT NOT NULL,
    bezeichnung      VARCHAR(150) NOT NULL,    -- z.B. "Hausmeister EG+OG"
    betrag_pro_monat DECIMAL(10,2) NOT NULL,
    gueltig_von      DATE NOT NULL,
    gueltig_bis      DATE NULL,                -- NULL = läuft weiter bis auf Widerruf
    aktiv            TINYINT(1) DEFAULT 1,
    notiz            VARCHAR(255),
    erstellt_am      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kostenart_id) REFERENCES kostenarten(id)
);

-- Welche Wohnungen mit welchem Anteil betroffen sind
CREATE TABLE IF NOT EXISTS wiederkehrende_kosten_wohnungen (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    wiederkehrende_kosten_id INT NOT NULL,
    wohnung_id             INT NOT NULL,
    anteil                 DECIMAL(6,4) NOT NULL,  -- frei wählbar, Summe = 1.0
    FOREIGN KEY (wiederkehrende_kosten_id) REFERENCES wiederkehrende_kosten(id) ON DELETE CASCADE,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);
ALTER TABLE wiederkehrende_kosten_wohnungen ADD INDEX idx_wkw_kosten (wiederkehrende_kosten_id);

-- ############################################################
-- Quelle: migration_gutschriften.sql
-- ############################################################
-- ============================================================
-- Migration: Individuelle Rabatte / Gutschriften je Wohnung
-- z.B. Hausmeistertätigkeit, Treppenhausreinigung durch Mieter selbst
-- Einmalig in phpMyAdmin ausführen
-- ============================================================
USE hausverwaltung;

-- Stammdaten: wiederkehrende oder einmalige Gutschriften je Wohnung
CREATE TABLE IF NOT EXISTS gutschriften (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id      INT NOT NULL,
    bezeichnung     VARCHAR(150) NOT NULL,        -- z.B. "Hausmeistertätigkeit"
    betrag_pro_monat DECIMAL(10,2) NOT NULL,       -- monatlicher Gutschriftsbetrag
    gueltig_von     DATE NOT NULL,
    gueltig_bis     DATE NULL,                     -- NULL = unbegrenzt / bis auf Widerruf
    notiz           VARCHAR(255),
    aktiv           TINYINT(1) DEFAULT 1,
    erstellt_am     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);
ALTER TABLE gutschriften ADD INDEX idx_gs_wohnung (wohnung_id);

-- Abrechnungen: Gutschriftsbetrag separat ausweisen (fließt NICHT in gesamtkosten ein,
-- sondern wird erst beim Saldo abgezogen → bleibt für Umlage auf andere Wohnungen neutral)
ALTER TABLE abrechnungen
    ADD COLUMN IF NOT EXISTS gutschrift DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER vorauszahlungen;

-- Abrechnungspositionen: Kennzeichnung ob es sich um eine Gutschrift handelt (für PDF-Darstellung)
ALTER TABLE abrechnungspositionen
    ADD COLUMN IF NOT EXISTS ist_gutschrift TINYINT(1) NOT NULL DEFAULT 0;

-- ############################################################
-- Quelle: migration_freier_zeitraum.sql
-- ############################################################
-- ============================================================
-- Migration: Freier Abrechnungszeitraum (statt nur Kalenderjahr)
-- Einmalig in phpMyAdmin ausführen
-- ============================================================
USE hausverwaltung;

-- Abrechnungen: echten Zeitraum statt nur "jahr" speichern
ALTER TABLE abrechnungen
    ADD COLUMN IF NOT EXISTS zeitraum_von DATE NULL AFTER jahr,
    ADD COLUMN IF NOT EXISTS zeitraum_bis DATE NULL AFTER zeitraum_von,
    ADD COLUMN IF NOT EXISTS bezeichnung  VARCHAR(100) NULL AFTER zeitraum_bis;

-- Bestehende Datensätze auf Kalenderjahr migrieren (Rückwärtskompatibilität)
UPDATE abrechnungen
SET zeitraum_von = CONCAT(jahr, '-01-01'),
    zeitraum_bis = CONCAT(jahr, '-12-31'),
    bezeichnung  = CONCAT('Kalenderjahr ', jahr)
WHERE zeitraum_von IS NULL;

-- Das alte UNIQUE (wohnung_id, jahr) entfernen – mehrere Zeiträume pro Jahr möglich
ALTER TABLE abrechnungen DROP INDEX IF EXISTS unique_abr;

-- Rechnungen: zusätzlich echtes Datum für flexible Zeiträume nutzen
-- (datum existiert schon – jahr bleibt für Schnellfilter erhalten)

-- Vorauszahlungen: Gültigkeitszeitraum statt nur Jahr
ALTER TABLE vorauszahlungen
    ADD COLUMN IF NOT EXISTS gueltig_von DATE NULL,
    ADD COLUMN IF NOT EXISTS gueltig_bis DATE NULL;

UPDATE vorauszahlungen
SET gueltig_von = CONCAT(jahr, '-01-01'),
    gueltig_bis = CONCAT(jahr, '-12-31')
WHERE gueltig_von IS NULL;

-- Wasserablesungen: Datum existiert schon (Spalte "datum"), jahr bleibt als Filter

-- ############################################################
-- Quelle: migration_mandantenfaehigkeit.sql
-- ############################################################
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

-- ############################################################
-- Quelle: migration_online_status.sql
-- ############################################################
-- ============================================================
-- Migration: Online-Status / letzte Aktivität je Benutzer
-- Einmalig ausführen in phpMyAdmin oder MariaDB-Konsole
--
-- Wird bei jedem Seitenaufruf eines eingeloggten Nutzers aktualisiert
-- (höchstens einmal pro Minute, siehe assets/header.php), damit der
-- Admin in der Benutzerverwaltung sehen kann, wer gerade online ist.
-- ============================================================

USE hausverwaltung;

ALTER TABLE benutzer
    ADD COLUMN IF NOT EXISTS letzte_aktivitaet DATETIME NULL;

-- ############################################################
-- Quelle: migration_einreichung_positionen.sql
-- ############################################################
-- ============================================================
-- Migration: Einreichungen – Art vorklassifizieren + bei Freigabe
-- in mehrere Positionen (umlegbar / nicht umlegbar) aufteilen können
-- Einmalig ausführen in phpMyAdmin oder MariaDB-Konsole
-- ============================================================

USE hausverwaltung;

-- Der Einreicher kann schon grob einschätzen, um was es sich handelt.
-- Reine Vorbelegung für die Freigabe, keine feste Zuordnung.
ALTER TABLE einreichungen
    ADD COLUMN IF NOT EXISTS art ENUM('umlegbar','nicht_umlegbar','unklar') NOT NULL DEFAULT 'unklar';

-- Nachvollziehbarkeit: eine Einreichung kann bei der Freigabe in mehrere
-- Positionen aufgeteilt werden (z.B. 300€ umlegbar + 150€ nicht umlegbar).
-- Jede Zeile hier verweist auf die dabei tatsächlich angelegte Rechnung
-- bzw. Eigentümerkosten-Position.
CREATE TABLE IF NOT EXISTS einreichung_positionen (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    einreichung_id INT NOT NULL,
    ziel_typ       ENUM('rechnung','eigentuemerkosten') NOT NULL,
    ziel_id        INT NOT NULL,
    betrag         DECIMAL(10,2) NOT NULL,
    erstellt_am    DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ep_einreichung (einreichung_id)
);
