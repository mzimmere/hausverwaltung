#!/usr/bin/env bash
# Hausverwaltung - lokaler Start (Mac/Linux)
set -e
cd "$(dirname "$0")"

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker wurde nicht gefunden. Bitte zuerst Docker Desktop installieren: https://www.docker.com/products/docker-desktop"
    exit 1
fi

if [ ! -f .env ]; then
    echo "Erster Start: lege .env mit zufaelligen Passwoertern an..."
    DB_PASS=$(openssl rand -hex 16 2>/dev/null || head -c 32 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c 24)
    DB_ROOT_PASS=$(openssl rand -hex 16 2>/dev/null || head -c 32 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c 24)
    cat > .env <<EOF
APP_PORT=8080
DB_NAME=hausverwaltung
DB_USER=hvuser
DB_PASS=${DB_PASS}
DB_ROOT_PASS=${DB_ROOT_PASS}
EOF
    echo "Fertig - .env angelegt (bitte nicht loeschen, sonst geht die Datenbankverbindung verloren)."
fi

APP_PORT=$(grep '^APP_PORT=' .env | cut -d= -f2)
APP_PORT=${APP_PORT:-8080}

echo "Starte Container (beim allerersten Mal kann das ein paar Minuten dauern)..."
docker compose up -d --build

echo "Warte, bis die Anwendung erreichbar ist..."
url="http://localhost:${APP_PORT}/"
ready=0
for i in $(seq 1 90); do
    if curl -sf -o /dev/null "$url"; then
        ready=1
        break
    fi
    sleep 2
done

if [ "$ready" = "1" ]; then
    echo "Hausverwaltung ist bereit: $url"
    echo "Standard-Login: admin / hausverwaltung (bitte gleich nach dem ersten Login aendern)"
    if command -v open >/dev/null 2>&1; then open "$url"; fi
    if command -v xdg-open >/dev/null 2>&1; then xdg-open "$url"; fi
else
    echo "Die Anwendung antwortet nach 3 Minuten noch nicht."
    echo "Bitte $url im Browser oeffnen, oder 'docker compose logs -f' pruefen."
fi
