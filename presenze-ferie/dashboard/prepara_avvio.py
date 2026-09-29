# Eseguito da avvia_dashboard.bat PRIMA di avviare la dashboard.
# Dice al browser che la pagina e' in italiano e che NON va tradotta
# (altrimenti Edge/Chrome chiedono ogni volta "Tradurre la pagina dall'inglese?").
import os
import re

try:
    import streamlit
    percorso = os.path.join(os.path.dirname(streamlit.__file__), "static", "index.html")
    with open(percorso, "r", encoding="utf-8") as f:
        testo = f.read()
    if 'translate="no"' in testo:
        print("Lingua della pagina: italiano (gia' impostato).")
    else:
        testo = re.sub(r"<html[^>]*>", '<html lang="it" translate="no" class="notranslate">', testo, count=1)
        testo = re.sub(r"(<head[^>]*>)", r'\1\n    <meta name="google" content="notranslate" />', testo, count=1)
        with open(percorso, "w", encoding="utf-8") as f:
            f.write(testo)
        print("Lingua della pagina impostata su italiano (niente piu' richiesta di traduzione).")
except Exception as e:
    print("Attenzione: non sono riuscito a impostare la lingua della pagina:", e)
