@echo off
rem AGENZIA - avvia il programma (se non e' gia' acceso) e apre la schermata nel browser.
cd /d "%~dp0"
set PORTA=8787

netstat -an | findstr /r /c:":%PORTA% .*LISTENING" >nul
if %errorlevel%==0 goto apri

if exist "agenzia.exe" (
  start "AGENZIA - gestionale (non chiudere questa finestra)" /min agenzia.exe
) else (
  start "AGENZIA - gestionale (non chiudere questa finestra)" /min node server\server.js
)

rem aspetta che il programma sia pronto (max 20 secondi)
set /a N=0
:attesa
timeout /t 1 /nobreak >nul
netstat -an | findstr /r /c:":%PORTA% .*LISTENING" >nul
if %errorlevel%==0 goto apri
set /a N+=1
if %N% lss 20 goto attesa
echo Il programma non risponde sulla porta %PORTA%. Controlla la finestra "AGENZIA - gestionale".
pause
exit /b 1

:apri
start "" "http://localhost:%PORTA%"
exit /b 0
