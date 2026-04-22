param(
    [int]$Port = 0,
    [switch]$Open
)

$ErrorActionPreference = "Stop"
$gema0 = Split-Path -Parent $PSScriptRoot
$venvPython = Join-Path $gema0 "rpa_workers\venv\Scripts\python.exe"

if (-not (Test-Path $venvPython)) {
    throw "Brak venv. Uruchom najpierw tools\install.ps1"
}

$env:PYTHON_EXE = $venvPython
if ($Port -gt 0) { $env:PORT = "$Port" }

Push-Location $gema0
try {
    if ($Open) {
        Start-Process "http://127.0.0.1:$($env:PORT ?? 7878)"
    }
    node backend/server.js
} finally {
    Pop-Location
}
