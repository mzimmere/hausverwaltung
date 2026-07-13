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
