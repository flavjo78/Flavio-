import argparse
from .advisor import Situation, advise, TIPI


def main():
    ap = argparse.ArgumentParser(prog="coach", description="Coach di poker (Texas Hold'em)")
    ap.add_argument("--hole", required=True, help='le tue 2 carte, es. "Ah Kd"')
    ap.add_argument("--board", default="", help='carte sul tavolo, es. "7c 8d 2s" (vuoto = preflop)')
    ap.add_argument("--pot", type=float, default=0, help="piatto totale (inclusa la puntata che affronti)")
    ap.add_argument("--call", type=float, default=0, help="quanto devi pagare (0 = puoi passare)")
    ap.add_argument("--stack", type=float, default=1000, help="il tuo stack")
    ap.add_argument("--bb", type=float, help="big blind (default: quello di partita.json, se c'è)")
    ap.add_argument("--ante", type=float, help="ante (default: quello di partita.json)")
    ap.add_argument("--giocatori", type=int, help="giocatori seduti al tavolo (default: partita.json, se no 6)")
    ap.add_argument("--stack-eff", type=float, help="stack effettivo, se l'avversario ha meno fiches di te")
    ap.add_argument("--rilancia", help="posizione di chi ha rilanciato o è andato all-in")
    ap.add_argument("--opp", type=int, default=1, help="avversari ancora in mano")
    ap.add_argument("--pos", default="BTN", help="UTG, MP, CO, BTN, SB, BB")
    ap.add_argument("--tipo", choices=TIPI, help="tipo dell'avversario, se lo conosci")
    ap.add_argument("--avversario", help="nome dell'avversario: il tipo lo prende dall'archivio")
    ap.add_argument("--db", default="storia.db", help="file dell'archivio mani")
    a = ap.parse_args()
    if a.avversario and not a.tipo:
        from .storia import Storia
        a.tipo = Storia(a.db).tipo(a.avversario)
        print(f"(archivio: {a.avversario} = {a.tipo or 'pochi dati, uso la strategia base'})")
    from .partita import carica
    partita = carica()
    if partita:
        print("(partita.json: " + partita.riassunto() + ")")
    bb = a.bb if a.bb is not None else (partita.bb if partita else 1)
    ante = a.ante if a.ante is not None else (partita.ante if partita else 0)
    giocatori = a.giocatori or (partita.giocatori if partita else 6)
    s = Situation(hole=a.hole, board=a.board, pot=a.pot, to_call=a.call, stack=a.stack,
                  bb=bb, n_opp=a.opp, position=a.pos.upper(), opp_type=a.tipo, n_players=giocatori,
                  ante=ante, stack_eff=a.stack_eff, chi_rilancia=a.rilancia.upper() if a.rilancia else None)
    print(advise(s))
