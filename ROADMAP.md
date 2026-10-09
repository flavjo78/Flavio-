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

## 3. Intelligenza artificiale (foto B)
- [ ] Collegare un servizio IA per generare la rivelazione (consigliato: **Google Gemini 2.5 Flash Image** — ~0,04 €/foto, spesso gratis a basso volume; alternativa **FLUX Kontext**).
- [ ] Serve una **chiave API** (a consumo, separata dall'abbonamento personale Gemini).
- [ ] **Definire i crediti**: regola chiara **1 credito = 1 foto generata**.

## 4. Limiti per utente (per rendere i piani imponibili)
- [ ] Contatori per utente: **mail/mese**, **mail/giorno**, **sessioni (mese e giorno)**, **crediti IA**.
- [ ] Azzeramento automatico (giornaliero / mensile) + blocco al raggiungimento del tetto.
- [ ] Gestiti dal pannello admin per ogni utente, secondo il piano acquistato.

## 5. Funzione "DUO" (assistente temporaneo)
- [ ] Un **link** che permette a **un'altra persona** di aiutare il prestigiatore durante lo show:
  scrivere la predizione o caricare la foto, in modo temporaneo.
- [ ] Nei piani: "2 link" = 2 assistenti abilitabili.

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

## 7. Parte legale/fiscale (NON tecnica)
- [ ] Verificare coerenza con la **Partita IVA** (vendere software = fatture + IVA).
- [ ] Pagine legali minime (termini, privacy) sul sito.
- [ ] Valutare "merchant of record" (Lemon Squeezy/Paddle) per semplificare IVA internazionale.

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
