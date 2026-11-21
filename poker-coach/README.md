# Poker Coach

Coach di poker (Texas Hold'em) per **studio**, contro bot e con soldi finti.
Ti dà il consiglio (fold / check / call / bet / raise) con equity, pot odds e spiegazione.

> Progetto separato da PREDIZIONE. Non clicca niente: decidi e giochi tu.

## Installazione su Windows
1. Installa Python da python.org (spunta "Add Python to PATH").
2. In una finestra di comandi dentro questa cartella:
   `py -m pip install -r requirements.txt`

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

## Test
`py -m unittest discover -s tests -v`

## Cosa c'è
- `coach/equity.py` simulazione Monte Carlo + conteggio dei "out" (progetti di scala/colore).
- `coach/preflop.py` classifica delle 169 mani e range di apertura per posizione (formula di Chen: stima, da raffinare).
- `coach/advisor.py` decisioni preflop (push/fold a stack corto, open, 3-bet) e postflop (equity vs pot odds, range di chi punta, semi-bluff).
- `coach/storia.py` archivio permanente (partite, mani, azioni, carte mostrate, risultati, note).
- `coach/opponents.py` statistiche (VPIP, PFR, aggressività, fold a puntata) e tipo di avversario.

## Prossimi passi
1. Lettura automatica dello schermo (screenshot continui) e finestra con il suggerimento sempre in primo piano.
2. Simulatore con bot a profili per misurare il guadagno (bb/100) e provare le strategie.
3. Range migliori (solver/CFR), dimensioni di puntata variabili, memoria della mano.

## Limiti attuali
Range avversari stimati dalla dimensione della puntata; non conosce ancora le azioni precedenti nella mano
(multiway complesso, check-raise, ecc.). È una base per studiare, non un oracolo.
