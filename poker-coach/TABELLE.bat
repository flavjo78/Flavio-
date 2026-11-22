@echo off
chcp 65001 >nul
cd /d "%~dp0"
title Poker Coach - Tabelle push/fold
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
set /p G=Quanti giocatori al tavolo (2-10)? 
set /p P=La tua posizione (es. UTG, MP, CO, BTN, SB, BB)? 
set /p C=Chi e' andato all-in prima di te? (INVIO se nessuno): 
if "%C%"=="" (%PY% -m coach.pushfold --giocatori %G% --pos %P%) else (%PY% -m coach.pushfold --giocatori %G% --pos %P% --contro %C%)
pause
