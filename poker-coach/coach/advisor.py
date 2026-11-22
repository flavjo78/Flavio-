"""Il 'cervello' del coach: data una situazione, consiglia l'azione e spiega perché.

Convenzione: `pot` = totale al centro PRIMA della tua mossa, INCLUSA la puntata
che stai affrontando. `to_call` = quanto devi mettere per pareggiare (0 se puoi
passare). Tutte le cifre nella stessa unità (fiches).
"""
from dataclasses import dataclass, field
from typing import List, Optional

from . import preflop, pushfold, tavolo
from .equity import equity, count_outs, hand_class, made_hand_name, parse_cards

TIPI = ("sasso", "stazione", "maniaco", "pauroso", "regolare")


@dataclass
class Situation:
    hole: list
    board: list = field(default_factory=list)
    pot: float = 0.0
    to_call: float = 0.0
    stack: float = 1000.0
    bb: float = 1.0
    n_opp: int = 1
    position: str = "BTN"
    opp_type: Optional[str] = None  # uno di TIPI, se conosciuto
    n_players: int = 6              # giocatori seduti al tavolo (2..10)
    ante: float = 0.0               # ante pagato da ogni giocatore
    stack_eff: Optional[float] = None  # stack effettivo (il minore tra il tuo e quello di chi ti sfida)
    chi_rilancia: Optional[str] = None  # posizione di chi ha rilanciato / è andato all-in


@dataclass
class Advice:
    action: str           # FOLD / CHECK / CALL / BET / RAISE / ALL-IN
    amount: float = 0.0   # quanto puntare/rilanciare (totale)
    equity: float = 0.0
    pot_odds: float = 0.0
    outs: int = 0
    reasons: List[str] = field(default_factory=list)

    def __str__(self):
        a = self.action + (f" {self.amount:g}" if self.amount else "")
        lines = [f">>> {a}"]
        lines.append(f"    equity {self.equity:.0%}  |  pot odds {self.pot_odds:.0%}"
                     + (f"  |  out di scala/colore {self.outs}" if self.outs else ""))
        lines += [f"    - {r}" for r in self.reasons]
        return "\n".join(lines)


def _street(board):
    return {0: "preflop", 3: "flop", 4: "turn", 5: "river"}[len(board)]


def advise(s: Situation, iters: int = 3000) -> Advice:
    hole, board = parse_cards(s.hole), parse_cards(s.board)
    if len(hole) != 2 or len(board) not in (0, 3, 4, 5):
        raise ValueError("Servono 2 carte tue e 0/3/4/5 sul tavolo")
    if s.position not in tavolo.posizioni(s.n_players):
        raise ValueError(f"Con {s.n_players} giocatori le posizioni sono: " + ", ".join(tavolo.posizioni(s.n_players)))
    if not board:
        return _preflop(s, hole, iters)
    return _postflop(s, hole, board, iters)


# ---------------------------------------------------------------- PREFLOP
PUSHFOLD_SOTTO = 15  # sotto questi big blind effettivi si gioca solo all-in o fold


def _chi_rilancia(s):
    """Posizione di chi ha rilanciato; se non la so, suppongo il giocatore subito prima di me."""
    pos = tavolo.posizioni(s.n_players)
    if s.chi_rilancia and s.chi_rilancia in pos and pos.index(s.chi_rilancia) < pos.index(s.position):
        return s.chi_rilancia, False
    i = pos.index(s.position)
    return (pos[i - 1] if i > 0 else None), True


