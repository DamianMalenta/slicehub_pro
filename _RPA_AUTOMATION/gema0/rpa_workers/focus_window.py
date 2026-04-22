"""
Helper worker: tylko focusuje okno Cursor o podanej etykiecie
i zwraca tytul. Uzywany do healthcheck i testow panelu.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

from common import logger
from common.errors import RpaError, WindowNotFoundError
from common.window_resolver import load_map, resolve


def main() -> int:
    raw = sys.stdin.read()
    try:
        payload = json.loads(raw) if raw.strip() else {}
        label = str(payload.get("target_label", "")).strip()
        if not label:
            raise ValueError("Brak target_label")
    except Exception as exc:
        print(json.dumps({"ok": False, "message": f"Zly payload: {exc}"}))
        return 2

    cfg = Path(__file__).resolve().parent.parent / "backend" / "config" / "windows_map.json"

    try:
        mapping = load_map(cfg)
        win = resolve(label, mapping)
        win.activate()
        logger.info("focus_ok", window=win.title)
        print(json.dumps({"ok": True, "window_title": win.title}, ensure_ascii=False))
        return 0
    except (WindowNotFoundError, RpaError) as exc:
        logger.error("focus_error", detail=str(exc))
        print(json.dumps({"ok": False, "message": str(exc)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    sys.exit(main())
