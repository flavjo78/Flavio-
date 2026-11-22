"""Modalità IMPARA e pacchetto RISULTATI: preparano uno zip da caricare nella chat con Claude.

    py -m coach.impara              modalità impara: scegli la finestra, registra, INVIO per fermare -> zip
    py -m coach.impara risultati    zip con archivio mani, ritratti degli avversari e una tua nota

Gli zip finiscono nella cartella `pacchetti/` (si apre da sola alla fine).
"""
import contextlib
import io
import os
import platform
import shutil
import sys
import threading
import time
import zipfile

from . import __version__, schermo

CARTELLA_PACCHETTI = "pacchetti"
MAX_FOTO = 180          # foto massime nello zip
MAX_MB = 25             # grandezza massima indicativa dello zip


def _ora():
    return time.strftime("%Y%m%d_%H%M")


def _apri_cartella(path):
    if sys.platform.startswith("win"):
        try:
            os.startfile(os.path.abspath(path))
        except Exception:
            pass


def info_sistema(finestra=None):
    righe = [f"Poker Coach {__version__}", f"Data: {time.strftime('%Y-%m-%d %H:%M:%S')}",
             f"Sistema: {platform.platform()}", f"Python: {platform.python_version()}"]
    try:
        with schermo._MSS() as sct:
            for i, m in enumerate(sct.monitors[1:], start=1):
                righe.append(f"Monitor {i}: {m['width']}x{m['height']} in ({m['left']},{m['top']})")
    except Exception as e:
        righe.append(f"Monitor: non leggibili ({e})")
    if sys.platform.startswith("win"):
        try:
            import ctypes
            righe.append(f"Zoom di Windows: {ctypes.windll.shcore.GetScaleFactorForDevice(0)}%")
        except Exception:
            pass
    try:
        from .partita import carica
        p = carica()
        if p:
            righe.append("Partita: " + p.riassunto())
    except Exception:
        pass
    if finestra:
        righe.append(f"Finestra del gioco: «{finestra.titolo}» area {finestra.w}x{finestra.h}"
                     f" in ({finestra.x},{finestra.y})")
    try:
        righe.append("Finestre aperte:")
        righe += [f"  {f}" for f in schermo.elenca_finestre()]
    except Exception:
        pass
    return "\n".join(righe) + "\n"


def scegli_finestra():
    fin = sorted(schermo.elenca_finestre(), key=lambda f: -f.w * f.h)
    if not fin:
        print("Non trovo finestre aperte. Apri il gioco e riprova.")
        return None
    print("\nFinestre aperte:")
    for i, f in enumerate(fin, start=1):
        print(f"  {i:>2}) {f}")
    while True:
        r = input("\nScrivi il NUMERO della finestra del poker e premi INVIO: ").strip()
        if r.isdigit() and 1 <= int(r) <= len(fin):
            return fin[int(r) - 1]
        print("Numero non valido.")


