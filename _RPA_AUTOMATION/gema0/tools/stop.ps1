Get-Process node -ErrorAction SilentlyContinue | Where-Object {
    $_.Path -and (Split-Path -Parent $_.Path) -match "node"
} | Stop-Process -Force -ErrorAction SilentlyContinue

Write-Host "[GEMA-0] Zatrzymano procesy node (jesli byly)."
