"""Il FILE DEL GIOCO: il tuo programma scrive una riga per ogni cosa che succede, il coach la legge.

Formato: JSON Lines (un oggetto JSON per riga, in UTF-8). Spiegazione completa: FORMATO-FILE.txt.
Tre tipi di riga:

  {"tipo":"stato", "gioco":"PokerTH", "mano":12, "bb":20, "ante":0, "bottone":2, "hero":1,
   "tocca_a":1, "board":"7h 7c 2s", "piatto":200,
   "posti":{"1":{"nome":"Hero","stack":1400,"puntata":0,"carte":"As Kd","in_mano":true}, ...}}
  {"tipo":"azione", "mano":12, "posto":3, "strada":"flop", "azione":"bet", "importo":100}
  {"tipo":"fine_mano", "mano":12, "risultati":{"1":-300,"3":300}, "mostrate":{"3":"7d 8d"}}

- "piatto" = tutto quello che c'è al centro, comprese le puntate di questo giro.
- "puntata" = quanto ha messo quel posto in QUESTO giro (preflop, flop, turn, river).
- "importo" di un'azione = quanto mette con quella mossa.
- azioni: fold, check, call, bet, raise, allin (anche "post" per i bui).
"""
import json
import os
from dataclasses import dataclass, field

from .lettore import bloccato

STRADE = {0: "preflop", 3: "flop", 4: "turn", 5: "river"}
AZIONI = ("fold", "check", "call", "bet", "raise", "allin", "post")


def leggi_riga(riga):
    """Una riga del file -> dizionario, oppure None se non è valida (righe a metà, commenti, vuote)."""
    riga = riga.strip()
    if not riga or riga.startswith("#"):
        return None
    try:
        d = json.loads(riga)
    except ValueError:
        return None
    return d if isinstance(d, dict) and d.get("tipo") in ("stato", "azione", "fine_mano") else None


class Segui:
    """Legge le righe NUOVE di un file che il gioco sta ancora scrivendo (come «tail -f»)."""

    def __init__(self, path, dall_inizio=False):
        self.path = path
        self.pos = 0
        self.resto = ""
        if not dall_inizio and os.path.exists(path):
            self.pos = os.path.getsize(path)

    def nuove(self):
        if not os.path.exists(self.path):
            return []
        dim = os.path.getsize(self.path)
        if dim < self.pos:  # file ricominciato da capo
            self.pos, self.resto = 0, ""
        with open(self.path, encoding="utf-8", errors="replace") as f:
            f.seek(self.pos)
            testo = f.read()
            self.pos = f.tell()
        testo = self.resto + testo
        righe = testo.split("\n")
        self.resto = righe.pop()  # l'ultima può essere a metà: aspetto che il gioco la finisca
        return [d for d in (leggi_riga(r) for r in righe) if d]


@dataclass
class StatoTavolo:
    """Il tavolo come lo descrive il file: serve sia al coach sia al mini-tavolo disegnato."""
    gioco: str = ""
    mano: int = None
    bb: float = None
    ante: float = 0.0
    bottone: int = None
    hero: int = 1
    tocca_a: int = None
    board: list = field(default_factory=list)
    piatto: float = 0.0
    posti: dict = field(default_factory=dict)   # int -> {nome, stack, puntata, carte, in_mano}
    errore: str = ""

    @classmethod
    def da_riga(cls, d):
        posti = {}
        for k, p in (d.get("posti") or {}).items():
            try:
                posti[int(k)] = {"nome": str(p.get("nome", f"Posto {k}")), "stack": p.get("stack"),
                                 "puntata": float(p.get("puntata") or 0), "carte": p.get("carte") or "",
                                 "in_mano": bool(p.get("in_mano", True))}
            except (TypeError, ValueError, AttributeError):
                continue
        board = d.get("board") or ""
        return cls(gioco=str(d.get("gioco", "")), mano=d.get("mano"), bb=d.get("bb"), ante=d.get("ante") or 0.0,
                   bottone=d.get("bottone"), hero=int(d.get("hero") or 1), tocca_a=d.get("tocca_a"),
                   board=board.split() if isinstance(board, str) else list(board),
                   piatto=float(d.get("piatto") or 0), posti=posti)

    @property
    def strada(self):
        return STRADE.get(len(self.board), "preflop")

    def seduti(self):
        return sorted(k for k, p in self.posti.items() if p.get("stack") is not None)

    def campi(self):
        """Campi della finestra del coach (stringhe) da questo stato."""
        from .tavolo import posizione_da_posti
        me = self.posti.get(self.hero, {})
        mia = me.get("puntata", 0.0)
        massima = max([p.get("puntata", 0.0) for p in self.posti.values()] or [0.0])
        in_mano = [k for k, p in self.posti.items() if p.get("in_mano")]
        c = {"carte": me.get("carte", ""), "tavolo": " ".join(self.board), "piatto": f"{self.piatto:g}",
             "pagare": f"{max(0.0, massima - mia):g}", "giocatori": str(len(self.seduti())),
             "avversari": str(max(1, len(in_mano) - 1))}
        if me.get("stack") is not None:
            c["stack"] = f"{float(me['stack']):g}"
        if self.bb:
            c["bb"] = f"{float(self.bb):g}"
        c["ante"] = f"{float(self.ante or 0):g}"
        if self.bottone in self.seduti() and self.hero in self.seduti():
            try:
                c["posizione"] = posizione_da_posti(self.seduti(), self.bottone, self.hero)
            except ValueError:
                pass
        return c


