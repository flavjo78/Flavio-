"""Lettore del tavolo: dalle foto della finestra alle carte, ai numeri, al turno.

È GENERICO: non conosce in partenza nessun gioco. Sa dove guardare grazie alla
calibrazione (le zone che hai disegnato) e riconosce carte e cifre confrontandole con
i modelli che gli INSEGNI tu (la prima volta che vede qualcosa di nuovo te lo chiede).

È SPENTO di default e NON legge le finestre dei siti di poker a soldi veri.
"""
import json
import os
import re
from dataclasses import dataclass, field

import numpy as np
from PIL import Image

CARTELLA = "lettore"
MODELLI = os.path.join(CARTELLA, "modelli")
SOGLIA_CARTA = 0.90
SOGLIA_GLIFO = 0.86
FORMA_CARTA = (24, 32)    # larghezza, altezza dei modelli delle carte
FORMA_GLIFO = (12, 20)
IGNORA = "_ignora"

# Siti e programmi a soldi veri: qui il lettore non legge MAI (regola del progetto)
SITI_SOLDI_VERI = ("pokerstars", "ggpoker", "gg poker", "888poker", "888 poker", "partypoker", "party poker",
                   "winamax", "ipoker", "sisal", "snai", "lottomatica", "eurobet", "goldbet", "betflag",
                   "william hill", "williamhill", "bwin", "unibet", "betfair", "betsson", "pokerclub",
                   "wpt global", "acr poker", "americas cardroom", "natural8", "coinpoker", "pokerok")


def bloccato(titolo):
    t = (titolo or "").lower()
    return any(s in t for s in SITI_SOLDI_VERI)


# ------------------------------------------------------------------ immagini
def _np(img):
    return np.asarray(img.convert("RGB"), dtype=np.int32)  # 32 bit: con 16 la luminosità va in overflow


def ritaglia(img, zona_px):
    x, y, w, h = zona_px
    return img.crop((x, y, x + w, y + h))


def _bbox(mask):
    ys, xs = np.nonzero(mask)
    if len(xs) == 0:
        return None
    return xs.min(), ys.min(), xs.max() + 1, ys.max() + 1


def _normalizza(mask, forma):
    """Maschera booleana -> matrice 0/1 della forma data (mantiene le proporzioni dentro il riquadro)."""
    if mask.size == 0 or not mask.any():
        return np.zeros((forma[1], forma[0]), dtype=np.uint8)
    im = Image.fromarray((mask * 255).astype(np.uint8)).resize(forma, Image.BILINEAR)
    return (np.asarray(im) > 127).astype(np.uint8)


def somiglianza(a, b):
    return 1.0 - float(np.mean(a != b))


# ------------------------------------------------------------------ carte
def carta_presente(crop):
    """Una carta scoperta ha lo sfondo chiaro: almeno il 40% di pixel quasi bianchi."""
    a = _np(crop)
    chiari = (a.min(axis=2) > 185)
    return float(chiari.mean()) >= 0.40


def maschera_carta(crop):
    """Inchiostro della carta (nero o rosso) su fondo chiaro; colore 'rosso' o 'nero'."""
    a = _np(crop)
    r, g, b = a[..., 0], a[..., 1], a[..., 2]
    lum = (r * 299 + g * 587 + b * 114) // 1000
    rosso = (r > 120) & (r - g > 60) & (r - b > 60)
    nero = lum < 110
    ink = rosso | nero
    bb = _bbox(ink)
    if bb is None:
        return None, None
    x0, y0, x1, y1 = bb
    colore = "rosso" if rosso[y0:y1, x0:x1].sum() > nero[y0:y1, x0:x1].sum() else "nero"
    return ink[y0:y1, x0:x1], colore


# ------------------------------------------------------------------ numeri
def maschera_testo(crop, stacco=90):
    """Pixel del testo: quelli che staccano molto dal colore di sfondo della zona (testo chiaro o scuro).

    Lo sfondo è il valore più comune (mediana); bordi del tavolo e sfumature staccano poco e
    vengono ignorati, le cifre staccano tanto.
    """
    a = _np(crop)
    lum = (a[..., 0] * 299 + a[..., 1] * 587 + a[..., 2] * 114) // 1000
    sfondo = int(np.median(lum))
    chiari, scuri = lum > sfondo + stacco, lum < sfondo - stacco
    return chiari if chiari.sum() >= scuri.sum() else scuri


def segmenta_glifi(mask):
    """Divide la riga di testo in caratteri (colonne vuote = separatori). Ritorna [(x0, x1)]."""
    righe = np.nonzero(mask.any(axis=1))[0]
    if len(righe) == 0:
        return [], None
    y0, y1 = righe.min(), righe.max() + 1
    m = mask[y0:y1]
    col = m.any(axis=0)
    out, inizio = [], None
    for x, c in enumerate(list(col) + [False]):
        if c and inizio is None:
            inizio = x
        elif not c and inizio is not None:
            out.append((inizio, x))
            inizio = None
    return out, (y0, y1)


