-- ============================================================
-- Migration: Einreichungen – Art vorklassifizieren + bei Freigabe
-- in mehrere Positionen (umlegbar / nicht umlegbar) aufteilen können
-- Einmalig ausführen in phpMyAdmin oder MariaDB-Konsole
-- ============================================================

USE hausverwaltung;

-- Der Einreicher kann schon grob einschätzen, um was es sich handelt.
-- Reine Vorbelegung für die Freigabe, keine feste Zuordnung.
ALTER TABLE einreichungen
    ADD COLUMN IF NOT EXISTS art ENUM('umlegbar','nicht_umlegbar','unklar') NOT NULL DEFAULT 'unklar';

-- Nachvollziehbarkeit: eine Einreichung kann bei der Freigabe in mehrere
-- Positionen aufgeteilt werden (z.B. 300€ umlegbar + 150€ nicht umlegbar).
-- Jede Zeile hier verweist auf die dabei tatsächlich angelegte Rechnung
-- bzw. Eigentümerkosten-Position.
CREATE TABLE IF NOT EXISTS einreichung_positionen (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    einreichung_id INT NOT NULL,
    ziel_typ       ENUM('rechnung','eigentuemerkosten') NOT NULL,
    ziel_id        INT NOT NULL,
    betrag         DECIMAL(10,2) NOT NULL,
    erstellt_am    DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ep_einreichung (einreichung_id)
);
