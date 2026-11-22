import os
import tempfile
import unittest
from coach import schermo


class FakeImg:
    def __init__(self, w, h, colore):
        self.size = (w, h)
        self.raw = bytes([colore, colore, colore, 255]) * (w * h)
        self.rgb = bytes([colore] * 3) * (w * h)


class FakeMSS:
    sequenza = []

    def __enter__(self):
        return self

    def __exit__(self, *a):
        pass

    def grab(self, area):
        if len(FakeMSS.sequenza) > 1:
            FakeMSS.sequenza.pop(0)
        colore = FakeMSS.sequenza[0]  # finita la sequenza, resta l'ultimo colore
        return FakeImg(area["width"], area["height"], colore)


class T(unittest.TestCase):
    def test_differenza(self):
        self.assertEqual(schermo.differenza(b"abc", b"abc"), 0)
        self.assertEqual(schermo.differenza(b"\x00\x00", b"\xff\x00"), 0.5)
        self.assertEqual(schermo.differenza(None, b"a"), 1.0)

    def test_registra_salva_solo_i_cambi_e_vede_il_ridimensionamento(self):
        dimensioni = [(100, 80)] * 4 + [(120, 90)] * 3
        def finestra():
            w, h = dimensioni.pop(0) if dimensioni else (120, 90)
            return schermo.Finestra("Tavolo Poker", 7, 0, 0, w, h)
        orig = (schermo.trova, schermo.aggiorna, schermo._MSS, schermo.coperta)
        schermo.trova = lambda t: finestra()
        schermo.aggiorna = lambda f: finestra()
        schermo.coperta = lambda f: False
        schermo._MSS = FakeMSS
        # colori catturati: 2 uguali, un cambio, poi ridimensionata (=> nuova foto), poi uguali
        FakeMSS.sequenza = [None, 10, 10, 200, 200, 200, 200, 200]
        log = []
        try:
            with tempfile.TemporaryDirectory() as d:
                n = schermo.registra("poker", d, intervallo=0, max_foto=99, durata=0.3, log=log.append)
                righe = open(os.path.join(d, "indice.csv")).read().strip().splitlines()
        finally:
            schermo.trova, schermo.aggiorna, schermo._MSS, schermo.coperta = orig
        self.assertEqual(n, 3, log)   # primo, cambio colore, dopo il ridimensionamento
        self.assertEqual(len(righe), 4)
        self.assertTrue(any("ridimensionata: 100x80 -> 120x90" in m for m in log), log)


if __name__ == "__main__":
    unittest.main()


class Nera(unittest.TestCase):
    def test_quasi_nera(self):
        self.assertTrue(schermo.quasi_nera(bytes([0, 0, 0, 255]) * 1000))
        self.assertFalse(schermo.quasi_nera(bytes([30, 120, 60, 255]) * 1000))
        mista = bytes([0, 0, 0, 255]) * 900 + bytes([200, 200, 200, 255]) * 100
        self.assertFalse(schermo.quasi_nera(mista))

    def test_avviso_in_registrazione(self):
        import tempfile
        orig = (schermo.trova, schermo.aggiorna, schermo._MSS, schermo.coperta)
        schermo.trova = lambda t: schermo.Finestra("Poker", 7, 0, 0, 100, 80)
        schermo.aggiorna = lambda f: f
        schermo.coperta = lambda f: False
        schermo._MSS = FakeMSS
        FakeMSS.sequenza = [None, 0]
        log = []
        try:
            with tempfile.TemporaryDirectory() as d:
                schermo.registra("poker", d, intervallo=0, durata=0.2, log=log.append)
        finally:
            schermo.trova, schermo.aggiorna, schermo._MSS, schermo.coperta = orig
        self.assertEqual(sum("NERE" in m for m in log), 1, log)  # avvisa una volta sola
