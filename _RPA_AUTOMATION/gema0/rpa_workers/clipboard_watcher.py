"""
Watcher schowka: Cursor -> panel.

Dziala w petli. Jesli uzytkownik w Cursor zaznaczy odpowiedz i wcisnie Ctrl+C,
watcher wykrywa zmiane schowka i emituje zdarzenie JSON na stdout.
Panel (Node backend) moze to podniesc i przerzucic do Gemini.
"""

from __future__ import annotations

import json
import sys
import time
from pathlib import Path

import pyperclip

from common import logger, panic


POLL_S = 0.25
MIN_CHARS = 8


def main() -> int:
    panic.install()
    last = pyperclip.paste() or ""
    logger.info("watcher_start")

    try:
        while not panic.is_panic():
            current = pyperclip.paste() or ""
            if current != last and len(current) >= MIN_CHARS:
                last = current
                event = {
                    "ts": time.time(),
                    "type": "clipboard_change",
                    "chars": len(current),
                    "preview": current[:160],
                    "content": current,
                }
                print(json.dumps(event, ensure_ascii=False), flush=True)
            time.sleep(POLL_S)
    except KeyboardInterrupt:
        pass

    logger.info("watcher_stop")
    return 0


if __name__ == "__main__":
    sys.exit(main())
