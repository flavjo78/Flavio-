"""Forza delle mani prima del flop: classifica delle 169 mani e range per posizione."""
import math

_R = "23456789TJQKA"
_VAL = {r: i for i, r in enumerate(_R)}  # 0..12


def chen(r1, r2, suited):
    """Punteggio di Bill Chen (stima rapida della forza di una mano di partenza)."""
    def base(r):
        v = _VAL[r]
        return {12: 10, 11: 8, 10: 7, 9: 6}.get(v, (v + 2) / 2)
    hi, lo = (r1, r2) if _VAL[r1] >= _VAL[r2] else (r2, r1)
    s = base(hi)
    if hi == lo:
        return max(5, s * 2)
    if suited:
        s += 2
    gap = _VAL[hi] - _VAL[lo] - 1
    s -= {0: 0, 1: 1, 2: 2, 3: 4}.get(gap, 5)
    if gap <= 1 and _VAL[hi] < _VAL["Q"]:
        s += 1
    return math.ceil(s)


def _build():
    classes = []
    for i, a in enumerate(reversed(_R)):
        for j, b in enumerate(reversed(_R)):
            if j < i:
                continue
            if a == b:
                classes.append((a + b, chen(a, b, False), 6, _VAL[a], _VAL[b], 0))
            else:
                classes.append((a + b + "s", chen(a, b, True), 4, _VAL[a], _VAL[b], 1))
                classes.append((a + b + "o", chen(a, b, False), 12, _VAL[a], _VAL[b], 0))
    classes.sort(key=lambda c: (-c[1], -c[3], -c[4], -c[5]))
    out, cum = [], 0
    for name, _s, combos, *_ in classes:
        out.append((name, cum / 1326.0))  # percentile in cui INIZIA la classe
        cum += combos
    return out


_RANKING = _build()
_PCT = dict(_RANKING)


def percentile(cls):
    """0.0 = mano migliore (AA), vicino a 1.0 = peggiore (32o)."""
    return _PCT[cls]


def top_classes(top):
    """Insieme delle classi nel migliore `top` (es. 0.15 = 15%) delle mani."""
    return {n for n, p in _RANKING if p < top}


# Percentuale di mani con cui aprire (tavolo da 6), per posizione
OPEN_TOP = {"UTG": 0.13, "MP": 0.17, "CO": 0.26, "BTN": 0.42, "SB": 0.38, "BB": 0.0}
