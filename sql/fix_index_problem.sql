-- ============================================================
-- Fix: unique_abr Index lässt sich nicht löschen,
-- da er für den Foreign Key (wohnung_id) benötigt wird.
-- Lösung: Ersatz-Index anlegen, dann den alten entfernen.
-- ============================================================
USE hausverwaltung;

-- 1. Normalen (nicht-eindeutigen) Index für den Foreign Key anlegen
ALTER TABLE abrechnungen ADD INDEX idx_wohnung_id (wohnung_id);

-- 2. Jetzt kann der alte UNIQUE-Index entfernt werden
ALTER TABLE abrechnungen DROP INDEX unique_abr;
