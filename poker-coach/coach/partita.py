"""La partita in corso: cash o torneo, quanti giocatori, bui, stack iniziale, velocità.

Viene salvata in `partita.json`. Quando il programma saprà leggere il tavolo la
aggiornerà da solo (giocatori seduti, bui); fino ad allora la imposti tu con
PARTITA.bat (domande guidate) e puoi aggiornare i bui a ogni livello.

    py -m coach.partita                 domande guidate
    py -m coach.partita mostra          riepilogo
    py -m coach.partita bui 15 30       i bui sono cambiati adesso (aggiunge --ante N se c'è)
    py -m coach.partita stato 1240      previsione con il tuo stack attuale
    py -m coach.partita giocatori 5     è uscito qualcuno: ora siete in 5
"""
import json
import os
from dataclasses import asdict, dataclass, field

from .torneo import VELOCITA, Osservatore

FILE = "partita.json"


@dataclass
class Partita:
    tipo: str = "torneo"            # "torneo" o "cash"
    giocatori: int = 6              # seduti al tavolo adesso (2..10)
    posti: int = 6                  # posti del tavolo (6, 9, ...)
    sb: float = 10
    bb: float = 20
    ante: float = 0
    stack_iniziale: float = 1500
    pagati: int = 0                 # quanti posti vanno a premio (torneo)
    bui: dict = field(default_factory=dict)  # dati dell'Osservatore (velocità e storia dei livelli)

    def osservatore(self):
        return Osservatore.from_dict(self.bui)

    def aggiorna_bui(self, sb, bb, ante=0, ts=None):
        o = self.osservatore()
        cambiato = o.registra(sb, bb, ante, ts)
        self.sb, self.bb, self.ante, self.bui = sb, bb, ante, o.to_dict()
        return cambiato

    def riassunto(self):
        t = "Torneo" if self.tipo == "torneo" else "Cash"
        s = f"{t} · {self.giocatori} giocatori (tavolo da {self.posti}) · bui {self.sb:g}/{self.bb:g}"
        if self.ante:
            s += f" ante {self.ante:g}"
        if self.tipo == "torneo":
            s += f" · stack iniziale {self.stack_iniziale:g} ({self.stack_iniziale / self.bb:.0f} bb)"
            m = self.osservatore().minuti_livello()
            s += f" · livelli da {m:g} min" if m else " · velocità bui: la sto misurando"
        return s


def carica(path=FILE):
    if not os.path.exists(path):
        return None
    with open(path, encoding="utf-8") as f:
        d = json.load(f)
    return Partita(**{k: v for k, v in d.items() if k in Partita.__dataclass_fields__})


def salva(p, path=FILE):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(asdict(p), f, ensure_ascii=False, indent=1)


def _chiedi(domanda, default, tipo=str, scelte=None):
    while True:
        r = input(f"{domanda} [{default}]: ").strip()
        if not r:
            return default
        try:
            v = tipo(r.replace(",", "."))
        except ValueError:
            print("  non ho capito, riprova")
            continue
        if scelte and v not in scelte:
            print(f"  scegli tra: {', '.join(map(str, scelte))}")
            continue
        return v


def domande(path=FILE):
    vecchia = carica(path) or Partita()
    print("\n=== LA TUA PARTITA ===  (INVIO = tieni il valore tra parentesi)\n")
    tipo = _chiedi("Torneo o cash? (t/c)", "t" if vecchia.tipo == "torneo" else "c", str, ["t", "c"])
    p = Partita(tipo="torneo" if tipo == "t" else "cash")
    p.posti = _chiedi("Quanti posti ha il tavolo (es. 6 o 9)?", vecchia.posti, int, list(range(2, 11)))
    p.giocatori = _chiedi("Quanti giocatori sono seduti adesso?", min(vecchia.giocatori, p.posti), int,
                          list(range(2, p.posti + 1)))
    p.sb = _chiedi("Small blind attuale", vecchia.sb, float)
    p.bb = _chiedi("Big blind attuale", vecchia.bb, float)
    p.ante = _chiedi("Ante (0 se non c'è)", vecchia.ante, float)
    minuti = None
    if p.tipo == "torneo":
        p.stack_iniziale = _chiedi("Stack iniziale (fiches a testa all'inizio)", vecchia.stack_iniziale, float)
        p.pagati = _chiedi("Quanti posti vanno a premio (0 se non lo sai)", vecchia.pagati, int)
        print("Ogni quanti minuti salgono i bui?  " +
              "  ".join(f"{k}={v}" for k, v in VELOCITA.items()) + "   0 = non lo so, imparalo tu")
        minuti = _chiedi("Minuti per livello", 0, float) or None
    p.bui = Osservatore(minuti).to_dict()
    p.aggiorna_bui(p.sb, p.bb, p.ante)
    salva(p, path)
    print("\nSalvato:", p.riassunto())
    return p


def main(argv=None):
    import argparse
    ap = argparse.ArgumentParser(prog="coach.partita")
    ap.add_argument("--file", default=FILE)
    sub = ap.add_subparsers(dest="cmd")
    sub.add_parser("mostra")
    b = sub.add_parser("bui")
    b.add_argument("sb", type=float)
    b.add_argument("bb", type=float)
    b.add_argument("--ante", type=float, default=0)
    s = sub.add_parser("stato")
    s.add_argument("stack", type=float)
    g = sub.add_parser("giocatori")
    g.add_argument("n", type=int)
    a = ap.parse_args(argv)
    if not a.cmd:
        domande(a.file)
        return
    p = carica(a.file)
    if not p:
        raise SystemExit("Nessuna partita impostata: lancia PARTITA.bat (o py -m coach.partita).")
    if a.cmd == "bui":
        nuovo = p.aggiorna_bui(a.sb, a.bb, a.ante)
        salva(p, a.file)
        print("Nuovo livello registrato." if nuovo else "Stessi bui di prima: niente da cambiare.")
        m = p.osservatore().minuti_livello()
        if m:
            print(f"Velocità stimata: un livello ogni {m:g} minuti.")
    elif a.cmd == "giocatori":
        p.giocatori = max(2, min(a.n, 10))
        salva(p, a.file)
    elif a.cmd == "stato":
        print(p.osservatore().stato(a.stack, p.giocatori)["messaggio"])
        return
    print(p.riassunto())


if __name__ == "__main__":
    main()
