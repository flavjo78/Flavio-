"""Calcola l'equity preflop all-in di ogni classe di mano contro ogni altra (169x169).

Serve solo a chi sviluppa: il risultato va in coach/dati/equity169.json e il programma
sul PC del giocatore lo legge già pronto (non ha bisogno di phevaluator).

    pip install phevaluator numpy
    python strumenti/genera_equity.py --prove 4000
"""
import argparse
import json
import os
import random
import sys
import time
from multiprocessing import Pool

from phevaluator import evaluate_cards

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from coach.preflop import CLASSI  # noqa: E402

RANKS = "23456789TJQKA"


def combos(cls):
    """Tutte le combinazioni concrete (coppie di interi 0..51, id = rango*4 + seme)."""
    a, b = RANKS.index(cls[0]), RANKS.index(cls[1])
    out = []
    for s1 in range(4):
        for s2 in range(4):
            c1, c2 = a * 4 + s1, b * 4 + s2
            if a == b:
                if s1 < s2:
                    out.append((c1, c2))
            elif cls[2] == "s" and s1 == s2:
                out.append((c1, c2))
            elif cls[2] == "o" and s1 != s2:
                out.append((c1, c2))
    return out


COMBOS = {c: combos(c) for c in CLASSI}


def equity_coppia(args):
    i, j, prove, seed = args
    rng = random.Random(seed)
    ca, cb = COMBOS[CLASSI[i]], COMBOS[CLASSI[j]]
    tot, fatte = 0.0, 0
    while fatte < prove:
        h1, h2 = rng.choice(ca), rng.choice(cb)
        if h1[0] in h2 or h1[1] in h2:
            continue
        usate = {h1[0], h1[1], h2[0], h2[1]}
        board = []
        while len(board) < 5:
            c = rng.randrange(52)
            if c not in usate:
                usate.add(c)
                board.append(c)
        r1 = evaluate_cards(*board, *h1)
        r2 = evaluate_cards(*board, *h2)
        tot += 1.0 if r1 < r2 else (0.5 if r1 == r2 else 0.0)
        fatte += 1
    return i, j, tot / prove


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--prove", type=int, default=4000)
    ap.add_argument("--out", default=os.path.join(os.path.dirname(__file__), "..", "coach", "dati", "equity169.json"))
    a = ap.parse_args()
    n = len(CLASSI)
    lavori = [(i, j, a.prove, i * 1000 + j) for i in range(n) for j in range(i, n)]
    m = [[0.0] * n for _ in range(n)]
    t = time.time()
    with Pool() as p:
        for k, (i, j, e) in enumerate(p.imap_unordered(equity_coppia, lavori, chunksize=50)):
            m[i][j], m[j][i] = e, 1 - e
            if k % 2000 == 0:
                print(f"{k}/{len(lavori)}  {time.time() - t:.0f}s", flush=True)
    for i in range(n):
        m[i][i] = 0.5  # stessa classe: in pratica pareggio
    with open(a.out, "w") as f:
        json.dump({"classi": CLASSI, "prove": a.prove,
                   "equity": [[round(x * 10000) for x in r] for r in m]}, f, separators=(",", ":"))
    print("salvato", a.out, f"{time.time() - t:.0f}s")


if __name__ == "__main__":
    main()