def scegli_foto(nomi, max_foto=MAX_FOTO, max_byte=MAX_MB * 1024 * 1024, grandezze=None):
    """Se le foto sono troppe o pesano troppo, ne tiene una parte distribuita in modo uniforme."""
    n = len(nomi)
    if not n:
        return []
    peso_medio = (sum(grandezze) / n) if grandezze else 0
    limite = max_foto
    if peso_medio:
        limite = min(limite, max(1, int(max_byte // peso_medio)))
    if n <= limite:
        return list(nomi)
    passo = (n - 1) / (limite - 1) if limite > 1 else n
    scelti = sorted({round(i * passo) for i in range(limite)})
    return [nomi[i] for i in scelti]


def crea_zip_impara(cartella, finestra=None, nota="", dest=CARTELLA_PACCHETTI):
    os.makedirs(dest, exist_ok=True)
    foto = sorted(x for x in os.listdir(cartella) if x.lower().endswith((".png", ".jpg")))
    pesi = [os.path.getsize(os.path.join(cartella, x)) for x in foto]
    scelte = scegli_foto(foto, grandezze=pesi)
    nome_zip = os.path.join(dest, f"poker_impara_{_ora()}.zip")
    with zipfile.ZipFile(nome_zip, "w", zipfile.ZIP_DEFLATED) as z:
        for x in scelte:
            z.write(os.path.join(cartella, x), f"foto/{x}")
        for extra in ("indice.csv", "finestra.json"):
            p = os.path.join(cartella, extra)
            if os.path.exists(p):
                z.write(p, extra)
        if os.path.exists("partita.json"):
            z.write("partita.json", "partita.json")
        testo = info_sistema(finestra)
        testo += f"\nFoto registrate: {len(foto)}, nello zip: {len(scelte)}\n"
        if nota:
            testo += f"\nNota del giocatore:\n{nota}\n"
        z.writestr("info.txt", testo)
    return nome_zip, len(foto), len(scelte)


def impara(finestra=None, intervallo=1.0, durata=None, chiedi_nota=True):
    print("\n=== MODALITÀ IMPARA ===")
    print("Registro la finestra del poker mentre giochi, così Claude impara la grafica e le carte.")
    if finestra is None:
        finestra = scegli_finestra()
        if finestra is None:
            return None
    cartella = os.path.join("sessioni", f"impara_{_ora()}")
    stop = threading.Event()
    t = threading.Thread(target=schermo.registra, kwargs=dict(
        titolo=None, finestra=finestra, cartella=cartella, intervallo=intervallo,
        durata=durata, stop=stop, formato="jpg"))
    t.start()
    if durata:
        t.join()
    else:
        time.sleep(0.5)
        print("\n>>> Gioca normalmente: più mani diverse vedo, meglio è "
              "(carte sul tavolo, all-in, fine mano, carte mostrate).")
        try:
            input(">>> Quando hai finito premi INVIO qui per fermare e preparare lo zip.\n")
        except (EOFError, KeyboardInterrupt):
            pass
        stop.set()
        t.join()
    nota = ""
    if chiedi_nota:
        try:
            nota = input("Vuoi scrivere una nota per Claude (es. nome del gioco)? INVIO per saltare: ").strip()
        except (EOFError, KeyboardInterrupt):
            pass
    nome_zip, tot, messe = crea_zip_impara(cartella, finestra, nota)
    mb = os.path.getsize(nome_zip) / 1024 / 1024
    print(f"\nPRONTO: {os.path.abspath(nome_zip)}  ({mb:.1f} MB, {messe} foto su {tot})")
    print("Caricalo nella chat con Claude.")
    _apri_cartella(CARTELLA_PACCHETTI)
    return nome_zip


def crea_zip_risultati(db="storia.db", nota="", dest=CARTELLA_PACCHETTI):
    from .storia import Storia, main as storia_main
    from .ritratto import riepilogo
    os.makedirs(dest, exist_ok=True)
    nome_zip = os.path.join(dest, f"poker_risultati_{_ora()}.zip")
    report = io.StringIO()
    if os.path.exists(db):
        st = Storia(db)
        for titolo, argv in (("GIOCATORI", ["giocatori"]), ("ULTIME MANI", ["mani", "--limite", "30"])):
            report.write(f"===== {titolo} =====\n")
            with contextlib.redirect_stdout(report):
                storia_main(["--db", db] + argv)
            report.write("\n")
        report.write("===== AVVERSARI =====\n" + riepilogo(st) + "\n")
        st.db.close()
    else:
        report.write("Archivio mani ancora vuoto (le mani si registreranno da sole quando il "
                     "programma saprà leggere il tavolo).\n")
    with zipfile.ZipFile(nome_zip, "w", zipfile.ZIP_DEFLATED) as z:
        if os.path.exists(db):
            copia = db + ".copia"
            shutil.copyfile(db, copia)
            z.write(copia, "storia.db")
            os.remove(copia)
        if os.path.exists("partita.json"):
            z.write("partita.json", "partita.json")
        z.writestr("report.txt", report.getvalue())
        z.writestr("info.txt", info_sistema() + (f"\nNota del giocatore:\n{nota}\n" if nota else ""))
    return nome_zip


def risultati(db="storia.db", chiedi_nota=True):
    print("\n=== RISULTATI DELLA PARTITA ===")
    nota = ""
    if chiedi_nota:
        try:
            nota = input("Com'è andata? Scrivi una nota per Claude (INVIO per saltare): ").strip()
        except (EOFError, KeyboardInterrupt):
            pass
    nome_zip = crea_zip_risultati(db, nota)
    print(f"\nPRONTO: {os.path.abspath(nome_zip)}\nCaricalo nella chat con Claude.")
    _apri_cartella(CARTELLA_PACCHETTI)
    return nome_zip


def main(argv=None):
    import argparse
    ap = argparse.ArgumentParser(prog="coach.impara")
    ap.add_argument("cosa", nargs="?", default="impara", choices=("impara", "risultati"))
    ap.add_argument("--titolo", help="parte del titolo della finestra (salta la scelta dal menù)")
    ap.add_argument("--intervallo", type=float, default=1.0)
    ap.add_argument("--durata", type=float, help="ferma da solo dopo N secondi")
    ap.add_argument("--db", default="storia.db")
    ap.add_argument("--senza-domande", action="store_true")
    a = ap.parse_args(argv)
    if a.cosa == "risultati":
        risultati(a.db, not a.senza_domande)
        return
    fin = schermo.trova(a.titolo) if a.titolo else None
    if a.titolo and not fin:
        raise SystemExit(f"Nessuna finestra con «{a.titolo}» nel titolo.")
    impara(fin, a.intervallo, a.durata, not a.senza_domande)


if __name__ == "__main__":
    main()
