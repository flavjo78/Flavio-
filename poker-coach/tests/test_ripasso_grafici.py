import unittest

from coach.advisor import Situation
from coach.finestra import importo_mossa
from coach.ripasso import analizza, categoria, ev, pagina
from coach.storia import Storia


class T(unittest.TestCase):
    def test_ev(self):
        self.assertEqual(ev("fold", 0, 0.9, 100, 50, 1000), 0)
        self.assertAlmostEqual(ev("call", 0, 0.5, 100, 50, 1000), 0.5 * 150 - 50)
        self.assertGreater(ev("aggressivo", 150, 0.9, 100, 50, 1000), ev("call", 0, 0.9, 100, 50, 1000))
        self.assertLess(ev("call", 0, 0.1, 100, 50, 1000), 0)

    def test_categorie(self):
        self.assertEqual(categoria("call", "fold"), "paghi")
        self.assertEqual(categoria("fold", "call"), "lasci")
        self.assertEqual(categoria("check", "aggressivo"), "passivo")
        self.assertEqual(categoria("aggressivo", "check"), "aggressivo")

    def test_importo(self):
        s = Situation(hole="Ah Kh", pot=100, to_call=40, stack=500, bb=20)
        self.assertEqual(importo_mossa("call", s), 40)
        self.assertEqual(importo_mossa("fold", s), 0)
        self.assertEqual(importo_mossa("allin", s), 500)
        self.assertEqual(importo_mossa("raise", s, 160), 160)
        self.assertEqual(importo_mossa("raise", s), 120)
        self.assertEqual(importo_mossa("raise", s, 900), 500)

    def test_curve_e_pagina(self):
        st = Storia(":memory:")
        p = st.nuova_partita("x")
        for carte, mossa, ris in (("Ah Ad", "raise", 60), ("7c 2d", "call", -20), ("Kh Kd", "fold", -10)):
            m = st.nuova_mano(p, 20, [{"nome": "Io", "eroe": True, "carte": carte, "posizione": "CO",
                                       "stack": 2000}], n_giocatori=6)
            st.azione(m, "Io", "preflop", mossa, 50 if mossa == "raise" else 20, da_pagare=20, piatto=30, avversari=2)
            st.fine_mano(m, {"Io": ris})
        a = analizza(st, p, iters=300)
        self.assertEqual(a["curva_reale"], [0.0, 3.0, 2.0, 1.5])
        self.assertEqual(len(a["curva_coach"]), 4)
        self.assertGreaterEqual(a["coach_bb"], a["risultato_bb"])  # 72o pagato e KK buttato: col coach meglio
        self.assertIn("paghi", a["categorie"])
        self.assertIn("lasci", a["categorie"])
        h = pagina(a)
        for pezzo in ("Risultato mano dopo mano", "fase per fase", "Dove migliorare", "Tipi di errore"):
            self.assertIn(pezzo, h)


if __name__ == "__main__":
    unittest.main()
