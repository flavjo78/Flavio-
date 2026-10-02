# PREDIZIONE — Autorisponditore: guida passo-passo

Questa guida spiega come mettere in funzione la **risposta automatica**: quando
qualcuno scrive a `io@abraka.it`, parte da sola una mail di risposta con la
**foto dinamica** dell'effetto. Gira su **Amazon Lambda** (nel cloud, non serve
tenere acceso PC o telefono) e si "sveglia" **ogni minuto**.

Il codice è già pronto: è il file **`autorisponditore.zip`** (contiene `index.mjs`
+ le librerie). Tu farai solo dei clic guidati su Amazon.

---

## 0. Prima di iniziare — cosa serve

- L'**accesso alla produzione** di Amazon SES **approvato** (l'email di conferma
  da Amazon). In sandbox risponde solo verso indirizzi verificati.
- La casella `io@abraka.it` che **riceve** (già sistemata).
- La **password della casella** (mailbox `abraka.it`). Se non la ricordi, impostala
  su Tophost: `cp.tophost.it` → GESTIONE EMAIL → Gestione semplificata →
  "Gestione MAILBOX" → seleziona `abraka.it` → **Modifica password**.

Tutto si fa nella regione **Europa (Irlanda)** — la stessa di SES.

---

## 1. Creare la funzione Lambda

1. Nella console AWS, in alto assicurati che la regione sia **Europa (Irlanda)**.
2. Cerca **`Lambda`** nella barra in alto e aprilo.
3. Clicca **"Crea funzione"** (Create function).
4. Scegli **"Crea da zero"** (Author from scratch).
5. Compila:
   - **Nome funzione:** `predizione-autorisponditore`
   - **Runtime:** **Node.js 20.x** (o 22.x)
   - **Architettura:** lascia `x86_64`
6. Clicca **"Crea funzione"**.

---

## 2. Caricare il codice (lo zip)

1. Nella pagina della funzione, scheda **"Codice"** (Code).
2. A destra clicca **"Carica da"** (Upload from) → **".zip file"**.
3. Scegli il file **`autorisponditore.zip`** che ti ho mandato → **Salva**.
4. Attendi il caricamento. Vedrai comparire `index.mjs` e la cartella `node_modules`.

### Controlla il "Handler"
- Scheda **"Codice"** → in basso **"Impostazioni runtime"** → **Modifica**.
- Il campo **Handler** deve essere: **`index.handler`**  (se non lo è, scrivilo e salva).

---

## 3. Impostare le variabili (dati di collegamento)

Scheda **"Configurazione"** → **"Variabili d'ambiente"** → **Modifica** → aggiungi
queste righe (Chiave = Valore). Copia esattamente:

| Chiave | Valore |
|---|---|
| `IMAP_HOST` | `pop.tophost.it` |
| `IMAP_PORT` | `993` |
| `IMAP_USER` | `abraka.it`  *(il nome della MAILBOX; se non funziona, prova `io@abraka.it`)* |
| `IMAP_PASS` | *(la password della casella `abraka.it`)* |
| `SMTP_HOST` | `mail.tophost.it`  *(serve per la modalità "casella"/piccoli invii)* |
| `SMTP_PORT` | `587`  *(STARTTLS; utente/password = quelli IMAP)* |
| `SES_FROM` | `Predizione <io@abraka.it>` |
| `STATO_URL` | `https://abraka.it/predizione/stato.php` |
| `IMAGE_URL_BASE` | `https://abraka.it/predizione/image.php` |
| `MAIL_SUBJECT` | `La tua predizione` |
| `MAIL_INTRO` | `Grazie per aver scritto. Ecco la tua predizione.` |

Salva. *(La password resta solo qui su Amazon: non va scritta nel codice né altrove.)*

> Oggetto e testo li puoi cambiare quando vuoi modificando `MAIL_SUBJECT` e `MAIL_INTRO`.

---

## 4. Dare più tempo alla funzione

Scheda **"Configurazione"** → **"Configurazione generale"** → **Modifica**:
- **Timeout:** **2 min 0 sec** (di serie è 3 secondi: troppo poco).
- **Memoria:** **256 MB** va bene.
- Salva.

---

## 5. Permesso di inviare mail (SES)

La funzione deve poter spedire tramite SES:
1. Scheda **"Configurazione"** → **"Autorizzazioni"** (Permissions).
2. Sotto **"Ruolo di esecuzione"** clicca sul **nome del ruolo** (si apre IAM in una nuova scheda).
3. In IAM, **"Aggiungi autorizzazioni"** → **"Collega policy"** (Attach policies).
4. Cerca **`AmazonSESFullAccess`**, spunta la casella, **"Aggiungi autorizzazioni"**.

---

## 6. Farla partire ogni minuto (pianificazione)

