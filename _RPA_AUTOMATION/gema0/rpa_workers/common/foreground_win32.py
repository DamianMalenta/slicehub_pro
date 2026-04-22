"""
Weryfikacja aktywnego okna przez HWND (Win32).

Wiele okien Cursor ma rozne HWND w tym samym PID — porownanie PID nie rozroznia okien.
Porownanie tytulu jest kruche (tytul zmienia sie przy przejsciu do pliku).
GetForegroundWindow() == hwnd docelowego okna jest najpewniejsze dla RPA.
"""

from __future__ import annotations

import ctypes


_user32 = ctypes.WinDLL("user32", use_last_error=True)
GetForegroundWindow = _user32.GetForegroundWindow


def foreground_hwnd() -> int | None:
    hwnd = GetForegroundWindow()
    if not hwnd:
        return None
    return int(hwnd)


def is_foreground_hwnd(hwnd: int | None) -> bool:
    if hwnd is None or hwnd == 0:
        return False
    fg = foreground_hwnd()
    return fg is not None and fg == hwnd
