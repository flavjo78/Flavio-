"""Ripasso a partita finita: le tue giocate rivissute, cosa avrebbe fatto il coach, grafici.

Lavora solo su mani già finite e salvate (storia.db): dal diario della finestra del coach
(dal vivo o in ripasso) e, in futuro, dalla lettura automatica del tavolo.

Il confronto dei risultati è una STIMA: per ogni decisione si calcola il valore atteso
(EV) della tua mossa e di quella del coach con un modello semplice (probabilità di vincere,
piatto, puntate, un po' di "fold equity" per chi punta). Non è quello che sarebbe successo
davvero, ma dice dove hai lasciato più valore sul tavolo.
"""
import html
import json
import os
import time

from . import tavolo
from .advisor import Situation, advise

GRUPPO = {"fold": "fold", "check": "check", "call": "call",
          "bet": "aggressivo", "raise": "aggressivo", "allin": "aggressivo",
          "FOLD": "fold", "CHECK": "check", "CALL": "call",
          "BET": "aggressivo", "RAISE": "aggressivo", "ALL-IN": "aggressivo"}
CARTE_STRADA = {"preflop": 0, "flop": 3, "turn": 4, "river": 5}
STRADE = ("preflop", "flop", "turn", "river")
FOLD_EQUITY = 0.30  # quante volte, in media, una puntata fa lasciare l'avversario

CATEGORIE = {
    "paghi": ("Paghi troppo", "Confronta la probabilità di vincere con le pot odds: se è più bassa, lascia."),
    "aggressivo": ("Troppo aggressivo", "Punta o rilancia quando sei avanti o hai un progetto forte; "
                                        "con mani deboli passa o lascia."),
    "lasci": ("Lasci troppo presto", "Se la probabilità di vincere supera le pot odds, pagare conviene "
                                     "anche quando fa paura."),
    "passivo": ("Troppo passivo", "Con le mani forti punta tu: fai pagare gli avversari invece di aspettare."),
}


def categoria(tua, sua):
    if tua == "call" and sua == "fold":
        return "paghi"
    if tua == "aggressivo" and sua in ("fold", "check", "call"):
        return "aggressivo"
    if tua == "fold" and sua in ("call", "aggressivo"):
        return "lasci"
    if tua in ("call", "check") and sua == "aggressivo":
        return "passivo"
    if tua == "check" and sua == "call":
        return "passivo"
    return "aggressivo" if tua == "aggressivo" else "lasci"


def ev(gruppo, importo, eq, pot, to_call, stack):
    """Valore atteso (in fiches, da ora in poi) di una mossa. Modello semplice e dichiarato."""
    if gruppo == "fold":
        return 0.0
    if gruppo == "check":
        return eq * pot
    if gruppo == "call":
        paga = min(to_call, stack)
        return eq * (pot + paga) - paga
    a = min(max(importo or 0, to_call), stack) or (3 * to_call if to_call else 0.66 * pot)
    pagata = eq * (pot + 2 * a - to_call) - a
    f = FOLD_EQUITY if a < stack else FOLD_EQUITY * 0.8
    return f * pot + (1 - f) * pagata


