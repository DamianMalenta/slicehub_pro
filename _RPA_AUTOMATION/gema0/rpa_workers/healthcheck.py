"""
Pre-flight healthcheck dla panelu.

Sprawdza:
- czy proces Cursor jest uruchomiony,
- czy mapowanie windows_map.json istnieje,
- ktore etykiety CURSOR_X faktycznie maja dopasowanie,
- czy kluczowe pakiety Pythona sie ladowaly.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

from common import logger


def main() -> int:
    issues: list[str] = []
    ok_flags: dict[str, bool] = {}

    try:
        import pyperclip  # noqa: F401
        ok_flags["pyperclip"] = True
    except Exception as exc:
        ok_flags["pyperclip"] = False
        issues.append(f"pyperclip: {exc}")

    try:
        import pygetwindow  # noqa: F401
        ok_flags["pygetwindow"] = True
    except Exception as exc:
        ok_flags["pygetwindow"] = False
        issues.append(f"pygetwindow: {exc}")

    try:
        import pywinauto  # noqa: F401
        ok_flags["pywinauto"] = True
    except Exception as exc:
        ok_flags["pywinauto"] = False
        issues.append(f"pywinauto: {exc}")

    try:
        import psutil
        cursor_pids = [
            p.info["pid"]
            for p in psutil.process_iter(attrs=["pid", "name"])
            if (p.info.get("name") or "").lower() == "cursor.exe"
        ]
        ok_flags["cursor_running"] = bool(cursor_pids)
        ok_flags["cursor_process_count"] = len(cursor_pids)
    except Exception as exc:
        ok_flags["cursor_running"] = False
        issues.append(f"psutil: {exc}")

    cfg = Path(__file__).resolve().parent.parent / "backend" / "config" / "windows_map.json"
    resolved: dict[str, str] = {}
    try:
        from common.window_resolver import load_map, resolve, WindowHandle

        mapping = load_map(cfg)
        ok_flags["windows_map_loaded"] = True
        for label in mapping.keys():
            try:
                win: WindowHandle = resolve(label, mapping)
                resolved[label] = win.title
            except Exception as exc:
                resolved[label] = f"MISSING: {exc}"
    except Exception as exc:
        ok_flags["windows_map_loaded"] = False
        issues.append(f"windows_map: {exc}")

    out = {
        "ok": not issues,
        "flags": ok_flags,
        "resolved_windows": resolved,
        "issues": issues,
    }
    logger.info("healthcheck", **out)
    print(json.dumps(out, ensure_ascii=False))
    return 0 if not issues else 1


if __name__ == "__main__":
    sys.exit(main())
