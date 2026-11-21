"""Trova la finestra del gioco, ne misura la grandezza e la registra in automatico.

Comandi (Windows):
    py -m coach.schermo finestre                 elenco delle finestre aperte con dimensioni
    py -m coach.schermo foto "Poker"             una foto della finestra (da mandare per la calibrazione)
    py -m coach.schermo registra "Poker"         registra di continuo (Ctrl+C per fermare)

"Poker" = una parte del titolo della finestra (maiuscole/minuscole non contano).
Si misura solo l'AREA DI GIOCO (senza bordi e barra del titolo), in pixel reali.
"""
import csv
import json
import os
import sys
import time
from dataclasses import dataclass, asdict

import mss
import mss.tools

WINDOWS = sys.platform.startswith("win")
_MSS = getattr(mss, "MSS", None) or mss.mss  # nome nuovo (mss >= 10) o vecchio


@dataclass
class Finestra:
    titolo: str
    handle: int
    x: int
    y: int
    w: int
    h: int
    minimizzata: bool = False

    @property
    def area(self):
        return {"left": self.x, "top": self.y, "width": self.w, "height": self.h}

    def __str__(self):
        stato = " (ridotta a icona)" if self.minimizzata else ""
        return f"{self.w}x{self.h} in ({self.x},{self.y})  «{self.titolo}»{stato}"


# ------------------------------------------------------------------ Windows
if WINDOWS:
    import ctypes
    from ctypes import wintypes

    _u32 = ctypes.windll.user32
    try:  # pixel reali anche con lo zoom di Windows al 125%/150%
        ctypes.windll.shcore.SetProcessDpiAwareness(2)
    except Exception:
        try:
            _u32.SetProcessDPIAware()
        except Exception:
            pass
    _ENUM = ctypes.WINFUNCTYPE(wintypes.BOOL, wintypes.HWND, wintypes.LPARAM)
    _u32.WindowFromPoint.argtypes = [wintypes.POINT]
    _u32.WindowFromPoint.restype = wintypes.HWND
    _u32.GetAncestor.restype = wintypes.HWND

    def _titolo(hwnd):
        n = _u32.GetWindowTextLengthW(hwnd)
        buf = ctypes.create_unicode_buffer(n + 1)
        _u32.GetWindowTextW(hwnd, buf, n + 1)
        return buf.value

    def _leggi(hwnd):
        if not _u32.IsWindow(hwnd):
            return None
        rc = wintypes.RECT()
        _u32.GetClientRect(hwnd, ctypes.byref(rc))
        pt = wintypes.POINT(0, 0)
        _u32.ClientToScreen(hwnd, ctypes.byref(pt))
        return Finestra(_titolo(hwnd), int(hwnd), pt.x, pt.y, rc.right - rc.left, rc.bottom - rc.top,
                        bool(_u32.IsIconic(hwnd)))

    def elenca_finestre():
        out = []

        def cb(hwnd, _lp):
            if _u32.IsWindowVisible(hwnd) and _u32.GetWindowTextLengthW(hwnd):
                f = _leggi(hwnd)
                if f and (f.minimizzata or (f.w >= 200 and f.h >= 150)):
                    out.append(f)
            return True

        _u32.EnumWindows(_ENUM(cb), 0)
        return out

    def aggiorna(f):
        return _leggi(f.handle)

    def coperta(f):
        """True se un'altra finestra copre una parte della finestra del gioco."""
        for fx, fy in ((0.5, 0.5), (0.1, 0.1), (0.9, 0.1), (0.1, 0.9), (0.9, 0.9)):
            pt = wintypes.POINT(int(f.x + f.w * fx), int(f.y + f.h * fy))
            h = _u32.WindowFromPoint(pt)
            if h and int(_u32.GetAncestor(h, 2)) != f.handle:  # 2 = GA_ROOT
                return True
        return False

# ------------------------------------------------------------------ altri sistemi (prove)
else:
    def elenca_finestre():
        """Fuori da Windows: ogni monitor è trattato come una 'finestra' (serve solo per provare)."""
        with _MSS() as sct:
            return [Finestra(f"Schermo {i}", i, m["left"], m["top"], m["width"], m["height"])
                    for i, m in enumerate(sct.monitors[1:], start=1)]

    def aggiorna(f):
        for x in elenca_finestre():
            if x.handle == f.handle:
                return x
        return None

    def coperta(f):
        return False


def trova(titolo):
    """Finestra il cui titolo contiene `titolo`; se più di una, la più grande."""
    t = titolo.lower()
    cand = [f for f in elenca_finestre() if t in f.titolo.lower()]
    if not cand:
        return None
    return max(cand, key=lambda f: (not f.minimizzata, f.w * f.h))


