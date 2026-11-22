import os
import tempfile
import unittest

from coach import annotazioni, schermo
from coach.ripasso import analizza, pagina
from coach.storia import Storia


def partita_finta(st):
    p = st.nuova_partita("prova")
    gioc = lambda carte: [
        {"nome": "Io", "posizione": "BTN", "stack": 2000, "eroe": True, "carte": carte},
        {"nome": "Bot1", "posizione": "SB", "stack": 2000},
        {"nome": "Bot2", "posizione": "BB", "stack": 2000}]
    # 1) AA aperto dal bottone: d'accordo
    m = st.nuova_mano(p, 20, gioc("Ah As"))
    st.azione(m, "Io", "preflop", "raise", 50, da_pagare=20, piatto=30)
    st.azione(m, "Bot1", "preflop", "fold", da_pagare=50)
    st.azione(m, "Bot2", "preflop", "fold", da_pagare=50)
    st.fine_mano(m, {"Io": 30, "Bot1": -10, "Bot2": -20})
    # 2) AA buttato via: da rivedere
    m = st.nuova_mano(p, 20, gioc("Kh Kd"))
    st.board(m, "Kc 7d 2s")
    st.azione(m, "Io", "preflop", "call", 20, da_pagare=20, piatto=30)
    st.azione(m, "Bot2", "flop", "bet", 40, piatto=60)
    st.azione(m, "Io", "flop", "fold", da_pagare=40, piatto=100)
    st.fine_mano(m, {"Io": -20, "Bot2": 20})
    return p


class T(unittest.TestCase):
    def test_ripasso(self):
        st = Storia(":memory:")
        p = partita_finta(st)
        a = analizza(st, p)
        self.assertEqual(a["n_mani"], 2)
        self.assertEqual(a["n_decisioni"], 3)
        prima = a["mani"][0]["decisioni"][0]
        self.assertTrue(prima["accordo"])
        fold_set = a["mani"][1]["decisioni"][-1]
        self.assertFalse(fold_set["accordo"])
        self.assertTrue(fold_set["grave"])
        self.assertEqual(a["gravi"], 1)
        h = pagina(a, ["../sessioni/x/00001.jpg"])
        self.assertIn("da rivedere", h)
        self.assertIn("00001.jpg", h)

    def test_interruttore_e_gioco_aperto(self):
        cfg = {"suggerimenti": False, "ultima": {"titolo": "Tavolo Poker", "handle": None,
                                                 "cartella": "x", "fine": "oggi"}}
        ok, motivo = annotazioni.puo_rivedere(cfg)
        self.assertFalse(ok)
        self.assertIn("SPENTI", motivo)
        cfg["suggerimenti"] = True
        orig = schermo.trova
        try:
            schermo.trova = lambda t: schermo.Finestra("Tavolo Poker", 1, 0, 0, 800, 600)
            ok, motivo = annotazioni.puo_rivedere(cfg)
            self.assertFalse(ok)
            self.assertIn("ancora aperto", motivo)
            schermo.trova = lambda t: None
            self.assertTrue(annotazioni.puo_rivedere(cfg)[0])
        finally:
            schermo.trova = orig

    def test_rivedi_crea_pagina(self):
        with tempfile.TemporaryDirectory() as d:
            cwd = os.getcwd()
            os.chdir(d)
            orig = schermo.trova
            try:
                schermo.trova = lambda t: None
                st = Storia("storia.db")
                p = partita_finta(st)
                st.db.close()
                cfg = {"suggerimenti": True, "ultima": {"titolo": "Tavolo", "handle": None, "partita": p,
                                                       "cartella": "sessioni/manca", "fine": "oggi"}}
                path = annotazioni.rivedi(cfg, apri=False)
                self.assertTrue(os.path.exists(path))
            finally:
                schermo.trova = orig
                os.chdir(cwd)


if __name__ == "__main__":
    unittest.main()
