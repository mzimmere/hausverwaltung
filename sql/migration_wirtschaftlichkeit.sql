-- ============================================================
-- Migration: Mieteinnahmen & nicht umlegbare Kosten
-- (NUR für Eigentümer-Dashboard, NICHT Teil der Nebenkostenabrechnung)
-- Einmalig in phpMyAdmin ausführen
-- ============================================================
USE hausverwaltung;

-- Tatsächliche Mieteinnahmen (Kaltmiete, separat von Vorauszahlungen Nebenkosten)
CREATE TABLE IF NOT EXISTS mieteinnahmen (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id  INT NOT NULL,
    datum       DATE NOT NULL,
    betrag      DECIMAL(10,2) NOT NULL,
    jahr        INT NOT NULL,
    monat       TINYINT NOT NULL,        -- 1-12, für Monatsübersicht
    beschreibung VARCHAR(255),
    erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);
ALTER TABLE mieteinnahmen ADD INDEX idx_me_wohnung (wohnung_id);

-- Kategorien für nicht umlegbare Kosten (Eigentümerkosten)
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

-- Nicht umlegbare Kosten (gehen NICHT in die Nebenkostenabrechnung ein)
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
