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
    def __init__(self, root, cfg_path=annotazioni.CONFIG, iters=2500):
        import tkinter as tk
        self.tk, self.root, self.cfg_path, self.iters = tk, root, cfg_path, iters
        self.cfg = annotazioni.carica_config(cfg_path)
        from .partita import carica
        self.partita = carica()
        root.title("Poker Coach")
        root.configure(bg=COL["bg"])
        self._posiziona()
        self.campo_attivo = None
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
        self.corpo = tk.Frame(r, bg=COL["bg"])
        self.corpo.pack(fill="both", expand=True, padx=14)
        self.msg_ripasso = self._lab(self.corpo, "Modalità RIPASSO: nessun suggerimento durante il gioco.\n\n"
                                     "Registra la partita con ANNOTAZIONI.bat e rivedila a gioco chiuso.\n\n"
                                     "Per le partite contro il computer puoi passare a «Dal vivo».",
                                     "mut", 11, wraplength=320, justify="left")
        self.pannello = tk.Frame(self.corpo, bg=COL["bg"])

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

        # tastierina carte
        kb = tk.Frame(self.pannello, bg=COL["bg"])
        kb.pack(fill="x", pady=6)
        for i, rango in enumerate("AKQJT98765432"):  # due righe: 7 + 6 tasti
            b = self._bottone(kb, "10" if rango == "T" else rango, lambda x=rango: self._tasto(x), width=2)
            b.grid(row=i // 7, column=i % 7, padx=1, pady=1, sticky="we", ipady=3)
        for c in range(7):
            kb.grid_columnconfigure(c, weight=1, uniform="tasti")
        semi = tk.Frame(self.pannello, bg=COL["bg"])
        semi.pack(fill="x")
        for i, (lettera, simbolo) in enumerate(SEMI.items()):
            col = "rosso" if lettera in "hd" else "txt"
            self._bottone(semi, simbolo, lambda x=lettera: self._tasto(x, seme=True), col=col, width=3) \
                .pack(side="left", padx=1, expand=True, fill="x", ipady=3)
        self._bottone(semi, "⌫", self._cancella_ultima, width=3).pack(side="left", padx=1, expand=True, fill="x")

        # numeri
        n = tk.Frame(self.pannello, bg=COL["bg"])
        n.pack(fill="x", pady=4)
        righe = [("piatto", "Piatto"), ("pagare", "Da pagare"), ("stack", "Il tuo stack"),
                 ("bb", "Big blind"), ("ante", "Ante"), ("giocatori", "Giocatori"),
                 ("avversari", "Avversari")]
        for i, (k, nome) in enumerate(righe):  # tre colonne per stare anche su schermi bassi
            self._lab(n, nome, size=9).grid(row=i // 3 * 2, column=i % 3, sticky="w", padx=(0, 6))
            self._campo(n, self.v[k], 7).grid(row=i // 3 * 2 + 1, column=i % 3, sticky="we", padx=(0, 6))
        for c in range(3):
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

        az = tk.Frame(self.pannello, bg=COL["bg"])
        az.pack(fill="x", pady=8)
        self._bottone(az, "Consiglia  (Invio)", self.consiglia, col="oro", bg="#2a1f0b") \
            .pack(side="left", expand=True, fill="x", ipady=8)
        self._bottone(az, "Nuova mano", self.nuova_mano).pack(side="left", padx=(6, 0), ipady=8, ipadx=6)

        # consiglio
        box = tk.Frame(self.pannello, bg=COL["card"])
        box.pack(fill="both", expand=True, pady=(0, 8))
        self.l_azione = self._lab(box, "—", "txt", 24, True, bg=COL["card"])
        self.l_azione.pack(anchor="w", padx=12, pady=(10, 0))
        self.l_numeri = self._lab(box, "", "mut", 10, bg=COL["card"])
        self.l_numeri.pack(anchor="w", padx=12)
        self.l_motivi = self._lab(box, "", "txt", 10, bg=COL["card"], wraplength=300, justify="left")
        self.l_motivi.pack(anchor="w", padx=12, pady=(6, 10))
        self.l_motivi.bind("<Configure>", lambda e: self.l_motivi.config(wraplength=max(200, e.width - 4)))


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
        for k in ("carte", "tavolo", "piatto", "pagare"):
            self.v[k].set("")
        self.v["rilancia"].set("-")
        self.campo_attivo = self.v["carte"]
        self.l_azione.config(text="—", fg=COL["txt"])
        self.l_numeri.config(text="")
        self.l_motivi.config(text="")

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
            self.msg_ripasso.pack_forget()
            self.pannello.pack(fill="both", expand=True)
        else:
            self.pannello.pack_forget()
            self.msg_ripasso.pack(fill="x", pady=10)

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
