-- ============================================================
-- Migration: Freigabe einzelner Dokumentensafe-Einträge an den
-- Vermieter (standardmäßig bleibt alles privat für den Mieter -
-- nur explizit freigegebene Dokumente werden für die Verwaltung
-- sichtbar, siehe pages/dokumente.php).
-- ============================================================

ALTER TABLE dokumentensafe
    ADD COLUMN IF NOT EXISTS freigegeben TINYINT(1) NOT NULL DEFAULT 0;
