# Pillolario

App Android per ricordare pillole e integratori (orari + giorni della settimana), con notifiche anche a telefono in standby.
Progetto separato: non ha nulla a che fare con PREDIZIONE.

- `www/index.html` — tutta l'app (interfaccia, salvataggio sul telefono, programmazione avvisi).
- `android/` — progetto Android (Capacitor 7 + plugin Local Notifications).
- `Pillolario.apk` — l'app pronta da installare.

Ricompilare: `npm install`, poi `npm run apk` (servono JDK 21 e Android SDK 35).
L'APK esce in `android/app/build/outputs/apk/debug/app-debug.apk`.
La chiave di firma `android/pillolario.keystore` è nel progetto apposta: così ogni APK nuovo aggiorna quello già installato senza perdere i dati.