def _decisioni_mano(st, mano_id, iters=800):
    m = st.db.execute("SELECT * FROM mani WHERE id=?", (mano_id,)).fetchone()
    gioc = st.db.execute(
        "SELECT g.nome, mg.* FROM mani_giocatori mg JOIN giocatori g ON g.id=mg.giocatore_id"
        " WHERE mg.mano_id=?", (mano_id,)).fetchall()
    eroe = next((g for g in gioc if g["eroe"]), None)
    if not eroe or not eroe["carte"]:
        return None
    n = m["giocatori"] or len(gioc)
    n = max(2, min(n, 10))
    bb = m["bb"] or 1
    azioni = st.db.execute(
        "SELECT a.*, g.nome FROM azioni a JOIN giocatori g ON g.id=a.giocatore_id"
        " WHERE a.mano_id=? ORDER BY a.ordine", (mano_id,)).fetchall()
    board = (m["board"] or "").split()
    usciti, messo = set(), 0.0
    out = []
    for a in azioni:
        if a["nome"] == eroe["nome"] and a["tipo"] != "post":
            pos = eroe["posizione"] or "BTN"
            if pos not in tavolo.posizioni(n):
                pos = tavolo.posizioni(n)[-2] if n > 2 else "SB"
            stack = max((eroe["stack"] or 0) - messo, 0) or 100 * bb
            avv = a["avversari"] or max(1, len(gioc) - 1 - len(usciti)) if len(gioc) > 1 else (a["avversari"] or 1)
            s = Situation(hole=eroe["carte"], board=board[:CARTE_STRADA[a["strada"]]],
                          pot=a["piatto"] or 0, to_call=a["da_pagare"] or 0, stack=stack, bb=bb,
                          n_opp=max(1, min(avv, n - 1)), position=pos, n_players=n, ante=m["ante"] or 0)
            try:
                c = advise(s, iters=iters)
            except Exception as e:  # dati incompleti: lo dico invece di inventare
                out.append({"strada": a["strada"], "tua": a["tipo"], "importo": a["importo"],
                            "consiglio": None, "errore": str(e)})
                continue
            tua, sua = GRUPPO.get(a["tipo"], a["tipo"]), GRUPPO.get(c.action, c.action)
            accordo = tua == sua or {tua, sua} == {"fold", "check"}
            ev_tua = ev(tua, a["importo"], c.equity, s.pot, s.to_call, s.stack)
            ev_sua = ev(sua, c.amount, c.equity, s.pot, s.to_call, s.stack)
            diff = 0.0 if accordo else (ev_sua - ev_tua) / bb
            cat = None if accordo else categoria(tua, sua)
            grave = (not accordo) and diff >= 3
            out.append({"strada": a["strada"], "tua": a["tipo"], "importo": a["importo"],
                        "consiglio": c, "accordo": accordo, "grave": grave, "diff_bb": diff,
                        "categoria": cat, "board": board[:CARTE_STRADA[a["strada"]]],
                        "visto": a["consiglio"]})
        if a["tipo"] == "fold":
            usciti.add(a["nome"])
        if a["nome"] == eroe["nome"]:
            messo += a["importo"] or 0
    return {"mano": dict(m), "carte": eroe["carte"], "posizione": eroe["posizione"],
            "risultato": eroe["risultato"] or 0, "risultato_bb": (eroe["risultato"] or 0) / bb,
            "decisioni": out, "giocatori": n, "diff_bb": sum(d.get("diff_bb", 0) for d in out)}


def analizza(st, partita_id=None, iters=800):
    if partita_id is None:
        partita_id = st.db.execute("SELECT MAX(id) FROM partite").fetchone()[0]
    if partita_id is None:
        return None
    mani = [r[0] for r in st.db.execute("SELECT id FROM mani WHERE partita_id=? ORDER BY numero",
                                        (partita_id,))]
    risultati = [x for x in (_decisioni_mano(st, mid, iters) for mid in mani) if x]
    dec = [d for r in risultati for d in r["decisioni"] if d.get("consiglio")]

    per_strada = {}
    for s_ in STRADE:
        ds = [d for d in dec if d["strada"] == s_]
        if ds:
            per_strada[s_] = (sum(d["accordo"] for d in ds) / len(ds), len(ds))
    per_pos = {}
    for r in risultati:
        for d in r["decisioni"]:
            if d.get("consiglio"):
                per_pos.setdefault(r["posizione"] or "?", []).append(d["accordo"])
    per_pos = {k: (sum(v) / len(v), len(v)) for k, v in per_pos.items()}
    cat = {}
    for d in dec:
        if d["categoria"]:
            n, bb = cat.get(d["categoria"], (0, 0.0))
            cat[d["categoria"]] = (n + 1, bb + d["diff_bb"])

    reale, coach, x = [0.0], [0.0], 0.0
    for r in risultati:
        reale.append(reale[-1] + r["risultato_bb"])
        x += r["diff_bb"]
        coach.append(reale[-1] + x)
    peggiori = sorted(((d["diff_bb"], r, d) for r in risultati for d in r["decisioni"]
                       if d.get("consiglio") and not d["accordo"]), key=lambda t: -t[0])[:5]
    return {"partita": partita_id, "mani": risultati, "n_mani": len(mani), "n_decisioni": len(dec),
            "accordo": sum(d["accordo"] for d in dec) / len(dec) if dec else None,
            "gravi": sum(d["grave"] for d in dec),
            "risultato": sum(r["risultato"] for r in risultati),
            "risultato_bb": reale[-1], "coach_bb": coach[-1],
            "per_strada": per_strada, "per_posizione": per_pos, "categorie": cat,
            "curva_reale": reale, "curva_coach": coach, "peggiori": peggiori}


