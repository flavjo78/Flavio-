import os
import tempfile
import unittest

from coach import lettore
from coach.calibra import Calibrazione
from tests import finto_tavolo as ft


def calibrazione():
    c = Calibrazione(ft.W, ft.H, 6, "Gioco finto")
    for k, (x, y, w, h) in ft.zone().items():
        if k != "bottone":
            c.imposta(k, x, y, x + w, y + h)
    return c


def insegna(lett, letto, verita):
    """Fa la parte del giocatore: etichetta ogni cosa sconosciuta con la risposta giusta.

    Per i numeri fa come farebbe una persona guardando i pezzi: le cifre (da destra) le scrive,
    tutto quello che sta prima (lettere, «Pot», simboli attaccati) lo marca «ignora».
    """
    per_zona = {}
    for s in letto.sconosciuti:
        if s.tipo == "carta":
            lett.mod.insegna_carta(verita[s.zona], s.matrice, s.colore)
        else:
            per_zona.setdefault(s.zona, []).append(s)
    for z, glifi in per_zona.items():
        cifre = [c for c in verita[z] if c.isdigit()]
        etichette = [lettore.IGNORA] * len(glifi)
        for i in range(1, min(len(cifre), len(glifi)) + 1):
            etichette[-i] = cifre[-i]
        for g, e in zip(glifi, etichette):
            lett.mod.insegna_glifo(e, g.matrice)


