"""Test della parte ferie di dashboard/APP.py e confronto con l'app PHP.

Uso:   python3 tests/test_dashboard_ferie.py        (serve anche `php` per i confronti)

APP.py e' un'app Streamlit e non si puo' importare senza avviarla: qui si
estraggono da APP.py SOLO le funzioni che servono (regole dei giorni lavorativi
e blocco "FERIE") e si eseguono in isolamento. Cosi' si prova il codice vero,
non una copia.
"""
import ast
import datetime
import email
import email.header
import email.message
import email.utils
import glob
import hashlib
import json
import os
import re
import secrets
import smtplib
import socketserver
import ssl
import subprocess
import tempfile
import threading
import unittest
import base64

QUI = os.path.dirname(os.path.abspath(__file__))
APP = os.path.join(QUI, "..", "dashboard", "APP.py")
CARTELLA_PHP = QUI

FUNZIONI_NECESSARIE = {
    "_pasqua", "festivi_nazionali", "festivi_anno", "nome_festivo", "lavorativo_il",
    "giorno_lavorativo_per", "chiave_giorno", "carica_correzioni", "salva_correzioni",
}
IMP = {}


def scrivi_json(percorso, dati):
    with open(percorso, "w", encoding="utf-8") as f:
        json.dump(dati, f)


def carica_funzioni_app():
    with open(APP, encoding="utf-8") as f:
        testo = f.read()
    albero = ast.parse(testo)
    ns = {"datetime": datetime, "os": os, "json": json, "glob": glob, "hashlib": hashlib, "re": re,
          "secrets": secrets, "smtplib": smtplib, "ssl": ssl, "email": email,
          "PERCORSO_CORREZIONI": "correzioni_timbrature.json",
          "GIUSTIFICATIVI": {"M": "Malattia", "F": "Ferie", "F½": "Ferie ½ giornata"},
          "imp": lambda k: IMP.get(k), "_CACHE_FESTIVI": {}}
    for nodo in albero.body:
        if isinstance(nodo, ast.FunctionDef) and nodo.name in FUNZIONI_NECESSARIE:
            exec(compile(ast.get_source_segment(testo, nodo), APP, "exec"), ns)
    blocco = testo[testo.index("# FERIE-INIZIO"):testo.index("# FERIE-FINE")]
    exec(compile(blocco, APP + " (blocco FERIE)", "exec"), ns)
    return ns


A = carica_funzioni_app()

# Orari come li vede la dashboard (orari_lavoro.json -> orari con time/"OFF") e come li vede il PHP (json grezzo)
ORARI_JSON = {
    "Mario Rossi": {"per_day": {"5": "OFF", "6": "OFF"}},
    "Laura Bianchi": {"per_day": {"4": "OFF", "5": "OFF", "6": "OFF"}},
    "Sabato Sì": {"per_day": {"5": "08:00", "6": "OFF"}},
    "Senza Orari": {"per_day": {}},
    "Giorgio Verdi": {"per_day": {"0": "OFF", "5": "OFF", "6": "OFF"}},
}


def orari_dashboard():
    r = {}
    for nome, info in ORARI_JSON.items():
        r[nome] = {"per_day": {int(k): ("OFF" if v == "OFF" else datetime.time.fromisoformat(v))
                               for k, v in info["per_day"].items()}}
    return r


IMPOSTAZIONI = {"festivi_nazionali": True, "festivi_extra": {"2026-12-24": "Chiusura aziendale", "2027-08-16": "Ponte"},
                "festivi_esclusi": ["2027-01-06"]}


class Base(unittest.TestCase):
    def setUp(self):
        IMP.clear()
        IMP.update(IMPOSTAZIONI)
        A["_CACHE_FESTIVI"].clear()
        self._cwd = os.getcwd()
        self._tmp = tempfile.TemporaryDirectory()
        os.chdir(self._tmp.name)

    def tearDown(self):
        os.chdir(self._cwd)
        self._tmp.cleanup()


