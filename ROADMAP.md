# PREDIZIONE — Prossimi passi (promemoria)

> Lista delle cose da fare per trasformare l'app in un prodotto vendibile.
> Annotata il 5 ottobre 2026. NIENTE di questo è ancora implementato: è un piano.

---

## ✅ Già fatto (per contesto)
- App funzionante (001) + multi-utente (002 mail Gmail, 003 strumento foto).
- Autorisponditore **multi-casella** (ogni utente la sua Gmail) + anti-loop.
- Report di sessione (mittenti + aperture), conteggi mail reali.
- Indirizzi con `www` (fix redirect/POST).

---

## 1. Subito — gratis e veloce (AWS)
- [ ] **Avviso di spesa**: AWS → Billing → Budgets → budget **1 €** con email.
- [ ] **Pulizia log**: CloudWatch → Log group della Lambda → Retention **7 giorni**.
  - Scopo: restare a **0 €** e accorgersi subito se mai qualcosa costasse.

## 2. Scaling del robottino (quando crescono gli utenti)
- [ ] Far elencare al robottino **solo gli show avviati** (non tutti gli iscritti).
  - [ ] Ogni ~3 giri, fare comunque un **giro completo di sicurezza** (per non perdere nulla).
- [ ] (Più avanti) **parallelo interno**: ogni postino gestisce più caselle insieme → giri di pochi secondi.
  - Nota: la "coda + tanti postini" serve solo a numeri estremi (centinaia di show accesi nello stesso minuto).

## 2-bis. Robottino: non rispondere a mail "sbagliate" (IMPORTANTE)
- [ ] **All'Avvia**: segnare come lette (SENZA rispondere) tutte le mail **già presenti** in casella,
  così la nuova sessione conta solo chi scrive **dopo** l'avvio (niente "arretrati").
- [ ] **Saltare i mittenti automatici/pubblicitari**: `no-reply@`, `noreply@`, `mailer-daemon`,
  `bounce`, newsletter/marketing (es. Mailgun, Google notifiche). Rispondere solo a persone vere.
  - Motivo: su una Gmail dedicata arrivano comunque notifiche e pubblicità → il robottino non deve
    rispondere a quelle (sprecate e brutte). Emerso nel test del 10/10/2026 (report di 002).

## 3. Intelligenza artificiale (foto B)
- [x] **Scelto il modello: Google Nano Banana 2.1** (`gemini-nano-banana-2.1`) — provato il
      10/10/2026 su un foglio reale: scrittura a mano perfetta e credibile. ~0,034 €/foto.
- [x] **Chiave API creata** (progetto "Predizione", credito prepagato 5 € con ricarica
      automatica OFF = tetto di spesa blindato). Input immagine ~259 token = frazione di cent.
- [x] **Prompt collaudati salvati** in `guide/prompt-foto-ia.md` (Prompt 1 base, Prompt 2
      avanzato con regole di grafia naturale; nota: inserire la frase senza parentesi quadre).
- [ ] **Collegare la chiave all'app** (genera.php/nuovo endpoint): il pannello invia la frase
      → il server chiama Nano Banana 2.1 con il Prompt 2 → salva la foto come rivelazione (B).
- [ ] **Multi-riga / lista di previsioni**: scrivere più righe (una per previsione, a capo).
      Metodo incrementale: una riga alla volta, reimmettendo ogni volta la foto precedente
      (vedi `guide/prompt-foto-ia.md`). L'app gestisce il ciclo in automatico.
- [ ] **Definire i crediti**: regola chiara **1 credito = 1 foto generata**.

## 4. Limiti per utente (per rendere i piani imponibili)
- [ ] Contatori per utente: **mail/mese**, **mail/giorno**, **sessioni (mese e giorno)**, **crediti IA**.
- [ ] Azzeramento automatico (giornaliero / mensile) + blocco al raggiungimento del tetto.
- [ ] Gestiti dal pannello admin per ogni utente, secondo il piano acquistato.

