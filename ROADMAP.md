# PREDIZIONE — Roadmap (stato reale)

> Aggiornata il **17 ottobre 2026** dallo stato VERO del codice e dal
> registro "AGG 12 → 60" in `CLAUDE.md`.
>
> Legenda: ✅ fatto e funzionante · 🟡 fatto nel codice, da confermare se è ONLINE
> o da collaudare dal vivo · 🔴 da fare davvero.

---

## ✅ FATTO E FUNZIONANTE

**Gioco + app del mago**
- Effetto completo a sessioni (Avvia → Cambia → Finisci), foto A→B per-sessione congelate.
- App Regia installabile sul telefono (PWA), con Impostazioni, Aiuto, guida "come si gioca".
- **Foto magic (IA Gemini/Nano Banana)**: collegata — generi la rivelazione dall'app.
- **Assistente di scena**: link temporaneo per far aiutare un'altra persona.
- **Invio diretto a mano** per piccoli gruppi (`invia.php`, fino a 10 indirizzi).
- **Ridimensionamento automatico** delle foto al caricamento (risparmio spazio).

**Robottino su AWS (autorisponditore) — tutto lo "scaling" è fatto**
- Multi-casella (ogni cliente la sua casella), anti-loop, anti-doppione, ON/OFF dal pannello.
- Modi d'invio: **Casella** (SMTP) · **Palco** (Mailgun) · **SES**.
- Parallelo + anti-ingorgo + **split fino a 10 robottini** su una casella piena (provato: 150 mail).
- **Lucchetto lato server** (un turno alla volta, senza "concorrenza riservata" AWS).
- **Sicurezza anti-dimenticanza** (spegnimento automatico se lasci un gioco acceso troppe ore).
- Ronda ~3s, log leggibili (GIRO/PICCO). Costi AWS ≈ **0 €** (budget 1 € + log a 7 giorni).

**Pannello di controllo 000 + report + limiti**
- Utenti, ordini, **listino modificabile**, storico versioni piani, password d'accesso con provenienza.
- Scheda utente: **Entra diretto** nella Regia, provenienza+piano nel titolo, **limiti modificabili a mano**.
- **Limiti per utente** (mail mese/giorno, sessioni, foto magic, assistenti) con azzeramento e blocco.
- **Test invio** integrato (anche da più caselle insieme).
- **Report** di sessione completo: mittenti, aperture (tutti gli orari), diario, **ora d'invio**,
  **colonna Robottino (R1/R2…)**, robottini usati + massimo insieme + tempo di lavoro.

**Vendita (impianto costruito, nel codice)**
- Modello **licenza d'uso** definito; **licenza scritta** (`guide/licenza-uso.html/.txt`); prezzi decisi.
- **Sito vetrina** (`sito/index.html`): video d'ingresso, Accedi, biglietteria. Pagine `acquista.html`,
  `licenza.html`, `prezzi.html`.
- **Ordine completo** (`ordine.php`): numero `PRD-NNNN`, codice di conferma via mail, coordinate di
  pagamento (bonifico/PayPal/Satispay), mail al cliente + `supporto@` + autore, **mail di benvenuto
  con password** e **ricevuta PDF**. Attivazione utente (`attiva_ordine`).

**Rifiniture e robustezza (AGG 41→60, 15-17 ottobre)**
- **Invio a mano "Invia mail"** funziona anche con caselle di **dominio** (login automatico, AGG 41).
- **Guida primo accesso**: tasto **"Salta"**, percorso **"dominio mio"** che configura davvero la casella,
  Gmail con **@gmail.com** già scritto, etichette che si adattano (Gmail/dominio) (AGG 42/43).
- **Pannello 000**: **cambia username** (solo utenti senza sessioni), **tipo casella** (Gmail/Altra) nel titolo,
  nuovo utente con **funzione mail attiva** di default (AGG 42/43/44).
- **SICUREZZA**: la **password della casella non è più leggibile** nella risposta pubblica del server (AGG 44).
- **"Autorisponditore" e "Invia mail" sono funzioni SEPARATE**, attivabili per utente dal pannello 000 (AGG 45).
- **"Caligrafia Foto magic"**: il **prompt dell'IA** è modificabile/creabile e selezionabile dal pannello 000 (AGG 46).
- **AGG 47**: ricevuta PDF col **codice ordine** (es. p90a0, non PRD-0009); mail benvenuto ritoccata; **nuova icona
  Foto magic**; **cartella `guide/`** da caricare nella radice del sito (link guida era 404); form "richiedi accesso"
  con messaggio "Richiesta inviata" + ritorno alla schermata Password.