# ------------------------------------------------------------------ cattura
def _campione(img, punti=60000):
    raw = img.raw
    passo = max(4, len(raw) // punti)
    return raw[::passo]


def differenza(a, b, tolleranza=16):
    """Frazione di punti campione cambiati tra due catture (0 = identiche)."""
    if a is None or b is None or len(a) != len(b):
        return 1.0
    diversi = sum(1 for x, y in zip(a, b) if abs(x - y) > tolleranza)
    return diversi / len(a)


def foto(titolo, file="foto_gioco.png"):
    f = trova(titolo)
    if not f:
        raise SystemExit(f"Nessuna finestra con «{titolo}» nel titolo. Prova: py -m coach.schermo finestre")
    if f.minimizzata:
        raise SystemExit("La finestra è ridotta a icona: aprila e riprova.")
    with _MSS() as sct:
        img = sct.grab(f.area)
        mss.tools.to_png(img.rgb, img.size, output=file)
    avviso = "  ATTENZIONE: un'altra finestra la copre in parte" if coperta(f) else ""
    print(f"Salvata {file}: {f}{avviso}")
    return file, f


def salva_immagine(img, percorso):
    """PNG (senza perdita) oppure JPG (molto più leggero, serve Pillow) secondo l'estensione."""
    if percorso.lower().endswith((".jpg", ".jpeg")):
        from PIL import Image
        Image.frombytes("RGB", img.size, img.rgb).save(percorso, quality=85, optimize=True)
    else:
        mss.tools.to_png(img.rgb, img.size, output=percorso)


def registra(titolo, cartella="registrazioni", intervallo=1.0, soglia=0.003,
             durata=None, max_foto=None, log=print, finestra=None, stop=None, formato="png"):
    """Cattura la finestra ogni `intervallo` secondi e salva SOLO quando l'immagine cambia.

    finestra: una Finestra già scelta (altrimenti la cerca per titolo);
    stop: un threading.Event per fermare da fuori; formato: "png" o "jpg".

    Tiene in `cartella/indice.csv` l'elenco delle foto e in `finestra.json` la storia delle
    dimensioni della finestra. Ritorna il numero di foto salvate.
    """
    os.makedirs(cartella, exist_ok=True)
    indice_path = os.path.join(cartella, "indice.csv")
    nuovo = not os.path.exists(indice_path)
    indice = open(indice_path, "a", newline="", encoding="utf-8")
    scrivi = csv.writer(indice)
    if nuovo:
        scrivi.writerow(["n", "ora", "file", "larghezza", "altezza", "x", "y", "cambiamento"])
    storia_dim = []

    import threading
    stop = stop or threading.Event()
    f, ultimo_campione, ultima_dim = finestra, None, None
    salvate, avvisi = 0, set()
    inizio = time.time()
    log(f"Registrazione in «{cartella}» ogni {intervallo:g}s. Ctrl+C per fermare.")
    try:
        with _MSS() as sct:
            while not stop.is_set():
                if durata and time.time() - inizio > durata:
                    break
                if max_foto and salvate >= max_foto:
                    break
                t0 = time.time()
                f = aggiorna(f) if f else None
                if f is None:
                    f = trova(titolo) if titolo else None
                    if f is None:
                        if "manca" not in avvisi:
                            log(f"Aspetto una finestra con «{titolo}» nel titolo...")
                            avvisi.add("manca")
                        stop.wait(1)
                        continue
                    avvisi.discard("manca")
                    log(f"Trovata: {f}")

                if f.minimizzata or f.w < 50 or f.h < 50:
                    if "mini" not in avvisi:
                        log("La finestra è ridotta a icona: aspetto che torni visibile.")
                        avvisi.add("mini")
                    stop.wait(1)
                    continue
                avvisi.discard("mini")

                dim = (f.w, f.h)
                if dim != ultima_dim:
                    if ultima_dim:
                        log(f"Finestra ridimensionata: {ultima_dim[0]}x{ultima_dim[1]} -> {f.w}x{f.h}")
                    else:
                        log(f"Area di gioco: {f.w}x{f.h} pixel, angolo in alto a sinistra ({f.x},{f.y})")
                    storia_dim.append({"ora": time.strftime("%Y-%m-%d %H:%M:%S"), **asdict(f)})
                    ultima_dim, ultimo_campione = dim, None

                if coperta(f):
                    if "coperta" not in avvisi:
                        log("ATTENZIONE: un'altra finestra copre il gioco: porta il gioco davanti.")
                        avvisi.add("coperta")
                else:
                    avvisi.discard("coperta")

                img = sct.grab(f.area)
                camp = _campione(img)
                diff = differenza(camp, ultimo_campione)
                if diff >= soglia:
                    salvate += 1
                    nome = f"{salvate:05d}_{time.strftime('%H%M%S')}.{formato}"
                    salva_immagine(img, os.path.join(cartella, nome))
                    scrivi.writerow([salvate, time.strftime("%Y-%m-%d %H:%M:%S"), nome,
                                     f.w, f.h, f.x, f.y, f"{diff:.4f}"])
                    indice.flush()
                    ultimo_campione = camp
                stop.wait(max(0.0, intervallo - (time.time() - t0)))
    except KeyboardInterrupt:
        pass
    finally:
        indice.close()
        with open(os.path.join(cartella, "finestra.json"), "a", encoding="utf-8") as fp:
            for d in storia_dim:
                fp.write(json.dumps(d, ensure_ascii=False) + "\n")
    log(f"Fermato. Foto salvate: {salvate} (solo quando lo schermo cambiava).")
    return salvate


def main(argv=None):
    import argparse
    ap = argparse.ArgumentParser(prog="coach.schermo", description="Cattura della finestra del gioco")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("finestre", help="elenco delle finestre aperte")
    fo = sub.add_parser("foto", help="una foto della finestra del gioco")
    fo.add_argument("titolo")
    fo.add_argument("--file", default="foto_gioco.png")
    r = sub.add_parser("registra", help="registra di continuo la finestra del gioco")
    r.add_argument("titolo")
    r.add_argument("--cartella", default="registrazioni")
    r.add_argument("--intervallo", type=float, default=1.0, help="secondi tra una cattura e l'altra")
    r.add_argument("--durata", type=float, help="ferma dopo N secondi")
    a = ap.parse_args(argv)
    if a.cmd == "finestre":
        fin = elenca_finestre()
        if not fin:
            print("Nessuna finestra trovata.")
        for f in sorted(fin, key=lambda f: -f.w * f.h):
            print(" ", f)
    elif a.cmd == "foto":
        foto(a.titolo, a.file)
    else:
        registra(a.titolo, a.cartella, a.intervallo, durata=a.durata)


if __name__ == "__main__":
    main()
