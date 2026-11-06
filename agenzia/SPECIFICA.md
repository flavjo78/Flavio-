# AGENZIA — Gestionale per agenzia di viaggi (prima versione)

> Specifica concordata il 27 ottobre 2026 con l'autore, sulla base del modulo stampato
> "Proposta di compravendita di pacchetto/servizio turistico" e delle schermate del
> vecchio programma FESTA. È il riferimento di quello che la prima versione fa e non fa.

## 1. Come gira

- **Un PC "centrale"** dell'agenzia tiene il programma e i dati. Gli altri PC della rete
  locale lo aprono dal browser all'indirizzo `http://NOME-PC:8787` (icona sul desktop).
  Niente internet, niente server esterno. Il PC centrale deve restare acceso quando lavorano gli altri.
- **Zero dipendenze:** server Node.js con il database SQLite incorporato (`node:sqlite`).
  Si distribuisce come cartella con `agenzia.exe` + `web/` (vedi `LEGGIMI.md`); i dati
  stanno in `_dati/agenzia.db` accanto al programma.
- **Backup automatico** una volta al giorno in una cartella scelta dal pannello opzioni
  (chiavetta, altro PC in rete), più il tasto "Backup adesso". Dal pannello si può anche
  **scaricare una copia completa dei dati** (file `.db`) e **importarne una** al posto dei dati
  attuali (per prove, trasloco su altro PC, assistenza).
- **Prezzi tutti IVA inclusa**: un solo campo prezzo per riga, nessuna gestione IVA.

## 2. Chi lo usa

- Ogni persona entra con **username e password propri**: pratiche, acconti, pagamenti e
  modifiche vengono registrati con il nome di chi li ha fatti (log operazioni).
- Per ogni utente si scelgono le **voci del menù** che può usare con la propria password; le altre
  restano visibili con un lucchetto e si aprono, per quella sessione, con la password del pannello.
  Il **pannello opzioni** (dati agenzia, testi legali, numerazione, liste, backup, utenti) si apre
  con una **password a parte**.
- Al primo avvio il programma chiede di creare il primo utente e la password del pannello
  (entrambe ripetute), oppure di **importare un file di dati** già esistente.
  All'accesso si sceglie il proprio nome dall'elenco e si scrive solo la password. Le password
  degli utenti sono visibili in chiaro nel pannello (per chi le dimentica); se si dimentica anche
  la password del pannello, c'è il file `_dati/RESET-PASSWORD.txt` (vedi LEGGIMI).
- Se si cambia schermata con modifiche non salvate, il programma chiede: resta / esci senza
  salvare / salva e vai. Una pratica salvata senza conferma resta come "Preventivo" (bozza).

## 3. Archivi

### Clienti
Cognome, nome, azienda, indirizzo, CAP, città, provincia, nazione, sesso, telefono,
cellulare, telefono 2, email + **altri contatti** (lista: tipo, numero, riferimento), nato il /
luogo, codice fiscale, P.IVA, **documenti** (lista: tipo, numero, rilasciato da, il, scade),
**tessere e programmi fedeltà** (lista a campo libero: MSC, Costa, Trenitalia…), **compagni di viaggio**
(persone senza scheda propria: nome, nascita, documento; cercandole si arriva al cliente; si aggiungono da
sole dai biglietti), note, "viaggia con". Data creazione,
ultimo contatto, chi ha modificato. Dalla scheda si vedono le pratiche del cliente e le
sue statistiche (quante pratiche, quanto speso, per anno).

### Fornitori
Nome, tipo (Tour operator, Hotel, Assicurazione, Compagnia aerea, Autonoleggio, Navale,
Trasporti, Ferrovie, Guide, Ristorante, Corrispondenti, Servizi vari), indirizzo, contatti,
P.IVA, referente, provvigione %, modalità pagamento, **codice agenzia presso il fornitore**,
**più IBAN** (lista: intestazione, banca, IBAN, note), note. Dalla scheda si vede
la **situazione**: per ogni pratica quanto l'agenzia deve al fornitore, quanto ha già pagato,
quanto il cliente ha versato per quella quota; e le **statistiche per anno** (confronto anni).

