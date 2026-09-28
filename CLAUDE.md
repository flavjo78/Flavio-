# PREDIZIONE — Passaggio di consegne

> Documento di handover del progetto. Spiega **cosa fa** l'app, **com'è fatta**,
> le **decisioni prese** e **cosa manca ancora**. Leggilo prima di metterci mano.

Ultimo aggiornamento: 28 settembre 2026 (online su abraka.it; SES verificato; casella io@ ok).

---

## 1. Cos'è

Uno **strumento da palco per un effetto di mentalismo**. Durante lo spettacolo gli
spettatori scrivono una mail al performer; il sistema risponde in automatico con una
mail che contiene una **foto**. Durante il gioco la foto è una **neutra di attesa (A)**;
quando il performer chiude la sessione, la foto diventa la **rivelazione**.

L'illusione sta nel fatto che la foto sembra "dentro" una mail già ricevuta, mentre
in realtà viene ricostruita a ogni apertura da un server. Lo spettatore non immagina
che una foto nel corpo di una mail possa cambiare dopo l'invio.

**A sessioni.** Ogni show è una **sessione** con la sua rivelazione. Le foto cambiano
di sessione in sessione, ma ogni sessione conserva le sue: **chi ha ricevuto la
rivelazione di una sessione continua a vederla per sempre**, anche quando ne partono
altre con foto diverse. Per questo ogni mail porta il numero di sessione nell'indirizzo
dell'immagine (`image.php?s=NUMERO&id=…`).

**Natura del progetto:** è uno strumento di intrattenimento da palco, con un pubblico
consenziente. NON deve imitare marchi reali, NON raccoglie credenziali, NON inganna
terzi al di fuori del gioco. Vedi §6 (Vincoli etici) — sono vincoli di progetto, non
opzionali.

---

## 2. Come funziona (architettura)

Dominio e hosting: **abraka.it** su Tophost (piano Topweb, PHP). Il pannello e il
backend PHP sono costruiti e collaudati; restano da fare invio e autorisponditore.

```
   Spettatore                 Sistema del performer
  ┌──────────┐   mail in    ┌───────────────────────────┐
  │  scrive  │ ───────────► │  Casella (abraka.it)       │
  │  la mail │              │            │               │
  └──────────┘              │            ▼               │
       ▲                    │   Autorisponditore  [TODO] │
       │  risposta con      │            │               │
       │  <img> remota      │            ▼               │
       │                    │   Servizio invio (SES/Brevo)
       │  apre la mail      └───────────────────────────┘
       │  ─── GET image.php?id=… ───►  image.php  [PRONTO]
       │                                    ▲
       │  ◄─── Foto A / B / neutra ──────────┘
       │        (decisa dallo STATO del gioco)
                                            ▲
   Performer  ── Avvia/Finisci/Carica ──► stato.php + carica.php  [PRONTI]
   (pannello)                                (comandati dal pannello, con password)
```

Lo **scambio A→B è comandato dal performer** ("Finisci gioco"), con un **orario di
sicurezza** facoltativo che lo fa scattare da solo. Le mail contano solo a gioco avviato.

1. **Pannello di regia** (`pannello/regia-predizione.html`) — l'app del performer.
   *Costruito e collegato al backend; installabile sul telefono (PWA).*
2. **Backend dell'effetto** (`server/image.php` + `stato.php` + `carica.php` + `config.php`).
   *Scritto e collaudato in locale; da caricare su abraka.it.*
3. **Servizio d'invio** (Amazon SES o Brevo) — spedisce le ~500 risposte.
   *Da attivare/acquistare.*
4. **Autorisponditore** — collega "mail arrivata → risposta con foto".
   *Da costruire.*

---

## 3. I file

