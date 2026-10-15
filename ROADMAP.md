# PREDIZIONE — Roadmap (stato reale)

> Aggiornata il **15 ottobre 2026** ricostruendo lo stato VERO dal codice e dal
> registro "AGG 12 → 40" in `CLAUDE.md`. La versione vecchia (5-10 ottobre) era
> rimasta indietro: molte voci segnate "da fare" sono in realtà **già fatte**.
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

---

## 🟡 DA CONFERMARE INSIEME (fatto nel codice, ma: è ONLINE? funziona dal vivo?)

- **Sito + acquisto online**: i file ci sono nel progetto. Verificare che siano **caricati su
  abraka.it** e che il giro "visita → codice → biglietteria → ordine → mail" funzioni dal vivo.
- **Caselle `supporto@abraka.it` e `accesso@abraka.it`**: usate dal codice (mail d'ordine, benvenuto,
  richieste d'accesso). Verificare che siano **create su Tophost** e che le mail partano davvero.
- **Mail di benvenuto / ricevuta PDF**: codice pronto; fare **una prova reale** di un ordine completo.
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
3. **Foto magic — lista di previsioni** (più righe, una per previsione, con metodo incrementale).
4. **Uscire dalla sandbox SES** (opzionale: Mailgun copre già i grandi volumi).
5. **Prova reale "stress"** dello split su uno show grande vero (tante connessioni su una casella piena).
6. **Parte legale/fiscale** (NON tecnica): commercialista (ricevute/redditi), pagine privacy/termini sul sito.

7. **VERIFICHE DI CONSEGNA + "riscaldamento" (DA FARE davvero).** Controllare che le mail
   arrivino in **Posta in arrivo** (non spam) e che i domini siano "caldi". Checklist:
   - [ ] **mail-tester.com**: mandare 1 mail e leggere il **voto /10** (obiettivo ≥ 8). Da fare per:
         (a) `abraka.it` (mail commerciali da `supporto@`), (b) il **dominio Mailgun** di ogni cliente (Palco).
   - [ ] **MXToolbox blacklist**: controllare che dominio/IP **non** siano in liste nere (tutto verde).
   - [ ] **SPF / DKIM / DMARC**: verificare che i record esistano e siano corretti (li segnala anche mail-tester).
   - [ ] **Prova inbox reale**: inviare a **Gmail + Outlook/Hotmail + iCloud** e vedere se finiscono in
         Posta in arrivo o in Spam.
   - [ ] **Mailgun**: dominio **verde/verified**, controllare i **Logs** (consegnate vs spam/bounce) e
         il **riscaldamento** (mandare poche alla volta all'inizio, poi salire).
   - [ ] (Avanzato) **Google Postmaster Tools**: reputazione del dominio su Gmail (Alta/Media/Bassa).
   - **Nota:** il "riscaldamento" è una reputazione, non un numero unico: si misura con questi strumenti
     messi insieme. Va rifatto per OGNI nuovo dominio cliente usato con Palco.

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
