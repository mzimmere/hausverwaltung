-- ============================================================
-- Migration: Fotoalbum (vom Vermieter verwaltet, je Foto gezielt
-- für bestimmte Wohnungen/Mieter freigebbar)
-- Wird automatisch von includes/migrationen.php angewendet - kein
-- manueller Schritt in phpMyAdmin nötig.
-- ============================================================

CREATE TABLE IF NOT EXISTS fotoalbum_kategorien (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    objekt_id   INT NOT NULL,
    bezeichnung VARCHAR(100) NOT NULL,
    sortierung  INT NOT NULL DEFAULT 0,
    erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (objekt_id) REFERENCES objekt(id)
);

CREATE TABLE IF NOT EXISTS fotoalbum_bilder (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    objekt_id       INT NOT NULL,
    kategorie_id    INT NOT NULL,
    bezeichnung     VARCHAR(255) NOT NULL,
    dateiname       VARCHAR(255) NOT NULL,
    hochgeladen_von INT NULL,
    hochgeladen_am  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (objekt_id) REFERENCES objekt(id),
    FOREIGN KEY (kategorie_id) REFERENCES fotoalbum_kategorien(id),
    FOREIGN KEY (hochgeladen_von) REFERENCES benutzer(id)
);

-- Sichtbarkeit je Foto: welche Wohnungen (= welcher Mieter) es sehen
-- dürfen. Kein Eintrag = für keinen Mieter sichtbar (nur Verwaltung).
CREATE TABLE IF NOT EXISTS fotoalbum_sichtbarkeit (
    bild_id    INT NOT NULL,
    wohnung_id INT NOT NULL,
    PRIMARY KEY (bild_id, wohnung_id),
    FOREIGN KEY (bild_id) REFERENCES fotoalbum_bilder(id) ON DELETE CASCADE,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id) ON DELETE CASCADE
);
