"""Calcola le tabelle push/fold "di Nash" per tavoli da 2 a 10 giocatori.

Modello (quello classico delle tabelle push/fold):
- tutti hanno lo stesso stack effettivo S (in big blind), bui 0.5/1, niente ante;
- chi apre va all-in (push) o passa (fold); chi è dietro può solo pagare (call) o passare;
- conta solo il primo che paga (gli all-in a tre sono rari e si trascurano);
- vince chi ha più fiches in media (chip EV), non si considerano i premi (ICM).

Per ogni tavolo, posizione e stack si cerca l'equilibrio: nessuno può guadagnare
cambiando da solo la propria strategia. Metodo: fictitious play (ognuno risponde al
meglio alla strategia media degli altri, ripetuto centinaia di volte).

Risultato in coach/dati/pushfold.json: per ogni mano il MASSIMO stack (bb) con cui
andare all-in (push) o pagare un all-in (call). 0 = mai.

    python strumenti/genera_pushfold.py
"""
import json
import os
import sys
import time

import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from coach.preflop import CLASSI, COMBO  # noqa: E402
from coach.tavolo import posizioni  # noqa: E402

QUI = os.path.dirname(__file__)
DATI = os.path.join(QUI, "..", "coach", "dati")
GRIGLIA = [1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5, 5, 5.5, 6, 6.5, 7, 7.5, 8, 8.5, 9, 9.5, 10,
           11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 22, 25]
MAX = 25  # oltre questo stack le tabelle push/fold non si usano
ITER = 400


def carica_equity():
    d = json.load(open(os.path.join(DATI, "equity169.json")))
    assert d["classi"] == CLASSI, "ordine delle classi diverso: rigenera equity169.json"
    return np.array(d["equity"], dtype=float) / 10000.0


def risolvi(E, w, S, blind_apre, blinds_dietro):
    """Equilibrio per chi apre (posta blind_apre) contro i giocatori dietro (blinds_dietro)."""
    k = len(blinds_dietro)
    push = np.ones(169)
    calls = [np.zeros(169) for _ in range(k)]
    for t in range(1, ITER + 1):
        # 1) chi è dietro risponde al meglio al range medio di push
        wp = w * push
        if wp.sum() > 0:
            eq_vs_push = E @ wp / wp.sum()
        else:
            eq_vs_push = np.full(169, 0.5)
        for j, bj in enumerate(blinds_dietro):
            morto = 1.5 - blind_apre - bj
            br = (eq_vs_push * (2 * S + morto) - S > -bj).astype(float)
            calls[j] += (br - calls[j]) / t
        # 2) chi apre risponde al meglio ai range medi di call
        nessuno = 1.0
        ev = np.zeros(169)
        for j, bj in enumerate(blinds_dietro):
            wc = w * calls[j]
            p = wc.sum()
            if p > 0:
                morto = 1.5 - blind_apre - bj
                eq = E @ wc / p
                ev += nessuno * p * (eq * (2 * S + morto) - S)
            nessuno *= (1 - p)
        ev += nessuno * (1.5 - blind_apre)
        br = (ev > -blind_apre).astype(float)
        push += (br - push) / (t + 1)
    return push, calls


def main():
    E = carica_equity()
    w = np.array([COMBO[c] for c in CLASSI], dtype=float) / 1326.0
    out = {"griglia": GRIGLIA, "max": MAX, "classi": CLASSI, "tavoli": {},
           "nota": "chip EV, niente ante, stack uguali, solo il primo che paga"}
    t0 = time.time()
    for n in range(2, 11):
        pos = posizioni(n)
        blind = {p: (0.5 if p == "SB" else 1.0 if p == "BB" else 0.0) for p in pos}
        tav = {"push": {}, "call": {}}
        for i, apre in enumerate(pos[:-1]):
            dietro = pos[i + 1:]
            max_push = np.zeros(169)
            max_call = {d: np.zeros(169) for d in dietro}
            for S in GRIGLIA:
                push, calls = risolvi(E, w, S, blind[apre], [blind[d] for d in dietro])
                max_push[push >= 0.5] = S
                for d, c in zip(dietro, calls):
                    max_call[d][c >= 0.5] = S
            tav["push"][apre] = [float(x) for x in max_push]
            tav["call"][apre] = {d: [float(x) for x in v] for d, v in max_call.items()}
            perc10 = w[max_push >= 10].sum()
            print(f"{n} giocatori, {apre:<5} a 10 bb push {perc10:5.1%}   ({time.time() - t0:.0f}s)", flush=True)
        out["tavoli"][str(n)] = tav
    with open(os.path.join(DATI, "pushfold.json"), "w") as f:
        json.dump(out, f, separators=(",", ":"))
    print("salvato pushfold.json")


if __name__ == "__main__":
    main()
