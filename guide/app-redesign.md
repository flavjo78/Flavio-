# PREDIZIONE — Ridisegno app utente (specifica di Flavio, 10/10/2026)

> Indicazioni per rifare l'interfaccia utente (login + impostazioni). NIENTE è
> ancora implementato: è la base per le anteprime e poi per il codice vero.
> Le traduzioni delle lingue si fanno ALLA FINE, quando l'app funziona bene.

## 1. Accesso (login)
- In cima, SOPRA il numero utente: **selettore lingua** = frecce ‹ › con **bandiera**
  al centro e **nome lingua** sotto. Lingue: Italiano, Inglese, Francese, Spagnolo, Tedesco.
  (Per ora solo il selettore grafico: NON tradurre davvero, lo faremo dopo.)
- Poi: numero utente + password + tasto **Accedi**.
- Premendo **Accedi**: sia **password** sia **lingua** restano **preimpostate** per la volta dopo.

## 2. Prima configurazione (SOLO la prima volta, se non già fatta)
- Subito dopo la password (al primo accesso) → pagina per inserire:
  - **numero di telefono** col **prefisso già precompilato** (+39),
  - **mail Gmail** (la casella del gioco),
  - **mail per le comunicazioni**.
- Una volta inserite, NON servono più: finiscono in una pagina di **"impostazioni
  secondarie"** (quelle che si impostano una volta sola).

## 3. Regia (schermata gioco)
- VA BENE com'è (4 contatori + stato + Avvia/Cambia/Finisci).

## 4. Rotella (⚙️) → Impostazioni = un MENU al centro
- Al posto delle 4 caselle, un **menu** con le voci (tutte al centro):
  - **Imposta foto**
  - **Imposta mail**
  - **Limiti** (schermata "I miei limiti" — già approvata, va bene)
  - **Autorisponditore / Invia mail**
  - **Assistente di scena**
  - **Report** (cliccabile se possibile)
- Per **tornare al gioco**: premere dove prima c'era la rotella.

### 4a. Imposta foto
- **Foto A** e **Foto B**; **orario di fine gioco** e **orario del Cambia** (sicurezza).
- Dentro: un tasto **"Testo mail"**; un tasto **"IA"** (cliccabile solo se il piano lo
  consente, altrimenti **visibile ma non cliccabile**).
- Se la **Foto B** viene generata (IA) viene messa **come Foto B** della sessione.
- Ad **ogni sessione** vanno ricaricate le due foto; MA se si **spunta "Foto A"** quella
  resta **di default** (non va ricaricata).
- Vicino a ogni caricamento: una **X** per eliminare il caricamento / non tenerlo in memoria.
- **Genera/Carica foto**: si può **prendere dalla galleria** oppure **scattare** cliccando
  direttamente la **fotocamera**.

### 4b. Autorisponditore / Invia mail
- Aprendolo, si può scegliere tra:
  - **Funzione autorisponditore** (la scelta selezionata appare come anteprima sul tasto),
  - **Funzione palco** (grandi invii),
  - **Inserisci mail** → apre un **elenco** dove indicare le mail (invio diretto manuale).

### 4c. Assistente di scena
- Genera il **link** e mostra se l'assistente è **collegato**.

### 4d. Imposta mail
- La casella (Gmail + password per app + mittente) — da impostazioni.

## Note
- Le "impostazioni secondarie" (telefono, mail, ecc.) stanno a parte, impostate una volta.
- Obiettivo: Impostazioni più **pulite** (oggi è troppo piena) → menu + sotto-pagine.

## Aggiornamenti (10/10/2026, sera)
- **Bandiere**: le emoji bandiera NON si vedono su Windows → usare **bandiere disegnate (SVG)**.
- **Prima configurazione ("Quasi pronto")**: aggiungere il **codice Gmail / password per app
  (16 caratteri)** insieme a telefono, Gmail e mail comunicazioni.
- **Grafica**: usare **la stessa grafica dell'app vera** (header Cormorant, card, tasti oro,
  .btn/.gear/.slot/.pill ecc.), non uno stile nuovo.
- **"Impostazioni avanzate"**: voce di menu che **sostituisce** Imposta mail + password ecc.
  Dentro: casella Gmail, codice 16 caratteri, mittente, telefono, mail comunicazioni, cambia
  password. (= le cose impostate una volta sola.)
- **Menu impostazioni** (ordine): Imposta foto · Autorisponditore/Invia mail · Assistente di
  scena · Limiti · Report · Impostazioni avanzate.
- **Cancella report**: lo rimuove **solo per l'utente**; nell'**archivio del 000** la sessione
  **resta**, segnata come **"sessione cancellata"** (il 000 vede tutto).
