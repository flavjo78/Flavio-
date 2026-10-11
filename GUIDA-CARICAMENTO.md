# PREDIZIONE / abraka — GUIDA DI CARICAMENTO (il "grande caricamento")

> Da fare **dopo lo spettacolo**, con calma. Qui c'è **cosa caricare, dove, e in che ordine**.
> Due destinazioni: **Tophost** (il sito + il backend PHP) e **AWS** (l'autorisponditore).
> Regola d'oro: usa sempre l'indirizzo con **www** (`www.abraka.it`).

---

## 0) Prima di iniziare — cosa creare sul server (una volta sola)
Caselle email su Tophost (oltre a `io@abraka.it` che hai già):
- **`supporto@abraka.it`** → pagamenti, ricevute, mail di benvenuto.
- **`accesso@abraka.it`** → richieste d'accesso dalla vetrina.

---

## 1) BACKEND + PANNELLO → cartella `www.abraka.it/predizione/`
Carica in **`/predizione/`** TUTTI questi file (stanno nel repo in `server/` e `pannello/`):

**Da `server/` (PHP e risorse):**
- `config.php`  ← **apri e metti la password admin** (riga `PRED_ADMIN_PASS`, adesso è `'!!!'`)
- `image.php`, `stato.php`, `carica.php`, `genera_ia.php`, `report.php`
- `piani.php`  (NUOVO — prezzi e limiti)
- `ordine.php` (NUOVO — ordini e attivazione)
- `migra_001.php` (NUOVO — spostamento 001, da usare dopo, vedi §5)
- `neutro.png`, `scrittura.ttf`

**Da `pannello/` (l'app del performer):**
- `regia-predizione.html`, `admin.html`, `assistente.html`
- `manifest.json`, `service-worker.js`, cartella `icons/`

> Nota: pannello e PHP stanno **nella stessa cartella `/predizione/`** (così i tasti chiamano `./stato.php`, `./ordine.php`).

**Dati solo-server da creare in `/predizione/_dati/`** (NON sono nel repo perché contengono dati personali):
- `pagamenti.json` → copia `server/pagamenti.esempio.json`, rinominalo `pagamenti.json` e metti **IBAN/intestatario/PayPal/Satispay veri**.
- `autore.json` → nome completo, indirizzo, codice fiscale (per le ricevute). *(Mai nel repo.)*
- `gemini.key` e `gemini.model` → la chiave IA (come già fatto per l'utente 003).

**Prova:** apri `https://www.abraka.it/predizione/regia-predizione.html` (entra con un utente) e
`https://www.abraka.it/predizione/admin.html` (entra con 000 + password admin).

---

## 2) SITO (vetrina) → cartella radice `www.abraka.it/`
Carica **tutto il contenuto della cartella `sito/`** nella radice del dominio:
- `index.html` (la vetrina)
- `prezzi.html` (il listino)
- **`assets/`** ← la cartella con **i 2 video e le 2 foto** (biglietteria + palco). **Caricala intera, non rinominare i file.**

**Prova:** apri `https://www.abraka.it/` → deve partire la vetrina coi 3 giochi.
Il tasto d'acquisto chiama già `/predizione/ordine.php` (se hai caricato il §1, funziona).

> Se metti il sito in una sottocartella diversa, in `sito/index.html` cambia la riga
> `var ORDINE_URL='/predizione/ordine.php';` con il percorso giusto.

---

## 3) Collaudo del flusso d'acquisto (5 minuti)
1. Dal sito, fai un **ordine di prova** (dati tuoi, piano Quinte, bonifico).
2. Apri `admin.html` → sezione **Ordini** → trovi l'ordine "in attesa".
3. Premi **"Segna pagato e attiva"** → ti mostra **numero utente + password** creati e manda la mail di benvenuto.
4. Controlla `admin.html` (l'utente nuovo c'è) e che sia arrivata la mail (anche nello **spam**).

---

## 4) AUTORISPONDITORE → AWS (Lambda)
Come da `guide/autorisponditore.md`:
- Crea la Lambda (Node.js), carica `autorisponditore/autorisponditore.zip`.
- Imposta le variabili d'ambiente (IMAP_USER/PASS della casella, ecc.).
- Trigger EventBridge `rate(1 minute)`, timeout 2 min, policy `AmazonSESFullAccess`.
- **IMPORTANTE:** `STATO_URL` e `IMAGE_URL_BASE` con **www** (altrimenti i POST perdono i dati).

---

## 5) SPOSTARE l'utente 001 nella sua cartella (facoltativo, tra due spettacoli)
Serve solo per ordine; **nessuno perde la foto B** (già testato).
1. Apri: `https://www.abraka.it/predizione/migra_001.php?u=000&pass=TUA_PASSWORD_ADMIN`
   → **prova a vuoto**: ti dice cosa sposterebbe. Non tocca niente.
2. Se ti torna, esegui: aggiungi `&conferma=1` allo stesso indirizzo.
3. Subito dopo, apri un **vecchio indirizzo** `image.php?s=NUMERO&id=...` di una mail già spedita
   e controlla che la **foto B** si veda ancora. (Fa un backup di `stato.json` da solo.)

---

## Promemoria importanti
- **Non spegnere il server:** le foto restano visibili finché `image.php` e le immagini sono online.
- **Sempre www.** Sito canonico `www.abraka.it`.
- **SES ancora in sandbox:** per eventi grandi usa **Brevo** (già collaudato) come modo di invio.
- Ordine consigliato: **§1 → §2 → §3** (sito funzionante) e, con calma, **§4** e **§5**.