## 5. "Assistente di scena" (ex DUO) — COSTRUITO (10/10/2026)
- [x] Un **link** che permette a **un'altra persona** di aiutare il prestigiatore durante lo show.
  L'assistente può preparare la rivelazione (frase + Genera con IA / caricare foto) e comandare
  il gioco (Avvia/Cambia/Finisci); NON vede password, casella mail, report/consumi, né crea altri
  assistenti. Accesso con **link temporaneo** (niente password): valido da quando il performer lo
  condivide fino a **"Finisci"** (fine sessione) o alla revoca.
  Realizzazione: lasciapassare (token) in `stato.json` (`assistente_token`), helper
  `predizione_assistente_valido()`, azioni `assistente_crea`/`assistente_revoca` in stato.php,
  token accettato da stato.php (solo avvia/cambia/finisci), genera_ia.php e carica.php (B/BASE,
  non la Foto A). Pagina dedicata `pannello/assistente.html`. Tasto "Crea link" nel pannello.
- [ ] Nei piani: "2 link" = 2 assistenti abilitabili (serve il sistema limiti §4).

## 5-bis. Admin 000 — da potenziare (richiesto 10/10/2026)
- [ ] **Più funzioni** nel pannello admin (da dettagliare con Flavio).
- [ ] **Accesso a TUTTI i report** dal 000: il 000 deve poter aprire i report di ogni utente
  per monitorare l'uso e capire eventuali problemi.
- [ ] **Report: aggiungere Mail inviate e Mail ricevute** nei riquadri in alto (oggi mostra
  mittenti/aperture; vanno aggiunti i due conteggi mail della sessione).
- [ ] **Ordine dei file in `_dati/` con tanti utenti** (archiviazione): ogni utente nella sua
  cartella `uNNN/`; dentro ogni utente, sottocartelle ordinate (foto/ sessioni/ log/).
  **Deciso (10/10/2026):** puntare alla **versione pulita** con ANCHE il **001 archiviato** in
  `u001/` (migrazione sicura, con backup, provata prima sul 003). Nella radice solo "roba di
  sistema" (utenti.json, .htaccess, gemini.key) + le cartelle `uNNN/`.

## 5-ter. Pannello 000 — struttura decisa (anteprima 10/10/2026)
- [ ] **Home:** riquadri (utenti, in gioco ora, incasso anno, foto IA oggi), **riepilogo acquisti**
  (cosa è stato comprato finora), lista **"In gioco adesso"** (chi ha premuto Avvia), e
  **ricerca/menù** per trovare un utente.
- [ ] **Pagina del singolo utente:** sezione **"Acquistato"** (accesso, piano, estensioni, scadenze,
  totale speso), **tutti i report** delle sue sessioni (apribili), e le **modifiche**
  (aggiungi/cambia abbonamento, aggiungi crediti anche a scadenza, password, casella, sospendi,
  elimina).
- [ ] **Aggiungere crediti a piacimento** ai singoli utenti dal 000: crediti **mail** e crediti
  **IA (foto)**, anche **con scadenza** (es. +100 mail validi 30 giorni, +20 foto IA validi fino a X).
  Serve il sistema di contatori/limiti per utente (vedi §4) come base.

## 5-quater. Sito vetrina "abraka" (da cui si comprano le app) — in design (10/10/2026)
- In alto SOLO il tasto **Accedi**; le **app in primo piano** (per ora 1: Predizione), scorribili
  con le **frecce** per mostrarne altre in futuro; **niente prezzi** in vetrina.
- Sfondo a **tutto schermo** (foto teatro: sipario + pubblico), app come **medaglione tondo** sul palco.
- **Due direzioni candidate (da scegliere):**
  - **A) Vetrina camera** (solo foto): clic sull'app → effetto telecamera dal palco alla platea
    verso il palcoscenico (2 foto + zoom/dissolvenza). Leggera, gratis, istantanea.
  - **B) Vetrina video + app** ⭐: clic sull'app → parte un **video vero** (camera che entra in teatro);
    a fine video il selettore dell'app **sale dal basso al centro** e si cambia app con le frecce.
    Serve il video (generabile con IA "foto→video", ~qualche €, oppure girato). Più "wow".
- Da definire: nome/brand (abraka), dove porta "Accedi" (pannello performer), testi, e per la (B)
  comprimere il video per il web + immagine sicura/libera da diritti per il pubblico.

