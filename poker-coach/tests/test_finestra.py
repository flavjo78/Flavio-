import unittest

from coach.finestra import costruisci_situazione, carte_belle

BASE = {"carte": "Ah Kh", "tavolo": "Ks 7h 2d", "piatto": "160", "pagare": "40", "stack": "1240",
        "bb": "20", "ante": "0", "giocatori": "6", "posizione": "BTN", "avversari": "1",
        "rilancia": "", "tipo": "stazione"}


class T(unittest.TestCase):
    def test_ok(self):
        s = costruisci_situazione(BASE)
        self.assertEqual(len(s.hole), 2)
        self.assertEqual(len(s.board), 3)
        self.assertEqual(s.to_call, 40)
        self.assertEqual(s.opp_type, "stazione")
        self.assertEqual(costruisci_situazione(dict(BASE, piatto="160,5")).pot, 160.5)

    def test_errori_chiari(self):
        casi = {
            "carte": ("Ah", "2 carte"), "tavolo": ("Ks 7h", "0, 3, 4 o 5"),
            "posizione": ("UTG+2", "scegli tra"), "bb": ("0", "Big blind"), "stack": ("abc", "numero"),
            "giocatori": ("12", "da 2 a 10"), "avversari": ("9", "Avversari"),
        }
        for campo, (valore, attesa) in casi.items():
            with self.assertRaises(ValueError) as e:
                costruisci_situazione(dict(BASE, **{campo: valore}))
            self.assertIn(attesa, str(e.exception), campo)
        with self.assertRaises(ValueError) as e:
            costruisci_situazione(dict(BASE, carte="Ks Kh"))
        self.assertIn("ripetuta", str(e.exception))

    def test_carte_belle(self):
        self.assertEqual(carte_belle("Ah Td"), "A♥  10♦")
        self.assertEqual(carte_belle("zz"), "")


if __name__ == "__main__":
    unittest.main()