class TestGiorniLavorativi(Base):
    def test_esempi_di_documentazione(self):
        o = orari_dashboard()
        g = A["giorni_lavorativi_periodo"]
        d = datetime.date
        self.assertEqual(len(g(o, "Mario Rossi", d(2026, 10, 12), d(2026, 10, 16))), 5)
        self.assertEqual(len(g(o, "Laura Bianchi", d(2026, 10, 12), d(2026, 10, 16))), 4)  # venerdi' di riposo
        # Pasquetta 2026 (6 aprile): lun festivo
        self.assertEqual(g(o, "Mario Rossi", d(2026, 4, 3), d(2026, 4, 7)), [d(2026, 4, 3), d(2026, 4, 7)])
        # chiusura aziendale + Natale
        self.assertEqual(len(g(o, "Mario Rossi", d(2026, 12, 21), d(2026, 12, 25))), 3)
        # 4 ottobre: festivo dal 2026 ma non nel 2025 (sabato/domenica esclusi: uso una persona con sabato lavorativo)
        self.assertEqual(g(o, "Sabato Sì", d(2026, 10, 3), d(2026, 10, 5)), [d(2026, 10, 3), d(2026, 10, 5)])
        self.assertEqual(g(o, "Sabato Sì", d(2025, 10, 3), d(2025, 10, 6)), [d(2025, 10, 3), d(2025, 10, 4), d(2025, 10, 6)])

    def test_parita_con_php_su_due_anni(self):
        """Per ogni persona e ogni periodo, app PHP e dashboard devono contare gli stessi giorni."""
        casi = []
        inizio = datetime.date(2026, 1, 1)
        for nome in list(ORARI_JSON) + ["Sconosciuto"]:
            for k in range(0, 730, 3):  # un periodo ogni 3 giorni, di lunghezze diverse
                dal = inizio + datetime.timedelta(days=k)
                al = dal + datetime.timedelta(days=(k * 7) % 17)
                casi.append((nome, dal, al))
        risposta = esegui_php({"casi": [[n, d.isoformat(), a.isoformat()] for n, d, a in casi], "saldi_casi": [], "pin_casi": []})
        o = orari_dashboard()
        diversi = []
        for (nome, dal, al), giorni_php in zip(casi, risposta["giorni"]):
            mie = [g.isoformat() for g in A["giorni_lavorativi_periodo"](o, nome, dal, al)]
            if mie != giorni_php:
                diversi.append((nome, dal, al, mie, giorni_php))
        self.assertEqual(diversi[:3], [], f"{len(diversi)} periodi contati diversamente")
        self.assertGreater(len(casi), 700)


def esegui_php(extra, giustificativi=None, richieste=None, saldi=None, oggi="2026-09-25"):
    dati = {"orari": ORARI_JSON, "imp": IMPOSTAZIONI, "oggi": oggi, "giustificativi": giustificativi or {},
            "richieste": richieste or [], "saldi": saldi or {}}
    dati.update(extra)
    with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8") as f:
        json.dump(dati, f, ensure_ascii=False)
    try:
        r = subprocess.run(["php", os.path.join(CARTELLA_PHP, "parita_php.php"), f.name], capture_output=True, text=True)
    finally:
        os.unlink(f.name)
    if r.returncode != 0:
        raise RuntimeError(r.stdout + r.stderr)
    return json.loads(r.stdout)