## 6. Vendita e pagamenti (automazione)
- [ ] **Pagina di acquisto** su abraka.it.
- [ ] **Pagamento automatico** (webhook): pagato → crea utente → invia credenziali via mail, da solo.
  - Cassa consigliata per partire: **Lemon Squeezy / Paddle** (gestiscono loro IVA e fatture),
    oppure **Stripe** (fee più basse ma IVA/fatture a carico tuo).
- [ ] Lo "stato pagato" lo stabilisce il sistema di pagamento (notifica verificata), non a mano.

## 6-bis. Benvenuto e guida (onboarding)
- [x] **Guida utente** con disegni → `guide/guida-registrazione.html` (BOZZA pronta, non ancora online).
  - Da aggiornare a ogni nuova funzionalità (ha "Versione + data" in fondo).
  - Da caricare su abraka.it in una cartella `guide/` quando si vuole.
- [ ] **Mail di benvenuto** (bozza scritta): credenziali `{{NUMERO}}`/`{{PASSWORD}}` + link alla guida.
  - La invierà in automatico il webhook del pagamento (punto 6).
- [ ] Da decidere: nome/brand in cima, contatto assistenza, se fare una guida diversa per piano
  oppure una sola con le parti opzionali segnate.

## 6-ter. Ridimensionamento automatico delle foto (risparmio spazio)
- [ ] Al **caricamento**, ogni foto viene **rimpicciolita in automatico** (nessun tasto):
  - lato lungo max **1280 px**, qualità JPEG **~82%**, proporzioni mantenute, salvata in JPEG.
  - vale per **Foto A, Rivelazione e foto base** (carica.php + genera.php).
  - risultato: da 2–5 MB a ~100–250 KB (−10/20×), senza differenza visibile nella mail (mostrata a 520 px).
- [ ] **Regola di conservazione foto (decisa):**
  - **Foto B / rivelazioni: MAI cancellate.** Il pubblico tiene la mail come ricordo e il trucco non deve svelarsi → restano online per sempre (il server non va mai spento).
  - **Foto A / neutre d'attesa:** cancellabili dopo **60 giorni** e sostituite da una **"Foto A per tutti"** standard condivisa (da scegliere). Sicuro, perché dopo lo show image.php mostra comunque la B.

## 6-quater. Idea di ricavo futura — "Conserva foto"
- [ ] Dopo i **5 anni** (o alla scadenza), per i **non più abbonati**: offrire a pagamento la
  **conservazione delle Foto B** (il "ricordo" resta online). Esempio: piccola quota annua per
  tenere vive le rivelazioni. Sostiene la promessa "foto per sempre" e diventa un ricavo.

## 6-quinquies. Invio diretto (senza autorisponditore) — per piccoli gruppi
- [ ] Modalità alternativa per **pochi spettatori (max ~5)**: il performer **non** aspetta che
  gli scrivano; **inserisce lui gli indirizzi** nel pannello e **invia la mail direttamente**
  dall'app. "Datemi il vostro indirizzo che vi scrivo io."
- [ ] Utile quando non si vuole usare il flusso "scrivetemi → rispondo". Riusa l'invio già
  esistente (Gmail/casella), solo con destinatari digitati a mano.

## 7. Parte legale/fiscale (NON tecnica)
- [ ] Verificare coerenza con la **Partita IVA** (vendere software = fatture + IVA).
- [ ] Pagine legali minime (termini, privacy) sul sito.
- [ ] Valutare "merchant of record" (Lemon Squeezy/Paddle) per semplificare IVA internazionale.

## 8. Vendita come AUTORE (licenza d'uso, senza merchant of record) — DA COSTRUIRE
> Annotato il 10 ottobre 2026. Ipotesi di lavoro: il performer non compra "un servizio" ma una
> **licenza d'uso dell'opera PREDIZIONE** (diritto d'autore, art. 53 c.2 lett. b TUIR; fuori campo
> IVA art. 3 c.4 lett. a DPR 633/72). Niente IVA, niente fattura elettronica, niente INPS; ricevuta
> semplice con bollo 2 € sopra 77,47 €; incasso diretto senza commissione fissa.
> **Da confermare con il commercialista prima di andare online.** Se non conferma, si torna a Paddle (§7).
> Partita IVA personale (70.20.09, ordinario) NON si tocca: questi redditi viaggiano separati (quadro RL).

