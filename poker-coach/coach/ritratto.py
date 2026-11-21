"""Ritratto a parole di ogni avversario (come se fosse una persona) + segnali che sia un bot."""
import statistics

from treys import Evaluator

from . import preflop
from .equity import hand_class, parse_cards

_ev = Evaluator()

COME_BATTERLO = {
    "sasso": "Rubagli i bui quando passa, ma quando rilancia o punta forte credigli e lascia perdere "
             "le mani medie.",
    "stazione": "Non bluffarlo mai: paga troppo. Punta per valore anche con mani discrete e più grande del solito.",
    "maniaco": "Lascialo puntare: paga più spesso con mani medie e fagli trappole con le mani forti "
               "(check e poi rilancio).",
    "pauroso": "Punta spesso quando nessuno ha mostrato forza: si ritira facilmente. Se invece rilancia lui, rispettalo.",
    "regolare": "Gioca solido: niente trucchi fissi, segui i consigli base e osserva le sue eccezioni.",
}


def _q(st, sql, args):
    return st.db.execute(sql, args).fetchall()


# ------------------------------------------------------------------ bot?
def segnali_bot(st, nome):
    """Ritorna (punteggio 0..100 o None se pochi dati, lista di motivi, lista di segni 'umani')."""
    motivi, umani, punti, prove = [], [], 0, 0

    tempi = [r[0] for r in _q(st, "SELECT a.tempo FROM azioni a JOIN giocatori g ON g.id=a.giocatore_id"
                                  " WHERE g.nome=? AND a.tempo IS NOT NULL AND a.tipo!='post'", (nome,))]
    if len(tempi) >= 20:
        prove += 1
        media = statistics.mean(tempi)
        cv = statistics.pstdev(tempi) / media if media > 0 else 0
        if cv < 0.15:
            punti += 35
            motivi.append(f"i tempi di decisione sono quasi identici (in media {media:.1f}s, variazione {cv:.0%})")
        elif cv > 0.45:
            umani.append("i tempi di decisione cambiano molto, come per una persona")
        if media < 0.7:
            punti += 25
            motivi.append(f"decide in meno di un secondo ({media:.2f}s di media)")

        facili = [r[0] for r in _q(st, "SELECT a.tempo FROM azioni a JOIN giocatori g ON g.id=a.giocatore_id"
                                       " WHERE g.nome=? AND a.tempo IS NOT NULL AND a.strada='preflop'"
                                       " AND a.tipo='fold'", (nome,))]
        difficili = [r[0] for r in _q(st, "SELECT a.tempo FROM azioni a JOIN giocatori g ON g.id=a.giocatore_id"
                                          " WHERE g.nome=? AND a.tempo IS NOT NULL AND a.strada!='preflop'"
                                          " AND a.da_pagare>0", (nome,))]
        if len(facili) >= 5 and len(difficili) >= 5:
            rapporto = statistics.mean(difficili) / max(statistics.mean(facili), 1e-9)
            if rapporto < 1.15:
                punti += 15
                motivi.append("ci mette lo stesso tempo per un fold banale e per una decisione difficile")
            elif rapporto > 1.8:
                umani.append("riflette di più sulle decisioni difficili")

    frazioni = [round(r[0] / r[1] * 20) / 20 for r in _q(
        st, "SELECT a.importo, a.piatto FROM azioni a JOIN giocatori g ON g.id=a.giocatore_id"
            " WHERE g.nome=? AND a.tipo IN ('bet','raise') AND a.strada!='preflop' AND a.piatto>0"
            " AND a.importo>0", (nome,))]
    if len(frazioni) >= 15:
        prove += 1
        diversi = len(set(frazioni))
        if diversi <= 2:
            punti += 15
            motivi.append(f"punta sempre la stessa frazione del piatto ({', '.join(f'{x:.0%}' for x in sorted(set(frazioni)))})")
        elif diversi >= 6:
            umani.append("varia molto la grandezza delle puntate")

    orari = sorted(r[0] for r in _q(st, "SELECT m.ts FROM mani m JOIN mani_giocatori mg ON mg.mano_id=m.id"
                                        " JOIN giocatori g ON g.id=mg.giocatore_id WHERE g.nome=?", (nome,)))
    if len(orari) >= 50:
        prove += 1
        piu_lunga, inizio = 0, orari[0]
        for a, b in zip(orari, orari[1:]):
            if b - a > 600:  # pausa di oltre 10 minuti
                inizio = b
            piu_lunga = max(piu_lunga, b - inizio)
        if piu_lunga > 4 * 3600:
            punti += 15
            motivi.append(f"ha giocato {piu_lunga / 3600:.1f} ore di fila senza una pausa di 10 minuti")

    if prove == 0:
        return None, [], []
    return min(punti, 100), motivi, umani


