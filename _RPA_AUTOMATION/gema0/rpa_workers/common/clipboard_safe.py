"""
Bezpieczny dostep do schowka systemowego.

- Zawsze robi backup poprzedniej zawartosci.
- Po zakonczeniu operacji przywraca backup.
- Weryfikuje, ze clipboard faktycznie przyjal zadana wartosc.
"""

from __future__ import annotations

import time
from contextlib import contextmanager
from typing import Iterator

import pyperclip

from .errors import ClipboardError


def _read_safe() -> str:
    try:
        return pyperclip.paste() or ""
    except Exception:
        return ""


def _write_and_verify(value: str, timeout_s: float = 1.5) -> None:
    pyperclip.copy(value)
    deadline = time.monotonic() + timeout_s
    last = ""
    while time.monotonic() < deadline:
        last = _read_safe()
        if last == value:
            return
        time.sleep(0.04)
    raise ClipboardError(
        f"Schowek nie przyjal wartosci (oczekiwano {len(value)} znakow, dostano {len(last)})."
    )


@contextmanager
def clipboard_payload(value: str) -> Iterator[None]:
    """Atomowo ustawia wartosc schowka na czas bloku, potem przywraca backup."""
    backup = _read_safe()
    _write_and_verify(value)
    try:
        yield
    finally:
        try:
            pyperclip.copy(backup)
        except Exception:
            pass


def snapshot() -> str:
    return _read_safe()
