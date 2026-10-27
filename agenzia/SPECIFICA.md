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
  (chiavetta, altro PC in rete), più il tasto "Backup adesso".
- **Prezzi tutti IVA inclusa**: un solo campo prezzo per riga, nessuna gestione IVA.

## 2. Chi lo usa

- Ogni persona entra con **username e password propri**: pratiche, acconti, pagamenti e
  modifiche vengono registrati con il nome di chi li ha fatti (log operazioni).
- Tutti gli utenti sono uguali. Il **pannello opzioni** (dati agenzia, testi legali,
  numerazione, liste, backup, utenti) si apre con una **password a parte**.
- Al primo avvio il programma chiede di creare il primo utente e la password del pannello.

## 3. Archivi

### Clienti
Cognome, nome, azienda, indirizzo, CAP, città, provincia, nazione, sesso, telefono,
cellulare, telefono 2, email, nato il / luogo, codice fiscale, P.IVA, documento (tipo,
numero, rilasciato da, rilasciato il, scade il), note, "viaggia con". Data creazione,
ultimo contatto, chi ha modificato. Dalla scheda si vedono le pratiche del cliente e le
sue statistiche (quante pratiche, quanto speso, per anno).

### Fornitori
Nome, tipo (Tour operator, Hotel, Assicurazione, Compagnia aerea, Autonoleggio, Navale,
Trasporti, Ferrovie, Guide, Ristorante, Corrispondenti, Servizi vari), indirizzo, contatti,
P.IVA, referente, provvigione %, modalità pagamento, IBAN/banca, note. Dalla scheda si vede
la **situazione**: per ogni pratica quanto l'agenzia deve al fornitore, quanto ha già pagato,
quanto il cliente ha versato per quella quota; e le **statistiche per anno** (confronto anni).

### Pratiche
- Testata: **numero/anno** (progressivo annuale: si azzera ogni anno; dal pannello si
  imposta da quale numero partire la prima volta), cliente, operatore (chi l'ha creata),
  tipo pratica, **stato** (Preventivo · Confermata · Annullata · Annullata con penale ·
  Chiusa; con penale si indica l'importo della penale), data apertura, intermediazione
  (sì di norma) o organizzazione propria, pacchetto/servizio, in catalogo o fuori catalogo
  (catalogo e pagina), riferimento pratica e operatore del tour operator, destinazione/
  itinerario, partenza e rientro (data + "da/a" facoltativi), adulti/bambini/infanti,
  durata giorni/notti, PNR, documenti necessari, richieste specifiche, note interne.
- **Partecipanti:** elenco nome + data di nascita.
- **Righe servizi:** descrizione, **fornitore della riga**, prezzo × pax = totale,
  **costo fornitore** (quanto dovete al fornitore per quella riga, non stampato),
  spunta "in stampa". I diritti d'agenzia / ADV fee sono righe normali inserite a mano.
- **Acconti (illimitati):** data, importo, metodo, descrizione, fornitore a cui si
  riferisce (facoltativo), chi l'ha registrato. Abbuono e **saldo da versare entro il**.
- **Pagamenti al fornitore:** data, importo, fornitore, metodo, descrizione.
- Totali calcolati: totale pratica, versato, saldo; per fornitore: costo, pagato, dovuto.

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

## 5. Fuori dalla prima versione
Biglietteria aerea/ferroviaria come modulo a parte, voucher, buoni di consegna, estratti
conto, newsletter, credenziali web dei fornitori, importazione dei dati dal vecchio FESTA
(valutabile se si riesce a esportare i suoi archivi).
