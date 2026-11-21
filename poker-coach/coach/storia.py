"""Archivio permanente di partite, mani, azioni e giocatori (SQLite).

Tutto resta salvato in un file (default `storia.db`): le statistiche di ogni
giocatore si accumulano partita dopo partita.

Uso tipico (lo farà da solo il lettore dello schermo):
    st = Storia("storia.db")
    p = st.nuova_partita("NomeGioco")
    m = st.nuova_mano(p, bb=10, giocatori=[
        {"nome": "Io", "posto": 1, "posizione": "BTN", "stack": 1000, "eroe": True},
        {"nome": "Bot3", "posto": 3, "posizione": "BB", "stack": 800}])
    st.azione(m, "Io", "preflop", "raise", 25)
    st.azione(m, "Bot3", "preflop", "call", 15, da_pagare=15)
    st.board(m, "7c 8d 2s")
    st.fine_mano(m, risultati={"Io": 40, "Bot3": -40}, mostrate={"Bot3": "9h 9d"})
"""
import sqlite3
import time

from .opponents import classifica

STRADE = ("preflop", "flop", "turn", "river")
TIPI_AZIONE = ("fold", "check", "call", "bet", "raise", "allin", "post")

_SCHEMA = """
CREATE TABLE IF NOT EXISTS partite (
  id INTEGER PRIMARY KEY, inizio REAL, gioco TEXT, note TEXT);
CREATE TABLE IF NOT EXISTS giocatori (
  id INTEGER PRIMARY KEY, nome TEXT UNIQUE NOT NULL, note TEXT DEFAULT '',
  primo_visto REAL, ultimo_visto REAL);
CREATE TABLE IF NOT EXISTS mani (
  id INTEGER PRIMARY KEY, partita_id INTEGER REFERENCES partite(id), numero INTEGER,
  ts REAL, bb REAL, board TEXT DEFAULT '', piatto REAL DEFAULT 0, chiusa INTEGER DEFAULT 0);
CREATE TABLE IF NOT EXISTS mani_giocatori (
  mano_id INTEGER REFERENCES mani(id), giocatore_id INTEGER REFERENCES giocatori(id),
  posto INTEGER, posizione TEXT, stack REAL, carte TEXT, risultato REAL DEFAULT 0,
  eroe INTEGER DEFAULT 0, PRIMARY KEY (mano_id, giocatore_id));
CREATE TABLE IF NOT EXISTS azioni (
  id INTEGER PRIMARY KEY, mano_id INTEGER REFERENCES mani(id), ordine INTEGER,
  strada TEXT, giocatore_id INTEGER REFERENCES giocatori(id), tipo TEXT,
  importo REAL DEFAULT 0, da_pagare REAL DEFAULT 0);
CREATE INDEX IF NOT EXISTS ix_az_g ON azioni(giocatore_id);
CREATE INDEX IF NOT EXISTS ix_mg_g ON mani_giocatori(giocatore_id);
"""


