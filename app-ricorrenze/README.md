# Ricorrenze — app Android per compleanni e festività

App personale (Android 8.0 o più recente) che ricorda compleanni, anniversari,
onomastici, festività e altre date, con una notifica all'orario scelto.

## Cosa fa
- **Una scheda per persona**: nome, telefono, gruppo e note si scrivono una volta sola, e dentro
  ci metti tutte le sue date — compleanno, **onomastico**, anniversario (es. "matrimonio"),
  o **altri eventi** a nome libero (es. "Laurea"). Ogni data ha i suoi promemoria.
- Le schede doppie create prima (stesso nome) si uniscono da *Impostazioni → Nomi doppi*;
  se salvi un nome che esiste già, l'app propone di aggiungere le date alla scheda esistente.
- **Promemoria**: il giorno stesso e/o 1, 2, 3, 5, 7, 14, 30 giorni prima (o un numero a scelta),
  all'**orario** scelto (uno predefinito per tutte, o uno diverso per singola ricorrenza).
- **Notifica con tasti**: *Chiama*, *WhatsApp* e *SMS* con il messaggio di auguri già scritto.
- **Filtri** nell'elenco: Compleanni, Onomastici, Anniversari, Festività, Altri (anche più insieme) e per gruppo;
  la ricerca per nome è nell'icona 🔍 in alto.
- **Anno facoltativo** solo per compleanni e anniversari → mostra l'età ("compie 41 anni", "14° anniversario").
- **Contatto** facoltativo: numero scritto a mano o scelto dalla rubrica.
- **Gruppi personalizzabili** (Famiglia, Amici, Lavoro, Palestra, Altro + quelli che crei):
  nome, colore, notifiche accese/spente per gruppo, filtro nell'elenco.
- **Calendario** mensile con i pallini colorati per gruppo.
- **Festività italiane** pronte (anche Pasqua, Pasquetta, Carnevale e Festa della mamma, calcolate ogni anno).
- **Importa compleanni dalla rubrica** del telefono.
- **Copia di sicurezza**: esporta/importa un file JSON (per cambiare telefono).
- Le notifiche perse (telefono spento) arrivano all'accensione, fino a 36 ore dopo.
- Tema chiaro e scuro automatici. I dati restano solo sul telefono.

## Installazione
1. Copia `Ricorrenze.apk` sul telefono e aprilo.
2. Se richiesto, consenti "Installa app sconosciute" per l'app da cui lo apri (File, Chrome, Drive…).
3. Al primo avvio consenti le **notifiche**. In *Impostazioni → Controllo notifiche* trovi
   lo stato dei permessi e il tasto **"Manda una notifica di prova"**.
4. Su alcuni telefoni (Xiaomi, Huawei, Samsung…) conviene togliere il **risparmio batteria**
   per l'app (tasto "Togli" nello stesso riquadro), altrimenti gli avvisi possono ritardare.

## Per sviluppatori
- Codice Java puro, nessuna libreria esterna. Compilazione senza Gradle: `./build.sh`
  (richiede JDK 17+ e Android SDK con `platforms;android-35` e `build-tools;35.0.0`).
- La chiave di firma NON è nel repository (`KEYSTORE=...`): per installare un aggiornamento
  sopra l'app esistente serve la stessa chiave usata la prima volta.
- Test: `test/esegui.sh` (Robolectric: logica + screenshot delle schermate).
