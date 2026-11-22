"""Ricostruisce le mani dalle letture del tavolo (foto dopo foto) e le salva nell'archivio.

Lavora A PARTITA FINITA sulle foto registrate (ANNOTAZIONI / IMPARA): niente lettura dal vivo.

Cosa capisce, confrontando una lettura con la successiva:
- inizio mano: compaiono carte tue diverse da quelle di prima;
- fasi: il tavolo passa da 0 a 3, 4, 5 carte (preflop, flop, turn, river);
- puntate: la puntata di un posto sale -> call (pareggia), bet/raise (supera), all-in (stack a zero);
- le TUE decisioni: quando tocca a te fotografa la situazione (piatto, da pagare, tavolo, stack) e guarda
  cosa succede dopo: la tua puntata sale (call/raise), le tue carte spariscono (fold), la mano va
  avanti senza che tu metta niente (check);
- risultato: il tuo stack all'inizio della mano dopo meno quello all'inizio di questa.
Le letture incerte vengono saltate (meglio una mossa in meno che una inventata).
"""
from dataclasses import dataclass, field

from .tavolo import posizione_da_posti

STRADE = {0: "preflop", 3: "flop", 4: "turn", 5: "river"}


@dataclass
class _Decisione:
    strada: str
    tavolo: list
    piatto: float
    da_pagare: float
    stack: float
    mia_puntata: float
    avversari: int
    ts: float
    tipo: str = None
    importo: float = 0.0


@dataclass
class Mano:
    carte: list
    ts: float
    stack_inizio: dict
    bottone: int = None
    tavolo: list = field(default_factory=list)
    decisioni: list = field(default_factory=list)
    azioni_altri: list = field(default_factory=list)   # (strada, posto, tipo, importo, ts)
    risultato: float = None
    foldata: bool = False


class Ricostruttore:
    def __init__(self, mio=1):
        self.mio = mio
        self.mani = []
        self.corrente = None
        self.prec = None          # ultima lettura sicura
        self.in_attesa = None     # decisione fotografata, aspetto di vedere cosa hai fatto

    # -------------------------------------------------------------- aiuti
    def _strada(self, l):
        return STRADE.get(len(l.tavolo), "preflop")

    def _max_puntata(self, l):
        return max([v for v in l.puntate.values() if v] or [0.0])

    def _chiudi_attesa(self, tipo, importo=0.0):
        if self.in_attesa and self.corrente:
            self.in_attesa.tipo, self.in_attesa.importo = tipo, importo
            self.corrente.decisioni.append(self.in_attesa)
        self.in_attesa = None

    def _chiudi_mano(self, stack_dopo=None):
        m = self.corrente
        if not m:
            return
        if self.in_attesa:  # la mano è finita mentre aspettavo: se non hai messo niente era check/fold
            self._chiudi_attesa("fold" if self.in_attesa.da_pagare > 0 else "check")
        inizio = m.stack_inizio.get(self.mio)
        if stack_dopo is not None and inizio is not None:
            m.risultato = stack_dopo - inizio
        self.mani.append(m)
        self.corrente = None

    # -------------------------------------------------------------- una lettura
    def aggiungi(self, l, ts):
        if l is None or not l.sicura():
            return
        mie = [c for c in l.mie_carte if c != "?"]
        p = self.prec

        # nuova mano: carte tue diverse (o ricomparse) rispetto all'ultima mano
        if len(mie) == 2 and (self.corrente is None or set(mie) != set(self.corrente.carte)):
            self._chiudi_mano(l.stack.get(self.mio))
            self.corrente = Mano(carte=mie, ts=ts, stack_inizio=dict(l.stack), bottone=l.bottone)
            p = None  # niente confronti con la mano di prima
        m = self.corrente
        if m is None:
            self.prec = l
            return
        if len(l.tavolo) > len(m.tavolo):
            m.tavolo = list(l.tavolo)
        if l.bottone and not m.bottone:
            m.bottone = l.bottone

        if p is not None:
            strada_prec, strada = self._strada(p), self._strada(l)
            nuova_strada = strada != strada_prec
            # puntate degli altri: salite = azioni
            if not nuova_strada:
                massimo_prec = self._max_puntata(p)
                for k, v in l.puntate.items():
                    prima = p.puntate.get(k) or 0.0
                    if k == self.mio or not v or v <= prima:
                        continue
                    if l.stack.get(k) == 0:
                        tipo = "allin"
                    elif v > massimo_prec:
                        tipo = "bet" if massimo_prec == 0 else "raise"
                    else:
                        tipo = "call"
                    m.azioni_altri.append((strada, k, tipo, v, ts))
            # la TUA mossa, se stavo aspettando
            if self.in_attesa:
                mia_prima = self.in_attesa.mia_puntata
                mia_ora = l.puntate.get(self.mio) or 0.0
                carte_sparite = len(mie) < 2
                if carte_sparite:
                    m.foldata = True
                    self._chiudi_attesa("fold")
                elif not nuova_strada and mia_ora > mia_prima:
                    messo = mia_ora - mia_prima
                    if l.stack.get(self.mio) == 0:
                        tipo = "allin"
                    elif mia_ora > self.in_attesa.da_pagare + mia_prima + 1e-9:
                        tipo = "raise" if self.in_attesa.da_pagare > 0 else "bet"
                    else:
                        tipo = "call"
                    self._chiudi_attesa(tipo, messo)
                elif nuova_strada:
                    # la fase è cambiata: se dovevi pagare e sei ancora in mano hai pagato, altrimenti check
                    if self.in_attesa.da_pagare > 0:
                        self._chiudi_attesa("call", min(self.in_attesa.da_pagare, self.in_attesa.stack))
                    else:
                        self._chiudi_attesa("check")

        # tocca a te: fotografo la situazione
        if l.tocca_a_me and self.in_attesa is None and len(mie) == 2 and not m.foldata:
            mia = l.puntate.get(self.mio) or 0.0
            occupati = [k for k, v in l.stack.items() if v is not None]
            self.in_attesa = _Decisione(
                strada=self._strada(l), tavolo=list(l.tavolo), piatto=l.piatto or 0.0,
                da_pagare=max(0.0, self._max_puntata(l) - mia), stack=l.stack.get(self.mio) or 0.0,
                mia_puntata=mia, avversari=max(1, len(occupati) - 1), ts=ts)
        self.prec = l

    def fine(self):
        stack_finale = self.prec.stack.get(self.mio) if self.prec else None
        self._chiudi_mano(stack_finale if self.corrente and self.corrente.decisioni else None)
        return self.mani