- **AGG 48 — LISTINO arricchito**: **prezzo pieno (barrato) + sconto** per accesso e piani (anno/mese);
  **interruttore incluso/escluso** per ogni funzione di ogni piano con **icone**; **estensioni**;
  **una tantum per singolo piano** (compaiono nel carrello "scegli"). Tutto additivo, i piani esistenti non cambiano.
- **AGG 49**: link "Prezzi" → ora apre il **confronto piani** (`prezzi.html`), non più la guida hosting.
- **AGG 50**: **omaggio di benvenuto per piano** (Foyer = 10 Foto magic una volta sola), regalato ai nuovi utenti.
- **AGG 51**: **vetrina `prezzi.html` DINAMICA** — legge il listino dall'editor (vedi 🟡, fatto).
- **AGG 52–54**: editor a **righe unificate** per servizio (omaggio · al giorno · al mese), con significato
  corretto (Mail = **limiti** "al mese, max al giorno"; Sessioni = **si sommano** "al giorno + al mese");
  stesse frasi sul sito; accesso di default **105 → 25 (promo)**.
- **AGG 55**: tasti **Salva** e **Vedi prezzi** in cima all'editor Listino.
- **AGG 56**: la base d'accesso **"aaa"** si può **disattivare/ripristinare** dal pannello.
- **AGG 57–58**: pagina prezzi con **righe allineate** per tutti i piani (le voci mancanti in grigio).
- **AGG 59**: **licenza** senza il banner rosso "bozza/commercialista".
- **AGG 60 — ACCESSORI = "secchiello separato"**: i crediti comprati si **consumano davvero** (prima il
  piano del mese, poi i crediti) e **NON si ricaricano** col ripristino; **scadenza in mesi o data precisa**
  impostabile nell'editor. *(Dopo il deploy, fare una prova d'invio reale: è cambiato il conteggio consumi.)*
- **AGG 61**: fix **sconto 100% "gratis"** — piano con prezzo pieno > 0 e pagato = 0 → sul sito prezzo
  pieno barrato + "gratis" + "100% di sconto".
- **AGG 62 — GRAFICI + SCADENZA ACCESSO + ICONA TELEFONO**:
  - scheda utente (000): **grafici nel tempo** (acquisti € a barre; mail/sessioni/Foto magic "che restano"
    a linee), **richiudibili**, con **somma del periodo nel titolo**, e selettore **Mese/Anno/Periodo**
    (endpoint `admin_cronologia`).
  - **scadenza accesso (4 anni) modificabile** dal 000 (data / "rinnova 4 anni" / "non scade"); alla scadenza
    **tutte le funzioni si bloccano** (server) e la Regia mostra **banner "accesso scaduto"**; **promemoria
    un mese prima** in app (banner oro) + **mail automatica** (una sola, via giro del robottino).
  - guida primo accesso: passo finale **"metti l'icona sul telefono"** (iPhone/Android). Service-worker v23.
  *(Dopo il deploy, prova d'invio reale: invia.php/genera_ia.php ora includono piani.php.)*

---

## 🟡 DA CONFERMARE INSIEME (fatto nel codice, ma: è ONLINE? funziona dal vivo?)

- **Ordine online completo**: ✅ **PROVATO dall'autore (16 ott) e FUNZIONA** — il giro
  "visita → codice → biglietteria → ordine → mail" gira, con mail di benvenuto + ricevuta PDF che
  arrivano da `supporto@`. **Tenuto in lista di proposito:** l'autore vuole **rivedere con calma
  tutto il processo** — ma è solo questione di **TESTI / cosa c'è scritto** (pagine, mail, ricevuta),
  non di farlo funzionare.
- **Caselle `supporto@abraka.it` e `accesso@abraka.it`**: ✅ **create e funzionanti** (le mail d'ordine
  partono da `supporto@`). `accesso@` = richieste d'accesso dal sito.
- ✅ **Archiviazione `_dati` ordinata** — **FATTO (17 ott)**: eseguito `migra_001.php`, 122 file di 001
  spostati in `_dati/u001/` (con backup di `stato.json`); i vecchi link `image.php` continuano a funzionare.
- ✅ **Contatore Palco**: confermato dall'autore (17 ott) che il conteggio "Mail inviate/ricevute" in modo Palco **va bene**.
- ✅ **Vetrina `prezzi.html` dinamica — FATTO (AGG 51)**: la pagina vetrina ora legge il listino dall'editor
  (endpoint `ordine.php?azione=listino`) e si costruisce da sola: accesso con prezzo pieno barrato + promo,
  i 4 piani (al giorno/al mese, foto, omaggio 🎁, prezzo pieno barrato), estensioni (una tantum / al mese).
  L'editor del pannello 000 è la "fonte della verità": cambi lì e la pagina si aggiorna. *(acquista.html già
  dinamica da AGG 48.)* Resta eventuale: la vecchia `guide/prezzi-predizione.html` è solo un bozzetto, non serve.
