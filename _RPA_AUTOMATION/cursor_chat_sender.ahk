#Requires AutoHotkey v2.0
#SingleInstance Force

SetTitleMatchMode 2
DetectHiddenWindows False

windowSelector := "ahk_exe Cursor.exe"
openChatHotkey := "^l"
activateTimeoutMs := 5000
chatReadyDelayMs := 500
afterPasteDelayMs := 350
restoreClipboard := true
sendAfterPaste := true

for arg in A_Args {
    if arg = "--no-send" {
        sendAfterPaste := false
    }
}

promptText := LoadPrompt(A_Args)
if Trim(promptText) = "" {
    Fail("Prompt jest pusty. Podaj sciezke do pliku albo tekst inline.")
}

if !WinExist(windowSelector) {
    Fail("Nie znaleziono okna Cursor. Otworz Cursor i sprobuj ponownie.")
}

WinActivate(windowSelector)
if !WinWaitActive(windowSelector, , activateTimeoutMs / 1000) {
    Fail("Nie udalo sie aktywowac okna Cursor.")
}

Sleep 200
Send(openChatHotkey)
Sleep chatReadyDelayMs

savedClipboard := ClipboardAll()
try {
    A_Clipboard := promptText
    if !ClipWait(2) {
        Fail("Schowek systemowy nie przyjal promptu.")
    }

    Send("^v")
    Sleep afterPasteDelayMs

    if sendAfterPaste {
        Send("{Enter}")
    }
} finally {
    if restoreClipboard {
        Sleep 120
        A_Clipboard := savedClipboard
    }
}

ExitApp 0

LoadPrompt(args) {
    if args.Length = 0 {
        defaultPromptPath := A_ScriptDir "\prompt_example.txt"
        if !FileExist(defaultPromptPath) {
            Fail("Brak argumentu i brak pliku prompt_example.txt.")
        }
        return NormalizePrompt(FileRead(defaultPromptPath, "UTF-8"))
    }

    firstArg := ""
    for arg in args {
        if arg = "--no-send" {
            continue
        }
        firstArg := arg
        break
    }

    if firstArg = "" {
        Fail("Nie podano promptu ani pliku promptu.")
    }

    if FileExist(firstArg) {
        return NormalizePrompt(FileRead(firstArg, "UTF-8"))
    }

    return NormalizePrompt(firstArg)
}

NormalizePrompt(text) {
    text := StrReplace(text, "`r`n", "`n")
    text := StrReplace(text, "`r", "`n")
    return text
}

Fail(message, exitCode := 1) {
    MsgBox(message, "Cursor Chat Sender", "Iconx T3")
    ExitApp exitCode
}