class Storia:
    def __init__(self, path="storia.db"):
        self.db = sqlite3.connect(path)
        self.db.row_factory = sqlite3.Row
        self.db.executescript(_SCHEMA)
        # colonne aggiunte dopo (archivi vecchi): orario, tempo di decisione, piatto al momento
        col = {r[1] for r in self.db.execute("PRAGMA table_info(azioni)")}
        for nome, tipo in (("ts", "REAL"), ("tempo", "REAL"), ("piatto", "REAL")):
            if nome not in col:
                self.db.execute(f"ALTER TABLE azioni ADD COLUMN {nome} {tipo}")
        self.db.commit()

    # ------------------------------------------------------------ scrittura
    def _gid(self, nome):
        now = time.time()
        r = self.db.execute("SELECT id FROM giocatori WHERE nome=?", (nome,)).fetchone()
        if r:
            self.db.execute("UPDATE giocatori SET ultimo_visto=? WHERE id=?", (now, r["id"]))
            return r["id"]
        return self.db.execute(
            "INSERT INTO giocatori(nome, primo_visto, ultimo_visto) VALUES (?,?,?)",
            (nome, now, now)).lastrowid

    def nuova_partita(self, gioco="", note=""):
        pid = self.db.execute("INSERT INTO partite(inizio, gioco, note) VALUES (?,?,?)",
                              (time.time(), gioco, note)).lastrowid
        self.db.commit()
        return pid

    def nuova_mano(self, partita_id, bb, giocatori, ts=None):
        n = self.db.execute("SELECT COUNT(*) FROM mani WHERE partita_id=?",
                            (partita_id,)).fetchone()[0] + 1
        mid = self.db.execute("INSERT INTO mani(partita_id, numero, ts, bb) VALUES (?,?,?,?)",
                              (partita_id, n, ts if ts is not None else time.time(), bb)).lastrowid
        for g in giocatori:
            self.db.execute(
                "INSERT INTO mani_giocatori(mano_id, giocatore_id, posto, posizione, stack, carte, eroe)"
                " VALUES (?,?,?,?,?,?,?)",
                (mid, self._gid(g["nome"]), g.get("posto"), g.get("posizione"),
                 g.get("stack"), g.get("carte"), 1 if g.get("eroe") else 0))
        self.db.commit()
        return mid

    def azione(self, mano_id, nome, strada, tipo, importo=0, da_pagare=0, tempo=None, piatto=None, ts=None):
        """tempo = secondi impiegati a decidere; piatto = piatto prima dell'azione."""
        if strada not in STRADE or tipo not in TIPI_AZIONE:
            raise ValueError(f"strada {STRADE}, tipo {TIPI_AZIONE}")
        o = self.db.execute("SELECT COUNT(*) FROM azioni WHERE mano_id=?", (mano_id,)).fetchone()[0]
        self.db.execute(
            "INSERT INTO azioni(mano_id, ordine, strada, giocatore_id, tipo, importo, da_pagare, tempo, piatto, ts)"
            " VALUES (?,?,?,?,?,?,?,?,?,?)", (mano_id, o + 1, strada, self._gid(nome), tipo, importo,
                                             da_pagare, tempo, piatto, ts if ts is not None else time.time()))
        self.db.commit()

    def board(self, mano_id, carte):
        self.db.execute("UPDATE mani SET board=? WHERE id=?", (carte, mano_id))
        self.db.commit()

    def fine_mano(self, mano_id, risultati, mostrate=None, piatto=0):
        """risultati: {nome: vincita netta (+/-)}; mostrate: {nome: 'Ah Kd'} carte viste allo showdown."""
        for nome, r in risultati.items():
            self.db.execute("UPDATE mani_giocatori SET risultato=? WHERE mano_id=? AND giocatore_id=?",
                            (r, mano_id, self._gid(nome)))
        for nome, c in (mostrate or {}).items():
            self.db.execute("UPDATE mani_giocatori SET carte=? WHERE mano_id=? AND giocatore_id=?",
                            (c, mano_id, self._gid(nome)))
        self.db.execute("UPDATE mani SET chiusa=1, piatto=? WHERE id=?", (piatto, mano_id))
        self.db.commit()

    def nota(self, nome, testo):
        self.db.execute("UPDATE giocatori SET note=? WHERE id=?", (testo, self._gid(nome)))
        self.db.commit()

    # ------------------------------------------------------------ lettura
    def statistiche(self, nome):
        g = self.db.execute("SELECT * FROM giocatori WHERE nome=?", (nome,)).fetchone()
        if not g:
            return None
        gid = g["id"]
        q = lambda sql: self.db.execute(sql, (gid,)).fetchone()[0] or 0
        mani = q("SELECT COUNT(*) FROM mani_giocatori WHERE giocatore_id=?")
        if not mani:
            return {"nome": nome, "mani": 0, "note": g["note"]}
        vpip = q("SELECT COUNT(DISTINCT mano_id) FROM azioni WHERE giocatore_id=? AND strada='preflop'"
                 " AND tipo IN ('call','bet','raise','allin')")
        pfr = q("SELECT COUNT(DISTINCT mano_id) FROM azioni WHERE giocatore_id=? AND strada='preflop'"
                " AND tipo IN ('bet','raise','allin')")
        aggr = q("SELECT COUNT(*) FROM azioni WHERE giocatore_id=? AND strada!='preflop'"
                 " AND tipo IN ('bet','raise','allin')")
        calls = q("SELECT COUNT(*) FROM azioni WHERE giocatore_id=? AND strada!='preflop' AND tipo='call'")
        faced = q("SELECT COUNT(*) FROM azioni WHERE giocatore_id=? AND strada!='preflop' AND da_pagare>0")
        folds = q("SELECT COUNT(*) FROM azioni WHERE giocatore_id=? AND strada!='preflop'"
                  " AND da_pagare>0 AND tipo='fold'")
        showdown = q("SELECT COUNT(*) FROM mani_giocatori WHERE giocatore_id=? AND carte IS NOT NULL"
                     " AND carte!='' AND eroe=0")
        net = q("SELECT SUM(risultato) FROM mani_giocatori WHERE giocatore_id=?")
        net_bb = q("SELECT SUM(mg.risultato / m.bb) FROM mani_giocatori mg JOIN mani m ON m.id=mg.mano_id"
                   " WHERE mg.giocatore_id=? AND m.bb>0")
        pr = {
            "nome": nome, "mani": mani, "VPIP": vpip / mani, "PFR": pfr / mani,
            "AF": aggr / max(calls, 1), "fold_a_puntata": folds / faced if faced else None,
            "showdown": showdown, "vincita": net, "bb_100": 100 * net_bb / mani, "note": g["note"],
        }
        pr["tipo"] = classifica(pr)
        return pr

    def tipo(self, nome):
        s = self.statistiche(nome)
        return s.get("tipo") if s else None

    def avversari(self):
        """Tutti i giocatori che non sei mai stato tu."""
        rows = self.db.execute(
            "SELECT nome FROM giocatori WHERE id NOT IN (SELECT giocatore_id FROM mani_giocatori WHERE eroe=1)"
            " ORDER BY ultimo_visto DESC").fetchall()
        return [r["nome"] for r in rows]

    def giocatori(self):
        rows = self.db.execute("SELECT nome FROM giocatori ORDER BY ultimo_visto DESC").fetchall()
        return [self.statistiche(r["nome"]) for r in rows]

    def mani_mostrate(self, nome, limite=50):
        """Le carte che il giocatore ha mostrato e come ha giocato: oro per capire i bot."""
        rows = self.db.execute(
            "SELECT m.id, m.board, mg.carte, mg.posizione, mg.risultato FROM mani_giocatori mg"
            " JOIN mani m ON m.id=mg.mano_id JOIN giocatori g ON g.id=mg.giocatore_id"
            " WHERE g.nome=? AND mg.carte IS NOT NULL AND mg.carte!='' ORDER BY m.id DESC LIMIT ?",
            (nome, limite)).fetchall()
        out = []
        for r in rows:
            az = self.db.execute(
                "SELECT strada, tipo, importo FROM azioni a JOIN giocatori g ON g.id=a.giocatore_id"
                " WHERE a.mano_id=? AND g.nome=? ORDER BY ordine", (r["id"], nome)).fetchall()
            out.append({"mano": r["id"], "carte": r["carte"], "board": r["board"],
                        "posizione": r["posizione"], "risultato": r["risultato"],
                        "azioni": [f"{a['strada']}:{a['tipo']}{' ' + format(a['importo'], 'g') if a['importo'] else ''}"
                                   for a in az]})
        return out

    def mano(self, mano_id):
        """Ricostruzione testuale di una mano."""
        m = self.db.execute("SELECT * FROM mani WHERE id=?", (mano_id,)).fetchone()
        if not m:
            return "mano non trovata"
        righe = [f"Mano #{m['id']} (partita {m['partita_id']}, n.{m['numero']})  bb {m['bb']:g}"
                 f"  board: {m['board'] or '-'}"]
        for g in self.db.execute(
                "SELECT g.nome, mg.* FROM mani_giocatori mg JOIN giocatori g ON g.id=mg.giocatore_id"
                " WHERE mano_id=? ORDER BY posto", (mano_id,)):
            righe.append(f"  {'*' if g['eroe'] else ' '} {g['nome']:<12} {g['posizione'] or '':<4}"
                         f" stack {g['stack'] or 0:g}  carte {g['carte'] or '??':<6} risultato {g['risultato']:+g}")
        for a in self.db.execute(
                "SELECT a.strada, g.nome, a.tipo, a.importo FROM azioni a JOIN giocatori g"
                " ON g.id=a.giocatore_id WHERE mano_id=? ORDER BY ordine", (mano_id,)):
            righe.append(f"    {a['strada']:<7} {a['nome']:<12} {a['tipo']} {a['importo'] or '':}")
        return "\n".join(righe)

    def ultime_mani(self, limite=20, nome=None):
        if nome:
            sql = ("SELECT m.id FROM mani m JOIN mani_giocatori mg ON mg.mano_id=m.id JOIN giocatori g"
                   " ON g.id=mg.giocatore_id WHERE g.nome=? ORDER BY m.id DESC LIMIT ?")
            return [r[0] for r in self.db.execute(sql, (nome, limite))]
        return [r[0] for r in self.db.execute("SELECT id FROM mani ORDER BY id DESC LIMIT ?", (limite,))]


