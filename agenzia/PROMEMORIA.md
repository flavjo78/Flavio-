# PROMEMORIA — cose da fare

> Aggiornato 4 novembre 2026: le voci sotto sono state costruite nella 1.5 (prima versione);
> restano aperte le domande sull'Excel (vedi conversazione) e le regole fini della biglietteria.

> Appunti dell'autore (3 novembre 2026), da sviscerare uno alla volta nella prossima sessione.
> Solo promemoria: nessuna di queste voci è stata costruita.

## Pratiche
- Invertire le colonne: la parte compatta (cliente, viaggio, partecipanti) va a **sinistra**,
  servizi e acconti a destra.
- **Totale in alto** (non solo in fondo ai servizi).
- Colore della card diverso per la biglietteria rispetto al resto.

## Biglietteria (nuova parte)
- Tre tipi: **biglietteria aerea**, **biglietteria treni**, **biglietteria low cost**.
  I fornitori/vettori sono quelli della lista "Vettori biglietteria" (già salvata nelle impostazioni).
- La biglietteria è una **pratica a sé**, con **numerazione separata** da quella dei pacchetti.
- Aereo: campo "modalità di acquisizione biglietto" = **cash** o **carta di credito**.
- Regole dei campi/stampa/totali: ancora da definire con l'autore.

## Menù di sinistra
- Aggiungere la voce **Liste** (oggi le liste stanno dentro Opzioni).

## Da fare più avanti (richieste del 4 novembre)
- **Statistiche → premio da raggiungere**: per la biglietteria (es. "over" Italo / Frecce) poter impostare
  un obiettivo di vendite per vettore/canale e vedere quanto manca. Non ancora costruito.
- Biglietteria: cambio / rimborso ci sono; da definire le regole di calcolo (importo negativo nel rimborso?).
- Nomi veri dei due fornitori aerei da mettere in Liste (fatto: Fancy Tour pagato/debito, Only Ticket pagato — da confermare la modalità di Only Ticket).

## Sicurezza — da fare più avanti (richiesta del 21 novembre, NON ora)
Rischio: il programma sta sul PC principale, dietro il router, ed è raggiungibile solo dalla rete dell'agenzia
(porta 8787), a meno che qualcuno apra la porta sul router. Punti deboli noti:
- **Nessun limite ai tentativi di password.** Da fare: dopo 5 password sbagliate, pausa di 15 minuti per quell'utente
  (e anche per l'indirizzo che prova), con avviso in Opzioni → Registro. È la cosa più importante.
- **Sessione più breve:** scadenza dopo alcune ore di inattività (oggi, con «solo la prima volta», dura 30 giorni).
- **Connessione non cifrata (http).** Valutare HTTPS solo se serve davvero (certificato locale); per ora si limita il rischio
  con Wi-Fi protetto e rete ospiti separata.
- **Password in chiaro** visibili in Opzioni (scelta dell'autore): valutare un'opzione per non salvarle più in chiaro.
- **Firewall:** la regola «AGENZIA gestionale» accetta da qualsiasi indirizzo. Restringerla alla rete locale:
  `netsh advfirewall firewall set rule name="AGENZIA gestionale" new remoteip=LocalSubnet`
  (e farlo anche in `installa-avvio-automatico.bat`).
- Cose senza programmazione: nessun inoltro porte / UPnP spento sul router; password forte sul Wi-Fi; cambiare le password
  di prova; backup su chiavetta custodita (contengono le password in chiaro); UltraViewer con password lunga.
