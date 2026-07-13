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
- **Web Station** und **MariaDB 10** werden beim Installieren automatisch
  mitinstalliert, falls noch nicht vorhanden (als Paket-Abhängigkeit
  hinterlegt).
- **phpMyAdmin** wird für den letzten Einrichtungsschritt gebraucht (falls
  noch nicht vorhanden, kurz vorher aus dem Paket-Zentrum installieren).

### Installieren
1. `synology-spk/dist/HausVerwaltung.spk` auf den PC herunterladen (liegt
   bereits fertig gebaut im Projektordner).
2. Synology **Paket-Zentrum** öffnen → oben rechts **Manuell installieren**
   → die `.spk`-Datei auswählen → durchklicken.
3. Nach der Installation zeigt das Paket-Zentrum eine **Anleitung für den
   letzten Schritt** an (auch später einsehbar über die Paket-Details):
   eine vorbereitete SQL-Datei muss einmal in phpMyAdmin importiert werden.
   Genauer Ablauf steht in der Meldung, kurz zusammengefasst:
   - phpMyAdmin öffnen → Reiter **Importieren**
   - Datei auswählen: im File Station unter **web → hausverwaltung →**
     `EINMALIG_IN_PHPMYADMIN_AUSFUEHREN.sql`
   - **Los** klicken
   - Diese Datei danach löschen (sie enthält das neu erzeugte
     Datenbank-Passwort)
4. Fertig: `http://<NAS-IP>/hausverwaltung/` öffnen, Login `admin` /
   `hausverwaltung` (bitte sofort ändern).

### Wichtig, falls schon eine bestehende Installation läuft
Das Paket erkennt automatisch, ob unter
`/var/services/web/hausverwaltung` bereits eine Installation mit eigener
`config/config.php` liegt (z. B. die produktive Instanz). In diesem Fall
werden **Datenbank-Zugangsdaten, Uploads und Backups nicht verändert** –
es werden nur die Programmdateien aktualisiert, und der SQL-Einrichtungs-
schritt entfällt. Trotzdem: **vor dem ersten Test auf echter Hardware ein
Backup ziehen** (Seite „Backup" in der App), reine Vorsicht.

### Deinstallieren
Löscht bewusst **nichts**: Datenbank, Uploads und Backups bleiben
erhalten (liegen außerhalb des von DSM automatisch entfernten
Paketordners). Für eine komplette Entfernung müssten
`/var/services/web/hausverwaltung` per File Station und die Datenbank
`hausverwaltung` per phpMyAdmin von Hand gelöscht werden.

### Paket neu bauen (nach Code-Änderungen)
```
cd synology-spk
bash build_spk.sh
```
Baut `dist/HausVerwaltung.spk` aus dem aktuellen Projektordner neu
zusammen (Details siehe `synology-spk/README.md`).

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

## Update-Hinweis pflegen (nur für dich als Betreiber relevant)

Jede Installation prüft (nur für Admins sichtbar, höchstens 1x täglich,
scheitert lautlos ohne Internet) eine kleine, öffentlich erreichbare
JSON-Datei und zeigt „Update vXX verfügbar" neben der Versionsnummer an,
falls die eigene Version älter ist. Diese Datei liegt auf Vercel:
`https://hausverwaltung-updatecheck-mzimmere.vercel.app/version.json`,
hinterlegt in `config/config.php` als `UPDATE_CHECK_URL`.

**Bei jedem neuen Release:** die Datei mit der neuen Versionsnummer neu
deployen (einfach mich – Claude – bitten, `version.json` mit der neuen
Version zu redeployen, oder selbst über das Vercel-Dashboard bearbeiten).

**Einmaliger Schritt, noch offen:** Vercel schützt neue Projekte
standardmäßig mit „Vercel Authentication" – dadurch ist die Datei aktuell
noch NICHT öffentlich abrufbar. Bitte einmalig im Vercel-Dashboard unter
Project Settings → Deployment Protection → „Vercel Authentication"
deaktivieren, sonst bleibt der Update-Hinweis dauerhaft unsichtbar (die
App scheitert dabei absichtlich lautlos, es gibt also keine Fehlermeldung,
die darauf hinweist).

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
