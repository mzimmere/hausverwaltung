# Hausverwaltung installieren

Es gibt jetzt zwei Wege, die App zu installieren – auf der Synology als
eigenes Paket, oder lokal auf einem PC über Docker.

**Ehrlicher Hinweis vorab:** Beides wurde sorgfältig nach Synologys
offizieller Dokumentation gebaut, konnte aber in dieser Umgebung nicht auf
echter Synology-Hardware bzw. mit laufendem Docker getestet werden. Bitte
den ersten Testlauf mit etwas Zeitpuffer einplanen, falls doch noch etwas
nachjustiert werden muss.

Nutzungsbedingungen: [EULA.md](EULA.md) (wird bei der Synology-Installation
automatisch als Lizenztext angezeigt und muss akzeptiert werden).

---

## Option 1: Synology NAS als Paket (.spk)

### Voraussetzungen
- **Web Station**, **MariaDB 10** und **PHP 8.2** werden beim Installieren
  automatisch mitinstalliert, falls noch nicht vorhanden (als
  Paket-Abhängigkeit hinterlegt).
- **phpMyAdmin** wird für den letzten Einrichtungsschritt gebraucht (falls
  noch nicht vorhanden, kurz vorher aus dem Paket-Zentrum installieren).

Das Paket läuft komplett **ohne Root-Rechte** (Pflicht seit DSM 7 für
unsignierte Drittanbieter-Pakete) – die Dateien liegen dadurch nicht mehr
unter `/var/services/web/`, sondern in einem von Web Station selbst
verwalteten Ordner unter `/var/services/web_packages/hausverwaltung`.

### Installieren – Variante A: Paketquelle (empfohlen, mit Ein-Klick-Updates)
Einmalig einrichten, danach erscheint „Hausverwaltung" im Paket-Zentrum
wie ein ganz normales Paket – inklusive automatischer Update-Anzeige:

1. Synology **Paket-Zentrum** öffnen → Zahnrad oben rechts →
   **Einstellungen** → Reiter **Paketquellen** → **Hinzufügen**
2. Name: `Hausverwaltung` (frei wählbar)
   Adresse: `https://hausverwaltung-updatecheck.vercel.app/packages.json`
3. Im Paket-Zentrum unter „Community" (bzw. der neuen Quelle) erscheint
   jetzt „Hausverwaltung" – **Installieren** klicken.
4. Weiter wie unten ab Schritt 3.

Bei einer neuen Version reicht künftig ein Klick auf **Aktualisieren** im
Paket-Zentrum.

