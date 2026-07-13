# synology-spk/

Baut `HausVerwaltung.spk` – das Synology-Paket für Paket-Zentrum →
„Manuell installieren".

## Ordner

- `build/` – Bausteine des Pakets (INFO, scripts, WIZARD_UIFILES, Icons).
  Wird von `build_spk.sh` zusätzlich um `build/package/app` (Kopie der
  App-Dateien) und `build/package.tgz` ergänzt, beides danach wieder
  gelöscht – im Git-/Dateisystem bleiben nur die Bausteine.
- `dist/` – fertiges `HausVerwaltung.spk` (Ergebnis von `build_spk.sh`).

## Neu bauen

```
bash build_spk.sh
```

Kopiert die aktuellen App-Dateien aus dem übergeordneten Projektordner,
entfernt dabei bewusst:
- `config/init.sql` (veraltet, durch `sql/install_complete.sql` ersetzt)
- das echte Datenbank-Passwort aus `config/config.php` (wird durch einen
  Platzhalter ersetzt – das echte Passwort wird erst bei der Installation
  auf der NAS zufällig neu erzeugt, siehe `build/scripts/postinst`)

nicht mit ins Paket kommen: `docker-compose.yml`, `Dockerfile`, `.env*`,
`start.bat`/`start.ps1`/`start.sh`, `backups/*.sql` (enthalten echte
Produktivdaten!), `backups/install.php`, `backups/passwort_reset.php`.

## Wie die Installation abläuft (Kurzfassung)

1. `preinst` prüft, ob Web Station vorhanden ist.
2. `postinst` kopiert die App nach `/var/services/web/hausverwaltung`,
   erkennt dabei eine eventuell schon bestehende Installation (lässt deren
   `config/config.php`, `uploads/`, `backups/` unangetastet), generiert bei
   einer Frischinstallation ein zufälliges Datenbank-Passwort und legt eine
   einmalig auszuführende SQL-Datei bereit.
3. Die abschließende Anleitung erscheint im Paket-Zentrum
   (`$SYNOPKG_TEMP_LOGFILE`).

## Bekannte Grenzen / nicht getestet

Diese Umgebung hatte keinen Zugriff auf echte Synology-Hardware. Die
Paketstruktur folgt Synologys offizieller Entwickler-Dokumentation
(`help.synology.com/developer-guide/...`), konnte aber nie tatsächlich
installiert werden. Falls die Installation mit „Invalid file format"
fehlschlägt: `build_spk.sh` verwendet bereits `tar --format=ustar` mit
expliziter Dateireihenfolge (bekannte Fehlerquelle bei selbstgebauten
`.spk`-Dateien) – trotzdem als Erstes prüfen.