class T(unittest.TestCase):
    def setUp(self):
        self.d = tempfile.TemporaryDirectory()
        self.cartella = os.path.join(self.d.name, "modelli")

    def tearDown(self):
        self.d.cleanup()

    def test_numeri(self):
        self.assertEqual(lettore.interpreta_numero("€2.05"), 2.05)
        self.assertEqual(lettore.interpreta_numero("1.500"), 1500)
        self.assertEqual(lettore.interpreta_numero("1,234.50"), 1234.5)
        self.assertEqual(lettore.interpreta_numero("Pot: €0.29"), 0.29)
        self.assertIsNone(lettore.interpreta_numero("Empty Seat"))

    def test_impara_e_poi_riconosce(self):
        cal = calibrazione()
        lett = lettore.Lettore(cal, self.cartella)
        # 1) prima foto: non conosce niente, chiede tutto
        img1 = ft.disegna(["Ad", "Qh"], ["Ks", "7c", "2d"], 160,
                          {1: 1240, 2: 980, 3: 1560, 4: 640, 5: 1105, 6: 2310}, {4: 40, 1: 0}, tocca=True)
        l1 = lett.leggi(img1)
        self.assertGreater(len(l1.sconosciuti), 10)
        self.assertFalse(l1.sicura())
        verita = {"mia_carta_1": "Ad", "mia_carta_2": "Qh", "tavolo_1": "Ks", "tavolo_2": "7c", "tavolo_3": "2d",
                  "piatto": "Pot 160", "posto_1": "1240", "posto_2": "980", "posto_3": "1560", "posto_4": "640",
                  "posto_5": "1105", "posto_6": "2310", "puntata_4": "40"}
        insegna(lett, l1, verita)
        # 2) stessa foto: ora legge tutto da solo
        l2 = lett.leggi(img1)
        self.assertTrue(l2.sicura(), l2.incerti)
        self.assertEqual(l2.mie_carte, ["Ad", "Qh"])
        self.assertEqual(l2.tavolo, ["Ks", "7c", "2d"])
        self.assertEqual(l2.piatto, 160)
        self.assertEqual(l2.stack[1], 1240)
        self.assertEqual(l2.puntate[4], 40)
        self.assertTrue(l2.tocca_a_me)
        # 3) foto NUOVA con altri numeri e carte già viste in altri posti: niente da insegnare
        img3 = ft.disegna(["Ks", "Ad"], ["Qh", "2d", "7c", "Ad"], 1060,
                          {1: 1016, 2: 4321, 3: None, 4: 658, 5: 1105, 6: 2310}, {2: 120}, tocca=False)
        l3 = lett.leggi(img3)
        self.assertEqual(l3.sconosciuti, [], [s.zona for s in l3.sconosciuti])
        self.assertEqual(l3.mie_carte, ["Ks", "Ad"])
        self.assertEqual(l3.tavolo, ["Qh", "2d", "7c", "Ad"])
        self.assertEqual(l3.piatto, 1060)
        self.assertEqual(l3.stack[2], 4321)
        self.assertIsNone(l3.stack[3])  # posto vuoto
        self.assertFalse(l3.tocca_a_me)
        campi = lettore.situazione_da_lettura(l3, 20, 6)
        self.assertEqual(campi["pagare"], "120")
        self.assertEqual(campi["giocatori"], "5")
        # 3b) una cifra mai vista nel piatto (carattere diverso dagli stack): la CHIEDE, non tira a indovinare
        l3b = lett.leggi(ft.disegna(["Ks", "Ad"], [], 4012, {1: 1016}, {}))
        self.assertIsNone(l3b.piatto)
        self.assertIn("piatto", l3b.incerti)
        self.assertEqual(l3b.testi["piatto"], "?012")
        # 4) finestra ingrandita del 25%: il gioco ridisegna le cifre, quindi lo DICE e chiede di insegnare
        grande = ft.disegna(["Ad", "Qh"], ["Ks", "7c", "2d"], 160,
                            {1: 1240, 2: 980, 3: 1560, 4: 640, 5: 1105, 6: 2310}, {4: 40}, scala=1.25)
        l4 = lett.leggi(grande)
        self.assertFalse(l4.sicura())
        self.assertTrue(any("1000x700" in a for a in l4.avvisi), l4.avvisi)
        self.assertEqual(l4.mie_carte, ["?", "?"])
        insegna(lett, l4, dict(verita, puntata_4="40"))
        l5 = lett.leggi(grande)
        self.assertTrue(l5.sicura(), l5.incerti)
        self.assertEqual((l5.mie_carte, l5.piatto), (["Ad", "Qh"], 160))
        # tornando alla grandezza di prima usa i modelli di prima
        self.assertEqual(lett.leggi(img1).mie_carte, ["Ad", "Qh"])

    def test_modelli_salvati(self):
        cal = calibrazione()
        lett = lettore.Lettore(cal, self.cartella)
        img = ft.disegna(["Ad", "Qh"], [], 30, {1: 1000}, {})
        l = lett.leggi(img)
        insegna(lett, l, {"mia_carta_1": "Ad", "mia_carta_2": "Qh", "piatto": "Pot 30", "posto_1": "1000"})
        di_nuovo = lettore.Lettore(cal, self.cartella)  # riapre da disco
        self.assertEqual(di_nuovo.leggi(img).mie_carte, ["Ad", "Qh"])

    def test_blocco_soldi_veri(self):
        self.assertTrue(lettore.bloccato("Cash game | PokerStars - Google Chrome"))
        self.assertTrue(lettore.bloccato("GGPoker"))
        self.assertFalse(lettore.bloccato("Poker offline contro il computer"))
        l = lettore.Lettore(calibrazione(), self.cartella).leggi(ft.disegna(["Ad", "Qh"], [], 30, {1: 1000}, {}),
                                                            titolo="PokerStars")
        self.assertTrue(l.bloccata)
        self.assertEqual(l.mie_carte, [])


if __name__ == "__main__":
    unittest.main()


class Bottone(unittest.TestCase):
    def test_trova_il_bottone_che_si_sposta(self):
        with tempfile.TemporaryDirectory() as d:
            cwd = os.getcwd()
            os.chdir(d)
            try:
                cal = calibrazione()
                x, y, w, h = ft.zona_bottone(1)
                cal.imposta("bottone", x, y, x + w, y + h)
                cal.salva(ft.disegna(["Ad", "Qh"], [], 30, {1: 1000}, {}, bottone=1))
                lett = lettore.Lettore(cal, os.path.join(d, "m"))
                for posto in (1, 4, 6):
                    img = ft.disegna(["Ad", "Qh"], [], 30, {k: 1000 for k in range(1, 7)}, {}, bottone=posto)
                    self.assertEqual(lett.trova_bottone(img), posto)
            finally:
                os.chdir(cwd)