# ------------------------------------------------------------------ grafici (SVG, senza internet)
def _fmt_bb(x):
    return f"{x:+.1f}".replace(".", ",") + " bb"


def grafico_linee(reale, coach, w=880, h=280):
    n = len(reale) - 1
    if n < 1:
        return ""
    pl, pr, pt, pb = 52, 150, 16, 34
    lo, hi = min(reale + coach + [0]), max(reale + coach + [0])
    if hi - lo < 1:
        hi, lo = hi + 0.5, lo - 0.5
    marg = (hi - lo) * 0.08
    lo, hi = lo - marg, hi + marg
    X = lambda i: pl + (w - pl - pr) * i / n
    Y = lambda v: pt + (h - pt - pb) * (hi - v) / (hi - lo)
    # griglia: 4 tacche "tonde"
    import math
    passo = (hi - lo) / 4
    mag = 10 ** math.floor(math.log10(passo)) if passo > 0 else 1
    passo = min((mag * k for k in (1, 2, 2.5, 5, 10) if mag * k >= passo), default=passo)
    tacche, v = [], math.ceil(lo / passo) * passo
    while v <= hi + 1e-9:
        tacche.append(v)
        v += passo
    g = []
    for t in tacche:
        g.append(f'<line x1="{pl}" x2="{w - pr}" y1="{Y(t):.1f}" y2="{Y(t):.1f}" class="{"zero" if abs(t) < 1e-9 else "grid"}"/>'
                 f'<text x="{pl - 8}" y="{Y(t) + 4:.1f}" class="ax" text-anchor="end">{t:g}</text>')
    passo_x = max(1, round(n / 8))
    for i in range(0, n + 1, passo_x):
        g.append(f'<text x="{X(i):.1f}" y="{h - 12}" class="ax" text-anchor="middle">{i}</text>')
    pts = lambda serie: " ".join(f"{X(i):.1f},{Y(v):.1f}" for i, v in enumerate(serie))
    fine_r, fine_c = Y(reale[-1]), Y(coach[-1])
    if abs(fine_r - fine_c) < 16:  # etichette che si toccherebbero: le allontano
        mid = (fine_r + fine_c) / 2
        fine_r, fine_c = (mid - 9, mid + 9) if reale[-1] >= coach[-1] else (mid + 9, mid - 9)
    dati = json.dumps([[i, round(reale[i], 2), round(coach[i], 2)] for i in range(n + 1)])
    return f'''<figure class="viz"><figcaption><b>Risultato mano dopo mano</b>
<span class="leg"><i class="sw s1"></i>Reale <i class="sw s2 tratteggio"></i>Seguendo il coach (stima)</span></figcaption>
<svg viewBox="0 0 {w} {h}" class="linee" data-punti='{dati}' data-x0="{pl}" data-x1="{w - pr}" role="img"
 aria-label="Andamento cumulato in big blind: reale {_fmt_bb(reale[-1])}, seguendo il coach {_fmt_bb(coach[-1])}">
{"".join(g)}
<text x="{w - pr + 10}" y="{h - 12}" class="ax">mani</text>
<polyline points="{pts(coach)}" class="l s2 tratteggio"/>
<polyline points="{pts(reale)}" class="l s1"/>
<circle cx="{X(n):.1f}" cy="{Y(coach[-1]):.1f}" r="4" class="pt s2"/>
<circle cx="{X(n):.1f}" cy="{Y(reale[-1]):.1f}" r="4" class="pt s1"/>
<text x="{X(n) + 10:.1f}" y="{fine_r + 4:.1f}" class="lab">Reale {_fmt_bb(reale[-1])}</text>
<text x="{X(n) + 10:.1f}" y="{fine_c + 4:.1f}" class="lab">Coach ≈ {_fmt_bb(coach[-1])}</text>
<line class="croce" x1="0" x2="0" y1="{pt}" y2="{h - pb}" visibility="hidden"/>
<rect class="hit" x="{pl}" y="{pt}" width="{w - pl - pr}" height="{h - pt - pb}"/>
</svg></figure>'''