- 🟣 **Pagina prezzi/estensioni — DA RIVEDERE INSIEME** (richiesta autore 17 ott): testi e impaginazione
  della vetrina e della presentazione delle estensioni vanno guardati insieme con calma (è questione di
  "cosa c'è scritto" e layout, non di funzionamento).
- ✅ **Durata accesso (anni) nel listino — FATTO (AGG 62)**: gli anni di validità dell'accesso (default 4)
  si impostano nell'editor Listino (campo "Accesso — durata (anni validi)") e i nuovi ordini li usano.

---

## 🔴 DA FARE DAVVERO

### ⭐ URGENTI (le due cose che contano adesso)
- **A. Pagamento AUTOMATICO.** 🟡 **PayPal automatico COSTRUITO (AGG 64)**: il cliente paga nel sito e il
  sistema **attiva da solo** (crea utente + ricevuta + mail con password). **Resta da fare l'autore:**
  mettere le chiavi in `predizione/_dati/paypal.json` (da developer.paypal.com) e **provare in sandbox**,
  poi passare a **live**. (Facoltativo: webhook firmato per robustezza extra — oggi la cattura è in onApprove.)
  **DECISIONI 18 ott (autore):**
  - **Partenza:** **bonifico** (QR SEPA + copia IBAN/importo/causale col codice ordine) + **PayPal AUTOMATICO**.
  - **Carrello (`acquista.html`) GIÀ FATTO (UI):** tasti pagamento = **Bonifico** · **PayPal** · **Carte**.
    - **PayPal** diventa cliccabile SOLO col totale **≥ 20 €**; sotto, nota in piccolo "aggiungi altri X €".
    - **Carte** (al posto di Satispay): **non cliccabile**, nota "prossimamente in arrivo".
    - **Eccezione:** la **Foto magic di PROVA** (`prova_foto`) abilita PayPal **anche sotto i 20 €**
      (`SOGLIA_PP=20`, `haProva()`), così si può collaudare PayPal con un importo piccolo.
    - **Testo del tasto per metodo** (`labelPaga()`): Bonifico → "Ordina e paga"; PayPal → "Paga e attiva ora".
    - **FIX (AGG 63):** in vetrina `prezzi.html` il piano **gratis (Foyer)** ora risponde al toggle
      Annuale/Mensile (prima mostrava sempre il prezzo annuale).
  - **Soglia minima PayPal = 20 €** (sotto → solo bonifico), **MAI** sovrapprezzo su PayPal.
  - **Da costruire (fase 2):** l'incasso automatico vero (webhook PayPal → `attiva_ordine`).
  - Per PayPal automatico servirà dall'autore: **conto PayPal Business** + **Client ID** + **Secret**.
  - **Voce di PROVA pagamento** nel listino (`prova_foto` = "5 Foto magic (prova)", 0,40 €): **VISIBILE** ai
    clienti (scelta autore, per un test reale). Da togliere dall'editor Listino quando il test è finito.
