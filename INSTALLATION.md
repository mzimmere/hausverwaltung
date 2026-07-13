# Hausverwaltung installieren

Es gibt jetzt zwei Wege, die App zu installieren – auf der Synology als
eigenes Paket, oder lokal auf einem PC über Docker.

**Stand:** Die Synology-Paket-Installation (Variante A/B unten) wurde
mittlerweile auf echter Hardware erfolgreich getestet (Paket-Installation
+ Sync-Schritt per SSH). Der Docker-Weg wurde bisher nicht auf echter
Hardware/einem echten PC getestet.

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
unsignierte Drittanbieter-Pakete). Dadurch kann es die Dateien nicht mehr
automatisch in einen Web-Ordner kopieren – das erledigt ihr einmalig
selbst per File Station (siehe Schritt 3 unten).

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
3. Nach der Installation zeigt das Paket-Zentrum eine Anleitung an – der
   eine manuelle Schritt, der jetzt noch fehlt, läuft über **SSH**
   (der Paketordner ist NICHT über File Station erreichbar, auch nicht
   über „appstore" – das war ein früherer Irrtum in dieser Anleitung).
   Falls noch nicht aktiviert: Systemsteuerung → Terminal & SNMP →
   SSH-Dienst aktivieren.

### Zwei Möglichkeiten beim Kopieren (per SSH, getestet)

Mit einem SSH-Client (z. B. PuTTY) verbinden: `ssh admin@<NAS-IP>`
(eigenen Admin-Benutzernamen verwenden).

**A) Bestehende manuelle Installation aktualisieren (empfohlen):**
```bash
sudo rsync -av --exclude 'config/config.php' --exclude 'uploads/' --exclude 'backups/' \
    /var/packages/HausVerwaltung/target/app/ /volume1/web/hausverwaltung/
sudo chown -R http:http /volume1/web/hausverwaltung
```
Pfad `/volume1/web/hausverwaltung/` an den tatsächlichen Installationsort
anpassen, falls abweichend (in File Station auf den Ordner „web"
klicken, der Pfad steht dann oben in der Adressleiste). Kein SQL-Schritt
nötig – eure Datenbank und Zugangsdaten bleiben unverändert.

**B) Neue, eigenständige Installation:**
```bash
sudo mkdir -p /volume1/web/hausverwaltung_neu
sudo cp -r /var/packages/HausVerwaltung/target/app/. /volume1/web/hausverwaltung_neu/
sudo chown -R http:http /volume1/web/hausverwaltung_neu
```
Danach einmalig `EINMALIG_IN_PHPMYADMIN_AUSFUEHREN.sql` aus diesem neuen
Ordner in phpMyAdmin importieren (Reiter „Importieren", Datei auswählen,
„Los"), die Datei danach löschen. Login danach: `admin` /
`hausverwaltung` (bitte sofort ändern). Diese Variante legt eine eigene,
neue Datenbank an (`hausverwaltung_paket`) und lässt eure bestehende
Installation komplett unangetastet.

### Deinstallieren
Löscht bewusst **nichts** außerhalb des eigenen Paketordners – eure
Web-Ordner-Kopie (egal ob Variante A oder B) und alle Datenbanken bleiben
vollständig erhalten, da das Paket sie nie selbst angelegt/verändert hat
(nur ihr, beim manuellen Kopieren per SSH).

### Bei jedem Update
Genau derselbe SSH-Befehl (Variante A oder B von oben): Paket-Zentrum
zeigt „Update verfügbar" → aktualisieren → danach den rsync/cp-Befehl
(außer `config/config.php`, `uploads/`, `backups/`).

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
