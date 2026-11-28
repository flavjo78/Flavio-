# Pillolario

App Android per ricordare pillole e integratori (orari + giorni della settimana), con notifiche anche a telefono in standby.
Progetto separato: non ha nulla a che fare con PREDIZIONE.

- `www/index.html` — tutta l'app (interfaccia, salvataggio sul telefono, programmazione avvisi).
- `android/` — progetto Android (Capacitor 7 + plugin Local Notifications).
- `Pillolario.apk` — l'app pronta da installare.

Ricompilare: `npm install`, poi `npm run apk` (servono JDK 21 e Android SDK 35).
L'APK esce in `android/app/build/outputs/apk/debug/app-debug.apk`.
La chiave di firma `android/pillolario.keystore` è nel progetto apposta: così ogni APK nuovo aggiorna quello già installato senza perdere i dati.

## Versione 1.1
- Pallino rosso / numero sull'icona: ogni dose da prendere resta come notifica finché non premi Presa o Ignora
  (i richiami "insisti" sostituiscono l'avviso della stessa dose, quindi il numero = dosi in attesa).
- Vibrazione decisa a ogni avviso e a ogni richiamo (canale nuovo `pillole`, il vecchio `promemoria` viene tolto).
- Tasto **Ignora** nella notifica e nell'app.
- Il modulo nativo delle notifiche è modificato: la modifica sta in `patches/` e si riapplica da sola con `npm install`.

## Versione 1.2
- Richiami che crescono: primo avviso leggero (canale `p1`), dopo 10 minuti più forte (`p2`),
  dopo 20 e 30 minuti vibrazione lunga + suono della sveglia (`p3`, sul volume notifiche: rispetta silenzioso e Non disturbare).
- Tasto **Posticipa** (nella notifica e nell'app): 30 minuti o 1 ora. Il richiamo posticipato riparte da "più forte".

## Versione 1.3
- Nuova scelta dell'orario: interruttore **Rotelle** (ore e minuti a passi di 5) / **Momenti** (Risveglio, Colazione,
  Pranzo, Pomeriggio, Cena, Prima di dormire, con −15/+15 min). L'app ricorda l'ultimo modo usato.

## Versione 1.4
- **Allarme continuo** (se "Insisti" è attivo): dopo i richiami a 10, 20 e 30 minuti, ogni 10 minuti suona al
  **volume della sveglia** (canale `p4`, anche in silenzioso) finché non premi Presa, Ignora o Posticipa.
  Si ferma comunque dopo 12 ore. Il telefono lo ripete da solo (modifica nativa `rearmLoop`).
- Corretto: le ripetizioni settimanali ora svegliano il telefono anche in standby (prima solo la prima volta).
