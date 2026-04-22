param(
    [string]$PromptFile = (Join-Path $PSScriptRoot "prompt_example.txt"),
    [string]$InlinePrompt,
    [string]$AutoHotkeyExe = "AutoHotkey64.exe",
    [switch]$NoSend
)

$scriptPath = Join-Path $PSScriptRoot "cursor_chat_sender.ahk"

if (-not (Test-Path $scriptPath)) {
    throw "Nie znaleziono skryptu AHK: $scriptPath"
}

$ahkCommand = Get-Command $AutoHotkeyExe -ErrorAction SilentlyContinue
if ($ahkCommand) {
    $resolvedAhk = $ahkCommand.Source
} elseif (Test-Path $AutoHotkeyExe) {
    $resolvedAhk = (Resolve-Path $AutoHotkeyExe).Path
} else {
    throw "Nie znaleziono AutoHotkey v2. Zainstaluj je albo podaj pelna sciezke przez -AutoHotkeyExe."
}

if ($PSBoundParameters.ContainsKey("InlinePrompt")) {
    $promptArgument = $InlinePrompt
} else {
    if (-not (Test-Path $PromptFile)) {
        throw "Nie znaleziono pliku promptu: $PromptFile"
    }
    $promptArgument = (Resolve-Path $PromptFile).Path
}

$argumentList = @(
    $scriptPath,
    $promptArgument
)

if ($NoSend) {
    $argumentList += "--no-send"
}

$process = Start-Process -FilePath $resolvedAhk `
    -ArgumentList $argumentList `
    -Wait `
    -PassThru

exit $process.ExitCode
