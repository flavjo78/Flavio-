# AGENZIA — come si installa e si usa

Gestionale per agenzia di viaggi: pratiche, clienti, fornitori, acconti, scadenze,
statistiche, stampa della conferma di prenotazione. Funziona su un PC "centrale" dell'agenzia;
gli altri PC della rete lo usano dal browser. Niente internet, niente abbonamenti.

## 1. Preparare il pacchetto (una volta, sul PC di chi lo distribuisce)
1. Installare Node.js (versione 22 o superiore) da https://nodejs.org (solo su QUESTO PC).
2. Doppio clic su `windows\costruisci-exe.bat`. Alla fine compare la cartella `dist\AGENZIA`
   con dentro `agenzia.exe`, la cartella `web\` e i file `.bat`.

(In alternativa, senza creare l'exe: sul PC centrale si installa Node.js e si usa `avvia.bat`,
che avvia `server\server.js`.)

## 2. Installare sul PC centrale dell'agenzia
1. Copiare la cartella `AGENZIA` ad esempio in `C:\AGENZIA`.
2. Tasto destro su `installa-avvio-automatico.bat` → **Esegui come amministratore**.
   Apre la porta 8787 nel firewall e fa partire il programma a ogni accensione del PC
   (anche senza fare il login di Windows).
3. Aprire il browser su **http://localhost:8787**. Al primo avvio si crea il primo utente
   e la password del pannello opzioni.

Se non si vuole l'avvio automatico: doppio clic su `avvia.bat` (la finestra nera va lasciata aperta).

## 3. Gli altri PC
Nel browser (Edge o Chrome) aprire `http://NOME-DEL-PC-CENTRALE:8787` (il nome lo stampa
`installa-avvio-automatico.bat`; si può usare anche l'indirizzo IP). Per avere l'icona sul
desktop: menù del browser → "Installa app" / "Crea collegamento".
Il PC centrale deve essere acceso quando lavorano gli altri.

## 4. Dati e backup
- Tutto l'archivio sta in `_dati\agenzia.db` accanto al programma. Non cancellarlo.
- Opzioni → Backup: indicare una cartella (chiavetta, disco esterno o cartella di rete scritta
  come `\\ALTRO-PC\cartella`) e l'ora. Ogni giorno viene fatta una copia; si tengono le ultime 30.
  Con l'avvio automatico il programma gira come "sistema": le lettere di rete (es. `Z:`)
  non si vedono, usare il percorso `\\NOME-PC\cartella`.
- Per ripristinare un backup: fermare il programma, copiare il file del backup al posto di
  `_dati\agenzia.db`, riavviare.

## 5. Aggiornare a una versione nuova
Fermare il programma, sostituire `agenzia.exe` e la cartella `web\`, riavviare.
La cartella `_dati\` non si tocca.

## 6. Se qualcosa non va
- "La porta 8787 è già occupata": il programma è già avviato (controllare le attività pianificate).
- Gli altri PC non si collegano: controllare che il PC centrale sia acceso, che il firewall
  abbia la regola "AGENZIA gestionale" e che i PC siano nella stessa rete.
- **Password dimenticata** (di un utente o del pannello): sul PC centrale creare nella cartella
  `_dati` un file di testo chiamato `RESET-PASSWORD.txt` con dentro una riga
  `nomeutente nuovapassword` (oppure `pannello nuovapassword`), poi riavviare il programma.
  La password viene cambiata e il file cancellato da solo.
