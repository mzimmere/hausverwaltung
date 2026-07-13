# synology-spk/

Baut `HausVerwaltung.spk` – das Synology-Paket für Paket-Zentrum.

Läuft **ohne Root-Rechte** (Pflicht seit DSM 7 für unsignierte
Drittanbieter-Pakete – eine frühere, root-basierte Version wurde von
DSM 7 mit „kann nicht installiert werden, da es mit Root-Rechten
ausgeführt wird" abgelehnt). Stattdessen: `conf/privilege` lässt das
Paket als eigener eingeschränkter Nutzer laufen, `conf/resource`
deklariert einen Web-Service-Eintrag, den DSMs eigener
„Webservice-Worker" (mit dessen eigenen, höheren Rechten) automatisch
nach `/var/services/web_packages/hausverwaltung` kopiert/chownt – das
Paket selbst muss dafür nichts Privilegiertes mehr tun. Aufgebaut nach
Synologys offiziellem WordPress-Paket-Beispiel
(`help.synology.com/developer-guide/examples/compile_web_package.html`).

**Wichtige Konsequenz:** Dadurch installiert das Paket immer eine
**eigenständige, neue** Instanz (eigene Datenbank, eigener Ordner) – es
übernimmt/verändert NICHT eine bereits bestehende manuelle Installation
unter `/var/services/web/hausverwaltung`.

## Ordner

- `build/` – Bausteine des Pakets (INFO, `conf/privilege`,
  `conf/resource`, scripts, WIZARD_UIFILES, Icons). Wird von
  `build_spk.sh` zusätzlich um `build/package/app` (Kopie der
  App-Dateien) und `build/package.tgz` ergänzt, beides danach wieder
  gelöscht – im Git-/Dateisystem bleiben nur die Bausteine.
- `dist/` – fertiges `HausVerwaltung.spk` (Ergebnis von `build_spk.sh`),
  nicht Teil des Repos (siehe `.gitignore`) – wird stattdessen als
  GitHub-Release-Asset veröffentlicht (siehe unten).
- `packages.json` – Synology-Paketquelle (Format nach Vorbild echter
  Community-Repos), verweist auf das jeweils aktuelle GitHub-Release.
- `update-endpoint/` – kleines statisches Vercel-Projekt, das
  `packages.json` und `version.json` (Update-Hinweis in der App)
  öffentlich hostet: https://hausverwaltung-updatecheck.vercel.app

## Neue Version veröffentlichen

```
bash release.sh 46        # 46 = neue Versionsnummer, ohne "v"
```

Macht alles in einem Schritt: `.spk` neu bauen → committen/taggen/pushen
nach [github.com/mzimmere/hausverwaltung](https://github.com/mzimmere/hausverwaltung)
→ GitHub Release mit `.spk`-Asset anlegen → `packages.json` mit neuer
Version/Checksums/Link aktualisieren → `update-endpoint/` neu auf Vercel
deployen. Voraussetzung: `gh` (GitHub CLI, angemeldet) und `vercel` CLI
(angemeldet, Projekt per `vercel link` schon verknüpft) sind installiert,
und `$changelog` in `assets/header.php` wurde vorher um den passenden
Eintrag ergänzt.

## Nur die .spk neu bauen (ohne zu veröffentlichen)

```
bash build_spk.sh
```

Kopiert die aktuellen App-Dateien aus dem übergeordneten Projektordner,
entfernt/ersetzt dabei bewusst:
- `config/init.sql` (veraltet, durch `sql/install_complete.sql` ersetzt)
- das echte Produktiv-Datenbank-Passwort in `config/config.php` – wird
  durch ein bei jedem Build **neu zufällig erzeugtes** Passwort ersetzt,
  das gleichzeitig in eine mitgelieferte
  `EINMALIG_IN_PHPMYADMIN_AUSFUEHREN.sql` geschrieben wird (beide Werte
  bleiben so konsistent, ganz ohne Laufzeit-Generierung beim Installieren
  nötig zu machen)
- echtes Hausfoto (`assets/haus.jpg`) – Privatsphäre, spart auch Platz

nicht mit ins Paket kommen: `docker-compose.yml`, `Dockerfile`, `.env*`,
`start.bat`/`start.ps1`/`start.sh`, `backups/*.sql` (enthalten echte
Produktivdaten!), `backups/install.php`, `backups/passwort_reset.php`.

## Wie die Installation abläuft (Kurzfassung)

1. DSM installiert Web Station, MariaDB 10 und PHP 8.2, falls nötig
   (`install_dep_packages` in `INFO`).
2. DSM kopiert/chownt die App anhand von `conf/resource`
   (`pkg_dir_prepare`) automatisch nach
   `/var/services/web_packages/hausverwaltung` – kein Root-Skript nötig.
3. `postinst` zeigt nur noch die Anleitung für den letzten manuellen
   Schritt an (`$SYNOPKG_TEMP_LOGFILE`): die mitgelieferte SQL-Datei
   einmalig in phpMyAdmin importieren.

## Bekannte Grenzen / nicht getestet

Diese Umgebung hatte keinen Zugriff auf echte Synology-Hardware. Die
`conf/privilege`/`conf/resource`-Struktur folgt einem echten, offiziellen
Synology-Beispielpaket (WordPress) so genau wie möglich, konnte aber nie
tatsächlich installiert werden. Mögliche Unsicherheiten, falls es beim
ersten echten Test hakt:
- `php.backend: 7` in `conf/resource` (PHP-Versions-Kennzahl) ist vom
  WordPress-Beispiel (dort für PHP 7.3) übernommen – die richtige Zahl
  für PHP 8.2 ist nicht sicher verifiziert.
- Das Icon-Feld in `conf/resource` erwartet laut Beispiel eigentlich ein
  Namensschema mit Platzhalter (`name_{0}.png` für mehrere Größen) - hier
  wird vereinfachend direkt auf `PACKAGE_ICON_256.PNG` verwiesen.
- Ob `web_packages/hausverwaltung` beim Deinstallieren automatisch
  entfernt wird, ist nicht bekannt (das managt DSMs Webservice-Worker,
  nicht das Paket selbst).

Falls die Installation mit „Invalid file format" fehlschlägt:
`build_spk.sh` verwendet bereits `tar --format=ustar` mit expliziter
Dateireihenfolge (bekannte Fehlerquelle bei selbstgebauten
`.spk`-Dateien) – trotzdem als Erstes prüfen.