class TestSaldo(Base):
    GIUST = {"2026-09-21|Mario Rossi": "F", "2026-09-22|Mario Rossi": "F", "2026-10-05|Mario Rossi": "F",
             "2026-11-02|Mario Rossi": "F½", "2026-09-18|Mario Rossi": "M", "2025-12-30|Mario Rossi": "F",
             "2026-09-25|Mario Rossi": "F"}  # 25/09 = oggi: conta come goduta
    RICH = [
        {"id": "a", "nome": "Mario Rossi", "dal": "2026-10-12", "al": "2026-10-16", "mezza_giornata": None, "giorni_lavorativi": 5, "stato": "in_attesa"},
        {"id": "b", "nome": "Mario Rossi", "dal": "2026-10-20", "al": "2026-10-20", "mezza_giornata": "mattina", "giorni_lavorativi": 0.5, "stato": "in_attesa"},
        {"id": "c", "nome": "Mario Rossi", "dal": "2026-08-03", "al": "2026-08-07", "mezza_giornata": None, "giorni_lavorativi": 5, "stato": "rifiutata"},
        {"id": "d", "nome": "Laura Bianchi", "dal": "2026-10-12", "al": "2026-10-13", "mezza_giornata": None, "giorni_lavorativi": 2, "stato": "in_attesa"},
        {"id": "e", "nome": "Mario Rossi", "dal": "2026-12-28", "al": "2027-01-08", "mezza_giornata": None, "giorni_lavorativi": 8, "stato": "in_attesa"},
    ]
    SALDI = {"Mario Rossi": {"anno": 2026, "giorni_spettanti": 26, "residuo_anno_precedente": 3},
             "Laura Bianchi": {"anno": 2025, "giorni_spettanti": 20}}

    def test_valori(self):
        s = A["saldo_ferie"]("Mario Rossi", 2026, orari_dashboard(), self.GIUST, self.RICH, self.SALDI, datetime.date(2026, 9, 25))
        self.assertEqual(s["godute"], 3.0)         # 21, 22 e 25 settembre (oggi incluso); il 2025 non conta
        self.assertEqual(s["programmate"], 1.5)    # 5 ottobre + mezza il 2 novembre
        # in attesa 2026: 5 + 0,5 + (28,29,30 dic; il 24 e' chiusura, 25 e 26 festivi, 31 dic mer) = 5 + 0,5 + 4
        self.assertEqual(s["in_attesa"], 9.5)
        self.assertEqual(s["disponibili"], 26 + 3 - 3 - 1.5)
        self.assertEqual(s["disponibili_se_approvate"], 24.5 - 9.5)
        self.assertTrue(s["configurato"])
        self.assertFalse(A["saldo_ferie"]("Laura Bianchi", 2026, orari_dashboard(), {}, [], self.SALDI, datetime.date(2026, 9, 25))["configurato"])

    def test_parita_con_php(self):
        nomi_anni = [("Mario Rossi", 2026), ("Mario Rossi", 2027), ("Laura Bianchi", 2026), ("Laura Bianchi", 2025), ("Senza Orari", 2026)]
        r = esegui_php({"casi": [], "saldi_casi": nomi_anni, "pin_casi": []}, self.GIUST, self.RICH, self.SALDI)
        for (nome, anno), php in zip(nomi_anni, r["saldi"]):
            mio = A["saldo_ferie"](nome, anno, orari_dashboard(), self.GIUST, self.RICH, self.SALDI, datetime.date(2026, 9, 25))
            for k in ("godute", "programmate", "in_attesa", "disponibili", "disponibili_se_approvate", "spettanti", "residuo"):
                self.assertAlmostEqual(mio[k], php[k], msg=f"{nome} {anno} {k}")
            self.assertEqual(mio["configurato"], php["configurato"], f"{nome} {anno}")


class TestPin(Base):
    def test_hash_e_verifica(self):
        h = A["crea_pin_hash"]("4321")
        self.assertNotIn("4321", h)
        self.assertTrue(A["verifica_pin_hash"]("4321", h))
        self.assertFalse(A["verifica_pin_hash"]("4322", h))
        self.assertNotEqual(A["crea_pin_hash"]("4321"), h, "il sale deve cambiare l'impronta")
        self.assertFalse(A["verifica_pin_hash"]("4321", "boh"))

    def test_validita(self):
        for ok in ("1234", "00000000", "9999"):
            self.assertTrue(A["pin_valido"](ok))
        for ko in ("123", "123456789", "12a4", "", " 1234"):
            self.assertFalse(A["pin_valido"](ko))
        self.assertTrue(A["pin_valido"](A["nuovo_pin_casuale"]()))

    def test_compatibile_con_php_nei_due_versi(self):
        h = A["crea_pin_hash"]("4321")
        r = esegui_php({"casi": [], "saldi_casi": [], "pin_casi": [["4321", h], ["0000", h]]})
        self.assertEqual(r["pin"], [True, False], "il PHP deve riconoscere l'impronta creata dalla dashboard")
        self.assertTrue(A["verifica_pin_hash"](r["pin_php"]["pin"], r["pin_php"]["hash"]), "la dashboard deve riconoscere quella del PHP")


