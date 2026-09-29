@echo off
rem ------------------------------------------------------------------
rem  Avvio Dashboard Presenze
rem  NOTA: il messaggio "CMD.EXE e' stato avviato utilizzando il percorso
rem  precedente... I percorsi UNC non sono supportati" che compare in cima
rem  quando si avvia dalla rete e' NORMALE: si puo' ignorare.
rem  (Questo file NON usa piu' "pushd", che ad ogni avvio lasciava una nuova
rem  unita' di rete "web (\\ServerNas)" collegata: N:, O:, P: ...)
rem ------------------------------------------------------------------
set "CARTELLA=%~dp0"
cd /d "%TEMP%"
echo.
echo (Il messaggio sui "percorsi UNC" qui sopra e' normale, puoi ignorarlo.)
echo.

rem --- 1) Trovo Python su questo computer --------------------------------
set "PY="
where python3 >nul 2>nul
if not errorlevel 1 set "PY=python3"
if defined PY goto :python_trovato
where py >nul 2>nul
if not errorlevel 1 set "PY=py"
if defined PY goto :python_trovato
where python >nul 2>nul
if not errorlevel 1 set "PY=python"
if defined PY goto :python_trovato
echo ERRORE: Python non e' installato su questo computer.
echo Installa "Python 3" dal Microsoft Store e poi riapri questo file.
goto :fine

:python_trovato
rem --- 2) Installo i programmi necessari SOLO se mancano -----------------
%PY% -c "import streamlit, pandas, openpyxl, numpy" >nul 2>nul
if not errorlevel 1 goto :avvio
echo PRIMA VOLTA SU QUESTO COMPUTER: installo i programmi necessari.
echo Serve internet e puo' richiedere da 2 a 10 minuti.
echo NON chiudere questa finestra, vedrai scorrere l'avanzamento...
echo.
%PY% -m pip install streamlit pandas openpyxl numpy
if errorlevel 1 goto :errore_installazione

:avvio
rem --- 3) Evito la domanda "Email:" che Streamlit fa al primo avvio -------
if not exist "%USERPROFILE%\.streamlit" mkdir "%USERPROFILE%\.streamlit"
if not exist "%USERPROFILE%\.streamlit\credentials.toml" (
    > "%USERPROFILE%\.streamlit\credentials.toml" echo [general]
    >> "%USERPROFILE%\.streamlit\credentials.toml" echo email = ""
)

rem --- 4) Pagina in italiano: niente richiesta "Tradurre la pagina?" ------
if exist "%CARTELLA%prepara_avvio.py" %PY% "%CARTELLA%prepara_avvio.py"

echo.
echo Avvio la Dashboard Presenze...
echo Si aprira' automaticamente nel browser tra qualche secondo.
echo Per chiudere l'app chiudi questa finestra.
echo.
%PY% -m streamlit run "%CARTELLA%APP.py" --browser.gatherUsageStats false
goto :fine

:errore_installazione
echo.
echo ERRORE durante l'installazione dei programmi.
echo Controlla che il computer sia collegato a internet e riprova.
echo Se l'errore si ripete, fai una foto a questa finestra e mandala.

:fine
pause
