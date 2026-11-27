<#
.SYNOPSIS
    Registers (or removes) Windows Scheduled Tasks so the Unfold 3D World Worker and
    Free AI Extractor Worker start automatically when the lab computer boots/logs on.
#>
param(
    [string]$SiteUrl = 'http://localhost:8881',
    [string]$Provider = '',
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$TaskNames = @('Unfold-WorldWorker', 'Unfold-ExtractorWorker')

if ($Uninstall) {
    foreach ($name in $TaskNames) {
        if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskName $name -Confirm:$false
            Write-Host "Removed scheduled task: $name"
        }
    }
    return
}

function Find-Python([string]$PreferredVenv) {
    if (Test-Path $PreferredVenv) { return $PreferredVenv }
    $cmd = Get-Command python -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    throw "Python executable not found (checked $PreferredVenv and PATH)."
}

$WorldPy   = Find-Python "$env:LOCALAPPDATA\Packages\Claude_pzs8sxrjxfjjc\LocalCache\Local\unfold-session-export\venv\Scripts\python.exe"
$ExtractPy = Find-Python "$env:LOCALAPPDATA\unfold-extractor\venv\Scripts\python.exe"

$WorldScript   = Join-Path $RepoRoot 'workers\world\world_worker.py'
$ExtractScript = Join-Path $RepoRoot 'workers\extractor\unfold_worker.py'

$Trigger  = New-ScheduledTaskTrigger -AtLogOn
$Settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit (New-TimeSpan -Days 365)

$worldCmd = "set `"UNFOLD_SITE=$SiteUrl`" && `"$WorldPy`" -u `"$WorldScript`""
Register-ScheduledTask -TaskName 'Unfold-WorldWorker' `
    -Action (New-ScheduledTaskAction -Execute 'cmd.exe' -Argument "/c $worldCmd" -WorkingDirectory $RepoRoot) `
    -Trigger $Trigger -Settings $Settings -Force | Out-Null

$provPart = if ($Provider) { "set `"UNFOLD_AI_PROVIDER=$Provider`" && " } else { '' }
$extCmd   = "${provPart}set `"UNFOLD_SITE=$SiteUrl`" && `"$ExtractPy`" -u `"$ExtractScript`""
Register-ScheduledTask -TaskName 'Unfold-ExtractorWorker' `
    -Action (New-ScheduledTaskAction -Execute 'cmd.exe' -Argument "/c $extCmd" -WorkingDirectory $RepoRoot) `
    -Trigger $Trigger -Settings $Settings -Force | Out-Null

Write-Host "Registered scheduled tasks: Unfold-WorldWorker, Unfold-ExtractorWorker (Site: $SiteUrl)"
Write-Host "Start them now with: Start-ScheduledTask -TaskName Unfold-WorldWorker; Start-ScheduledTask -TaskName Unfold-ExtractorWorker"
