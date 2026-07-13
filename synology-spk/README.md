# synology-spk/

Baut `HausVerwaltung.spk` – das Synology-Paket für Paket-Zentrum.

## Architektur (nach zwei gescheiterten Anläufen tatsächlich getestet)

Läuft **ohne Root-Rechte** (Pflicht seit DSM 7 für unsignierte
Drittanbieter-Pakete). Zwei frühere Ansätze sind auf echter Hardware
gescheitert, bevor dieser hier bestätigt funktioniert hat:

1. **Root-basiert** (erste Version): DSM lehnte die Installation direkt ab
   („kann nicht installiert werden, da es mit Root-Rechten ausgeführt
   wird" – seit DSM 7 grundsätzlich blockiert für unsignierte Pakete).
2. **`conf/resource`-basiert** (zweite Version, nach echtem
   Synology-WordPress-Beispiel gebaut): lief ohne Root, scheiterte aber
   reproduzierbar mit „Ungültiges Dateiformat" – per gezielten
   Diagnose-Testpaketen eingegrenzt auf `conf/resource` selbst (auch nach
   Korrektur eines gefundenen Fehlers dort). Vermutlich ein Detail im
   Webservice-Resource-Schema, das ohne offizielles Synology-Toolkit
   nicht zuverlässig rekonstruierbar war.
3. **Aktueller Ansatz (funktioniert):** nur `conf/privilege`
   (`run-as: package`) – bestätigt per Diagnose-Testpaket erfolgreich
   installierbar. Kein `conf/resource` mehr. Die App-Dateien landen dafür
   im eigenen Paket-Sandbox-Ordner (`SYNOPKG_PKGDEST/app`, sichtbar in
   File Station unter `appstore → HausVerwaltung → target → app`, ggf.
   erst „Versteckte Dateien anzeigen" aktivieren) und müssen **einmalig
   manuell per File Station** in den eigentlichen Web-Ordner kopiert
   werden (Anleitung erscheint nach der Installation).

**Vorteil dieses Ansatzes:** Man kann beim Kopieren wählen, ob man eine
bestehende manuelle Installation aktualisiert (Datenbank/Uploads bleiben
unangetastet) oder eine komplett neue anlegt.

## Ordner

- `build/` – Bausteine des Pakets (INFO, `conf/privilege`, scripts,
  WIZARD_UIFILES, Icons). Wird von `build_spk.sh` zusätzlich um
  `build/package/app` (Kopie der App-Dateien) und `build/package.tgz`
  ergänzt, beides danach wieder gelöscht – im Git-/Dateisystem bleiben
  nur die Bausteine.
- `dist/` – fertiges `HausVerwaltung.spk` (Ergebnis von `build_spk.sh`),
  nicht Teil des Repos (siehe `.gitignore`) – wird stattdessen als
  GitHub-Release-Asset veröffentlicht (siehe unten).
- `packages.json` – Synology-Paketquelle (Format nach Vorbild echter
  Community-Repos), verweist auf das jeweils aktuelle GitHub-Release.
- `update-endpoint/` – kleines statisches Vercel-Projekt, das
  `packages.json` und `version.json` (Update-Hinweis in der App)
  öffentlich hostet: https://hausverwaltung-updatecheck.vercel.app
- `test-build/` – Diagnose-Testpakete (nicht Teil des Repos, siehe
  `.gitignore`), die zur Fehlersuche bei „Ungültiges Dateiformat"
  gebaut wurden. Kann bei Bedarf gelöscht werden.

## Neue Version veröffentlichen

```
bash release.sh 49        # 49 = neue Versionsnummer, ohne "v"
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
  eigener Datenbankname/-benutzer (`hausverwaltung_paket`, bewusst
  verschieden vom Namen der echten Produktivdatenbank!), beides
  gleichzeitig in eine mitgelieferte
  `EINMALIG_IN_PHPMYADMIN_AUSFUEHREN.sql` geschrieben (nur für Variante B,
  siehe `INSTALLATION.md`)
- echtes Hausfoto (`assets/haus.jpg`) – Privatsphäre, spart auch Platz

nicht mit ins Paket kommen: `docker-compose.yml`, `Dockerfile`, `.env*`,
`start.bat`/`start.ps1`/`start.sh`, `backups/*.sql` (enthalten echte
Produktivdaten!), `backups/install.php`, `backups/passwort_reset.php`.

## Wie die Installation abläuft (Kurzfassung)

1. DSM installiert Web Station, MariaDB 10 und PHP 8.2, falls nötig
   (`install_dep_packages` in `INFO`).
2. DSM extrahiert `package.tgz` nach `SYNOPKG_PKGDEST/app` – als
   eingeschränkter Paket-Nutzer (`conf/privilege`), kein Root nötig.
3. `postinst` zeigt die Anleitung für den manuellen Kopierschritt an
   (`$SYNOPKG_TEMP_LOGFILE`).

## Bekannte Grenzen

- Der Kopierschritt ist manuell (File Station), kein echtes Ein-Klick-
  Update im vollen Sinne – dafür zuverlässig getestet, im Gegensatz zu
  den beiden vorherigen (gescheiterten) Ansätzen.
- Ob `SYNOPKG_PKGDEST/app` bei jedem DSM/Package-Center-Update
  zuverlässig unter demselben File-Station-Pfad (`appstore →
  HausVerwaltung → target → app`) auffindbar bleibt, wurde nur einmalig
  bestätigt, nicht über mehrere DSM-Versionen hinweg getestet.
