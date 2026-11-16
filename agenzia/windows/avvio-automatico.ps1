# AGENZIA - crea l'avvio automatico del programma (attivita' pianificata "AGENZIA gestionale").
# Parte all'accensione del PC e anche al login, non si ferma mai da sola e si riavvia se si chiude.
# Si usa da installa-avvio-automatico.bat; va eseguito come amministratore.
param([Parameter(Mandatory = $true)][string]$Exe)
$ErrorActionPreference = 'Stop'
$cartella = Split-Path -Parent $Exe
$azione = New-ScheduledTaskAction -Execute $Exe -WorkingDirectory $cartella
$trigger = @((New-ScheduledTaskTrigger -AtStartup), (New-ScheduledTaskTrigger -AtLogOn))
$impostazioni = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1) `
  -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew
$utente = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
Register-ScheduledTask -TaskName 'AGENZIA gestionale' -Action $azione -Trigger $trigger -Settings $impostazioni -Principal $utente -Force | Out-Null
Start-ScheduledTask -TaskName 'AGENZIA gestionale'
Write-Host 'Avvio automatico impostato.'
