"""LETTORE.bat: insegna al lettore, provalo sulle foto, accendilo o spegnilo.

    py -m coach.lettore_app
Menu (finestra):
  1) Insegna e prova sulle foto registrate (cartella sessioni/...): per ogni carta o cifra
     nuova te la mostra ingrandita e scrivi cos'è; poi crea il REPORT di cosa ha letto.
  2) Interruttore del lettore automatico (SPENTO di default). Acceso + modalità Dal vivo:
     riempie da solo la finestra del coach. Non legge mai i siti a soldi veri.
"""
import html
import os
import time
import zipfile

from PIL import Image

from . import annotazioni
from .calibra import carica as carica_cal
from .lettore import IGNORA, Lettore, bloccato

CARTELLA_REPORT = "report_lettore"


def foto_della_cartella(cartella):
    return sorted(os.path.join(cartella, f) for f in os.listdir(cartella) if f.lower().endswith((".jpg", ".png")))


def report_html(righe, cartella_foto, dest):
    """righe: [(file, lettura)] -> pagina con cosa ha letto foto per foto."""
    e = html.escape
    tot = len(righe)
    sicure = sum(1 for _, l in righe if l.sicura())
    pezzi = []
    for f, l in righe:
        rel = os.path.relpath(f, os.path.dirname(dest)).replace(os.sep, "/")
        stack = " · ".join(f"{k}: {v:g}" if v is not None else f"{k}: —" for k, v in sorted(l.stack.items()))
        punt = " · ".join(f"{k}: {v:g}" for k, v in sorted(l.puntate.items()) if v)
        pezzi.append(
            f'<section class="{"ok" if l.sicura() else "no"}"><a href="{e(rel)}" target="_blank">'
            f'<img src="{e(rel)}" loading="lazy" alt=""></a><div><b>{e(os.path.basename(f))}</b>'
            f'<p>Tue carte: <b>{e(" ".join(l.mie_carte) or "—")}</b> · Tavolo: <b>{e(" ".join(l.tavolo) or "—")}</b></p>'
            f'<p>Piatto: <b>{"—" if l.piatto is None else f"{l.piatto:g}"}</b> · Bottone: posto {l.bottone or "?"} · '
            f'Tocca a te: {"sì" if l.tocca_a_me else "no" if l.tocca_a_me is False else "?"}</p>'
            f'<p>Stack: {e(stack)}</p><p>Puntate: {e(punt or "—")}</p>'
            + (f'<p class="dubbi">Incerto: {e(", ".join(l.incerti))}</p>' if l.incerti else "")
            + "".join(f'<p class="dubbi">{e(a)}</p>' for a in l.avvisi)
            + "</div></section>")
    pagina = f"""<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Report del lettore</title>
<meta name="viewport" content="width=device-width, initial-scale=1"><style>
body{{margin:0;background:#0f1012;color:#f0efec;font:15px/1.5 system-ui,"Segoe UI",sans-serif}}
main{{max-width:1100px;margin:0 auto;padding:20px 16px}} section{{display:flex;gap:14px;flex-wrap:wrap;
background:#1a1a19;border:1px solid #2e2e2b;border-left:4px solid #6ee7a8;border-radius:10px;padding:10px;margin:10px 0}}
section.no{{border-left-color:#ffd27a}} img{{width:420px;max-width:100%;border-radius:6px}} p{{margin:2px 0}}
.dubbi{{color:#ffd27a}} b{{color:#fff}}</style></head><body><main>
<h1>Report del lettore</h1><p>{time.strftime('%d/%m/%Y %H:%M')} · foto lette: {tot} · lette con sicurezza: {sicure}
({(sicure / tot if tot else 0):.0%}) · cartella: {e(cartella_foto)}</p>{''.join(pezzi)}</main></body></html>"""
    with open(dest, "w", encoding="utf-8") as fp:
        fp.write(pagina)
    return dest


