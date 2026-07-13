-- ============================================================
-- Migration: Anpassbare Abrechnungs-Vorlage
-- (Bankverbindung, Zahlungsziel, persönlicher Vorlagentext)
-- Einmalig ausführen in phpMyAdmin oder MariaDB-Konsole
-- ============================================================

USE hausverwaltung;

ALTER TABLE objekt
    ADD COLUMN IF NOT EXISTS abrechnung_bank_inhaber VARCHAR(200) NULL,
    ADD COLUMN IF NOT EXISTS abrechnung_bank_iban VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS abrechnung_bank_bic VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS abrechnung_bank_name VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS abrechnung_zahlungsziel_tage INT NOT NULL DEFAULT 30,
    ADD COLUMN IF NOT EXISTS abrechnung_vorlagentext TEXT NULL;