1. Torna alla funzione Lambda → scheda **"Codice"** o la panoramica → **"Aggiungi trigger"** (Add trigger).
2. Come sorgente scegli **"EventBridge (CloudWatch Events)"**.
3. Seleziona **"Crea una nuova regola"**:
   - **Nome regola:** `predizione-ogni-minuto`
   - **Tipo di regola:** **Espressione di pianificazione** (Schedule expression)
   - **Espressione:** `rate(1 minute)`
4. **Aggiungi**.

Da ora la funzione si esegue **da sola ogni minuto**: legge la posta e risponde.

---

## 7. Prova che funzioni

### Prova "a secco" (senza mail)
1. Nella funzione → scheda **"Test"** → crea un evento di test qualsiasi (nome `prova`,
   lascia il JSON `{}`) → **Test**.
2. In fondo compaiono i **log**. Cerca la riga `ESITO {...}`:
   - se vedi `"lette":0` è normale (nessuna mail nuova);
   - se compare un errore IMAP → controlla `IMAP_USER`/`IMAP_PASS` (prova `io@abraka.it` come utente).

### Prova reale (l'effetto!)
1. Apri il **pannello** `https://abraka.it/predizione/regia-predizione.html`, metti la
   password, in **Impostazioni** accendi l'interruttore **"Autorisponditore"**, carica una
   **Rivelazione**, premi **Avvia gioco** (nasce la Sessione #N).
2. Dalla **Gmail** manda una mail a `io@abraka.it`.
3. Aspetta **~1 minuto** (la funzione scatta ogni minuto).
4. Ti arriva la **risposta automatica** con l'immagine: durante il gioco è la **Foto A**
   (neutra). Premi **Finisci gioco** sul pannello e riapri la mail: dovresti vedere la
   **Rivelazione**.

> **Logica delle fasi (importante).** Tre tasti: **Avvia → Cambia → Finisci**.
> L'autorisponditore risponde mentre il gioco è **attivo**, cioè nelle fasi **"In corso"**
> (mostra Foto A) e **"Rivelato"** (dopo "Cambia", mostra Foto B). **Prima** di "Avvia" non
> invia nulla; **dopo** "Finisci" non risponde più (sessione chiusa, la B resta congelata).
> Poiché gira ogni minuto, **premi "Finisci" circa 1 minuto dopo l'ultima mail**, così tutte
> le mail arrivate durante il gioco fanno in tempo a ricevere la risposta.

---

## 8. Cose importanti da sapere

- **Cache di Gmail/Apple Mail.** Una volta che il programma di posta ha scaricato
  l'immagine, a volte la **tiene in memoria** e non la riscarica: in quel caso lo
  scambio A→B potrebbe non aggiornarsi per chi ha già aperto. È il limite noto della
  tecnica (vedi `CLAUDE.md` §5). Su **Gmail** l'effetto è più affidabile che su iPhone.
  Consiglio: fai partire la risposta **poco prima** del momento della rivelazione.
- **Accendere/spegnere l'automatismo (dal telefono).** Nel **pannello → Impostazioni**
  c'è l'interruttore **"Autorisponditore"**. **Accendilo prima dello show, spegnilo alla
  fine.** Quando è spento, la funzione su Amazon si sveglia lo stesso ogni minuto ma
  **esce subito senza fare niente** (non legge le mail, non risponde): così non lavora
  quando non serve, e non devi toccare AWS. Se il gioco è avviato ma l'interruttore è
  spento, il pannello te lo segnala con **"⚠ autorisponditore SPENTO"**.
  *(In alternativa, per fermarlo lato AWS: nella regola EventBridge metti "Disabilita".)*
- **Leggere chi ti scrive.** Le mail restano nella casella: le leggi dalla **webmail**
  Tophost o dove preferisci. La funzione le segna solo come "lette".
- **Costi.** Praticamente nulli: Lambda ha un'ampia quota gratuita mensile; SES costa
  ~0,10 $ ogni 1000 mail. Per gli usi tuoi, pochi centesimi.
- **Volumi (700 in 5 minuti).** La funzione gira ogni minuto e, ad ogni giro, risponde a
  tutte le mail non lette: in pochi minuti smaltisce centinaia di risposte. Serve però
  l'accesso **produzione** SES attivo (fuori sandbox).

---

## 9. Se qualcosa non va

- **Non arriva risposta:** controlla che il gioco sia **Avviato** sul pannello; guarda i
  **log** della Lambda (scheda Monitor → View CloudWatch logs) e cerca `ESITO` o errori.
- **Errore IMAP / login:** prova `IMAP_USER = io@abraka.it`; verifica la password della
  casella; assicurati che `IMAP_HOST = pop.tophost.it` e `IMAP_PORT = 993`.
- **Errore invio SES:** se sei ancora in **sandbox**, puoi rispondere solo a indirizzi
  verificati (la tua Gmail sì). Fuori sandbox, verso tutti.
- **La foto non cambia:** è la cache del client di posta (vedi §8), non un errore del sistema.
