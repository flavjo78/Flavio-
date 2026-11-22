import unittest

from coach.lettore import Lettura
from coach.ricostruisci import Ricostruttore, salva_in_storia
from coach.storia import Storia


def L(mie, tav, piatto, stack, puntate, tocca, bottone=1):
    return Lettura(mie_carte=mie, tavolo=tav, piatto=piatto, stack=stack, puntate=puntate,
                   tocca_a_me=tocca, bottone=bottone)


S = {k: 1000 for k in range(1, 7)}


def sequenza():
    return [
        # mano 1: Ad Qh dal bottone. Preflop tocca a me (da pagare 20) -> rilancio a 60
        L(["Ad", "Qh"], [], 30, dict(S), {5: 10, 6: 20}, True),
        L(["Ad", "Qh"], [], 90, {**S, 1: 940}, {1: 60, 5: 10, 6: 20}, False),
        L(["Ad", "Qh"], [], 130, {**S, 1: 940, 6: 960}, {1: 60, 6: 60}, False),   # posto 6 paga
        # flop: tocca a me, punto 80
        L(["Ad", "Qh"], ["Ks", "7c", "2d"], 130, {**S, 1: 940, 6: 940}, {}, True),
        L(["Ad", "Qh"], ["Ks", "7c", "2d"], 210, {**S, 1: 860, 6: 940}, {1: 80}, False),
        # mano 2: nuove carte, il mio stack è 1100 (ho vinto 100)
        L(["Ks", "Ad"], [], 30, {**S, 1: 1100}, {6: 10, 1: 20}, False, bottone=5),
        L(["Ks", "Ad"], [], 90, {**S, 1: 1100}, {6: 10, 1: 20, 2: 60}, True, bottone=5),  # posto 2 rilancia
        L([], [], 90, {**S, 1: 1080}, {6: 10, 1: 20, 2: 60}, False, bottone=5),           # io fold
        L(["Ks", "Ad"], ["?"], 0, {}, {}, False),  # lettura incerta: va ignorata
    ]


class T(unittest.TestCase):
    def test_ricostruisce_due_mani(self):
        r = Ricostruttore()
        for i, l in enumerate(sequenza()):
            if i == 8:
                l.incerti.append("tavolo_1")
            r.aggiungi(l, ts=1000 + i * 3)
        mani = r.fine()
        self.assertEqual(len(mani), 2)
        m1, m2 = mani
        self.assertEqual(m1.carte, ["Ad", "Qh"])
        self.assertEqual([(d.strada, d.tipo, d.importo) for d in m1.decisioni],
                         [("preflop", "raise", 60), ("flop", "bet", 80)])
        self.assertEqual(m1.decisioni[0].da_pagare, 20)
        self.assertEqual(m1.tavolo, ["Ks", "7c", "2d"])
        self.assertEqual(m1.risultato, 100)
        self.assertIn(("preflop", 6, "call", 60), [a[:4] for a in m1.azioni_altri])
        self.assertEqual([(d.strada, d.tipo) for d in m2.decisioni], [("preflop", "fold")])
        self.assertEqual(m2.decisioni[0].da_pagare, 40)
        self.assertEqual(m2.risultato, -20)
        self.assertTrue(m2.foldata)

    def test_salva_e_ripasso(self):
        from coach.ripasso import analizza
        r = Ricostruttore()
        for i, l in enumerate(sequenza()[:8]):
            r.aggiungi(l, ts=1000 + i * 3)
        st = Storia(":memory:")
        p = st.nuova_partita("registrata")
        self.assertEqual(salva_in_storia(r.fine(), st, p, bb=20, n_posti=6), 2)
        a = analizza(st, p, iters=300)
        self.assertEqual(a["n_mani"], 2)
        self.assertEqual(a["n_decisioni"], 3)
        self.assertEqual(a["mani"][0]["posizione"], "BTN")
        self.assertEqual(a["curva_reale"], [0.0, 5.0, 4.0])
        # le azioni degli altri finiscono nell'archivio (per i ritratti degli avversari)
        self.assertEqual(st.statistiche("Posto 6")["mani"], 2)


if __name__ == "__main__":
    unittest.main()


