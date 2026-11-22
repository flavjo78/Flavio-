"""Finestra del coach, sul lato destro dello schermo (1/4), con l'interruttore della modalità.

- RIPASSO (predefinita): nessun suggerimento durante il gioco.
- DAL VIVO: suggerimenti in tempo reale. Da usare solo nelle partite contro il computer
  (offline o dove il regolamento lo permette): lo decidi tu, con una conferma.

Finché il programma non sa leggere il tavolo, la mano la inserisci tu: carte (con la
tastierina), piatto, quanto pagare, stack. Poi INVIO o "Consiglia".

    py -m coach.finestra
"""
import threading

from . import annotazioni, tavolo
from .advisor import Situation, advise, TIPI
from .equity import parse_cards

COL = {"bg": "#12151b", "card": "#1a1e26", "txt": "#e8ebf0", "mut": "#a3acb9", "bordo": "#2a2f3a",
       "oro": "#ffd27a", "blu": "#7cc4ff", "verde": "#9ff0c4", "rosso": "#ff9b9b", "campo": "#0f1216"}
COLORE_AZIONE = {"FOLD": "#cfd5de", "CHECK": "#cfd5de", "CALL": "#7cc4ff",
                 "BET": "#ffd27a", "RAISE": "#ffd27a", "ALL-IN": "#ff9b9b"}
SEMI = {"h": "♥", "d": "♦", "c": "♣", "s": "♠"}
CONFERMA_VIVO = ("Modalità DAL VIVO: il coach ti darà suggerimenti mentre giochi.\n\n"
                 "Usala solo nelle partite contro il computer (offline, o su siti il cui regolamento "
                 "lo permette). Contro persone vere gli aiuti in tempo reale sono vietati.\n\n"
                 "Vuoi attivarla?")


def _num(testo, nome, minimo=0.0):
    t = (testo or "").strip().replace(",", ".")
    if not t:
        return 0.0
    try:
        v = float(t)
    except ValueError:
        raise ValueError(f"{nome}: scrivi un numero")
    if v < minimo:
        raise ValueError(f"{nome}: non può essere negativo")
    return v


def costruisci_situazione(c):
    """Dai campi della finestra (stringhe) alla Situation. Errori in italiano, mai valori inventati."""
    try:
        hole = parse_cards(c.get("carte", ""))
    except Exception:
        raise ValueError("Le tue carte: scrivile tipo «Ah Kd» (h=♥ d=♦ c=♣ s=♠)")
    try:
        board = parse_cards(c.get("tavolo", ""))
    except Exception:
        raise ValueError("Carte sul tavolo: scrivile tipo «7c 8d 2s»")
    if len(hole) != 2:
        raise ValueError("Servono esattamente 2 carte tue")
    if len(board) not in (0, 3, 4, 5):
        raise ValueError("Sul tavolo ci vanno 0, 3, 4 o 5 carte")
    if len(set(hole + board)) != len(hole) + len(board):
        raise ValueError("C'è una carta ripetuta")
    giocatori = int(_num(c.get("giocatori", "6"), "Giocatori") or 6)
    if not 2 <= giocatori <= 10:
        raise ValueError("Giocatori al tavolo: da 2 a 10")
    pos = (c.get("posizione") or "").upper()
    if pos not in tavolo.posizioni(giocatori):
        raise ValueError(f"Posizione: con {giocatori} giocatori scegli tra " + ", ".join(tavolo.posizioni(giocatori)))
    bb = _num(c.get("bb", ""), "Big blind")
    if bb <= 0:
        raise ValueError("Big blind: serve un numero maggiore di zero")
    stack = _num(c.get("stack", ""), "Il tuo stack")
    if stack <= 0:
        raise ValueError("Il tuo stack: serve un numero maggiore di zero")
    avv = int(_num(c.get("avversari", "1"), "Avversari in mano") or 1)
    if not 1 <= avv <= giocatori - 1:
        raise ValueError(f"Avversari in mano: da 1 a {giocatori - 1}")
    chi = (c.get("rilancia") or "").upper() or None
    if chi in ("", "-", "NESSUNO"):
        chi = None
    tipo = (c.get("tipo") or "").lower() or None
    if tipo and tipo not in TIPI:
        tipo = None
    return Situation(hole=hole, board=board, pot=_num(c.get("piatto"), "Piatto"),
                     to_call=_num(c.get("pagare"), "Da pagare"), stack=stack, bb=bb,
                     n_opp=avv, position=pos, opp_type=tipo, n_players=giocatori,
                     ante=_num(c.get("ante"), "Ante"), chi_rilancia=chi)


