"""Prepara la cartella di PROVA per usare app ferie + dashboard sul proprio PC,
con dati dimostrativi (nessun dato reale, nulla viene toccato sul NAS).

Crea  prova_pc/dati_prova/  con: APP.py (la dashboard), orari, festivi, timbrature di esempio,
i giorni di ferie e i PIN dei dipendenti di prova. Si puo' rilanciare quando si vuole:
con l'opzione --azzera riparte da zero, altrimenti conserva quello che hai provato."""
import hashlib
import json
import os
import secrets
import shutil
import sys

QUI = os.path.dirname(os.path.abspath(__file__))
RADICE = os.path.dirname(QUI)
DEST = os.path.join(QUI, "dati_prova")
PIN_PROVA = {"Mario Rossi": "1111", "Laura Bianchi": "2222", "Giorgio Verdi": "3333"}


def crea_pin_hash(pin, iterazioni=100000):
    sale = secrets.token_bytes(16)
    h = hashlib.pbkdf2_hmac("sha256", pin.encode(), sale, iterazioni)
    return f"pbkdf2${iterazioni}${sale.hex()}${h.hex()}"


def main():
    if "--azzera" in sys.argv and os.path.isdir(DEST):
        shutil.rmtree(DEST)
    os.makedirs(DEST, exist_ok=True)
    # la dashboard va sempre aggiornata all'ultima versione; i dati di prova no
    shutil.copy2(os.path.join(RADICE, "dashboard", "APP.py"), os.path.join(DEST, "APP.py"))
    esempi = os.path.join(RADICE, "esempi_dati")
    for nome in ("orari_lavoro.json", "impostazioni.json", "alias_nomi.json", "correzioni_timbrature.json"):
        if not os.path.exists(os.path.join(DEST, nome)):
            shutil.copy2(os.path.join(esempi, nome), os.path.join(DEST, nome))
    presenze = os.path.join(DEST, "presenze")
    if not os.path.isdir(presenze):
        shutil.copytree(os.path.join(esempi, "presenze"), presenze)
    with open(os.path.join(DEST, "config_cartella_log.txt"), "w", encoding="utf-8") as f:
        f.write(presenze)
    if not os.path.exists(os.path.join(DEST, "password_orari.txt")):
        with open(os.path.join(DEST, "password_orari.txt"), "w", encoding="utf-8") as f:
            f.write("0")
    saldi = os.path.join(DEST, "ferie_saldi.json")
    if not os.path.exists(saldi):
        dati = {
            "Mario Rossi": {"anno": 2026, "giorni_spettanti": 26, "residuo_anno_precedente": 3},
            "Laura Bianchi": {"anno": 2026, "giorni_spettanti": 22, "residuo_anno_precedente": 0},
            "Giorgio Verdi": {"anno": 2026, "giorni_spettanti": 10, "residuo_anno_precedente": 1},
        }
        for nome, pin in PIN_PROVA.items():
            dati[nome]["pin_hash"] = crea_pin_hash(pin)
        with open(saldi, "w", encoding="utf-8") as f:
            json.dump(dati, f, ensure_ascii=False, indent=2)
    print("Cartella di prova pronta:", DEST)
    print("\nDipendenti di prova e loro PIN:")
    for nome, pin in PIN_PROVA.items():
        print(f"   {nome:15s} PIN {pin}")
    print("\nPassword della dashboard (schede protette): 0")


if __name__ == "__main__":
    main()