def grafico_barre(titolo, righe, w=880, unita="%"):
    """righe: [(etichetta, valore 0..1 o numero, testo a destra, tooltip)] - una sola serie."""
    if not righe:
        return ""
    alto, pl, pr = 30, 150, 250  # a destra c'è spazio per il testo del valore
    h = alto * len(righe) + 8
    massimo = 1.0 if unita == "%" else max(v for _, v, _, _ in righe) or 1
    g = []
    for i, (etich, v, testo, tip) in enumerate(righe):
        y = 4 + i * alto
        lw = max(2.0, (w - pl - pr) * v / massimo)
        g.append(f'<g class="barra" data-tip="{html.escape(tip)}"><title>{html.escape(tip)}</title>'
                 f'<rect class="hitbar" x="0" y="{y}" width="{w}" height="{alto}"/>'
                 f'<text x="{pl - 10}" y="{y + 19}" class="lab" text-anchor="end">{html.escape(etich)}</text>'
                 f'<rect x="{pl}" y="{y + 6}" width="{w - pl - pr}" height="16" class="traccia" rx="4"/>'
                 f'<rect x="{pl}" y="{y + 6}" width="{lw:.1f}" height="16" class="s1f" rx="4"/>'
                 f'<text x="{pl + lw + 8:.1f}" y="{y + 19}" class="val">{html.escape(testo)}</text></g>')
    return (f'<figure class="viz"><figcaption><b>{html.escape(titolo)}</b></figcaption>'
            f'<svg viewBox="0 0 {w} {h}" role="img" aria-label="{html.escape(titolo)}">{"".join(g)}</svg></figure>')


# ------------------------------------------------------------------ pagina
def _carte(txt):
    out = []
    for c in (txt or "").split():
        rosso = c[-1].lower() in "hd"
        seme = {"h": "♥", "d": "♦", "c": "♣", "s": "♠"}.get(c[-1].lower(), c[-1])
        out.append(f'<span class="c{" r" if rosso else ""}">{html.escape(c[:-1].replace("T", "10"))}{seme}</span>')
    return "".join(out) or '<span class="vuoto">—</span>'