### 8.1 Regole decise
- **Tre canali in fase 1:** bonifico SEPA (0 €), Satispay Business con richiesta manuale dall'app
  (0 € sotto 10 €, poi ~0,20 €), PayPal con link PayPal.Me a importo fisso (3,4% + 0,35 €).
  PayPal è anche il canale per l'**estero**; Stripe arriva in fase 2 quando ci sono clienti esteri veri.
- **Sconto, MAI sovrapprezzo.** In Italia è vietato far pagare la commissione al cliente
  (D.Lgs. 11/2010 art. 3 c.4, mod. D.Lgs. 218/2017) e lo vietano anche le regole PayPal.
  Quindi: **prezzo di listino unico** (copre PayPal) e **sconto "pagamento diretto"** per Satispay/bonifico
  che riporta al prezzo di tabella.
- **Extra sotto 10 € solo con Satispay o bonifico** (con PayPal su 1 € si perde il 38%).
- **Gli extra sono "estensioni della licenza d'uso"** (più invii, più sessioni, utenza assistente,
  funzione Report), MAI "assistenza" o "servizio": nella ricevuta si scrive ciò che amplia la licenza.
  Le **foto IA** sono il punto debole (si paga Gemini per produrle → somiglia a un servizio):
  tenerle incluse nei piani; se vendute a parte, un solo pacchetto grande (50 foto / 10 €).
  Alzare la soglia minima degli extra a 2–3 € accorpandoli (meno micro-ordini da attivare a mano).
- **Listino unico (tondo) e sconto diretto:**

| Prodotto | Prezzo tabella | Listino (PayPal) | Sconto Satispay/bonifico |
|---|---|---|---|
| Accesso 5 anni | 25 € | **27 €** | −2 € |
| Quinte mensile / annuale | 6 € / 60 € | **7 € / 65 €** | −1 € / −5 € |
| Sipario mensile / annuale | 9 € / 90 € | **10 € / 95 €** | −1 € / −5 € |
| Palco mensile / annuale | 19 € / 190 € | **21 € / 199 €** | −2 € / −9 € |
| 5.000 invii Palco | 7 € | **8 €** | −1 € |
| +200 invii · 5 sessioni · Duo 30 gg · Report 30 gg | 1 € · 2 € · 2 € · 1 € | solo Satispay/bonifico | — |
| 50 foto IA (se tenute) | 9 € | **10 €** | −1 € |

- **Fiscale:** ricevuta per diritti d'autore (non fattura), numerata per anno, bollo 2 € se > 77,47 €;
  privati: nessuna ritenuta; aziende italiane: ritenuta 20% sul 75% applicata da loro.
  UE sotto 10.000 €/anno B2C → regole italiane, niente OSS. Extra-UE: se diventano tanti → Paddle solo per loro.

### 8.2 Iter (scenario: ordine lunedì 1 feb ore 10:00, Quinte annuale)
Parte comune automatica (PC): `ordine.php` crea `PRD-NNNN` (in attesa; salva mail, telefono, prodotto,
canale, importo dovuto, accettazione licenza con data/ora/IP) → mail "come pagare" al cliente (+ licenza)
→ mail "nuovo ordine" a me → promemoria a +3 gg, scaduto a +7 gg (il robottino AWS chiama
`ordini.php?azione=scadenze` una volta l'ora: Tophost non ha cron affidabile).
Quando l'ordine diventa **pagato** → **`attiva_ordine()`** (UNICA funzione, chiamata dal mio clic o da un
callback): crea utente / estende limiti → ricevuta PDF (FPDF) → mail benvenuto (credenziali + ricevuta +
guida + licenza) → riga in `_dati/vendite.csv` → riquadro incassi in admin.

