#!/usr/bin/env bash
# Baut eine neue Version, veröffentlicht sie auf GitHub (Release + .spk-Asset)
# und aktualisiert die Vercel-Paketquelle (packages.json + version.json).
#
# Aufruf: bash release.sh 44          (Versionsnummer ohne "v")
#
# Voraussetzung: assets/header.php $changelog wurde bereits um den neuen
# Eintrag ergänzt (die Versionsnummer hier muss dazu passen), und alle
# Code-Änderungen sind bereits im Arbeitsverzeichnis.
set -e
cd "$(dirname "$0")"

VERSION="$1"
if [ -z "$VERSION" ]; then
    echo "Bitte Versionsnummer angeben, z.B.: bash release.sh 44"
    exit 1
fi
TAG="v${VERSION}"
REPO="mzimmere/hausverwaltung"
GH="/c/Program Files/GitHub CLI/gh.exe"

echo "== 1/6: INFO-Version setzen =="
sed -i "s/^version=\".*\"/version=\"${VERSION}-1\"/" build/INFO

echo "== 2/6: .spk bauen =="
bash build_spk.sh

SPK="dist/HausVerwaltung.spk"
SIZE=$(stat -c%s "$SPK")
MD5=$(md5sum "$SPK" | cut -d' ' -f1)
SHA256=$(sha256sum "$SPK" | cut -d' ' -f1)
echo "   Größe: $SIZE Bytes, md5: $MD5"

echo "== 3/6: packages.json + version.json aktualisieren =="
LINK="https://github.com/${REPO}/releases/download/${TAG}/HausVerwaltung.spk"
node -e "
const fs = require('fs');
const [version, link, md5, sha256, size] = process.argv.slice(1);
const p = JSON.parse(fs.readFileSync('packages.json', 'utf8'));
p.packages[0].version = version;
p.packages[0].link = link;
p.packages[0].md5 = md5;
p.packages[0].sha256 = sha256;
p.packages[0].size = parseInt(size, 10);
fs.writeFileSync('packages.json', JSON.stringify(p, null, 4) + '\n');
" "${VERSION}-1" "$LINK" "$MD5" "$SHA256" "$SIZE"

cp packages.json update-endpoint/packages.json
cp build/PACKAGE_ICON_256.PNG update-endpoint/PACKAGE_ICON_256.PNG
cat > update-endpoint/version.json <<EOF
{
  "version": "${TAG}",
  "datum": "$(date +'%B %Y')",
  "hinweis": "Siehe Changelog in der Anwendung."
}
EOF

echo "== 4/6: Git commit + Tag + Push =="
cd ..
git add -A
git commit -m "Release ${TAG}" || echo "   (nichts zu committen)"
git tag -f "$TAG"
git push origin main
git push origin "$TAG" --force
cd synology-spk

echo "== 5/6: GitHub Release + Asset hochladen =="
"$GH" release delete "$TAG" --repo "$REPO" --yes 2>/dev/null || true
"$GH" release create "$TAG" "$SPK" \
    --title "$TAG" \
    --notes "Siehe Changelog in der Anwendung (Versions-Button oben)." \
    --repo "$REPO"

echo "== 6/6: Vercel deployen =="
( cd update-endpoint && vercel deploy --prod --yes )

echo ""
echo "Fertig: ${TAG} veröffentlicht."
echo "  Release: https://github.com/${REPO}/releases/tag/${TAG}"
echo "  Paketquelle: https://hausverwaltung-updatecheck.vercel.app/packages.json"