def _pct(x):
    return "-" if x is None else f"{x:.0%}"


def main(argv=None):
    import argparse
    ap = argparse.ArgumentParser(prog="coach.storia", description="Archivio mani e giocatori")
    ap.add_argument("--db", default="storia.db")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("giocatori", help="tabella di tutti i giocatori")
    g = sub.add_parser("giocatore", help="scheda di un giocatore")
    g.add_argument("nome")
    m = sub.add_parser("mani", help="ultime mani")
    m.add_argument("--nome")
    m.add_argument("--limite", type=int, default=10)
    n = sub.add_parser("nota", help="scrivi una nota su un giocatore")
    n.add_argument("nome")
    n.add_argument("testo")
    r = sub.add_parser("ritratto", help="descrizione a parole di un avversario (+ è un bot?)")
    r.add_argument("nome")
    sub.add_parser("ritratti", help="tutti gli avversari + riepilogo bot")
    a = ap.parse_args(argv)
    st = Storia(a.db)
    if a.cmd == "giocatori":
        print(f"{'nome':<14}{'mani':>6}{'VPIP':>6}{'PFR':>6}{'AF':>6}{'fold':>6}{'bb/100':>8}  tipo")
        for s in st.giocatori():
            if not s["mani"]:
                continue
            print(f"{s['nome']:<14}{s['mani']:>6}{_pct(s['VPIP']):>6}{_pct(s['PFR']):>6}"
                  f"{s['AF']:>6.1f}{_pct(s['fold_a_puntata']):>6}{s['bb_100']:>8.1f}  {s['tipo'] or '(pochi dati)'}")
    elif a.cmd == "giocatore":
        s = st.statistiche(a.nome)
        if not s:
            print("giocatore sconosciuto")
            return
        for k, v in s.items():
            print(f"  {k:<15} {_pct(v) if k in ('VPIP', 'PFR', 'fold_a_puntata') else v}")
        print("  mani mostrate:")
        for x in st.mani_mostrate(a.nome, 15):
            print(f"    {x['carte']:<6} {x['posizione'] or '':<4} board {x['board'] or '-':<15}"
                  f" {x['risultato']:+g}  {' '.join(x['azioni'])}")
    elif a.cmd == "mani":
        for mid in st.ultime_mani(a.limite, a.nome):
            print(st.mano(mid), "\n")
    elif a.cmd in ("ritratto", "ritratti"):
        from .ritratto import descrivi, riepilogo
        print(descrivi(st, a.nome) if a.cmd == "ritratto" else riepilogo(st))
    elif a.cmd == "nota":
        st.nota(a.nome, a.testo)
        print("nota salvata")


if __name__ == "__main__":
    main()