| Canale | Passo "in attesa → pagato" | Cliente attivo dopo | Lavoro mio |
|---|---|---|---|
| Bonifico | vedo l'accredito (giorno lav. dopo), premo **Attiva** | ~1 giorno (30 min se istantaneo) | 1 min |
| Satispay manuale | mail ordine → richiesta dall'app Business al numero del cliente (20 s) → paga → **Attiva** | ~30 min | 1 min |
| PayPal link | mail PayPal "hai ricevuto…" con nota PRD → **Attiva** | ~20 min | 30 s |
| Satispay API (fase 2) | `paga.php` crea pagamento → `satispay-callback.php` verifica ACCEPTED → attiva da solo | 2 min | 0 |
| Stripe (fase 2) | Checkout/Payment Link → `stripe-webhook.php` → attiva da solo | 2 min | 0 |
| PayPal webhook (fase 2) | pulsante Checkout → `paypal-webhook.php` → attiva da solo | 2 min | 0 |

Soldi sul conto: bonifico subito; Satispay bonifico mensile gratis (impostare **mensile**);
PayPal al prelievo (1 g); Stripe 2–7 gg.

### 8.3 Attivazione: chi fa cosa (stato attuale del codice)
- **IO:** vedo il pagamento → admin (utente 000) → oggi "Crea utente" (numero, password, flag mail/foto)
  e mail a mano; domani tasto **Attiva** sull'ordine (o **Aggiungi** sull'utente per gli extra).
  **Lambda: NON si tocca.** Il robottino chiede a ogni giro `admin_caselle` e prende da solo gli utenti
  attivi con casella configurata (eccezione: 001 è cablato nelle env). Niente da fare su Tophost/SES/Brevo.
- **PC:** `admin_crea` in `utenti.json` (attivo, permessi dal piano) → cartella `_dati/NNN/` → piano e
  limiti (DA COSTRUIRE: oggi non esistono piani) → ricevuta → mail benvenuto → `vendite.csv` → conferma a me.
- **CLIENTE (una volta):** installa la PWA → login numero+password (la cambia) → su Google attiva la
  verifica in 2 passaggi e crea una **password per app** → Impostazioni → "La tua casella" → Salva →
  oggetto/testo mail → interruttore Autorisponditore ON → prova con 1 mail (apre DOPO il Cambia).
  Operativo in ~30 min senza il mio intervento. Punto critico d'assistenza: la password per app Google
  (curare quella pagina della guida).
- **CLIENTE (ogni show):** carica rivelazione → Avvia → Cambia → Finisci.

### 8.4 Da costruire (in ordine)
- [x] **(a) Contratto di licenza d'uso (EULA)** → `guide/licenza-uso.html` + `guide/licenza-uso.txt` (BOZZA v1.0 del 10/10/2026;
      foro di Lecce; spunta di sola presa visione, la rinuncia al recesso sta nell'art. 9; fornitori citati solo come
      "servizi gestiti da terzi"; dicitura fiscale della ricevuta da concordare col commercialista).
      **Dati personali dell'autore:** nei file del repo (PUBBLICO) solo nome, città e mail. Indirizzo completo e
      codice fiscale vanno in un file sul server NON versionato (`server/_dati/autore.json`, cartella già in
      `.gitignore`) e compaiono solo nella ricevuta PDF e nella mail d'ordine al cliente. Pagina licenza con
      `noindex`, linkata solo dal flusso d'ordine.
      (da allegare alla mail di benvenuto e far accettare al checkout con checkbox).
      Contenuti: oggetto (licenza non esclusiva, non trasferibile), durata piani, limiti inclusi,
      estensioni, divieto di rivendita/uso fuori dal palco (vincoli etici §6 CLAUDE.md), foto B per sempre
      vs foto A 60 gg, assistenza, recesso, privacy spettatori, foro. **Portarlo al commercialista.**
- [ ] **(b) Nuovo listino a LIVELLI DI LICENZA** in `guide/prezzi-predizione.html`: listino tondo + sconto
      diretto; extra piccoli solo Satispay/bonifico; foto IA incluse nei piani. Aggiornare la tabella piani sotto.
- [ ] **(c) `ordine.php` + pagina "Paga"**: scelta prodotto e canale, mail+telefono, checkbox licenza,
      codice `PRD-NNNN`, istruzioni per canale (IBAN+causale / "riceverai richiesta Satispay" + QR /
      link `paypal.me/<nome>/<importo>EUR` + "scrivi il codice nella nota"), mail cliente e mail a me,
      promemoria/scadenza via chiamata oraria del robottino. Dati in `_dati/ordini.json`.