def zip_report(dest_html, righe, lett):
    """Zip da mandare a Claude: report, calibrazione, modelli insegnati e le foto lette male."""
    os.makedirs("pacchetti", exist_ok=True)
    nome = os.path.join("pacchetti", f"poker_lettore_{time.strftime('%Y%m%d_%H%M')}.zip")
    with zipfile.ZipFile(nome, "w", zipfile.ZIP_DEFLATED) as z:
        z.write(dest_html, "report.html")
        if os.path.exists("calibrazione.json"):
            z.write("calibrazione.json", "calibrazione.json")
        if os.path.exists(os.path.join("calibrazione", "anteprima.png")):
            z.write(os.path.join("calibrazione", "anteprima.png"), "calibrazione/anteprima.png")
        for radice, _, files in os.walk(lett.cartella):
            for f in files:
                p = os.path.join(radice, f)
                z.write(p, os.path.relpath(p).replace(os.sep, "/"))
        dubbie = [f for f, l in righe if not l.sicura()][:40]
        for f in dubbie:
            z.write(f, "foto_dubbie/" + os.path.basename(f))
        z.writestr("leggimi.txt", "Report del lettore: apri report.html. Le foto lette con dubbi sono in foto_dubbie/.\n")
    return nome


class App:
    def __init__(self, root):
        import tkinter as tk
        self.tk, self.root = tk, root
        root.title("Lettore del tavolo")
        root.configure(bg="#12151b")
        root.geometry("720x560")
        self.cfg = annotazioni.carica_config()
        self.cal = carica_cal()
        self._menu()

    def _pulisci(self):
        for w in self.root.winfo_children():
            w.destroy()

    def _lab(self, p, t, size=11, bold=False, col="#e8ebf0", **kw):
        return self.tk.Label(p, text=t, bg="#12151b", fg=col, font=("Segoe UI", size, "bold" if bold else "normal"), **kw)

    def _btn(self, p, t, cmd, col="#e8ebf0", bg="#1a1e26"):
        return self.tk.Button(p, text=t, command=cmd, bg=bg, fg=col, relief="flat", bd=0, cursor="hand2",
                              activebackground="#2a3446", activeforeground="#fff", font=("Segoe UI", 10, "bold"),
                              padx=12, pady=7)

    # ------------------------------------------------------------ menu
    def _menu(self):
        self._pulisci()
        f = self.tk.Frame(self.root, bg="#12151b")
        f.pack(fill="both", expand=True, padx=18, pady=16)
        self._lab(f, "Lettore del tavolo", 17, True).pack(anchor="w")
        if not self.cal:
            self._lab(f, "Prima fai la calibrazione (CALIBRA.bat): il lettore deve sapere dove guardare.",
                      11, col="#ffd27a", wraplength=660, justify="left").pack(anchor="w", pady=10)
            return
        attivo = bool(self.cfg.get("lettore_attivo"))
        self._lab(f, f"Calibrazione: «{self.cal.titolo}» · {self.cal.n_posti} posti · finestra {self.cal.w}x{self.cal.h}",
                  10, col="#a3acb9").pack(anchor="w", pady=(2, 14))
        self._btn(f, "Insegna e prova sulle foto registrate…", self.scegli_cartella, col="#ffd27a", bg="#2a1f0b") \
            .pack(fill="x")
        self._lab(f, "Scegli una cartella in «sessioni» (le foto di IMPARA o di ANNOTAZIONI). Per ogni carta o cifra "
                     "nuova ti chiedo cos'è; alla fine creo il report e lo zip da mandare a Claude.",
                  9, col="#a3acb9", wraplength=660, justify="left").pack(anchor="w", pady=(4, 16))
        self._btn(f, "Analizza una partita registrata e apri il ripasso…", self.analizza_partita, col="#7cc4ff") \
            .pack(fill="x")
        self._lab(f, "A partita finita: legge tutte le foto, ricostruisce le mani e ti mostra come avrebbe "
                     "giocato il coach. Insegna prima le cose nuove con il tasto sopra.",
                  9, col="#a3acb9", wraplength=660, justify="left").pack(anchor="w", pady=(4, 16))
        self._lab(f, "Lettore automatico nella finestra del coach:", 11, True).pack(anchor="w")
        self._btn(f, ("● ACCESO: premi per spegnere" if attivo else "SPENTO: premi per accendere"),
                  self.interruttore, col="#ffd27a" if attivo else "#9ff0c4").pack(anchor="w", pady=6)
        self._lab(f, "Acceso + modalità «Dal vivo» = riempie da solo la finestra del coach. Usalo solo nelle partite "
                     "contro il computer. Sui siti a soldi veri (PokerStars, GGPoker, 888, Sisal, Snai…) non legge "
                     "mai, anche se è acceso.", 9, col="#a3acb9", wraplength=660, justify="left").pack(anchor="w")
        self.msg = self._lab(f, "", 10, col="#9ff0c4", wraplength=660, justify="left")
        self.msg.pack(anchor="w", pady=10)

    def interruttore(self):
        if not self.cfg.get("lettore_attivo"):
            from tkinter import messagebox
            if not messagebox.askyesno("Lettore automatico",
                                       "Accendere il lettore automatico?\n\nLegge la finestra del gioco e, in "
                                       "modalità «Dal vivo», riempie da solo il coach. Solo per partite contro il "
                                       "computer. Non funziona sui siti a soldi veri.", parent=self.root):
                return
        self.cfg = annotazioni.aggiorna_config({"lettore_attivo": not self.cfg.get("lettore_attivo")})
        self._menu()

    def analizza_partita(self, cartella=None, apri=True):
        from tkinter import filedialog
        from .partita import carica as carica_partita
        from .ricostruisci import analizza_registrazione
        from .ripasso import analizza, salva_pagina
        from .storia import Storia
        if cartella is None:
            cartella = filedialog.askdirectory(title="Cartella della partita registrata",
                                               initialdir="sessioni" if os.path.isdir("sessioni") else ".")
            if not cartella:
                return None
        st = Storia("storia.db")
        pid = st.nuova_partita(gioco=self.cal.titolo, note="analisi delle foto: " + os.path.basename(cartella))
        p = carica_partita()
        self.msg.config(text="Analizzo le foto…", fg="#a3acb9")
        self.root.update()
        esito = analizza_registrazione(cartella, st, pid, self.cal, p.bb if p else 20, p.ante if p else 0,
                                       avanzamento=lambda i, n: (self.msg.config(text=f"Analizzo le foto… {i}/{n}"),
                                                                 self.root.update()))
        if esito["bloccata"]:
            self.msg.config(text="Partita su un sito a soldi veri: il lettore non la analizza.", fg="#ffd27a")
            return None
        path = salva_pagina(analizza(st, pid), "ripassi")
        dubbi = ", ".join(f"{z.replace('_', ' ')} ({n})" for z, n in esito.get("incerte", [])[:5])
        self.msg.config(text=f"Foto: {esito['foto']} (lette con sicurezza {esito['sicure']}) · mani: {esito['mani']}\n"
                             f"Ripasso: {os.path.abspath(path)}" + (f"\nZone con dubbi: {dubbi}" if dubbi else ""),
                        fg="#9ff0c4")
        if apri:
            import webbrowser
            webbrowser.open("file:///" + os.path.abspath(path).replace(os.sep, "/"))
        return path

    # ------------------------------------------------------------ insegna + report
    def scegli_cartella(self):
        from tkinter import filedialog
        d = filedialog.askdirectory(title="Cartella con le foto", initialdir="sessioni" if os.path.isdir("sessioni") else ".")
        if d:
            self.avvia(d)

    def avvia(self, cartella):
        self.lett = Lettore(self.cal)
        self.cartella = cartella
        self.foto = foto_della_cartella(cartella)
        self.i, self.righe, self.coda = 0, [], []
        self._prossima()

    def _prossima(self):
        """Legge le foto una dopo l'altra; se trova cose nuove si ferma e le chiede."""
        while self.i < len(self.foto):
            f = self.foto[self.i]
            img = Image.open(f).convert("RGB")
            titolo = self.cal.titolo
            l = self.lett.leggi(img, titolo)
            if l.bloccata or bloccato(titolo):
                self._fine("La calibrazione è di un sito a soldi veri: il lettore non lavora su queste foto.")
                return
            if l.sconosciuti:
                self.coda = l.sconosciuti
                self._chiedi()
                return
            self.righe.append((f, l))
            self.i += 1
        self._fine()

    def _chiedi(self):
        from PIL import ImageTk
        s = self.coda[0]
        self._pulisci()
        f = self.tk.Frame(self.root, bg="#12151b")
        f.pack(fill="both", expand=True, padx=18, pady=16)
        self._lab(f, f"Foto {self.i + 1} di {len(self.foto)} · zona «{s.zona.replace('_', ' ')}»", 10,
                  col="#a3acb9").pack(anchor="w")
        if s.tipo == "carta":
            domanda = "Che carta è? Scrivi valore e seme: es. Ad, 10h, Ks, 7c  (h=♥ d=♦ c=♣ s=♠)"
        else:
            domanda = "Che cos'è? Scrivi la cifra o il simbolo (es. 7 , . €). Se non fa parte del numero premi «Ignora»."
        self._lab(f, domanda, 12, True, wraplength=660, justify="left").pack(anchor="w", pady=(4, 10))
        w, h = s.immagine.size
        k = max(1, min(8, int(220 / max(h, 1))))
        self._foto = ImageTk.PhotoImage(s.immagine.resize((w * k, h * k), Image.NEAREST))
        self.tk.Label(f, image=self._foto, bg="#000").pack(anchor="w")
        self.v = self.tk.StringVar()
        e = self.tk.Entry(f, textvariable=self.v, font=("Segoe UI", 16), width=8)
        e.pack(anchor="w", pady=10)
        e.focus_set()
        e.bind("<Return>", lambda ev: self._risposta())
        b = self.tk.Frame(f, bg="#12151b")
        b.pack(anchor="w")
        self._btn(b, "OK (Invio)", self._risposta, col="#ffd27a", bg="#2a1f0b").pack(side="left")
        if s.tipo == "glifo":
            self._btn(b, "Ignora (non è un numero)", lambda: self._risposta(IGNORA)).pack(side="left", padx=8)
        self._btn(b, "Salta questa foto", self._salta_foto).pack(side="left", padx=8)
        self.errore = self._lab(f, "", 10, col="#ff9b9b")
        self.errore.pack(anchor="w", pady=6)
        self._lab(f, f"Da insegnare in questa foto: {len(self.coda)}", 9, col="#a3acb9").pack(anchor="w")

    def _risposta(self, valore=None):
        s = self.coda[0]
        v = (valore if valore is not None else self.v.get()).strip()
        if s.tipo == "carta":
            v = v.replace("10", "T")
            if len(v) != 2 or v[0].upper() not in "AKQJT98765432" or v[1].lower() not in "hdcs":
                self.errore.config(text="Scrivi tipo «Ad», «10h», «Ks», «7c».")
                return
            v = v[0].upper() + v[1].lower()
            self.lett.mod.insegna_carta(v, s.matrice, s.colore)
        else:
            if not v:
                self.errore.config(text="Scrivi cos'è, oppure premi «Ignora».")
                return
            self.lett.mod.insegna_glifo(v, s.matrice)
        self.coda.pop(0)
        self.coda = [x for x in self.coda if not self._riconosciuto(x)]  # ogni cosa si chiede una volta sola
        if self.coda:
            self._chiedi()
        else:
            self._prossima()  # rilegge la stessa foto con quello che ha imparato

    def _riconosciuto(self, x):
        if x.tipo == "carta":
            return self.lett.mod.riconosci_carta(x.matrice, x.colore) is not None
        return self.lett.mod.riconosci_glifo(x.matrice) is not None

    def _salta_foto(self):
        self.coda = []
        self.i += 1
        self._prossima()

    def _fine(self, nota=""):
        os.makedirs(CARTELLA_REPORT, exist_ok=True)
        dest = os.path.join(CARTELLA_REPORT, f"report_{time.strftime('%Y%m%d_%H%M%S')}.html")
        report_html(self.righe, self.cartella, dest)
        z = zip_report(dest, self.righe, self.lett)
        self._menu()
        self.msg.config(text=(nota + "\n" if nota else "") + f"Report: {os.path.abspath(dest)}\n"
                             f"Zip per Claude: {os.path.abspath(z)}")
        import webbrowser
        webbrowser.open("file:///" + os.path.abspath(dest).replace(os.sep, "/"))
        return dest, z


def main():
    import tkinter as tk
    root = tk.Tk()
    App(root)
    root.mainloop()


if __name__ == "__main__":
    main()
