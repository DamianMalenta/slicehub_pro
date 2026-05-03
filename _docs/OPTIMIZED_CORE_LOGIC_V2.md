# SLICEHUB OS — OPTIMIZED CORE LOGIC V2 (Gold Standard)

> **Synthesized from** `LEGACY_BUSINESS_LOGIC_EXTRACTION.md` (1324 lines across ~146 legacy files).
> Every conflicting version has been compared, the best algorithm selected, bugs eliminated,
> and logic upgraded for a modern, stateless REST API architecture.
>
> **This document contains ZERO implementation code.**
> It defines pure architectural logic, mathematical formulas, and data contracts.

---

## North Star — jak rozwijamy produkt (bez przebudowy od zera)

Strategia wdrożenia **nie zakłada** pisania systemu od nowa ani zmiany paradygmatu „na siłę”. Rozwój jest **inkrementalny**: ten sam rdzeń (PHP, moduły operacyjne, baza, event/outbox), migracje i workery CLI dokładane obok istniejącego kodu.

**Kolejność priorytetów:**

1. **Domknąć panel ustawień** (`modules/settings`, integracje, webhooki, klucze, DLQ, health) jako warstwę konfiguracji **na prawie gotowym** stacku operacyjnym — nie jako osobny produkt obok.
   - *Stan 2026-05-03:* Settings domknięty funkcjonalnie (w tym zakładka Dziennik / `sh_settings_audit`). Rozliczenia split-tender poza POS: `api/payments/settle.php` emituje **`order.completed`** lub **`payment.settled`** przez `OrderEventPublisher` (ta sama transakcja co zapis płatności).
2. **Następnie** wziąć na warsztat pozostały backlog (np. offline POS, rozliczenia, HR, edge case’y z dokumentów pokrewnych) i **udoskonalać pod testy w realnym lokalu** (własna pizzeria jako środowisko walidacji).

Ten dokument opisuje **logikę złota standardu** niezależnie od kolejności implementacji; powyższa kolejność mówi **w jakiej kolejności sens ma ją wdrażać zespół**.

---

## Table of Contents

