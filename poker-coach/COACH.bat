@echo off
chcp 65001 >nul
cd /d "%~dp0"
title Poker Coach
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
echo  Si apre la finestra del coach sul lato destro. Non chiudere questa finestra nera.
%PY% -m coach.finestra
if errorlevel 1 pause