def _preflop(s, hole, iters):
    cls = hand_class(hole)
    p = preflop.percentile(cls)
    eq = equity(hole, [], s.n_opp, iters)
    po = s.to_call / (s.pot + s.to_call) if s.to_call else 0.0
    adv = Advice("FOLD", equity=eq, pot_odds=po)
    eff = (s.stack_eff if s.stack_eff else s.stack) / s.bb
    eff_eq = pushfold.stack_equivalente(eff, s.ante / s.bb, s.n_players)
    adv.reasons.append(f"{cls}: tra il {p:.0%} migliore delle mani · {eff:.0f} bb effettivi · "
                       f"tavolo da {s.n_players}, sei {s.position}")
    if s.ante:
        adv.reasons.append(f"con l'ante il piatto è più ricco: è come avere {eff_eq:.1f} bb")
    unopened = s.to_call <= s.bb  # nessun rilancio davanti a te (al massimo limp)
    gratis = "CHECK" if s.to_call == 0 else "FOLD"

    # ---- stack corto: tabelle push/fold di Nash
    if eff_eq <= PUSHFOLD_SOTTO:
        if unopened:
            if s.position == "BB":
                adv.action = "CHECK"
                adv.reasons.append("big blind senza rilanci: vedi il flop gratis")
                return adv
            m = pushfold.push_max(s.n_players, s.position, cls)
            if eff_eq <= m:
                adv.action, adv.amount = "ALL-IN", s.stack
                adv.reasons.append(f"tabella push/fold: da {s.position} con {cls} si va all-in fino a "
                                   f"{pushfold.fmt(m)} bb, tu ne hai {eff_eq:.1f}")
            else:
                adv.action = gratis
                adv.reasons.append(f"tabella push/fold: da {s.position} {cls} va all-in solo fino a "
                                   f"{pushfold.fmt(m)} bb, tu ne hai {eff_eq:.1f}: passa")
            return adv
        chi, supposto = _chi_rilancia(s)
        if chi is None:
            adv.action = gratis
            return adv
        m = pushfold.call_max(s.n_players, chi, s.position, cls)
        if supposto:
            adv.reasons.append(f"non so chi ha rilanciato: suppongo {chi}")
        all_in = s.to_call >= s.stack * 0.9
        if eff_eq <= m:
            adv.action, adv.amount = ("CALL", 0) if all_in and s.to_call < s.stack else ("ALL-IN", s.stack)
            cosa = "pagare l'all-in" if all_in else "rispondere all-in (re-push)"
            adv.reasons.append(f"tabella push/fold: contro {chi} con {cls} conviene {cosa} fino a "
                               f"{pushfold.fmt(m)} bb, tu ne hai {eff_eq:.1f}")
        else:
            adv.action = "FOLD"
            adv.reasons.append(f"tabella push/fold: contro {chi} {cls} si gioca solo fino a "
                               f"{pushfold.fmt(m)} bb, tu ne hai {eff_eq:.1f}: passa")
        return adv

    # ---- stack profondo
    if unopened:
        top = tavolo.apri_top(s.n_players, s.position)
        if s.position == "BB":
            if p < 0.10:
                adv.action, adv.amount = "RAISE", round(3.5 * s.bb, 2)
                adv.reasons.append("mano forte in big blind: rilancia per valore")
            else:
                adv.action = "CHECK"
                adv.reasons.append("big blind senza rilancio davanti: vedi il flop gratis")
        elif p < top:
            adv.action, adv.amount = "RAISE", round(2.5 * s.bb, 2)
            adv.reasons.append(f"con {s.n_players} giocatori da {s.position} si apre col {top:.0%} delle mani: "
                               f"questa ci sta, rilancia 2.5 bb")
        else:
            adv.action = gratis
            adv.reasons.append(f"con {s.n_players} giocatori da {s.position} si apre solo col {top:.0%} "
                               f"delle mani: troppo debole, passa")
        return adv

    # Davanti c'è un rilancio
    raise_size = s.to_call
    stretto = 1.0 if s.n_players <= 6 else 0.8  # tavoli pieni: rilanci più credibili
    if p < 0.04:
        adv.action, adv.amount = "RAISE", round(min(s.stack, 3 * raise_size), 2)
        adv.reasons.append("mano premium: rilancia (3-bet) per valore")
    else:
        if s.n_players == 2:
            call_top = 0.45
        elif s.position == "BB" and raise_size <= 3.5 * s.bb:
            call_top = 0.28 * stretto
        else:
            call_top = 0.10 * stretto
        if p < call_top:
            adv.action = "CALL"
            adv.reasons.append("mano giocabile contro un rilancio: paga")
            if s.position == "BB":
                adv.reasons.append("in big blind hai già una parte della puntata dentro")
        else:
            adv.action = "FOLD"
            adv.reasons.append("troppo debole contro un rilancio: passa")
    if s.opp_type == "maniaco" and adv.action == "FOLD" and p < 0.18:
        adv.action = "CALL"
        adv.reasons.append("l'avversario rilancia troppo spesso (maniaco): allarga e paga")
    if s.opp_type == "sasso" and adv.action == "CALL" and p > 0.06:
        adv.action = "FOLD"
        adv.reasons.append("l'avversario rilancia solo con mani forti (sasso): rispetta il rilancio")
    return adv