class TestApprovazione(Base):
    def setUp(self):
        super().setUp()
        # il test gira in una cartella vuota: anche il modulo "carica_correzioni" usa file relativi
        os.makedirs("richieste_ferie")
        self.orari = orari_dashboard()

    def scrivi_richiesta(self, id_, nome="Mario Rossi", dal="2026-10-12", al="2026-10-16", mezza=None, stato="in_attesa"):
        r = {"id": id_, "nome": nome, "tipo": "ferie", "dal": dal, "al": al, "mezza_giornata": mezza, "giorni_lavorativi": 5,
             "nota": "", "stato": stato, "creata_il": "2026-10-01T09:30:12", "deciso_il": None, "deciso_da": None, "motivo_rifiuto": None}
        scrivi_json(f"richieste_ferie/{id_}.json", r)
        return r

    def giust(self):
        return A["carica_correzioni"]()["giustificativi"]

    def test_approva_scrive_solo_i_giorni_lavorativi(self):
        self.scrivi_richiesta("r1", "Laura Bianchi", "2026-10-12", "2026-10-18")  # lun-dom, venerdi' di riposo per Laura
        ok, msg = A["approva_richiesta_ferie"]("r1", "ufficio", self.orari)
        self.assertTrue(ok, msg)
        self.assertEqual(sorted(self.giust()), [f"2026-10-{g}|Laura Bianchi" for g in ("12", "13", "14", "15")])
        self.assertEqual(set(self.giust().values()), {"F"})
        r = A["leggi_richiesta_ferie"]("r1")
        self.assertEqual((r["stato"], r["deciso_da"]), ("approvata", "ufficio"))
        self.assertTrue(r["deciso_il"])

    def test_non_si_approva_due_volte_ne_una_annullata(self):
        self.scrivi_richiesta("r1")
        self.assertTrue(A["approva_richiesta_ferie"]("r1", "ufficio", self.orari)[0])
        ok, msg = A["approva_richiesta_ferie"]("r1", "ufficio", self.orari)
        self.assertFalse(ok)
        self.assertIn("non è più in attesa", msg)
        self.scrivi_richiesta("r2", stato="annullata")
        self.assertFalse(A["approva_richiesta_ferie"]("r2", "ufficio", self.orari)[0])
        self.assertEqual(len(self.giust()), 5)  # solo la prima

    def test_mezza_giornata(self):
        self.scrivi_richiesta("r3", dal="2026-10-19", al="2026-10-19", mezza="pomeriggio")
        ok, msg = A["approva_richiesta_ferie"]("r3", "ufficio", self.orari)
        self.assertTrue(ok, msg)
        self.assertEqual(self.giust(), {"2026-10-19|Mario Rossi": "F½"})
        self.assertIn("0,5", msg)

    def test_non_sovrascrive_la_malattia_e_rispetta_le_altre_correzioni(self):
        scrivi_json("correzioni_timbrature.json",
                    {"aggiunte": [{"data": "2026-09-24", "nome": "Mario Rossi", "orario": "16:30:00", "evento": "ESCE", "nota": "", "quando": "x"}],
                     "eliminate": [], "giustificativi": {"2026-10-14|Mario Rossi": "M", "2026-09-21|Mario Rossi": "F"}})
        self.scrivi_richiesta("r4")
        ok, msg = A["approva_richiesta_ferie"]("r4", "ufficio", self.orari)
        self.assertTrue(ok, msg)
        self.assertIn("malattia", msg)
        g = self.giust()
        self.assertEqual(g["2026-10-14|Mario Rossi"], "M")
        self.assertEqual(g["2026-10-12|Mario Rossi"], "F")
        self.assertEqual(g["2026-09-21|Mario Rossi"], "F")
        self.assertEqual(len(A["carica_correzioni"]()["aggiunte"]), 1, "le timbrature aggiunte non vanno perse")

    def test_periodo_senza_giorni_lavorativi(self):
        self.scrivi_richiesta("r5", dal="2026-10-17", al="2026-10-18")
        ok, msg = A["approva_richiesta_ferie"]("r5", "ufficio", self.orari)
        self.assertFalse(ok)
        self.assertEqual(A["leggi_richiesta_ferie"]("r5")["stato"], "in_attesa")

    def test_rifiuto_richiede_il_motivo(self):
        self.scrivi_richiesta("r6")
        self.assertFalse(A["rifiuta_richiesta_ferie"]("r6", "   ", "ufficio")[0])
        self.assertEqual(A["leggi_richiesta_ferie"]("r6")["stato"], "in_attesa")
        ok, _ = A["rifiuta_richiesta_ferie"]("r6", "Periodo di inventario", "ufficio")
        self.assertTrue(ok)
        r = A["leggi_richiesta_ferie"]("r6")
        self.assertEqual((r["stato"], r["motivo_rifiuto"]), ("rifiutata", "Periodo di inventario"))
        self.assertEqual(self.giust(), {}, "un rifiuto non scrive nessuna ferie")

    def test_id_pericolosi_rifiutati(self):
        for cattivo in ("../orari_lavoro", "a/b", "", "x y"):
            with self.assertRaises(ValueError):
                A["leggi_richiesta_ferie"](cattivo)

    def test_elenco_ignora_file_rovinati(self):
        self.scrivi_richiesta("ok1")
        for nome_file, testo in (("rotto", "{non json"), ("incompleto", '{"id": "x"}')):
            with open(f"richieste_ferie/{nome_file}.json", "w") as f:
                f.write(testo)
        self.assertEqual([r["id"] for r in A["carica_richieste_ferie"]()], ["ok1"])

    def test_file_dell_app_php_letto_dalla_dashboard(self):
        """Una richiesta creata davvero dal PHP deve essere leggibile e approvabile dalla dashboard."""
        dati = tempfile.mkdtemp()
        script = f"""<?php
define('DATI_DIR', {json.dumps(dati)}); define('RICHIESTE_DIR', {json.dumps(dati + '/richieste_ferie')});
require {json.dumps(os.path.join(QUI, '..', 'ferie', 'lib.php'))};
$GLOBALS['ORARI_TEST'] = json_decode({json.dumps(json.dumps(ORARI_JSON))}, true);
$GLOBALS['IMP_TEST'] = json_decode({json.dumps(json.dumps(IMPOSTAZIONI))}, true);
$GLOBALS['OGGI_TEST'] = '2026-09-25';
$GLOBALS['SALDI_TEST'] = ['Mario Rossi' => ['anno' => 2026, 'giorni_spettanti' => 26]];
$GLOBALS['CORREZIONI_TEST'] = ['giustificativi' => []];
$r = crea_richiesta('Mario Rossi', '2026-10-12', '2026-10-16', null, 'Viaggio');
echo $r['id'];
"""
        r = subprocess.run(["php", "-r", script.replace("<?php", "", 1)], capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        id_ = r.stdout.strip()
        os.rmdir("richieste_ferie")
        os.symlink(os.path.join(dati, "richieste_ferie"), "richieste_ferie")
        lette = A["carica_richieste_ferie"]()
        self.assertEqual([x["id"] for x in lette], [id_])
        self.assertEqual((lette[0]["nome"], lette[0]["stato"], lette[0]["giorni_lavorativi"]), ("Mario Rossi", "in_attesa", 5))
        self.assertTrue(A["approva_richiesta_ferie"](id_, "ufficio", self.orari)[0])
        self.assertEqual(len(self.giust()), 5)


class FinteSMTP(socketserver.StreamRequestHandler):
    """Mini server SMTP (senza cifratura) che registra i messaggi ricevuti."""
    ricevuti = []

    def _r(self, testo):
        self.wfile.write((testo + "\r\n").encode())

    def handle(self):
        self._r("220 finto smtp")
        msg = {"rcpt": [], "auth": []}
        while True:
            riga = self.rfile.readline().decode(errors="replace").rstrip("\r\n")
            if not riga:
                return
            cmd = riga.upper()
            if cmd.startswith("EHLO"):
                self.wfile.write(b"250-finto\r\n250 AUTH LOGIN PLAIN\r\n")
            elif cmd.startswith("AUTH LOGIN"):
                self._r("334 VXNlcm5hbWU6")
                msg["auth"].append(base64.b64decode(self.rfile.readline().strip()).decode())
                self._r("334 UGFzc3dvcmQ6")
                msg["auth"].append(base64.b64decode(self.rfile.readline().strip()).decode())
                self._r("235 ok")
            elif cmd.startswith("AUTH PLAIN"):
                parti = riga.split()
                blob = base64.b64decode(parti[2]).decode().split("\0") if len(parti) > 2 else []
                msg["auth"] += blob[1:]
                self._r("235 ok")
            elif cmd.startswith("MAIL FROM"):
                msg["from"] = riga[10:]
                self._r("250 ok")
            elif cmd.startswith("RCPT TO"):
                msg["rcpt"].append(riga[8:])
                self._r("250 ok")
            elif cmd == "DATA":
                self._r("354 avanti")
                righe = []
                while True:
                    l = self.rfile.readline().decode(errors="replace").rstrip("\r\n")
                    if l == ".":
                        break
                    righe.append(l[1:] if l.startswith("..") else l)
                msg["data"] = "\r\n".join(righe)
                FinteSMTP.ricevuti.append(msg)
                self._r("250 ricevuto")
            elif cmd == "QUIT":
                self._r("221 ciao")
                return
            else:
                self._r("250 ok")


class TestEmail(Base):
    @classmethod
    def setUpClass(cls):
        socketserver.ThreadingTCPServer.allow_reuse_address = True
        cls.srv = socketserver.ThreadingTCPServer(("127.0.0.1", 0), FinteSMTP)
        cls.porta = cls.srv.server_address[1]
        threading.Thread(target=cls.srv.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls):
        cls.srv.shutdown()
        cls.srv.server_close()

    def setUp(self):
        super().setUp()
        FinteSMTP.ricevuti.clear()

    def cfg(self, **extra):
        c = {"attiva": True, "smtp_host": "127.0.0.1", "smtp_porta": self.porta, "smtp_sicurezza": "nessuna",
             "smtp_utente": "utente", "smtp_password": "segreta", "mittente": "ferie@azienda.test", "mittente_nome": "Ferie àzienda"}
        c.update(extra)
        return c

    def test_dashboard_invia(self):
        ok, err = A["invia_mail_ferie"](["mario@azienda.test", "sbagliato"], "Ferie approvate", "Ciao Mario, è tutto ok.\n", self.cfg())
        self.assertTrue(ok, err)
        m = FinteSMTP.ricevuti[0]
        self.assertEqual(m["auth"], ["utente", "segreta"])
        self.assertEqual(len(m["rcpt"]), 1)
        self.assertIn("mario@azienda.test", m["rcpt"][0])
        letto = email.message_from_string(m["data"])
        self.assertEqual(str(email.header.make_header(email.header.decode_header(letto["Subject"]))), "Ferie approvate")
        self.assertIn("è tutto ok", letto.get_payload(decode=True).decode("utf-8"))

    def test_dashboard_non_lancia_mai(self):
        self.assertEqual(A["invia_mail_ferie"](["a@b.it"], "x", "y", self.cfg(attiva=False))[0], False)
        self.assertEqual(A["invia_mail_ferie"]([], "x", "y", self.cfg())[0], False)
        ok, err = A["invia_mail_ferie"](["a@b.it"], "x", "y", self.cfg(smtp_porta=1))  # porta chiusa
        self.assertFalse(ok)
        self.assertTrue(err)

    def test_notifica_esito_usa_email_del_dipendente(self):
        os.makedirs("richieste_ferie")
        scrivi_json("ferie_saldi.json", {"Mario Rossi": {"anno": 2026, "giorni_spettanti": 26, "email": "mario@azienda.test"}})
        scrivi_json("ferie_config.json", self.cfg())
        scrivi_json("richieste_ferie/r1.json", {"id": "r1", "nome": "Mario Rossi", "dal": "2026-10-12", "al": "2026-10-16",
                                                "stato": "rifiutata", "motivo_rifiuto": "Inventario", "creata_il": "x"})
        frase = A["notifica_esito_ferie"]("r1")
        self.assertIn("mario@azienda.test", frase)
        corpo = email.message_from_string(FinteSMTP.ricevuti[0]["data"]).get_payload(decode=True).decode("utf-8")
        self.assertIn("Inventario", corpo)
        self.assertIn("12/10/2026 - 16/10/2026", corpo)

    def test_app_php_invia(self):
        r = subprocess.run(["php", os.path.join(QUI, "mail_php.php"), str(self.porta)], capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertEqual(json.loads(r.stdout), [True, ""])
        m = FinteSMTP.ricevuti[0]
        self.assertEqual(m["auth"], ["utente", "segreta"])
        self.assertEqual(len(m["rcpt"]), 1, "l'indirizzo non valido va scartato")
        letto = email.message_from_string(m["data"])
        self.assertIn("Nuova richiesta ferie: Mario Rossi", str(email.header.make_header(email.header.decode_header(letto["Subject"]))))
        corpo = letto.get_payload(decode=True).decode("utf-8")
        self.assertIn("12/10/2026 - 16/10/2026 = 5 giorni lavorativi", corpo)
        self.assertIn(".punto solo in riga", corpo)


if __name__ == "__main__":
    unittest.main(verbosity=1)
