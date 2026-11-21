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
- `coach/storia.py` archivio permanente (partite, mani, azioni, carte mostrate, risultati, note).
- `coach/ritratto.py` descrizione a parole degli avversari e rilevamento bot.
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
