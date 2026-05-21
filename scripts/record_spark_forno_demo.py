#!/usr/bin/env python3
"""DEPRECATED for SPARK video — produces static slideshow-like clips.

Use RecordScreen + computerUse per _docs/PROMPT_SPARK_NAGRANIE_PROCESY_FORNO_V2.md instead.
This script remains only for quick PNG screenshots / API smoke checks.
"""
from __future__ import annotations

import json
import subprocess
import sys
import urllib.request
from pathlib import Path

BASE = "https://slicehub.net"
OUT = Path("/opt/cursor/artifacts")
OUT.mkdir(parents=True, exist_ok=True)
VIEWPORT = {"width": 1920, "height": 1080}


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
        raise RuntimeError(f"Login failed: {data}")
    return data["data"]["token"], data["data"]["user"]


def ensure_playwright():
    try:
        from playwright.sync_api import sync_playwright  # noqa: F401
    except ImportError:
        subprocess.check_call([sys.executable, "-m", "pip", "install", "playwright", "-q"])
        subprocess.check_call([sys.executable, "-m", "playwright", "install", "chromium"])


def inject(page, token: str, user: dict) -> None:
    page.evaluate(
        """([token, user]) => {
          localStorage.setItem('sh_token', token);
          localStorage.setItem('sh_user', JSON.stringify(user));
        }""",
        [token, user],
    )


def main() -> None:
    ensure_playwright()
    from playwright.sync_api import sync_playwright

    owner_token, owner_user = api_login("Damian", "Dammalq123123")
    driver_token, driver_user = api_login("kasia@slicehub.net", "asdasd")

    video_dir = OUT / "spark_forno_video"
    video_dir.mkdir(parents=True, exist_ok=True)

    scenes = [
        ("01_online", f"{BASE}/modules/online/index.html", None, 5000),
        ("02_kds", f"{BASE}/modules/kds/index.html", owner_token, 6000),
        ("03_courses", f"{BASE}/modules/courses/index.html", owner_token, 6000),
        ("04_driver", f"{BASE}/modules/driver_app/index.html", driver_token, 6000),
        ("05_ksef", f"{BASE}/modules/procurement/index.html", owner_token, 5000),
        ("06_bi", f"{BASE}/modules/bi/index.html", owner_token, 5000),
        ("07_studio", f"{BASE}/modules/studio/index.html", owner_token, 5000),
    ]

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            viewport=VIEWPORT,
            record_video_dir=str(video_dir),
            record_video_size={"width": 1920, "height": 1080},
            color_scheme="dark",
        )

        for name, url, token, wait_ms in scenes:
            page = context.new_page()
            try:
                if token:
                    page.goto(f"{BASE}/modules/hub/index.html", wait_until="domcontentloaded", timeout=60000)
                    inject(page, token, owner_user if token == owner_token else driver_user)
                page.goto(url, wait_until="networkidle", timeout=90000)
                page.wait_for_timeout(wait_ms)

                if name == "01_online":
                    page.evaluate("window.scrollTo(0, 400)")
                    page.wait_for_timeout(1500)
                    for sel in ["text=PIZZE", "text=PANINI", "text=MARGHERITA"]:
                        try:
                            page.locator(sel).first.click(timeout=3000)
                            page.wait_for_timeout(1200)
                        except Exception:
                            pass

                if name == "02_kds":
                    page.wait_for_timeout(2000)

                if name == "05_ksef":
                    row = page.locator("tr.pi-row, .pi-invoice-row, [data-invoice-id], tbody tr").first
                    if row.count() > 0:
                        row.click(timeout=5000)
                        page.wait_for_timeout(2500)

                if name == "06_bi":
                    btn = page.locator("#bi-load")
                    if btn.is_visible(timeout=3000):
                        btn.click()
                        page.wait_for_timeout(3500)

                shot = OUT / f"spark_forno_{name}.png"
                page.screenshot(path=str(shot), full_page=False)
                print(f"OK screenshot {shot}")
            except Exception as e:
                print(f"FAIL {name}: {e}", file=sys.stderr)
            finally:
                page.close()

        context.close()
        browser.close()

    # Collect webm files from playwright
    webms = sorted(video_dir.glob("*.webm"))
    print(f"Video chunks: {len(webms)} in {video_dir}")
    for w in webms:
        print(f"  {w} ({w.stat().st_size // 1024} KB)")

    if webms:
        raw = OUT / "spark_forno_procesy_raw.webm"
        if len(webms) == 1:
            raw.write_bytes(webms[0].read_bytes())
        else:
            list_file = OUT / "concat_list.txt"
            list_file.write_text("\n".join(f"file '{w}'" for w in webms))
            subprocess.run(
                [
                    "ffmpeg", "-y", "-f", "concat", "-safe", "0",
                    "-i", str(list_file), "-c", "copy", str(raw),
                ],
                check=False,
                capture_output=True,
            )
        if raw.exists():
            out_mp4 = OUT / "spark_forno_procesy_90s.mp4"
            subprocess.run(
                [
                    "ffmpeg", "-y", "-i", str(raw),
                    "-vf", "setpts=0.2*PTS,fps=30",
                    "-an", "-t", "90",
                    str(out_mp4),
                ],
                check=False,
            )
            print(f"Final: {out_mp4} exists={out_mp4.exists()}")


if __name__ == "__main__":
    main()