def verdetto_bot(punti):
    if punti is None:
        return "non posso ancora dirlo (servono almeno 20 decisioni cronometrate)"
    if punti >= 60:
        return f"quasi certamente un BOT ({punti}/100)"
    if punti >= 35:
        return f"possibile bot ({punti}/100)"
    return f"si comporta come una persona ({punti}/100)"


# ------------------------------------------------------------------ ritratto
def _mostrate(st, nome):
    """Media della forza delle mani mostrate + quante volte ha bluffato al river."""
    righe = st.mani_mostrate(nome, 500)
    perc, bluff = [], 0
    for r in righe:
        try:
            carte = parse_cards(r["carte"])
            perc.append(preflop.percentile(hand_class(carte)))
            board = parse_cards(r["board"]) if r["board"] else []
            if len(board) == 5 and any(a.startswith("river:bet") or a.startswith("river:raise") for a in r["azioni"]):
                if _ev.get_rank_class(_ev.evaluate(board, carte)) >= 8:  # coppia o niente
                    bluff += 1
        except Exception:
            continue
    return perc, bluff


def descrivi(st, nome):
    s = st.statistiche(nome)
    if not s or not s["mani"]:
        return f"{nome}: non l'ho mai visto giocare."
    n = s["mani"]
    out = [f"=== {nome} ({n} mani osservate) ==="]
    if n < 30:
        out.append(f"Ho visto solo {n} mani: è una prima impressione, prendila con cautela.")

    v, p, af, fold = s["VPIP"], s["PFR"], s["AF"], s["fold_a_puntata"]
    if v < 0.18:
        out.append(f"Gioca pochissime mani ({v:.0%}): è paziente e aspetta le carte buone.")
    elif v < 0.28:
        out.append(f"Sceglie le mani con giudizio ({v:.0%}), come un giocatore solido.")
    elif v < 0.40:
        out.append(f"Entra in parecchie mani ({v:.0%}): gli piace giocare.")
    else:
        out.append(f"Gioca quasi tutto ({v:.0%}): vuole stare in ogni piatto.")
    if v > 0:
        r = p / v
        if r > 0.7:
            out.append("Quando entra lo fa quasi sempre rilanciando: ama prendere l'iniziativa.")
        elif r < 0.35:
            out.append("Entra spesso solo pagando, senza rilanciare: prima del flop è passivo.")
    if af > 2.5:
        out.append(f"Dopo il flop è molto aggressivo (aggressività {af:.1f}): punta e rilancia più che pagare.")
    elif af < 1:
        out.append(f"Dopo il flop preferisce pagare che puntare (aggressività {af:.1f}): "
                   "quando punta lui, di solito ha qualcosa.")
    if fold is not None:
        if fold > 0.6:
            out.append(f"Si arrende facilmente quando gli punti contro ({fold:.0%} delle volte).")
        elif fold < 0.3:
            out.append(f"È difficile farlo ritirare ({fold:.0%}): con lui i bluff non funzionano.")

    perc, bluff = _mostrate(st, nome)
    if perc:
        m = statistics.mean(perc)
        out.append(f"Le mani che ha mostrato a fine mano ({len(perc)}) erano in media tra il {m:.0%} migliore.")
    if bluff:
        out.append(f"L'ho visto puntare al river con una mano debole {bluff} volte: sa bluffare.")
    if n >= 30:
        bb = s["bb_100"]
        out.append(f"Risultato: {'sta vincendo' if bb > 0 else 'sta perdendo'} {abs(bb):.1f} big blind ogni 100 mani.")
    if s["tipo"]:
        out.append(f"In sintesi: tipo «{s['tipo']}». {COME_BATTERLO[s['tipo']]}")
    if s["note"]:
        out.append(f"Tua nota: {s['note']}")

    punti, motivi, umani = segnali_bot(st, nome)
    out.append(f"È un bot? {verdetto_bot(punti)}")
    out += [f"  ⚠ {m}" for m in motivi]
    out += [f"  ✓ {u}" for u in umani]
    return "\n".join(out)


def riepilogo(st):
    nomi = st.avversari()
    if not nomi:
        return "Nessun avversario in archivio."
    parti, bot, forse = [], [], []
    for nome in nomi:
        parti.append(descrivi(st, nome))
        punti = segnali_bot(st, nome)[0]
        if punti is not None and punti >= 60:
            bot.append(nome)
        elif punti is not None and punti >= 35:
            forse.append(nome)
    testa = [f"Avversari in archivio: {len(nomi)}"]
    testa.append("Probabili bot: " + (", ".join(bot) if bot else "nessuno"))
    if forse:
        testa.append("Da tenere d'occhio: " + ", ".join(forse))
    return "\n".join(testa) + "\n\n" + "\n\n".join(parti)