```
predizione/
├─ CLAUDE.md                       ← questo documento
├─ pannello/
│   ├─ regia-predizione.html       ← pannello del performer (collegato al backend, PWA)
│   ├─ manifest.json               ← installazione come app sul telefono
│   ├─ service-worker.js           ← installazione + apertura offline
│   └─ icons/                      ← icone dell'app (192, 512, apple-touch)
├─ server/
│   ├─ config.php                  ← impostazioni: PASSWORD, fuso orario, cartella dati
│   ├─ image.php                   ← immagine dinamica (il cuore dell'effetto)
│   ├─ stato.php                   ← comandi Avvia/Finisci/Azzera/Forza (protetti)
│   ├─ carica.php                  ← upload delle foto dal pannello (protetto)
│   ├─ neutro.png                  ← Foto A neutra PREDEFINITA (usata se non se ne carica una)
│   ├─ _dati/                      ← [creata sul server] foto, stato.json, aperture.csv
│   └─ ISTRUZIONI.txt              ← come caricare i file su abraka.it
└─ guide/
    └─ guida-acquisti.html         ← cosa comprare (dominio, hosting, invio) a confronto
```

### `pannello/regia-predizione.html`
App mobile in verticale, singolo file HTML (CSS e JS inline). **Installabile** sul
telefono (PWA: manifest + service worker + icone). Due schermate: **Regia** (aperture
registrate, stato/fase del gioco, Avvia/Finisci/Azzera) e **Impostazioni** (ingranaggio:
password, carico Foto A neutra / rivelazione, orario di sicurezza, test Forza A/B/Auto).
Mostra il numero di **sessione** corrente; "Avvia" richiede Foto A + rivelazione caricate.

**Stato attuale:** **collegato al backend e collaudato** (test end-to-end contro i file
PHP, con Chromium). Chiama `stato.php` e `carica.php` sul server (via `../predizione/`),
si aggiorna ogni 4 secondi e mostra la fase reale. La password è salvata solo sul
dispositivo (localStorage). Il caricamento foto ora **persiste** (le salva il server).
Da collegare, in futuro, il contatore alle mail reali (oggi conta le aperture del log).

### `server/config.php`
Impostazioni condivise. **L'unica cosa da cambiare a mano è la PASSWORD**
(`PREDIZIONE_PASSWORD`), la stessa che si inserisce nel pannello. Contiene fuso orario,
percorso della cartella dati protetta `_dati/`, gli helper per leggere/scrivere lo stato
e il conteggio aperture. Anche `ABILITA_LOG` (cattura on/off) sta qui.

### `server/stato.php`
Riceve i comandi dal pannello (tutti tranne la sola lettura richiedono la password):
`avvia` (apre una NUOVA sessione: congela Foto A + rivelazione in file dedicati,
orario di sicurezza opzionale), `finisci` (la sessione corrente → rivelazione),
`azzera` (annulla la sessione corrente se non ancora terminata), `forza` (A/B/OFF test),
`stato` (lettura per il polling). Lo stato vive in `_dati/stato.json`.

### `server/carica.php`
Riceve dal pannello (con password) e valida l'immagine (JPG/PNG/GIF/WEBP):
`slot=A` → **Foto A neutra** persistente (`_dati/foto_a.*`, riusata ogni sessione);
`slot=B` → **rivelazione** in attesa (`_dati/rivelazione_pronta.*`), consumata al prossimo `avvia`.
Se non si carica nessuna Foto A, `avvia` usa la **neutra predefinita `neutro.png`**
(nella cartella degli script): così basta caricare la rivelazione. Il performer può
sovrascrivere la neutra caricandone una propria (slot=A) quando vuole.

### `server/image.php`
Il motore dell'effetto. La mail chiede `image.php?s=NUMERO&id=…`; lo script guarda la
**sessione** di quella persona e restituisce:
- **sessione assente / gioco spento** → immagine **neutra** (pixel trasparente).
- **sessione IN CORSO** → **Foto A** (neutra di attesa).
- **sessione TERMINATA** (o orario di sicurezza scattato) → la **rivelazione di quella sessione**.
- **Forza A/B** (test): mostra A/B della sessione di riferimento, vince su tutto.
Ogni sessione ha i suoi file (`sess_N_before.*`, `sess_N_after.*`): mai sovrascritti,
così le rivelazioni vecchie restano congelate. Header **anti-cache** sempre. Log in
`_dati/aperture.csv` (data/ora, sessione, id, foto, IP, dispositivo; `ABILITA_LOG`).

