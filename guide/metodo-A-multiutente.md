# PREDIZIONE — Metodo A (centralizzato / multi-utente)

> Documento di lavoro: come trasformare PREDIZIONE in un servizio "scaricabile"
> da più prestigiatori, per spettacoli **piccoli e grandi**.
> Linguaggio semplice, con le **tappe** per realizzarlo.
> Ultimo aggiornamento: ottobre 2026.

---

## 1. L'idea in una frase

**Tu** tieni UN solo sistema centrale (server + autorisponditore + invio). Ogni
prestigiatore **scarica solo il pannello** (l'app PWA) e **fa login col suo
numero** (001, 002, 003…). Non deve installare né configurare niente di tecnico:
apre l'app, entra, gioca.

Tu offri il servizio; loro pagano una quota (abbonamento o a spettacolo).

---

## 2. La regola di ferro (ripasso)

Il trucco ha sempre bisogno di 3 pezzi:

| Pezzo | Cosa fa | Dove sta (Metodo A) |
|---|---|---|
| 🧠 Motore immagine (PHP + foto) | serve la foto A/B che cambia | **il TUO server** (Tophost/abraka.it), per tutti |
| 📬 Casella | dove scrivono gli spettatori | la mail **del prestigiatore** (Gmail o suo dominio) |
| ✈️ Invio risposte | manda la mail con la foto | piccoli = sua casella · grandi = **tuo Mailgun** col **suo** dominio |

Il **motore immagine non può stare su Gmail**: per questo lo ospiti tu, una
volta, per tutti.

---

## 3. La domanda chiave: "il mittente è sempre il loro?"

Sì, ma con un limite onesto e inaggirabile sui numeri grandi:

### 3a. Prestigiatore CON un dominio (es. `mario.it`)
- Aggiungiamo **il suo dominio** al **tuo** Mailgun (si possono mettere tanti
  domini in un solo account Mailgun).
- Lui aggiunge **2 record DNS** al suo dominio (SPF + DKIM) → Mailgun è
  "autorizzato" a spedire a suo nome.
- Risultato: anche nei **grandi eventi**, la mail parte **da `io@mario.it`**,
  passando dal tuo Mailgun. ✅ Mai `abraka.it`.

### 3b. Prestigiatore con SOLO Gmail (niente dominio)
- **Piccoli eventi (~20-50):** l'autorisponditore legge la sua Gmail e risponde
  **dalla sua Gmail** (invio diretto Gmail). Mittente = la sua Gmail. ✅
- **Grandi eventi (centinaia):** ❌ **non è possibile** mandare in raffica
  "da @gmail.com". Gmail può essere "firmato" solo da Google: un invio di massa
  a suo nome da fuori viene marcato come falso → spam/blocco. **Non è un limite
  del nostro codice, è una regola di Internet.**
  - **Soluzione:** per fare grandi eventi serve un **dominio** (anche economico,
    ~10€/anno). Possiamo offrirlo come parte del "pacchetto grandi eventi":
    glielo prepariamo noi, resta suo, e il mittente è il suo dominio.
  - **Alternativa di ripiego** (se proprio non vuole un dominio): mandare da un
    **dominio neutro di servizio** (NON abraka.it) col suo **nome** visibile e
    `Rispondi-a:` la sua Gmail. Funziona, ma il mittente non è "la sua mail":
    lo sconsigliamo, lo teniamo come ultima spiaggia.

### Riassunto mittente
| Ha… | Piccoli eventi | Grandi eventi | Mittente |
|---|---|---|---|
| Solo Gmail | ✅ dalla sua Gmail | ❌ (serve un dominio) | sua Gmail (piccoli) |
| Dominio | ✅ dalla sua casella | ✅ dal tuo Mailgun | sempre il **suo** dominio |

---

## 4. Lo schema del sistema

```
        PRESTIGIATORI                          IL TUO SISTEMA CENTRALE
   ┌───────────────────────┐
   │ 001 (Gmail)           │  login     ┌─────────────────────────────────┐
   │ 002 (dominio mario.it)│ ─────────► │  Pannello (PWA) → punta al tuo    │
   │ 003 (dominio ...)     │            │  server; ognuno vede SOLO i suoi  │
   └───────────────────────┘            │  dati                             │
                                        │                                   │
   Spettatori ── mail ──► casella del   │  SERVER Tophost (abraka.it)        │
                         prestigiatore  │   └ image.php?u=001&s=N&id=…       │
                              │         │   └ _dati/001/… _dati/002/…        │
                              ▼         │       (foto e stato SEPARATI)      │
                        AUTORISPONDITORE│                                   │
                        (AWS Lambda)    │  Per OGNI account sa:              │
                        legge le caselle│   - quale casella leggere (IMAP)  │
                        di tutti e       │   - come inviare (Gmail o Mailgun)│
                        risponde         │   - quale mittente (il SUO)       │
                                        └─────────────────────────────────┘
                                             ✈️ invio: Gmail (piccoli)
                                                       Mailgun (grandi, loro dominio)
```

---

## 5. Cosa c'è da costruire (le tappe)

### FASE 1 — Multi-utente nel motore (separazione dati)
Obiettivo: ogni account ha le **sue** foto, sessioni, aperture, password.
- [ ] Introdurre il concetto di **account** (001, 002…): una cartella dati per
      ciascuno (`_dati/001/`, `_dati/002/`), con dentro `stato.json`, foto, log.
- [ ] `image.php` accetta `?u=ACCOUNT` oltre a `?s=` e `?id=`, e serve le foto
      di QUEL account.
- [ ] `stato.php` e `carica.php` lavorano dentro la cartella dell'account.
- [ ] **Login**: l'utente (001) non è più finto ma **seleziona l'account**; la
      password è verificata per quell'account (elenco account sul server, con
      password separate, mai in chiaro nel pannello).
