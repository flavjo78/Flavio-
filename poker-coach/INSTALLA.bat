@echo off
chcp 65001 >nul
cd /d "%~dp0"
title Poker Coach - Installazione
set "PY="
py --version >nul 2>nul && set "PY=py"
if not defined PY python --version >nul 2>nul && set "PY=python"
if not defined PY (
  echo.
  echo  Python non e' installato. Si apre la pagina per scaricarlo:
  echo  installalo spuntando "Add python.exe to PATH", poi riapri questo file.
  start https://www.python.org/downloads/
  pause
  exit /b 1
)
echo  Installo i componenti...
%PY% -m pip install --upgrade pip
%PY% -m pip install -r requirements.txt
if errorlevel 1 (
  echo.
  echo  ERRORE durante l'installazione: fai una foto a questa finestra e mandala a Claude.
  pause
  exit /b 1
)
echo.
echo  Prova: ecco le finestre che vedo aperte adesso
%PY% -m coach.schermo finestre
echo.
echo  ===== INSTALLAZIONE COMPLETATA =====
echo  Ora apri il poker e fai doppio clic su IMPARA.bat
pause
