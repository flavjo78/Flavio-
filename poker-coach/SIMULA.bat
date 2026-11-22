@echo off
chcp 65001 >nul
cd /d "%~dp0"
title Poker Coach - Simulatore del file del gioco
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
echo  Scrivo due mani di prova nel file partita_prova.jsonl, una riga ogni 1,5 secondi.
echo  Apri COACH.bat, premi "Scegli..." accanto a "File del gioco" e scegli partita_prova.jsonl.
echo.
%PY% -m coach.simula partita_prova.jsonl
pause
