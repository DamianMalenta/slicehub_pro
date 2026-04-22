"""
PUSH TO CURSOR_X worker - wersja produkcyjna.

Wywolywany przez Node jako proces potomny (execa/spawn).
Wejscie: JSON na stdin.
Wyjscie: JSON na stdout (finalny wynik).
Log:    JSONL na stderr (zywe zdarzenia dla panelu).

Cecha kluczowa: dwuetapowa obsluga @mention, ktora faktycznie
wyzwala native picker Cursor zamiast wyslac literalny tekst.
"""

from __future__ import annotations

import json
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

from common import logger, panic, screenshot
from common.clipboard_safe import clipboard_payload
from common.delays import get_instance as get_delays
from common.errors import (
    BadPayloadError,
    ClipboardError,
    FocusLostError,
    PanicStop,
    RpaError,
    WindowNotFoundError,
)
from common.keyboard_safe import block_input, send, type_text, wait
from common.window_resolver import (
    WindowHandle,
    is_focus_on_window,
    load_map,
    resolve,
    resolve_all,
    SPECIAL_ALL,
)


HOTKEY_OPEN_CHAT = "^l"
HOTKEY_SUBMIT_CTRL_ENTER = "^{ENTER}"
HOTKEY_PASTE = "^v"
HOTKEY_ENTER = "{ENTER}"


@dataclass
class PushRequest:
    target_label: str
    note_name: str
    note_text: str
    extra_instruction: str
    mention_syntax: bool
    submit: bool
    dry_run: bool
    session_id: str
    preflight_esc: bool


def read_request() -> PushRequest:
    raw = sys.stdin.read()
    if not raw.strip():
        raise BadPayloadError("Brak payloadu na stdin.")
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise BadPayloadError(f"Zly JSON: {exc}") from exc

    try:
        return PushRequest(
            target_label=str(payload["target_label"]).strip(),
            note_name=str(payload["note_name"]).strip(),
            note_text=str(payload.get("note_text", "")),
            extra_instruction=str(payload.get("extra_instruction", "")).strip(),
            mention_syntax=bool(payload.get("mention_syntax", True)),
            submit=bool(payload.get("submit", True)),
            dry_run=bool(payload.get("dry_run", False)),
            session_id=str(payload.get("session_id", "default")),
            preflight_esc=bool(payload.get("preflight_esc", False)),
        )
    except KeyError as exc:
        raise BadPayloadError(f"Brak pola: {exc}") from exc


def resolve_targets(req: PushRequest, mapping: dict[str, str]) -> list[WindowHandle]:
    if req.target_label.upper() == SPECIAL_ALL:
        return list(resolve_all(mapping))
    return [resolve(req.target_label, mapping)]


def verify_focus(win: WindowHandle) -> None:
    if not is_focus_on_window(win):
        hint = f"hwnd={win.hwnd}" if win.hwnd else "tytul"
        raise FocusLostError(f"Focus zgubiony; oczekiwano okna '{win.title}' ({hint}).")


def open_chat_or_retry(win: WindowHandle, delays, preflight_esc: bool) -> None:
    panic.raise_if_panic()
    if preflight_esc:
        send("{ESC}")
        wait(0.08)
    send(HOTKEY_OPEN_CHAT)
    wait(delays.get("after_open_chat"))
    try:
        verify_focus(win)
        delays.success("after_open_chat")
        return
    except FocusLostError:
        delays.failure("after_open_chat")

    panic.raise_if_panic()
    logger.warn("open_chat_retry", hwnd=win.hwnd, title=win.title)
    win.activate()
    wait(delays.get("after_activate"))
    verify_focus(win)
    if preflight_esc:
        send("{ESC}")
        wait(0.08)
    send(HOTKEY_OPEN_CHAT)
    wait(delays.get("after_open_chat"))
    verify_focus(win)
    delays.success("after_open_chat")