class Registrazione(unittest.TestCase):
    def test_dalle_foto_al_ripasso(self):
        """Partita registrata (solo foto) -> a fine partita ricostruisce le mani e fa il ripasso."""
        import os
        import tempfile
        from coach import annotazioni, lettore, schermo
        from tests import finto_tavolo as ft
        from tests.test_lettore import calibrazione, insegna
        S6 = {k: 1000 for k in range(1, 7)}
        foto = [  # (mie, tavolo, piatto, stack, puntate, tocca)
            (["Ad", "Qh"], [], 60, S6, {5: 10, 6: 20}, True),
            (["Ad", "Qh"], [], 100, {**S6, 1: 940}, {1: 60, 5: 10, 6: 20}, False),
            (["Ad", "Qh"], ["Ks", "7c", "2d"], 160, {**S6, 1: 940, 6: 940}, {}, True),
            (["Ad", "Qh"], ["Ks", "7c", "2d"], 160, {**S6, 1: 860, 6: 940}, {1: 80}, False),
            (["Ks", "2d"], [], 60, {**S6, 1: 1100}, {6: 10, 1: 20}, False),
            (["Ks", "2d"], [], 100, {**S6, 1: 1100}, {6: 10, 1: 20, 2: 60}, True),
            ([], [], 100, {**S6, 1: 1080}, {6: 10, 1: 20, 2: 60}, False),
        ]
        with tempfile.TemporaryDirectory() as d:
            cwd = os.getcwd()
            os.chdir(d)
            orig = schermo.trova
            try:
                schermo.trova = lambda t: None  # il gioco è chiuso
                cal = calibrazione()
                cal.salva()
                # il giocatore ha già insegnato carte e cifre con LETTORE.bat
                lett = lettore.Lettore(cal)
                img1 = ft.disegna(["Ad", "Qh"], ["Ks", "7c", "2d"], 160,
                                  {1: 1240, 2: 980, 3: 1560, 4: 640, 5: 1105, 6: 2310}, {4: 40})
                insegna(lett, lett.leggi(img1), {
                    "mia_carta_1": "Ad", "mia_carta_2": "Qh", "tavolo_1": "Ks", "tavolo_2": "7c", "tavolo_3": "2d",
                    "piatto": "Pot 160", "posto_1": "1240", "posto_2": "980", "posto_3": "1560", "posto_4": "640",
                    "posto_5": "1105", "posto_6": "2310", "puntata_4": "40"})
                # varianti che il lettore chiede di nuovo (es. uno «0» disegnato mezzo pixel più in là):
                # il giocatore le insegna con LETTORE.bat sulla prima foto della partita
                f0 = ft.disegna(*foto[0][:5], tocca=True)
                insegna(lett, lett.leggi(f0), {"mia_carta_1": "Ad", "mia_carta_2": "Qh", "piatto": "Pot 60",
                                               **{f"posto_{k}": "1000" for k in range(1, 7)},
                                               "puntata_5": "10", "puntata_6": "20"})
                cartella = os.path.join("sessioni", "partita")
                os.makedirs(cartella)
                with open(os.path.join(cartella, "indice.csv"), "w") as f:
                    f.write("n,ora,file,larghezza,altezza,x,y,cambiamento\n")
                    for i, (mie, tav, piatto, stack, punt, tocca) in enumerate(foto):
                        nome = f"{i:05d}.png"
                        ft.disegna(mie, tav, piatto, stack, punt, tocca=tocca).save(os.path.join(cartella, nome))
                        f.write(f"{i},2026-11-22 21:00:{i * 5:02d},{nome},1000,700,0,0,1\n")
                from coach.storia import Storia
                st = Storia("storia.db")
                pid = st.nuova_partita("Gioco finto", "annotazioni")
                st.db.close()
                cfg = {"suggerimenti": True, "ultima": {"cartella": cartella, "titolo": "Gioco finto", "handle": None,
                                                       "partita": pid, "fine": "oggi"}}
                pagina = annotazioni.rivedi(cfg, apri=False)
                st = Storia("storia.db")
                n_mani = st.db.execute("SELECT COUNT(*) FROM mani WHERE partita_id=?", (pid,)).fetchone()[0]
                mie = [r[0] for r in st.db.execute(
                    "SELECT a.tipo FROM azioni a JOIN giocatori g ON g.id=a.giocatore_id WHERE g.nome='Io' ORDER BY a.id")]
                html = open(pagina, encoding="utf-8").read()
            finally:
                schermo.trova = orig
                os.chdir(cwd)
        self.assertEqual(n_mani, 2)
        self.assertEqual(mie, ["raise", "bet", "fold"])
        self.assertIn("Tutte le tue giocate", html)
        self.assertIn("Mano 1", html)

    def test_soldi_veri_niente_analisi(self):
        from coach.calibra import Calibrazione
        from coach.ricostruisci import analizza_registrazione
        c = Calibrazione(100, 100, 6, "Cash game | PokerStars")
        self.assertTrue(analizza_registrazione(".", None, 1, c, 20)["bloccata"])


class Password(unittest.TestCase):
    def test_password(self):
        from coach import annotazioni
        cfg = {}
        with self.assertRaises(ValueError):
            annotazioni.imposta_password(cfg, "12")
        annotazioni.imposta_password(cfg, "segreta")
        self.assertNotIn("segreta", str(cfg))  # mai in chiaro
        self.assertTrue(annotazioni.password_giusta(cfg, "segreta"))
        self.assertFalse(annotazioni.password_giusta(cfg, "sbagliata"))
        self.assertFalse(annotazioni.password_giusta({}, "segreta"))
