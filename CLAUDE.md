# PREDIZIONE — Passaggio di consegne

> Documento di handover del progetto. Spiega **cosa fa** l'app, **com'è fatta**,
> le **decisioni prese** e **cosa manca ancora**. Leggilo prima di metterci mano.

Ultimo aggiornamento: 28 settembre 2026 (pannello collegato al backend).

---

## 1. Cos'è

Uno **strumento da palco per un effetto di mentalismo**. Durante lo spettacolo gli
spettatori scrivono una mail al performer; il sistema risponde in automatico con una
mail che contiene una **foto**. La foto **cambia in base all'orario di apertura**:
chi apre prima di un certo momento vede la Foto A, chi apre dopo vede la Foto B.

L'illusione sta nel fatto che la foto sembra "dentro" una mail già ricevuta, mentre
in realtà viene ricostruita a ogni apertura da un server. Lo spettatore non immagina
che una foto nel corpo di una mail possa cambiare dopo l'invio.

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
│   ├─ _dati/                      ← [creata sul server] foto, stato.json, aperture.csv
│   └─ ISTRUZIONI.txt              ← come caricare i file su abraka.it
└─ guide/
    └─ guida-acquisti.html         ← cosa comprare (dominio, hosting, invio) a confronto
```

### `pannello/regia-predizione.html`
App mobile in verticale, singolo file HTML (CSS e JS inline). **Installabile** sul
telefono (PWA: manifest + service worker + icone). Due schermate: **Regia** (aperture
registrate, stato/fase del gioco, Avvia/Finisci/Azzera) e **Impostazioni** (ingranaggio:
password, carico Foto A / Foto B, orario di sicurezza, modalità test Forza A/B/Auto).

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
`avvia` (fase→A, con orario di sicurezza opzionale), `finisci` (fase→B), `azzera`
(fase→spento), `forza` (A/B/OFF per i test), `stato` (lettura, per il polling del pannello).

### `server/carica.php`
Riceve la Foto A o B dal pannello (con password), verifica che sia un'immagine valida
(JPG/PNG/GIF/WEBP), la salva in `_dati/` e registra il percorso nello stato.

### `server/image.php`
Il motore dell'effetto. Quando lo spettatore apre la mail, il suo client scarica
questo script, che legge lo **stato del gioco** (comandato dal pannello via `stato.php`)
e decide quale foto restituire — le **tre fasi**:
- **Spento** → immagine **neutra** (un pixel trasparente): il gioco non è attivo.
- **In corso** (dopo "Avvia") → **Foto A**.
- **Terminato** (dopo "Finisci" **oppure** allo scattare dell'orario di sicurezza) → **Foto B**.
- **Forza A/B** (dallo stato, non più un file): scorciatoia per i test, vince su tutto.
- **Header anti-cache** obbligatori, così lo scambio avviene davvero all'apertura.
- **Log delle aperture** (la "cattura") in `_dati/aperture.csv`: data/ora, id, foto
  mostrata (A/B/neutro), IP, dispositivo. Disattivabile con `ABILITA_LOG = false` in config.
- Parametro `?id=` per dare un URL unico a ogni destinatario (anti-cache + tracciamento).

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
- [x] **Backend a tre fasi** (image/stato/carica/config) + pannello collegato, collaudato in locale.
- [ ] **Caricare i 4 file PHP** (`config.php`, `image.php`, `stato.php`, `carica.php`) in
      `abraka.it/predizione/` e il pannello in `abraka.it/pannello/`; impostare la password
      in `config.php`; provare l'effetto dal vivo (upload foto → Avvia → Finisci).
- [ ] Attivare **Amazon SES** (o Brevo): verifica dominio, SPF/DKIM/DMARC, uscita dalla
      sandbox, richiesta aumento limite giornaliero.
- [ ] Costruire l'**autorisponditore**: legge la casella, per ogni mail invia la risposta
      via SES/Brevo con dentro `<img src="…/image.php?id=…">`. Deve rispondere solo a
      gioco avviato (fase != spento).
- [ ] Collegare il **contatore** del pannello alle mail reali (oggi mostra le aperture del log).
- [ ] Prova completa in piccolo (2–3 mail) prima dello show, su Gmail e su iPhone.

### Snippet dell'immagine nella mail (per l'autorisponditore)
```html
<img src="https://abraka.it/predizione/image.php?id=SPETTATORE1"
     width="500" style="display:block;max-width:100%;border:0" alt="">
```
Un `id` diverso per ogni destinatario (es. email o numero progressivo).
