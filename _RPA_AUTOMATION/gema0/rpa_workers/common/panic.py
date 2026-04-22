"""
Globalny panic-stop dla workerow.

Rejestruje hotkey (domyslnie Ctrl+Alt+F12), ktory ustawia flage.
Krytyczne petle sprawdzaja ta flage przed kazdym krokiem.
"""

from __future__ import annotations

import threading


_PANIC = threading.Event()
_REGISTERED = False


def is_panic() -> bool:
    return _PANIC.is_set()


def raise_if_panic() -> None:
    from .errors import PanicStop
    if _PANIC.is_set():
        raise PanicStop("Operator wywolal panic-stop.")


def install(hotkey: str = "ctrl+alt+f12") -> bool:
    """Rejestruje globalny hotkey. Zwraca False jesli biblioteka 'keyboard' nie jest dostepna."""
    global _REGISTERED
    if _REGISTERED:
        return True
    try:
        import keyboard  # type: ignore
    except Exception:
        return False

    def _trip() -> None:
        _PANIC.set()

    try:
        keyboard.add_hotkey(hotkey, _trip)
        _REGISTERED = True
        return True
    except Exception:
        return False


def reset() -> None:
    _PANIC.clear()
