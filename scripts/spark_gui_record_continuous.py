#!/usr/bin/env python3
"""
Jedno nagranie SPARK — cała ścieżka P1→P7 w jednej sesji Chrome (bez montażu xfade).
Wynik: spark_forno_procesy_ciagle.webm → opcjonalnie .mp4 (prędkość 1.0×).
"""
from __future__ import annotations

import json
import os
import subprocess
import time
from pathlib import Path
from urllib.parse import quote

# reuse helpers from per-process script
from spark_gui_record_processes import (
    BASE,
    ENV_PATH,
    PAUSE,
    hub_tile,
    inject_url,
    load_env,
    move_click,
    p1_online,
    p2_kds,
    p3_track,
    p4_courses,
    p5_driver,
    p6_ksef,
    p7_bi_studio,
    scroll_page,
)

OUT = Path("/opt/cursor/artifacts")
REPO_OUT = Path(__file__).resolve().parents[1] / "_docs/SPARK_materialy/wideo"


def run_continuous() -> Path:
    from playwright.sync_api import sync_playwright

    env = load_env()
    video_dir = OUT / "continuous_rec"
    video_dir.mkdir(parents=True, exist_ok=True)
    for f in video_dir.glob("*.webm"):
        f.unlink()

    os.environ.setdefault("DISPLAY", ":1")

    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=False,
            args=["--window-size=1920,1080", "--window-position=0,0"],
        )
        context = browser.new_context(
            viewport={"width": 1920, "height": 1080},
            record_video_dir=str(video_dir),
            record_video_size={"width": 1920, "height": 1080},
        )
        page = context.new_page()

        try:
            print("P1 Online…")
            p1_online(page, env)
            print("P2 KDS…")
            p2_kds(page, env)
            print("P3 Track (nowa karta)…")
            track = context.new_page()
            p3_track(track, env)
            track.close()
            print("P4 Courses…")
            p4_courses(page, env)
            print("P5 Driver…")
            p5_driver(page, env)
            print("P6 KSeF…")
            p6_ksef(page, env)
            print("P7 BI + Studio…")
            p7_bi_studio(page, env)
        finally:
            page.close()
            context.close()
            browser.close()

    webms = sorted(video_dir.glob("*.webm"), key=lambda x: x.stat().st_mtime)
    if not webms:
        raise SystemExit("Brak pliku wideo z ciągłej sesji")
    src = webms[-1]
    dest = OUT / "spark_forno_procesy_ciagle.webm"
    src.rename(dest)
    print(f"✅ {dest} ({dest.stat().st_size // 1024} KB)")

    mp4 = OUT / "spark_forno_procesy_ciagle.mp4"
    subprocess.run(
        [
            "ffmpeg", "-y", "-i", str(dest),
            "-an", "-c:v", "libx264", "-preset", "fast", "-crf", "22",
            "-movflags", "+faststart", str(mp4),
        ],
        check=True,
        capture_output=True,
    )
    print(f"✅ {mp4}")

    REPO_OUT.mkdir(parents=True, exist_ok=True)
    for target in (
        REPO_OUT / "spark_forno_procesy_ciagle.webm",
        REPO_OUT / "spark_forno_procesy_ciagle.mp4",
        REPO_OUT / "spark_forno_procesy_final.mp4",
    ):
        subprocess.run(["cp", "-f", str(mp4 if target.suffix == ".mp4" else dest), str(target)], check=True)
    print(f"Skopiowano do {REPO_OUT}")
    return dest


if __name__ == "__main__":
    run_continuous()