def _chiave(s):
    """Identifica una situazione: serve a sapere se il consiglio mostrato è quello della mossa salvata."""
    return (tuple(s.hole), tuple(s.board), s.pot, s.to_call, s.position)


def importo_mossa(tipo, s, quanto=0.0, ultimo=None):
    """Quante fiches metti con la mossa: quello che scrivi tu, altrimenti un valore ragionevole."""
    if tipo in ("fold", "check"):
        return 0.0
    if tipo == "call":
        return min(s.to_call, s.stack)
    if tipo == "allin":
        return s.stack
    if quanto:
        return min(quanto, s.stack)
    if ultimo and ultimo[0] == _chiave(s) and ultimo[1].amount and ultimo[1].action in ("BET", "RAISE"):
        return ultimo[1].amount
    return min(s.stack, round(3 * s.to_call if s.to_call else 0.66 * s.pot, 2) or 2.5 * s.bb)


def carte_belle(testo):
    try:
        out = []
        for c in parse_cards(testo):
            from treys import Card
            s = Card.int_to_str(c)
            out.append(s[0].replace("T", "10") + SEMI[s[1]])
        return "  ".join(out)
    except Exception:
        return ""


class App:
    def __init__(self, root, cfg_path=annotazioni.CONFIG, iters=2500, db="storia.db"):
        import tkinter as tk
        self.tk, self.root, self.cfg_path, self.iters = tk, root, cfg_path, iters
        self.cfg = annotazioni.carica_config(cfg_path)
        from .partita import carica
        self.partita = carica()
        root.title("Poker Coach")
        root.configure(bg=COL["bg"])
        self._posiziona()
        self.campo_attivo = None
        self.st, self.db = None, db
        self.mano_id, self.mano_carte, self.ultimo = None, None, None
        self.v = {k: tk.StringVar() for k in ("carte", "tavolo", "piatto", "pagare", "stack", "bb", "ante",
                                               "giocatori", "posizione", "avversari", "rilancia", "tipo")}
        p = self.partita
        self.v["giocatori"].set(str(p.giocatori if p else 6))
        self.v["bb"].set(f"{p.bb:g}" if p else "20")
        self.v["ante"].set(f"{p.ante:g}" if p else "0")
        self.v["avversari"].set("1")
        self.v["posizione"].set("BTN")
        self._costruisci()
        self._aggiorna_modo()
        root.bind("<Return>", lambda e: self.consiglia())
        self._lettore, self._ultima_lettura = None, None
        root.after(1500, self._ciclo_lettore)

    # ------------------------------------------------------------ layout
    def _posiziona(self):
        sw, sh = self.root.winfo_screenwidth(), self.root.winfo_screenheight()
        w = max(340, sw // 4)
        h = max(600, sh - 80)
        self.root.geometry(f"{w}x{h}+{sw - w}+0")
        self.root.minsize(320, 560)

    def _lab(self, parent, testo, col="mut", size=10, bold=False, **kw):
        return self.tk.Label(parent, text=testo, bg=kw.pop("bg", COL["bg"]), fg=COL[col],
                             font=("Segoe UI", size, "bold" if bold else "normal"), **kw)

    def _bottone(self, parent, testo, cmd, col="txt", bg=None, **kw):
        return self.tk.Button(parent, text=testo, command=cmd, bg=bg or COL["card"], fg=COL[col],
                              activebackground="#2a3446", activeforeground="#ffffff", relief="flat",
                              bd=0, highlightthickness=0, cursor="hand2", font=("Segoe UI", 10, "bold"), **kw)

    def _campo(self, parent, var, larghezza=8):
        e = self.tk.Entry(parent, textvariable=var, width=larghezza, bg=COL["campo"], fg=COL["txt"],
                          insertbackground=COL["txt"], relief="flat", font=("Segoe UI", 11),
                          highlightthickness=1, highlightbackground=COL["bordo"], highlightcolor=COL["blu"])
        e.bind("<FocusIn>", lambda ev: setattr(self, "campo_attivo", var))
        return e

    def _costruisci(self):
        tk, r = self.tk, self.root
        top = tk.Frame(r, bg=COL["bg"])
        top.pack(fill="x", padx=14, pady=(12, 4))
        self._lab(top, "Poker Coach", "txt", 15, True).pack(side="left")
        self.badge = self._lab(top, "", "verde", 9, True, padx=8, pady=2)
        self.badge.pack(side="right")
        self.riassunto = self._lab(r, self.partita.riassunto() if self.partita else
                                   "Nessuna partita impostata (PARTITA.bat)", wraplength=320, justify="left")
        self.riassunto.pack(fill="x", padx=14)

        # interruttore modalità
        sw = tk.Frame(r, bg=COL["bg"])
        sw.pack(fill="x", padx=14, pady=8)
        self.b_ripasso = self._bottone(sw, "Ripasso", lambda: self.imposta_vivo(False))
        self.b_vivo = self._bottone(sw, "Dal vivo", lambda: self.imposta_vivo(True))
        self.b_ripasso.pack(side="left", expand=True, fill="x", ipady=6)
        self.b_vivo.pack(side="left", expand=True, fill="x", ipady=6, padx=(6, 0))

        self._lab(r, "Lettura automatica del tavolo: in arrivo (dopo le foto della modalità impara).",
                  "mut", 8, wraplength=320, justify="left").pack(side="bottom", fill="x", padx=14, pady=6)
        self.stato_lbl = self._lab(r, "", "mut", 9, wraplength=320, justify="left")
        self.stato_lbl.pack(side="bottom", fill="x", padx=14)
        fp = tk.Frame(r, bg=COL["bg"])
        fp.pack(side="bottom", fill="x", padx=14, pady=(4, 2))
        self._bottone(fp, "Fine partita  →  ripasso con grafici", self.fine_partita, col="blu") \
            .pack(fill="x", ipady=6)

        # parte centrale scorrevole (schermi bassi)
        contenitore = tk.Frame(r, bg=COL["bg"])
        contenitore.pack(fill="both", expand=True, padx=(14, 4))
        self.canvas = tk.Canvas(contenitore, bg=COL["bg"], highlightthickness=0, bd=0)
        barra = tk.Scrollbar(contenitore, orient="vertical", command=self.canvas.yview)
        self.canvas.configure(yscrollcommand=barra.set)
        barra.pack(side="right", fill="y")
        self.canvas.pack(side="left", fill="both", expand=True)
        self.corpo = tk.Frame(self.canvas, bg=COL["bg"])
        finestra_id = self.canvas.create_window((0, 0), window=self.corpo, anchor="nw")
        self.corpo.bind("<Configure>", lambda e: self.canvas.configure(scrollregion=self.canvas.bbox("all")))
        self.canvas.bind("<Configure>", lambda e: self.canvas.itemconfigure(finestra_id, width=e.width - 6))
        self.canvas.bind_all("<MouseWheel>", lambda e: self.canvas.yview_scroll(int(-e.delta / 120), "units"))
        self.msg_modo = self._lab(self.corpo, "", "mut", 9, wraplength=300, justify="left")
        self.msg_modo.pack(fill="x", pady=(0, 4))
        self.pannello = tk.Frame(self.corpo, bg=COL["bg"])
        self.pannello.pack(fill="both", expand=True)

        # carte
        g = tk.Frame(self.pannello, bg=COL["bg"])
        g.pack(fill="x")
        for i, (chiave, nome) in enumerate((("carte", "Le tue carte"), ("tavolo", "Sul tavolo"))):
            self._lab(g, nome).grid(row=i * 2, column=0, sticky="w", pady=(4, 0))
            e = self._campo(g, self.v[chiave], 16)
            e.grid(row=i * 2 + 1, column=0, sticky="we")
            vis = self._lab(g, "", "oro", 12, True)
            vis.grid(row=i * 2 + 1, column=1, sticky="w", padx=8)
            self.v[chiave].trace_add("write", lambda *a, k=chiave, l=vis: l.config(text=carte_belle(self.v[k].get())))
            if chiave == "carte":
                e.focus_set()
                self.campo_attivo = self.v["carte"]

        # tastierina carte: due righe da 9 (valori, poi semi e cancella)
        kb = tk.Frame(self.pannello, bg=COL["bg"])
        kb.pack(fill="x", pady=4)
        tasti = [("10" if r_ == "T" else r_, lambda x=r_: self._tasto(x), "txt") for r_ in "AKQJT98765432"]
        tasti += [(simb, lambda x=l: self._tasto(x, seme=True), "rosso" if l in "hd" else "txt") for l, simb in SEMI.items()]
        tasti.append(("⌫", self._cancella_ultima, "mut"))
        for i, (testo, cmd, col) in enumerate(tasti):
            self._bottone(kb, testo, cmd, col=col, width=2).grid(row=i // 9, column=i % 9, padx=1, pady=1,
                                                                  sticky="we", ipady=2)
        for c in range(9):
            kb.grid_columnconfigure(c, weight=1, uniform="tasti")

        # numeri
        n = tk.Frame(self.pannello, bg=COL["bg"])
        n.pack(fill="x", pady=4)
        righe = [("piatto", "Piatto"), ("pagare", "Da pagare"), ("stack", "Tuo stack"), ("avversari", "Avversari"),
                 ("bb", "Big blind"), ("ante", "Ante"), ("giocatori", "Giocatori")]
        for i, (k, nome) in enumerate(righe):  # quattro colonne per stare anche su schermi bassi
            self._lab(n, nome, size=9).grid(row=i // 4 * 2, column=i % 4, sticky="w", padx=(0, 4))
            self._campo(n, self.v[k], 5).grid(row=i // 4 * 2 + 1, column=i % 4, sticky="we", padx=(0, 4))
        for c in range(4):
            n.grid_columnconfigure(c, weight=1, uniform="num")
        self.v["giocatori"].trace_add("write", lambda *a: self._aggiorna_posizioni())
        pz = tk.Frame(self.pannello, bg=COL["bg"])
        pz.pack(fill="x")
        self._lab(pz, "Posizione").grid(row=0, column=0, sticky="w")
        self._lab(pz, "Chi ha rilanciato").grid(row=0, column=1, sticky="w", padx=6)
        self._lab(pz, "Tipo avversario").grid(row=0, column=2, sticky="w")
        self.m_pos = tk.OptionMenu(pz, self.v["posizione"], "BTN")
        self.m_ril = tk.OptionMenu(pz, self.v["rilancia"], "-")
        self.m_tipo = tk.OptionMenu(pz, self.v["tipo"], "-", *TIPI)
        for i, m in enumerate((self.m_pos, self.m_ril, self.m_tipo)):
            m.config(bg=COL["card"], fg=COL["txt"], relief="flat", highlightthickness=0,
                     activebackground="#2a3446", activeforeground="#ffffff")
            m.grid(row=1, column=i, sticky="we", padx=(0 if i == 0 else 6, 0))
        self.v["rilancia"].set("-")
        self.v["tipo"].set("-")
        self._aggiorna_posizioni()

        # consiglio (solo DAL VIVO)
        self.zona_consiglio = tk.Frame(self.pannello, bg=COL["bg"])
        self._bottone(self.zona_consiglio, "Consiglia  (Invio)", self.consiglia, col="oro", bg="#2a1f0b") \
            .pack(fill="x", ipady=6, pady=(8, 6))
        box = tk.Frame(self.zona_consiglio, bg=COL["card"])
        box.pack(fill="x")
        self.l_azione = self._lab(box, "—", "txt", 22, True, bg=COL["card"])
        self.l_azione.pack(anchor="w", padx=12, pady=(8, 0))
        self.l_numeri = self._lab(box, "", "mut", 9, bg=COL["card"])
        self.l_numeri.pack(anchor="w", padx=12)
        self.l_motivi = self._lab(box, "", "txt", 9, bg=COL["card"], wraplength=290, justify="left")
        self.l_motivi.pack(anchor="w", padx=12, pady=(4, 8))
        self.zona_consiglio.pack(fill="x")

        # diario della mano (sempre): cosa hai fatto, risultato
        d = tk.Frame(self.pannello, bg=COL["bg"])
        d.pack(fill="x", pady=(10, 0))
        self._lab(d, "Ho fatto:", "txt", 9, True).pack(anchor="w")
        tasti = tk.Frame(d, bg=COL["bg"])
        tasti.pack(fill="x", pady=2)
        for i, (nome, tipo) in enumerate((("Fold", "fold"), ("Check", "check"), ("Call", "call"),
                                          ("Rilancia", "aggressivo"), ("All-in", "allin"))):
            self._bottone(tasti, nome, lambda t=tipo: self.ho_fatto(t)).grid(
                row=0, column=i, sticky="we", padx=1, ipady=4)
            tasti.grid_columnconfigure(i, weight=1, uniform="ho")
        rr = tk.Frame(d, bg=COL["bg"])
        rr.pack(fill="x", pady=(4, 0))
        self.v["quanto"] = self.tk.StringVar()
        self.v["risultato"] = self.tk.StringVar()
        self._lab(rr, "Quanto", size=9).grid(row=0, column=0, sticky="w")
        self._lab(rr, "Risultato ±", size=9).grid(row=0, column=1, sticky="w", padx=6)
        self._campo(rr, self.v["quanto"], 7).grid(row=1, column=0, sticky="we")
        self._campo(rr, self.v["risultato"], 9).grid(row=1, column=1, sticky="we", padx=6)
        self._bottone(rr, "Fine mano", self.fine_mano).grid(row=1, column=2, sticky="we", ipady=3)
        for c in range(3):
            rr.grid_columnconfigure(c, weight=1, uniform="rr")

    # ------------------------------------------------------------ azioni
    def _tasto(self, x, seme=False):
        var = self.campo_attivo or self.v["carte"]
        t = var.get()
        if seme:
            var.set(t + x + " ")
        else:
            var.set(t + x)

    def _cancella_ultima(self):
        var = self.campo_attivo or self.v["carte"]
        var.set(var.get().rstrip()[:-1])

    def _aggiorna_posizioni(self):
        try:
            n = int(self.v["giocatori"].get())
            pos = tavolo.posizioni(n)
        except Exception:
            return
        for menu, scelte, var in ((self.m_pos, pos, self.v["posizione"]), (self.m_ril, ["-"] + pos[:-1], self.v["rilancia"])):
            m = menu["menu"]
            m.delete(0, "end")
            for p in scelte:
                m.add_command(label=p, command=lambda x=p, v=var: v.set(x))
            if var.get() not in scelte:
                var.set(scelte[-2] if var is self.v["posizione"] and len(scelte) > 1 else scelte[0])

    def nuova_mano(self):
        for k in ("carte", "tavolo", "piatto", "pagare", "quanto", "risultato"):
            self.v[k].set("")
        self.v["rilancia"].set("-")
        self.campo_attivo = self.v["carte"]
        self.l_azione.config(text="—", fg=COL["txt"])
        self.l_numeri.config(text="")
        self.l_motivi.config(text="")
        self.ultimo = None

    # ------------------------------------------------------------ diario
    def _stato(self, testo, col="mut"):
        self.stato_lbl.config(text=testo, fg=COL[col])

    def _storia(self):
        if self.st is None:
            from .storia import Storia
            self.st = Storia(self.db)
        return self.st

    def _partita_corrente(self):
        pid = self.cfg.get("diario_partita")
        if not pid:
            pid = self._storia().nuova_partita(gioco="finestra del coach",
                                               note="dal vivo" if self.cfg.get("dal_vivo") else "ripasso")
            self.cfg["diario_partita"] = pid
            annotazioni.salva_config(self.cfg, self.cfg_path)
        return pid

    def ho_fatto(self, tipo):
        try:
            s = costruisci_situazione(self.campi())
        except ValueError as e:
            self._stato("Non salvato: " + str(e), "rosso")
            return None
        try:
            quanto = _num(self.v["quanto"].get(), "Quanto")
        except ValueError as e:
            self._stato("Non salvato: " + str(e), "rosso")
            return None
        if tipo == "aggressivo":
            tipo = "raise" if s.to_call > 0 else "bet"
        importo = importo_mossa(tipo, s, quanto, self.ultimo)
        st = self._storia()
        pid = self._partita_corrente()
        carte = " ".join(self.v["carte"].get().split())
        if self.mano_id is not None and carte != self.mano_carte:
            st.fine_mano(self.mano_id, {"Io": 0})  # carte nuove senza «Fine mano»: chiudo la vecchia a 0
            self._stato("La mano precedente era senza risultato: salvata a 0.", "oro")
            self.mano_id = None
        if self.mano_id is None:
            self.mano_id = st.nuova_mano(
                pid, s.bb, [{"nome": "Io", "eroe": True, "carte": carte, "posizione": s.position,
                             "stack": s.stack}], n_giocatori=s.n_players, ante=s.ante,
                modalita="vivo" if self.cfg.get("dal_vivo") else "ripasso")
            self.mano_carte = carte
        st.board(self.mano_id, " ".join(self.v["tavolo"].get().split()))
        strada = {0: "preflop", 3: "flop", 4: "turn", 5: "river"}[len(s.board)]
        visto = None
        if self.cfg.get("dal_vivo") and self.ultimo and self.ultimo[0] == _chiave(s):
            a = self.ultimo[1]
            visto = a.action + (f" {a.amount:g}" if a.amount else "")
        st.azione(self.mano_id, "Io", strada, tipo, importo, da_pagare=s.to_call, piatto=s.pot,
                  avversari=s.n_opp, consiglio=visto)
        self._stato(f"Salvato: {strada} {tipo.upper()}" + (f" {importo:g}" if importo else ""), "verde")
        for k in ("pagare", "quanto"):
            self.v[k].set("")
        return tipo, importo

    def fine_mano(self):
        if self.mano_id is None:
            self._stato("Nessuna mossa salvata in questa mano.", "oro")
            self.nuova_mano()
            return None
        try:
            r = _num(self.v["risultato"].get().replace("+", ""), "Risultato", minimo=-1e12)
        except ValueError:
            self._stato("Risultato: scrivi quanto hai vinto (es. 60) o perso (es. -40), anche 0.", "rosso")
            return None
        self._storia().fine_mano(self.mano_id, {"Io": r})
        self.mano_id, self.mano_carte = None, None
        self.nuova_mano()
        self._stato(f"Mano salvata ({r:+g}).", "verde")
        return r

    def fine_partita(self, conferma=True, apri=True):
        pid = self.cfg.get("diario_partita")
        if not pid:
            self._stato("Non hai ancora salvato nessuna mano in questa partita.", "oro")
            return None
        if conferma:
            from tkinter import messagebox
            if not messagebox.askyesno("Fine partita", "Chiudere la partita e aprire il ripasso con i grafici?",
                                       parent=self.root):
                return None
        if self.mano_id is not None:
            if not self.v["risultato"].get().strip():
                self.v["risultato"].set("0")
            self.fine_mano()
        from .ripasso import analizza, salva_pagina
        analisi = analizza(self._storia(), pid)
        path = salva_pagina(analisi, "ripassi")
        self.cfg["diario_partita"] = None  # la prossima mano apre una partita nuova
        annotazioni.salva_config(self.cfg, self.cfg_path)
        self._stato(f"Ripasso pronto: {path}", "verde")
        if apri:
            import os
            import webbrowser
            webbrowser.open("file:///" + os.path.abspath(path).replace(os.sep, "/"))
        return path

    def imposta_vivo(self, vivo, chiedi=True):
        if vivo and not self.cfg.get("dal_vivo"):
            if chiedi:
                from tkinter import messagebox
                if not messagebox.askyesno("Modalità dal vivo", CONFERMA_VIVO, parent=self.root):
                    return
        self.cfg["dal_vivo"] = bool(vivo)
        annotazioni.salva_config(self.cfg, self.cfg_path)
        self._aggiorna_modo()

    def _aggiorna_modo(self):
        vivo = bool(self.cfg.get("dal_vivo"))
        self.b_vivo.config(bg="#2a3446" if vivo else COL["card"], fg="#ffffff" if vivo else COL["mut"])
        self.b_ripasso.config(bg="#2a3446" if not vivo else COL["card"], fg="#ffffff" if not vivo else COL["mut"])
        self.badge.config(text="● DAL VIVO" if vivo else "RIPASSO", fg=COL["oro"] if vivo else COL["verde"],
                          bg="#2a1f0b" if vivo else "#12301f")
        if vivo:
            self.msg_modo.config(text="Scrivi la mano · INVIO = consiglio · poi «Ho fatto».")
            self.zona_consiglio.pack(fill="x", before=self.zona_consiglio.master.winfo_children()[-1])
        else:
            self.msg_modo.config(text="Nessun consiglio mentre giochi: annota le mosse, "
                                      "a fine partita vedrai cosa avrebbe fatto il coach.")
            self.zona_consiglio.pack_forget()

    # ------------------------------------------------------------ lettore automatico (spento di default)
    def _ciclo_lettore(self):
        try:
            self.passo_lettore()
        except Exception as e:  # il lettore non deve mai bloccare la finestra
            self._stato(f"Lettore: errore ({e})", "rosso")
        self.root.after(1000, self._ciclo_lettore)

    def passo_lettore(self, img=None, titolo=None):
        """Una lettura: se il lettore è acceso e sei «Dal vivo», riempie i campi. Ritorna la lettura."""
        cfg = annotazioni.carica_config(self.cfg_path)
        if not cfg.get("lettore_attivo") or not self.cfg.get("dal_vivo"):
            return None
        from . import lettore, schermo
        from .calibra import carica as carica_cal
        if self._lettore is None:
            cal = carica_cal()
            if not cal:
                self._stato("Lettore acceso ma manca la calibrazione (CALIBRA.bat).", "oro")
                return None
            self._lettore = lettore.Lettore(cal)
        if img is None:
            f = schermo.trova(self._lettore.cal.titolo[:40]) if self._lettore.cal.titolo else None
            if f is None or f.minimizzata:
                self._stato("Lettore: non trovo la finestra del gioco calibrata.", "oro")
                return None
            titolo = f.titolo
            if lettore.bloccato(titolo):
                self._stato("Lettore fermo: sito a soldi veri.", "oro")
                return None
            from PIL import Image
            with schermo._MSS() as sct:
                g = sct.grab(f.area)
                img = Image.frombytes("RGB", g.size, g.rgb)
        l = self._lettore.leggi(img, titolo or "")
        if l.bloccata or l.avvisi:
            self._stato("Lettore: " + " ".join(l.avvisi), "oro")
            return l
        if not l.sicura():
            self._stato("Lettore: non sono sicuro di " + ", ".join(z.replace("_", " ") for z in l.incerti[:4]) +
                        " (LETTORE.bat per insegnarmelo)", "oro")
            return l
        campi = lettore.situazione_da_lettura(l, None, self._lettore.cal.n_posti)
        cambiato = campi != self._ultima_lettura
        if cambiato:
            for k, v in campi.items():
                if k in self.v:
                    self.v[k].set(v)
            self._ultima_lettura = campi
        self._stato("Lettore: letto ✓" + (" · tocca a te" if l.tocca_a_me else ""), "verde")
        if cambiato and l.tocca_a_me and len(l.mie_carte) == 2:
            self.consiglia()
        return l

    def campi(self):
        c = {k: v.get() for k, v in self.v.items()}
        c["rilancia"] = "" if c["rilancia"] == "-" else c["rilancia"]
        c["tipo"] = "" if c["tipo"] == "-" else c["tipo"]
        return c

    def consiglia(self, attendi=False):
        if not self.cfg.get("dal_vivo"):
            return None  # in ripasso non si consiglia mai durante il gioco
        try:
            s = costruisci_situazione(self.campi())
        except ValueError as e:
            self.l_azione.config(text="Controlla i dati", fg=COL["rosso"])
            self.l_numeri.config(text="")
            self.l_motivi.config(text=str(e))
            return None
        self.l_azione.config(text="…", fg=COL["mut"])
        risultato = {}

        def lavora():
            try:
                risultato["a"] = advise(s, self.iters)
            except Exception as e:
                risultato["e"] = str(e)

        def mostra():
            if "e" in risultato:
                self.l_azione.config(text="Errore", fg=COL["rosso"])
                self.l_motivi.config(text=risultato["e"])
                return
            a = risultato["a"]
            self.ultimo = (_chiave(s), a)
            self.l_azione.config(text=a.action + (f" {a.amount:g}" if a.amount else ""),
                                 fg=COLORE_AZIONE.get(a.action, COL["txt"]))
            extra = f"  ·  out {a.outs}" if a.outs else ""
            self.l_numeri.config(text=f"vincita {a.equity:.0%}  ·  pot odds {a.pot_odds:.0%}  ·  "
                                      f"{s.stack / s.bb:.0f} bb{extra}")
            self.l_motivi.config(text="\n".join("• " + m for m in a.reasons))

        # il calcolo gira in sottofondo; la finestra la aggiorna SOLO il thread principale
        t = threading.Thread(target=lavora, daemon=True)
        t.start()

        def controlla():
            if t.is_alive():
                self.root.after(40, controlla)
            else:
                mostra()

        if attendi:
            t.join()
            mostra()
            self.root.update()
        else:
            self.root.after(40, controlla)
        return s


def main():
    import tkinter as tk
    root = tk.Tk()
    App(root)
    root.mainloop()


if __name__ == "__main__":
    main()