### Pratiche
- Testata: **numero/anno** (progressivo annuale: si azzera ogni anno; dal pannello si
  imposta da quale numero partire la prima volta), cliente, operatore (chi l'ha creata),
  tipo pratica, **stato** (Preventivo · Confermata · Annullata · Annullata con penale ·
  Chiusa; con penale si indica l'importo della penale), data apertura, intermediazione
  (sì di norma) o organizzazione propria, oggetto pacchetto/servizio/**biglietteria** (regole
  dedicate in arrivo), in catalogo o fuori catalogo
  (catalogo e pagina), destinazione/
  itinerario, partenza e rientro (data + "da/a" facoltativi), adulti/bambini/infanti,
  durata giorni/notti, PNR, documenti necessari, richieste specifiche, note interne.
- **Partecipanti:** nome, data di nascita e documento (tipo, numero, scadenza); il tasto 📇 li prende
  dall'anagrafica del cliente o dei suoi compagni di viaggio; i nomi nuovi diventano compagni.
- **Servizi (card):** ogni servizio ha descrizione, **fornitore**, importo cad. × pax, spunta
  "in stampa", più **N. pratica T.O. e operatore T.O.** del fornitore (stampati sotto il servizio,
  spunta per toglierli). Sotto ogni servizio si aggiungono **voci nascoste** (provvigione, diritti, costi):
  si sommano all'importo del servizio e il cliente vede UNA riga col totale (una voce nascosta
  può anche essere messa "in stampa" come riga a parte, ma solo con la password del pannello).
- **Vista**: finché non c'è il cliente la pratica è "larga" (si compila dall'alto); appena scelto il
  cliente passa alla **vista compatta**, tutta in una schermata: servizi e acconti al centro, cliente,
  viaggio e partecipanti in piccolo a destra. Il tasto "⇄ vista" le alterna. L'importo del servizio con fornitore
  è quanto dovete al fornitore; le voci nascoste restano all'agenzia (margine).
- **Acconti (illimitati):** data, importo, metodo, descrizione, fornitore a cui si
  riferisce (facoltativo), chi l'ha registrato. Abbuono e **saldo da versare entro il**.
- **Pagamenti al fornitore:** data, importo, fornitore, metodo, descrizione.
- Totali calcolati: totale pratica, versato, saldo; per fornitore (compresi i biglietti collegati):
  costo, pagato, dovuto, versato dal cliente per quella quota. Gli acconti riferiti a un fornitore vanno
  a lui; quelli senza riferimento si ripartiscono in proporzione al costo, prima i fornitori e poi
  l'agenzia ("Incassato per l'agenzia"). La tabella fornitori è di sola lettura.
- Le tendine con molte voci sono **ricercabili**: al click mostrano tutto, scrivendo filtrano.

### Scadenze (schermata iniziale)
Saldi clienti in arrivo o scaduti, pagamenti dovuti ai fornitori per le pratiche
confermate, partenze dei prossimi 30 giorni, documenti dei clienti in scadenza.

### Statistiche
Per anno: pratiche, venduto, incassato, da incassare, margine (venduto − costi fornitori).
Valore pratiche per mese confrontato tra anni; per tipo pratica; per fornitore (anno
contro anno precedente); per operatore; migliori clienti. Esclude le pratiche annullate
(quelle "con penale" contano solo la penale).

## 4. Stampe

- **Conferma di prenotazione** (modulo art. 35 Codice del Turismo): intestazione agenzia,
  cliente intestatario e partecipanti, dichiarazioni, organizzatore/i (i fornitori delle
  righe), pacchetto/servizio, in/fuori catalogo, destinazione, date e durata, righe servizi
  (solo quelle "in stampa"), totale, documenti necessari, **tabella completa degli
  acconti** con saldo e data, validità, firma, informativa privacy (GDPR). Si apre nel
  browser pronta da stampare o salvare in PDF.
- Tutti i **testi legali** e i dati dell'intestazione si modificano dal pannello opzioni.
- Ricevuta per singolo acconto: **rimandata** (da decidere).

## 4b. Biglietteria (da costruire, regole in arrivo)
Oggetto "Biglietteria" nella pratica. Lista dei **vettori** (modificabile da Opzioni → Liste,
"Vettori biglietteria"), presa dal vecchio programma: ADR Parking, Air Europa, Air France, Alilauro,
Amatori, Avis, Ease, Easyjet, Esta, Europcar, Eurowings, FCOExpress, Flixbus, Fly Dubai, Gesac, Global
Hertz, GNV, Grimaldi, Iberia, ITA Airways, Itabus, Maggiore, Marino Bus, Miccolis, Morfimare, Neos,
Ryanair, Satam, Siremar, SNAV, Superfast, TAP, Tirrenia, Transavia, Volotea, Vueling, Wizzair, Varie.
Le regole dei servizi di biglietteria (campi, stampa) verranno definite con l'autore.

## 5. Fuori dalla prima versione
Biglietteria aerea/ferroviaria come modulo a parte, voucher, buoni di consegna, estratti
conto, newsletter, credenziali web dei fornitori, importazione dei dati dal vecchio FESTA
(valutabile se si riesce a esportare i suoi archivi).
