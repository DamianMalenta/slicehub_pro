"""
Strukturalny logger dla workerow.

Loguje na stderr w formacie JSON Lines, zeby Node moze parsowac strumien
i broadcastowac na WebSocket. Kazda linia = jedno zdarzenie.
"""

from __future__ import annotations

import json
import sys
import time
import uuid
from typing import Any


def emit(level: str, message: str, **context: Any) -> None:
    payload = {
        "ts": time.time(),
        "lvl": level.upper(),
        "msg": message,
        "id": uuid.uuid4().hex[:8],
    }
    if context:
        payload["ctx"] = context
    sys.stderr.write(json.dumps(payload, ensure_ascii=False) + "\n")
    sys.stderr.flush()


def info(msg: str, **ctx: Any) -> None:
    emit("info", msg, **ctx)


def warn(msg: str, **ctx: Any) -> None:
    emit("warn", msg, **ctx)


def error(msg: str, **ctx: Any) -> None:
    emit("error", msg, **ctx)


def debug(msg: str, **ctx: Any) -> None:
    emit("debug", msg, **ctx)
