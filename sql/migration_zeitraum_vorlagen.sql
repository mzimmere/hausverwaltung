-- ============================================================
-- Migration: Eigene Zeitraum-Vorlagen für die Schnellauswahl
-- Einmalig in phpMyAdmin ausführen
-- ============================================================
USE hausverwaltung;

CREATE TABLE IF NOT EXISTS zeitraum_vorlagen (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    label       VARCHAR(100) NOT NULL,
    von_datum   DATE NOT NULL,
    bis_datum   DATE NOT NULL,
    sortierung  INT NOT NULL DEFAULT 0,
    erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Zwei Beispiel-Vorlagen zum Start anlegen
INSERT INTO zeitraum_vorlagen (label, von_datum, bis_datum, sortierung) VALUES
(CONCAT('Kalenderjahr ', YEAR(CURDATE())-1), CONCAT(YEAR(CURDATE())-1,'-01-01'), CONCAT(YEAR(CURDATE())-1,'-12-31'), 1),
(CONCAT('Kalenderjahr ', YEAR(CURDATE())),   CONCAT(YEAR(CURDATE()),'-01-01'),   CONCAT(YEAR(CURDATE()),'-12-31'),   2);
