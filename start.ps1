# Hausverwaltung - lokaler Start (Windows)
# Wird ueber start.bat per Doppelklick aufgerufen.

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $root

function New-RandomPassword {
    param([int]$Length = 24)
    $bytes = New-Object byte[] $Length
    $rng = New-Object System.Security.Cryptography.RNGCryptoServiceProvider
    $rng.GetBytes($bytes)
    $rng.Dispose()
    $text = [Convert]::ToBase64String($bytes) -replace '[^a-zA-Z0-9]', ''
    return $text.Substring(0, $Length)
}

$dockerCmd = Get-Command docker -ErrorAction SilentlyContinue
if (-not $dockerCmd) {
    Write-Host "Docker Desktop wurde nicht gefunden." -ForegroundColor Red
    Write-Host "Bitte zuerst https://www.docker.com/products/docker-desktop installieren, starten, und dieses Skript erneut ausfuehren."
    Read-Host "Enter zum Beenden druecken"
    exit 1
}

$envFile = Join-Path $root ".env"
if (-not (Test-Path $envFile)) {
    Write-Host "Erster Start: lege .env mit zufaelligen Passwoertern an..."
    $dbPass = New-RandomPassword
    $dbRootPass = New-RandomPassword
    $lines = @(
        "APP_PORT=8080",
        "DB_NAME=hausverwaltung",
        "DB_USER=hvuser",
        "DB_PASS=$dbPass",
        "DB_ROOT_PASS=$dbRootPass"
    )
    Set-Content -Path $envFile -Value $lines -Encoding utf8
    Write-Host "Fertig - .env angelegt (bitte nicht loeschen, sonst geht die Datenbankverbindung verloren)."
}

$appPort = '8080'
foreach ($line in Get-Content $envFile) {
    if ($line -match '^\s*APP_PORT\s*=\s*(.+)\s*$') {
        $appPort = $matches[1].Trim()
    }
}

Write-Host "Starte Container (beim allerersten Mal kann das ein paar Minuten dauern)..."
docker compose up -d --build
if ($LASTEXITCODE -ne 0) {
    Write-Host "Start fehlgeschlagen - siehe Meldungen oben." -ForegroundColor Red
    Read-Host "Enter zum Beenden druecken"
    exit 1
}

Write-Host "Warte, bis die Anwendung erreichbar ist..."
$url = "http://localhost:$appPort/"
$ready = $false
for ($i = 0; $i -lt 90; $i++) {
    try {
        $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 3
        if ($resp.StatusCode -eq 200) { $ready = $true; break }
    } catch {
        Start-Sleep -Seconds 2
    }
}

if ($ready) {
    Write-Host "Hausverwaltung ist bereit: $url" -ForegroundColor Green
    Write-Host "Standard-Login: admin / hausverwaltung (bitte gleich nach dem ersten Login aendern)"
    Start-Process $url
} else {
    Write-Host "Die Anwendung antwortet nach 3 Minuten noch nicht." -ForegroundColor Yellow
    Write-Host "Bitte $url im Browser oeffnen, oder 'docker compose logs -f' pruefen."
}
