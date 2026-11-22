import os
import tempfile
import unittest

from coach import tavolo
from coach.torneo import Osservatore, prossimi_bui
from coach import partita as partita_mod


class Tavolo(unittest.TestCase):
    def test_posizioni(self):
        self.assertEqual(tavolo.posizioni(2), ["SB", "BB"])
        self.assertEqual(tavolo.posizioni(6), ["UTG", "MP", "CO", "BTN", "SB", "BB"])
        self.assertEqual(len(tavolo.posizioni(9)), 9)
        self.assertEqual(tavolo.posizioni(9)[0], "UTG")
        self.assertEqual(len(tavolo.posizioni(10)), 10)
        self.assertEqual(tavolo.dietro(9, "UTG"), 8)
        self.assertEqual(tavolo.dietro(9, "BTN"), 2)

    def test_apri_piu_stretto_con_piu_giocatori(self):
        self.assertLess(tavolo.apri_top(9, "UTG"), tavolo.apri_top(6, "UTG"))
        self.assertGreater(tavolo.apri_top(2, "SB"), tavolo.apri_top(6, "BTN"))
        self.assertEqual(tavolo.apri_top(6, "BB"), 0)

    def test_posizione_da_posti(self):
        # 6 posti, bottone al posto 4: 5=SB, 6=BB, 1=UTG, 2=MP, 3=CO
        posti = [1, 2, 3, 4, 5, 6]
        self.assertEqual(tavolo.posizione_da_posti(posti, 4, 4), "BTN")
        self.assertEqual(tavolo.posizione_da_posti(posti, 4, 6), "BB")
        self.assertEqual(tavolo.posizione_da_posti(posti, 4, 1), "UTG")
        self.assertEqual(tavolo.posizione_da_posti(posti, 4, 3), "CO")
        # posto vuoto: 5 giocatori
        self.assertEqual(tavolo.posizione_da_posti([1, 2, 4, 5, 6], 4, 1), "UTG")
        self.assertEqual(tavolo.posizione_da_posti([1, 2, 4, 5, 6], 4, 2), "CO")
        self.assertEqual(tavolo.posizione_da_posti([3, 7], 7, 3), "BB")


class Torneo(unittest.TestCase):
    def test_prossimi_bui(self):
        self.assertEqual(prossimi_bui(10, 20), (15, 30))
        self.assertEqual(prossimi_bui(100, 200), (150, 300))
        self.assertEqual(prossimi_bui(7, 14), (10, 20))  # sconosciuta: x1.5 arrotondato

    def test_impara_la_velocita(self):
        o = Osservatore()
        t = 1000.0
        for i, (sb, bb) in enumerate([(10, 20), (15, 30), (25, 50), (50, 100)]):
            o.registra(sb, bb, ts=t + i * 300)
            o.registra(sb, bb, ts=t + i * 300 + 60)  # stessa lettura: ignorata
        self.assertEqual(len(o.storia), 4)
        self.assertEqual(o.minuti_livello(), 5.0)
        st = o.stato(1500, 6, ts=t + 3 * 300 + 120)
        self.assertEqual(st["prossimo"], (75, 150))
        self.assertAlmostEqual(st["minuti_al_prossimo"], 3.0)
        self.assertIn("turbo", st["velocita"])
        self.assertIn("75/150", st["messaggio"])
        o2 = Osservatore.from_dict(o.to_dict())
        self.assertEqual(o2.minuti_livello(), 5.0)

    def test_avviso_zona_pushfold(self):
        o = Osservatore(minuti=10)
        o.registra(100, 200, ts=0)
        st = o.stato(3600, 6, ts=60)  # 18 bb ora, 12 bb al prossimo
        self.assertIn("push/fold", st["messaggio"])


class Partita(unittest.TestCase):
    def test_salva_carica_bui(self):
        with tempfile.TemporaryDirectory() as d:
            f = os.path.join(d, "partita.json")
            p = partita_mod.Partita(tipo="torneo", giocatori=9, posti=9, sb=10, bb=20)
            p.aggiorna_bui(10, 20, ts=0)
            p.aggiorna_bui(15, 30, ts=600)
            p.aggiorna_bui(25, 50, ts=1200)
            partita_mod.salva(p, f)
            q = partita_mod.carica(f)
            self.assertEqual(q.giocatori, 9)
            self.assertEqual(q.bb, 50)
            self.assertEqual(q.osservatore().minuti_livello(), 10.0)
            self.assertIn("livelli da 10 min", q.riassunto())


if __name__ == "__main__":
    unittest.main()
