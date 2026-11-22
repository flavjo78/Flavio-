import json
import os
import tempfile
import unittest

from coach.registro import Registro, Segui, leggi_file, leggi_riga
from coach.simula import righe
from coach.storia import Storia


class T(unittest.TestCase):
    def test_righe(self):
        self.assertIsNone(leggi_riga(""))
        self.assertIsNone(leggi_riga("# commento"))
        self.assertIsNone(leggi_riga('{"tipo": "stato", "mano": 1'))   # a metà
        self.assertIsNone(leggi_riga('{"tipo": "altro"}'))
        self.assertEqual(leggi_riga('{"tipo":"azione","mano":1}')["mano"], 1)

    def test_segui_righe_a_meta_e_file_ricreato(self):
        with tempfile.TemporaryDirectory() as d:
            p = os.path.join(d, "g.jsonl")
            open(p, "w").close()
            s = Segui(p)
            with open(p, "a") as f:
                f.write('{"tipo":"azione","mano":1,"posto":1,"azione":"fold"}\n{"tipo":"azione","ma')
            self.assertEqual(len(s.nuove()), 1)          # la seconda è a metà: aspetta
            with open(p, "a") as f:
                f.write('no":2,"posto":1,"azione":"call"}\n')
            n = s.nuove()
            self.assertEqual([x["mano"] for x in n], [2])
            with open(p, "w") as f:                       # il gioco ricrea il file
                f.write('{"tipo":"azione","mano":9,"posto":1,"azione":"check"}\n')
            self.assertEqual([x["mano"] for x in s.nuove()], [9])

    def test_stato_e_campi(self):
        r = Registro()
        for d in righe()[:3]:
            r.applica(d)
        c = r.stato.campi()
        self.assertEqual(c["carte"], "As Kd")
        self.assertEqual(c["posizione"], "CO")
        self.assertEqual(c["pagare"], "50")
        self.assertEqual(c["piatto"], "75")
        self.assertEqual(c["giocatori"], "4")

    def test_sito_soldi_veri(self):
        r = Registro()
        d = dict(righe()[2], gioco="PokerStars")
        self.assertIsNone(r.applica(d))
        self.assertTrue(r.bloccato)

    def test_archivio_e_ripasso(self):
        from coach.ripasso import analizza
        with tempfile.TemporaryDirectory() as d:
            p = os.path.join(d, "g.jsonl")
            with open(p, "w", encoding="utf-8") as f:
                for x in righe():
                    f.write(json.dumps(x) + "\n")
            r = leggi_file(p)
            st = Storia(":memory:")
            pid = st.nuova_partita("x")
            self.assertEqual(r.salva_in_storia(st, pid), 2)
            self.assertEqual(r.salva_in_storia(st, pid), 0)  # non le salva due volte
            a = analizza(st, pid, iters=300)
            m1, m2 = a["mani"]
            self.assertEqual([d["tua"] for d in m1["decisioni"]], ["raise", "bet", "fold"])
            self.assertEqual(m1["decisioni"][2]["consiglio"].pot_odds > 0.3, True)
            self.assertEqual((m1["posizione"], m1["risultato"]), ("CO", -225))
            self.assertEqual((m2["posizione"], m2["risultato"]), ("BB", 175))
            self.assertEqual(st.statistiche("Marco")["PFR"], 0.5)


if __name__ == "__main__":
    unittest.main()
