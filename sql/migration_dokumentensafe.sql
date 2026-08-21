-- ============================================================
-- Migration: Dokumentensafe (private Ablage je Wohnung, für Mieter)
-- Wird automatisch von includes/migrationen.php angewendet - kein
-- manueller Schritt in phpMyAdmin nötig.
--
-- Bewusst komplett getrennt von "dokumente"/"dokument_kategorien":
-- diese Kategorien legt der Mieter sich frei selbst an (z.B. Belege,
-- Bedienungsanleitungen, Bilder) und sie haben KEINE Bedeutung für
-- die Nebenkostenabrechnung - im Gegensatz zu den vom Vermieter
-- gepflegten Kategorien in der offiziellen Dokumentenverwaltung.
-- ============================================================

CREATE TABLE IF NOT EXISTS dokumentensafe_kategorien (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id  INT NOT NULL,
    bezeichnung VARCHAR(100) NOT NULL,
    sortierung  INT NOT NULL DEFAULT 0,
    erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);

CREATE TABLE IF NOT EXISTS dokumentensafe (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id      INT NOT NULL,
    kategorie_id    INT NOT NULL,
    bezeichnung     VARCHAR(255) NOT NULL,
    dateiname       VARCHAR(255) NOT NULL,
    hochgeladen_von INT NULL,
    hochgeladen_am  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id),
    FOREIGN KEY (kategorie_id) REFERENCES dokumentensafe_kategorien(id),
    FOREIGN KEY (hochgeladen_von) REFERENCES benutzer(id)
);
