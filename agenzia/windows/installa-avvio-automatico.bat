@echo off
rem Da eseguire UNA VOLTA sul PC centrale, con il tasto destro -> "Esegui come amministratore".
rem 1) apre la porta 8787 nel firewall di Windows (così gli altri PC della rete possono collegarsi)
rem 2) crea un'attività pianificata che avvia il programma all'accensione del PC, anche senza login
cd /d "%~dp0"
net session >nul 2>&1
if %errorlevel% neq 0 (
  echo Devi eseguire questo file come AMMINISTRATORE (tasto destro - Esegui come amministratore).
  pause
  exit /b 1
)
netsh advfirewall firewall delete rule name="AGENZIA gestionale" >nul 2>&1
netsh advfirewall firewall add rule name="AGENZIA gestionale" dir=in action=allow protocol=TCP localport=8787
if exist "%~dp0agenzia.exe" (
  set CMD="%~dp0agenzia.exe"
) else (
  set CMD="node" "%~dp0server\server.js"
)
schtasks /delete /tn "AGENZIA gestionale" /f >nul 2>&1
schtasks /create /tn "AGENZIA gestionale" /tr %CMD% /sc onstart /ru SYSTEM /rl HIGHEST /f
schtasks /run /tn "AGENZIA gestionale"
echo.
echo Fatto. Il programma parte da solo a ogni accensione di questo PC.
echo Apri il browser su http://localhost:8787  (dagli altri PC: http://%COMPUTERNAME%:8787)
echo.
pause
