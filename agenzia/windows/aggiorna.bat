@echo off
rem AGENZIA - aggiornamento del programma. Da usare sul PC centrale.
rem Si lancia con il tasto destro - Esegui come amministratore, dalla cartella dello zip dell'aggiornamento estratto.
rem I dati NON vengono toccati: si sostituiscono solo agenzia.exe e la cartella web.
cd /d "%~dp0"
title AGENZIA - aggiornamento
net session >nul 2>&1
if %errorlevel% neq 0 goto noadmin
echo.
echo  AGENZIA - aggiornamento alla versione @@VERSIONE@@
echo  Si puo' installare sopra QUALSIASI versione precedente, dalla @@DA@@ in poi.
echo  I dati si aggiornano da soli e prima ne viene fatta una copia di sicurezza.
echo  Non si puo' tornare a una versione piu' vecchia dopo l'aggiornamento.
if not exist "%~dp0agenzia.exe" goto nofile
if not exist "%~dp0web\index.html" goto nofile

echo.
echo  AGENZIA - aggiornamento del programma
echo  -------------------------------------
set EXE=
for /f "usebackq delims=" %%P in (`powershell -NoProfile -Command "(Get-ScheduledTask -TaskName 'AGENZIA gestionale' -ErrorAction SilentlyContinue).Actions[0].Execute"`) do set EXE=%%P
if not defined EXE goto chiedi
set EXE=%EXE:"=%
if not exist "%EXE%" goto chiedi
goto trovato

:chiedi
echo  Non trovo il programma installato. Scrivi la cartella dove si trova agenzia.exe
echo  per esempio C:\Gestionale\AGENZIA
set /p CART=Cartella:
set EXE=%CART%\agenzia.exe
if not exist "%EXE%" goto nonc

:trovato
for %%F in ("%EXE%") do set DEST=%%~dpF
if /i "%DEST%"=="%~dp0" goto stessa
echo  Programma installato in: %DEST%
echo.
echo  1/4 Fermo il programma...
schtasks /end /tn "AGENZIA gestionale" >nul 2>&1
taskkill /f /im agenzia.exe >nul 2>&1
timeout /t 3 /nobreak >nul

echo  2/4 Copia di sicurezza dei dati...
xcopy "%DEST%_dati\agenzia.db*" "%DEST%_dati\prima-aggiornamento\" /Y /I /Q >nul 2>&1

echo  3/4 Installo la versione nuova...
copy /y "%~dp0agenzia.exe" "%DEST%agenzia.exe" >nul
if errorlevel 1 goto errcopia
robocopy "%~dp0web" "%DEST%web" /MIR /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 goto errcopia

echo  4/4 Riavvio il programma...
schtasks /run /tn "AGENZIA gestionale" >nul 2>&1
if errorlevel 1 start "" /min "%DEST%agenzia.exe"
timeout /t 4 /nobreak >nul
echo.
echo  Aggiornamento completato. Nel browser premi Ctrl+F5 per vedere la versione nuova.
echo  I dati sono al loro posto. Una copia di sicurezza e' in _dati\prima-aggiornamento.
echo.
pause
exit /b 0

:noadmin
echo Devi eseguire questo file come AMMINISTRATORE: tasto destro, poi Esegui come amministratore.
pause
exit /b 1

:nofile
echo Non trovo agenzia.exe e la cartella web accanto a questo file. Estrai prima tutto lo zip dell'aggiornamento.
pause
exit /b 1

:nonc
echo Non trovo agenzia.exe in quella cartella. Aggiornamento annullato, non e' stato cambiato niente.
pause
exit /b 1

:stessa
echo Questo file e' dentro la cartella del programma. Copialo fuori, per esempio sul Desktop, con lo zip estratto, e riprova.
pause
exit /b 1

:errcopia
echo Errore nella copia dei file. Il programma potrebbe essere ancora aperto. Riavvia il computer e riprova.
pause
exit /b 1
