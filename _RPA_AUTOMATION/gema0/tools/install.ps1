param(
    [string]$PythonExe = "python",
    [switch]$Recreate
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$gema0 = $root
$venvDir = Join-Path $gema0 "rpa_workers\venv"
$requirements = Join-Path $gema0 "rpa_workers\requirements.txt"

Write-Host "[GEMA-0] Instalacja srodowiska..."

if ($Recreate -and (Test-Path $venvDir)) {
    Write-Host "[GEMA-0] Usuwam istniejacy venv..."
    Remove-Item -Recurse -Force $venvDir
}

if (-not (Test-Path $venvDir)) {
    Write-Host "[GEMA-0] Tworze venv..."
    & $PythonExe -m venv $venvDir
}

$venvPython = Join-Path $venvDir "Scripts\python.exe"
if (-not (Test-Path $venvPython)) { throw "Nie utworzono venv: $venvPython" }

Write-Host "[GEMA-0] Aktualizuje pip..."
& $venvPython -m pip install --upgrade pip | Out-Host

Write-Host "[GEMA-0] Instaluje requirements..."
& $venvPython -m pip install -r $requirements | Out-Host

Write-Host "[GEMA-0] npm install..."
Push-Location $gema0
try {
    npm install | Out-Host
} finally {
    Pop-Location
}

$envFile = Join-Path $gema0 ".env"
$envExample = Join-Path $gema0 ".env.example"
if (-not (Test-Path $envFile)) {
    Copy-Item $envExample $envFile
    Write-Host "[GEMA-0] Utworzono .env z .env.example."
}

Write-Host "[GEMA-0] Gotowe. Uzyj tools\start.ps1, zeby uruchomic panel."
