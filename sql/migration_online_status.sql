-- ============================================================
-- Migration: Online-Status / letzte Aktivität je Benutzer
-- Einmalig ausführen in phpMyAdmin oder MariaDB-Konsole
--
-- Wird bei jedem Seitenaufruf eines eingeloggten Nutzers aktualisiert
-- (höchstens einmal pro Minute, siehe assets/header.php), damit der
-- Admin in der Benutzerverwaltung sehen kann, wer gerade online ist.
-- ============================================================

USE hausverwaltung;

ALTER TABLE benutzer
    ADD COLUMN IF NOT EXISTS letzte_aktivitaet DATETIME NULL;
