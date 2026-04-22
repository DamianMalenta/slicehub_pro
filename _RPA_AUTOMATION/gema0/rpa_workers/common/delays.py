"""
Adaptacyjne opoznienia.

Sklad: prosty JSON-store w storage/. Kluczem jest nazwa etapu
(np. 'after_open_chat'), wartoscia rekomendowane opoznienie w sekundach.

Logika:
- start od 'base'
- jesli etap sie udal -> nieznacznie zmniejsz (minimum 'floor')
- jesli etap sie nie udal -> wyraznie zwieksz (maximum 'ceil')

To daje nam tuning bez recznego dlubania.
"""

from __future__ import annotations

import json
import threading
from pathlib import Path
from typing import Optional


_DEFAULTS = {
    "after_activate": 0.25,
    "after_open_chat": 0.55,
    "after_paste": 0.25,
    "after_mention_trigger": 0.45,
    "after_mention_confirm": 0.30,
    "before_submit": 0.20,
}

_FLOOR = {k: 0.10 for k in _DEFAULTS}
_CEIL = {k: 2.00 for k in _DEFAULTS}
_STEP_DOWN = 0.02
_STEP_UP = 0.15

_LOCK = threading.Lock()


def _load(path: Path) -> dict[str, float]:
    if not path.is_file():
        return dict(_DEFAULTS)
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        out = dict(_DEFAULTS)
        for k, v in data.items():
            if k in _DEFAULTS:
                out[k] = float(v)
        return out
    except Exception:
        return dict(_DEFAULTS)


def _save(path: Path, data: dict[str, float]) -> None:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(data, indent=2), encoding="utf-8")
    except Exception:
        pass


class AdaptiveDelays:
    def __init__(self, cache_path: Path) -> None:
        self._path = cache_path
        self._data = _load(cache_path)

    def get(self, stage: str) -> float:
        return float(self._data.get(stage, _DEFAULTS.get(stage, 0.3)))

    def success(self, stage: str) -> None:
        with _LOCK:
            current = self.get(stage)
            new = max(_FLOOR.get(stage, 0.10), current - _STEP_DOWN)
            self._data[stage] = round(new, 3)
            _save(self._path, self._data)

    def failure(self, stage: str) -> None:
        with _LOCK:
            current = self.get(stage)
            new = min(_CEIL.get(stage, 2.00), current + _STEP_UP)
            self._data[stage] = round(new, 3)
            _save(self._path, self._data)

    def snapshot(self) -> dict[str, float]:
        return dict(self._data)


_INSTANCE: Optional[AdaptiveDelays] = None


def get_instance(cache_path: Path) -> AdaptiveDelays:
    global _INSTANCE
    if _INSTANCE is None or _INSTANCE._path != cache_path:
        _INSTANCE = AdaptiveDelays(cache_path)
    return _INSTANCE
