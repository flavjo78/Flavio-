"""Posizioni al tavolo per 2..10 giocatori e quante mani aprire da ciascuna.

Le posizioni vanno dalla prima a parlare prima del flop (UTG) fino al big blind.
Testa a testa (2 giocatori) il bottone è anche lo small blind: posizioni SB e BB.
"""

_INIZIALI = {
    0: [],
    1: ["CO"],
    2: ["UTG", "CO"],
    3: ["UTG", "MP", "CO"],
    4: ["UTG", "MP", "HJ", "CO"],
    5: ["UTG", "UTG+1", "MP", "HJ", "CO"],
    6: ["UTG", "UTG+1", "UTG+2", "MP", "HJ", "CO"],
    7: ["UTG", "UTG+1", "UTG+2", "MP", "MP+1", "HJ", "CO"],
}

NOMI = {
    "UTG": "prima posizione (parla per primo)", "UTG+1": "seconda posizione", "UTG+2": "terza posizione",
    "MP": "posizione centrale", "MP+1": "posizione centrale", "HJ": "hijack (due posti prima del bottone)",
    "CO": "cutoff (un posto prima del bottone)", "BTN": "bottone (parla per ultimo dopo il flop)",
    "SB": "small blind", "BB": "big blind",
}


def posizioni(n):
    """Elenco delle posizioni nell'ordine in cui si parla prima del flop."""
    if not 2 <= n <= 10:
        raise ValueError("giocatori al tavolo: da 2 a 10")
    if n == 2:
        return ["SB", "BB"]
    return _INIZIALI[n - 3] + ["BTN", "SB", "BB"]


def dietro(n, pos):
    """Quanti giocatori parlano DOPO di te prima del flop (se tutti prima di te hanno passato)."""
    p = posizioni(n)
    if pos not in p:
        raise ValueError(f"con {n} giocatori le posizioni sono: {', '.join(p)}")
    return len(p) - 1 - p.index(pos)


# percentuale di mani con cui aprire (rilanciare per primo) secondo quanti giocatori restano dietro
_APRI_PER_DIETRO = {1: 0.40, 2: 0.45, 3: 0.28, 4: 0.20, 5: 0.15, 6: 0.13, 7: 0.11, 8: 0.10, 9: 0.09}


def apri_top(n, pos):
    """Frazione delle mani migliori con cui aprire da `pos` in un tavolo da `n` (0 per il BB)."""
    if pos == "BB":
        return 0.0
    if n == 2:
        return 0.80  # testa a testa il bottone gioca moltissime mani
    return _APRI_PER_DIETRO[dietro(n, pos)]


def posizione_da_posti(posti_occupati, bottone, mio_posto):
    """Dalla disposizione dei posti al tavolo ricava la tua posizione.

    posti_occupati: numeri dei posti con un giocatore (es. [1,2,4,5,6]); bottone: posto del bottone.
    Serve al lettore dello schermo, che vede i posti e il gettone del bottone.
    """
    ordine = sorted(posti_occupati)
    n = len(ordine)
    if bottone not in ordine or mio_posto not in ordine:
        raise ValueError("bottone e il tuo posto devono essere tra i posti occupati")
    i_btn = ordine.index(bottone)
    giro = ordine[i_btn:] + ordine[:i_btn]  # dal bottone in senso orario
    if n == 2:
        return {giro[0]: "SB", giro[1]: "BB"}[mio_posto]
    # dopo il bottone: SB, BB, poi UTG ... CO
    p = posizioni(n)
    nomi = ["BTN", "SB", "BB"] + p[:-3]
    return dict(zip(giro, nomi))[mio_posto]
