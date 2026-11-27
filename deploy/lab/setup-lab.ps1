<#
.SYNOPSIS
    One-command bootstrap for hosting Unfold on a Windows computer lab PC:
    1. Creates repo-local Python venvs for Extractor & 3D World workers and installs requirements.
    2. Installs Ollama (via winget if missing), starts Ollama serve, and pulls the pinned model.
    3. Enables the Lab Local Ollama AI preset in WordPress (plus initial worker token if needed).
    4. Registers Windows Scheduled Tasks so Ollama & both workers start automatically on boot/logon.
#>
param(
    [string]$Model = 'qwen2.5:7b',
    [string]$SiteUrl = 'http://localhost:8881',
    [switch]$SkipOllama,
    [switch]$SkipTasks,
    [switch]$SkipWordPress
)

$ErrorActionPreference = 'Stop'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

Write-Host "=== Unfold Computer Lab Setup ==="
Write-Host "Repo root : $RepoRoot"
Write-Host "Site URL  : $SiteUrl"
Write-Host "Model     : $Model"

# 1. Python virtual environments inside repo (portable across machines).
$pyCmd = Get-Command python -ErrorAction SilentlyContinue
if (-not $pyCmd) {
    throw "Python 3.10+ is required on PATH before running setup-lab.ps1."
}

$VenvSpecs = @(
    @{ Name = 'Extractor worker'; Dir = (Join-Path $RepoRoot 'workers\extractor\.venv'); Req = (Join-Path $RepoRoot 'workers\extractor\requirements.txt') },
    @{ Name = '3D World worker';  Dir = (Join-Path $RepoRoot 'workers\world\.venv');     Req = (Join-Path $RepoRoot 'workers\world\requirements.txt') }
)

foreach ($spec in $VenvSpecs) {
    $venvPy = Join-Path $spec.Dir 'Scripts\python.exe'
    if (-not (Test-Path $venvPy)) {
        Write-Host "Creating Python venv for $($spec.Name)..."
        & $pyCmd.Source -m venv $spec.Dir
        if ($LASTEXITCODE -ne 0) { throw "Failed creating venv at $($spec.Dir)" }
    }
    Write-Host "Installing dependencies for $($spec.Name)..."
    & $venvPy -m pip install -r $spec.Req
    if ($LASTEXITCODE -ne 0) { throw "Failed installing $($spec.Req)" }
}

# 2. Ollama installation, daemon startup, and model pull.
function Find-Ollama {
    $cmd = Get-Command ollama -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $local = Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama.exe'
    if (Test-Path $local) { return $local }
    return $null
}

if (-not $SkipOllama) {
    $ollamaExe = Find-Ollama
    if (-not $ollamaExe) {
        $winget = Get-Command winget -ErrorAction SilentlyContinue
        if (-not $winget) {
            throw "Ollama not found and winget is unavailable. Install Ollama from https://ollama.com and re-run."
        }
        Write-Host "Installing Ollama via winget..."
        & $winget.Source install --id Ollama.Ollama --accept-package-agreements --accept-source-agreements -e
        $ollamaExe = Find-Ollama
        if (-not $ollamaExe) {
            throw "Ollama installed, but ollama.exe is not in PATH yet. Open a new PowerShell window and re-run setup-lab.ps1."
        }
    }

    $ollamaReady = $false
    try {
        $resp = Invoke-WebRequest -Uri 'http://127.0.0.1:11434/api/tags' -UseBasicParsing -TimeoutSec 2
        if ($resp.StatusCode -eq 200) { $ollamaReady = $true }
    } catch {}

    if (-not $ollamaReady) {
        Write-Host "Starting Ollama service..."
        Start-Process -FilePath $ollamaExe -ArgumentList 'serve' -WindowStyle Hidden | Out-Null
        for ($i = 0; $i -lt 15; $i++) {
            Start-Sleep -Seconds 1
            try {
                $resp = Invoke-WebRequest -Uri 'http://127.0.0.1:11434/api/tags' -UseBasicParsing -TimeoutSec 2
                if ($resp.StatusCode -eq 200) { $ollamaReady = $true; break }
            } catch {}
        }
        if (-not $ollamaReady) {
            throw "Ollama did not respond on http://127.0.0.1:11434 within 15 seconds."
        }
    }

    Write-Host "Pulling Ollama model '$Model' (reuses local/USB-copied cache if already present)..."
    & $ollamaExe pull $Model
    if ($LASTEXITCODE -ne 0) { throw "Failed to pull Ollama model '$Model'." }
}

# 3. Enable Lab Local Ollama preset (and initial worker token) in WordPress.
$ExtToken   = Join-Path $RepoRoot 'workers\extractor\worker-token.txt'
$WorldToken = Join-Path $RepoRoot 'workers\world\worker-token.txt'
if (-not $SkipWordPress) {
    $studioCmd = Get-Command studio -ErrorAction SilentlyContinue
    if ($studioCmd) {
        Write-Host "Enabling Lab Local Ollama preset in WordPress..."
        $ErrorActionPreference = 'Continue'
        & $studioCmd.Source wp eval-file (Join-Path $PSScriptRoot 'enable-ollama-preset.php') $Model $ExtToken 2>&1
        $wpExit = $LASTEXITCODE
        $ErrorActionPreference = 'Stop'
        if ($wpExit -ne 0) {
            Write-Warning "Could not run WP-CLI setup automatically. Run manually after WordPress starts: studio wp eval-file deploy/lab/enable-ollama-preset.php $Model"
        }
    } else {
        Write-Host "Note: 'studio' CLI not found; enable Lab Local Ollama in wp-admin -> Projects -> Settings."
    }
}
if ((Test-Path $ExtToken) -and -not (Test-Path $WorldToken)) {
    Copy-Item -Path $ExtToken -Destination $WorldToken -Force
}

# 4. Register Windows Scheduled Tasks for boot/logon auto-start.
if (-not $SkipTasks) {
    Write-Host "Registering Windows Scheduled Tasks..."
    & (Join-Path $PSScriptRoot 'install-scheduled-tasks.ps1') -SiteUrl $SiteUrl -Provider 'ollama'
}

Write-Host ""
Write-Host "=== Setup complete ==="
Write-Host "Next manual step (once on the lab PC):"
Write-Host "  1. Open $SiteUrl/wp-admin/ -> Projects -> Settings and enter your KAU SMTP settings for email verification."
Write-Host "  2. Update wp-config.php if moving from localhost to the lab LAN IP, then test with: .\deploy\lab\start-lab.ps1"