def salva_in_storia(mani, st, partita_id, bb, n_posti, ante=0.0, mio=1):
    """Scrive le mani ricostruite nell'archivio (le TUE decisioni + le azioni viste degli altri)."""
    salvate = 0
    for m in mani:
        occupati = [k for k, v in m.stack_inizio.items() if v is not None]
        n = max(2, min(len(occupati) or n_posti, 10))
        pos = None
        if m.bottone in occupati and mio in occupati:
            try:
                pos = posizione_da_posti(occupati, m.bottone, mio)
            except Exception:
                pos = None
        gioc = [{"nome": "Io", "eroe": True, "carte": " ".join(m.carte), "posizione": pos,
                 "stack": m.stack_inizio.get(mio)}]
        gioc += [{"nome": f"Posto {k}", "posto": k, "stack": m.stack_inizio.get(k)} for k in occupati if k != mio]
        mid = st.nuova_mano(partita_id, bb, gioc, ts=m.ts, n_giocatori=n, ante=ante, modalita="registrata")
        st.board(mid, " ".join(m.tavolo))
        eventi = [(d.ts, "io", d) for d in m.decisioni] + [(a[4], "altro", a) for a in m.azioni_altri]
        for _, chi, x in sorted(eventi, key=lambda e: e[0]):
            if chi == "io":
                st.azione(mid, "Io", x.strada, x.tipo, x.importo, da_pagare=x.da_pagare, piatto=x.piatto,
                          avversari=x.avversari, ts=x.ts)
            else:
                strada, k, tipo, importo, ts = x
                st.azione(mid, f"Posto {k}", strada, tipo, importo, ts=ts)
        st.fine_mano(mid, {"Io": m.risultato or 0.0})
        salvate += 1
    return salvate


def analizza_registrazione(cartella, st, partita_id, cal, bb, ante=0.0, cartella_modelli=None, titolo=None,
                           avanzamento=None):
    """Legge tutte le foto di una partita registrata e salva le mani nell'archivio. Solo a partita finita.

    Ritorna un dizionario con quante foto ha letto, quante erano incerte e quante mani ha ricostruito.
    """
    import csv
    import os

    from PIL import Image

    from .lettore import MODELLI, Lettore, bloccato

    if bloccato(titolo) or bloccato(cal.titolo):
        return {"bloccata": True, "foto": 0, "sicure": 0, "mani": 0}
    ore = {}
    indice = os.path.join(cartella, "indice.csv")
    if os.path.exists(indice):
        import time as _t
        with open(indice, encoding="utf-8") as f:
            for r in csv.DictReader(f):
                try:
                    ore[r["file"]] = _t.mktime(_t.strptime(r["ora"], "%Y-%m-%d %H:%M:%S"))
                except Exception:
                    pass
    foto = sorted(x for x in os.listdir(cartella) if x.lower().endswith((".jpg", ".png")))
    lett = Lettore(cal, cartella_modelli or MODELLI)
    r = Ricostruttore()
    sicure, incerte_zone = 0, {}
    for i, nome in enumerate(foto):
        p = os.path.join(cartella, nome)
        l = lett.leggi(Image.open(p).convert("RGB"), titolo or cal.titolo)
        if l.sicura():
            sicure += 1
        for z in l.incerti:
            incerte_zone[z] = incerte_zone.get(z, 0) + 1
        r.aggiungi(l, ore.get(nome, os.path.getmtime(p)))
        if avanzamento:
            avanzamento(i + 1, len(foto))
    mani = r.fine()
    n = salva_in_storia(mani, st, partita_id, bb, cal.n_posti, ante)
    return {"bloccata": False, "foto": len(foto), "sicure": sicure, "mani": n,
            "incerte": sorted(incerte_zone.items(), key=lambda kv: -kv[1])}