Non c'è niente da configurare in `image.php`: tutte le impostazioni stanno in `config.php`.
Vedi `server/ISTRUZIONI.txt`.

---

## 4. Decisioni prese (e perché)

- **Immagine remota, non allegato.** La foto è un `<img src="…/image.php">`: viene
  scaricata a ogni apertura. È ciò che permette lo scambio A→B ed è tecnologia standard
  (stessa dei pixel di tracciamento). Non appesantisce la mail e non costa traffico SES.

- **Scambio comandato dal performer (non a orario fisso).** Lo decide il pulsante
  "Finisci gioco". Motivo: dal vivo il momento giusto lo conosce solo il performer.
  L'orario resta come **rete di sicurezza**: se non premi, lo scambio scatta lo stesso.

- **Le mail contano solo a gioco avviato.** A gioco spento image.php mostra il neutro e
  (in futuro) l'autorisponditore non deve rispondere: niente parte prima di "Avvia".

- **Foto per-sessione, congelate per sempre.** La Foto A neutra è persistente e riusata;
  la rivelazione è diversa a ogni sessione. Ogni "Avvia" crea una sessione numerata che
  salva le proprie foto in file dedicati (`sess_N_*`), mai sovrascritti. Così chi ha
  ricevuto una rivelazione continua a vederla anche dopo altre sessioni. Requisito del
  performer: la mail deve portare `?s=NUMERO` per legare il destinatario alla sua sessione.

- **Scambio deciso al momento dell'apertura**, non alla spedizione. Ogni spettatore
  resta con la versione vista alla *sua* prima apertura (per via della cache — vedi sotto).

- **Header anti-cache** in `image.php`. Senza, il client congelerebbe subito la prima
  foto e lo scambio non avverrebbe.

- **`?id=` per destinatario.** Doppio scopo: aggirare parte della cache condivisa e
  registrare chi apre/quando (funzione "cattura" del pannello).

- **Pannello protetto da password.** Il pannello può caricare foto e cambiare lo stato
  via internet, quindi va protetto: la password sta in `config.php` (server) e il
  pannello la invia a ogni comando. È il pannello del performer, non finge nessun
  servizio reale. La cartella dati `_dati/` è blindata con un `.htaccess`.

- **Stato su file, nessun database.** Lo stato del gioco è un piccolo `stato.json`, le
  foto e il log stanno in `_dati/`. Massima semplicità di deploy su hosting economici.

- **Pannello e backend separati ma stesso dominio.** Il pannello (in `/pannello/`) chiama
  gli script PHP (in `/predizione/`) sullo stesso dominio `abraka.it`: nessun problema di
  permessi cross-origin, e la foto e la mail restano coerenti sullo stesso dominio.

- **Invio via servizio dedicato (SES/Brevo), non dalla casella normale.** 500 mail in
  raffica da una Gmail/casella normale = blocco + spam. Un ESP con dominio autenticato
  (SPF/DKIM/DMARC) è l'unico modo affidabile. SES ≈ costo nullo ma più tecnico; Brevo
  più semplice ma il gratis è 300/giorno (per 500 serve lo Starter ~9 €/mese).

- **Hosting Tophost (piano Topweb) su dominio abraka.it.** Scelto per prezzo (~19 €/anno,
  dominio incluso) e per il PHP incluso, indispensabile all'effetto. Il piano "Topname"
  era escluso perché *senza* PHP. Aruba/Netsons erano alternative valide equivalenti.

- **Dominio onesto, mai look-alike di marchi.** Scelta tecnica *e* etica: un dominio che
  imita un marchio (es. "googie") viene messo in blacklist dai filtri e non è ammissibile.

---

## 5. Limiti noti

- **Cache di Apple Mail (iPhone).** Con "Protezione privacy Mail" (attiva di serie),
  Apple **pre-scarica** le immagini all'arrivo, non all'apertura: può congelare la Foto A
  prima dello scambio e mostrare quella anche se aperta dopo. È il punto debole; nessun
  trucco lo aggira al 100%. **Gmail** invece scarica all'apertura → l'effetto è affidabile.
  Mitigazione: far arrivare la mail il più vicino possibile al momento dell'apertura.

