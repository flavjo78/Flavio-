import json
import os
import tempfile
import unittest

from PIL import Image

from coach import calibra


class T(unittest.TestCase):
    def test_passi(self):
        p = calibra.passi(6)
        chiavi = [k for k, _, _, _ in p]
        self.assertEqual(chiavi[:3], ["mia_carta_1", "mia_carta_2", "tavolo_1"])
        self.assertIn("piatto", chiavi)
        self.assertIn("posto_6", chiavi)
        self.assertNotIn("posto_7", chiavi)
        self.assertEqual(len(calibra.passi(9)) - len(calibra.passi(6)), 6)

    def test_proporzioni_e_ridimensionamento(self):
        c = calibra.Calibrazione(1000, 800, 6)
        c.imposta("piatto", 600, 500, 400, 450)  # disegnato al contrario
        self.assertEqual(c.zone["piatto"], [0.4, 0.5625, 0.2, 0.0625])
        self.assertEqual(c.pixel("piatto"), (400, 450, 200, 50))
        self.assertEqual(c.pixel("piatto", 2000, 1600), (800, 900, 400, 100))  # finestra più grande
        with self.assertRaises(ValueError):
            c.imposta("tavolo", 10, 10, 12, 11)
        self.assertIn("mia_carta_1", c.mancanti())
        self.assertNotIn("piatto", c.mancanti())

    def test_salva_e_carica(self):
        with tempfile.TemporaryDirectory() as d:
            c = calibra.Calibrazione(400, 300, 2, "Tavolo")
            c.imposta("mia_carta_1", 150, 200, 250, 260)
            c.imposta("piatto", 170, 120, 230, 140)
            f = c.salva(Image.new("RGB", (400, 300), "green"), os.path.join(d, "cal.json"), os.path.join(d, "cal"))
            self.assertTrue(os.path.exists(os.path.join(d, "cal", "anteprima.png")))
            self.assertTrue(os.path.exists(os.path.join(d, "cal", "zone", "piatto.png")))
            self.assertEqual(Image.open(os.path.join(d, "cal", "zone", "piatto.png")).size, (60, 20))
            c2 = calibra.carica(f)
            self.assertEqual(c2.zone, c.zone)
            self.assertEqual(json.load(open(f))["mio_posto"], 1)


if __name__ == "__main__":
    unittest.main()
