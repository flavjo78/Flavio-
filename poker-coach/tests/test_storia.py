import unittest
from coach.storia import Storia


def gioca(st, p, tipo_bot):
    m = st.nuova_mano(p, bb=10, giocatori=[
        {"nome": "Io", "posto": 1, "posizione": "BTN", "stack": 1000, "eroe": True, "carte": "Ah Kd"},
        {"nome": "Bot", "posto": 2, "posizione": "BB", "stack": 1000}])
    st.azione(m, "Io", "preflop", "raise", 25)
    if tipo_bot == "maniaco":
        st.azione(m, "Bot", "preflop", "raise", 80, da_pagare=15)
        st.azione(m, "Io", "preflop", "call", 55, da_pagare=55)
        st.board(m, "7c 8d 2s")
        st.azione(m, "Bot", "flop", "bet", 100)
        st.azione(m, "Io", "flop", "fold", da_pagare=100)
        st.fine_mano(m, {"Io": -80, "Bot": 80}, piatto=160)
    else:
        st.azione(m, "Bot", "preflop", "fold", da_pagare=15)
        st.fine_mano(m, {"Io": 10, "Bot": -10}, piatto=35)
    return m


class T(unittest.TestCase):
    def test_archivio(self):
        st = Storia(":memory:")
        p = st.nuova_partita("prova")
        for _ in range(20):
            m = gioca(st, p, "maniaco")
        s = st.statistiche("Bot")
        self.assertEqual(s["mani"], 20)
        self.assertEqual(s["VPIP"], 1.0)
        self.assertEqual(st.tipo("Bot"), "maniaco")
        self.assertIn("Bot", st.mano(m))
        # le statistiche si accumulano anche in una nuova partita
        p2 = st.nuova_partita("prova 2")
        for _ in range(30):
            gioca(st, p2, "sasso")
        self.assertEqual(st.statistiche("Bot")["mani"], 50)
        self.assertEqual(st.statistiche("Io")["vincita"], 20 * -80 + 30 * 10)

    def test_mostrate(self):
        st = Storia(":memory:")
        p = st.nuova_partita()
        m = st.nuova_mano(p, 10, [{"nome": "Bot", "posizione": "SB"}])
        st.azione(m, "Bot", "preflop", "raise", 30)
        st.fine_mano(m, {"Bot": 30}, mostrate={"Bot": "9h 9d"})
        x = st.mani_mostrate("Bot")
        self.assertEqual(x[0]["carte"], "9h 9d")
        self.assertEqual(x[0]["azioni"], ["preflop:raise 30"])


if __name__ == "__main__":
    unittest.main()