# ---------------------------------------------------------------- POSTFLOP
def _opp_top(s):
    """Quanto è forte il range di chi ha puntato (puntata grande => range stretto)."""
    r = s.to_call / max(s.pot - s.to_call, 1e-9)
    top = max(0.25, 0.58 - 0.22 * r)
    if s.opp_type == "sasso":
        top *= 0.6
    elif s.opp_type == "maniaco":
        top = min(0.9, top * 1.6)
    return top


def _postflop(s, hole, board, iters):
    street = _street(board)
    facing = s.to_call > 0
    po = s.to_call / (s.pot + s.to_call) if facing else 0.0
    opp_top = _opp_top(s) if facing else None
    eq = equity(hole, board, s.n_opp, iters, opp_top=opp_top)
    outs = count_outs(hole, board)
    adv = Advice("CHECK", equity=eq, pot_odds=po, outs=outs)
    adv.reasons.append(f"{street}: hai {made_hand_name(hole, board)}")
    if facing:
        adv.reasons.append(
            f"stimo il range di chi ha puntato al migliore {opp_top:.0%} delle mani "
            f"(puntata = {s.to_call / max(s.pot - s.to_call, 1e-9):.0%} del piatto)")
    multiway = s.n_opp > 1

    if not facing:
        strong = 0.62 + 0.04 * (s.n_opp - 1)
        if eq >= strong:
            frac = 0.75 if s.opp_type == "stazione" else 0.66
            adv.action, adv.amount = "BET", round(min(s.stack, s.pot * frac), 2)
            adv.reasons.append("mano forte: punta per valore" + (
                " (contro una stazione di chiamata punta più grande)" if frac == 0.75 else ""))
        elif eq >= 0.50 and not multiway:
            adv.action, adv.amount = "BET", round(min(s.stack, s.pot * 0.33), 2)
            adv.reasons.append("mano discreta: piccola puntata di valore/protezione")
        elif outs >= 8 and street != "river" and not multiway:
            adv.action, adv.amount = "BET", round(min(s.stack, s.pot * 0.5), 2)
            adv.reasons.append(f"hai un progetto ({outs} out): semi-bluff, puoi vincere subito o completare")
        elif s.opp_type == "pauroso" and eq < 0.40 and street != "river" and not multiway:
            adv.action, adv.amount = "BET", round(min(s.stack, s.pot * 0.5), 2)
            adv.reasons.append("l'avversario foldа spesso (pauroso): bluff")
        else:
            adv.reasons.append("niente di abbastanza buono per puntare: passa (check)")
        return adv

    margin = 0.03 - (0.04 if s.opp_type == "maniaco" else 0.0)
    if eq >= 0.78:
        amt = min(s.stack, round(3 * s.to_call, 2))
        adv.action, adv.amount = ("ALL-IN", s.stack) if amt >= s.stack else ("RAISE", amt)
        adv.reasons.append("molto avanti anche contro un range forte: rilancia")
    elif eq >= po + margin:
        adv.action = "CALL"
        adv.reasons.append(f"equity {eq:.0%} > pot odds {po:.0%}: pagare conviene")
    elif outs >= 8 and street != "river" and eq >= po - 0.05:
        adv.action = "CALL"
        adv.reasons.append(f"{outs} out: le odds implicite (quello che vinci se completi) giustificano il call")
    else:
        adv.action = "FOLD"
        adv.reasons.append(f"equity {eq:.0%} < pot odds {po:.0%}: nel lungo periodo si perde, passa")
    if adv.action in ("CALL", "RAISE", "ALL-IN") and s.to_call >= s.stack:
        adv.action, adv.amount = "ALL-IN", s.stack
        adv.reasons.append("la puntata copre tutto il tuo stack")
    return adv