- [ ] **(d) `admin.html`**: elenco **Ordini in attesa** con **Attiva**; `attiva_ordine()` in `stato.php`
      (nuova azione `admin_attiva_ordine`); **Aggiungi** extra su utente; ricevuta PDF (FPDF, PHP puro)
      numerata per anno in `_dati/ricevute/`; `_dati/vendite.csv`; riquadro **Incassi dell'anno**.
      Mail di benvenuto automatica via SMTP Tophost (stesso del robottino).
- [ ] **(e) Piani e limiti per utente** (vedi §4): necessari perché "Attiva" assegni Quinte/Sipario/Palco.
- [ ] **(f) Fase 2 automazione**: `paga.php` + `satispay-callback.php` (API Satispay: attivazione canale
      online, chiavi RSA, tariffa 1,5% + 0,20 €), `stripe-webhook.php` per l'estero, `paypal-webhook.php`.
      Tutti chiamano la stessa `attiva_ordine()`.
- [ ] **Satispay Business da aprire** (professionista: certificato P.IVA, documento, IBAN); bonifico
      **mensile**; creare i link a importo fisso per i piani principali. Verificare tariffe al momento.

---

### Piani — DEFINITIVI (nomi "teatro"): Foyer → Quinte → Sipario → Palco
**Accesso all'app: 25 € / 5 anni** (una tantum, per tutti — scritto in piccolo ma chiaro). Poi si sceglie il livello.

| | Foyer | Quinte | Sipario | Palco |
|---|---|---|---|---|
| Prezzo/mese | 0 € | 6 € | 9 € | 19 € |
| Prezzo/anno (10 mesi, confermato) | — | 60 € | 90 € | 190 € |
| Sessioni | 12/mese | 1/g + 5/mese | 2/g + 10/mese | 3/g + 15/mese |
| Mail | 85/mese | 500/mese (100/g) | 1.500/mese (150/g) | 3.500/mese (funz. Palco, no limite giorno) + Gmail nei suoi limiti |
| Foto IA incluse | 10 (una tantum) | 15/mese | 30/mese | 100/mese |
| Assistente Duo | ✗ (a pagamento) | incluso | incluso | incluso |
| Report sessione | ✗ (a pagamento) | incluso | incluso | incluso |

Note:
- **Strumento foto manuale ELIMINATO**: esistono solo le **foto IA** (a crediti).
- **"Grandi invii" (Mailgun) INCLUSO nel Palco** (niente setup separato).
- Sessioni: si consumano **prima quelle del giorno**, poi il bonus del mese.
- Foyer "0 €/mese" ma richiede l'accesso 25 €/5 anni; prezzo modificabile, chi compra mantiene le sue condizioni.

### Acquisti extra (à la carte)
| Opzione | Prezzo | Costo per te |
|---|---|---|
| +200 mail (Gmail) | 1 € | ~0 € |
| 10 crediti foto IA | 2,5 € | ~0,40 € |
| 5 crediti sessione | 2 € (~0,40/cad) | ~0 € |
| Assistente Duo (solo Foyer) | 2 € / 30 gg | ~0 € |
| Report sessione (solo Foyer) | 1 € / 30 gg | ~0 € |
| 5.000 mail (funzione Palco) | 7 € (a costo) | ~7 € (Mailgun) |

- Costi vivi: foto IA ~0,04 €/foto; sessioni ~0 €; Duo/Report ~0 €; mail Mailgun ~1,4 €/1.000.
- Le **mail Palco** si vendono **a costo**: il guadagno è l'abbonamento (19 €/mese), non il pacchetto mail.

### Costi di riferimento (ad oggi)
- Hosting Tophost Topweb: ~19 €/anno (20 GB). Upgrade: Plus 30 GB / Ultra 50 GB.
- AWS robottino: **0 €** (piano gratuito perpetuo, con le regole del punto 1).
- IA: ~0,04 €/foto (spesso gratis a basso volume).
- Invio: Gmail gratis (~300–400/giorno prudenziale); eventi grandi → Brevo/Mailgun + dominio.
- Storage foto: ridimensionare + cancellare le vecchie per restare piccoli; cloud ~1–2 €/mese per 100 GB.
