<#
.SYNOPSIS
  Tworzy prosty folder na projekt z plikami .md (polecenia / ustalenia).
  Nie kopiuje zadnych panelow ani zewnetrznych aplikacji — tylko szkielet.

.PARAMETER TargetRoot
  Pelna sciezka docelowego folderu (np. C:\projekty\MojaStrona\docs_polecenia).

.EXAMPLE
  .\bootstrap_nowy_projekt.ps1 -TargetRoot "D:\NowyProjekt\docs_polecenia"
#>
param(
    [Parameter(Mandatory = $true)]
    [string]$TargetRoot
)

$ErrorActionPreference = "Stop"

$here = Split-Path -Parent $PSScriptRoot

function Copy-Instrukcje($targetRoot) {
    $names = @(
        "README.md",
        "INSTRUKCJA_START_OD_ZERA.md",
        "OCZEKIWANIE_NA_UZGODNIENIA_ZESPOLU.md"
    )
    $docDir = Join-Path $targetRoot "_instrukcje_zrodlowe"
    New-Item -ItemType Directory -Force -Path $docDir | Out-Null
    foreach ($n in $names) {
        $src = Join-Path $here $n
        if (Test-Path $src) {
            Copy-Item -Path $src -Destination (Join-Path $docDir $n) -Force
        }
    }
}

function Write-MinimalDocs($root) {
    $granica = @"
# Granica (opcjonalnie)

Jesli uzywasz Cursor z regula edycji: ten folder = zakres pracy agenta.
Inaczej — pomin ten plik.
"@
    $brief = @"
# BRIEF — uzupelnij

## Cel projektu

(tu sam opisujesz o co chodzi)

## Must-have

-

## Poza zakresem

-

## Linki / inspiracje

-
"@
    $kolejka = @"
# Kolejka / status

| Zadanie | Kto | Status |
|---------|-----|--------|
| (przyklad) Szkielet strony | | todo |
"@
    $start = @"
# START

1. Przeczytaj `BRIEF.md` (albo jak nazwales plik celu).
2. Zobacz `KOLEJKA.md` — co jest nastepne.
3. Szczegoly procesu: `_instrukcje_zrodlowe/INSTRUKCJA_START_OD_ZERA.md` (jesli skopiowany przez bootstrap).
"@
    New-Item -ItemType Directory -Force -Path $root | Out-Null
    Set-Content -Path (Join-Path $root "GRANICA_KATALOGU_MATKI.md") -Value $granica -Encoding UTF8
    Set-Content -Path (Join-Path $root "START_TUTAJ_CURSOR.md") -Value $start -Encoding UTF8
    Set-Content -Path (Join-Path $root "BRIEF.md") -Value $brief -Encoding UTF8
    Set-Content -Path (Join-Path $root "KOLEJKA.md") -Value $kolejka -Encoding UTF8
}

Write-Host "[bootstrap] TargetRoot = $TargetRoot"

if (-not (Test-Path $TargetRoot)) {
    New-Item -ItemType Directory -Force -Path $TargetRoot | Out-Null
}

Write-MinimalDocs $TargetRoot
Copy-Instrukcje $TargetRoot

$readmeDest = Join-Path $TargetRoot "README_SANDBOX.txt"
@"
Folder polecen utworzony przez bootstrap_nowy_projekt.ps1
Data: $(Get-Date -Format "yyyy-MM-dd HH:mm")
Zrodlo skryptu: $here
Uwaga: pracujecie przez pliki .md (BRIEF, KOLEJKA) — bez paneli.
"@ | Set-Content -Path $readmeDest -Encoding UTF8

Write-Host "[bootstrap] Gotowe: $TargetRoot"
