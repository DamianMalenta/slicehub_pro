#!/usr/bin/env python3
"""Prepare tenant for SPARK recording: KDS tickets + driver route (localhost or production)."""
from __future__ import annotations

import json
import os
import urllib.request

BASE = os.environ.get("SLICEHUB_BASE", "http://localhost/slicehub").rstrip("/")
OWNER_USER = os.environ.get("SLICEHUB_OWNER_USER", "spark_owner")
OWNER_PASS = os.environ.get("SLICEHUB_OWNER_PASS", "password")
DRIVER_USER = os.environ.get("SLICEHUB_DRIVER_USER", "spark_driver")
DRIVER_PASS = os.environ.get("SLICEHUB_DRIVER_PASS", "password")


def post(path: str, token: str, body: dict) -> dict:
    data = json.dumps(body).encode()
    req = urllib.request.Request(
        f"{BASE}{path}",
        data=data,
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode())


def login(user: str, password: str) -> tuple[str, dict]:
    body = json.dumps({"mode": "system", "username": user, "password": password}).encode()
    req = urllib.request.Request(
        f"{BASE}/api/auth/login.php",
        data=body,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        d = json.loads(r.read().decode())
    if not d.get("success"):
        raise RuntimeError(f"Login failed for {user}: {d}")
    return d["data"]["token"], d["data"]["user"]


def find_order_id(token: str, order_number: str) -> str | None:
    r = post("/api/pos/engine.php", token, {"action": "get_orders"})
    orders = (r.get("data") or {}).get("orders") or r.get("data") or []
    if not isinstance(orders, list):
        return None
    for o in orders:
        if o.get("order_number") == order_number:
            return o.get("id")
    return None


def main() -> None:
    print(f"Base: {BASE}")
    owner_token, owner_user = login(OWNER_USER, OWNER_PASS)
    driver_id = str(owner_user.get("id", ""))  # fallback

    forno4 = find_order_id(owner_token, "FORNO-004")
    if forno4:
        r = post("/api/kds/engine.php", owner_token, {
            "action": "recall_order",
            "order_id": forno4,
        })
        print("recall FORNO-004:", r.get("success"), r.get("data"))
    else:
        print("WARN: FORNO-004 not found — run seed_pizzaforno first")

    board = post("/api/kds/engine.php", owner_token, {"action": "get_board"})
    orders = board.get("data", {}).get("orders", [])
    print(f"KDS board: {len(orders)} orders")
    for o in orders:
        print(f"  - {o.get('order_number')} {o.get('status')}")

    po = post("/api/pos/engine.php", owner_token, {
        "action": "process_order",
        "order_type": "delivery",
        "source": "POS",
        "payment_method": "online",
        "payment_status": "online_paid",
        "customer_name": "Demo SPARK Nagranie",
        "customer_phone": "+48 600 999 888",
        "address": "ul. Testowa 1, Olsztyn",
        "cart": [{"cart_id": "d1", "ascii_key": "DIAVOLA_30CM", "name": "Diavola 30cm", "price": "35.00", "qty": 1}],
        "total_price": 35.00,
        "status": "pending",
    })
    oid = po.get("data", {}).get("order_id")
    print("new order:", oid)

    post("/api/pos/engine.php", owner_token, {"action": "accept_order", "order_id": oid})
    for st in ("preparing", "ready"):
        post("/api/kds/engine.php", owner_token, {
            "action": "bump_order",
            "order_id": oid,
            "new_status": st,
        })

    _, driver_user = login(DRIVER_USER, DRIVER_PASS)
    driver_uid = str(driver_user.get("id", driver_id))

    disp = post("/api/courses/engine.php", owner_token, {
        "action": "dispatch",
        "driver_id": driver_uid,
        "order_ids": [oid],
    })
    print("dispatch:", disp.get("success"), disp.get("data"))

    driver_token, _ = login(DRIVER_USER, DRIVER_PASS)
    runs = post("/api/courses/engine.php", driver_token, {"action": "get_driver_runs"})
    dr_orders = runs.get("data", {}).get("orders", [])
    print(f"Driver runs: {len(dr_orders)}")
    for o in dr_orders:
        print(f"  - {o.get('order_number')} {o.get('status')} {o.get('course_id')}")

    if len(orders) < 1:
        raise SystemExit("FAIL: KDS empty after prep")
    if len(dr_orders) < 1:
        raise SystemExit("FAIL: Driver empty after prep")
    print("OK prep complete")


if __name__ == "__main__":
    main()
