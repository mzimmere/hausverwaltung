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
