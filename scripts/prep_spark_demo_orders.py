#!/usr/bin/env python3
"""Prepare slicehub.net tenant 2 for SPARK recording: KDS tickets + driver route."""
from __future__ import annotations

import json
import urllib.request

BASE = "https://slicehub.net"


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


def login(user: str, password: str) -> str:
    body = json.dumps({"mode": "system", "username": user, "password": password}).encode()
    req = urllib.request.Request(
        f"{BASE}/api/auth/login.php",
        data=body,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        d = json.loads(r.read().decode())
    return d["data"]["token"]


def main() -> None:
    owner = login("Damian", "Dammalq123123")

    # KDS: FORNO-004 ready → preparing
    r = post("/api/kds/engine.php", owner, {
        "action": "recall_order",
        "order_id": "59927821-12e2-4851-8ea9-5a4b94ef6d19",
    })
    print("recall FORNO-004:", r.get("success"), r.get("data"))

    board = post("/api/kds/engine.php", owner, {"action": "get_board"})
    orders = board.get("data", {}).get("orders", [])
    print(f"KDS board: {len(orders)} orders")
    for o in orders:
        print(f"  - {o.get('order_number')} {o.get('status')}")

    # New delivery → dispatch Kasia (user_id=2)
    po = post("/api/pos/engine.php", owner, {
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

    post("/api/pos/engine.php", owner, {"action": "accept_order", "order_id": oid})
    for st in ("preparing", "ready"):
        post("/api/kds/engine.php", owner, {"action": "bump_order", "order_id": oid, "new_status": st})

    disp = post("/api/courses/engine.php", owner, {
        "action": "dispatch",
        "driver_id": "2",
        "order_ids": [oid],
    })
    print("dispatch:", disp.get("success"), disp.get("data"))

    kasia = login("kasia@slicehub.net", "asdasd")
    runs = post("/api/courses/engine.php", kasia, {"action": "get_driver_runs"})
    dr_orders = runs.get("data", {}).get("orders", [])
    print(f"Driver runs: {len(dr_orders)}")
    for o in dr_orders:
        print(f"  - {o.get('order_number')} {o.get('status')} {o.get('course_id')}")


if __name__ == "__main__":
    main()
