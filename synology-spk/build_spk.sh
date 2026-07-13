#!/usr/bin/env bash
# Baut HausVerwaltung.spk neu aus dem Projektordner.
# Ausfuehren mit: bash build_spk.sh   (aus dem synology-spk/ Ordner heraus)
set -e
cd "$(dirname "$0")"

PROJECT_ROOT="../"
APP="build/package/app"

rm -rf build/package
rm -f dist/HausVerwaltung.spk
mkdir -p "$APP" dist

cp -r "$PROJECT_ROOT"assets "$PROJECT_ROOT"config "$PROJECT_ROOT"includes \
      "$PROJECT_ROOT"pages "$PROJECT_ROOT"pdf "$PROJECT_ROOT"css \
      "$PROJECT_ROOT"js "$PROJECT_ROOT"sql "$APP"/
cp "$PROJECT_ROOT"index.php "$PROJECT_ROOT"login.php "$PROJECT_ROOT"logout.php \
   "$PROJECT_ROOT"setup.php "$APP"/

mkdir -p "$APP"/uploads/abrechnungen "$APP"/uploads/rechnungen/einreichungen \
         "$APP"/uploads/dokumente "$APP"/uploads/eigentuemerkosten \
         "$APP"/uploads/uebergabeprotokolle "$APP"/backups

rm -f "$APP"/config/init.sql

# Echtes Hausfoto nicht in ein potenziell weiterverteiltes Paket packen
# (Privatsphäre) - die App kommt ohne das Bild klar (siehe index.php,
# "Hausbild als Hintergrund, falls vorhanden"). Spart nebenbei viel Platz.
rm -f "$APP"/assets/haus.jpg "$APP"/assets/haus.png "$APP"/assets/haus.jpeg "$APP"/assets/haus.webp

# Zufaelliges Passwort EINMALIG beim Bauen erzeugen - fest in config.php UND
# in der vorbereiteten Setup-SQL verwendet (beide werden 1:1 mitkopiert,
# es gibt daher kein Timing-Problem mit dem Webservice-Worker, der die
# Dateien erst nach der Installation an ihren Serverort kopiert).
DB_PASS=$(head -c 33 /dev/urandom | md5sum | cut -c1-24)
sed -i "s/getenv('DB_PASS') ?: '[^']*'/getenv('DB_PASS') ?: '${DB_PASS}'/" "$APP"/config/config.php

{
    echo "-- Hausverwaltung - Ersteinrichtung der Datenbank"
    echo "-- Einmalig komplett in phpMyAdmin ausfuehren (Reiter 'Importieren',"
    echo "-- diese Datei auswaehlen, 'Los'). Danach ist die Anwendung startklar."
    echo "-- Diese Datei danach loeschen."
    echo ""
    echo "CREATE DATABASE IF NOT EXISTS hausverwaltung CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    echo "CREATE USER IF NOT EXISTS 'hausverwaltung'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    echo "GRANT ALL PRIVILEGES ON hausverwaltung.* TO 'hausverwaltung'@'localhost';"
    echo "FLUSH PRIVILEGES;"
    echo ""
    cat "$APP"/sql/install_complete.sql
} > "$APP"/EINMALIG_IN_PHPMYADMIN_AUSFUEHREN.sql

# Zugriff per Browser auf *.sql sperren (die Datei enthaelt das Passwort).
cat > "$APP"/.htaccess <<'EOF'
<FilesMatch "\.sql$">
  Require all denied
</FilesMatch>
EOF

# EULA.md ist die einzige gepflegte Quelle - wird 1:1 als Lizenztext beim
# Installieren angezeigt (Synology zeigt LICENSE unformatiert als Text an).
cp "$PROJECT_ROOT"EULA.md build/LICENSE

( cd build/package && tar -czf ../package.tgz app )

chmod +x build/scripts/preinst build/scripts/postinst build/scripts/preuninst \
         build/scripts/postuninst build/scripts/start-stop-status

( cd build && tar --format=ustar -cf ../dist/HausVerwaltung.spk \
    INFO package.tgz scripts conf WIZARD_UIFILES LICENSE PACKAGE_ICON.PNG PACKAGE_ICON_256.PNG )

rm -rf build/package

echo "Fertig: dist/HausVerwaltung.spk"
