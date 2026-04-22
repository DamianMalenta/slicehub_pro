"""
Bezpieczna warstwa klawiatury oparta o pywinauto.

- Ujednolicona skladnia hotkeyow.
- BlockInput na czas krytycznej sekwencji (wymagane ADMIN; fallback gdy brak uprawnien).
- Kazdy wyslany hotkey jest logowany.
"""

from __future__ import annotations

import ctypes
import time
from contextlib import contextmanager
from typing import Iterator

from pywinauto import keyboard as pw_keyboard

from . import logger
from .panic import raise_if_panic


def send(keys: str, pause_s: float = 0.04) -> None:
    raise_if_panic()
    logger.debug("send_keys", keys=keys)
    pw_keyboard.send_keys(keys, pause=pause_s, with_spaces=True)


def type_text(text: str) -> None:
    """Literalne wpisanie tekstu (bez interpretacji jako hotkey)."""
    raise_if_panic()
    safe = (
        text.replace("{", "{{}")
        .replace("}", "{}}")
        .replace("+", "{+}")
        .replace("^", "{^}")
        .replace("%", "{%}")
        .replace("~", "{~}")
    )
    logger.debug("type_text", chars=len(text))
    pw_keyboard.send_keys(safe, pause=0.01, with_spaces=True)


@contextmanager
def block_input() -> Iterator[bool]:
    """
    BlockInput blokuje fizyczne wejscie uzytkownika. Wymaga uprawnien.
    Zwraca True jesli sie udalo, False gdy fallback (i tak jedziemy dalej).
    """
    ok = False
    try:
        ok = bool(ctypes.windll.user32.BlockInput(True))
    except Exception:
        ok = False
    if not ok:
        logger.warn("block_input_unavailable", note="Brak uprawnien, dzialam bez blokady.")
    try:
        yield ok
    finally:
        if ok:
            try:
                ctypes.windll.user32.BlockInput(False)
            except Exception:
                pass


def wait(delay_s: float) -> None:
    raise_if_panic()
    end = time.monotonic() + max(0.0, delay_s)
    while time.monotonic() < end:
        raise_if_panic()
        time.sleep(min(0.05, max(0.0, end - time.monotonic())))
