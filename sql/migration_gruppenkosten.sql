-- ============================================================
-- Migration: Kosten für mehrere ausgewählte Wohnungen
-- (z.B. Hausmeister, Treppenhausreinigung für EG+OG, nicht DG)
--
-- Zwei Bausteine:
-- 1. Einzelne Rechnung auf mehrere frei wählbare Wohnungen verteilen
-- 2. Wiederkehrende Vereinbarung (läuft automatisch über mehrere Jahre)
-- Einmalig in phpMyAdmin ausführen
-- ============================================================
USE hausverwaltung;

-- ── Baustein 1: Verknüpfungstabelle für Mehrfachzuordnung ──────
-- Eine Rechnung kann über diese Tabelle mehreren Wohnungen mit
-- jeweils frei wählbarem Anteil zugeordnet werden.
-- Wenn ein Eintrag hier existiert, hat das Vorrang vor der einfachen
-- wohnung_id-Spalte in der Tabelle "rechnungen".
CREATE TABLE IF NOT EXISTS rechnung_wohnungen (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    rechnung_id  INT NOT NULL,
    wohnung_id   INT NOT NULL,
    anteil       DECIMAL(6,4) NOT NULL,   -- z.B. 0.5000 = 50 %, Summe aller Anteile einer Rechnung = 1.0
    FOREIGN KEY (rechnung_id) REFERENCES rechnungen(id) ON DELETE CASCADE,
    FOREIGN KEY (wohnung_id)  REFERENCES wohnungen(id)
);
ALTER TABLE rechnung_wohnungen ADD INDEX idx_rw_rechnung (rechnung_id);
ALTER TABLE rechnung_wohnungen ADD INDEX idx_rw_wohnung (wohnung_id);

-- ── Baustein 2: Wiederkehrende Gruppenkosten ───────────────────
-- Dauerhafte Vereinbarung, die sich die Abrechnung automatisch zieht,
-- ohne dass jeden Monat eine Rechnung erfasst werden muss.
CREATE TABLE IF NOT EXISTS wiederkehrende_kosten (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    kostenart_id     INT NOT NULL,
    bezeichnung      VARCHAR(150) NOT NULL,    -- z.B. "Hausmeister EG+OG"
    betrag_pro_monat DECIMAL(10,2) NOT NULL,
    gueltig_von      DATE NOT NULL,
    gueltig_bis      DATE NULL,                -- NULL = läuft weiter bis auf Widerruf
    aktiv            TINYINT(1) DEFAULT 1,
    notiz            VARCHAR(255),
    erstellt_am      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kostenart_id) REFERENCES kostenarten(id)
);

-- Welche Wohnungen mit welchem Anteil betroffen sind
CREATE TABLE IF NOT EXISTS wiederkehrende_kosten_wohnungen (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    wiederkehrende_kosten_id INT NOT NULL,
    wohnung_id             INT NOT NULL,
    anteil                 DECIMAL(6,4) NOT NULL,  -- frei wählbar, Summe = 1.0
    FOREIGN KEY (wiederkehrende_kosten_id) REFERENCES wiederkehrende_kosten(id) ON DELETE CASCADE,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id)
);
ALTER TABLE wiederkehrende_kosten_wohnungen ADD INDEX idx_wkw_kosten (wiederkehrende_kosten_id);