- **Il server non va mai spento.** La foto resta visibile agli spettatori solo finché
  `image.php` e le immagini restano online. Spegnere = immagini rotte per chi riscarica.

- **Sandbox SES.** All'inizio SES limita a ~200 mail/giorno verso indirizzi verificati.
  Va richiesto il passaggio a "production" e l'aumento del limite **giorni prima** dello show.

---

## 6. Vincoli etici (non negoziabili)

Questo è uno strumento da palco per un pubblico consenziente. Restano validi:
- Dominio **proprio e onesto**, che non imita nessun marchio o organizzazione reale.
- Nessuna schermata che finge di essere un servizio reale, nessuna raccolta di credenziali.
- La "cattura" registra aperture per uso da palco, non è sorveglianza di terzi ignari.

Se un'evoluzione futura spingesse verso l'inganno di terzi fuori dal gioco (finte
identità, finte prove, imitazione di brand), va fermata: esce dall'ambito di questo progetto.

---

## 7. Cosa manca — prossimi passi

- [x] Acquistare **dominio + hosting** → fatto: abraka.it su Tophost (Topweb, PHP).
- [x] **Pannello installabile** sul telefono (PWA).
- [x] **Backend a tre fasi/sessioni** (image/stato/carica/config) + pannello collegato.
- [x] **Deploy dal vivo:** i 5 file (4 PHP + pannello) sono in `abraka.it/predizione/`,
      password impostata in `config.php`, effetto provato online (neutro → A → rivelazione).
- [x] **HTTPS** attivato su Tophost (propagazione ~3-4h; poi mettere "Redirect automatico: On").
- [x] **Amazon SES** (regione Europa/Irlanda): account creato, **dominio `abraka.it` VERIFICATO**
      (DKIM 3x CNAME + DMARC su DNS Tophost), Gmail `appisolata@gmail.com` verificata come test.
      Ancora in **sandbox** (200 mail/giorno, solo verso indirizzi verificati).
- [x] **Casella del gioco `io@abraka.it`**: invia e riceve. Nota: andava agganciata alla
      mailbox principale `abraka.it` (all'inizio puntava a una mailbox separata senza spazio).
- [x] **Autorisponditore — CODICE PRONTO** (`autorisponditore/index.mjs`): AWS Lambda (Node.js)
      che ogni minuto legge la casella `io@abraka.it` via **IMAP** (mail restano su Tophost,
      leggibili) e, a gioco avviato, risponde via **SES** con `image.php?s=SESSIONE&id=…`.
      Impostazioni via variabili d'ambiente. Pacchetto zip pronto da caricare.
- [ ] **Deploy dell'autorisponditore su AWS**: creare la Lambda, caricare lo zip, impostare
      le variabili (IMAP_USER/PASS ecc.), timeout 2 min, policy `AmazonSESFullAccess`, trigger
      EventBridge `rate(1 minute)`. **Guida completa: `guide/autorisponditore.md`.**
- [ ] **Uscire dalla sandbox SES** ("Richiedi accesso alla produzione") + aumento limite,
      qualche giorno prima dello show.
- [ ] Collegare il **contatore** del pannello alle mail reali (oggi mostra le aperture del log).
- [ ] Prova completa in piccolo (2–3 mail) prima dello show, su Gmail e su iPhone.

### Credenziali/risorse in uso (per riprendere)
- AWS: account root con la Gmail; regione **eu-west-1 (Irlanda)** per SES.
- Tophost: pannello `cp.tophost.it`; casella gioco **io@abraka.it** su mailbox `abraka.it`.
- Pannello performer: `https://abraka.it/predizione/regia-predizione.html` (password in config.php).

### Snippet dell'immagine nella mail (per l'autorisponditore)
```html
<img src="https://abraka.it/predizione/image.php?s=NUMEROSESSIONE&id=SPETTATORE1"
     width="500" style="display:block;max-width:100%;border:0" alt="">
```
`s` = numero della sessione in corso (lo mostra il pannello); `id` diverso per ogni
destinatario. L'autorisponditore riempirà entrambi in automatico, e risponderà solo a
gioco avviato (fase ≠ spento).
