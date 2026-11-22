"""Calibrazione: disegni tu, una volta sola, dove stanno le cose nella finestra del poker.

Su una foto del gioco trascini il mouse per fare un rettangolo intorno a: le tue carte,
le carte sul tavolo, il piatto, ogni posto (nome + stack), le puntate, il bottone, i tuoi
tasti di gioco, i bui. Quello che non c'è lo salti.

Le zone sono salvate in proporzione alla finestra (0..1), così valgono anche se la
ridimensioni. Il lettore del tavolo userà queste zone per sapere dove guardare.

    py -m coach.calibra
"""
import json
import os
import time

FILE = "calibrazione.json"
CARTELLA = "calibrazione"

COLORI = {"carte": "#ffd27a", "tavolo": "#ffd27a", "piatto": "#7cc4ff", "posto": "#9ff0c4",
          "puntata": "#e87ba4", "bottone": "#ff9b9b", "tasti": "#9085e9", "bui": "#7cc4ff"}


def passi(n_posti):
    """Le zone da disegnare, in ordine: (chiave, spiegazione, obbligatoria, tipo per il colore)."""
    p = [("mia_carta_1", "L'ANGOLO della tua PRIMA carta: solo valore e seme (es. «A» e «♦»)", True, "carte"),
         ("mia_carta_2", "L'ANGOLO della tua SECONDA carta: solo valore e seme", True, "carte"),
         ("tavolo_1", "L'ANGOLO della 1ª carta sul tavolo (usa una foto con le carte del flop)", True, "tavolo"),
         ("tavolo_2", "L'ANGOLO della 2ª carta sul tavolo", True, "tavolo"),
         ("tavolo_3", "L'ANGOLO della 3ª carta sul tavolo (se salti, la ricavo dalle prime due)", False, "tavolo"),
         ("tavolo_4", "L'ANGOLO della 4ª carta (turn), se c'è", False, "tavolo"),
         ("tavolo_5", "L'ANGOLO della 5ª carta (river), se c'è", False, "tavolo"),
         ("piatto", "Il NUMERO del piatto (stretto intorno alle cifre)", True, "piatto"),
         ("posto_1", "Le TUE fiches: il NUMERO del tuo stack", True, "posto"),
         ("puntata_1", "Dove compare il numero della TUA puntata", False, "puntata")]
    for k in range(2, n_posti + 1):
        p.append((f"posto_{k}", f"Posto {k} (in senso orario dopo il tuo): il NUMERO delle sue fiches", True, "posto"))
        p.append((f"puntata_{k}", f"Dove compare il numero della puntata del posto {k}", False, "puntata"))
    p += [("bottone", "Il gettone del BOTTONE (la «D»): stretto intorno al gettone", False, "bottone"),
          ("tasti", "La zona dove compaiono i TUOI tasti (Fold / Call / Raise)", False, "tasti"),
          ("bui", "Dove è scritto il livello dei BUI, se c'è", False, "bui")]
    return p


class Calibrazione:
    def __init__(self, larghezza, altezza, n_posti=6, titolo=""):
        self.w, self.h, self.n_posti, self.titolo = larghezza, altezza, n_posti, titolo
        self.zone = {}  # chiave -> [x, y, w, h] in frazioni della finestra (0..1)

    def imposta(self, chiave, x0, y0, x1, y1):
        """Rettangolo in pixel dell'immagine originale (anche disegnato «al contrario»)."""
        x0, x1 = sorted((max(0, min(x0, self.w)), max(0, min(x1, self.w))))
        y0, y1 = sorted((max(0, min(y0, self.h)), max(0, min(y1, self.h))))
        if x1 - x0 < 4 or y1 - y0 < 4:
            raise ValueError("rettangolo troppo piccolo: trascina il mouse")
        self.zone[chiave] = [round(x0 / self.w, 5), round(y0 / self.h, 5),
                             round((x1 - x0) / self.w, 5), round((y1 - y0) / self.h, 5)]

    def togli(self, chiave):
        self.zone.pop(chiave, None)

    def pixel(self, chiave, larghezza=None, altezza=None):
        """La zona in pixel per una finestra grande larghezza x altezza (di default quella calibrata)."""
        W, H = larghezza or self.w, altezza or self.h
        x, y, w, h = self.zone[chiave]
        return round(x * W), round(y * H), round(w * W), round(h * H)

    def mancanti(self):
        return [k for k, _, obbl, _ in passi(self.n_posti) if obbl and k not in self.zone]

    def to_dict(self):
        return {"versione": 1, "creata": time.strftime("%Y-%m-%d %H:%M"), "titolo": self.titolo,
                "finestra": {"w": self.w, "h": self.h}, "posti": self.n_posti, "mio_posto": 1,
                "zone": self.zone}

    @classmethod
    def from_dict(cls, d):
        c = cls(d["finestra"]["w"], d["finestra"]["h"], d.get("posti", 6), d.get("titolo", ""))
        c.zone = {k: list(v) for k, v in d.get("zone", {}).items()}
        return c

    def salva(self, immagine=None, file=FILE, cartella=CARTELLA):
        """Salva il json e, se c'è l'immagine (PIL), l'originale, l'anteprima con i rettangoli e i ritagli."""
        with open(file, "w", encoding="utf-8") as f:
            json.dump(self.to_dict(), f, ensure_ascii=False, indent=1)
        if immagine is None:
            return file
        from PIL import ImageDraw
        os.makedirs(os.path.join(cartella, "zone"), exist_ok=True)
        img = immagine.convert("RGB")
        img.save(os.path.join(cartella, "originale.png"))
        prev = img.copy()
        d = ImageDraw.Draw(prev)
        tipo = {k: t for k, _, _, t in passi(self.n_posti)}
        for k in self.zone:
            x, y, w, h = self.pixel(k, *img.size)
            col = COLORI.get(tipo.get(k, ""), "#ffffff")
            d.rectangle([x, y, x + w, y + h], outline=col, width=3)
            d.text((x + 4, y + 2), k.replace("_", " "), fill=col)
            img.crop((x, y, x + w, y + h)).save(os.path.join(cartella, "zone", f"{k}.png"))
        prev.save(os.path.join(cartella, "anteprima.png"))
        return file


