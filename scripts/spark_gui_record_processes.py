#!/usr/bin/env python3
"""Headed Chrome — 7 SPARK process clips (localhost). Short pauses, hub card clicks."""
from __future__ import annotations

import json
import os
import time
from pathlib import Path
from urllib.parse import quote

BASE = os.environ.get("SLICEHUB_BASE", "http://localhost/slicehub").rstrip("/")
OUT = Path("/opt/cursor/artifacts")
OUT.mkdir(parents=True, exist_ok=True)
ENV_PATH = OUT / "spark_recording_env.json"
PAUSE = float(os.environ.get("SPARK_CLICK_PAUSE", "1.4"))


def load_env() -> dict:
    return json.loads(ENV_PATH.read_text())


def inject_url(token: str, user: dict, redirect: str) -> str:
    u = quote(json.dumps(user, ensure_ascii=False))
    r = quote(redirect, safe="")
    return f"{BASE}/tests/spark_auth_inject.html?token={quote(token)}&user={u}&redirect={r}"


def move_click(page, selector: str, *, timeout: int = 8000) -> None:
    loc = page.locator(selector).first
    loc.wait_for(state="visible", timeout=timeout)
    box = loc.bounding_box()
    if box:
        page.mouse.move(box["x"] + box["width"] / 2, box["y"] + box["height"] / 2, steps=12)
        time.sleep(0.25)
    loc.click()
    time.sleep(PAUSE)


def scroll_page(page, delta: int = 400) -> None:
    page.mouse.wheel(0, delta)
    time.sleep(PAUSE * 0.7)


def hub_tile(page, href_part: str) -> None:
    sel = f"a.hub-card[href*='{href_part}']"
    if href_part == "../studio/":
        sel = "a.hub-card[href$='studio/index.html']"
    move_click(page, sel)


def record_process(name: str, fn) -> Path:
    from playwright.sync_api import sync_playwright

    out = OUT / f"{name}.webm"
    for old in OUT.glob("*.webm"):
        if old.name.startswith(name):
            old.unlink()

    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=False,
            args=["--window-size=1920,1080", "--window-position=0,0"],
        )
        context = browser.new_context(
            viewport={"width": 1920, "height": 1080},
            record_video_dir=str(OUT),
            record_video_size={"width": 1920, "height": 1080},
        )
        page = context.new_page()
        try:
            fn(page, load_env())
        finally:
            page.close()
            context.close()
            browser.close()

    videos = sorted(OUT.glob("*.webm"), key=lambda p: p.stat().st_mtime, reverse=True)
    if videos:
        latest = videos[0]
        if latest.name != out.name:
            latest.rename(out)
    return out


def p1_online(page, env: dict) -> None:
    page.goto(f"{BASE}/modules/online/index.html?tenant={env['tenant_id']}&skip=doors", wait_until="networkidle")
    time.sleep(1.2)
    scroll_page(page, 500)
    for sel in ["text=PIZZE", "button:has-text('PIZZE')"]:
        try:
            move_click(page, sel, timeout=3000)
            break
        except Exception:
            continue
    for sel in ["text=MARGHERITA", ".menu-item:has-text('MARGHERITA')"]:
        try:
            move_click(page, sel, timeout=5000)
            break
        except Exception:
            continue
    for sel in ["text=30", "button:has-text('30')"]:
        try:
            move_click(page, sel, timeout=3000)
            break
        except Exception:
            continue
    time.sleep(0.8)
    for sel in ["text=37", "button:has-text('37')"]:
        try:
            move_click(page, sel, timeout=3000)
            break
        except Exception:
            continue
    for sel in ["text=Dodaj", "button:has-text('Dodaj')"]:
        try:
            move_click(page, sel, timeout=3000)
            break
        except Exception:
            continue
    for sel in [".cart-fab", "#cart-toggle", ".fa-cart-shopping"]:
        try:
            move_click(page, sel, timeout=3000)
            break
        except Exception:
            continue
    scroll_page(page, 200)


def p2_kds(page, env: dict) -> None:
    o = env["owner"]
    page.goto(inject_url(o["jwt"], o["user_json"], f"{BASE}/modules/hub/index.html"))
    page.wait_for_load_state("networkidle")
    hub_tile(page, "kds")
    page.wait_for_load_state("networkidle")
    for sel in ["text=FORNO-004", ".kds-ticket", ".order-card"]:
        try:
            move_click(page, sel, timeout=5000)
            break
        except Exception:
            continue
    for sel in ["text=ROZPOCZNIJ", "button:has-text('ROZPOCZ')"]:
        try:
            move_click(page, sel, timeout=3000)
        except Exception:
            pass
    for sel in ["text=GOTOWE", "button:has-text('GOTOWE')"]:
        try:
            move_click(page, sel, timeout=3000)
        except Exception:
            pass
    scroll_page(page, 300)
    page.goto(inject_url(o["jwt"], o["user_json"], f"{BASE}/modules/hub/index.html"))


