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


def aggiorna_config(chiavi, path=CONFIG):
    """Aggiorna SOLO le voci indicate, rileggendo il file: due programmi aperti non si cancellano a vicenda."""
    cfg = carica_config(path)
    cfg.update(chiavi)
    salva_config(cfg, path)
    return cfg


def imposta_password(cfg, password):
    """Salva solo l'impronta (PBKDF2 con sale), mai la password in chiaro."""
    import hashlib
    import os as _os
    if len(password) < 4:
        raise ValueError("La password deve avere almeno 4 caratteri")
    sale = _os.urandom(16)
    cfg["pw_vivo"] = {"sale": sale.hex(),
                      "impronta": hashlib.pbkdf2_hmac("sha256", password.encode(), sale, 200_000).hex()}


def password_giusta(cfg, password):
    import hashlib
    import hmac
    pw = cfg.get("pw_vivo")
    if not pw:
        return False
    calc = hashlib.pbkdf2_hmac("sha256", (password or "").encode(), bytes.fromhex(pw["sale"]), 200_000).hex()
    return hmac.compare_digest(calc, pw["impronta"])


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
    st = Storia(db)
    ha_mani = st.db.execute("SELECT COUNT(*) FROM mani WHERE partita_id=?", (u.get("partita"),)).fetchone()[0]
    if not ha_mani and os.path.isdir(u["cartella"]):
        from .calibra import carica as carica_cal
        cal = carica_cal()
        if cal:
            from .partita import carica as carica_partita
            from .ricostruisci import analizza_registrazione
            p = carica_partita()
            print("Analizzo le foto della partita (può volerci un po')...")
            esito = analizza_registrazione(u["cartella"], st, u.get("partita"), cal, p.bb if p else 20,
                                           p.ante if p else 0, titolo=u.get("titolo"))
            if esito["bloccata"]:
                print("Partita registrata su un sito a soldi veri: il lettore non la analizza.")
            else:
                print(f"Foto lette: {esito['foto']} (sicure {esito['sicure']}), mani ricostruite: {esito['mani']}")
                if esito.get("incerte"):
                    print("Zone lette con dubbi (insegnale con LETTORE.bat): " +
                          ", ".join(f"{z} ({n})" for z, n in esito["incerte"][:6]))
    analisi = analizza(st, u.get("partita"))
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
                if not cfg.get("pw_vivo"):
                    print("Prima scegli una password: servirà ogni volta per accendere «Dal vivo».")
                    try:
                        imposta_password(cfg, input("Nuova password: "))
                    except ValueError as e:
                        print(e)
                        continue
                cfg["dal_vivo"] = password_giusta(cfg, input("Password per «Dal vivo»: "))
                if not cfg["dal_vivo"]:
                    print("Password sbagliata: resto in RIPASSO.")
            salva_config(cfg)
        elif r == "0":
            return


if __name__ == "__main__":
    menu()
