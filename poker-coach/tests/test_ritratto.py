import random
import unittest
from coach.storia import Storia
from coach.ritratto import segnali_bot, descrivi, riepilogo


def popola(st, nome, bot, mani=80, seed=0):
    rng = random.Random(seed)
    p = st.nuova_partita("prova")
    t = 1_000_000.0
    for i in range(mani):
        t += 40 if bot else rng.choice([40, 60, 90, 900])  # l'umano ogni tanto fa pause
        m = st.nuova_mano(p, 10, [{"nome": "Io", "eroe": True}, {"nome": nome, "posizione": "BB"}], ts=t)
        tempo = (lambda: 0.4) if bot else (lambda: rng.uniform(1, 12))
        if i % 3 == 0:
            st.azione(m, nome, "preflop", "fold", da_pagare=15, tempo=tempo(), ts=t)
            continue
        st.azione(m, nome, "preflop", "raise", 30, da_pagare=15, tempo=tempo(), ts=t)
        frazione = 0.5 if bot else rng.choice([0.3, 0.45, 0.6, 0.75, 1.0, 1.3, 0.9])
        st.azione(m, nome, "flop", "bet", 60 * frazione, piatto=60, tempo=tempo(), ts=t)
        st.azione(m, nome, "turn", "call", 50, da_pagare=50,
                  tempo=tempo() * (1 if bot else 2.5), ts=t)
        st.fine_mano(m, {nome: 20, "Io": -20})


class T(unittest.TestCase):
    def test_bot_e_umano(self):
        st = Storia(":memory:")
        popola(st, "Robot", bot=True)
        popola(st, "Mario", bot=False)
        pb, motivi, _ = segnali_bot(st, "Robot")
        pu, _, umani = segnali_bot(st, "Mario")
        self.assertGreaterEqual(pb, 60, motivi)
        self.assertLess(pu, 35)
        self.assertTrue(umani)
        testo = riepilogo(st)
        self.assertIn("Probabili bot: Robot", testo)
        self.assertNotIn("Io (", testo)  # l'eroe non viene descritto
        self.assertIn("È un bot?", descrivi(st, "Mario"))

    def test_pochi_dati(self):
        st = Storia(":memory:")
        popola(st, "Nuovo", bot=True, mani=5)
        self.assertIsNone(segnali_bot(st, "Nuovo")[0])
        self.assertIn("prima impressione", descrivi(st, "Nuovo"))


if __name__ == "__main__":
    unittest.main()
