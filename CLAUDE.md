# PREDIZIONE — Passaggio di consegne

> Documento di handover del progetto. Spiega **cosa fa** l'app, **com'è fatta**,
> le **decisioni prese** e **cosa manca ancora**. Leggilo prima di metterci mano.

Ultimo aggiornamento: 28 settembre 2026.

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

Quattro pezzi. Solo il primo è costruito; gli altri sono progettati ma da realizzare.

```
   Spettatore                 Sistema del performer
  ┌──────────┐   mail in    ┌───────────────────────────┐
  │  scrive  │ ───────────► │  Casella (hosting Aruba)   │
  │  la mail │              │            │               │
  └──────────┘              │            ▼               │
       ▲                    │   Autorisponditore  [TODO] │
       │  risposta con      │            │               │
       │  <img> remota      │            ▼               │
       │                    │   Servizio invio (SES/Brevo)
       │  apre la mail      └───────────────────────────┘
       │  ─── GET image.php?id=… ───►  image.php  [PRONTO, da deployare]
       │                                    │
       │  ◄─── Foto A o Foto B ──────────────┘
       │        (decisa in base all'ora)
```

1. **Pannello di regia** (`pannello/regia-predizione.html`) — l'app del performer.
   *Front-end, costruito e pubblicato come Artifact.*
2. **Immagine dinamica** (`server/image.php`) — il cuore dell'effetto.
   *Scritto, pronto da caricare sull'hosting.*
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
│   └─ regia-predizione.html       ← pannello di controllo del performer (front-end)
├─ server/
│   ├─ image.php                   ← immagine dinamica (backend dell'effetto)
│   └─ ISTRUZIONI.txt              ← come caricare image.php su hosting PHP
└─ guide/
    └─ guida-acquisti.html         ← cosa comprare (dominio, hosting, invio) a confronto
```

### `pannello/regia-predizione.html`
App mobile in verticale, singolo file HTML (CSS e JS inline, nessuna dipendenza).
Due schermate: **Regia** (contatore mail, stato, Inizia/Finisci gioco) e
**Impostazioni** (ingranaggio in alto → carico Foto A / Foto B, orario dello scambio,
testo del messaggio, toggle "Attiva cattura"). Estetica: nero, testo crema/oro,
pulsanti arrotondati, forma d'onda a puntini animata (canvas) dietro il titolo.

**Stato attuale (importante):** è un **front-end / mockup interattivo**.
- Il contatore è **simulato** (mail finte ogni pochi secondi) solo per mostrare
  l'animazione. Non è collegato a mail reali.
- Il caricamento foto funziona lato client (anteprima) ma **non persiste**: ricaricando
  la pagina si azzera. Nessun salvataggio permanente ancora.

### `server/image.php`
Il motore dell'effetto. Quando lo spettatore apre la mail, il suo client scarica
questo script, che decide quale foto restituire:
- **Automatico a orario:** prima di `$ORARIO_SCAMBIO` → `foto_a.jpg`; dopo → `foto_b.jpg`.
- **Forzatura manuale (opzionale):** se esiste `forza.txt` con dentro `A` o `B`,
  vince quello e ignora l'orario. Serve per test e per controllo dal vivo.
- **Header anti-cache** obbligatori, così lo scambio avviene davvero all'apertura.
- **Log delle aperture** (la "cattura") in `aperture.csv`: data/ora, id spettatore,
  foto mostrata, IP, dispositivo. Disattivabile con `$ABILITA_LOG = false`.
- Parametro `?id=` per dare un URL unico a ogni destinatario (anti-cache + tracciamento).

Da configurare prima dell'uso: fuso orario, `$ORARIO_SCAMBIO`, nomi dei file foto.
Vedi `server/ISTRUZIONI.txt`.

---

## 4. Decisioni prese (e perché)

- **Immagine remota, non allegato.** La foto è un `<img src="…/image.php">`: viene
  scaricata a ogni apertura. È ciò che permette lo scambio A→B ed è tecnologia standard
  (stessa dei pixel di tracciamento). Non appesantisce la mail e non costa traffico SES.

- **Scambio deciso al momento dell'apertura**, non alla spedizione. Ogni spettatore
  resta con la versione vista alla *sua* prima apertura (per via della cache — vedi sotto).

- **Header anti-cache** in `image.php`. Senza, il client congelerebbe subito la prima
  foto e lo scambio non avverrebbe.

- **`?id=` per destinatario.** Doppio scopo: aggirare parte della cache condivisa e
  registrare chi apre/quando (funzione "cattura" del pannello).

- **Forzatura manuale via `forza.txt`.** Oltre all'orario automatico, il performer può
  forzare A o B dal vivo. Semplice (un file di testo), nessun database.

- **Nessun database, un solo file PHP.** L'effetto non lo richiede: massima semplicità
  di deploy su hosting economici. Log su CSV, stato su file di testo.

- **Front-end come Artifact separato dal backend.** Il pannello è HTML puro, provabile
  su telefono senza server. Il "motore" vero (mail + foto) è separato e gira su hosting.

- **Invio via servizio dedicato (SES/Brevo), non dalla casella normale.** 500 mail in
  raffica da una Gmail/casella normale = blocco + spam. Un ESP con dominio autenticato
  (SPF/DKIM/DMARC) è l'unico modo affidabile. SES ≈ costo nullo ma più tecnico; Brevo
  più semplice ma il gratis è 300/giorno (per 500 serve lo Starter ~9 €/mese).

- **Hosting Aruba come base.** Tutto-in-uno (dominio + email + PHP in italiano), la via
  più semplice per caricare `image.php`. Alternative valutate: Netsons, Keliweb.

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

- [ ] Acquistare **dominio + hosting Aruba** (vedi `guide/guida-acquisti.html`).
- [ ] Caricare `image.php` + `foto_a.jpg` + `foto_b.jpg` sull'hosting e provarlo.
- [ ] Attivare **Amazon SES** (o Brevo): verifica dominio, SPF/DKIM/DMARC, uscita dalla
      sandbox, richiesta aumento limite giornaliero.
- [ ] Costruire l'**autorisponditore** (script su hosting consigliato): legge la casella,
      per ogni mail invia la risposta via SES/Brevo con dentro `<img src="…/image.php?id=…">`.
- [ ] Collegare il **contatore reale** del pannello al conteggio delle mail (oggi è simulato).
- [ ] (Opzionale) Persistenza delle impostazioni/foto nel pannello.
- [ ] Prova completa in piccolo (2–3 mail) prima dello show.

### Snippet dell'immagine nella mail (per l'autorisponditore)
```html
<img src="https://ILTUODOMINIO/predizione/image.php?id=SPETTATORE1"
     width="500" style="display:block;max-width:100%;border:0" alt="">
```
Un `id` diverso per ogni destinatario (es. email o numero progressivo).