def glifo_normalizzato(mask, x0, x1, riga):
    y0, y1 = riga
    return _normalizza(mask[y0:y1, x0:x1], FORMA_GLIFO)


def interpreta_numero(testo):
    """'€1.234,50' / '1,234.50' / 'Pot: 0.29' / '2.05' -> float. None se non c'è un numero."""
    t = re.sub(r"[^0-9.,]", "", testo or "")
    if not re.search(r"\d", t):
        return None
    m = re.search(r"[.,](\d{1,2})$", t)
    if m:  # separatore decimale alla fine
        intero = re.sub(r"[.,]", "", t[:m.start()])
        return float(f"{intero or 0}.{m.group(1)}")
    return float(re.sub(r"[.,]", "", t))


# ------------------------------------------------------------------ modelli insegnati
def _nome_file(etichetta):
    if etichetta == IGNORA:
        return "ign"
    return "-".join(f"{ord(c):x}" for c in etichetta)


def _etichetta_da_file(nome):
    base = nome.split("__")[0]
    if base == "ign":
        return IGNORA
    return "".join(chr(int(p, 16)) for p in base.split("-"))


class Modelli:
    """Carte e caratteri insegnati dal giocatore, salvati come piccole immagini PNG.

    Valgono per UNA dimensione della finestra: se la ingrandisci, il gioco ridisegna le cifre
    in modo diverso e vanno insegnate di nuovo (cartella separata per ogni dimensione).
    """

    def __init__(self, cartella=MODELLI, dimensione=None):
        self.base = cartella
        self.dimensione = dimensione
        self.cartella = os.path.join(cartella, f"{dimensione[0]}x{dimensione[1]}") if dimensione else cartella
        self.carte, self.glifi = [], []  # [(etichetta, matrice, colore)]
        self._carica()

    def _carica(self):
        for tipo, lista in (("carte", self.carte), ("caratteri", self.glifi)):
            d = os.path.join(self.cartella, tipo)
            if not os.path.isdir(d):
                continue
            for f in sorted(os.listdir(d)):
                if f.endswith(".png"):
                    m = (np.asarray(Image.open(os.path.join(d, f)).convert("L")) > 127).astype(np.uint8)
                    colore = "rosso" if "__r" in f else ("nero" if "__n" in f else None)
                    lista.append((_etichetta_da_file(f), m, colore))

    def _salva(self, tipo, etichetta, matrice, colore=None):
        d = os.path.join(self.cartella, tipo)
        os.makedirs(d, exist_ok=True)
        suff = {"rosso": "r", "nero": "n"}.get(colore, "x")
        n = len([f for f in os.listdir(d) if f.startswith(_nome_file(etichetta) + "__")])
        Image.fromarray((matrice * 255).astype(np.uint8)).save(
            os.path.join(d, f"{_nome_file(etichetta)}__{suff}{n}.png"))

    def insegna_carta(self, etichetta, matrice, colore):
        self.carte.append((etichetta, matrice, colore))
        self._salva("carte", etichetta, matrice, colore)

    def insegna_glifo(self, etichetta, matrice):
        self.glifi.append((etichetta, matrice, None))
        self._salva("caratteri", etichetta, matrice)

    @staticmethod
    def _migliore(lista, m, colore=None):
        best, score = None, 0.0
        for etich, mod, col in lista:
            if colore and col and col != colore:
                continue
            if mod.shape != m.shape:
                continue
            s = somiglianza(mod, m)
            if s > score:
                best, score = etich, s
        return best, score

    def vuoto(self):
        return not self.carte and not self.glifi

    def altre_dimensioni(self):
        if not os.path.isdir(self.base):
            return []
        return [d for d in os.listdir(self.base) if re.fullmatch(r"\d+x\d+", d) and
                (not self.dimensione or d != f"{self.dimensione[0]}x{self.dimensione[1]}")]

    def carta(self, m, colore):
        return self._migliore(self.carte, m, colore)

    def glifo(self, m):
        return self._migliore(self.glifi, m)


# ------------------------------------------------------------------ lettura
@dataclass
class Sconosciuto:
    tipo: str              # "carta" o "glifo"
    zona: str
    matrice: object        # matrice normalizzata da insegnare
    immagine: object       # ritaglio PIL da mostrare al giocatore
    colore: str = None


