#!/usr/bin/env python3
"""Capture F6S update screenshots from localhost SliceHub via JWT + Playwright."""
from __future__ import annotations

import json
import subprocess
import sys
import urllib.request
from pathlib import Path

BASE = "http://localhost/slicehub"
OUT = Path(__file__).resolve().parent.parent / "_docs" / "F6S_SPARK_3_0" / "screenshots_update"
VIEWPORT = {"width": 1920, "height": 1080}


def ensure_playwright():
    try:
        from playwright.sync_api import sync_playwright  # noqa: F401
    except ImportError:
        subprocess.check_call([sys.executable, "-m", "pip", "install", "playwright", "-q"])
        subprocess.check_call([sys.executable, "-m", "playwright", "install", "chromium"])
    from playwright.sync_api import sync_playwright

    return sync_playwright


def api_login(username: str, password: str) -> tuple[str, dict]:
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


INJECT_JS = """
([token, user]) => {
  localStorage.setItem('sh_token', token);
  localStorage.setItem('sh_user', JSON.stringify(user));
}
"""


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    sync_playwright = ensure_playwright()
    token, user = api_login("Damian", "Dammalq123123")

    shots: list[tuple[str, str, int, str | None]] = [
        ("01_hub_moduly_lego.png", f"{BASE}/modules/hub/index.html", 1800, "hub"),
        ("02_ksef_inbox_lista.png", f"{BASE}/modules/procurement/index.html", 3500, "ksef_list"),
        ("03_ksef_faktura_mapowanie.png", f"{BASE}/modules/procurement/index.html", 500, "ksef_detail"),
        ("04_bi_pl_dashboard.png", f"{BASE}/modules/bi/index.html", 4500, "bi_top"),
        ("05_bi_capital_flow.png", f"{BASE}/modules/bi/index.html", 4500, "bi_flow"),
    ]

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            viewport=VIEWPORT,
            device_scale_factor=1,
            color_scheme="dark",
        )

        for name, url, wait_ms, mode in shots:
            page = context.new_page()
            try:
                page.goto(f"{BASE}/modules/hub/index.html", wait_until="domcontentloaded", timeout=60000)
                page.evaluate(INJECT_JS, [token, user])
                page.goto(url, wait_until="networkidle", timeout=90000)
                page.wait_for_timeout(wait_ms)

                if mode == "hub":
                    page.wait_for_selector("#hub-dash:not(.hub-hidden)", timeout=10000)
                    page.evaluate("window.scrollTo(0, 0)")

                if mode == "ksef_list":
                    page.wait_for_selector("#pi-invoices-list .pi-invoice-row", timeout=15000)

                if mode == "ksef_detail":
                    page.wait_for_selector("#pi-invoices-list .pi-invoice-row", timeout=15000)
                    target = page.locator('.pi-invoice-row:has-text("FA/FORNO/2026/002")').first
                    if target.count() == 0:
                        target = page.locator("#pi-invoices-list .pi-invoice-row").first
                    target.click(timeout=8000)
                    page.wait_for_selector("#pi-modal-backdrop:not(.hidden)", timeout=10000)
                    page.wait_for_timeout(2500)
                    modal = page.locator("#pi-modal-backdrop")
                    path = OUT / name
                    modal.screenshot(path=str(path))
                    print(f"OK {name} -> {path}")
                    continue

                if mode in ("bi_top", "bi_flow"):
                    page.locator("#bi-from").fill("2026-05-01")
                    page.locator("#bi-to").fill("2026-06-30")
                    btn = page.locator("#bi-load")
                    if btn.is_visible(timeout=5000):
                        btn.click()
                    page.wait_for_function(
                        """() => {
                            const el = document.getElementById('bi-net');
                            if (!el) return false;
                            const t = (el.textContent || '').trim();
                            return t && t !== '—' && !t.startsWith('0,00');
                        }""",
                        timeout=15000,
                    )
                    page.wait_for_timeout(1200)
                    if mode == "bi_flow":
                        page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
                        page.wait_for_timeout(600)
                    else:
                        page.evaluate("window.scrollTo(0, 0)")
                        page.wait_for_timeout(400)

                path = OUT / name
                page.screenshot(path=str(path), full_page=False)
                print(f"OK {name} -> {path}")
            except Exception as exc:
                print(f"FAIL {name}: {exc}", file=sys.stderr)
                return 1
            finally:
                page.close()

        browser.close()

    print(f"DONE -> {OUT}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
