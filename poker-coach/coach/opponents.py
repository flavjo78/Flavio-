"""Studio degli avversari: statistiche per giocatore e classificazione del tipo."""
from collections import defaultdict


class _Stats:
    def __init__(self):
        self.hands = 0
        self.vpip = 0          # mani in cui ha messo soldi volontariamente prima del flop
        self.pfr = 0           # mani in cui ha rilanciato prima del flop
        self.aggr = 0          # puntate+rilanci dopo il flop
        self.calls = 0         # call dopo il flop
        self.faced = 0         # volte che ha affrontato una puntata dopo il flop
        self.folds = 0         # volte che ha foldato davanti a una puntata
        self._seen_vpip = False
        self._seen_pfr = False


class OpponentTracker:
    """Registra le azioni e dice che tipo di giocatore è ognuno.

    Uso: new_hand([nomi]) all'inizio di ogni mano, poi action(nome, tipo, ...).
    """

    def __init__(self, min_hands=15):
        self.stats = defaultdict(_Stats)
        self.min_hands = min_hands

    def new_hand(self, players):
        for p in players:
            st = self.stats[p]
            st.hands += 1
            st._seen_vpip = st._seen_pfr = False

    def action(self, player, kind, preflop=False, faced_bet=False):
        """kind: 'fold' | 'check' | 'call' | 'bet' | 'raise'."""
        st = self.stats[player]
        if preflop:
            if kind in ("call", "bet", "raise") and not st._seen_vpip:
                st.vpip += 1
                st._seen_vpip = True
            if kind in ("bet", "raise") and not st._seen_pfr:
                st.pfr += 1
                st._seen_pfr = True
            return
        if kind in ("bet", "raise"):
            st.aggr += 1
        elif kind == "call":
            st.calls += 1
        if faced_bet:
            st.faced += 1
            if kind == "fold":
                st.folds += 1

    def profile(self, player):
        st = self.stats[player]
        if st.hands == 0:
            return {}
        return {
            "mani": st.hands,
            "VPIP": st.vpip / st.hands,
            "PFR": st.pfr / st.hands,
            "AF": st.aggr / max(st.calls, 1),
            "fold_a_puntata": st.folds / st.faced if st.faced else None,
        }

    def classify(self, player):
        """Ritorna 'sasso', 'stazione', 'maniaco', 'pauroso', 'regolare' o None (pochi dati)."""
        pr = self.profile(player)
        if not pr or pr["mani"] < self.min_hands:
            return None
        vpip, af, fold = pr["VPIP"], pr["AF"], pr["fold_a_puntata"]
        if vpip > 0.40 and af >= 1.5:
            return "maniaco"
        if vpip > 0.40:
            return "stazione"
        if vpip < 0.18:
            return "sasso"
        if fold is not None and fold > 0.60:
            return "pauroso"
        return "regolare"
