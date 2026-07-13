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
