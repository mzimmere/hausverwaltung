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
