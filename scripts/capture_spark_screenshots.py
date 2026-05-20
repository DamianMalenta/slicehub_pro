#!/usr/bin/env python3
"""Capture SPARK hero screenshots from slicehub.net via API JWT + Playwright."""
import json
import subprocess
import sys
import time
from pathlib import Path

OUT = Path("/opt/cursor/artifacts")
OUT.mkdir(parents=True, exist_ok=True)
REPO = Path("/workspace/_docs/SPARK_materialy")
BASE = "https://slicehub.net"
VIEWPORT = {"width": 1920, "height": 1080}


def api_login(username: str, password: str) -> tuple[str, dict]:
    import urllib.request

    body = json.dumps({"mode": "system", "username": username, "password": password}).encode()
    req = urllib.request.Request(
        f"{BASE}/api/auth/login.php",
        data=body,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        data = json.loads(r.read().decode())
    if not data.get("success"):
        raise RuntimeError(f"Login failed for {username}: {data}")
    return data["data"]["token"], data["data"]["user"]


def main():
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        subprocess.check_call([sys.executable, "-m", "pip", "install", "playwright", "-q"])
        subprocess.check_call([sys.executable, "-m", "playwright", "install", "chromium"])
        from playwright.sync_api import sync_playwright

    owner_token, owner_user = api_login("Damian", "Dammalq123123")
    driver_token, driver_user = api_login("kasia@slicehub.net", "asdasd")

    shots = [
        ("hero_01_hub.png", owner_token, owner_user, f"{BASE}/modules/hub/index.html", None),
        ("hero_02_online.png", owner_token, owner_user, f"{BASE}/modules/online/index.html", 3000),
        ("hero_04_studio.png", owner_token, owner_user, f"{BASE}/modules/studio/index.html", 4000),
        ("hero_06_warehouse.png", owner_token, owner_user, f"{BASE}/modules/warehouse/index.html", 2500),
        ("hero_03_pos.png", owner_token, owner_user, f"{BASE}/modules/pos/index.html", None, "pos"),
        ("hero_05_courses.png", owner_token, owner_user, f"{BASE}/modules/courses/index.html", 4000),
        ("hero_07_ksef.png", owner_token, owner_user, f"{BASE}/modules/procurement/index.html", 3500, "ksef"),
        ("hero_08_bi.png", owner_token, owner_user, f"{BASE}/modules/bi/index.html", 3000, "bi"),
        ("hero_09_driver.png", driver_token, driver_user, f"{BASE}/modules/driver_app/index.html", 3000),
        ("hero_10_kds.png", owner_token, owner_user, f"{BASE}/modules/kds/index.html", 3000),
    ]

    inject_js = """
    ([token, user]) => {
      localStorage.setItem('sh_token', token);
      localStorage.setItem('sh_user', JSON.stringify(user));
    }
    """

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            viewport=VIEWPORT,
            device_scale_factor=1,
            color_scheme="dark",
        )

        for item in shots:
            name = item[0]
            token, user = item[1], item[2]
            url = item[3]
            wait_ms = item[4] if len(item) > 4 and isinstance(item[4], int) else 2500
            mode = item[5] if len(item) > 5 else None

            page = context.new_page()
            try:
                page.goto(f"{BASE}/modules/hub/index.html", wait_until="domcontentloaded", timeout=60000)
                page.evaluate(inject_js, [token, user])
                page.goto(url, wait_until="networkidle", timeout=90000)
                page.wait_for_timeout(wait_ms)

                if mode == "pos":
                    # Kiosk PIN if pin screen visible
                    pin = page.locator("#pin-screen")
                    if pin.is_visible(timeout=3000):
                        for d in "1111":
                            page.locator(f'button[data-pin="{d}"], .pin-key:has-text("{d}")').first.click(timeout=2000)
                            page.wait_for_timeout(200)
                        page.wait_for_timeout(3000)
                    page.wait_for_timeout(2000)

                if mode == "ksef":
                    # Open first invoice in list if present
                    row = page.locator("tr.pi-row, .pi-invoice-row, [data-invoice-id]").first
                    if row.count() > 0:
                        row.click(timeout=5000)
                        page.wait_for_timeout(2500)

                if mode == "bi":
                    btn = page.locator("#bi-load")
                    if btn.is_visible(timeout=3000):
                        btn.click()
                        page.wait_for_timeout(3500)

                path = OUT / name
                page.screenshot(path=str(path), full_page=False)
                print(f"OK {name} -> {path}")
            except Exception as e:
                print(f"FAIL {name}: {e}", file=sys.stderr)
            finally:
                page.close()

        browser.close()

    for png in OUT.glob("hero_*.png"):
        dest = REPO / png.name
        dest.write_bytes(png.read_bytes())
        print(f"copied -> {dest}")


if __name__ == "__main__":
    main()
