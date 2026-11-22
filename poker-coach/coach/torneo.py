"""Struttura del torneo: livelli dei bui, velocità, previsione del prossimo livello.

Il programma osserva i bui nel tempo (dallo schermo o scritti da te) e capisce da solo
ogni quanti minuti salgono. Così può avvisarti PRIMA che il tuo stack diventi corto.
"""
import statistics
import time

# progressione tipica dei Sit&Go (small blind, big blind)
PROGRESSIONE = [(10, 20), (15, 30), (25, 50), (50, 100), (75, 150), (100, 200), (150, 300),
                (200, 400), (300, 600), (400, 800), (500, 1000), (600, 1200), (800, 1600),
                (1000, 2000), (1500, 3000), (2000, 4000), (3000, 6000), (4000, 8000)]

VELOCITA = {"hyper": 3, "turbo": 5, "normale": 10, "lento": 15}


def nome_velocita(minuti):
    if not minuti:
        return "sconosciuta"
    nome = min(VELOCITA, key=lambda k: abs(VELOCITA[k] - minuti))
    return f"{nome} (~{minuti:g} min a livello)"


def prossimi_bui(sb, bb, livelli=None):
    """Il livello che viene dopo (sb, bb): dalla struttura se lo conosce, altrimenti x1.5."""
    livelli = livelli or PROGRESSIONE
    for i, (s, b) in enumerate(livelli):
        if (s, b) == (sb, bb) and i + 1 < len(livelli):
            return livelli[i + 1]
    def tondo(x):
        for passo in (1000, 500, 100, 50, 25, 5, 1):
            if x >= passo * 4:
                return int(round(x / passo) * passo)
        return int(round(x))
    return tondo(sb * 1.5), tondo(bb * 1.5)


def zona(stack_bb):
    if stack_bb > 25:
        return "tranquillo: c'è spazio per giocare"
    if stack_bb > 15:
        return "attenzione: lo stack si sta accorciando, preparati al push/fold"
    if stack_bb > 8:
        return "push/fold: solo all-in o fold"
    return "emergenza: vai all-in con un range largo prima che i bui ti mangino"


class Osservatore:
    """Registra i cambi di livello e stima ogni quanti minuti salgono i bui."""

    def __init__(self, minuti=None, livelli=None, storia=None):
        self.minuti_dati = minuti            # se il giocatore conosce la durata dei livelli
        self.livelli = livelli               # struttura esplicita [(sb, bb), ...] se nota
        self.storia = list(storia or [])     # [{"ts", "sb", "bb", "ante"}] un elemento per livello

    def registra(self, sb, bb, ante=0, ts=None):
        """Chiamalo quando leggi i bui (anche spesso): salva solo quando il livello cambia."""
        ts = ts if ts is not None else time.time()
        if self.storia and (self.storia[-1]["sb"], self.storia[-1]["bb"], self.storia[-1]["ante"]) == (sb, bb, ante):
            return False
        self.storia.append({"ts": ts, "sb": sb, "bb": bb, "ante": ante})
        return True

    def durate_osservate(self):
        """Minuti dei livelli VISTI PER INTERO (tra due cambi)."""
        return [(b["ts"] - a["ts"]) / 60 for a, b in zip(self.storia[1:], self.storia[2:])] \
            if len(self.storia) >= 3 else []

    def minuti_livello(self):
        d = self.durate_osservate()
        if d:
            return round(statistics.median(d), 1)
        return self.minuti_dati

    def stato(self, stack, giocatori=None, ts=None):
        """Situazione attuale e previsione: dizionario + messaggio a parole."""
        if not self.storia:
            return {"messaggio": "Non conosco ancora i bui."}
        ts = ts if ts is not None else time.time()
        cur = self.storia[-1]
        sb, bb, ante = cur["sb"], cur["bb"], cur["ante"]
        nsb, nbb = prossimi_bui(sb, bb, self.livelli)
        minuti = self.minuti_livello()
        r = {"sb": sb, "bb": bb, "ante": ante, "livello": len(self.storia),
             "prossimo": (nsb, nbb), "minuti_livello": minuti, "velocita": nome_velocita(minuti),
             "stack_bb": stack / bb, "stack_bb_dopo": stack / nbb}
        giro = sb + bb + ante * (giocatori or 0)
        r["M"] = stack / giro if giro else None  # quanti giri di bui puoi pagare senza giocare
        parti = [f"Bui {sb}/{bb}" + (f" ante {ante}" if ante else "") + f" (livello {r['livello']}).",
                 f"Hai {r['stack_bb']:.0f} bb: {zona(r['stack_bb'])}."]
        if minuti:
            resta = minuti - (ts - cur["ts"]) / 60
            r["minuti_al_prossimo"] = max(0.0, resta)
            parti.append(f"Velocità {r['velocita']}. Tra circa {max(0, resta):.0f} min i bui passano a "
                         f"{nsb}/{nbb}: scenderai a {r['stack_bb_dopo']:.0f} bb.")
        else:
            parti.append(f"Prossimo livello: {nsb}/{nbb} (non so ancora quando: sto misurando la velocità).")
        if r["stack_bb"] > 15 >= r["stack_bb_dopo"]:
            parti.append("Al prossimo livello entri nella zona push/fold.")
        r["messaggio"] = " ".join(parti)
        return r

    def to_dict(self):
        return {"minuti": self.minuti_dati, "livelli": self.livelli, "storia": self.storia}

    @classmethod
    def from_dict(cls, d):
        d = d or {}
        liv = [tuple(x) for x in d["livelli"]] if d.get("livelli") else None
        return cls(d.get("minuti"), liv, d.get("storia"))
