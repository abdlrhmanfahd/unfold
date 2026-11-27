<#
.SYNOPSIS
    Starts and supervises both Unfold Python workers (3D world builder & free AI extractor)
    for computer-lab hosting on Windows.
#>
param(
    [string]$SiteUrl = $env:UNFOLD_SITE,
    [string]$Provider = $env:UNFOLD_AI_PROVIDER,
    [int]$RestartDelaySeconds = 5
)

$ErrorActionPreference = 'Stop'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$LogDir   = Join-Path $PSScriptRoot 'logs'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

if (-not $SiteUrl) { $SiteUrl = 'http://localhost:8881' }
$env:UNFOLD_SITE = $SiteUrl
if ($Provider) { $env:UNFOLD_AI_PROVIDER = $Provider }

function Find-Python([string[]]$Candidates) {
    foreach ($p in $Candidates) {
        if ($p -and (Test-Path $p)) { return $p }
    }
    $cmd = Get-Command python -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    throw "Python executable not found (checked $($Candidates -join ', ') and PATH)."
}

$WorldPy = Find-Python @(
    (Join-Path $RepoRoot 'workers\world\.venv\Scripts\python.exe'),
    "$env:LOCALAPPDATA\Packages\Claude_pzs8sxrjxfjjc\LocalCache\Local\unfold-session-export\venv\Scripts\python.exe"
)
$ExtractPy = Find-Python @(
    (Join-Path $RepoRoot 'workers\extractor\.venv\Scripts\python.exe'),
    "$env:LOCALAPPDATA\unfold-extractor\venv\Scripts\python.exe"
)

$WorldScript   = Join-Path $RepoRoot 'workers\world\world_worker.py'
$ExtractScript = Join-Path $RepoRoot 'workers\extractor\unfold_worker.py'

Write-Host "Unfold Lab Supervisor starting (Site: $SiteUrl)"
Write-Host "  World worker   : $WorldPy -> $WorldScript"
Write-Host "  Extractor      : $ExtractPy -> $ExtractScript"
Write-Host "  Logs           : $LogDir"

$procs = @{}

function Start-Worker([string]$Name, [string]$Exe, [string]$Script) {
    $outLog = Join-Path $LogDir "$Name.out.log"
    $errLog = Join-Path $LogDir "$Name.err.log"
    $p = Start-Process -FilePath $Exe -ArgumentList @('-u', $Script) -WorkingDirectory $RepoRoot -NoNewWindow -PassThru -RedirectStandardOutput $outLog -RedirectStandardError $errLog
    Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Started $Name (PID $($p.Id))"
    return $p
}

try {
    $procs['world']     = Start-Worker 'world' $WorldPy $WorldScript
    $procs['extractor'] = Start-Worker 'extractor' $ExtractPy $ExtractScript

    while ($true) {
        Start-Sleep -Seconds $RestartDelaySeconds
        foreach ($name in @('world', 'extractor')) {
            $p = $procs[$name]
            if ($p.HasExited) {
                Write-Warning "[$(Get-Date -Format 'HH:mm:ss')] Worker '$name' exited with code $($p.ExitCode). Restarting..."
                if ($name -eq 'world') {
                    $procs['world'] = Start-Worker 'world' $WorldPy $WorldScript
                } else {
                    $procs['extractor'] = Start-Worker 'extractor' $ExtractPy $ExtractScript
                }
            }
        }
    }
} finally {
    foreach ($p in $procs.Values) {
        if ($p -and -not $p.HasExited) {
            Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue
        }
    }
}
