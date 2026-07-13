# 🏠 Hausverwaltung – Installationsanleitung für Synology NAS

## Was ist das?
Eine vollständige Web-App zur Nebenkostenabrechnung für Ihr 3-Parteien-Haus.
Läuft direkt auf Ihrer Synology NAS ohne externe Dienste.

---

## Voraussetzungen (Synology Pakete)

Installieren Sie folgende Pakete über das **Paket-Zentrum**:

1. **Web Station** (Webserver)
2. **PHP** (empfohlen: PHP 8.1 oder 8.2)
3. **MariaDB 10** (Datenbank)
4. **phpMyAdmin** (für Datenbankanlage, optional)

---

## Installation Schritt für Schritt

### 1. Datenbank anlegen

Öffnen Sie **phpMyAdmin** (oder MariaDB Commandline) und führen Sie aus:

```sql
CREATE DATABASE hausverwaltung CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'hausverwaltung'@'localhost' IDENTIFIED BY 'IhrSicheresPasswort';

GRANT ALL PRIVILEGES ON hausverwaltung.* TO 'hausverwaltung'@'localhost';

FLUSH PRIVILEGES;
```

### 2. Dateien kopieren

Kopieren Sie den gesamten Ordner `hausverwaltung/` in den Web-Ordner der Synology:

```
/volume1/web/hausverwaltung/
```

Am einfachsten per **File Station** oder **FTP/SCP**.

### 3. Ordner-Berechtigungen setzen

Folgende Ordner brauchen Schreibrechte für den Webserver (`http`-Benutzer):

```bash
chmod 777 /volume1/web/hausverwaltung/uploads
chmod 777 /volume1/web/hausverwaltung/uploads/abrechnungen
chmod 777 /volume1/web/hausverwaltung/uploads/rechnungen
chmod 777 /volume1/web/hausverwaltung/uploads/dokumente
chmod 777 /volume1/web/hausverwaltung/backups
```

Alternativ im File Station: Rechtsklick → Eigenschaften → Berechtigungen → `http` Vollzugriff.

### 4. Installations-Assistent aufrufen

Öffnen Sie im Browser:

```
http://IHR-NAS-IP/hausverwaltung/install.php
```

z.B. `http://192.168.1.100/hausverwaltung/install.php`

Der Assistent führt Sie durch:
- ✅ Systemprüfung
- ✅ Datenbankverbindung testen & konfigurieren
- ✅ Alle Tabellen anlegen
- ✅ Beispieldaten einrichten (3 Wohnungen, Kostenarten)

### 5. install.php löschen

**Wichtig:** Nach der Installation die Datei `install.php` löschen!

### 6. Anwendung öffnen

```
http://IHR-NAS-IP/hausverwaltung/
```

---

## PDF-Funktion (optional)

Die Abrechnungen können direkt als druckbare HTML-Seite geöffnet und über den Browser als PDF gespeichert werden.

Für automatischen PDF-Export installieren Sie zusätzlich **Composer** und DomPDF:

```bash
# Im Projektordner auf der Synology (SSH):
cd /volume1/web/hausverwaltung
composer require dompdf/dompdf
```

Danach erscheint auf der Abrechnung ein **"PDF Download"** Button.

---

## Funktionsübersicht

| Seite | Funktion |
|-------|----------|
| Dashboard | Übersicht Kosten, Rücklagen, letzte Rechnungen |
| Wohnungen | Stammdaten, Mieter, Wohnflächen |
| Rechnungen | Erfassen mit Datei-Upload, Jahresfilter |
| Zählerstände | Wasser Anfang/Endstand, Verbrauch automatisch |
| Vorauszahlungen | Monatliche Abschläge je Wohnung |
| Jahresabrechnung | Automatische Berechnung nach Umlageschlüssel |
| Dokumente | Upload und Verwaltung aller Unterlagen |
| Backup | Datenbankexport auf Knopfdruck |
| Einstellungen | Verwalter-/Objektdaten (erscheinen auf PDFs) |

---

## Umlageschlüssel

| Kostenart | Schlüssel |
|-----------|-----------|
| Grundsteuer | Wohnfläche |
| Gebäudeversicherung | Wohnfläche |
| Müllabfuhr | Gleiche Anteile (je 1/3) |
| Wasser/Abwasser | Verbrauch (Zählerstand) |
| Heizkosten | Verbrauch oder Direktimport |
| Allgemeinstrom | Wohnfläche |

---

## Sicherheit

- Die Anwendung ist **nur im Heimnetz** gedacht (kein Internet-Zugang)
- Kein Passwortschutz eingebaut – bei Bedarf nginx/Apache Basic Auth nutzen
- Regelmäßig Backup erstellen (Seite "Backup")

---

## Tipps für Synology

- **Port**: Standard ist Port 80. Alternativen in der Web Station konfigurieren.
- **HTTPS**: In der Web Station ein Zertifikat einrichten für sichere Verbindung.
- **Automatisches Backup**: Synology Task Scheduler + Backup-Skript einrichten.
- **Mobil**: Die App ist responsiv und auch auf dem Smartphone nutzbar.

---

Erstellt mit Claude | Version 1.0