- [ ] Il **pannello** invia l'account a ogni comando (resta memorizzato dopo il
      login).
- *Effetto: il trucco funziona identico, ma per N prestigiatori separati.*

### FASE 2 — Autorisponditore multi-utente
Obiettivo: un solo programma su AWS serve tutti.
- [ ] Una **tabella di configurazione** per account: tipo casella (Gmail/dominio),
      credenziali IMAP (per leggere la posta), modo di invio (Gmail/Mailgun),
      indirizzo mittente (il SUO).
- [ ] La Lambda, a ogni giro, **scorre tutti gli account attivi**, legge la
      casella di ciascuno e risponde con `image.php?u=ACCOUNT&s=…&id=…`.
- [ ] Invio scelto per account: **Gmail SMTP** (piccoli) o **Mailgun col loro
      dominio** (grandi).
- [ ] Le credenziali (password per app Gmail, ecc.) salvate **cifrate** sul
      server, mai nel pannello.

### FASE 3 — Onboarding (far entrare un nuovo prestigiatore)
Obiettivo: aggiungere un prestigiatore in modo semplice e guidato.
- [ ] Una piccola **pagina admin** (solo tua) per creare un account: numero,
      password, tipo casella, mittente.
- [ ] **Guida per il prestigiatore**, due versioni:
      - *Solo Gmail*: attiva la verifica in 2 passaggi → crea una "password per
        app" → ce la comunica in modo sicuro → installa il pannello → login.
      - *Con dominio*: aggiunge 2 record DNS (glieli diamo noi) → noi aggiungiamo
        il suo dominio a Mailgun → login.

### FASE 4 — Pagamenti (opzionale, quando serve)
- [ ] Abbonamento mensile o a spettacolo (es. link di pagamento).
- [ ] Attivazione/disattivazione account legata al pagamento.

### FASE 5 — Rifiniture e sicurezza
- [ ] Credenziali cifrate, dati di ogni account isolati (nessuno vede gli altri).
- [ ] Log minimi, cancellabili (privacy spettatori).
- [ ] Prova completa in piccolo per ogni tipo di account, su Gmail e iPhone.
- [ ] (Avanzato) **Immagine sul loro dominio**: per chi ha un dominio, un
      sottodominio `img.suodominio.it` che "punta" (CNAME) al tuo server → nelle
      mail la foto risulta su `img.suodominio.it`, non su abraka.it. Massima
      coerenza e segretezza.

---

## 6. Cosa deve fare il prestigiatore (onboarding, in pratica)

### Se ha solo Gmail (piccoli eventi)
1. Attiva la **verifica in due passaggi** sulla sua Gmail.
2. Crea una **password per app** (una stringa che permette all'autorisponditore
   di leggere/inviare per suo conto, senza dargli la password vera).
3. Ce la comunica in modo sicuro; noi configuriamo il suo account.
4. Installa il **pannello** sul telefono e fa **login** col suo numero.
5. Fine: gli spettatori scrivono alla sua Gmail, lui comanda dal pannello.

### Se ha un dominio (piccoli + grandi eventi)
1. Ci dà accesso ai **DNS** del suo dominio (o li modifica lui con le nostre
   istruzioni): aggiunge **SPF + DKIM** per autorizzare l'invio.
2. Noi aggiungiamo il suo dominio al **Mailgun** centrale e verifichiamo.
3. (Opzionale) aggiunge il CNAME `img.suodominio` per servire la foto dal suo
   dominio.
4. Installa il pannello e fa login.
5. Fine: piccoli dalla sua casella, grandi dal suo dominio via Mailgun.

---

## 7. Costi (indicativi)

| Voce | Chi paga | Quanto |
|---|---|---|
| Hosting PHP centrale (Tophost) | tu | ~19 €/anno (già tuo) |
| AWS Lambda (autorisponditore) | tu | ~0 € a volumi normali |
| Mailgun (grandi eventi) | tu | ~15 $/mese **solo nei mesi** con grandi eventi |
| Dominio del prestigiatore (se grandi eventi) | lui | ~10 €/anno |

I ricavi (quota ai prestigiatori) coprono questi costi fissi molto bassi.

---

## 8. Limiti noti (da dire sempre con onestà)

- **Gmail non si può usare come mittente di massa**: per i grandi eventi serve un
  dominio. (Punto 3b.)
- **Cache di Apple Mail (iPhone)**: può pre-scaricare la foto all'arrivo e
  "congelarla". Mitigazione: far arrivare/aprire la mail vicino al momento dello
  scambio. Vale per tutti, sempre.
- **Il server non va mai spento**: la foto resta viva finché il tuo server è
  online. In Metodo A sei TU responsabile dell'uptime per tutti.
- **Credenziali altrui (password per app Gmail)**: vanno trattate con cura
  (cifrate, mai esposte). È una responsabilità in più del Metodo A.

---

## 9. Prossimo passo suggerito

Partire dalla **FASE 1** (multi-utente nel motore): è il cuore e sblocca tutto il
resto. Quando vuoi, la progettiamo nel dettaglio e la costruiamo passo-passo,
senza toccare ciò che oggi funziona per te (il tuo account resterebbe il "001").
