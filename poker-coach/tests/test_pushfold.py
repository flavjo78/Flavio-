import unittest

import numpy as np

from coach import pushfold
from coach.advisor import Situation, advise
from coach.preflop import CLASSI, COMBO

W = np.array([COMBO[c] for c in CLASSI]) / 1326


def percento(valori, S):
    return float(W[np.array(valori) >= S].sum())


class T(unittest.TestCase):
    def test_testa_a_testa_come_da_riferimento(self):
        # Nash HU a 10 bb: push SB ~58%, call BB ~37%
        d = pushfold.dati()["tavoli"]["2"]
        self.assertAlmostEqual(percento(d["push"]["SB"], 10), 0.58, delta=0.02)
        self.assertAlmostEqual(percento(d["call"]["SB"]["BB"], 10), 0.37, delta=0.02)

    def test_piu_giocatori_dietro_piu_stretto(self):
        d = pushfold.dati()["tavoli"]["9"]["push"]
        seq = [percento(d[p], 10) for p in ("UTG", "MP", "CO", "BTN", "SB")]
        self.assertEqual(seq, sorted(seq))

    def test_mani_note(self):
        self.assertGreaterEqual(pushfold.push_max(9, "UTG", "AA"), 25)
        self.assertEqual(pushfold.fmt(pushfold.push_max(9, "UTG", "AA")), "25+")
        self.assertLess(pushfold.push_max(9, "UTG", "72o"), 2)
        self.assertGreater(pushfold.push_max(9, "BTN", "A9o"), pushfold.push_max(9, "UTG", "A9o"))

    def test_stack_equivalente_con_ante(self):
        self.assertEqual(pushfold.stack_equivalente(10, 0, 9), 10)
        self.assertLess(pushfold.stack_equivalente(10, 0.125, 9), 10)

    def test_coach_stack_corto(self):
        a = advise(Situation(hole="Ah 9d", position="CO", bb=100, stack=900, n_players=9), 200)
        self.assertEqual(a.action, "ALL-IN")
        a = advise(Situation(hole="Ah 9d", position="UTG", bb=100, stack=900, n_players=9), 200)
        self.assertEqual(a.action, "FOLD")
        a = advise(Situation(hole="Kc Td", position="BB", bb=100, stack=800, pot=1050, to_call=700,
                             n_players=6, chi_rilancia="BTN"), 200)
        self.assertEqual(a.action, "ALL-IN")
        a = advise(Situation(hole="7c 2d", position="BB", bb=100, stack=800, pot=1050, to_call=700,
                             n_players=6, chi_rilancia="UTG"), 200)
        self.assertEqual(a.action, "FOLD")

    def test_tabella_testo(self):
        t = pushfold.tabella(6, "BTN")
        self.assertIn("PUSH", t)
        self.assertEqual(len(t.splitlines()), 17)


if __name__ == "__main__":
    unittest.main()
