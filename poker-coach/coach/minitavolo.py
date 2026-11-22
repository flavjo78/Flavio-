"""Mini-tavolo «Cosa vedo»: ridisegna il tavolo come lo ha capito il coach, per confrontarlo col gioco."""
import math

from .registro import StatoTavolo

SEMI = {"h": "♥", "d": "♦", "c": "♣", "s": "♠"}


def da_lettura(l, n_posti, hero=1):
    """Lettura dello schermo -> StatoTavolo (così il disegno è lo stesso per file e schermo)."""
    posti = {}
    for k in range(1, n_posti + 1):
        stack = l.stack.get(k)
        posti[k] = {"nome": "Io" if k == hero else f"Posto {k}", "stack": stack,
                    "puntata": l.puntate.get(k) or 0.0, "carte": " ".join(l.mie_carte) if k == hero else "",
                    "in_mano": stack is not None}
    return StatoTavolo(bottone=l.bottone, hero=hero, tocca_a=hero if l.tocca_a_me else None,
                       board=list(l.tavolo), piatto=l.piatto or 0.0, posti=posti)


def _carta(canvas, x, y, c, w=20, h=26, font=9):
    rosso = len(c) == 2 and c[1].lower() in "hd"
    canvas.create_rectangle(x, y, x + w, y + h, fill="#ffffff" if c != "?" else "#3a2a0b", outline="#888888")
    testo = "?" if c == "?" else c[0].replace("T", "10") + SEMI.get(c[1].lower(), c[1]) if len(c) == 2 else c
    canvas.create_text(x + w / 2, y + h / 2, text=testo, fill="#c81e1e" if rosso else "#111111",
                       font=("Segoe UI", font, "bold"))


def disegna(canvas, st, w, h):
    canvas.delete("all")
    canvas.create_rectangle(0, 0, w, h, fill="#0f1216", outline="#2a2f3a")
    if st is None:
        canvas.create_text(w / 2, h / 2, text="Nessun dato dal gioco", fill="#a3acb9", font=("Segoe UI", 9))
        return
    cx, cy = w / 2, h / 2
    rx, ry = min(w * 0.36, w / 2 - 26 - 38), h * 0.30  # i posti devono restare dentro il disegno
    canvas.create_oval(cx - rx, cy - ry, cx + rx, cy + ry, fill="#1f6b46", outline="#3b2a1e", width=4)
    # carte sul tavolo e piatto
    n_b = len(st.board)
    for i, c in enumerate(st.board):
        _carta(canvas, cx - n_b * 11 + i * 22, cy - 8, c, 20, 26)
    canvas.create_text(cx, cy - 18, text=f"Piatto {st.piatto:g}", fill="#ffffff", font=("Segoe UI", 8, "bold"))
    # posti in cerchio: io in basso, gli altri in senso orario
    numeri = sorted(st.posti) or [st.hero]
    n = max(max(numeri), len(numeri))
    for k in numeri:
        p = st.posti[k]
        i = (k - st.hero) % n
        t = math.pi / 2 + 2 * math.pi * i / n
        sx, sy = cx + (rx + 26) * math.cos(t), cy + (ry + 22) * math.sin(t)
        vivo = p.get("in_mano") and p.get("stack") is not None
        turno = st.tocca_a == k
        bordo = "#ffd27a" if turno else ("#7cc4ff" if k == st.hero else "#2a2f3a")
        canvas.create_rectangle(sx - 34, sy - 13, sx + 34, sy + 13, fill="#1a1e26" if vivo else "#12151b",
                                outline=bordo, width=2 if turno else 1)
        nome = (p.get("nome") or f"Posto {k}")[:9]
        stack = "vuoto" if p.get("stack") is None else f"{float(p['stack']):g}"
        col = "#e8ebf0" if vivo else "#6b7280"
        canvas.create_text(sx, sy - 5, text=nome, fill=col, font=("Segoe UI", 7, "bold"))
        canvas.create_text(sx, sy + 6, text=stack, fill="#9ff0c4" if vivo else "#6b7280", font=("Segoe UI", 7))
        if p.get("puntata"):
            bx, by = cx + (rx - 18) * math.cos(t) * 0.78, cy + (ry - 10) * math.sin(t) * 0.70
            canvas.create_text(bx, by, text=f"{float(p['puntata']):g}", fill="#ffd27a", font=("Segoe UI", 8, "bold"))
        if st.bottone == k:
            canvas.create_oval(sx + 26, sy - 22, sx + 38, sy - 10, fill="#ffffff", outline="#000000")
            canvas.create_text(sx + 32, sy - 16, text="D", fill="#000000", font=("Segoe UI", 6, "bold"))
        if k == st.hero and p.get("carte"):
            for j, c in enumerate(p["carte"].split()[:2]):
                _carta(canvas, sx - 22 + j * 23, sy - 44, c, 21, 28, 9)
