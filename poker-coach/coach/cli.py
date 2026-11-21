import argparse
from .advisor import Situation, advise, TIPI


def main():
    ap = argparse.ArgumentParser(prog="coach", description="Coach di poker (Texas Hold'em)")
    ap.add_argument("--hole", required=True, help='le tue 2 carte, es. "Ah Kd"')
    ap.add_argument("--board", default="", help='carte sul tavolo, es. "7c 8d 2s" (vuoto = preflop)')
    ap.add_argument("--pot", type=float, default=0, help="piatto totale (inclusa la puntata che affronti)")
    ap.add_argument("--call", type=float, default=0, help="quanto devi pagare (0 = puoi passare)")
    ap.add_argument("--stack", type=float, default=1000, help="il tuo stack")
    ap.add_argument("--bb", type=float, default=1, help="big blind")
    ap.add_argument("--opp", type=int, default=1, help="avversari ancora in mano")
    ap.add_argument("--pos", default="BTN", help="UTG, MP, CO, BTN, SB, BB")
    ap.add_argument("--tipo", choices=TIPI, help="tipo dell'avversario, se lo conosci")
    a = ap.parse_args()
    s = Situation(hole=a.hole, board=a.board, pot=a.pot, to_call=a.call, stack=a.stack,
                  bb=a.bb, n_opp=a.opp, position=a.pos.upper(), opp_type=a.tipo)
    print(advise(s))
