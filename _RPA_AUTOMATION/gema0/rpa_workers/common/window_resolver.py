"""
Rozwiazywanie etykiet CURSOR_X na konkretne okno systemu.

Strategia:
1. Wczytaj mapowanie CURSOR_X -> hint tytulu z windows_map.json.
2. Wylistuj okna procesu Cursor.exe przez psutil + pygetwindow.
3. Wybierz najlepsze dopasowanie przez rapidfuzz.
4. Uzyj progu zaufania; ponizej progu = twardy fail.

Obsluguje specjalne etykiety:
- CURSOR_ALL -> broadcast do wszystkich zmapowanych.
- CURSOR_ACTIVE -> aktualnie aktywne okno Cursor.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

import psutil
import pygetwindow as gw
from rapidfuzz import fuzz

from .errors import WindowNotFoundError
from .foreground_win32 import is_foreground_hwnd


CURSOR_PROCESS_NAMES = {"cursor.exe"}
SPECIAL_ALL = "CURSOR_ALL"
SPECIAL_ACTIVE = "CURSOR_ACTIVE"
MIN_SCORE = 60


def _hwnd_from_raw(raw: object) -> int | None:
    h = getattr(raw, "_hWnd", None)
    if h is None:
        return None
    try:
        return int(h)
    except (TypeError, ValueError):
        return None


@dataclass
class WindowHandle:
    title: str
    raw: object
    hwnd: int | None = None

    def activate(self) -> None:
        try:
            if getattr(self.raw, "isMinimized", False):
                self.raw.restore()
            self.raw.activate()
        except Exception as exc:
            raise WindowNotFoundError(f"Aktywacja okna nie powiodla sie: {exc}") from exc


def _make_handle(title: str, raw: object) -> WindowHandle:
    return WindowHandle(title=title, raw=raw, hwnd=_hwnd_from_raw(raw))


def _cursor_pids() -> set[int]:
    pids: set[int] = set()
    for proc in psutil.process_iter(attrs=["pid", "name"]):
        try:
            name = (proc.info.get("name") or "").lower()
        except Exception:
            continue
        if name in CURSOR_PROCESS_NAMES:
            pids.add(proc.info["pid"])
    return pids


def _all_cursor_windows() -> list[WindowHandle]:
    windows = []
    for w in gw.getAllWindows():
        title = (w.title or "").strip()
        if not title:
            continue
        if "cursor" not in title.lower() and "cursor" not in title:
            continue
        windows.append(_make_handle(title, w))
    return windows


def load_map(path: Path) -> dict[str, str]:
    if not path.is_file():
        raise WindowNotFoundError(f"Brak windows_map.json: {path}")
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise WindowNotFoundError("windows_map.json musi byc obiektem label -> hint")
    return {str(k).upper(): str(v) for k, v in data.items()}


def resolve(label: str, mapping: dict[str, str]) -> WindowHandle:
    label_up = label.upper()

    if label_up == SPECIAL_ACTIVE:
        active = gw.getActiveWindow()
        if not active or "cursor" not in (active.title or "").lower():
            raise WindowNotFoundError("Aktywne okno nie jest Cursorem.")
        return _make_handle(active.title, active)

    hint = mapping.get(label_up)
    if not hint:
        raise WindowNotFoundError(f"Etykieta nie zmapowana: {label}")

    windows = _all_cursor_windows()
    if not windows:
        raise WindowNotFoundError("Nie znaleziono zadnego okna Cursor.")

    scored = [(fuzz.partial_ratio(hint.lower(), w.title.lower()), w) for w in windows]
    scored.sort(key=lambda x: x[0], reverse=True)
    best_score, best = scored[0]

    if best_score < MIN_SCORE:
        raise WindowNotFoundError(
            f"Zaden tytul nie pasuje do '{hint}'. Najlepszy: '{best.title}' (score={best_score})."
        )

    return best


def resolve_all(mapping: dict[str, str]) -> Iterable[WindowHandle]:
    windows = _all_cursor_windows()
    if not windows:
        return []
    result: list[WindowHandle] = []
    for label, hint in mapping.items():
        if label in (SPECIAL_ALL, SPECIAL_ACTIVE):
            continue
        scored = [(fuzz.partial_ratio(hint.lower(), w.title.lower()), w) for w in windows]
        scored.sort(key=lambda x: x[0], reverse=True)
        if scored and scored[0][0] >= MIN_SCORE:
            result.append(scored[0][1])
    return result


def active_title() -> str:
    w = gw.getActiveWindow()
    return (w.title if w else "") or ""


def is_still_focused(expected_title: str) -> bool:
    return active_title() == expected_title


def is_focus_on_window(win: WindowHandle) -> bool:
    if win.hwnd is not None:
        return is_foreground_hwnd(win.hwnd)
    return is_still_focused(win.title)