### Installieren – Variante B: Manuell (ohne Paketquelle)
1. Aktuelle `.spk`-Datei von
   [github.com/mzimmere/hausverwaltung/releases](https://github.com/mzimmere/hausverwaltung/releases)
   herunterladen.
2. Synology **Paket-Zentrum** öffnen → oben rechts **Manuell installieren**
   → die `.spk`-Datei auswählen → durchklicken.
3. Nach der Installation zeigt das Paket-Zentrum eine **Anleitung für den
   letzten Schritt** an (auch später einsehbar über die Paket-Details):
   eine vorbereitete SQL-Datei muss einmal in phpMyAdmin importiert werden.
   Genauer Ablauf steht in der Meldung, kurz zusammengefasst:
   - phpMyAdmin öffnen → Reiter **Importieren**
   - Datei auswählen: im File Station unter **web_packages →
     hausverwaltung →** `EINMALIG_IN_PHPMYADMIN_AUSFUEHREN.sql`
   - **Los** klicken
   - Diese Datei danach löschen (sie enthält das mitgelieferte
     Datenbank-Passwort)
4. Fertig: `http://<NAS-IP>/hausverwaltung/` öffnen, Login `admin` /
   `hausverwaltung` (bitte sofort ändern).

### Wichtig, falls schon eine bestehende (manuelle) Installation läuft
Anders als eine frühere Version dieses Pakets **übernimmt/verändert diese
Version eure bestehende manuelle Installation unter
`/var/services/web/hausverwaltung` NICHT** – das Paket legt zwingend eine
**eigenständige, neue** Installation an (eigener Ordner unter
`web_packages`, eigene neue Datenbank `hausverwaltung`). Grund: der
DSM7-konforme, root-freie Weg funktioniert nur über diesen von DSM selbst
verwalteten Ordner. Wer beide zusammenführen möchte, muss die Daten
(Datenbank-Inhalt, hochgeladene Dateien) von Hand von der alten in die
neue Installation übertragen.

### Deinstallieren
Die Datenbank `hausverwaltung` bleibt in jedem Fall bestehen und muss bei
Bedarf separat per phpMyAdmin gelöscht werden. Ob der Ordner unter
`web_packages` beim Deinstallieren automatisch entfernt wird, ist nicht
sicher bekannt (wird von DSMs Webservice-Worker verwaltet, nicht vom
Paket selbst) – vor dem Deinstallieren sicherheitshalber ein Backup
ziehen, falls wichtige Uploads dort liegen.

### Neue Version veröffentlichen (nach Code-Änderungen)
```
cd synology-spk
bash release.sh 44        # 44 = neue Versionsnummer, ohne "v"
```
Baut die `.spk` neu, committet/taggt/pusht nach GitHub, lädt die `.spk`
als Release-Asset hoch und aktualisiert die Paketquelle
(`packages.json`) sowie den Update-Hinweis (`version.json`) auf Vercel –
alles in einem Schritt. Voraussetzung: `$changelog` in `assets/header.php`
wurde vorher um den passenden Eintrag ergänzt. Details siehe
`synology-spk/README.md`.

---

## Option 2: Lokal auf einem PC (Docker)

### Voraussetzungen
[Docker Desktop](https://www.docker.com/products/docker-desktop)
installiert und gestartet (Windows, Mac oder Linux).

### Starten
- **Windows:** Doppelklick auf `start.bat`
- **Mac/Linux:** `./start.sh` im Terminal (einmalig `chmod +x start.sh`)

Beim allerersten Start:
- wird automatisch eine `.env`-Datei mit zufällig erzeugten Passwörtern
  angelegt (nicht löschen!),
- wird das Docker-Image gebaut (dauert ein paar Minuten),
- wird die Datenbank automatisch mit dem kompletten Schema eingerichtet
  (keine manuellen SQL-Schritte nötig, anders als bei der Synology-Variante),
- öffnet sich der Browser automatisch auf `http://localhost:8080/`.

Login: `admin` / `hausverwaltung` (bitte sofort ändern).

Bei jedem weiteren Start genügt derselbe Doppelklick – die Daten bleiben
in Docker-Volumes erhalten.

### Beenden
```
docker compose down
```
(lässt die Daten unangetastet; nur die Container werden gestoppt)

---

## Update-Hinweis & Paketquelle pflegen (nur für dich als Betreiber relevant)

Jede Installation prüft (nur für Admins sichtbar, höchstens 1x täglich,
scheitert lautlos ohne Internet) eine kleine, öffentlich erreichbare
JSON-Datei und zeigt „Update vXX verfügbar" neben der Versionsnummer an,
falls die eigene Version älter ist. Diese Datei liegt auf Vercel:
`https://hausverwaltung-updatecheck.vercel.app/version.json`,
hinterlegt in `config/config.php` als `UPDATE_CHECK_URL`.

Die Synology-Paketquelle (`packages.json`, siehe „Variante A" oben) liegt
auf demselben Vercel-Projekt und verweist auf die `.spk`-Datei im
jeweils aktuellen [GitHub Release](https://github.com/mzimmere/hausverwaltung/releases).

**Bei jedem neuen Release:** `bash synology-spk/release.sh <Versionsnummer>`
ausführen (siehe oben) – aktualisiert beide Dateien automatisch und
deployed sie neu.

---

## Was technisch geändert wurde, um beides möglich zu machen

- `config/config.php` liest Datenbank-Zugangsdaten jetzt zuerst aus
  Umgebungsvariablen und fällt sonst auf die bisherigen festen Werte
  zurück – die bestehende native Installation ist davon nicht betroffen.
- `sql/install_complete.sql` (neu) bündelt `schema.sql` und alle nötigen
  Migrationen für eine **komplett neue** Installation in der richtigen
  Reihenfolge.
- Beim Zusammenstellen ist aufgefallen, dass einige seit Längerem
  produktiv genutzte Tabellen/Spalten (Einreichungen, Versorger,
  Wartungsaufgaben, Kaution, Nachzahlungen, Mieter-Login-Felder) nie in
  eine eigene `sql/migration_*.sql`-Datei geschrieben wurden. Das wurde in
  `sql/migration_fehlende_tabellen_und_spalten.sql` nachgeholt.
