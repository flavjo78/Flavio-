"""Tabelle push/fold (Nash) per tavoli da 2 a 10 giocatori.

Ogni mano ha un numero: il MASSIMO stack (in big blind) con cui conviene
- andare all-in per primo (tabella PUSH, per la tua posizione), oppure
- pagare l'all-in di un altro (tabella CALL, per la tua posizione contro la sua).
Se hai meno o uguale a quel numero: all-in / paga. Se hai di più: fold.

    py -m coach.pushfold --giocatori 9 --pos BTN              tabella push dal bottone
    py -m coach.pushfold --giocatori 9 --pos BB --contro SB   quando pagare un all-in dello SB
"""
import json
import os

from . import tavolo

_DATI = None
_FILE = os.path.join(os.path.dirname(__file__), "dati", "pushfold.json")
_R = "AKQJT98765432"


def dati():
    global _DATI
    if _DATI is None:
        with open(_FILE, encoding="utf-8") as f:
            _DATI = json.load(f)
        _DATI["_idx"] = {c: i for i, c in enumerate(_DATI["classi"])}
    return _DATI


def stack_equivalente(stack_bb, ante_bb=0.0, giocatori=0):
    """Con gli ante il piatto iniziale è più ricco: è come avere meno big blind."""
    piatto = 1.5 + ante_bb * giocatori
    return stack_bb * 1.5 / piatto


def push_max(n, pos, cls):
    d = dati()
    return d["tavoli"][str(n)]["push"][pos][d["_idx"][cls]]


def call_max(n, chi_spinge, pos, cls):
    d = dati()
    return d["tavoli"][str(n)]["call"][chi_spinge][pos][d["_idx"][cls]]


def fmt(v):
    d = dati()
    if v <= 0:
        return "-"
    if v >= d["max"]:
        return f"{d['max']}+"
    return f"{v:g}"


def tabella(n, pos, contro=None):
    """Griglia 13x13: in alto a destra le mani dello stesso seme (s), in basso a sinistra diverse (o)."""
    tavolo.dietro(n, pos)  # controlla che la posizione esista
    righe = []
    if contro:
        titolo = f"CALL: sei {pos}, {contro} è andato all-in ({n} giocatori)"
        valore = lambda c: call_max(n, contro, pos, c)
    else:
        titolo = f"PUSH: sei {pos} e nessuno ha giocato prima di te ({n} giocatori)"
        valore = lambda c: push_max(n, pos, c)
    righe.append(titolo)
    righe.append("Numero = massimo stack in big blind per farlo.  '-' = mai.")
    righe.append("      " + "".join(f"{r:>6}" for r in _R))
    for i, a in enumerate(_R):
        celle = []
        for j, b in enumerate(_R):
            if i == j:
                c = a + b
            elif i < j:
                c = a + b + "s"
            else:
                c = b + a + "o"
            celle.append(f"{fmt(valore(c)):>6}")
        righe.append(f"{a:>6}" + "".join(celle))
    righe.append("Sopra la diagonale: stesso seme (es. AKs). Sotto: semi diversi (es. AKo).")
    return "\n".join(righe)


def main(argv=None):
    import argparse
    ap = argparse.ArgumentParser(prog="coach.pushfold", description="Tabelle push/fold")
    ap.add_argument("--giocatori", type=int, default=6)
    ap.add_argument("--pos", default="BTN")
    ap.add_argument("--contro", help="posizione di chi è andato all-in (per la tabella call)")
    a = ap.parse_args(argv)
    print(tabella(a.giocatori, a.pos.upper(), a.contro.upper() if a.contro else None))


if __name__ == "__main__":
    main()
