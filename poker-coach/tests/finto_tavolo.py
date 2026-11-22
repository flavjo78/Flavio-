"""Un tavolo di poker FINTO disegnato con PIL: serve solo ai test del lettore."""
from PIL import Image, ImageDraw, ImageFont

FONT = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
SEMI = {"h": "♥", "d": "♦", "c": "♣", "s": "♠"}
W, H = 1000, 700
POSTI = [(430, 620), (90, 470), (90, 160), (430, 60), (780, 160), (780, 470)]  # posto 1 = io


def _f(size):
    return ImageFont.truetype(FONT, size)


def zone():
    """Le zone giuste (in pixel) per la calibrazione del tavolo finto."""
    z = {"mia_carta_1": (442, 522, 30, 48), "mia_carta_2": (507, 522, 30, 48), "piatto": (452, 255, 120, 26),
         "tasti": (700, 640, 280, 50), "bottone": (0, 0, 1, 1)}
    for i in range(5):
        z[f"tavolo_{i + 1}"] = (332 + i * 70, 302, 30, 48)
    for k, (x, y) in enumerate(POSTI, 1):
        z[f"posto_{k}"] = (x + 8, y + 26, 110, 22)
        z[f"puntata_{k}"] = _pos_puntata(k, x, y)
    return z


def _pos_puntata(k, x, y):
    if k == 1:
        return (x + 150, y + 12, 80, 22)  # la mia puntata: a destra (sopra ci sono le mie carte)
    return (x + 20, y + (-30 if y > 350 else 56), 80, 22)


def carta(d, x, y, c):
    d.rounded_rectangle([x, y, x + 56, y + 78], 6, fill="white", outline="#999999")
    rosso = c[1] in "hd"
    col = "#c81e1e" if rosso else "#111111"
    d.text((x + 4, y + 1), c[0].replace("T", "10"), font=_f(18), fill=col)
    d.text((x + 5, y + 24), SEMI[c[1]], font=_f(18), fill=col)


def disegna(mie, tavolo, piatto, stack, puntate, tocca=False, bottone=1, scala=1.0):
    img = Image.new("RGB", (W, H), "#14171d")
    d = ImageDraw.Draw(img)
    d.ellipse([80, 120, 920, 600], fill="#1f6b46", outline="#3b2a1e", width=14)
    d.text((456, 258), f"Pot {piatto}", font=_f(17), fill="white")
    for i in range(5):
        if i < len(tavolo):
            carta(d, 330 + i * 70, 300, tavolo[i])
    for k, (x, y) in enumerate(POSTI, 1):
        d.rounded_rectangle([x, y, x + 140, y + 50], 8, fill="#0f1216", outline="#2a2f3a", width=2)
        d.text((x + 10, y + 5), f"Bot{k}" if k > 1 else "Io", font=_f(13), fill="#cccccc")
        if stack.get(k) is not None:
            d.text((x + 10, y + 27), str(stack[k]), font=_f(16), fill="#9ff0c4")
        if puntate.get(k):
            bx, by, _, _ = _pos_puntata(k, x, y)
            d.text((bx + 4, by + 2), str(puntate[k]), font=_f(16), fill="white")
        if k == bottone:
            d.ellipse([x + 118, y - 14, x + 138, y + 6], fill="white", outline="black")
            d.text((x + 123, y - 13), "D", font=_f(13), fill="black")
    for i, c in enumerate(mie):
        carta(d, 440 + i * 65, 520, c)
    if tocca:
        for i, t in enumerate(("Fold", "Call", "Raise")):
            d.rounded_rectangle([710 + i * 90, 645, 790 + i * 90, 685], 6, fill="#a86a12")
            d.text((722 + i * 90, 655), t, font=_f(15), fill="white")
    if scala != 1.0:
        img = img.resize((int(W * scala), int(H * scala)), Image.BILINEAR)
    return img


def zona_bottone(k=1):
    x, y = POSTI[k - 1]
    return (x + 116, y - 16, 24, 24)
