# Poker Coach

Coach di poker (Texas Hold'em) per **studio**, contro bot e con soldi finti.
Ti dà il consiglio (fold / check / call / bet / raise) con equity, pot odds e spiegazione.

> Progetto separato da PREDIZIONE. Non clicca niente: decidi e giochi tu.

## Installazione su Windows
Guida passo passo: **LEGGIMI-INSTALLAZIONE.txt**. In breve: installa Python (spunta "Add python.exe to PATH"),
estrai la cartella, doppio clic su **INSTALLA.bat**.

## Uso quotidiano (doppio clic)
- **IMPARA.bat**: scegli la finestra del poker, giochi, INVIO per fermare. Crea `pacchetti/poker_impara_DATA.zip`
  (foto JPG della finestra, indice, dimensioni, informazioni sul sistema) da caricare nella chat con Claude.
- **RISULTATI.bat**: crea `pacchetti/poker_risultati_DATA.zip` (archivio mani, tabella giocatori, ritratti
  degli avversari, una tua nota) da caricare nella chat dopo ogni partita.

## Uso (prima versione, da riga di comando)
```
py -m coach --hole "Ah Kd" --pos UTG --bb 10 --stack 1500
py -m coach --hole "9h 8h" --board "7h 6h 2c" --pot 100 --call 50 --opp 1
py -m coach --hole "Ac 3d" --board "Ks Qd 9h" --pot 150 --call 120 --tipo maniaco
```
- `--pot` = piatto totale **inclusa** la puntata che affronti; `--call` = quanto devi pagare (0 = puoi passare).
- `--pos` UTG, MP, CO, BTN, SB, BB · `--opp` avversari in mano · `--tipo` sasso, stazione, maniaco, pauroso, regolare.

## Calibrazione (CALIBRA.bat)
`py -m coach.calibra`: fotografa la finestra del poker (o apre una foto registrata) e chiede, una zona per volta,
di disegnare con il mouse un rettangolo intorno a: tue carte, carte del tavolo, piatto, ogni posto (nome + stack, il
tuo per primo e poi in senso orario), puntate, bottone, tasti di gioco, bui. Le zone sono salvate in proporzione alla
finestra (`calibrazione.json`, valgono anche se la ridimensioni), con `calibrazione/anteprima.png` e i ritagli di ogni
zona. Finiscono nello zip di IMPARA: sono la base del lettore automatico del tavolo.

## Lettore del tavolo (LETTORE.bat) — spento di default
`coach/lettore.py` è **generico**: dove guardare lo sa dalla calibrazione (angolo di ogni carta, numeri di piatto,
stack e puntate, gettone del bottone, zona dei tasti), cosa c'è lo impara dal giocatore. Carte: fondo chiaro =
carta presente, inchiostro nero/rosso normalizzato e confrontato con i modelli insegnati. Numeri: testo staccato
dallo sfondo, diviso in pezzi, ogni pezzo confrontato con i modelli (le lettere si marcano «ignora»). Bottone:
correlazione normalizzata (FFT) con il gettone calibrato, assegnato al posto più vicino. Turno: zona dei tasti non
uniforme. Le soglie sono severe: quello che non riconosce lo chiede invece di indovinare. I modelli valgono per
una dimensione di finestra (`lettore/modelli/<L>x<A>/`): a dimensione diversa il lettore avvisa.
`coach/lettore_app.py`: insegna sulle foto registrate (ogni cosa chiesta una volta sola), report HTML foto per foto
e zip per Claude, interruttore acceso/spento (in `annotazioni.json`). La finestra del coach, se il lettore è acceso
e la modalità è «Dal vivo», si riempie da sola ogni secondo e chiede il consiglio quando tocca a te.
**Non legge mai le finestre dei siti a soldi veri** (elenco in `lettore.SITI_SOLDI_VERI`).

**Ricostruzione delle mani a partita finita** (`coach/ricostruisci.py`): dalle letture foto dopo foto capisce inizio
mano (carte tue nuove), fasi (0/3/4/5 carte), puntate degli altri (call/bet/raise/all-in) e le TUE decisioni (quando
tocca a te fotografa piatto, da pagare, stack; poi guarda se la tua puntata sale, se le carte spariscono = fold, se
la fase avanza senza puntare = check/call). Risultato = stack della mano dopo − stack di questa. Letture incerte
saltate. `analizza_registrazione()` lo fa su una cartella di foto (usa gli orari di `indice.csv`) e salva nell'archivio;
Annotazioni → Rivedi e LETTORE → "Analizza una partita registrata" aprono poi il ripasso. Riconoscimento: soglia
alta, oppure soglia minima + distacco netto dalla seconda etichetta (0 e 8 ambigui = domanda, non tentativo).

**Lucchetto "Dal vivo"**: password (impronta PBKDF2 con sale in `annotazioni.json`, mai in chiaro), richiesta a ogni
accensione; la finestra riparte sempre in Ripasso; le impostazioni sono aggiornate voce per voce (`aggiorna_config`)
così finestra e LETTORE aperti insieme non si cancellano a vicenda.

Per Zoom e i siti a soldi veri: solo ripasso a sessione finita dalla cronologia delle mani (vedi `GUIDA-ZOOM.txt`).

## Finestra del coach (COACH.bat)
Finestra sul quarto destro dello schermo (`py -m coach.finestra`, Tkinter) con l'**interruttore della modalità**:
**Ripasso** (predefinita, nessun suggerimento durante il gioco) o **Dal vivo** (suggerimenti in tempo reale, da
attivare con una conferma, solo per partite contro il computer). In "Dal vivo" si inseriscono carte (anche con la
tastierina), piatto, da pagare, stack, posizione, chi ha rilanciato e tipo di avversario; INVIO = consiglio con
vincita, pot odds, bb e motivi. Dati sbagliati = messaggio chiaro, mai un consiglio inventato. La modalità è salvata
in `annotazioni.json` (condivisa con Annotazioni).

**Diario e ripasso con grafici.** In entrambe le modalità i tasti *Ho fatto* (Fold/Check/Call/Rilancia/All-in) salvano
la tua mossa in `storia.db`; *Fine mano* salva il risultato; *Fine partita* chiude la partita e apre la pagina di
ripasso (`ripassi/`), con grafici SVG che funzionano senza internet (tema chiaro/scuro, riquadri al passaggio del mouse):
risultato cumulato reale vs **stima** "seguendo il coach", accordo per fase e per posizione, tipi di errore con il
valore perso stimato, consigli su dove migliorare, decisioni più costose, tutte le giocate. La stima usa il valore
atteso di ogni scelta (equity, piatto, puntate, 30% di fold per chi punta): è un confronto indicativo, non la
partita rigiocata.

## Annotazioni: registra senza aiuti, rivedi dopo
**ANNOTAZIONI.bat** (o `py -m coach.annotazioni`): durante la partita registra SOLO foto, senza analizzare
né consigliare nulla. A partita finita il **ripasso** (pagina HTML in `ripassi/`) confronta ogni tua decisione
con quella del coach: d'accordo / diverso / da rivedere, con equity, pot odds e motivi, più le foto.
Il ripasso si apre solo se l'**interruttore dei suggerimenti** è acceso (di default è spento) **e** la finestra
del gioco registrato è chiusa. È il modo pensato per le partite in cui gli aiuti in tempo reale non sono ammessi:
controlla comunque il regolamento del sito, alcuni vietano anche la sola registrazione dello schermo.

## La partita: giocatori, bui, torneo
**PARTITA.bat** (o `py -m coach.partita`) chiede: torneo o cash, posti del tavolo, giocatori seduti, bui, ante,
stack iniziale, posti a premio e ogni quanti minuti salgono i bui (o "non lo so": lo impara osservando i cambi).
Tutto finisce in `partita.json`, che il coach usa da solo.
```
py -m coach.partita bui 15 30          # i bui sono saliti adesso (impara la velocità del torneo)
py -m coach.partita stato 1240         # "Tra circa 4 min i bui passano a 25/50: scenderai a 25 bb..."
py -m coach.partita giocatori 5        # è uscito qualcuno
```
Il coach conosce i tavoli da **2 a 10 giocatori**: posizioni (UTG ... CO, BTN, SB, BB) e quante mani aprire da
ciascuna cambiano con il numero di giocatori (più giocatori = mani più strette).

## Tabelle push/fold (Nash)
Sotto i **15 big blind effettivi** il coach usa le tabelle push/fold: per ogni mano il massimo stack con cui
andare all-in per primo (PUSH) o pagare/rispondere a un all-in (CALL), per ogni posizione e tavolo da 2 a 10.
**TABELLE.bat** (o `py -m coach.pushfold --giocatori 9 --pos BTN` / `--pos BB --contro SB`) le mostra come
griglia 13x13. Con l'ante il programma calcola lo "stack equivalente" (piatto più ricco = come avere meno bb).

Le tabelle sono **calcolate**, non copiate: `strumenti/genera_equity.py` calcola le probabilità di ogni mano
contro ogni altra (169x169, 20.000 prove a coppia), `strumenti/genera_pushfold.py` trova l'equilibrio di Nash
(fictitious play). Modello classico: chip EV, stack uguali, conta solo il primo che paga, niente ICM e niente
"blocker". Per i premi del torneo (ICM) servirà un passo in più.

## Archivio di mani e giocatori
Tutte le partite, le mani, le azioni e i giocatori restano salvati nel file `storia.db`
(database SQLite, nessuna installazione). Le statistiche si accumulano partita dopo partita.
```
py -m coach.storia giocatori              # tabella: mani, VPIP, PFR, aggressività, fold, bb/100, tipo
py -m coach.storia giocatore Bot3         # scheda + carte mostrate e come le ha giocate
py -m coach.storia mani --nome Bot3       # ultime mani ricostruite
py -m coach.storia nota Bot3 "bluffa al river"
py -m coach --hole "Qh Js" --pot 115 --call 80 --avversario Bot3   # il tipo lo prende dall'archivio
```
Il salvataggio delle mani (`coach/storia.py`, classe `Storia`) sarà fatto in automatico dal lettore dello schermo.

## Ritratto degli avversari e bot
```
py -m coach.storia ritratti               # tutti gli avversari descritti a parole + elenco dei probabili bot
py -m coach.storia ritratto Bot3          # uno solo
```
Per ogni avversario: quante mani gioca, se è aggressivo o passivo, se si ritira facilmente, che mani
mostra, se bluffa, se vince, e **come batterlo**. Poi il verdetto **"È un bot?"** (punteggio 0-100) con i motivi:
tempi di decisione tutti uguali o sotto il secondo, stesso tempo per decisioni facili e difficili,
puntate sempre della stessa grandezza, ore di gioco senza pause. Servono almeno 20 decisioni cronometrate.

## Registrazione dello schermo (Windows)
```
py -m coach.schermo finestre                       # finestre aperte con la grandezza dell'area di gioco
py -m coach.schermo foto "Poker"                   # una foto della finestra (da mandare per la calibrazione)
py -m coach.schermo registra "Poker"               # registra di continuo, Ctrl+C per fermare
py -m coach.schermo registra "Poker" --intervallo 0.5 --cartella partita1
```
"Poker" = una parte del titolo della finestra del gioco. Misura solo l'area di gioco (senza bordi), in pixel
reali anche con lo zoom di Windows al 125/150%. Salva una foto **solo quando lo schermo cambia**, segnala se la
finestra viene ridimensionata, ridotta a icona o coperta da un'altra finestra. In `indice.csv` l'elenco delle
foto con ora e dimensioni, in `finestra.json` la storia delle dimensioni.

## Test
`py -m unittest discover -s tests -v`

## Cosa c'è
- `coach/equity.py` simulazione Monte Carlo + conteggio dei "out" (progetti di scala/colore).
- `coach/preflop.py` classifica delle 169 mani e range di apertura per posizione (formula di Chen: stima, da raffinare).
- `coach/advisor.py` decisioni preflop (push/fold a stack corto, open, 3-bet) e postflop (equity vs pot odds, range di chi punta, semi-bluff).
- `coach/tavolo.py` posizioni e aperture per 2-10 giocatori.
- `coach/pushfold.py` tabelle push/fold (dati in `coach/dati/`).
- `coach/torneo.py` livelli dei bui, velocità del torneo, previsioni.
- `coach/partita.py` la partita in corso (`partita.json`).
- `coach/storia.py` archivio permanente (partite, mani, azioni, carte mostrate, risultati, note).
- `coach/ritratto.py` descrizione a parole degli avversari e rilevamento bot.
- `coach/ricostruisci.py` ricostruzione delle mani dalle foto, a partita finita.
- `coach/lettore.py` e `coach/lettore_app.py`: lettore del tavolo (spento di default) e app per insegnargli.
- `coach/calibra.py` calibrazione: le zone del tavolo disegnate dal giocatore.
- `coach/finestra.py` la finestra del coach con l'interruttore Ripasso / Dal vivo.
- `coach/annotazioni.py` e `coach/ripasso.py`: registrazione senza aiuti e ripasso a gioco chiuso.
- `coach/impara.py` modalità impara e pacchetto risultati (gli zip per Claude).
- `coach/schermo.py` trova la finestra del gioco, ne misura l'area e la registra.
- `coach/opponents.py` statistiche (VPIP, PFR, aggressività, fold a puntata) e tipo di avversario.

## Prossimi passi
1. Lettura di carte, piatto e puntate dalle foto (serve la grafica del gioco) e finestra del suggerimento sempre in primo piano.
2. Simulatore con bot a profili per misurare il guadagno (bb/100) e provare le strategie.
3. Range migliori (solver/CFR), dimensioni di puntata variabili, memoria della mano.

## Limiti attuali
Range avversari stimati dalla dimensione della puntata; non conosce ancora le azioni precedenti nella mano
(multiway complesso, check-raise, ecc.). È una base per studiare, non un oracolo.