def run_sequence(win: WindowHandle, req: PushRequest, delays_cache: Path, shots_dir: Path) -> dict:
    delays = get_delays(delays_cache)
    expected_title = win.title

    logger.info("push_start", target=req.target_label, window=expected_title, dry_run=req.dry_run)

    if req.dry_run:
        return {
            "ok": True,
            "window_title": expected_title,
            "dry_run": True,
            "planned_prompt": _compose_instruction(req),
        }

    screenshot.capture(shots_dir, "pre", req.session_id)

    win.activate()
    wait(delays.get("after_activate"))

    try:
        verify_focus(win)
    except FocusLostError:
        delays.failure("after_activate")
        win.activate()
        wait(delays.get("after_activate"))
        verify_focus(win)
    else:
        delays.success("after_activate")

    with block_input() as blocked:
        logger.info("input_blocked", blocked=blocked)

        open_chat_or_retry(win, delays, req.preflight_esc)

        instruction = _compose_instruction(req)
        if instruction:
            with clipboard_payload(instruction):
                verify_focus(win)
                send(HOTKEY_PASTE)
                wait(delays.get("after_paste"))
                delays.success("after_paste")

        if req.mention_syntax and req.note_name:
            _type_mention(req.note_name, delays, win)

        wait(delays.get("before_submit"))
        verify_focus(win)

        if req.submit:
            send(HOTKEY_SUBMIT_CTRL_ENTER)

    shot = screenshot.capture(shots_dir, "post", req.session_id)
    logger.info("push_done", screenshot=str(shot) if shot else None)

    return {
        "ok": True,
        "window_title": expected_title,
        "screenshot": str(shot) if shot else None,
        "delays_snapshot": delays.snapshot(),
    }


def _compose_instruction(req: PushRequest) -> str:
    parts = []
    if req.extra_instruction:
        parts.append(req.extra_instruction)
    if req.note_text and not req.mention_syntax:
        parts.append(req.note_text)
    return "\n\n".join(parts).strip()


def _type_mention(note_name: str, delays, win: WindowHandle) -> None:
    """
    Dwuetapowa sekwencja mention:
    1. Wpisz '@' jako osobny klawisz -> Cursor otwiera picker.
    2. Wpisz nazwe pliku literalnie.
    3. Zatwierdz Enter w pickerze.
    """
    panic.raise_if_panic()
    verify_focus(win)
    send(" ")
    send("@")
    wait(delays.get("after_mention_trigger"))

    type_text(note_name)
    wait(delays.get("after_mention_confirm"))

    verify_focus(win)
    send(HOTKEY_ENTER)


def main() -> int:
    cfg_dir = Path(__file__).resolve().parent.parent / "backend" / "config"
    storage_dir = Path(__file__).resolve().parent.parent / "storage"
    delays_cache = storage_dir / "timing_cache.json"
    shots_dir = storage_dir / "screenshots"

    panic.install()

    try:
        req = read_request()
    except BadPayloadError as exc:
        logger.error("bad_payload", detail=str(exc))
        print(json.dumps({"ok": False, "error": "bad_payload", "message": str(exc)}, ensure_ascii=False))
        return 2

    try:
        mapping = load_map(cfg_dir / "windows_map.json")
    except RpaError as exc:
        logger.error("config_error", detail=str(exc))
        print(json.dumps({"ok": False, "error": "config", "message": str(exc)}, ensure_ascii=False))
        return 2

    try:
        targets = resolve_targets(req, mapping)
    except WindowNotFoundError as exc:
        logger.error("window_not_found", detail=str(exc))
        print(json.dumps({"ok": False, "error": "window_not_found", "message": str(exc)}, ensure_ascii=False))
        return 1

    if not targets:
        msg = "Nie znaleziono zadnych okien docelowych."
        logger.error("no_targets", detail=msg)
        print(json.dumps({"ok": False, "error": "no_targets", "message": msg}, ensure_ascii=False))
        return 1

    results = []
    overall_ok = True
    for win in targets:
        try:
            res = run_sequence(win, req, delays_cache, shots_dir)
            results.append(res)
        except PanicStop as exc:
            logger.error("panic_stop", detail=str(exc))
            results.append({"ok": False, "error": "panic", "message": str(exc), "window_title": win.title})
            overall_ok = False
            break
        except (FocusLostError, ClipboardError, RpaError) as exc:
            logger.error("sequence_error", detail=str(exc), window=win.title)
            results.append(
                {"ok": False, "error": exc.__class__.__name__, "message": str(exc), "window_title": win.title}
            )
            overall_ok = False
        except Exception as exc:
            logger.error("unexpected", detail=str(exc), window=win.title)
            results.append(
                {"ok": False, "error": "unexpected", "message": str(exc), "window_title": win.title}
            )
            overall_ok = False

    print(
        json.dumps(
            {
                "ok": overall_ok,
                "target": req.target_label,
                "count": len(results),
                "results": results,
            },
            ensure_ascii=False,
        )
    )
    return 0 if overall_ok else 1


if __name__ == "__main__":
    sys.exit(main())
