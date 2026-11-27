#!/usr/bin/env bash
# Compila l'APK di "Ricorrenze" senza Gradle: aapt2 -> javac -> d8 -> zipalign -> apksigner.
# Requisiti: JDK 17+ e Android SDK (platforms;android-35 + build-tools;35.0.0).
# Uso:  ANDROID_HOME=/percorso/sdk ./build.sh
# La chiave di firma è letta da $KEYSTORE (default: ~/.ricorrenze/ricorrenze.jks) e creata se manca.
# ATTENZIONE: per installare un aggiornamento sopra l'app già installata serve la STESSA chiave.
set -euo pipefail
cd "$(dirname "$0")"

SDK="${ANDROID_HOME:-/opt/android-sdk}"
BT="$SDK/build-tools/35.0.0"
JAR="$SDK/platforms/android-35/android.jar"
KEYSTORE="${KEYSTORE:-$HOME/.ricorrenze/ricorrenze.jks}"
KSPASS="${KSPASS:-ricorrenze}"
OUT=build

rm -rf "$OUT"; mkdir -p "$OUT/gen" "$OUT/classes" "$OUT/dex"

echo "1/6 risorse"
"$BT/aapt2" compile --dir res -o "$OUT/res.zip"
"$BT/aapt2" link -o "$OUT/base.apk" -I "$JAR" --manifest AndroidManifest.xml \
    --java "$OUT/gen" --min-sdk-version 26 --target-sdk-version 35 "$OUT/res.zip"

echo "2/6 java"
javac -nowarn -Xlint:-options --release 8 -encoding UTF-8 -classpath "$JAR" \
    -d "$OUT/classes" $(find src "$OUT/gen" -name '*.java')

echo "3/6 dex"
"$BT/d8" --release --min-api 26 --lib "$JAR" --output "$OUT/dex" $(find "$OUT/classes" -name '*.class')

echo "4/6 pacchetto"
cp "$OUT/base.apk" "$OUT/unsigned.apk"
(cd "$OUT/dex" && zip -q -j ../unsigned.apk classes.dex)
"$BT/zipalign" -f -p 4 "$OUT/unsigned.apk" "$OUT/aligned.apk"

echo "5/6 firma"
if [ ! -f "$KEYSTORE" ]; then
    mkdir -p "$(dirname "$KEYSTORE")"
    keytool -genkeypair -keystore "$KEYSTORE" -storepass "$KSPASS" -keypass "$KSPASS" -alias ricorrenze \
        -keyalg RSA -keysize 2048 -validity 10000 -dname "CN=Ricorrenze, O=Personale, C=IT" >/dev/null 2>&1
fi
"$BT/apksigner" sign --ks "$KEYSTORE" --ks-pass "pass:$KSPASS" --ks-key-alias ricorrenze \
    --out Ricorrenze.apk "$OUT/aligned.apk"

echo "6/6 verifica"
"$BT/apksigner" verify --print-certs Ricorrenze.apk | head -3
ls -la Ricorrenze.apk
