import unittest
from coach.equity import equity, parse_cards
from coach.advisor import Situation, advise
from coach.opponents import OpponentTracker


class T(unittest.TestCase):
    def test_equity_note(self):
        # valori noti: AA vs 1 mano a caso ~85%, 72o ~35%
        self.assertAlmostEqual(equity(parse_cards("Ah As"), [], 1, 6000, seed=1), 0.85, delta=0.03)
        self.assertAlmostEqual(equity(parse_cards("7c 2d"), [], 1, 6000, seed=1), 0.35, delta=0.03)

    def test_preflop(self):
        r = advise(Situation(hole="Ah As", position="UTG", bb=10, stack=1500), 500)
        self.assertEqual(r.action, "RAISE")
        r = advise(Situation(hole="7c 2d", position="UTG", bb=10, stack=1500, to_call=10), 500)
        self.assertEqual(r.action, "FOLD")
        r = advise(Situation(hole="Qh Qs", position="BTN", bb=10, stack=1500, pot=55, to_call=25), 500)
        self.assertEqual(r.action, "RAISE")

    def test_postflop(self):
        # set sul flop, nessuno ha puntato: puntare per valore
        r = advise(Situation(hole="8h 8d", board="8c Ks 2d", pot=100, to_call=0), 1500)
        self.assertEqual(r.action, "BET")
        # niente contro una puntata enorme: fold
        r = advise(Situation(hole="Ac 3d", board="Ks Qd 9h", pot=150, to_call=120), 1500)
        self.assertEqual(r.action, "FOLD")

    def test_tracker(self):
        t = OpponentTracker(min_hands=5)
        for _ in range(10):
            t.new_hand(["bot1"])
            t.action("bot1", "raise", preflop=True)
            t.action("bot1", "bet")
        self.assertEqual(t.classify("bot1"), "maniaco")
        t2 = OpponentTracker(min_hands=5)
        for _ in range(10):
            t2.new_hand(["bot2"])
            t2.action("bot2", "fold", preflop=True)
        self.assertEqual(t2.classify("bot2"), "sasso")


if __name__ == "__main__":
    unittest.main()
