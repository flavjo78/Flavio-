"""Ripasso a partita finita: per ogni tua decisione, cosa avrebbe consigliato il coach.

Non dà MAI consigli durante il gioco: lavora solo sulle mani già finite e salvate
nell'archivio (storia.db). Produce una pagina HTML da aprire nel browser.
"""
import html
import os
import time

from .advisor import Situation, advise
from . import tavolo

GRUPPO = {"fold": "fold", "check": "check", "call": "call",
          "bet": "aggressivo", "raise": "aggressivo", "allin": "aggressivo",
          "FOLD": "fold", "CHECK": "check", "CALL": "call",
          "BET": "aggressivo", "RAISE": "aggressivo", "ALL-IN": "aggressivo"}
CARTE_STRADA = {"preflop": 0, "flop": 3, "turn": 4, "river": 5}


def _decisioni_mano(st, mano_id):
    m = st.db.execute("SELECT * FROM mani WHERE id=?", (mano_id,)).fetchone()
    gioc = st.db.execute(
        "SELECT g.nome, mg.* FROM mani_giocatori mg JOIN giocatori g ON g.id=mg.giocatore_id"
        " WHERE mg.mano_id=?", (mano_id,)).fetchall()
    eroe = next((g for g in gioc if g["eroe"]), None)
    if not eroe or not eroe["carte"]:
        return None
    n = len(gioc)
    azioni = st.db.execute(
        "SELECT a.*, g.nome FROM azioni a JOIN giocatori g ON g.id=a.giocatore_id"
        " WHERE a.mano_id=? ORDER BY a.ordine", (mano_id,)).fetchall()
    board = (m["board"] or "").split()
    usciti, messo = set(), 0.0
    out = []
    for a in azioni:
        if a["nome"] == eroe["nome"] and a["tipo"] != "post":
            pos = eroe["posizione"] or "BTN"
            if 2 <= n <= 10 and pos not in tavolo.posizioni(n):
                pos = tavolo.posizioni(n)[-2] if n > 2 else "SB"
            stack = max((eroe["stack"] or 0) - messo, 0) or 1000 * (m["bb"] or 1)
            s = Situation(hole=eroe["carte"], board=board[:CARTE_STRADA[a["strada"]]],
                          pot=a["piatto"] or 0, to_call=a["da_pagare"] or 0, stack=stack,
                          bb=m["bb"] or 1, n_opp=max(1, n - 1 - len(usciti)), position=pos,
                          n_players=max(2, min(n, 10)))
            try:
                c = advise(s, iters=800)
            except Exception as e:  # dati incompleti: lo segnalo invece di inventare
                out.append({"strada": a["strada"], "tua": a["tipo"], "importo": a["importo"],
                            "consiglio": None, "errore": str(e)})
                continue
            tua, sua = GRUPPO.get(a["tipo"], a["tipo"]), GRUPPO.get(c.action, c.action)
            accordo = tua == sua or {tua, sua} == {"fold", "check"}
            grave = (not accordo) and (
                (sua == "fold" and tua in ("call", "aggressivo") and c.equity < c.pot_odds - 0.10) or
                (sua == "aggressivo" and tua == "fold" and c.equity > 0.65))
            out.append({"strada": a["strada"], "tua": a["tipo"], "importo": a["importo"],
                        "consiglio": c, "accordo": accordo, "grave": grave,
                        "board": board[:CARTE_STRADA[a["strada"]]]})
        if a["tipo"] == "fold":
            usciti.add(a["nome"])
        if a["nome"] == eroe["nome"]:
            messo += a["importo"] or 0
    return {"mano": dict(m), "carte": eroe["carte"], "posizione": eroe["posizione"],
            "risultato": eroe["risultato"], "decisioni": out, "giocatori": n}


def analizza(st, partita_id=None):
    if partita_id is None:
        r = st.db.execute("SELECT MAX(id) FROM partite").fetchone()
        partita_id = r[0]
    if partita_id is None:
        return None
    mani = [r[0] for r in st.db.execute("SELECT id FROM mani WHERE partita_id=? ORDER BY numero",
                                        (partita_id,))]
    risultati = [x for x in (_decisioni_mano(st, mid) for mid in mani) if x]
    dec = [d for r in risultati for d in r["decisioni"] if d.get("consiglio")]
    return {"partita": partita_id, "mani": risultati, "n_mani": len(mani), "n_decisioni": len(dec),
            "accordo": sum(d["accordo"] for d in dec) / len(dec) if dec else None,
            "gravi": sum(d["grave"] for d in dec),
            "risultato": sum(r["risultato"] or 0 for r in risultati)}


# ------------------------------------------------------------------ pagina HTML
def _carte(txt):
    out = []
    for c in (txt or "").split():
        rosso = c[-1].lower() in "hd"
        seme = {"h": "♥", "d": "♦", "c": "♣", "s": "♠"}.get(c[-1].lower(), c[-1])
        out.append(f'<span class="c{" r" if rosso else ""}">{html.escape(c[:-1].replace("T", "10"))}{seme}</span>')
    return "".join(out) or '<span class="vuoto">—</span>'


