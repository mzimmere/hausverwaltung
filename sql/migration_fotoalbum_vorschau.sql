-- ============================================================
-- Migration: Vorschaubild für Formate, die Browser nicht direkt
-- anzeigen können (HEIC/HEIF von iPhones, DNG-Rohdaten). Wird beim
-- Upload automatisch als JPEG erzeugt, falls der Server das
-- unterstützt (PHP-Erweiterung "imagick" mit HEIC/RAW-Unterstützung).
-- ============================================================

ALTER TABLE fotoalbum_bilder
    ADD COLUMN IF NOT EXISTS vorschau_dateiname VARCHAR(255) NULL;