class Registro:
    """Applica le righe del file una dopo l'altra: tiene lo stato attuale e costruisce le mani."""

    def __init__(self):
        self.stato = None
        self.mani = {}          # numero mano -> dati per l'archivio
        self.ordine = []
        self.bloccato = False

    def _mano(self, n):
        if n not in self.mani:
            self.mani[n] = {"mano": n, "inizio": None, "ultimo": None, "azioni": [], "risultati": None,
                            "mostrate": {}}
            self.ordine.append(n)
        return self.mani[n]

    def applica(self, d):
        tipo = d.get("tipo")
        if tipo == "stato":
            s = StatoTavolo.da_riga(d)
            if bloccato(s.gioco):
                self.bloccato = True
                self.stato = None
                return None
            self.stato = s
            if s.mano is not None:
                m = self._mano(s.mano)
                if m["inizio"] is None:
                    m["inizio"] = s
                m["ultimo"] = s
            return s
        if tipo == "azione" and d.get("mano") is not None:
            if d.get("azione") not in AZIONI:
                return None
            m = self._mano(d["mano"])
            # la situazione PRIMA della mossa (serve al ripasso: piatto e quanto dovevi pagare)
            prima = m["ultimo"]
            m["azioni"].append({"posto": int(d.get("posto") or 0), "strada": d.get("strada") or
                                (prima.strada if prima else "preflop"), "azione": d["azione"],
                                "importo": float(d.get("importo") or 0), "prima": prima})
        elif tipo == "fine_mano" and d.get("mano") is not None:
            m = self._mano(d["mano"])
            m["risultati"] = {int(k): float(v) for k, v in (d.get("risultati") or {}).items()}
            m["mostrate"] = {int(k): v for k, v in (d.get("mostrate") or {}).items()}
        return self.stato

    def salva_in_storia(self, st, partita_id, solo_finite=True):
        """Scrive nell'archivio le mani finite (azioni di tutti, carte mostrate, risultati)."""
        salvate = 0
        for n in self.ordine:
            m = self.mani[n]
            ini = m["inizio"]
            if not ini or (solo_finite and m["risultati"] is None) or m.get("salvata"):
                continue
            c = ini.campi()
            nomi = {k: ("Io" if k == ini.hero else p["nome"]) for k, p in ini.posti.items()}
            gioc = []
            for k in ini.seduti():
                p = ini.posti[k]
                gioc.append({"nome": nomi[k], "posto": k, "stack": p.get("stack"), "eroe": k == ini.hero,
                             "carte": p.get("carte") if k == ini.hero else None,
                             "posizione": c.get("posizione") if k == ini.hero else None})
            mid = st.nuova_mano(partita_id, float(ini.bb or 1), gioc, n_giocatori=len(ini.seduti()),
                                ante=float(ini.ante or 0), modalita="file")
            fine = m["ultimo"] or ini
            st.board(mid, " ".join(fine.board))
            for a in m["azioni"]:
                prima = a["prima"] or ini
                me = prima.posti.get(a["posto"], {})
                massima = max([p.get("puntata", 0.0) for p in prima.posti.values()] or [0.0])
                in_mano = [k for k, p in prima.posti.items() if p.get("in_mano")]
                st.azione(mid, nomi.get(a["posto"], f"Posto {a['posto']}"), a["strada"], a["azione"],
                          a["importo"], da_pagare=max(0.0, massima - me.get("puntata", 0.0)),
                          piatto=prima.piatto, avversari=max(1, len(in_mano) - 1))
            ris = {nomi.get(k, f"Posto {k}"): v for k, v in (m["risultati"] or {}).items()}
            most = {nomi.get(k, f"Posto {k}"): v for k, v in m["mostrate"].items() if k != ini.hero}
            st.fine_mano(mid, ris, most, piatto=fine.piatto)
            m["salvata"] = True
            salvate += 1
        return salvate


def leggi_file(path):
    """Tutto il file in una volta (per il ripasso a partita finita)."""
    r = Registro()
    with open(path, encoding="utf-8", errors="replace") as f:
        for riga in f:
            d = leggi_riga(riga)
            if d:
                r.applica(d)
    return r