def pagina(analisi, foto=None, titolo="Ripasso della partita"):
    e = html.escape
    a = analisi
    acc = f"{a['accordo']:.0%}" if a["accordo"] is not None else "—"
    righe = []
    for r in a["mani"]:
        dec = []
        for d in r["decisioni"]:
            c = d.get("consiglio")
            if not c:
                dec.append(f'<li class="neutro">{e(d["strada"])}: dati incompleti ({e(d.get("errore", ""))})</li>')
                continue
            stato = "ok" if d["accordo"] else ("grave" if d["grave"] else "diverso")
            etichetta = {"ok": "d'accordo", "diverso": "diverso", "grave": "da rivedere"}[stato]
            tua = d["tua"].upper() + (f' {d["importo"]:g}' if d["importo"] else "")
            sua = c.action + (f" {c.amount:g}" if c.amount else "")
            motivi = "".join(f"<li>{e(m)}</li>" for m in c.reasons)
            dec.append(
                f'<li class="{stato}"><div class="riga"><b>{e(d["strada"])}</b> {_carte(" ".join(d["board"]))}'
                f'<span class="tu">tu: {e(tua)}</span><span class="coach">coach: {e(sua)}</span>'
                f'<span class="badge {stato}">{etichetta}</span></div>'
                f'<div class="num">vincita {c.equity:.0%} · pot odds {c.pot_odds:.0%}</div>'
                f'<ul class="motivi">{motivi}</ul></li>')
        ris = r["risultato"] or 0
        righe.append(
            f'<section class="mano"><h3>Mano {r["mano"]["numero"]} · {_carte(r["carte"])} '
            f'<small>{e(r["posizione"] or "")} · {r["giocatori"]} giocatori · bb {r["mano"]["bb"]:g}</small>'
            f'<span class="ris {"piu" if ris > 0 else "meno" if ris < 0 else ""}">{ris:+g}</span></h3>'
            f'<ul class="dec">{"".join(dec) or "<li class=neutro>nessuna tua decisione registrata</li>"}</ul></section>')
    galleria = ""
    if foto:
        galleria = ('<h2>Foto della partita</h2><div class="gal">' +
                    "".join(f'<a href="{e(f)}" target="_blank"><img src="{e(f)}" loading="lazy" alt=""></a>'
                            for f in foto) + "</div>")
    vuoto = "" if a["mani"] else ('<p class="avviso">Nessuna mano con le tue carte nell\'archivio. Le mani si '
                                  'registreranno da sole quando il programma saprà leggere il tavolo; per ora '
                                  'qui sotto trovi le foto della partita.</p>')
    return f"""<!doctype html><html lang="it"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>{e(titolo)}</title>
<style>
:root{{--bg:#0f1115;--card:#171a21;--txt:#e8ebf0;--mut:#a3acb9;--ok:#6ee7a8;--warn:#ffd27a;--bad:#ff9b9b}}
body{{margin:0;background:var(--bg);color:var(--txt);font:15px/1.5 system-ui,Segoe UI,sans-serif}}
main{{max-width:960px;margin:0 auto;padding:24px 16px}}
h1{{margin:0 0 4px}} .sub{{color:var(--mut);margin:0 0 20px}}
.tot{{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:24px}}
.tot div{{background:var(--card);border-radius:10px;padding:12px}} .tot b{{display:block;font-size:24px}}
.tot span{{color:var(--mut);font-size:13px}}
.mano{{background:var(--card);border-radius:12px;padding:12px 16px;margin-bottom:12px}}
.mano h3{{margin:0 0 8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}} .mano small{{color:var(--mut);font-weight:400}}
.ris{{margin-left:auto}} .piu{{color:var(--ok)}} .meno{{color:var(--bad)}}
.dec{{list-style:none;margin:0;padding:0}} .dec>li{{border-left:3px solid #3a4150;padding:6px 10px;margin:6px 0}}
.dec>li.ok{{border-color:var(--ok)}} .dec>li.diverso{{border-color:var(--warn)}} .dec>li.grave{{border-color:var(--bad)}}
.riga{{display:flex;gap:10px;flex-wrap:wrap;align-items:center}} .num{{color:var(--mut);font-size:13px}}
.motivi{{margin:4px 0 0;padding-left:18px;color:#cfd5de;font-size:14px}}
.badge{{font-size:12px;font-weight:700;padding:1px 8px;border-radius:999px;background:#2a2f3a}}
.badge.ok{{color:var(--ok)}} .badge.diverso{{color:var(--warn)}} .badge.grave{{color:var(--bad)}}
.c{{display:inline-block;background:#fff;color:#111;border-radius:4px;padding:0 5px;margin-right:3px;font-weight:700}}
.c.r{{color:#c81e1e}} .vuoto{{color:var(--mut)}} .avviso{{background:#2b230b;color:#f3e2b3;padding:12px;border-radius:10px}}
.gal{{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px}} .gal img{{width:100%;border-radius:6px}}
</style></head><body><main>
<h1>{e(titolo)}</h1><p class="sub">Partita {a['partita']} · creato il {time.strftime('%d/%m/%Y %H:%M')} · solo a gioco chiuso</p>
<div class="tot"><div><b>{a['n_mani']}</b><span>mani giocate</span></div>
<div><b>{a['n_decisioni']}</b><span>tue decisioni analizzate</span></div>
<div><b>{acc}</b><span>d'accordo con il coach</span></div>
<div><b class="meno">{a['gravi']}</b><span>decisioni da rivedere</span></div>
<div><b class="{'piu' if a['risultato'] > 0 else 'meno'}">{a['risultato']:+g}</b><span>risultato in fiches</span></div></div>
{vuoto}{''.join(righe)}{galleria}
</main></body></html>"""


def salva_pagina(analisi, cartella="ripassi", foto=None):
    os.makedirs(cartella, exist_ok=True)
    path = os.path.join(cartella, f"ripasso_{time.strftime('%Y%m%d_%H%M')}.html")
    rel = [os.path.relpath(f, cartella).replace(os.sep, "/") for f in (foto or [])]
    with open(path, "w", encoding="utf-8") as f:
        f.write(pagina(analisi, rel))
    return path
