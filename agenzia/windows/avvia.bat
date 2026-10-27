@echo off
rem Avvia il gestionale AGENZIA (finestra da lasciare aperta). Chiudere la finestra = fermare il programma.
cd /d "%~dp0"
title AGENZIA - gestionale (non chiudere questa finestra)
if exist "agenzia.exe" (
  agenzia.exe
) else (
  node server\server.js
)
pause
