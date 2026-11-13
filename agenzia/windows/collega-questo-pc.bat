@echo off
rem AGENZIA - da eseguire sugli ALTRI PC dell'agenzia (NON sul PC centrale).
rem Chiede il nome del PC centrale, controlla che risponda e mette l'icona "AGENZIA" sul desktop.
setlocal
set PORTA=8787
echo.
echo  AGENZIA - collegamento di questo PC al PC centrale
echo  -------------------------------------------------
echo  Il nome del PC centrale lo vedi in Esplora file - Rete (es. PC01-FABIO).
echo  Se il nome non funziona, puoi scrivere l'indirizzo IP (es. 192.168.1.10).
echo.
set /p SERVER=Nome (o IP) del PC centrale dove gira AGENZIA: 
if "%SERVER%"=="" ( echo Nessun nome inserito. & pause & exit /b 1 )
set URL=http://%SERVER%:%PORTA%
echo.
echo Provo a raggiungere %SERVER% sulla porta %PORTA%...
powershell -NoProfile -Command "if ((Test-NetConnection -ComputerName '%SERVER%' -Port %PORTA% -WarningAction SilentlyContinue).TcpTestSucceeded) { exit 0 } else { exit 1 }"
if errorlevel 1 (
  echo.
  echo  ATTENZIONE: %SERVER% NON risponde sulla porta %PORTA%. Controlla che:
  echo   - il PC centrale sia acceso e AGENZIA sia avviato ^(avvia.bat o avvio automatico^)
  echo   - sul PC centrale sia stato eseguito installa-avvio-automatico.bat ^(apre il firewall^)
  echo   - i due PC siano sulla stessa rete
  echo  Il collegamento viene creato lo stesso: funzionera' quando il PC centrale risponde.
  echo.
) else (
  echo  OK: %SERVER% risponde.
)
for /f "delims=" %%D in ('powershell -NoProfile -Command "[Environment]::GetFolderPath('Desktop')"') do set DESK=%%D
if "%DESK%"=="" set DESK=%USERPROFILE%\Desktop
(
  echo [InternetShortcut]
  echo URL=%URL%
) > "%DESK%\AGENZIA.url"
echo.
echo  Creata l'icona "AGENZIA" sul desktop: apre %URL%
echo  Consiglio: nel browser, menu' ^> "Installa app" / "Crea collegamento" per averlo come programma.
echo.
start "" "%URL%"
pause