- **B. Conferma FINALE col commercialista.** Chiudere l'inquadramento fiscale (vedi punto 5): portare
  al commercialista il foglio `guide/domande-commercialista.html` con regime + ATECO e avere la risposta
  definitiva (integrare nella partita IVA esistente, oppure diritto d'autore / Paddle). Finché non è
  confermato, le ricevute e i testi legali restano "bozza".

### ⏳ Da fare in più (NON urgenti)
1. **Mailgun → piano a pagamento.** Ora sei sul Free = **100 mail/giorno**: basta per provare, NON
   per uno show vero (150-500). Prima di un evento reale serve un piano (Basic ~10.000/mese).
2. **Uscire dalla sandbox SES** (opzionale: Mailgun copre già i grandi volumi).
3. **Prova reale "stress"** dello split su uno show grande vero (tante connessioni su una casella piena).

3-bis. **Filtro aperture "foto A" più preciso (idea, NON urgente).** Oggi scarta le aperture di servizio
   SOLO a TEMPO (`PRED_APERTURA_GRACE = 15s` in config.php: primi 15s dall'arrivo + debounce). Rischio:
   una persona VERA che apre entro 15s dall'arrivo viene scartata per sbaglio. **Miglioria:** riconoscere
   il robot di Gmail dal NOME (`GoogleImageProxy`, già usato in `report.php` → "Gmail (anteprima)") e
   scartare QUELLO, **tenendo i 15s come rete di sicurezza** (il nome potrebbe cambiare, e Apple Mail non si
   firma). Così non si perde nessun umano veloce su Gmail. Facoltativo: aggiungere nel report la misura
   "il robot di Gmail ha scaricato dopo N secondi dall'arrivo" per vedere il dato vero degli show.
   **Per ora resta 15s (scelta dell'autore: meglio la rete di sicurezza).**
4. **Parte legale/fiscale** (NON tecnica): la **conferma finale** col commercialista è in URGENTE (B qui sopra);
   qui restano i materiali e le pagine privacy/termini da scrivere DOPO quella conferma.
   - [x] **Foglio domande per il commercialista** pronto: `guide/domande-commercialista.html` (chiaro+scuro,
         stampabile, **versione GENERICA**: descrive solo "software web a licenza/abbonamento", SENZA il
         contesto da palco/mentalismo — scelta dell'autore per non rivelare la professione). **L'autore HA
         GIÀ una partita IVA** (consulenza) → il foglio punta soprattutto a **integrare** le vendite del
         software in quella partita IVA (eventuale ATECO software); in alternativa reddito diverso o Paddle.
   - [ ] Portarlo al commercialista con **regime + codice ATECO** riempiti e avere le risposte.
   - [ ] **Approfondimento in corso** (ricerca in chat separata) sulle DUE strade: (A) integrare le vendite
         software nella **partita IVA esistente (regime ordinario, consulenza)**; (B) via **diritto d'autore /
         opera dell'ingegno** (limiti e interazione con la partita IVA già attiva) + metodi di pagamento
         (incl. Paddle). Volume iniziale piccolo (1º anno sotto i 1.000 €).
   - [ ] In base alle risposte: integrare in partita IVA **oppure** diritto d'autore **oppure** piano Paddle.
   - [ ] **Pagine privacy + termini** sul sito (le scriviamo dopo la conferma del commercialista).

6. **VERIFICHE DI CONSEGNA + "riscaldamento" (DA FARE davvero).** Controllare che le mail
   arrivino in **Posta in arrivo** (non spam) e che i domini siano "caldi". Checklist:
   - [x] **mail-tester.com** (15/10): **9/10** per `abraka.it` (da rifare per ogni dominio Mailgun cliente).
         NB: il primo tentativo diede 4.9 solo perché la mail di prova era VUOTA (no oggetto/testo).
   - [x] **MXToolbox blacklist** (15/10): **pulito**, non in liste nere (3 timeout ininfluenti).
   - [x] **SPF / DMARC**: validi. **SPF** = `include:_spf.th.seeweb.it include:mailgun.org ?all`;
         **DMARC** = `p=none` (va bene; `rua` punta ancora a Brevo → pulizia facoltativa).
   - [x] **Prova inbox reale** (15/10): le mail arrivano in **Posta in arrivo**, NON in spam. ✅
   - [ ] **DKIM su Tophost**: la firma DKIM manca sugli invii (selettore `s1` già in DNS). Attivarla in
         cp.tophost.it o chiederlo all'assistenza → guadagna l'ultimo punto. (Non blocca: già a 9/10.)
   - [ ] **Mailgun**: dominio **verde/verified**, controllare i **Logs** e il **riscaldamento**. Da rifare
         per OGNI nuovo dominio cliente usato con Palco.
   - [ ] (Avanzato, facoltativo) **Google Postmaster Tools**: reputazione del dominio su Gmail.
   - [ ] (Sicurezza, più avanti) DMARC `p=none` → `quarantine`/`reject` SOLO dopo aver verificato che
         TUTTI gli invii (Tophost + Mailgun) passino SPF/DKIM allineati (altrimenti blocci mail vere).

---

## 📌 Decisioni di riferimento (da non perdere)

**Caselle (Tophost)**
- `io@abraka.it` = casella del **gioco** (robottino).
- `supporto@abraka.it` = **commerciale** (ordini, benvenuto, ricevute).
- `accesso@abraka.it` = **richieste d'accesso** dal sito.

**Prezzi (decisi)**
- Accesso **25 €** (dura **4 anni**, Foyer incluso).
- Annuale: **Quinte 66 · Sipario 96 · Palco 228**. Mese singolo: **15 · 30 · 58** (l'annuale conviene).
- Sconto "pagamento diretto" per bonifico/Satispay; **mai** sovrapprezzo PayPal.

**Invio (chi manda cosa)**
- Le mail agli **spettatori** le manda **ciascun cliente**: dal **proprio dominio via Mailgun** (modo
  Palco) o dalla **propria casella** (modo Casella, es. Gmail, eventi piccoli). abraka NON manda più
  mail agli spettatori. Mailgun = **un solo account dell'autore**, multi-dominio (il cliente incolla
  3-4 righe DNS).

**Regola foto**
- **Foto B / rivelazioni: MAI cancellate** (il ricordo resta per sempre → server sempre acceso).
- **Foto A / neutre**: cancellabili dopo ~60 giorni, sostituite da una neutra standard.

**Ricavo futuro (idea)**
- Dopo i 4 anni, per i non più abbonati: piccola quota per **conservare le Foto B** online.
