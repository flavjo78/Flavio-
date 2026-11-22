"""ANNOTAZIONI POKER: registra la partita SENZA nessun aiuto, poi la rivedi con i suggerimenti.

Regole:
- durante la registrazione il programma NON analizza e NON consiglia nulla: salva solo foto;
- in modalità RIPASSO (predefinita) i suggerimenti si vedono solo con l'interruttore ACCESO e
  la finestra del gioco registrato CHIUSA;
- la modalità DAL VIVO (finestra COACH.bat) la attivi tu, con una conferma, per le partite contro
  il computer: lì il coach consiglia in tempo reale.

    py -m coach.annotazioni
"""
import json
import os
import sys
import threading
import time
import webbrowser

from . import impara, schermo
from .ripasso import analizza, salva_pagina

CONFIG = "annotazioni.json"


def carica_config(path=CONFIG):
    if os.path.exists(path):
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    return {"suggerimenti": False, "dal_vivo": False, "ultima": None}


def salva_config(cfg, path=CONFIG):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(cfg, f, ensure_ascii=False, indent=1)


def gioco_aperto(ultima):
    """True se la finestra del gioco registrato è ancora aperta."""
    if not ultima:
        return False
    if ultima.get("handle") is not None and sys.platform.startswith("win"):
        f = schermo.aggiorna(schermo.Finestra(ultima.get("titolo", ""), ultima["handle"], 0, 0, 0, 0))
        if f is not None:
            return True
    titolo = (ultima.get("titolo") or "").strip()
    return bool(titolo) and schermo.trova(titolo) is not None


def puo_rivedere(cfg):
    """(sì/no, motivo). Il ripasso con i suggerimenti richiede interruttore acceso e gioco chiuso."""
    if not cfg.get("suggerimenti"):
        return False, "I suggerimenti sono SPENTI. Accendili dal menù (scelta 3) quando vuoi rivedere."
    if not cfg.get("ultima"):
        return False, "Non hai ancora registrato nessuna partita."
    if not cfg.get("dal_vivo") and gioco_aperto(cfg["ultima"]):
        return False, ("Il gioco è ancora aperto: chiudilo del tutto e riprova. "
                       "I suggerimenti si vedono solo a partita finita.")
    return True, ""


def registra_partita(cfg, finestra=None, durata=None, db="storia.db"):
    from .storia import Storia
    if finestra is None:
        finestra = impara.scegli_finestra()
        if finestra is None:
            return None
    st = Storia(db)
    pid = st.nuova_partita(gioco=finestra.titolo, note="annotazioni")
    st.db.close()
    cartella = os.path.join("sessioni", f"partita_{time.strftime('%Y%m%d_%H%M')}")
    print("\nREGISTRAZIONE IN CORSO. Nessun suggerimento durante il gioco: gioca da solo.")
    stop = threading.Event()
    t = threading.Thread(target=schermo.registra, kwargs=dict(
        titolo=None, finestra=finestra, cartella=cartella, intervallo=1.0,
        durata=durata, stop=stop, formato="jpg"))
    t.start()
    if durata:
        t.join()
    else:
        time.sleep(0.5)
        try:
            input(">>> A fine partita premi INVIO qui per fermare la registrazione.\n")
        except (EOFError, KeyboardInterrupt):
            pass
        stop.set()
        t.join()
    cfg["ultima"] = {"cartella": cartella, "titolo": finestra.titolo, "handle": finestra.handle,
                     "partita": pid, "fine": time.strftime("%Y-%m-%d %H:%M")}
    salva_config(cfg)
    print("Partita salvata. Per rivederla con i suggerimenti: chiudi il gioco, accendi i suggerimenti, scelta 2.")
    return cfg["ultima"]


def rivedi(cfg, db="storia.db", apri=True):
    ok, motivo = puo_rivedere(cfg)
    if not ok:
        print("\n" + motivo)
        return None
    from .storia import Storia
    u = cfg["ultima"]
    analisi = analizza(Storia(db), u.get("partita"))
    if analisi is None:
        analisi = {"partita": u.get("partita"), "mani": [], "n_mani": 0, "n_decisioni": 0,
                   "accordo": None, "gravi": 0, "risultato": 0}
    foto = []
    if os.path.isdir(u["cartella"]):
        nomi = sorted(x for x in os.listdir(u["cartella"]) if x.lower().endswith((".jpg", ".png")))
        foto = [os.path.join(u["cartella"], x) for x in impara.scegli_foto(nomi, max_foto=200)]
    path = salva_pagina(analisi, "ripassi", foto)
    print(f"\nRipasso pronto: {os.path.abspath(path)}")
    if apri:
        webbrowser.open("file:///" + os.path.abspath(path).replace(os.sep, "/"))
    return path


def zip_per_claude(cfg):
    u = cfg.get("ultima")
    if u and os.path.isdir(u["cartella"]):
        z, tot, messe = impara.crea_zip_impara(u["cartella"], nota="partita registrata con Annotazioni")
        print(f"Foto: {os.path.abspath(z)} ({messe} su {tot})")
    z = impara.crea_zip_risultati()
    print(f"Risultati: {os.path.abspath(z)}")
    print("Carica gli zip nella chat con Claude.")
    impara._apri_cartella(impara.CARTELLA_PACCHETTI)


def menu():
    cfg = carica_config()
    while True:
        stato = "ACCESI" if cfg.get("suggerimenti") else "SPENTI"
        print("\n=========== ANNOTAZIONI POKER ===========")
        print(f" Suggerimenti nel ripasso: {stato}  (durante il gioco: mai)")
        if cfg.get("ultima"):
            print(f" Ultima partita: {cfg['ultima']['fine']}")
        print(" 1) Registra una partita (nessun suggerimento)")
        print(" 2) Rivedi l'ultima partita con i suggerimenti")
        print(f" 3) {'Spegni' if cfg.get('suggerimenti') else 'Accendi'} i suggerimenti del ripasso")
        print(" 4) Prepara gli zip per Claude")
        print(f" 5) Modalità: {'DAL VIVO' if cfg.get('dal_vivo') else 'RIPASSO'} (cambia)   -> finestra: COACH.bat")
        print(" 0) Esci")
        try:
            r = input("Scelta: ").strip()
        except (EOFError, KeyboardInterrupt):
            return
        if r == "1":
            registra_partita(cfg)
        elif r == "2":
            rivedi(cfg)
        elif r == "3":
            cfg["suggerimenti"] = not cfg.get("suggerimenti")
            salva_config(cfg)
        elif r == "4":
            zip_per_claude(cfg)
        elif r == "5":
            if cfg.get("dal_vivo"):
                cfg["dal_vivo"] = False
            else:
                from .finestra import CONFERMA_VIVO
                print("\n" + CONFERMA_VIVO)
                cfg["dal_vivo"] = input("Scrivi SI per attivarla: ").strip().lower() in ("si", "sì")
            salva_config(cfg)
        elif r == "0":
            return


if __name__ == "__main__":
    menu()
