import os
import tempfile
import unittest
import zipfile
from coach import impara
from coach.storia import Storia


class T(unittest.TestCase):
    def test_scegli_foto(self):
        nomi = [f"{i:05d}.jpg" for i in range(1000)]
        scelte = impara.scegli_foto(nomi, max_foto=100)
        self.assertEqual(len(scelte), 100)
        self.assertEqual(scelte[0], nomi[0])
        self.assertEqual(scelte[-1], nomi[-1])
        # limite per peso: 1 MB l'una, massimo 25 MB
        self.assertEqual(len(impara.scegli_foto(nomi, 500, 25 * 2**20, [2**20] * 1000)), 25)
        self.assertEqual(impara.scegli_foto(nomi[:10]), nomi[:10])

    def test_zip_risultati(self):
        with tempfile.TemporaryDirectory() as d:
            db = os.path.join(d, "storia.db")
            st = Storia(db)
            p = st.nuova_partita("prova")
            m = st.nuova_mano(p, 10, [{"nome": "Io", "eroe": True}, {"nome": "Bot1"}])
            st.azione(m, "Bot1", "preflop", "raise", 30)
            st.fine_mano(m, {"Bot1": 15, "Io": -15})
            st.db.close()
            z = impara.crea_zip_risultati(db, "è andata bene", dest=os.path.join(d, "pac"))
            with zipfile.ZipFile(z) as zf:
                self.assertIn("storia.db", zf.namelist())
                rep = zf.read("report.txt").decode()
                self.assertIn("Bot1", rep)
                self.assertIn("è andata bene", zf.read("info.txt").decode())

    def test_zip_risultati_senza_archivio(self):
        with tempfile.TemporaryDirectory() as d:
            z = impara.crea_zip_risultati(os.path.join(d, "manca.db"), dest=d)
            with zipfile.ZipFile(z) as zf:
                self.assertIn("ancora vuoto", zf.read("report.txt").decode())


if __name__ == "__main__":
    unittest.main()