def carica(file=FILE):
    if not os.path.exists(file):
        return None
    with open(file, encoding="utf-8") as f:
        return Calibrazione.from_dict(json.load(f))


# ------------------------------------------------------------------ finestra di calibrazione
class App:
    def __init__(self, root, n_posti=None):
        import tkinter as tk
        self.tk, self.root = tk, root
        root.title("Calibrazione - disegna la finestra del poker")
        root.configure(bg="#12151b")
        self.img = None
        self.cal = None
        self.i = 0
        self.inizio = None
        self.rett_corrente = None
        from .partita import carica as carica_partita
        p = carica_partita()
        self.n_posti = n_posti or (p.posti if p else 6)
        self._schermata_scelta()

    # --------------------------------------------- 1) scegli la foto
    def _pulisci(self):
        for w in self.root.winfo_children():
            w.destroy()

    def _lab(self, parent, testo, size=11, bold=False, col="#e8ebf0", **kw):
        return self.tk.Label(parent, text=testo, bg="#12151b", fg=col,
                             font=("Segoe UI", size, "bold" if bold else "normal"), **kw)

    def _btn(self, parent, testo, cmd, col="#e8ebf0", bg="#1a1e26"):
        return self.tk.Button(parent, text=testo, command=cmd, bg=bg, fg=col, relief="flat", bd=0,
                              activebackground="#2a3446", activeforeground="#ffffff", cursor="hand2",
                              font=("Segoe UI", 10, "bold"), padx=10, pady=6)

    def _schermata_scelta(self):
        from . import schermo
        tk = self.tk
        self._pulisci()
        self.root.geometry("640x520")
        f = tk.Frame(self.root, bg="#12151b")
        f.pack(fill="both", expand=True, padx=16, pady=16)
        self._lab(f, "Calibrazione del tavolo", 16, True).pack(anchor="w")
        self._lab(f, "1) Apri il gioco in una finestra con un tavolo in corso (meglio dopo il flop).\n"
                     "2) Scegli qui sotto la sua finestra e premi «Fotografa».\n"
                     "   (oppure usa una foto già registrata con «Apri una foto»)",
                  10, col="#a3acb9", justify="left").pack(anchor="w", pady=(4, 10))
        riga = tk.Frame(f, bg="#12151b")
        riga.pack(fill="x", pady=(0, 8))
        self._lab(riga, "Posti al tavolo:", 10).pack(side="left")
        self.v_posti = tk.StringVar(value=str(self.n_posti))
        tk.Spinbox(riga, from_=2, to=10, width=4, textvariable=self.v_posti, font=("Segoe UI", 11)).pack(side="left", padx=6)
        self.lista = tk.Listbox(f, bg="#0f1216", fg="#e8ebf0", selectbackground="#2a3446", relief="flat",
                                font=("Segoe UI", 10), height=12)
        self.lista.pack(fill="both", expand=True)
        self.finestre = sorted(schermo.elenca_finestre(), key=lambda x: -x.w * x.h)
        for x in self.finestre:
            self.lista.insert("end", str(x))
        b = tk.Frame(f, bg="#12151b")
        b.pack(fill="x", pady=(10, 0))
        self._btn(b, "Fotografa la finestra scelta", self._fotografa, col="#ffd27a", bg="#2a1f0b").pack(side="left")
        self._btn(b, "Apri una foto…", self._apri_foto).pack(side="left", padx=8)
        self.msg = self._lab(f, "", 10, col="#ff9b9b")
        self.msg.pack(anchor="w", pady=(6, 0))

    def _fotografa(self):
        from . import schermo
        sel = self.lista.curselection()
        if not sel:
            self.msg.config(text="Scegli prima una finestra dall'elenco.")
            return
        fin = self.finestre[sel[0]]
        from .lettore import bloccato
        if bloccato(fin.titolo) and not getattr(self, "_avviso_soldi", False):
            self._avviso_soldi = True
            self.msg.config(text="Attenzione: è un sito a soldi veri. Su questa finestra il lettore automatico "
                                 "NON leggerà mai. Premi di nuovo per fotografarla lo stesso.")
            return
        if fin.minimizzata:
            self.msg.config(text="Quella finestra è ridotta a icona: aprila e riprova.")
            return
        self.root.withdraw()  # tolgo di mezzo questa finestra per non fotografarla
        self.root.update()
        time.sleep(0.4)
        from PIL import Image
        with schermo._MSS() as sct:
            g = sct.grab(fin.area)
            img = Image.frombytes("RGB", g.size, g.rgb)
        self.root.deiconify()
        self.avvia(img, fin.titolo)

    def _apri_foto(self):
        from tkinter import filedialog
        from PIL import Image
        p = filedialog.askopenfilename(title="Scegli una foto del tavolo",
                                       initialdir="sessioni" if os.path.isdir("sessioni") else ".",
                                       filetypes=[("Immagini", "*.png *.jpg *.jpeg")])
        if p:
            self.avvia(Image.open(p).convert("RGB"), os.path.basename(p))

    # --------------------------------------------- 2) disegna le zone
    def avvia(self, img, titolo=""):
        try:
            self.n_posti = max(2, min(10, int(self.v_posti.get())))
        except Exception:
            pass
        self.img = img
        self.cal = Calibrazione(img.width, img.height, self.n_posti, titolo)
        vecchia = carica()
        if vecchia and vecchia.n_posti == self.n_posti:
            self.cal.zone = dict(vecchia.zone)  # riparto dalla calibrazione precedente
        self.passi = passi(self.n_posti)
        self.i = 0
        self._schermata_disegno()

    def _schermata_disegno(self):
        tk = self.tk
        from PIL import ImageTk
        self._pulisci()
        sw, sh = self.root.winfo_screenwidth(), self.root.winfo_screenheight()
        lato = 340
        self.scala = min((sw - lato - 60) / self.img.width, (sh - 120) / self.img.height, 1.0)
        vw, vh = int(self.img.width * self.scala), int(self.img.height * self.scala)
        self.root.geometry(f"{vw + lato + 30}x{max(vh + 20, 560)}+10+10")
        self.foto = ImageTk.PhotoImage(self.img.resize((vw, vh)))
        self.canvas = tk.Canvas(self.root, width=vw, height=vh, bg="#000000", highlightthickness=0,
                                cursor="crosshair")
        self.canvas.pack(side="left", padx=(10, 0), pady=10, anchor="n")
        self.canvas.create_image(0, 0, image=self.foto, anchor="nw")
        self.canvas.bind("<ButtonPress-1>", self._premi)
        self.canvas.bind("<B1-Motion>", self._trascina)
        self.canvas.bind("<ButtonRelease-1>", self._rilascia)
        lato_f = tk.Frame(self.root, bg="#12151b", width=lato)
        lato_f.pack(side="left", fill="both", expand=True, padx=10, pady=10)
        self.l_passo = self._lab(lato_f, "", 10, col="#a3acb9")
        self.l_passo.pack(anchor="w")
        self.l_domanda = self._lab(lato_f, "", 14, True, wraplength=lato - 10, justify="left")
        self.l_domanda.pack(anchor="w", pady=(4, 8))
        self.l_aiuto = self._lab(lato_f, "Trascina il mouse sulla foto per fare il rettangolo.\n"
                                         "Se sbagli, ridisegnalo: sostituisce quello di prima.",
                                 9, col="#a3acb9", justify="left")
        self.l_aiuto.pack(anchor="w")
        b = tk.Frame(lato_f, bg="#12151b")
        b.pack(fill="x", pady=10)
        self._btn(b, "‹ Indietro", self.indietro).pack(side="left")
        self.b_salta = self._btn(b, "Salta (non c'è)", self.salta)
        self.b_salta.pack(side="left", padx=6)
        self.b_avanti = self._btn(b, "Avanti ›", self.avanti, col="#ffd27a", bg="#2a1f0b")
        self.b_avanti.pack(side="left")
        self.elenco = self._lab(lato_f, "", 9, col="#cfd5de", justify="left")
        self.elenco.pack(anchor="w", pady=(6, 0))
        self.b_salva = self._btn(lato_f, "Salva la calibrazione", self.salva, col="#9ff0c4", bg="#12301f")
        self.b_salva.pack(fill="x", side="bottom")
        self.msg = self._lab(lato_f, "", 10, col="#ff9b9b", wraplength=lato - 10, justify="left")
        self.msg.pack(side="bottom", anchor="w", pady=6)
        self._mostra_passo()

    def _rettangoli(self):
        self.canvas.delete("zona")
        corrente = self.passi[self.i][0] if self.i < len(self.passi) else None
        tipi = {k: t for k, _, _, t in self.passi}
        for k in self.cal.zone:
            x, y, w, h = self.cal.pixel(k)
            s = self.scala
            col = COLORI.get(tipi.get(k, ""), "#ffffff")
            self.canvas.create_rectangle(x * s, y * s, (x + w) * s, (y + h) * s, outline=col,
                                         width=3 if k == corrente else 2, tags="zona",
                                         dash=() if k == corrente else (4, 2))
            self.canvas.create_text(x * s + 3, y * s + 2, text=k.replace("_", " "), anchor="nw", fill=col,
                                    font=("Segoe UI", 8, "bold"), tags="zona")

    def _mostra_passo(self):
        n = len(self.passi)
        if self.i >= n:
            self.l_passo.config(text="Finito")
            self.l_domanda.config(text="Controlla i rettangoli sulla foto e premi «Salva la calibrazione».")
        else:
            k, testo, obbl, _ = self.passi[self.i]
            self.l_passo.config(text=f"Passo {self.i + 1} di {n}" + ("" if obbl else "  ·  facoltativo"))
            self.l_domanda.config(text=testo)
        fatti = sum(1 for k, _, _, _ in self.passi if k in self.cal.zone)
        manc = self.cal.mancanti()
        self.elenco.config(text=f"Zone disegnate: {fatti} di {n}" +
                                (f"\nMancano (obbligatorie): {', '.join(manc)}" if manc else "\nTutte le obbligatorie ci sono."))
        self._rettangoli()

    def _premi(self, e):
        self.inizio = (e.x, e.y)
        self.rett_corrente = self.canvas.create_rectangle(e.x, e.y, e.x, e.y, outline="#ffffff", width=2)

    def _trascina(self, e):
        if self.inizio:
            self.canvas.coords(self.rett_corrente, *self.inizio, e.x, e.y)

    def _rilascia(self, e):
        if not self.inizio or self.i >= len(self.passi):
            return
        x0, y0 = self.inizio
        self.inizio = None
        self.canvas.delete(self.rett_corrente)
        s = self.scala
        try:
            self.cal.imposta(self.passi[self.i][0], x0 / s, y0 / s, e.x / s, e.y / s)
        except ValueError as err:
            self.msg.config(text=str(err))
            return
        self.msg.config(text="")
        self.avanti()

    def avanti(self):
        self.i = min(self.i + 1, len(self.passi))
        self._mostra_passo()

    def indietro(self):
        self.i = max(self.i - 1, 0)
        self._mostra_passo()

    def salta(self):
        if self.i < len(self.passi):
            k, _, obbl, _ = self.passi[self.i]
            if obbl:
                self.msg.config(text="Questa zona serve: se proprio non c'è, premi «Avanti» e la lasci vuota.")
            self.cal.togli(k)
        self.avanti()

    def salva(self):
        manc = self.cal.mancanti()
        if manc:
            self.msg.config(text="Mancano ancora: " + ", ".join(manc) + ". Puoi salvare lo stesso premendo di nuovo.")
            if getattr(self, "_avvisato", False) is False:
                self._avvisato = True
                return None
        f = self.cal.salva(self.img)
        self.msg.config(text=f"Salvata in {os.path.abspath(f)}.\nLe zone finiranno anche nello zip di IMPARA.",
                        fg="#9ff0c4")
        return f


def main():
    import tkinter as tk
    root = tk.Tk()
    App(root)
    root.mainloop()


if __name__ == "__main__":
    main()
