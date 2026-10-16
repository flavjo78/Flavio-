# PREDIZIONE — Roadmap (stato reale)

> Aggiornata il **15 ottobre 2026 (sera)** dallo stato VERO del codice e dal
> registro "AGG 12 → 46" in `CLAUDE.md`.
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

**Rifiniture e robustezza (AGG 41→46, 15 ottobre sera)**
- **Invio a mano "Invia mail"** funziona anche con caselle di **dominio** (login automatico, AGG 41).
- **Guida primo accesso**: tasto **"Salta"**, percorso **"dominio mio"** che configura davvero la casella,
  Gmail con **@gmail.com** già scritto, etichette che si adattano (Gmail/dominio) (AGG 42/43).
- **Pannello 000**: **cambia username** (solo utenti senza sessioni), **tipo casella** (Gmail/Altra) nel titolo,
  nuovo utente con **funzione mail attiva** di default (AGG 42/43/44).
- **SICUREZZA**: la **password della casella non è più leggibile** nella risposta pubblica del server (AGG 44).
- **"Autorisponditore" e "Invia mail" sono funzioni SEPARATE**, attivabili per utente dal pannello 000 (AGG 45).
- **"Caligrafia Foto magic"**: il **prompt dell'IA** è modificabile/creabile e selezionabile dal pannello 000 (AGG 46).

---

## 🟡 DA CONFERMARE INSIEME (fatto nel codice, ma: è ONLINE? funziona dal vivo?)

- **Ordine online completo**: ✅ **PROVATO dall'autore (16 ott) e FUNZIONA** — il giro
  "visita → codice → biglietteria → ordine → mail" gira, con mail di benvenuto + ricevuta PDF che
  arrivano da `supporto@`. **Tenuto in lista di proposito:** l'autore vuole **rivedere con calma
  tutto il processo** — ma è solo questione di **TESTI / cosa c'è scritto** (pagine, mail, ricevuta),
  non di farlo funzionare.
- **Caselle `supporto@abraka.it` e `accesso@abraka.it`**: ✅ **create e funzionanti** (le mail d'ordine
  partono da `supporto@`). `accesso@` = richieste d'accesso dal sito.
- **Archiviazione `_dati` ordinata** (anche 001 dentro `u001/`): c'è `migra_001.php`; verificare se la
  migrazione è stata fatta o va ancora eseguita.
- **Contatore Palco**: sistemare il conteggio "Mail inviate/ricevute" in modo Palco (in corso).

---

## 🔴 DA FARE DAVVERO

1. **Mailgun → piano a pagamento.** Ora sei sul Free = **100 mail/giorno**: basta per provare, NON
   per uno show vero (150-500). Prima di un evento reale serve un piano (Basic ~10.000/mese). ⭐
2. **Pagamento AUTOMATICO (webhook).** Oggi segni "pagato" a mano nel 000 e parte l'attivazione.
   Fase 2: il sistema di pagamento (Stripe/Paddle/PayPal) conferma da solo → utente creato e mail
   inviata senza intervento.
3. **Uscire dalla sandbox SES** (opzionale: Mailgun copre già i grandi volumi).
4. **Prova reale "stress"** dello split su uno show grande vero (tante connessioni su una casella piena).
5. **Parte legale/fiscale** (NON tecnica): commercialista (ricevute/redditi), pagine privacy/termini sul sito.
   - [x] **Foglio domande per il commercialista** pronto: `guide/domande-commercialista.html` (chiaro+scuro,
         stampabile, **versione GENERICA**: descrive solo "software web a licenza/abbonamento", SENZA il
         contesto da palco/mentalismo — scelta dell'autore per non rivelare la professione). **L'autore HA
         GIÀ una partita IVA** (consulenza) → il foglio punta soprattutto a **integrare** le vendite del
         software in quella partita IVA (eventuale ATECO software); in alternativa reddito diverso o Paddle.
   - [ ] Portarlo al commercialista con **regime + codice ATECO** riempiti e avere le risposte.
   - [ ] In base alle risposte: integrare in partita IVA **oppure** ricevuta occasionale **oppure** piano C (Paddle).
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