_STILE = """
.viz-root{color-scheme:light;--bg:#f6f6f3;--surface-1:#fcfcfb;--card:#ffffff;--bordo:#e4e3de;
 --text-primary:#0b0b0b;--text-secondary:#52514e;--grid:#e9e8e3;--axis:#bdbcb5;
 --series-1:#2a78d6;--series-2:#eb6834;--good:#006300;--warn:#8a5a00;--bad:#b42323;--carta:#ffffff}
@media (prefers-color-scheme: dark){:root:where(:not([data-theme="light"])) .viz-root{color-scheme:dark;
 --bg:#0f1012;--surface-1:#1a1a19;--card:#1a1a19;--bordo:#2e2e2b;--text-primary:#f0efec;--text-secondary:#c3c2b7;
 --grid:#2b2b28;--axis:#55554f;--series-1:#3987e5;--series-2:#d95926;--good:#6ee7a8;--warn:#ffd27a;--bad:#ff9b9b}}
:root[data-theme="dark"] .viz-root{color-scheme:dark;--bg:#0f1012;--surface-1:#1a1a19;--card:#1a1a19;--bordo:#2e2e2b;
 --text-primary:#f0efec;--text-secondary:#c3c2b7;--grid:#2b2b28;--axis:#55554f;--series-1:#3987e5;--series-2:#d95926;
 --good:#6ee7a8;--warn:#ffd27a;--bad:#ff9b9b}
body{margin:0} .viz-root{background:var(--bg);color:var(--text-primary);font:15px/1.5 system-ui,"Segoe UI",sans-serif;min-height:100vh}
main{max-width:960px;margin:0 auto;padding:24px 16px}
h1{margin:0 0 4px;font-size:26px} h2{font-size:18px;margin:28px 0 10px} .sub{color:var(--text-secondary);margin:0 0 20px}
.tot{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:8px}
.tot div{background:var(--card);border:1px solid var(--bordo);border-radius:10px;padding:12px}
.tot b{display:block;font-size:26px;font-variant-numeric:tabular-nums} .tot span{color:var(--text-secondary);font-size:13px}
.nota{color:var(--text-secondary);font-size:13px;margin:6px 0 0}
.viz{margin:0 0 16px;background:var(--surface-1);border:1px solid var(--bordo);border-radius:12px;padding:12px 12px 6px;position:relative}
.viz figcaption{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:6px}
.viz svg{width:100%;height:auto;display:block;overflow:visible}
.leg{color:var(--text-secondary);font-size:13px;display:flex;gap:6px;align-items:center}
.sw{display:inline-block;width:18px;height:0;border-top:2px solid;margin-left:8px}
.sw.s1{border-color:var(--series-1)} .sw.s2{border-color:var(--series-2);border-top-style:dashed}
.grid{stroke:var(--grid);stroke-width:1} .zero{stroke:var(--axis);stroke-width:1}
.ax{fill:var(--text-secondary);font-size:12px} .lab{fill:var(--text-primary);font-size:13px}
.val{fill:var(--text-secondary);font-size:13px;font-variant-numeric:tabular-nums}
.l{fill:none;stroke-width:2;stroke-linejoin:round;stroke-linecap:round} .l.s1{stroke:var(--series-1)} .l.s2{stroke:var(--series-2)}
.tratteggio.l{stroke-dasharray:6 4} .pt{stroke:var(--surface-1);stroke-width:2} .pt.s1{fill:var(--series-1)} .pt.s2{fill:var(--series-2)}
.croce{stroke:var(--axis);stroke-width:1} .hit,.hitbar{fill:transparent}
.traccia{fill:var(--grid)} .s1f{fill:var(--series-1)} .barra:hover .s1f{opacity:.85}
.tip{position:absolute;pointer-events:none;background:var(--card);color:var(--text-primary);border:1px solid var(--bordo);
 border-radius:8px;padding:6px 10px;font-size:13px;box-shadow:0 4px 14px rgba(0,0,0,.18);white-space:nowrap;display:none;z-index:2}
.consigli{list-style:none;padding:0;margin:0;display:grid;gap:8px}
.consigli li{background:var(--card);border:1px solid var(--bordo);border-radius:10px;padding:10px 12px}
.consigli b{display:block}
.mano{background:var(--card);border:1px solid var(--bordo);border-radius:12px;padding:12px 16px;margin-bottom:12px}
.mano h3{margin:0 0 8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:16px}
.mano small{color:var(--text-secondary);font-weight:400} .ris{margin-left:auto;font-variant-numeric:tabular-nums}
.piu{color:var(--good)} .meno{color:var(--bad)}
.dec{list-style:none;margin:0;padding:0} .dec>li{border-left:3px solid var(--axis);padding:6px 10px;margin:6px 0}
.dec>li.ok{border-color:var(--good)} .dec>li.diverso{border-color:var(--warn)} .dec>li.grave{border-color:var(--bad)}
.riga{display:flex;gap:10px;flex-wrap:wrap;align-items:center} .num{color:var(--text-secondary);font-size:13px}
.motivi{margin:4px 0 0;padding-left:18px;color:var(--text-secondary);font-size:14px}
.badge{font-size:12px;font-weight:700;padding:1px 8px;border-radius:999px;border:1px solid var(--bordo)}
.badge.ok{color:var(--good)} .badge.diverso{color:var(--warn)} .badge.grave{color:var(--bad)}
.c{display:inline-block;background:#fff;color:#111;border:1px solid #d8d8d8;border-radius:4px;padding:0 5px;margin-right:3px;font-weight:700}
.c.r{color:#c81e1e} .vuoto{color:var(--text-secondary)}
.avviso{background:var(--card);border:1px solid var(--warn);padding:12px;border-radius:10px}
.gal{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px} .gal img{width:100%;border-radius:6px}
a{color:var(--series-1)}
"""

_SCRIPT = """
document.querySelectorAll('.viz').forEach(function(fig){
  var tip=document.createElement('div');tip.className='tip';fig.appendChild(tip);
  var svg=fig.querySelector('svg.linee');
  function mostra(t,ev){tip.innerHTML=t;tip.style.display='block';var r=fig.getBoundingClientRect();
    var x=ev.clientX-r.left+14,y=ev.clientY-r.top-10;if(x>r.width-200)x=ev.clientX-r.left-200;tip.style.left=x+'px';tip.style.top=y+'px';}
  if(svg){var p=JSON.parse(svg.dataset.punti),x0=+svg.dataset.x0,x1=+svg.dataset.x1,c=svg.querySelector('.croce'),
    hit=svg.querySelector('.hit'),n=p.length-1;
    function f(v){return (v>=0?'+':'')+v.toFixed(1).replace('.',',')+' bb'}
    hit.addEventListener('mousemove',function(ev){var pt=svg.createSVGPoint();pt.x=ev.clientX;pt.y=ev.clientY;
      var q=pt.matrixTransform(svg.getScreenCTM().inverse()),i=Math.round((q.x-x0)/(x1-x0)*n);i=Math.max(0,Math.min(n,i));
      var xx=x0+(x1-x0)*i/n;c.setAttribute('x1',xx);c.setAttribute('x2',xx);c.setAttribute('visibility','visible');
      mostra('<b>Dopo la mano '+p[i][0]+'</b><br>Reale: '+f(p[i][1])+'<br>Seguendo il coach: '+f(p[i][2]),ev);});
    hit.addEventListener('mouseleave',function(){c.setAttribute('visibility','hidden');tip.style.display='none';});}
  fig.querySelectorAll('.barra').forEach(function(b){b.addEventListener('mousemove',function(ev){mostra(b.dataset.tip,ev);});
    b.addEventListener('mouseleave',function(){tip.style.display='none';});});
});
"""


