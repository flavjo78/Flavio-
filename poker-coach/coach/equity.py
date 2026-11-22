"""Calcolo dell'equity (probabilità di vincere) con simulazione Monte Carlo."""
import random
from treys import Card, Evaluator

_ev = Evaluator()
_RANKS = "23456789TJQKA"
_SUITS = "cdhs"
FULL_DECK = [Card.new(r + s) for r in _RANKS for s in _SUITS]


def parse_cards(text_or_list):
    """'Ah Kd' o ['Ah','Kd'] -> lista di carte treys. Accetta '10' come 'T'."""
    if isinstance(text_or_list, str):
        parts = text_or_list.replace(",", " ").split()
    else:
        parts = list(text_or_list)
    out = []
    for p in parts:
        if isinstance(p, int):
            out.append(p)
            continue
        p = p.strip().replace("10", "T")
        out.append(Card.new(p[0].upper() + p[1].lower()))
    return out


def hand_class(hole):
    """Due carte -> nome classe ('AKs', 'QQ', '72o')."""
    a, b = hole
    ra, rb = Card.get_rank_int(a), Card.get_rank_int(b)
    if ra < rb:
        a, b, ra, rb = b, a, rb, ra
    name = _RANKS[ra] + _RANKS[rb]
    if ra == rb:
        return name
    suited = Card.get_suit_int(a) == Card.get_suit_int(b)
    return name + ("s" if suited else "o")


def equity(hole, board, n_opp=1, iters=3000, opp_top=None, seed=None):
    """Equity dell'eroe contro n_opp avversari.

    opp_top: None = mani a caso; 0.2 = gli avversari hanno solo il 20% migliore
    delle mani (serve a stimare chi punta forte). Ritorna un numero 0..1.
    """
    from . import preflop  # import tardivo (dipendenze circolari)

    rng = random.Random(seed)
    hole, board = list(hole), list(board)
    known = set(hole) | set(board)
    deck = [c for c in FULL_DECK if c not in known]
    allowed = preflop.top_classes(opp_top) if opp_top else None
    need_board = 5 - len(board)
    score = 0.0
    done = 0
    for _ in range(iters):
        opp_hands = []
        used = set()
        ok = True
        for _o in range(n_opp):
            for _try in range(60):
                h = rng.sample(deck, 2)
                if used & set(h):
                    continue
                if allowed is None or hand_class(h) in allowed:
                    break
            else:
                ok = False
                break
            used |= set(h)
            opp_hands.append(h)
        if not ok:
            continue
        rest = [c for c in deck if c not in used]
        full_board = board + rng.sample(rest, need_board)
        hero = _ev.evaluate(full_board, hole)
        best = min(_ev.evaluate(full_board, h) for h in opp_hands)
        if hero < best:
            score += 1
        elif hero == best:
            ties = 1 + sum(1 for h in opp_hands if _ev.evaluate(full_board, h) == best)
            score += 1 / ties
        done += 1
    return score / done if done else 0.0


def count_outs(hole, board):
    """Quante carte (turn/river) migliorano la mano di categoria (draw a colore/scala/ecc.)."""
    if len(board) < 3 or len(board) >= 5:
        return 0
    known = set(hole) | set(board)
    base = _ev.get_rank_class(_ev.evaluate(board, hole))
    outs = 0
    for c in FULL_DECK:
        if c in known:
            continue
        cls = _ev.get_rank_class(_ev.evaluate(board + [c], hole))
        # classi treys: 1 scala colore ... 5 scala, 6 tris, 7 doppia coppia, 8 coppia, 9 carta alta
        if cls < base and cls <= 5:  # solo miglioramenti pesanti (scala o meglio)
            outs += 1
    return outs


NOMI_IT = {"Straight Flush": "scala colore", "Four of a Kind": "poker", "Full House": "full",
           "Flush": "colore", "Straight": "scala", "Three of a Kind": "tris", "Two Pair": "doppia coppia",
           "Pair": "coppia", "High Card": "carta alta"}


def made_hand_name(hole, board):
    if len(board) < 3:
        return hand_class(hole)
    nome = _ev.class_to_string(_ev.get_rank_class(_ev.evaluate(board, hole)))
    return NOMI_IT.get(nome, nome)
