@echo off
rem Toglie l'avvio automatico e la regola del firewall (eseguire come amministratore). I dati NON vengono toccati.
schtasks /end /tn "AGENZIA gestionale" >nul 2>&1
schtasks /delete /tn "AGENZIA gestionale" /f
netsh advfirewall firewall delete rule name="AGENZIA gestionale"
pause