def p3_track(page, env: dict) -> None:
    page.goto(env["track_url"], wait_until="domcontentloaded")
    time.sleep(1.5)
    scroll_page(page, 400)
    scroll_page(page, 300)
    try:
        move_click(page, ".leaflet-control-zoom-in", timeout=2000)
        page.mouse.move(960, 540, steps=8)
        page.mouse.down()
        page.mouse.move(1100, 600, steps=10)
        page.mouse.up()
    except Exception:
        pass


def p4_courses(page, env: dict) -> None:
    o = env["owner"]
    page.goto(inject_url(o["jwt"], o["user_json"], f"{BASE}/modules/hub/index.html"))
    page.wait_for_load_state("networkidle")
    hub_tile(page, "courses")
    page.wait_for_load_state("networkidle")
    for sel in ["text=Mapa", "button:has-text('Mapa')"]:
        try:
            move_click(page, sel, timeout=3000)
            break
        except Exception:
            continue
    page.mouse.move(700, 450, steps=10)
    page.mouse.down()
    page.mouse.move(900, 500, steps=12)
    page.mouse.up()
    time.sleep(PAUSE)
    for sel in ["text=Zamówienia", "button:has-text('Zamów')"]:
        try:
            move_click(page, sel, timeout=3000)
            break
        except Exception:
            continue
    for sel in ["tr:has-text('ready')", ".order-row", "text=ORD/"]:
        try:
            move_click(page, sel, timeout=4000)
            break
        except Exception:
            continue
    page.goto(inject_url(o["jwt"], o["user_json"], f"{BASE}/modules/hub/index.html"))
    hub_tile(page, "/pos/")
    for digit in env.get("pos_pin", "0000"):
        try:
            move_click(page, f"button:has-text('{digit}')", timeout=2000)
        except Exception:
            pass


def p5_driver(page, env: dict) -> None:
    d = env["driver"]
    page.goto(inject_url(d["jwt"], d["user_json"], f"{BASE}/modules/driver_app/index.html"))
    page.wait_for_load_state("domcontentloaded")
    time.sleep(1.5)
    for sel in [".run-card", ".order-card", "text=ORD/"]:
        try:
            move_click(page, sel, timeout=5000)
            break
        except Exception:
            continue
    scroll_page(page, 350)
    for sel in ["text=Dostarcz", "button:has-text('Dostarcz')"]:
        try:
            move_click(page, sel, timeout=2000)
        except Exception:
            pass
    for sel in ["text=Anuluj", "text=Cancel"]:
        try:
            move_click(page, sel, timeout=2000)
        except Exception:
            pass


def p6_ksef(page, env: dict) -> None:
    o = env["owner"]
    page.goto(inject_url(o["jwt"], o["user_json"], f"{BASE}/modules/procurement/index.html"))
    time.sleep(3)
    page.wait_for_selector(".pi-invoice-row", timeout=20000)
    move_click(page, ".pi-invoice-row", timeout=5000)
    scroll_page(page, 400)
    try:
        move_click(page, ".pi-detail-value, tbody tr, .pi-line-row", timeout=3000)
    except Exception:
        pass


def p7_bi_studio(page, env: dict) -> None:
    o = env["owner"]
    page.goto(inject_url(o["jwt"], o["user_json"], f"{BASE}/modules/hub/index.html"))
    page.wait_for_load_state("networkidle")
    hub_tile(page, "/bi/")
    page.wait_for_load_state("networkidle")
    for sel in ["text=Załaduj", "button:has-text('Załaduj')"]:
        try:
            move_click(page, sel, timeout=4000)
            break
        except Exception:
            pass
    scroll_page(page, 500)
    page.goto(inject_url(o["jwt"], o["user_json"], f"{BASE}/modules/hub/index.html"))
    hub_tile(page, "../studio/")
    page.wait_for_load_state("networkidle")
    for sel in ["text=PIZZE", "text=MARGHERITA", "text=30CM", "text=37CM"]:
        try:
            move_click(page, sel, timeout=4000)
        except Exception:
            continue
    scroll_page(page, 400)
    page.goto(inject_url(o["jwt"], o["user_json"], f"{BASE}/modules/hub/index.html"))
    scroll_page(page, 600)


PROCESSES = [
    ("spark_P1_online", p1_online),
    ("spark_P2_kds", p2_kds),
    ("spark_P3_track", p3_track),
    ("spark_P4_courses", p4_courses),
    ("spark_P5_driver", p5_driver),
    ("spark_P6_ksef", p6_ksef),
    ("spark_P7_bi", p7_bi_studio),
]


def main() -> None:
    os.environ.setdefault("DISPLAY", ":1")
    only = os.environ.get("SPARK_ONLY", "").strip()
    for name, fn in PROCESSES:
        if only and name != only:
            continue
        print(f"Recording {name}…")
        path = record_process(name, fn)
        if path.exists():
            print(f"  → {path} ({path.stat().st_size // 1024} KB)")
        else:
            print("  → MISSING")


if __name__ == "__main__":
    main()