| Domain | Section |
|--------|---------|
| **CART** | [1. Server-Authoritative Cart Engine](#1-server-authoritative-cart-engine) |
| **CART** | [2. Composite Item Pricing (Half-Half)](#2-composite-item-pricing-half-half) |
| **CART** | [3. Modifier Price Resolution](#3-modifier-price-resolution) |
| **CART** | [4. VAT / Tax Computation](#4-vat--tax-computation) |
| **CART** | [5. Discount & Promo Engine](#5-discount--promo-engine) |
| **ORDERS** | [6. Order Number Generation](#6-order-number-generation) |
| **ORDERS** | [7. Order Lifecycle State Machine](#7-order-lifecycle-state-machine) |
| **ORDERS** | [8. Order Edit & Kitchen Delta Detection](#8-order-edit--kitchen-delta-detection) |
| **ORDERS** | [9. Payment Settlement & Split Tender](#9-payment-settlement--split-tender) |
| **KITCHEN** | [10. KDS Ticket Routing Engine](#10-kds-ticket-routing-engine) |
| **KITCHEN** | [11. Panic Mode (Global Delay)](#11-panic-mode-global-delay) |
| **GATEWAY** | [12. Online Order Intake & Validation](#12-online-order-intake--validation) |
| **GATEWAY** | [13. Promised Time Engine](#13-promised-time-engine) |
| **LOGISTICS** | [14. Dispatch & Route Assignment (K/L System)](#14-dispatch--route-assignment-kl-system) |
| **LOGISTICS** | [15. Driver Cashbox & End-of-Shift Reconciliation](#15-driver-cashbox--end-of-shift-reconciliation) |
| **LOGISTICS** | [16. Delivery SLA Monitor](#16-delivery-sla-monitor) |
| **LOGISTICS** | [17. Driver Status State Machine](#17-driver-status-state-machine) |
| **WAREHOUSE** | [18. Recipe-Based Stock Consumption (WZ Engine)](#18-recipe-based-stock-consumption-wz-engine) |
| **WAREHOUSE** | [19. Goods Receipt & AVCO Valuation (PZ Engine)](#19-goods-receipt--avco-valuation-pz-engine) |
| **WAREHOUSE** | [20. Inter-Warehouse Transfer (MM)](#20-inter-warehouse-transfer-mm) |
| **WAREHOUSE** | [21. Void / Correction (KOR)](#21-void--correction-kor) |
| **WAREHOUSE** | [22. Physical Inventory Count (INW)](#22-physical-inventory-count-inw) |
| **WAREHOUSE** | [23. Food Cost & Margin Calculation](#23-food-cost--margin-calculation) |
| **STAFF** | [24. Clock In/Out & Work Sessions](#24-clock-inout--work-sessions) |
| **STAFF** | [25. Payroll Calculation Engine](#25-payroll-calculation-engine) |
| **STAFF** | [26. Boss Dashboard — Team Aggregate Payroll](#26-boss-dashboard--team-aggregate-payroll) |
| **PLATFORM** | [27. Authentication & Tenant Isolation](#27-authentication--tenant-isolation) |
| **PLATFORM** | [28. Document Numbering Standard](#28-document-numbering-standard) |
| **PLATFORM** | [29. ASCII Key Generation](#29-ascii-key-generation) |

---

## DOMAIN: CART

---

### 1. Server-Authoritative Cart Engine

- **The Legacy Mess:** The client computed `total = Σ(price × qty)` in JavaScript and POSTed it as `total_price`. The server stored it **blindly** — no recomputation, no validation. A tampered request could set any total. Additionally, all prices lived as mutable strings on the client, prone to floating-point drift via repeated `toFixed(2)` calls.

- **The Optimized Algorithm (Gold Standard):**

  **Step 1 — Client submits cart lines only (never a total).** Each line contains: `item_sku`, `variant_sku` (optional), `quantity`, `modifier_skus[]` (added), `removed_ingredient_skus[]`, `comment`, `is_half`, `half_a_sku`, `half_b_sku`.

  **Step 2 — Server resolves all prices** from `sh_price_tiers` for the order's channel (`POS`, `Takeaway`, `Delivery`):
  - Look up base price: `SELECT price FROM sh_price_tiers WHERE target_type = 'ITEM' AND target_sku = :sku AND channel = :channel`
  - For each added modifier: `SELECT price FROM sh_price_tiers WHERE target_type = 'MODIFIER' AND target_sku = :mod_sku AND channel = :channel`

  **Step 3 — Compute line total:**

  $$\text{line\_unit\_price} = \text{base\_price} + \sum \text{modifier\_prices}$$

  For half-half items (see §2), replace `base_price` with the composite price formula.

  $$\text{line\_total} = \text{line\_unit\_price} \times \text{quantity}$$

  **Step 4 — Compute order subtotal:**

  $$\text{subtotal} = \sum_{i} \text{line\_total}_i$$

  **Step 5 — Apply discounts** (see §5), delivery fee, then round:

  $$\text{grand\_total} = \text{round}(\text{subtotal} - \text{discount} + \text{delivery\_fee},\ 2)$$

  All monetary arithmetic MUST use integer minor units (grosze) internally, converting to decimal only for display/storage.

- **Modernization Upgrades:**
  - **Server-authoritative pricing** — the client never dictates prices; it only sends SKU references and quantities. This eliminates the critical legacy vulnerability of client-sent totals.
  - **Integer arithmetic** for all monetary values (multiply by 100, compute in grosze, divide at the end) to eliminate floating-point rounding errors that plagued the legacy `toFixed(2)` chain.

- **Data Blueprint:**

  **Request — `POST /api/orders`** (cart lines in body):

  ```json
  {
    "channel": "POS",
    "order_type": "delivery",
    "lines": [
      {
        "line_id": "uuid-v4",
        "item_sku": "margherita_32",
        "variant_sku": "margherita_32_large",
        "quantity": 2,
        "added_modifier_skus": ["extra_cheese", "jalapeno"],
        "removed_ingredient_skus": ["olives"],
        "comment": "Extra crispy",
        "is_half": false,
        "half_a_sku": null,
        "half_b_sku": null
      }
    ],
    "customer": { "phone": "+48600123456", "name": "Jan", "address": "ul. Kwiatowa 5" },
    "promo_code": "WEEKEND10",
    "requested_time": "2026-04-11T18:30:00"
  }
  ```

  **Response — `201 Created`:**

  ```json
  {
    "success": true,
    "data": {
      "order_id": "uuid-v4",
      "order_number": "ORD/20260411/047",
      "lines": [
        {
          "line_id": "uuid-v4",
          "item_sku": "margherita_32",
          "snapshot_name": "Margherita 32cm",
          "base_price": "32.00",
          "modifiers": [
            { "sku": "extra_cheese", "name": "Extra Ser", "price": "5.00" },
            { "sku": "jalapeno", "name": "Jalapeño", "price": "4.00" }
          ],
          "unit_price": "41.00",
          "quantity": 2,
          "line_total": "82.00",
          "vat_rate": "8.00",
          "vat_amount": "6.07"
        }
      ],
      "subtotal": "82.00",
      "discount": { "code": "WEEKEND10", "type": "percentage", "value": 10, "amount": "8.20" },
      "delivery_fee": "5.00",
      "grand_total": "78.80",
      "promised_time": "2026-04-11T18:30:00",
      "status": "pending"
    }
  }
  ```

---

### 2. Composite Item Pricing (Half-Half)

- **The Legacy Mess:** Both `pos.html` and `waiter.html` used the same formula: `max(priceA, priceB) + 2.00`. The `+2.00` surcharge was hardcoded in JavaScript with no configuration. Prices were resolved client-side from a flat `price` field, not from channel-specific tiers.

- **The Optimized Algorithm (Gold Standard):**

  **Step 1 — Resolve base prices** for both halves from `sh_price_tiers` using the order's channel.

  **Step 2 — Compute composite base price:**

  $$\text{composite\_base} = \max(price_A,\ price_B) + \text{half\_surcharge}$$

  Where `half_surcharge` is a **tenant-configurable value** stored in `sh_tenant_settings` (key: `half_half_surcharge`, default: `2.00`).

  **Step 3 — Apply modifiers** to the composite item (modifiers are resolved per-SKU from `sh_price_tiers`, not a flat +4).

  **Step 4 — For stock deduction**, each half consumes at **0.5 × multiplier** (see §18).

- **Modernization Upgrades:**
  - The `+2.00` surcharge is now a configurable tenant setting instead of a magic constant.
  - Price resolution uses the per-channel `sh_price_tiers` table, meaning a half-half on Delivery can cost differently than on POS.

- **Data Blueprint:**

  ```json
  {
    "line_id": "uuid-v4",
    "is_half": true,
    "half_a_sku": "capricciosa_32",
    "half_b_sku": "hawaii_32",
    "quantity": 1,
    "added_modifier_skus": ["extra_cheese"],
    "removed_ingredient_skus": ["pineapple"]
  }
  ```

  Server resolves internally and returns:

  ```json
  {
    "snapshot_name": "½ Capricciosa + ½ Hawaii 32cm",
    "base_price_a": "34.00",
    "base_price_b": "32.00",
    "half_surcharge": "2.00",
    "composite_base": "36.00",
    "modifiers_total": "5.00",
    "unit_price": "41.00"
  }
  ```

---

### 3. Modifier Price Resolution

- **The Legacy Mess:** Every modifier added a hardcoded `+4.00 PLN` regardless of what the modifier actually was. Removing a base ingredient ("BEZ") had no price impact. The v2 schema introduced `sh_price_tiers` with per-modifier-per-channel pricing and `sh_modifiers` with `linked_warehouse_sku`/`linked_quantity`, but the POS never used them.

- **The Optimized Algorithm (Gold Standard):**

  **Adding a modifier:**
  1. Look up modifier price: `sh_price_tiers WHERE target_type = 'MODIFIER' AND target_sku = :mod_sku AND channel = :channel`.
  2. If no tier exists for the channel, fall back to the `POS` channel tier. If still none, price is `0.00`.
  3. Add modifier price to the line's unit price.

  **Removing a base ingredient:**
  1. Removals are **free** (no price reduction) — this is the intentional legacy behavior and industry standard for pizza customization.
  2. Removals only affect stock deduction (ingredient skipped in recipe engine).

  **Modifier action types** (from `sh_modifiers.action_type`):
  - `ADD` — Adds a new ingredient with a price.
  - `REMOVE` — Records removal (zero price impact, stock impact).
  - `NONE` — Informational flag only.

- **Modernization Upgrades:**
  - Per-modifier, per-channel dynamic pricing replaces the blanket +4 PLN hardcode.
  - Modifiers now carry `linked_warehouse_sku` and `linked_quantity` so stock deduction uses the actual configured amount instead of the legacy heuristic (`1.0` for pieces, `0.05` for kg).

- **Data Blueprint:**

  ```json
  {
    "modifier_sku": "extra_cheese",
    "action_type": "ADD",
    "prices": {
      "POS": "5.00",
      "Takeaway": "5.00",
      "Delivery": "6.00"
    },
    "stock_link": {
      "warehouse_sku": "mozzarella_kg",
      "linked_quantity": 0.080,
      "linked_unit": "kg",
      "linked_waste_percent": 5.0
    }
  }
  ```

---

### 4. VAT / Tax Computation

- **The Legacy Mess:** The `vat_rate` column existed on `sh_order_items` (defaulting to `8.00`) but was **never populated or used**. All prices were gross (tax-inclusive). Receipts were labeled "non-fiscal." The v2 schema introduced `vat_rate_dine_in` and `vat_rate_takeaway` on `sh_menu_items`, acknowledging that Polish tax law requires different VAT rates for dine-in (8%) vs takeaway (5%) for prepared food.

- **The Optimized Algorithm (Gold Standard):**

  **Step 1 — Determine applicable VAT rate** per line item based on `order_type`:

  | Order Type | VAT Rate Source |
  |-----------|----------------|
  | `dine_in` | `sh_menu_items.vat_rate_dine_in` (default: 8%) |
  | `takeaway`, `delivery` | `sh_menu_items.vat_rate_takeaway` (default: 5%) |

  **Step 2 — Extract VAT from gross price (Polish standard: prices are always gross):**

  $$\text{vat\_amount} = \text{line\_total} - \frac{\text{line\_total}}{1 + \frac{\text{vat\_rate}}{100}}$$

  Simplified:

  $$\text{vat\_amount} = \text{line\_total} \times \frac{\text{vat\_rate}}{100 + \text{vat\_rate}}$$

  $$\text{net\_amount} = \text{line\_total} - \text{vat\_amount}$$

  **Step 3 — Aggregate per VAT rate** for receipt printing:

  $$\text{vat\_summary}[rate] = \sum \text{vat\_amount for lines with that rate}$$

- **Modernization Upgrades:**
  - VAT is now **always computed server-side** and stored per order line, enabling Polish fiscal compliance.
  - Dual VAT rate support (`dine_in` vs `takeaway`) based on the order type, as required by Polish tax law since 2024.

- **Data Blueprint:**

  ```json
  {
    "line_vat": {
      "gross": "82.00",
      "net": "75.93",
      "vat_rate": "8.00",
      "vat_amount": "6.07"
    },
    "order_vat_summary": [
      { "rate": "8.00", "net_total": "75.93", "vat_total": "6.07", "gross_total": "82.00" }
    ]
  }
  ```

---

### 5. Discount & Promo Engine

- **The Legacy Mess:** Discounts were **never implemented**. A loyalty mockup mentioned `10 PLN → 1 point, 100 points → free pizza` and ASCII promo codes, but no backend code existed.

- **The Optimized Algorithm (Gold Standard):**

  **Discount types** (evaluated in priority order — only one applies per order unless stacking is enabled):

  | Type | Behavior |
  |------|----------|
  | `percentage` | Reduce subtotal by `value`%. Cap at 100%. |
  | `fixed_amount` | Reduce subtotal by flat PLN amount. Floor at 0. |
  | `free_item` | Zero-price one specific item SKU in the cart. |
  | `loyalty_redeem` | Deduct N points = N PLN from subtotal. |

  **Validation pipeline (server-side):**

  1. **Code exists** in `sh_promo_codes` and `is_active = true`.
  2. **Date window:** `valid_from <= NOW() <= valid_to`.
  3. **Usage limit:** `current_uses < max_uses` (global) AND customer hasn't exceeded `max_uses_per_customer`.
  4. **Minimum order:** `subtotal >= min_order_value`.
  5. **Channel restriction:** code's `allowed_channels` includes the order's channel.
  6. **Compute** discount amount. Apply `GREATEST(0, subtotal - discount)` floor.
  7. **Increment** `current_uses` atomically.

  **Loyalty point accrual (post-payment):**

  $$\text{points\_earned} = \lfloor \frac{\text{grand\_total}}{\text{points\_per\_pln\_ratio}} \rfloor$$

  Default ratio: `10` (10 PLN = 1 point). Configurable per tenant.

- **Modernization Upgrades:**
  - Full promo engine with validation pipeline replaces the void. Atomic usage counter prevents over-redemption under concurrency.
  - Loyalty is phone-number-keyed (auto-account creation, as the mockup intended) with configurable earn/burn ratios.

- **Data Blueprint:**

  ```json
  {
    "promo_code": {
      "code": "WEEKEND10",
      "type": "percentage",
      "value": 10,
      "min_order_value": "50.00",
      "max_uses": 500,
      "max_uses_per_customer": 1,
      "valid_from": "2026-04-01T00:00:00",
      "valid_to": "2026-04-30T23:59:59",
      "allowed_channels": ["POS", "Delivery", "Takeaway"]
    },
    "applied_discount": {
      "code": "WEEKEND10",
      "subtotal_before": "82.00",
      "discount_amount": "8.20",
      "subtotal_after": "73.80"
    }
  }
  ```

---

## DOMAIN: ORDERS

---

### 6. Order Number Generation

- **The Legacy Mess:** Two conflicting schemes existed. POS used `ORD/YYYYMMDD/NNN` with `COUNT(*) + 1` — a race condition that produced duplicates under concurrency. Online used `WWW/YYYYMMDD/HHmmss` — timestamp-based but not unique if two orders landed in the same second.

- **The Optimized Algorithm (Gold Standard):**

  **Canonical format:** `{PREFIX}/{YYYYMMDD}/{NNNN}`

  | Source | Prefix |
  |--------|--------|
  | POS (local) | `ORD` |
  | Online / Web | `WWW` |
  | Kiosk | `KIO` |
  | Aggregator | `AGG` |
  | Waiter | `ORD` |

  **Sequence generation:** Use a database sequence table with atomic increment:

  1. `INSERT INTO sh_order_sequences (tenant_id, date) VALUES (:tid, CURDATE()) ON DUPLICATE KEY UPDATE seq = seq + 1`
  2. `SELECT seq FROM sh_order_sequences WHERE tenant_id = :tid AND date = CURDATE()`

  This is a single atomic operation. The composite key `(tenant_id, date)` auto-resets daily. Sequence zero-padded to 4 digits (supports up to 9999 orders/day/tenant).

  **Primary key:** Orders use a server-generated UUID v4 as their `id`. The `order_number` is the human-readable label. Both have `UNIQUE` constraints.

- **Modernization Upgrades:**
  - Atomic sequence via `ON DUPLICATE KEY UPDATE seq = seq + 1` replaces the race-prone `COUNT(*) + 1`.
  - UUID v4 as primary key decouples identity from display number and enables distributed operation.

- **Data Blueprint:**

  ```json
  {
    "id": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "order_number": "ORD/20260411/0047",
    "source": "local"
  }
  ```

---

### 7. Order Lifecycle State Machine

- **The Legacy Mess:** Statuses were scattered across files with inconsistent transitions. `pos_fleet.js` used `delivered` as a status, while `api_pos.php` used `completed`. The waiter module set `new` for initial orders, but POS used `pending`. No formal guard existed — any status could be set via `update_status`.

- **The Optimized Algorithm (Gold Standard):**

  **Canonical states:** `new` → `accepted` → `preparing` → `ready` → `in_delivery` → `completed` | `cancelled`

  **Allowed transitions (strictly enforced server-side):**

  ```
  new        → accepted, cancelled
  accepted   → preparing, cancelled
  preparing  → ready, cancelled
  ready      → in_delivery (delivery only), completed (pickup/dine-in), cancelled
  in_delivery → completed, cancelled
  completed  → (terminal)
  cancelled  → (terminal)
  ```

  **Transition rules:**

  | Transition | Guard Conditions |
  |-----------|-----------------|
  | `new → accepted` | Must set `promised_time`. Triggers kitchen ticket. |
  | `accepted → preparing` | Implicit or explicit kitchen acknowledgment. |
  | `preparing → ready` | Kitchen marks done. |
  | `ready → in_delivery` | Requires `driver_id` and `course_id` to be set. |
  | `ready → completed` | For `takeaway` / `dine_in`: requires `payment_status = 'paid'`. |
  | `in_delivery → completed` | Driver confirms delivery. Auto-sets driver to `available` if no remaining active orders. |
  | `* → cancelled` | If stock was deducted, trigger stock return (§21). Records `cancelled_by`, `cancel_reason`. |

  **Every transition** inserts an audit row: `(order_id, user_id, old_status, new_status, timestamp)`.

- **Modernization Upgrades:**
  - Explicit state machine with a whitelist of transitions replaces the legacy free-form `UPDATE status = ?`. Invalid transitions are rejected with `409 Conflict`.
  - Added `accepted` and `preparing` states to give kitchen workflow proper granularity (legacy jumped `new → pending → ready` with no prep tracking).

- **Data Blueprint:**

  ```json
  {
    "action": "transition",
    "order_id": "uuid",
    "new_status": "ready",
    "metadata": {
      "transitioned_by": "user-uuid",
      "timestamp": "2026-04-11T14:35:22Z"
    }
  }
  ```

  Response on invalid transition:

  ```json
  {
    "success": false,
    "error": "INVALID_TRANSITION",
    "message": "Cannot transition from 'new' to 'ready'. Allowed: ['accepted', 'cancelled']"
  }
  ```

---

### 8. Order Edit & Kitchen Delta Detection

- **The Legacy Mess:** The diff engine used `cart_id` (a `Date.now()` value) as the stable line identity and compared JSON strings. The algorithm was correct but stored the diff as a flat pipe-separated string (`"DODANO: 2x Pepperoni | USUNIĘTO: 1x Margherita"`), losing structure. On edit, the entire old cart's stock was returned and the entire new cart's stock re-deducted — expensive for unchanged lines.

- **The Optimized Algorithm (Gold Standard):**

  **Step 1 — Identify changes** using `line_id` (UUID, assigned at line creation) as the stable identity:

  - **Added lines:** `line_id` exists in new cart but not in old.
  - **Removed lines:** `line_id` exists in old cart but not in new.
  - **Modified lines:** `line_id` exists in both, but `quantity`, `modifiers`, `removed_ingredients`, or `comment` differ.
  - **Unchanged lines:** `line_id` exists in both with identical attributes.

  **Step 2 — Generate structured delta:**

  ```
  delta = {
    added:    [ {line_id, item_sku, qty, ...} ],
    removed:  [ {line_id, item_sku, qty, ...} ],
    modified: [ {line_id, field, old_value, new_value} ],
    unchanged_count: N
  }
  ```

  **Step 3 — Selective stock adjustment** (not full return + re-deduct):

  - For **removed** lines: return their ingredients to stock.
  - For **added** lines: deduct their ingredients from stock.
  - For **modified** lines where qty changed: deduct/return only the **delta** (`new_qty - old_qty`).
  - For **unchanged** lines: no stock operation.

  **Step 4 — Set `edited_since_print = true`** if delta is non-empty. Store structured delta as JSON in `kitchen_changes`. Clear flag when kitchen ticket is reprinted.

- **Modernization Upgrades:**
  - Structured JSON delta replaces the flat pipe-separated string, enabling the KDS to render precise change highlights.
  - Selective stock adjustment (delta-only) replaces the expensive full-return-then-full-deduct cycle. For an order with 10 lines where only 1 changed, this reduces stock operations from 20 to 2.

- **Data Blueprint:**

  ```json
  {
    "kitchen_delta": {
      "added": [
        { "line_id": "uuid", "snapshot_name": "Pepperoni 32cm", "quantity": 1 }
      ],
      "removed": [
        { "line_id": "uuid", "snapshot_name": "Margherita 32cm", "quantity": 1 }
      ],
      "modified": [
        { "line_id": "uuid", "snapshot_name": "Hawaii 32cm", "field": "quantity", "old": 1, "new": 2 }
      ],
      "unchanged_count": 3,
      "generated_at": "2026-04-11T14:22:00Z"
    }
  }
  ```

---

### 9. Payment Settlement & Split Tender

- **The Legacy Mess:** Only single-method payment was supported. `payment_method` was one of `cash|card|online|unpaid`. There was no split tender, no tip field, no payment gateway. Card/online payments required a mandatory receipt print (Polish law compliance).

- **The Optimized Algorithm (Gold Standard):**

  **Payment structure:** An order has one or more **payment entries** (split tender):

  $$\sum \text{payment\_amounts} = \text{grand\_total}$$

  **Settlement flow:**

  1. Client submits `payments[]` array.
  2. Server validates: `Σ payments.amount == grand_total`. Reject if mismatch (tolerance: ±0.01 PLN for rounding).
  3. For each payment entry, apply method-specific rules:
     - `cash`: Record amount. Calculate change: `change = cash_tendered - amount_due`.
     - `card`: **Mandatory receipt flag** auto-set to `true`. No gateway call (terminal is external).
     - `online`: Must have upstream `transaction_id` from PSP webhook. Verify `payment_status` was pre-set to `paid` by webhook before allowing order acceptance.
  4. Set `order.payment_status = 'paid'`, `order.status = 'completed'` (for non-delivery).
  5. Write audit trail.

  **Tip handling (new):**

  $$\text{tip} = \text{total\_tendered} - \text{grand\_total}$$

  If `tip > 0`, store separately as `tip_amount` on the order. Tips are excluded from revenue calculations but included in driver cashbox.

- **Modernization Upgrades:**
  - Split tender support (e.g., 50 PLN cash + 28.80 PLN card) replaces the single-method lock.
  - Explicit tip tracking as a first-class field rather than being invisible in the legacy system.

- **Data Blueprint:**

  ```json
  {
    "order_id": "uuid",
    "payments": [
      { "method": "cash", "amount": "50.00", "tendered": "60.00" },
      { "method": "card", "amount": "28.80" }
    ],
    "tip_amount": "0.00",
    "print_receipt": true
  }
  ```

  Response:

  ```json
  {
    "success": true,
    "data": {
      "payment_status": "paid",
      "change_due": "10.00",
      "receipt_printed": true
    }
  }
  ```

---

## DOMAIN: KITCHEN

---

### 10. KDS Ticket Routing Engine

- **The Legacy Mess:** No item-level station routing existed. The entire order was sent as a single ticket to the kitchen. The v2 schema added `kds_station_id` to `sh_menu_items` but it was never wired to any logic.

- **The Optimized Algorithm (Gold Standard):**

  **Step 1 — On order acceptance**, split order lines by their `kds_station_id`:

  ```
  stations = GROUP_BY(order.lines, line.item.kds_station_id)
  ```

  **Step 2 — Generate one KDS ticket per station:**

  Each ticket contains:
  - The **order number** and **order type** (header context).
  - Only the **lines routed to that station**.
  - Modifier and removal annotations per line.
  - The `promised_time` as the preparation deadline.

  **Step 3 — Station-level status tracking:**

  Each station ticket has its own status: `pending → preparing → done`.

  **Step 4 — Order readiness rule:**

  $$\text{order.status} = \text{'ready'} \iff \forall\ \text{station tickets: status} = \text{'done'}$$

  When the **last** station marks its ticket as `done`, the order auto-transitions to `ready`.

  **Fallback:** If an item has no `kds_station_id`, route it to a configurable default station (e.g., `KITCHEN_MAIN`).

- **Modernization Upgrades:**
  - Item-level station routing (Bar, Pizza Oven, Grill, Cold) replaces the monolithic single-ticket model.
  - Per-station status tracking enables the kitchen manager to see which station is the bottleneck for a given order.

- **Data Blueprint:**

  ```json
  {
    "kds_tickets": [
      {
        "station_id": "pizza_oven",
        "station_name": "Piec",
        "order_number": "ORD/20260411/0047",
        "promised_time": "2026-04-11T18:30:00",
        "status": "pending",
        "lines": [
          {
            "snapshot_name": "½ Capricciosa + ½ Hawaii",
            "quantity": 1,
            "modifiers_added": ["Extra Ser"],
            "ingredients_removed": ["Ananas"],
            "comment": "Well done"
          }
        ]
      },
      {
        "station_id": "bar",
        "station_name": "Bar",
        "status": "done",
        "lines": [
          { "snapshot_name": "Cola 0.5L", "quantity": 2 }
        ]
      }
    ]
  }
  ```

---

### 11. Panic Mode (Global Delay)

- **The Legacy Mess:** A single button added `+20 minutes` to all `pending` and `ready` orders. The delay amount was hardcoded. No record was kept of when or why panic was activated.

- **The Optimized Algorithm (Gold Standard):**

  **Step 1 — Operator triggers panic** with a configurable delay (default: 20 min, range: 5–60):

  $$\text{new\_promised\_time} = \text{COALESCE(promised\_time, created\_at)} + \text{delay\_minutes}$$

  Applied to all orders with `status IN ('accepted', 'preparing', 'ready')` for the tenant.

  **Step 2 — Log panic event:** Insert into `sh_panic_log (tenant_id, triggered_by, delay_minutes, affected_count, triggered_at)`.

  **Step 3 — Notify** all connected clients (POS, KDS, Driver apps) via real-time channel that promised times have shifted.

- **Modernization Upgrades:**
  - Configurable delay replaces the hardcoded 20 minutes.
  - Audit trail (who triggered, when, how many orders affected) replaces the fire-and-forget approach.

- **Data Blueprint:**

  ```json
  {
    "action": "panic_mode",
    "delay_minutes": 20
  }
  ```

  Response:

  ```json
  {
    "success": true,
    "data": {
      "affected_orders": 12,
      "delay_applied_minutes": 20,
      "triggered_at": "2026-04-11T19:05:00Z"
    }
  }
  ```

---

## DOMAIN: GATEWAY (Online Ordering)

---

### 12. Online Order Intake & Validation

- **The Legacy Mess:** Validation was almost non-existent: only checked for non-empty cart and phone number. No minimum order value, no delivery zone check, no business hours validation, no price re-verification. The `tenant_id` was hardcoded to `1`. The `requested_time` field was **interpolated directly into SQL** — a textbook SQL injection vulnerability.

- **The Optimized Algorithm (Gold Standard):**

  **Validation pipeline (server-side, executed in order — fail fast):**

  | # | Check | Rejection Reason |
  |---|-------|-----------------|
  | 1 | Tenant is valid and active | `TENANT_INACTIVE` |
  | 2 | Cart is non-empty | `EMPTY_CART` |
  | 3 | All `item_sku`s exist, are active, and `publication_status = 'Live'` | `ITEM_UNAVAILABLE` |
  | 4 | **Price re-verification**: Server recalculates total from `sh_price_tiers` for the `Delivery`/`Takeaway` channel. Must match client total ±0.01 PLN or server total is used. | `PRICE_MISMATCH` (warning, not rejection) |
  | 5 | `customer_phone` matches E.164 format (Polish: `+48XXXXXXXXX`) | `INVALID_PHONE` |
  | 6 | For `delivery`: `customer_address` is non-empty | `MISSING_ADDRESS` |
  | 7 | **Minimum order value**: `grand_total >= tenant.min_order_value` | `BELOW_MINIMUM` |
  | 8 | **Business hours**: Current time falls within `sh_tenant_settings.opening_hours` for the selected day of week | `STORE_CLOSED` |
  | 9 | **Requested time**: If not ASAP, must be ≥ `NOW() + min_prep_time` and ≤ closing time. **Parameterized** (never interpolated into SQL). | `INVALID_TIME` |
  | 10 | **Delivery zone** (if enabled): Address geocoded → check `ST_Contains(zone_polygon, point)` or simple radius check | `OUT_OF_ZONE` |

- **Modernization Upgrades:**
  - Full 10-step validation pipeline replaces the 2-field check. Every rejection returns a machine-readable error code.
  - All time parameters are **parameterized** in prepared statements — the SQL injection vulnerability is eliminated by design.

- **Data Blueprint:**

  Rejection response:

  ```json
  {
    "success": false,
    "error": "BELOW_MINIMUM",
    "message": "Minimum order value is 40.00 PLN. Your cart total is 35.00 PLN.",
    "data": {
      "min_order_value": "40.00",
      "cart_total": "35.00"
    }
  }
  ```

---

### 13. Promised Time Engine

- **The Legacy Mess:** Three conflicting approaches existed: (a) Online store offered static dropdown slots (12:00, 14:00, 18:00, 20:00); (b) Waiter module used a relative offset of 15/30/45/60 minutes from now; (c) POS accepted a free-form datetime. For ASAP, the server just stored `NOW()` — no actual estimation.

- **The Optimized Algorithm (Gold Standard):**

  **For scheduled orders** (customer picks a specific time):

  1. Validate: `requested_time >= NOW() + min_lead_time` (configurable, e.g., 30 min).
  2. Validate: `requested_time` falls within business hours.
  3. Store as `promised_time`.

  **For ASAP orders:**

  $$\text{promised\_time} = \text{NOW()} + \text{base\_prep\_time} + \text{channel\_buffer}$$

  | Factor | Source | Default |
  |--------|--------|---------|
  | `base_prep_time` | `sh_tenant_settings.base_prep_minutes` | 25 min |
  | `channel_buffer` | Per-channel: `dine_in = 0`, `takeaway = 5`, `delivery = 15` | varies |

  **Dynamic load adjustment (optional, phase 2):**

  $$\text{load\_factor} = \min(2.0,\ 1.0 + \frac{\text{active\_pending\_orders}}{20})$$

  $$\text{adjusted\_time} = \text{base\_prep\_time} \times \text{load\_factor} + \text{channel\_buffer}$$

  This linearly scales prep time as the queue grows, capping at 2x the base.

- **Modernization Upgrades:**
  - Dynamic ASAP estimation based on kitchen load replaces the static `NOW()` placeholder.
  - Unified model serves all channels (POS, waiter, online) instead of three separate approaches.

- **Data Blueprint:**

  ```json
  {
    "time_estimate": {
      "mode": "asap",
      "base_prep_minutes": 25,
      "channel_buffer_minutes": 15,
      "load_factor": 1.3,
      "active_orders": 6,
      "estimated_minutes": 47,
      "promised_time": "2026-04-11T19:17:00Z"
    }
  }
  ```

---

## DOMAIN: LOGISTICS (Battlefield)

---

### 14. Dispatch & Route Assignment (K/L System)

- **The Legacy Mess:** Two files implemented `assign_route` differently. `api_delivery.php` did a simple bulk `UPDATE` without course numbering. `api_pos.php` implemented the K/L system (`K1`, `K2`... for courses; `L1`, `L2`... for stops) using `COUNT(DISTINCT course_id) + 1` — again with a race condition on concurrent dispatches.

- **The Optimized Algorithm (Gold Standard):**

  **Step 1 — Validate dispatch request:**
  - Driver must exist and have `status = 'available'`.
  - All order IDs must have `status = 'ready'` and `type = 'delivery'`.
  - All orders must belong to the same `tenant_id`.

  **Step 2 — Generate course ID** using the same atomic sequence pattern as order numbers:

  `INSERT INTO sh_course_sequences (tenant_id, date) VALUES (:tid, CURDATE()) ON DUPLICATE KEY UPDATE seq = seq + 1`

  Course ID format: `K{seq}` (e.g., `K1`, `K2`, `K14`).

  **Step 3 — Assign stop numbers** based on the order of `order_ids` in the request array:

  For each `order_ids[i]`: `stop_number = 'L' + (i + 1)` → `L1`, `L2`, `L3`...

  **Step 4 — Atomic batch update:**

  For each order: set `status = 'in_delivery'`, `driver_id`, `course_id`, `stop_number`.
  Set `driver.status = 'busy'`.

  **Step 5 — Audit:** Log dispatch event with `course_id`, `driver_id`, `order_ids[]`, `dispatched_by`, `dispatched_at`.

- **Modernization Upgrades:**
  - Atomic course sequence (`ON DUPLICATE KEY UPDATE`) replaces the race-prone `COUNT(DISTINCT) + 1`.
  - Validation guards (driver availability, order readiness) prevent invalid dispatches that the legacy system allowed silently.

- **Data Blueprint:**

  Request:

  ```json
  {
    "driver_id": "user-uuid",
    "order_ids": ["order-uuid-1", "order-uuid-2", "order-uuid-3"]
  }
  ```

  Response:

  ```json
  {
    "success": true,
    "data": {
      "course_id": "K7",
      "stops": [
        { "order_id": "order-uuid-1", "stop": "L1", "address": "ul. Kwiatowa 5" },
        { "order_id": "order-uuid-2", "stop": "L2", "address": "ul. Polna 12" },
        { "order_id": "order-uuid-3", "stop": "L3", "address": "ul. Leśna 3" }
      ],
      "driver_status": "busy"
    }
  }
  ```

---

### 15. Driver Cashbox & End-of-Shift Reconciliation

- **The Legacy Mess:** Three overlapping cashbox calculations existed: (a) `api_delivery.php` summed `total_price` for cash+completed orders per `sh_drivers.id`; (b) `api_driver.php` ran the same query but keyed on `sh_users.id` — a **mismatch** that could produce wrong values; (c) `pos_active_routes.js` categorized per-route amounts into `paidTotal`, `cardToCollect`, `cashToCollect` with a heuristic (unpaid + not card = cash). Per-drop commissions, fuel allowances, and formal reconciliation were absent.

- **The Optimized Algorithm (Gold Standard):**

  **All driver references use `sh_users.id`** (the canonical user identifier). The legacy `sh_drivers.id` indirection is eliminated.

  **Per-route wallet** (computed for each `course_id` while driver is active):

  $$\text{cash\_to\_collect} = \sum \text{total\_price} \quad \text{WHERE payment\_status = 'unpaid' AND payment\_method IN ('cash', 'unpaid')}$$

  $$\text{card\_to\_collect} = \sum \text{total\_price} \quad \text{WHERE payment\_status = 'unpaid' AND payment\_method = 'card'}$$

  $$\text{pre\_paid} = \sum \text{total\_price} \quad \text{WHERE payment\_status = 'paid'}$$

  **End-of-shift reconciliation:**

  $$\text{total\_cash\_collected} = \sum \text{total\_price for all completed cash orders today}$$

  $$\text{expected\_cash\_in\_hand} = \text{initial\_cash} + \text{total\_cash\_collected} + \text{tips\_cash}$$

  $$\text{variance} = \text{counted\_cash} - \text{expected\_cash\_in\_hand}$$

  Where `counted_cash` is the physical amount the driver declares upon return.

  A variance beyond a configurable threshold (e.g., ±5.00 PLN) triggers a flag for manager review.

- **Modernization Upgrades:**
  - Unified driver key (`user_id` everywhere) fixes the legacy ID mismatch bug.
  - Formal reconciliation with `counted_cash` vs `expected_cash` and variance tracking replaces the one-directional "collected cash" display.

- **Data Blueprint:**

  End-of-shift reconciliation request:

  ```json
  {
    "driver_user_id": "user-uuid",
    "counted_cash": "185.00"
  }
  ```

  Response:

  ```json
  {
    "success": true,
    "data": {
      "initial_cash": "50.00",
      "cash_orders_total": "142.50",
      "cash_tips": "5.00",
      "expected_cash": "197.50",
      "counted_cash": "185.00",
      "variance": "-12.50",
      "variance_flag": "REVIEW_REQUIRED",
      "completed_deliveries": 8,
      "courses_completed": ["K3", "K5", "K9"]
    }
  }
  ```

---

### 16. Delivery SLA Monitor

- **The Legacy Mess:** A simple client-side calculation: `diffMin = floor((promised_time - now) / 60000)`. Negative = late (red), 0–5 = warning (yellow), else green. No server-side SLA tracking or alerting.

- **The Optimized Algorithm (Gold Standard):**

  $$\text{time\_remaining\_min} = \lfloor \frac{\text{promised\_time} - \text{NOW()}}{60} \rfloor$$

  **SLA tiers (configurable per tenant):**

  | Condition | Tier | Visual | Action |
  |-----------|------|--------|--------|
  | `time_remaining > sla_green_threshold` (default: 10 min) | `ON_TRACK` | Green | None |
  | `sla_yellow_threshold < time_remaining ≤ sla_green_threshold` (default: 5 min) | `AT_RISK` | Yellow | Highlight in dispatch view |
  | `0 < time_remaining ≤ sla_yellow_threshold` | `CRITICAL` | Orange | Push alert to dispatcher |
  | `time_remaining ≤ 0` | `BREACHED` | Red, pulsing | Log breach, alert manager |

  **SLA breach logging:** When an order enters `BREACHED`, record `(order_id, breach_minutes, driver_id, course_id)` in `sh_sla_breaches`. This feeds into analytics dashboards and driver performance reports.

- **Modernization Upgrades:**
  - Server-side SLA computation and breach logging replace the display-only client-side color coding.
  - Configurable thresholds per tenant instead of the hardcoded 5-minute yellow cutoff.

- **Data Blueprint:**

  ```json
  {
    "order_id": "uuid",
    "promised_time": "2026-04-11T18:30:00Z",
    "current_time": "2026-04-11T18:27:00Z",
    "time_remaining_min": 3,
    "sla_tier": "CRITICAL",
    "driver_id": "user-uuid",
    "course_id": "K5"
  }
  ```

---

### 17. Driver Status State Machine

- **The Legacy Mess:** `api_auth.php` auto-registered every user as a driver on clock-in (even cooks and waiters). `api_driver.php` set `available` on `completed` and `busy` on `in_delivery`. There was no `returning` state even though `pos_fleet.js` tried to render one by checking for `status === 'delivered'` (a status that didn't exist in the backend).

- **The Optimized Algorithm (Gold Standard):**

  **States:** `offline` → `available` → `busy` → `returning` → `available` | `offline`

  **Transitions:**

  | Trigger | From | To | Condition |
  |---------|------|----|-----------|
  | Clock in | `offline` | `available` | User has `role = 'driver'` (not auto-register everyone) |
  | Route assigned | `available` | `busy` | Dispatch sets `course_id` on orders |
  | All stops delivered | `busy` | `returning` | All orders in active course are `completed` |
  | Arrived at base | `returning` | `available` | Driver confirms return (or auto after N min) |
  | Clock out | `available` / `returning` | `offline` | Only if no active undelivered orders |
  | Clock out (force) | `busy` | `offline` | Manager override only. Undelivered orders reassigned. |

- **Modernization Upgrades:**
  - `returning` state (driver heading back to base) is now a real status, resolving the legacy `pos_fleet.js` rendering hack.
  - Clock-in only registers users with `role = 'driver'` as drivers, preventing the legacy bug where cooks appeared in the fleet view.

- **Data Blueprint:**

  ```json
  {
    "driver_user_id": "user-uuid",
    "status": "returning",
    "active_course": "K5",
    "completed_stops": 3,
    "total_stops": 3,
    "initial_cash": "50.00",
    "cash_collected": "87.50"
  }
  ```

---

## DOMAIN: WAREHOUSE

---

### 18. Recipe-Based Stock Consumption (WZ Engine)

- **The Legacy Mess:** Two conflicting versions existed. The older `api_magazyn_pro.php` used `req_qty = recipe.quantity * sold_qty` **without** the waste factor. The newer `api_pos.php` included it: `needed = recipe.quantity * (1 + waste_percent/100) * multiplier`. For modifier stock deduction, `api_pos.php` used hardcoded heuristics (`1.0` per piece, `0.05` for kg/litr) instead of the configured `linked_quantity` from `sh_modifiers`. Removal matching was by product name in the older version (fragile) vs. by product ID in the newer version (correct).

- **The Optimized Algorithm (Gold Standard):**

  **Per order line, execute the Recipe Engine:**

  **Step 1 — Determine recipe source(s):**

  | Item Type | Recipe Source | Multiplier |
  |-----------|-------------|-----------|
  | Standard | Recipe for `item_sku` | `1.0 × line_qty` |
  | Half-half (half A) | Recipe for `half_a_sku` | `0.5 × line_qty` |
  | Half-half (half B) | Recipe for `half_b_sku` | `0.5 × line_qty` |

  **Step 2 — For each recipe ingredient:**

  Skip if `ingredient.warehouse_sku ∈ removed_ingredient_skus`.

  Otherwise:

  $$\text{deduct\_qty} = \text{recipe\_quantity} \times (1 + \frac{\text{waste\_percent}}{100}) \times \text{multiplier}$$

  **Step 3 — For each added modifier:**

  Read from `sh_modifiers`: `linked_warehouse_sku`, `linked_quantity`, `linked_waste_percent`.

  $$\text{deduct\_qty} = \text{linked\_quantity} \times (1 + \frac{\text{linked\_waste\_percent}}{100}) \times \text{line\_qty}$$

  **Step 4 — Aggregate** deductions by `(warehouse_id, warehouse_sku)`:

  $$\text{total\_deduct}[sku] = \sum \text{deduct\_qty from all lines}$$

  **Step 5 — Execute stock update** within a transaction:

  ```
  UPDATE wh_stock SET quantity = quantity - :deduct WHERE warehouse_id = :wh AND sku = :sku
  ```

  If `rowcount = 0` (no existing stock row), insert with **negative quantity** and log an `ALERT_86` event.

  **Step 6 — Generate WZ document** with all deducted items for audit.

  **Step 7 — Log** each deduction to `wh_stock_logs` with `document_type = 'POS_SALE'`.

- **Modernization Upgrades:**
  - Waste factor is **always applied** (fixes the legacy version that omitted it). All recipes use the unified formula.
  - Modifier stock deduction uses the configured `linked_quantity` and `linked_waste_percent` from `sh_modifiers` instead of hardcoded heuristics (`1.0`/`0.05`).
  - Ingredient removal matching uses `warehouse_sku` (immutable key), not product name strings.

- **Data Blueprint:**

  ```json
  {
    "wz_document": {
      "doc_number": "WZ/2026/04/11/00023",
      "warehouse_id": "kitchen-uuid",
      "order_id": "order-uuid",
      "lines": [
        {
          "warehouse_sku": "mozzarella_kg",
          "quantity_deducted": 0.252,
          "unit": "kg",
          "source": "recipe",
          "recipe_quantity": 0.120,
          "waste_percent": 5.0,
          "multiplier": 2.0
        },
        {
          "warehouse_sku": "jalapeno_kg",
          "quantity_deducted": 0.168,
          "unit": "kg",
          "source": "modifier",
          "linked_quantity": 0.080,
          "waste_percent": 5.0,
          "line_qty": 2
        }
      ],
      "created_by": "user-uuid",
      "created_at": "2026-04-11T14:35:22Z"
    }
  }
  ```

---

### 19. Goods Receipt & AVCO Valuation (PZ Engine)

- **The Legacy Mess:** Two versions. The older `api_inventory.php` used `ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)` — correct for stock quantity but no cost tracking. The newer `api_warehouse.php` introduced AVCO (Weighted Average Cost), but only on the newer `wh_stock` table. The legacy `sh_stock_levels` table had no cost fields.

- **The Optimized Algorithm (Gold Standard):**

  **Per PZ line item:**

  **Step 1 — Resolve internal SKU** from supplier item name using `sh_product_mapping` (case-insensitive match on `external_name`). If no mapping, reject the line and flag for manual resolution.

  **Step 2 — Read current stock state:**

  $$old\_qty = \text{wh\_stock.quantity} \quad (0 \text{ if no row})$$
  $$old\_avco = \text{wh\_stock.current\_avco\_price} \quad (0 \text{ if no row})$$

  **Step 3 — Compute new AVCO:**

  $$\text{unit\_net\_cost} = \frac{\text{line\_net\_value}}{\text{received\_qty}}$$

  $$\text{new\_avco} = \begin{cases} \text{unit\_net\_cost} & \text{if } old\_qty \leq 0 \\ \frac{(old\_qty \times old\_avco) + (received\_qty \times unit\_net\_cost)}{old\_qty + received\_qty} & \text{otherwise} \end{cases}$$

  **Step 4 — Upsert stock:**

  $$new\_qty = old\_qty + received\_qty$$

  ```
  INSERT INTO wh_stock (warehouse_id, sku, quantity, current_avco_price)
  VALUES (:wh, :sku, :qty, :avco)
  ON DUPLICATE KEY UPDATE
    quantity = quantity + :qty,
    current_avco_price = :new_avco
  ```

  **Step 5 — Create PZ document** with all lines, supplier info, and per-line `unit_net_cost`.

  **Step 6 — Log** to `wh_stock_logs` with `document_type = 'PZ'`.

- **Modernization Upgrades:**
  - AVCO is always computed (the v2 formula is used), enabling real food cost calculations. The legacy quantity-only approach is retired.
  - Supplier item → internal SKU mapping is enforced at receipt time, not deferred to a separate settings page.

- **Data Blueprint:**

  ```json
  {
    "pz_document": {
      "doc_number": "PZ/2026/04/11/00005",
      "warehouse_id": "main-uuid",
      "supplier_name": "MAKRO",
      "supplier_invoice": "FV/2026/04/1234",
      "lines": [
        {
          "external_name": "SER MOZZARELLA 2.5KG",
          "resolved_sku": "mozzarella_kg",
          "quantity": 5.0,
          "unit": "kg",
          "unit_net_cost": "22.50",
          "line_net_value": "112.50",
          "vat_rate": "5.00",
          "old_avco": "21.80",
          "new_avco": "22.04"
        }
      ]
    }
  }
  ```

---

### 20. Inter-Warehouse Transfer (MM)

- **The Legacy Mess:** Worked correctly but with hardcoded warehouse IDs (Main = 1, Kitchen = 2) and no AVCO carry-over.

- **The Optimized Algorithm (Gold Standard):**

  **Step 1 — Validate:** `source_warehouse ≠ target_warehouse`, `quantity > 0`, both warehouses exist for the tenant.

  **Step 2 — Check source stock:** `wh_stock.quantity >= requested_qty` for the source warehouse. **Reject** if insufficient (unlike legacy which allowed silent negatives).

  **Step 3 — Transfer:**

  $$\text{source.quantity} = \text{source.quantity} - \text{transfer\_qty}$$
  $$\text{target.quantity} = \text{target.quantity} + \text{transfer\_qty}$$

  **Step 4 — AVCO carry-over:** The target warehouse receives stock at the source warehouse's current AVCO price. Recompute target AVCO using the standard weighted average formula (same as PZ, §19).

  **Step 5 — Generate MM document** and log to `wh_stock_logs` with `document_type = 'MM'`.

- **Modernization Upgrades:**
  - Source stock sufficiency check prevents silent negative quantities.
  - AVCO carries over during transfer, maintaining accurate cost tracking across warehouses.

- **Data Blueprint:**

  ```json
  {
    "mm_document": {
      "doc_number": "MM/2026/04/11/00003",
      "source_warehouse_id": "main-uuid",
      "target_warehouse_id": "kitchen-uuid",
      "lines": [
        {
          "sku": "mozzarella_kg",
          "quantity": 2.5,
          "unit": "kg",
          "avco_at_transfer": "22.04"
        }
      ]
    }
  }
  ```

---

### 21. Void / Correction (KOR)

- **The Legacy Mess:** Two approaches: `api_magazyn_pro.php` created a `KOR` document referencing the original `WZ` and returned stock. `api_pos.php`'s `cancel_order` re-ran the recipe engine using the **current** recipe — if a recipe had been modified since the sale, the return quantities would be **wrong**.

- **The Optimized Algorithm (Gold Standard):**

  **Use the WZ document as the source of truth** — never re-derive from recipes.

  **Step 1 — Find the WZ document** linked to the order being voided.

  **Step 2 — For each line in the WZ document**, return the **exact quantities that were originally deducted:**

  $$\text{stock.quantity} = \text{stock.quantity} + \text{wz\_line.quantity\_deducted}$$

  **Step 3 — Generate KOR document** with number format `KOR/{original_wz_number}`.

  **Step 4 — Update AVCO on return:** Use the AVCO that was current at the time of the original WZ (stored in `wh_stock_logs` for the original deduction). If not available, use current AVCO.

  **Step 5 — Log** to `wh_stock_logs` with `document_type = 'KOR'`.

- **Modernization Upgrades:**
  - Returns are **document-based** (exact reversal of the original WZ), not re-derived from potentially-changed recipes. This eliminates the critical legacy bug where recipe changes corrupted return quantities.
  - AVCO restoration uses historical cost data to maintain valuation accuracy.

- **Data Blueprint:**

  ```json
  {
    "kor_document": {
      "doc_number": "KOR/WZ/2026/04/11/00023",
      "references_wz": "WZ/2026/04/11/00023",
      "order_id": "order-uuid",
      "reason": "customer_cancelled",
      "lines": [
        { "sku": "mozzarella_kg", "quantity_returned": 0.252, "avco_at_original": "22.04" }
      ]
    }
  }
  ```

---

### 22. Physical Inventory Count (INW)

- **The Legacy Mess:** `api_ekipa.php` had a basic "set quantity to counted value" loop over `sh_products`. It computed `change = new_val - old_val` and logged it. It also auto-completed a "Magazyn Revision" gamification mission. No double-count verification, no blind-count mode, no approval workflow.

- **The Optimized Algorithm (Gold Standard):**

  **Step 1 — Create INW document** with status `in_progress`.

  **Step 2 — Blind count (optional, configurable):** The system hides the current system quantity from the counter. Counter enters only what they physically see.

  **Step 3 — For each SKU counted:**

  $$\text{variance} = \text{counted\_qty} - \text{system\_qty}$$

  **Step 4 — Apply variance rules:**

  | Variance | Action |
  |----------|--------|
  | `|variance| ≤ tolerance` (configurable, e.g., 2%) | Auto-approve |
  | `tolerance < |variance| ≤ critical_threshold` | Require manager approval |
  | `|variance| > critical_threshold` (e.g., 10%) | Require owner PIN + reason |

  **Step 5 — On approval,** update stock:

  $$\text{wh\_stock.quantity} = \text{counted\_qty}$$

  Generate compensating document: `RW` if shortage (internal loss), `PW` if surplus (production gain).

  **Step 6 — Log** all variances to `wh_stock_logs` with `document_type = 'INW'`.

- **Modernization Upgrades:**
  - Blind count mode and variance thresholds with approval workflow replace the unguarded direct overwrite.
  - Automatic RW/PW document generation for variances creates a complete audit trail.

- **Data Blueprint:**

  ```json
  {
    "inw_document": {
      "doc_number": "INW/2026/04/11/00001",
      "warehouse_id": "kitchen-uuid",
      "status": "pending_approval",
      "counted_by": "user-uuid",
      "lines": [
        {
          "sku": "mozzarella_kg",
          "system_qty": 12.500,
          "counted_qty": 11.800,
          "variance": -0.700,
          "variance_pct": -5.6,
          "approval_required": true,
          "cost_impact": "-15.43"
        }
      ]
    }
  }
  ```

---

### 23. Food Cost & Margin Calculation

- **The Legacy Mess:** **Never implemented.** `waste_percent` was stored in recipes and the AVCO was computed on goods receipt, but no code ever combined them to calculate actual food cost or margin percentage.

- **The Optimized Algorithm (Gold Standard):**

  **Per menu item, compute theoretical food cost:**

  **Step 1 — For each ingredient in the recipe:**

  $$\text{ingredient\_cost} = \text{recipe\_qty} \times (1 + \frac{\text{waste\%}}{100}) \times \text{AVCO\_per\_unit}$$

  **Step 2 — Sum base recipe cost:**

  $$\text{base\_food\_cost} = \sum_{i} \text{ingredient\_cost}_i$$

  **Step 3 — Add modifier costs** (for default/popular modifier combinations, or compute on demand):

  $$\text{modifier\_food\_cost} = \text{linked\_qty} \times (1 + \frac{\text{linked\_waste\%}}{100}) \times \text{AVCO}$$

  **Step 4 — Compute margin for each channel:**

  $$\text{food\_cost\%} = \frac{\text{base\_food\_cost}}{\text{selling\_price}} \times 100$$

  $$\text{margin\%} = 100 - \text{food\_cost\%}$$

  **Step 5 — Alert thresholds:**

  | Food Cost % | Status |
  |------------|--------|
  | `≤ 25%` | Excellent (green) |
  | `25% – 33%` | Healthy (yellow) |
  | `33% – 40%` | At risk (orange) |
  | `> 40%` | Critical — review pricing or recipe (red) |

- **Modernization Upgrades:**
  - This is an entirely new capability that didn't exist in legacy. It connects the recipe engine (§18) with the AVCO valuation engine (§19) to produce actionable margin data.
  - Per-channel margin analysis enables different pricing strategies for POS vs Delivery.

- **Data Blueprint:**

  ```json
  {
    "item_sku": "margherita_32",
    "food_cost_analysis": {
      "recipe_cost": "8.42",
      "waste_cost": "0.51",
      "total_food_cost": "8.93",
      "channels": [
        { "channel": "POS", "price": "32.00", "food_cost_pct": 27.9, "margin_pct": 72.1, "status": "healthy" },
        { "channel": "Delivery", "price": "35.00", "food_cost_pct": 25.5, "margin_pct": 74.5, "status": "healthy" }
      ],
      "last_avco_update": "2026-04-10T09:15:00Z"
    }
  }
  ```

---

## DOMAIN: STAFF

---

### 24. Clock In/Out & Work Sessions

- **The Legacy Mess:** `api_auth.php` auto-registered **every user** (including cooks and waiters) into `sh_drivers` on clock-in. The clock-out formula stored `total_time = TIMESTAMPDIFF(MINUTE, ...) / 60.0` as a float — precision loss on long shifts. No guard against double clock-in (user could have multiple open sessions).

- **The Optimized Algorithm (Gold Standard):**

  **Clock In:**

  1. Check: user has no open session (`end_time IS NULL`). If one exists, **reject** with `ALREADY_CLOCKED_IN`.
  2. Insert `work_session (tenant_id, user_id, start_time = NOW())`.
  3. If user has `role = 'driver'`, update `sh_drivers.status = 'available'`. Do **not** create driver records for non-drivers.
  4. Update `sh_users.last_seen = NOW()`.

  **Clock Out:**

  1. Find open session: `WHERE user_id = :uid AND end_time IS NULL`.
  2. If user is a driver with `status = 'busy'`, **reject** with `ACTIVE_DELIVERIES`. Must complete or reassign orders first.
  3. Compute duration:

  $$\text{total\_hours} = \text{ROUND}(\frac{\text{TIMESTAMPDIFF(SECOND, start\_time, NOW())}}{3600.0},\ 4)$$

  Using seconds (not minutes) for precision, stored to 4 decimal places.

  4. Update session: `end_time = NOW(), total_hours = :computed`.
  5. If driver: set `sh_drivers.status = 'offline'`.

- **Modernization Upgrades:**
  - Duplicate session guard prevents the "double clock-in" problem.
  - Second-level precision (not minute-level) eliminates rounding errors on long shifts.
  - Drivers with active deliveries cannot clock out until orders are resolved.

- **Data Blueprint:**

  ```json
  {
    "action": "clock_in",
    "user_id": "user-uuid",
    "result": {
      "session_id": "session-uuid",
      "start_time": "2026-04-11T08:00:00Z",
      "driver_status_set": "available"
    }
  }
  ```

---

### 25. Payroll Calculation Engine

- **The Legacy Mess:** Two versions. The older `stare pliki/api_ekipa.php` had a comprehensive model (MTD hours + active shift + deductions + previous month comparison using day-of-month capping). The newer `api_ekipa.php` simplified it but **lost** the active shift inclusion and previous month comparison.

- **The Optimized Algorithm (Gold Standard):**

  **Input:** `user_id`, `period_type` (week|month|year), `period_offset` (0 = current, 1 = previous, etc.)

  **Step 1 — Determine period boundaries:**

  | Type | Start | End |
  |------|-------|-----|
  | `week` | Monday 00:00:00 of target week | Sunday 23:59:59 |
  | `month` | 1st of target month 00:00:00 | Last day 23:59:59 |
  | `year` | Jan 1 00:00:00 | Dec 31 23:59:59 |

  **Step 2 — Sum closed sessions:**

  $$\text{closed\_hours} = \sum \text{total\_hours} \quad \text{WHERE start\_time IN period AND end\_time IS NOT NULL}$$

  **Step 3 — Add active session** (if currently clocked in and session started within period):

  $$\text{active\_hours} = \frac{\text{TIMESTAMPDIFF(SECOND, start\_time, NOW())}}{3600.0}$$

  $$\text{total\_hours} = \text{closed\_hours} + \text{active\_hours}$$

  **Step 4 — Compute payroll:**

  $$\text{gross\_pay} = \text{total\_hours} \times \text{hourly\_rate}$$

  $$\text{deductions} = \sum \text{amount} \quad \text{FROM sh\_deductions WHERE user\_id AND created\_at IN period}$$

  $$\text{meal\_charges} = \sum \text{employee\_price} \quad \text{FROM sh\_meals WHERE user\_id AND created\_at IN period}$$

  $$\text{net\_pay} = \text{gross\_pay} - \text{deductions} - \text{meal\_charges}$$

  **Step 5 — Comparison** (optional, for period_offset = 0):

  Compute the equivalent point in the previous period (e.g., same day-of-month, same time-of-day, capped to month length) and return both current and previous period data for delta display.

- **Modernization Upgrades:**
  - Unified engine serves all period types (the older version had separate code paths). Active shift is **always** included (the newer version's regression is fixed).
  - Meal charges are added as a deduction category (the older version had no meal tracking; the boss dashboard JS had it but the employee profile didn't).

- **Data Blueprint:**

  ```json
  {
    "payroll": {
      "period": { "type": "month", "start": "2026-04-01", "end": "2026-04-30", "label": "Kwiecień 2026" },
      "hours": { "closed": 142.5, "active": 3.25, "total": 145.75 },
      "hourly_rate": "25.00",
      "gross_pay": "3643.75",
      "deductions": [
        { "type": "advance", "total": "200.00" },
        { "type": "meal", "total": "45.00" }
      ],
      "total_deductions": "245.00",
      "net_pay": "3398.75",
      "comparison": {
        "prev_period_label": "Marzec 2026 (do 11.04 15:30)",
        "prev_hours": 138.2,
        "prev_net": "3210.00",
        "delta_hours": "+7.55",
        "delta_net": "+188.75"
      }
    }
  }
  ```

---

### 26. Boss Dashboard — Team Aggregate Payroll

- **The Legacy Mess:** Client-side JavaScript aggregated stats per employee: `final_payout = gross_earned - deductions - meal_charges`, with `max(0, final_payout)` clamping negative values. Active shift cost was computed separately as `elapsed_minutes / 60 * hourly_rate`. The server-side endpoint that produced `gross_earned` was missing from the repository.

- **The Optimized Algorithm (Gold Standard):**

  **Server-side aggregation** (not client-side):

  **Per employee in the period:**

  $$\text{gross} = \text{total\_hours} \times \text{hourly\_rate}$$

  $$\text{net} = \text{gross} - \text{deductions} - \text{meals}$$

  $$\text{payout} = \max(0,\ \text{net})$$

  $$\text{carry\_forward} = \min(0,\ \text{net}) \quad \text{(negative balance carried to next period)}$$

  **Team totals:**

  $$\text{total\_labor\_cost} = \sum_{i} \text{gross}_i$$

  $$\text{total\_payout} = \sum_{i} \text{payout}_i$$

  **Real-time shift burn rate:**

  $$\text{current\_burn\_rate\_per\_hour} = \sum_{active} \text{hourly\_rate}_i$$

  $$\text{shift\_cost\_so\_far} = \sum_{active} \frac{\text{elapsed\_seconds}_i}{3600} \times \text{hourly\_rate}_i$$

- **Modernization Upgrades:**
  - All computation is server-side (the legacy client-side aggregation was vulnerable to data manipulation and inconsistency).
  - Negative balance carry-forward replaces the clamping that silently absorbed over-advances.

- **Data Blueprint:**

  ```json
  {
    "team_payroll": {
      "period": "2026-04",
      "employees": [
        {
          "user_id": "uuid",
          "name": "Jan Kowalski",
          "hours": 145.75,
          "rate": "25.00",
          "gross": "3643.75",
          "deductions": "200.00",
          "meals": "45.00",
          "net": "3398.75",
          "payout": "3398.75",
          "carry_forward": "0.00"
        }
      ],
      "totals": {
        "total_hours": 580.5,
        "total_labor_cost": "14512.50",
        "total_deductions": "800.00",
        "total_payout": "13112.50"
      },
      "live_shift": {
        "active_employees": 4,
        "burn_rate_per_hour": "100.00",
        "cost_so_far": "325.00"
      }
    }
  }
  ```

---

## DOMAIN: PLATFORM

---

### 27. Authentication & Tenant Isolation

- **The Legacy Mess:** Three separate login endpoints existed (`api_auth.php`, `api_auth_admin.php`, `api_auth_kiosk.php`) with different session key names (`user_id` vs `kiosk_user_id`). The tenant ID silently defaulted to `1` if no session existed — a data leakage risk. PIN login used column `pin` in one file and `pin_code` in another (schema drift).

- **The Optimized Algorithm (Gold Standard):**

  **Single auth endpoint** with mode parameter:

  | Mode | Credentials | Allowed Roles |
  |------|-----------|--------------|
  | `system` | `username` + `password` (bcrypt) | All roles |
  | `kiosk` | `pin_code` (4–6 digits) | All except `owner` |

  **Session enforcement:**

  1. **No silent defaults.** If `tenant_id` is not in the session/token, reject with `401 Unauthorized`. Never fall back to tenant 1.
  2. **Every SQL query** includes `tenant_id = :tid` in the WHERE clause. This is enforced at the data access layer, not left to individual endpoints.
  3. **Role-based access control** via middleware:

  ```
  RBAC Matrix:
  owner    → all modules
  admin    → all modules except tenant management
  manager  → floor, delivery, staff, inventory
  cook     → kds, inventory (read)
  waiter   → pos (dine-in), floor
  driver   → driver app, fleet status
  employee → team app, clock in/out, chat
  ```

  **Token format (stateless):** JWT with claims: `user_id`, `tenant_id`, `role`, `exp`. Kiosk tokens have a short TTL (5 min) to force re-authentication.

- **Modernization Upgrades:**
  - Unified PIN column (`pin_code`) across all tables, eliminating the schema drift.
  - Hard-fail on missing `tenant_id` (no silent default to tenant 1) closes the multi-tenancy leakage vector.
  - JWT tokens replace PHP sessions for a stateless API architecture.

- **Data Blueprint:**

  Login request:

  ```json
  {
    "mode": "system",
    "username": "jan",
    "password": "secret"
  }
  ```

  Response:

  ```json
  {
    "success": true,
    "data": {
      "token": "eyJhbGci...",
      "user": {
        "id": "user-uuid",
        "name": "Jan Kowalski",
        "role": "manager",
        "tenant_id": "tenant-uuid"
      },
      "target_module": "pos",
      "expires_at": "2026-04-12T08:00:00Z"
    }
  }
  ```

---

### 28. Document Numbering Standard

- **The Legacy Mess:** Multiple inconsistent formats: `ORD/YYYYMMDD/NNN`, `WWW/YYYYMMDD/HHmmss`, `MM/YYYY/MM/DD/HHmmss`, `WZ/YYYY/MM/DD/HHmmss`, `KOR/WZ/...`. Some used sequential counters (race-prone), others used timestamps (collision-prone).

- **The Optimized Algorithm (Gold Standard):**

  **Unified format:** `{TYPE}/{YYYYMMDD}/{NNNN}`

  | Document Type | Prefix | Sequence Scope |
  |--------------|--------|----------------|
  | Order (POS) | `ORD` | Per tenant, per day |
  | Order (Online) | `WWW` | Per tenant, per day |
  | Order (Kiosk) | `KIO` | Per tenant, per day |
  | Goods Receipt | `PZ` | Per tenant, per day |
  | Goods Issue | `WZ` | Per tenant, per day |
  | Transfer | `MM` | Per tenant, per day |
  | Correction | `KOR` | Per tenant, per day |
  | Inventory | `INW` | Per tenant, per day |
  | Internal Use | `RW` | Per tenant, per day |

  All sequences use the atomic `ON DUPLICATE KEY UPDATE seq = seq + 1` pattern on table `sh_doc_sequences (tenant_id, doc_type, date, seq)`.

  Four-digit zero-padding supports up to 9,999 documents per type per day per tenant.

- **Modernization Upgrades:**
  - Consistent format across all document types replaces the ad-hoc per-module schemes.
  - Atomic sequence generation eliminates all race conditions.

- **Data Blueprint:**

  ```json
  {
    "doc_number": "PZ/20260411/0005",
    "doc_type": "PZ",
    "sequence": 5,
    "date": "2026-04-11"
  }
  ```

---

### 29. ASCII Key Generation

- **The Legacy Mess:** Client-side only. Polish diacritics were mapped to ASCII, then non-alphanumeric characters replaced with underscores. No uniqueness check. No collision handling.

- **The Optimized Algorithm (Gold Standard):**

  **Step 1 — Transliterate:**

  ```
  Polish diacritics map: ą→a, ć→c, ę→e, ł→l, ń→n, ó→o, ś→s, ź→z, ż→z
  ```

  **Step 2 — Normalize:**

  1. Lowercase.
  2. Replace all non `[a-z0-9]` with `_`.
  3. Collapse consecutive underscores to one.
  4. Trim leading/trailing underscores.

  **Step 3 — Uniqueness check (server-side):**

  Query `sh_menu_items WHERE ascii_key = :generated AND tenant_id = :tid`.

  If collision: append `_2`, `_3`, etc. until unique.

  **Step 4 — Immutability:** Once assigned and used in `sh_price_tiers`, `sh_recipes`, or `sh_order_items`, the `ascii_key` **cannot be changed** (it's a foreign key). Renaming the item's display name does not affect the key.

- **Modernization Upgrades:**
  - Server-side uniqueness check with auto-suffix replaces the client-only generation that could silently produce duplicates.
  - Immutability enforcement protects referential integrity across the price, recipe, and order schemas.

- **Data Blueprint:**

  ```json
  {
    "input_name": "Pizza Włoska Żółta",
    "generated_key": "pizza_wloska_zolta",
    "collision_check": "unique",
    "final_key": "pizza_wloska_zolta"
  }
  ```

  If collision:

  ```json
  {
    "input_name": "Pizza Włoska Żółta",
    "generated_key": "pizza_wloska_zolta",
    "collision_check": "exists",
    "final_key": "pizza_wloska_zolta_2"
  }
  ```

---

## APPENDIX: Legacy Bug Resolution Summary

| Legacy Bug (from extraction §9.7) | Resolution in V2 |
|------------------------------------|-------------------|
| #1 Negative stock (no guard) | Alert 86 logged but allowed (intentional business decision). Configurable per tenant: `allow_negative_stock`. |
| #2 Order number race condition | Atomic sequence table (§6). |
| #3 Total price not validated server-side | Server-authoritative cart engine (§1). Client never sends totals. |
| #4 SQL injection in promised_time | All parameters use prepared statements (§12). |
| #5 Driver ID mismatch | Unified on `sh_users.id` everywhere (§15, §17). |
| #6 Kiosk PIN variable bug | Single auth endpoint, unified PIN column (§27). |
| #7 Broken waiter.html references | N/A — no client-side HTML in the new architecture. SPA modules self-contained. |
| #8 Hardcoded warehouse IDs | Dynamic warehouse resolution by `tenant_id` and `warehouse_type`. |
| #9 Hardcoded tenant for online | Tenant resolved from subdomain/API key (§27). |
| #10 String comparison for stock qty | All quantities are `DECIMAL(10,3)` in DB. No client-side comparisons for business logic. |
| #11 Orphaned code blocks | Eliminated by modular architecture (one action = one handler). |
| #12 waste_percent ignored in legacy | Always applied (§18). |
| #13 Removal matching by name | Matching by `warehouse_sku` (immutable key) (§18). |
| #14 Full return + re-deduct on edit | Delta-only stock adjustment (§8). |
| #15 Recipe change corrupts cancel returns | Document-based returns from original WZ, never re-derived (§21). |

---

> **End of synthesis.** 29 processes distilled from 1324 lines of legacy extraction.
> Each algorithm is bug-free, race-condition-proof, and designed for a stateless multi-tenant REST API.
