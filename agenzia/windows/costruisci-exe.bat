@echo off
rem Da eseguire sul PC di chi prepara il programma (serve Node.js >= 22.13 installato: https://nodejs.org).
rem Produce la cartella dist\AGENZIA pronta da copiare sul PC centrale dell'agenzia (senza installare niente).
cd /d "%~dp0.."
node costruisci\bundle.js && node costruisci\exe.js
pause