def pagina(analisi, foto=None, titolo="Ripasso della partita"):
    e = html.escape
    a = analisi
    acc = f"{a['accordo']:.0%}" if a["accordo"] is not None else "—"
    # --- grafici
    grafici = []
    if a["mani"]:
        grafici.append(grafico_linee(a["curva_reale"], a["curva_coach"]))
    nomi_strada = {"preflop": "Prima del flop", "flop": "Flop", "turn": "Turn", "river": "River"}
    grafici.append(grafico_barre("Quanto hai giocato come il coach, fase per fase", [
        (nomi_strada[s], v, f"{v:.0%} · {n} " + ("decisione" if n == 1 else "decisioni"),
         f"{nomi_strada[s]}: d'accordo {v:.0%} su {n} " + ("decisione" if n == 1 else "decisioni"))
        for s, (v, n) in a["per_strada"].items()]))
    ordine_pos = ["UTG", "UTG+1", "UTG+2", "MP", "MP+1", "HJ", "CO", "BTN", "SB", "BB"]
    grafici.append(grafico_barre("Per posizione al tavolo", [
        (p, v, f"{v:.0%} · {n}", f"{p}: d'accordo {v:.0%} su {n} decisioni")
        for p, (v, n) in sorted(a["per_posizione"].items(),
                                key=lambda kv: ordine_pos.index(kv[0]) if kv[0] in ordine_pos else 99)]))
    cat = sorted(a["categorie"].items(), key=lambda kv: -kv[1][1])
    if cat:
        def _txt(n, bb):
            return f"{n} volte" + (f" · circa {bb:.1f} bb persi".replace(".", ",") if bb > 0.05 else
                                   " · nessuna perdita stimata")
        grafici.append(grafico_barre("Tipi di errore: quante volte (e quanto sono costati, stima)", [
            (CATEGORIE[k][0], n, _txt(n, bb), CATEGORIE[k][0] + ": " + _txt(n, bb))
            for k, (n, bb) in cat], unita="volte"))
    consigli = "".join(f"<li><b>{e(CATEGORIE[k][0])} ({n} volte)</b>{e(CATEGORIE[k][1])}</li>"
                       for k, (n, bb) in cat[:3] if bb > 0.05 or n >= 3)
    peggiori = "".join(
        f'<li><b><a href="#mano-{r["mano"]["numero"]}">Mano {r["mano"]["numero"]}</a> · {e(d["strada"])} · '
        f'circa {d["diff_bb"]:.1f} bb'.replace(".", ",") +
        f'</b>tu: {e(d["tua"].upper())} — coach: {e(d["consiglio"].action)} '
        f'(vincita {d["consiglio"].equity:.0%}, pot odds {d["consiglio"].pot_odds:.0%})</li>'
        for _, r, d in a["peggiori"] if _ > 0.05)

    # --- mani
    righe = []
    for r in a["mani"]:
        dec = []
        for d in r["decisioni"]:
            c = d.get("consiglio")
            if not c:
                dec.append(f'<li>{e(d["strada"])}: dati incompleti ({e(d.get("errore", ""))})</li>')
                continue
            stato = "ok" if d["accordo"] else ("grave" if d["grave"] else "diverso")
            etichetta = {"ok": "d'accordo", "diverso": "diverso", "grave": "da rivedere"}[stato]
            tua = d["tua"].upper() + (f' {d["importo"]:g}' if d["importo"] else "")
            sua = c.action + (f" {c.amount:g}" if c.amount else "")
            costo = "" if d["accordo"] else f' · ti è costata circa {d["diff_bb"]:.1f} bb'.replace(".", ",")
            visto = f' · dal vivo ti aveva detto: {e(d["visto"])}' if d.get("visto") else ""
            motivi = "".join(f"<li>{e(m)}</li>" for m in c.reasons)
            dec.append(
                f'<li class="{stato}"><div class="riga"><b>{e(d["strada"])}</b> {_carte(" ".join(d["board"]))}'
                f'<span>tu: <b>{e(tua)}</b></span><span>coach: <b>{e(sua)}</b></span>'
                f'<span class="badge {stato}">{etichetta}</span></div>'
                f'<div class="num">vincita {c.equity:.0%} · pot odds {c.pot_odds:.0%}{costo}{visto}</div>'
                f'<ul class="motivi">{motivi}</ul></li>')
        ris = r["risultato_bb"]
        righe.append(
            f'<section class="mano" id="mano-{r["mano"]["numero"]}"><h3>Mano {r["mano"]["numero"]} · {_carte(r["carte"])} '
            f'<small>{e(r["posizione"] or "")} · {r["giocatori"]} giocatori · bb {r["mano"]["bb"]:g}</small>'
            f'<span class="ris {"piu" if ris > 0 else "meno" if ris < 0 else ""}">{_fmt_bb(ris)}</span></h3>'
            f'<ul class="dec">{"".join(dec) or "<li>nessuna tua decisione registrata</li>"}</ul></section>')
    galleria = ""
    if foto:
        galleria = ('<h2>Foto della partita</h2><div class="gal">' +
                    "".join(f'<a href="{e(f)}" target="_blank"><img src="{e(f)}" loading="lazy" alt=""></a>'
                            for f in foto) + "</div>")
    vuoto = "" if a["mani"] else ('<p class="avviso">Nessuna mano con le tue carte in questa partita. Le mani '
                                  'arrivano dal diario della finestra del coach (tasti «Ho fatto») e, in futuro, '
                                  'dalla lettura automatica del tavolo.</p>')
    diff = a["coach_bb"] - a["risultato_bb"]
    return f"""<!doctype html><html lang="it"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>{e(titolo)}</title><style>{_STILE}</style></head>
<body><div class="viz-root"><main>
<h1>{e(titolo)}</h1><p class="sub">Partita {a['partita']} · creato il {time.strftime('%d/%m/%Y %H:%M')}</p>
<div class="tot"><div><b>{a['n_mani']}</b><span>mani giocate</span></div>
<div><b>{acc}</b><span>decisioni come il coach ({a['n_decisioni']} analizzate)</span></div>
<div><b class="{'piu' if a['risultato_bb'] >= 0 else 'meno'}">{_fmt_bb(a['risultato_bb'])}</b><span>il tuo risultato</span></div>
<div><b>{_fmt_bb(a['coach_bb'])}</b><span>seguendo il coach (stima)</span></div>
<div><b class="meno">{a['gravi']}</b><span>decisioni da rivedere</span></div></div>
<p class="nota">Il confronto con il coach è una stima: valore atteso di ogni scelta (probabilità di vincere, piatto,
puntate, {FOLD_EQUITY:.0%} di fold per chi punta), non quello che sarebbe successo davvero.
{"Seguendo il coach avresti guadagnato circa " + _fmt_bb(diff)[1:] + " in più." if diff > 0.05 else ""}</p>
{vuoto}
{"<h2>Grafici</h2>" + "".join(grafici) if a["mani"] else ""}
{"<h2>Dove migliorare</h2><ul class='consigli'>" + consigli + "</ul>" if consigli else ""}
{"<h2>Le decisioni che sono costate di più</h2><ul class='consigli'>" + peggiori + "</ul>" if peggiori else ""}
{"<h2>Tutte le tue giocate</h2>" + "".join(righe) if righe else ""}
{galleria}
</main></div><script>{_SCRIPT}</script></body></html>"""


def salva_pagina(analisi, cartella="ripassi", foto=None):
    os.makedirs(cartella, exist_ok=True)
    path = os.path.join(cartella, f"ripasso_{time.strftime('%Y%m%d_%H%M%S')}.html")
    rel = [os.path.relpath(f, cartella).replace(os.sep, "/") for f in (foto or [])]
    with open(path, "w", encoding="utf-8") as f:
        f.write(pagina(analisi, rel))
    return path
