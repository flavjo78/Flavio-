@echo off
rem ===========================================================================
rem  PROVA SUL PC: app ferie + dashboard, con dati dimostrativi.
rem  Non tocca il NAS. Per chiudere tutto: chiudi le due finestre nere.
rem ===========================================================================
cd /d "%~dp0"
chcp 65001 >nul

where python >nul 2>nul || (echo Python non trovato. Serve lo stesso Python della dashboard. & pause & exit /b 1)

rem --- PHP: cerca php\php.exe oppure php.exe accanto a questo file, altrimenti nel PATH
set "PHPEXE=%~dp0php\php.exe"
if not exist "%PHPEXE%" set "PHPEXE=%~dp0php.exe"
if not exist "%PHPEXE%" (
  where php >nul 2>nul && (set "PHPEXE=php") || (
    echo.
    echo  Manca PHP. E' un programma gratuito da scaricare UNA volta sola:
    echo    1. apri https://windows.php.net/download/
    echo    2. scarica "VS16 x64 Non Thread Safe" - il file ZIP
    echo    3. decomprimi tutto in una cartella chiamata  php  dentro  prova_pc
    echo       ^(deve esistere  prova_pc\php\php.exe^)
    echo    4. rilancia questo file
    echo.
    pause & exit /b 1
  )
)

python prepara_prova.py %1
if errorlevel 1 (pause & exit /b 1)

set "FERIE_DATI_DIR=%~dp0dati_prova"
start "App ferie (PHP) - non chiudere" "%PHPEXE%" -d display_errors=0 -S 0.0.0.0:8080 -t "%~dp0..\ferie"
start "Dashboard (Streamlit) - non chiudere" cmd /k "cd /d %~dp0dati_prova && python -m streamlit run APP.py --server.port 8501"

timeout /t 4 >nul
start http://localhost:8080/index.html
echo.
echo  App ferie:   http://localhost:8080/index.html   (si e' aperta da sola)
echo  Dashboard:   http://localhost:8501              (scheda "Ferie", password 0)
echo.
echo  Dal TABLET/TELEFONO nella stessa rete Wi-Fi: http://IP-DEL-PC:8080/index.html
echo  (l'IP del PC lo vedi con il comando  ipconfig,  riga "Indirizzo IPv4")
echo  Se non si apre, Windows chiede di autorizzare PHP nel firewall: consenti la rete privata.
echo.
pause
