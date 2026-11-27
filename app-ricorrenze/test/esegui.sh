#!/usr/bin/env bash
# Prova l'app sulla JVM con Robolectric (Android simulato): logica date/promemoria,
# notifiche, e screenshot di tutte le schermate (chiaro, scuro, Android 8) in test/shots/.
# Prima esegui ../build.sh (servono build/classes e build/base.apk).
set -euo pipefail
cd "$(dirname "$0")"
A="$(cd .. && pwd)"; SDK="${ANDROID_HOME:-/opt/android-sdk}"; JAR="$SDK/platforms/android-35/android.jar"
W=lavoro; mkdir -p $W/libs $W/cfg/com/android/tools $W/tcls
mvn -q dependency:build-classpath -Dmdep.outputFile=$W/cp.txt
for v in 15-robolectric-13954326-i7 8.0.0_r4-robolectric-r1-i7; do
  mvn -q dependency:get -Dartifact=org.robolectric:android-all-instrumented:$v; done
for a in $(tr ':' '\n' < $W/cp.txt | grep '\.aar$'); do unzip -o -q -p "$a" classes.jar > $W/libs/$(basename "$a" .aar).jar; done
CP=$(tr ':' '\n' < $W/cp.txt | grep -v '\.aar$' | grep -v android-all | paste -sd:):$(ls $W/libs/*.jar | paste -sd:)
printf 'android_merged_manifest=%s/AndroidManifest.xml\nandroid_resource_apk=%s/build/base.apk\nandroid_custom_package=app.ricorrenze\n' "$A" "$A" \
  > $W/cfg/com/android/tools/test_config.properties
javac -nowarn -encoding UTF-8 -d $W/tcls -cp "$JAR:$A/build/classes:$CP" ProvaTest.java
java -Dshots=shots -cp "$W/tcls:$A/build/classes:$W/cfg:$CP:$JAR" org.junit.runner.JUnitCore app.ricorrenze.ProvaTest
