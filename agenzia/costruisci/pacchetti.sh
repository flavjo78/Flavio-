#!/bin/bash
# Costruisce i due pacchetti per Windows: la versione completa (AGENZIA-x.y.z-windows.zip) e l'aggiornamento (AGGIORNAMENTO-x.y.z.zip).
# Servono: Node 22 e il node.exe di Windows (variabile WIN_NODE). Uscita nella cartella indicata da USCITA.
# L'aggiornamento porta SEMPRE scritto da quale versione si può installare (costruisci/da-versione.txt): nel file LEGGIMI-AGGIORNAMENTO.txt
# e nel messaggio iniziale di aggiorna.bat.
set -e
cd "$(dirname "$0")/.."
: "${WIN_NODE:?indicare il percorso di node.exe per Windows (WIN_NODE)}"
: "${USCITA:?indicare la cartella di uscita (USCITA)}"
VER=$(node -p "require('./package.json').version")
DA=$(tr -d ' \r\n' < costruisci/da-versione.txt)
node costruisci/bundle.js
node --experimental-sea-config dist/sea-config.json >/dev/null 2>&1
cp "$WIN_NODE" dist/agenzia.exe
(cd dist && npx --yes postject agenzia.exe NODE_SEA_BLOB sea-prep.blob --sentinel-fuse NODE_SEA_FUSE_fce680ab2cc467b6e072b8b5df1996b2 >/dev/null 2>&1)
rm -rf dist/AGENZIA dist/AGGIORNAMENTO && mkdir -p dist/AGENZIA dist/AGGIORNAMENTO
# --- pacchetto completo ---
cp dist/agenzia.exe dist/AGENZIA/ && cp -r web dist/AGENZIA/web
for f in windows/*.bat windows/*.ps1; do b=$(basename "$f"); [ "$b" != costruisci-exe.bat ] && [ "$b" != aggiorna.bat ] && cp "$f" dist/AGENZIA/; done
sed 's/$/\r/' LEGGIMI.md > dist/AGENZIA/LEGGIMI.txt && sed 's/$/\r/' SPECIFICA.md > dist/AGENZIA/SPECIFICA.txt
rm -f "$USCITA/AGENZIA-$VER-windows.zip" && (cd dist && zip -qr "$USCITA/AGENZIA-$VER-windows.zip" AGENZIA)
# --- aggiornamento ---
cp dist/agenzia.exe dist/AGGIORNAMENTO/ && cp -r web dist/AGGIORNAMENTO/web
sed "s/@@VERSIONE@@/$VER/g; s/@@DA@@/$DA/g" windows/aggiorna.bat > dist/AGGIORNAMENTO/aggiorna.bat
sed "s/@@VERSIONE@@/$VER/g; s/@@DA@@/$DA/g" costruisci/LEGGIMI-AGGIORNAMENTO.txt | sed 's/$/\r/' > dist/AGGIORNAMENTO/LEGGIMI-AGGIORNAMENTO.txt
rm -f "$USCITA/AGGIORNAMENTO-$VER.zip" && (cd dist && zip -qr "$USCITA/AGGIORNAMENTO-$VER.zip" AGGIORNAMENTO)
echo "Pacchetti pronti per la versione $VER (aggiornamento valido dalla $DA in poi) in $USCITA"
