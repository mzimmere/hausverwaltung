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