@dataclass
class Lettura:
    mie_carte: list = field(default_factory=list)       # es. ["Ad", "Qh"] ("?" = non riconosciuta)
    tavolo: list = field(default_factory=list)
    piatto: float = None
    stack: dict = field(default_factory=dict)            # posto -> numero (None = vuoto / illeggibile)
    puntate: dict = field(default_factory=dict)
    bottone: int = None                                  # posto con il bottone
    tocca_a_me: bool = None
    testi: dict = field(default_factory=dict)            # zona -> testo letto (per il report)
    incerti: list = field(default_factory=list)          # zone lette male
    sconosciuti: list = field(default_factory=list)      # cose da insegnare
    bloccata: bool = False
    avvisi: list = field(default_factory=list)            # messaggi per il giocatore

    def sicura(self):
        return not self.incerti and not self.bloccata

    def carte_txt(self, quali):
        return " ".join(quali)


class Lettore:
    def __init__(self, calibrazione, cartella=MODELLI):
        self.cal = calibrazione
        self.cartella = cartella
        self._per_dimensione = {}
        self.mod = self._modelli((calibrazione.w, calibrazione.h))
        self._modello_bottone = None
        p = os.path.join("calibrazione", "zone", "bottone.png")
        if "bottone" in self.cal.zone and os.path.exists(p):
            self._modello_bottone = np.asarray(Image.open(p).convert("L"), dtype=float)

    def _modelli(self, dimensione):
        if dimensione not in self._per_dimensione:
            self._per_dimensione[dimensione] = Modelli(self.cartella, dimensione)
        return self._per_dimensione[dimensione]

    def _zona(self, img, chiave):
        if chiave not in self.cal.zone:
            return None
        return ritaglia(img, self.cal.pixel(chiave, *img.size))

    def _zone_tavolo(self):
        """tavolo_1..5: quelle mancanti si ricavano dalla distanza tra le prime due."""
        z = dict(self.cal.zone)
        if "tavolo_1" in z and "tavolo_2" in z:
            x1, y1, w, h = z["tavolo_1"]
            dx, dy = z["tavolo_2"][0] - x1, z["tavolo_2"][1] - y1
            for i in range(3, 6):
                z.setdefault(f"tavolo_{i}", [x1 + dx * (i - 1), y1 + dy * (i - 1), w, h])
        return z

    def leggi_carta(self, crop, nome, lettura):
        if crop is None or not carta_presente(crop):
            return None
        mask, colore = maschera_carta(crop)
        if mask is None:
            return None
        m = _normalizza(mask, FORMA_CARTA)
        etich, s = self.mod.carta(m, colore)
        if etich is None or s < SOGLIA_CARTA:
            lettura.sconosciuti.append(Sconosciuto("carta", nome, m, crop, colore))
            lettura.incerti.append(nome)
            return "?"
        return etich

    def leggi_numero(self, crop, nome, lettura):
        if crop is None:
            return None
        mask = maschera_testo(crop)
        glifi, riga = segmenta_glifi(mask)
        if not glifi:
            lettura.testi[nome] = ""
            return None
        testo, dubbi = "", False
        for x0, x1 in glifi:
            g = glifo_normalizzato(mask, x0, x1, riga)
            etich, s = self.mod.glifo(g)
            if etich is None or s < SOGLIA_GLIFO:
                dubbi = True
                testo += "?"
                y0, y1 = riga
                lettura.sconosciuti.append(Sconosciuto("glifo", nome, g, crop.crop((x0, y0, x1, y1))))
            elif etich != IGNORA:
                testo += etich
        lettura.testi[nome] = testo
        if dubbi:
            lettura.incerti.append(nome)
            return None
        return interpreta_numero(testo)

    def trova_bottone(self, img):
        """Cerca il gettone del bottone (modello dalla calibrazione) e lo assegna al posto più vicino."""
        if self._modello_bottone is None:
            return None
        g = np.asarray(img.convert("L"), dtype=float)
        t = self._modello_bottone
        th, tw = t.shape
        if th >= g.shape[0] or tw >= g.shape[1]:
            return None
        scala = max(1, int(min(th, tw) // 8))
        g2, t2 = g[::scala, ::scala], t[::scala, ::scala]
        pos, punteggio = _cerca(g2, t2)
        if punteggio < 0.6:
            return None
        cy, cx = (pos[0] + t2.shape[0] / 2) * scala, (pos[1] + t2.shape[1] / 2) * scala
        migliore, dist = None, None
        for k in range(1, self.cal.n_posti + 1):
            chiave = f"posto_{k}"
            if chiave not in self.cal.zone:
                continue
            x, y, w, h = self.cal.pixel(chiave, *img.size)
            d = (x + w / 2 - cx) ** 2 + (y + h / 2 - cy) ** 2
            if dist is None or d < dist:
                migliore, dist = k, d
        return migliore

    def tocca_a_me(self, img):
        """I tuoi tasti compaiono solo quando tocca a te: la zona smette di essere uniforme."""
        crop = self._zona(img, "tasti")
        if crop is None:
            return None
        a = np.asarray(crop.convert("L"), dtype=float)
        return bool(a.std() > 18)

    def leggi(self, img, titolo=""):
        lettura = Lettura()
        if bloccato(titolo):
            lettura.bloccata = True
            lettura.avvisi.append("Sito a soldi veri: il lettore non legge questa finestra.")
            return lettura
        self.mod = self._modelli(tuple(img.size))
        if self.mod.vuoto() and self.mod.altre_dimensioni():
            lettura.avvisi.append(
                f"La finestra ora è {img.size[0]}x{img.size[1]}: le cose che mi hai insegnato valgono per "
                f"{', '.join(self.mod.altre_dimensioni())}. Rimetti la finestra di quella grandezza oppure "
                "insegnami di nuovo a questa.")
        for i in (1, 2):
            c = self.leggi_carta(self._zona(img, f"mia_carta_{i}"), f"mia_carta_{i}", lettura)
            if c:
                lettura.mie_carte.append(c)
        z = self._zone_tavolo()
        for i in range(1, 6):
            if f"tavolo_{i}" in z:
                x, y, w, h = z[f"tavolo_{i}"]
                W, H = img.size
                crop = img.crop((round(x * W), round(y * H), round((x + w) * W), round((y + h) * H)))
                c = self.leggi_carta(crop, f"tavolo_{i}", lettura)
                if c:
                    lettura.tavolo.append(c)
                else:
                    break  # le carte del tavolo escono in ordine: dopo uno spazio vuoto non ce ne sono
        lettura.piatto = self.leggi_numero(self._zona(img, "piatto"), "piatto", lettura)
        for k in range(1, self.cal.n_posti + 1):
            if f"posto_{k}" in self.cal.zone:
                lettura.stack[k] = self.leggi_numero(self._zona(img, f"posto_{k}"), f"posto_{k}", lettura)
            if f"puntata_{k}" in self.cal.zone:
                lettura.puntate[k] = self.leggi_numero(self._zona(img, f"puntata_{k}"), f"puntata_{k}", lettura) or 0.0
                if f"puntata_{k}" in lettura.incerti and not lettura.testi.get(f"puntata_{k}"):
                    lettura.incerti.remove(f"puntata_{k}")
        lettura.bottone = self.trova_bottone(img)
        lettura.tocca_a_me = self.tocca_a_me(img)
        return lettura


def _cerca(immagine, modello):
    """Correlazione normalizzata (FFT): posizione (y, x) e punteggio 0..1 del punto più simile."""
    th, tw = modello.shape
    m = modello - modello.mean()
    nm = np.sqrt((m ** 2).sum()) or 1.0
    H, W = immagine.shape
    fs = (H + th, W + tw)
    corr = np.fft.irfft2(np.fft.rfft2(immagine, fs) * np.conj(np.fft.rfft2(m, fs)), fs)[:H - th + 1, :W - tw + 1]
    # somma e somma dei quadrati delle finestre (immagini integrali)
    ii = np.pad(immagine, ((1, 0), (1, 0))).cumsum(0).cumsum(1)
    ii2 = np.pad(immagine ** 2, ((1, 0), (1, 0))).cumsum(0).cumsum(1)
    def finestra(s):
        return s[th:, tw:] - s[:-th, tw:] - s[th:, :-tw] + s[:-th, :-tw]
    n = th * tw
    somma, somma2 = finestra(ii), finestra(ii2)
    var = np.maximum(somma2 - somma ** 2 / n, 1e-9)
    ncc = corr / (np.sqrt(var) * nm)
    y, x = np.unravel_index(np.argmax(ncc), ncc.shape)
    return (int(y), int(x)), float(ncc[y, x])


def situazione_da_lettura(lettura, bb, n_posti, mio=1):
    """Campi per la finestra del coach (stringhe), da una lettura sicura."""
    occupati = [k for k, v in lettura.stack.items() if v is not None]
    campi = {"carte": " ".join(lettura.mie_carte), "tavolo": " ".join(lettura.tavolo)}
    if lettura.piatto is not None:
        campi["piatto"] = f"{lettura.piatto:g}"
    if lettura.stack.get(mio) is not None:
        campi["stack"] = f"{lettura.stack[mio]:g}"
    if lettura.puntate:
        mia = lettura.puntate.get(mio, 0.0)
        massima = max(lettura.puntate.values())
        campi["pagare"] = f"{max(0.0, massima - mia):g}"
    if len(occupati) >= 2:
        campi["giocatori"] = str(len(occupati))
        if lettura.bottone in occupati and mio in occupati:
            from .tavolo import posizione_da_posti
            try:
                campi["posizione"] = posizione_da_posti(occupati, lettura.bottone, mio)
            except Exception:
                pass
    return campi


def config_lettore(path="annotazioni.json"):
    if os.path.exists(path):
        with open(path, encoding="utf-8") as f:
            return json.load(f).get("lettore_attivo", False)
    return False
