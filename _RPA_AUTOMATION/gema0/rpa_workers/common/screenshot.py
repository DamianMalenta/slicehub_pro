"""
Screenshot aktywnego okna do audytu.

Zapis w storage/screenshots/<session>/<ts>_<stage>.png.
"""

from __future__ import annotations

import time
from pathlib import Path
from typing import Optional

from PIL import ImageGrab


def capture(out_dir: Path, stage: str, session_id: Optional[str] = None) -> Optional[Path]:
    try:
        out_dir = out_dir / (session_id or "default")
        out_dir.mkdir(parents=True, exist_ok=True)
        ts = int(time.time() * 1000)
        target = out_dir / f"{ts}_{stage}.png"
        img = ImageGrab.grab(all_screens=True)
        img.save(target, format="PNG", optimize=True)
        return target
    except Exception:
        return None
