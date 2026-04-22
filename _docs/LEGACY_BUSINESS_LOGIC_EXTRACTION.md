# SLICEHUB LEGACY — DEEP BUSINESS LOGIC EXTRACTION

> **Forensic reverse-engineering** of the SliceHub ERP legacy codebase (PHP + JS + HTML).
> Generated from `_KOPALNIA_WIEDZY_LEGACY/`, `stare pliki/`, and `_archive/` sources.
> CSS/HTML boilerplate has been stripped — only pure math, algorithms, and business rules remain.

---

## Table of Contents

1. [POS, Cart & Order Mechanics (Pulse)](#-1-pos-cart--order-mechanics-pulse)
2. [Kitchen Communication & KDS](#-2-kitchen-communication--kds)
3. [Online Ordering Gateway](#-3-online-ordering-gateway)
4. [Fleet & Logistics (Battlefield)](#-4-fleet--logistics-battlefield)
5. [Staff Management & Gamification (Ekipa)](#-5-staff-management--gamification-ekipa)
6. [Menu Studio, Recipes & Modifiers](#-6-menu-studio-recipes--modifiers)
7. [Inventory & Warehouse (ERP)](#-7-inventory--warehouse-erp)
8. [Authentication & Multi-Tenancy](#-8-authentication--multi-tenancy)
9. [Implicit Data Structures & Edge Cases](#-9-implicit-data-structures--edge-cases)

---

## 🛒 1. POS, Cart & Order Mechanics (Pulse)

**Source files:** `api_pos.php`, `pos.html`, `pos (1).html`, `waiter.html`

### 1.1 Cart Mathematics

The cart uses a simple accumulator — there is **no separate subtotal/tax split**. All prices are tax-inclusive (gross).

**Line extension:** `unit_price × quantity`
**Order total:** Sum of all line extensions

```javascript
// pos.html — updateCartUI()
function updateCartUI() {
    const list = document.getElementById('cart-items');
    let total = 0;
    list.innerHTML = state.cart.map(i => {
        total += (parseFloat(i.price) * i.qty);
        // Per-line display:
        // ${(i.price * i.qty).toFixed(2)} zł
    });
    document.getElementById('cart-total').innerText = total.toFixed(2) + " zł";
}
```

**Server-side:** The API does **not recompute** the total. It trusts the client-sent `total_price`:

```php
// api_pos.php — process_order (new order INSERT)
$stmt->execute([
    $tenant_id, $uuid, $order_number, $source,
    $input['order_type'], $initial_status,
    $input['payment_method'], $input['payment_status'],
    $input['total_price'],  // <-- CLIENT VALUE, no server validation
    $input['address'], $input['customer_phone'],
    $input['nip'] ?? '', $new_cart_json, $promised,
    $print_kitchen, $user_id
]);
```

**Rounding:** All money values use JavaScript `toFixed(2)`. No banker's rounding or currency minor-unit policy exists.

**VAT/Tax:** The receipt template labels itself **"RACHUNEK / PARAGON NIEFISKALNY"** (non-fiscal). No net/gross split, no VAT rate computation occurs at order time. The `vat_rate` column exists in `sh_order_items` (default `8.00`) but is **never populated** by the POS — it's a dead schema field in the legacy flow.

---

### 1.2 Half-and-Half Pizza Pricing

```javascript
// pos.html — openHalfDishCard()
async function openHalfDishCard(itemA, itemB) {
    const price = Math.max(parseFloat(itemA.price), parseFloat(itemB.price)) + 2.00;
    state.activeItem = {
        id: null,
        name: `½ ${itemA.name} + ½ ${itemB.name}`,
        price: price.toFixed(2),
        qty: 1,
        removed: [], added: [], comment: "",
        is_half: true, half_a: itemA.id, half_b: itemB.id,
        cart_id: Date.now()
    };
}
```

**Formula:**

$$\text{half\_price} = \max(price_A,\ price_B) + 2.00\ \text{PLN}$$

The `+2.00` surcharge is **hardcoded**. Both `pos.html` and `waiter.html` share this identical formula.

---

### 1.3 Modifier (Topping) Price Adjustment

Each modifier adds a **flat +4.00 PLN** to the unit price. This is hardcoded, not per-ingredient:

```javascript
// pos.html — toggleAddMod()
function toggleAddMod(cb) {
    const id = parseInt(cb.value);
    if (cb.checked) {
        state.activeItem.added.push(id);
        state.activeItem.price = (parseFloat(state.activeItem.price) + 4.00).toFixed(2);
    } else {
        state.activeItem.added = state.activeItem.added.filter(x => x !== id);
        state.activeItem.price = (parseFloat(state.activeItem.price) - 4.00).toFixed(2);
    }
}
```

**Removing base ingredients** ("BEZ" / without): Toggling a removal only fills the `removed[]` array with `product_id`s. **No price reduction** occurs — removals are free for the customer but affect stock deduction (see §7).

---

### 1.4 Order Number Generation

```php
// api_pos.php — process_order (new order)
$stmtSeq = $pdo->prepare(
    "SELECT COUNT(*) FROM sh_orders WHERE tenant_id = ? AND DATE(created_at) = CURDATE()"
);
$stmtSeq->execute([$tenant_id]);
$seq = $stmtSeq->fetchColumn() + 1;
$order_number = 'ORD/' . date('Ymd') . '/' . str_pad($seq, 3, '0', STR_PAD_LEFT);
```

**Format:** `ORD/{YYYYMMDD}/{NNN}` — daily reset, zero-padded to 3 digits.

**Race condition risk:** Two concurrent inserts can get the same `COUNT(*)` → duplicate order number. No `UNIQUE` constraint or `SELECT ... FOR UPDATE` guards this.

**UUID generation** (for the `uuid` column):

```php
function generate_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
```

---

### 1.5 Payments & Settlement

**Payment methods** are string labels: `'cash'`, `'card'`, `'online'`, `'unpaid'`.

**There is no payment gateway integration** — no card processor, no PSP redirect, no Stripe/PayU. "Card" and "Online" are labels that trigger specific business rules:

**Mandatory receipt rule for card/online:**

```php
// api_pos.php — settle_and_close
if (($method === 'card' || $method === 'online') && $print === 0 && $already_printed == 0) {
    sendResponse('error', null, 'Dla karty lub online wydruk paragonu jest obowiązkowy!');
}
```

**Settlement closes the order:**

```php
$sql = "UPDATE sh_orders SET payment_status='paid', payment_method=?, status='completed'";
if ($print === 1) $sql .= ", receipt_printed=1";
$sql .= " WHERE id=?";
```

**"Park" order flow:** Setting `payment_status = 'unpaid'` and `payment_method = 'unpaid'` parks the order for later settlement.

**Split bills & tips:** **Not implemented.** Each order has exactly one `total_price` and one `payment_method`. No split tender, no tip field.

---

### 1.6 Discounts, Promo Codes & CRM

**Discounts/Promo codes:** **Not implemented** in the legacy POS flow. No voucher table, no discount calculation, no percentage/fixed discount fields exist on orders.

**CRM:** `customer_phone` is a free-text input field. **No** customer lookup, no returning customer recognition, no loyalty point accrual happens at POS.

**Loyalty (mockup only):** `loyalty_view (1).html` contains a design spec mentioning:
- 10 PLN spent → 1 point
- 100 points → free pizza
- Auto-account by phone number
- ASCII codes for promos (e.g. `PROMO_WEEKEND`)

This was **never implemented** in the backend code available in the repository.

---

### 1.7 Panic Mode

A single global "delay all orders by 20 minutes" emergency button:

```php
// api_pos.php — panic_mode
$pdo->prepare(
    "UPDATE sh_orders SET promised_time = DATE_ADD(
        COALESCE(promised_time, created_at), INTERVAL 20 MINUTE
    ) WHERE status IN ('pending', 'ready') AND tenant_id = ?"
)->execute([$tenant_id]);
```

---

### 1.8 Order Edit & Kitchen Change Detection

When editing an existing order, the system **diff-compares** old and new cart JSON to generate a human-readable kitchen change summary:

```php
// api_pos.php — process_order (edit path)
if ($oldOrder['cart_json'] !== $new_cart_json) {
    $edited_flag = 1; // Yellow alert trigger
    $diff_arr = [];
    $oldMap = []; foreach($oldCart as $c) { $oldMap[$c['cart_id']] = $c; }
    $newMap = []; foreach($cart as $c) { $newMap[$c['cart_id']] = $c; }

    foreach($newMap as $cid => $c) {
        if (!isset($oldMap[$cid])) {
            $diff_arr[] = "DODANO: " . $c['qty'] . "x " . $c['name'];
        } else {
            if ($oldMap[$cid]['qty'] != $c['qty']) {
                $diff_arr[] = "ZMIENIONO ILOŚĆ: " . $c['name']
                    . " (" . $oldMap[$cid]['qty'] . " -> " . $c['qty'] . ")";
            }
            if (($oldMap[$cid]['comment'] ?? '') != ($c['comment'] ?? '')) {
                $diff_arr[] = "ZMIENIONO UWAGI DO: " . $c['name'];
            }
        }
    }
    foreach($oldMap as $cid => $oc) {
        if (!isset($newMap[$cid])) {
            $diff_arr[] = "USUNIĘTO: " . $oc['qty'] . "x " . $oc['name'];
        }
    }
    $kitchen_changes = implode(" | ", $diff_arr);
}
```

**Key:** `cart_id` (a `Date.now()` timestamp per line) is the stable identity used for diffing.

When a kitchen ticket is reprinted, the edit flag is cleared:

```php
if ($print_kitchen === 1 && $source === 'local') {
    $edited_flag = 0;
    $kitchen_changes = '';
}
```

---

## 👨‍🍳 2. Kitchen Communication & KDS

**Source files:** `api_pos.php`, `pos.html`, `waiter.html`

### 2.1 Ticket Routing

**There is no item-level station routing** (e.g. "drinks to bar, pizza to oven") in the legacy code. The kitchen receives the **entire order** as a single ticket.

The `kitchen_ticket_printed` flag on `sh_orders` tracks whether a ticket was sent:

```php
// api_pos.php — print_kitchen
$pdo->prepare(
    "UPDATE sh_orders SET kitchen_ticket_printed=1, edited_since_print=0, kitchen_changes='' WHERE id=?"
)->execute([$input['order_id']]);
```

**Waiter module** always sets `print_kitchen: 1`:

```javascript
// waiter.html — submitOrder payload
const payload = {
    // ...
    print_kitchen: 1, // Always notify kitchen
    source: 'waiter',
    status: isNewOrder ? 'new' : 'pending'
};
```

The v2 schema adds `kds_station_id` to `sh_menu_items`, suggesting **station-level routing was planned** but not implemented in legacy.

### 2.2 Hardware Integration (Printing)

**No direct printer driver code exists.** Printing is implemented via CSS `@media print` rules and `window.print()` browser API:

- **Kitchen ticket:** Generated as an HTML template in `pos.html` with order details, rendered in a hidden div, then `window.print()`.
- **Receipt:** Same approach — HTML template → browser print.
- **QR sheets:** `manager_floor.html` uses `window.print()` for table QR code sheets.

The `receipt_printed` and `kitchen_ticket_printed` flags are **database bookkeeping** — they don't trigger hardware directly.

### 2.3 Order Status Transitions (Kitchen Path)

```
[new] ──accept_order──> [pending] ──update_status──> [ready] ──(delivery/pickup)──> [completed]
                                                                                      │
                                                                     cancel_order ──> [cancelled]
```

- `new`: Online/waiter orders waiting for POS acceptance
- `pending`: Accepted, in kitchen queue
- `ready`: Prepared, awaiting pickup or dispatch
- `completed`: Paid and closed
- `cancelled`: Voided (with optional stock return)

---

## 🌐 3. Online Ordering Gateway

**Source files:** `api_online.php`, `online_store.html`

### 3.1 Time Estimation

**Static, not dynamic.** No distance, traffic, or prep-time algorithms exist.

**Client-side:** Fixed dropdown options:

```html
<select id="c-time">
    <option value="asap">Jak najszybciej (ASAP)</option>
    <option value="12:00">Na 12:00</option>
    <option value="14:00">Na 14:00</option>
    <option value="18:00">Na 18:00</option>
    <option value="20:00">Na 20:00</option>
</select>
```

**Server-side promise time:**

```php
// api_online.php — submit_order
$promised_sql = "NOW()";
if ($requested_time !== 'asap') {
    // WARNING: SQL injection — $requested_time is interpolated directly
    $promised_sql = "'" . date('Y-m-d') . " " . $requested_time . ":00'";
}
```

**Waiter module** uses a relative offset (15/30/45/60 minutes from now):

```javascript
// waiter.html
let targetTime = new Date();
targetTime.setMinutes(targetTime.getMinutes() + state.waiterTime);
const isoTime = new Date(
    targetTime.getTime() - (targetTime.getTimezoneOffset() * 60000)
).toISOString().slice(0, 16);
```

### 3.2 Cart Validation Rules

**Server-side validation is minimal:**

```php
// api_online.php
if (empty($cart) || empty($customer_phone)) {
    echo json_encode(['status' => 'error', 'error' => 'Brakujące dane zamówienia lub numer telefonu.']);
    exit;
}
```

**Client-side:**

```javascript
// online_store.html — submitOrder()
if (state.cart.length === 0) return;
if (!name || !phone) return alert("Podaj Imię i Telefon!");
if (state.orderType === 'delivery' && !addr) return alert("Podaj adres dostawy!");
```

**Not implemented:** minimum order value, delivery zone geofencing, business hours validation, item availability check, price re-validation.

### 3.3 Online Order Number & Payment

**Format:** `WWW/{YYYYMMDD}/{HHmmss}` — timestamp-based, **not** sequential.

```php
$order_number = 'WWW/' . date('Ymd/His');
```

**Payment status rule:**

```php
$payment_status = ($payment_method === 'online') ? 'paid' : 'unpaid';
$status = 'new'; // Flag to appear in POS "The Pulse" incoming queue
```

### 3.4 Tenant Hardcoding

```php
// api_online.php — line 25
$tenant_id = 1; // Fixed to a single tenant — no multi-tenant online store
```

---

## 🚚 4. Fleet & Logistics (Battlefield)

**Source files:** `api_delivery.php`, `api_driver.php`, `delivery.html`, `driver.html`, `pos_fleet.js`, `pos_active_routes.js`

### 4.1 Dispatch & Routing

**Manual dispatch only.** No auto-dispatch, no scoring, no optimization algorithm.

The flow: operator selects a driver + order IDs → single bulk UPDATE:

```php
// api_delivery.php — assign_route
$in = str_repeat('?,', count($order_ids) - 1) . '?';
$params = array_merge(['in_delivery', $driver_id], $order_ids);
$pdo->prepare(
    "UPDATE sh_orders SET status = ?, driver_id = ? WHERE id IN ($in)"
)->execute($params);
```

### 4.2 K-System & L-Queues (Course Numbering)

**Course ID (K-system):** Daily sequential trip counter per tenant:

```php
// api_pos.php — assign_route
$stmtK = $pdo->prepare(
    "SELECT COUNT(DISTINCT course_id) FROM sh_orders
     WHERE tenant_id = ? AND DATE(created_at) = CURDATE() AND course_id IS NOT NULL"
);
$stmtK->execute([$tenant_id]);
$next_k = $stmtK->fetchColumn() + 1;
$course_id = 'K' . $next_k;  // K1, K2, K3...
```

**Stop numbering (L-queues):** Sequential within a course:

```php
$l_num = 1;
foreach ($order_ids as $oid) {
    $stmtUpdate->execute([$driver_id, $course_id, 'L' . $l_num, $oid, $tenant_id]);
    $l_num++;  // L1, L2, L3...
}
```

**UI sorting by stop number:**

```javascript
// pos_active_routes.js
g.items.sort((a, b) => {
    let numA = parseInt((a.stop_number || 'L99').replace('L', ''));
    let numB = parseInt((b.stop_number || 'L99').replace('L', ''));
    return numA - numB;
});
```

Missing stop → treated as `L99` (sorted last).

### 4.3 Maps & Distances

**No server-side distance math, no geofencing, no zone boundaries.**

**Leaflet map** on dispatch dashboard uses **random marker offsets** around a hardcoded base point (not real geocoding):

```javascript
// delivery.html — updateMapMarkers()
readyOrders.forEach((o) => {
    const latOffset = (Math.random() - 0.5) * 0.04;
    const lngOffset = (Math.random() - 0.5) * 0.04;
    const marker = L.marker(
        [52.4064 + latOffset, 16.9252 + lngOffset], // Poznań area, random scatter
        { icon: L.divIcon({...}) }
    );
});
```

**Driver navigation:** Opens Google Maps Directions URL (browser-side only):

```javascript
// driver.html
const navLink = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(r.address)}`;
```

**Delivery fee calculations:** **Not present.** No `delivery_fee` field, no zone tables, no distance-based pricing.

### 4.4 Driver Payroll & Cashbox

**Per-drop commissions, fuel allowances:** **Not implemented.** No `pay_per_drop`, `fuel_rate`, or commission fields exist.

#### 4.4.1 Initial Cash ("Pogotowie Kasowe")

```php
// api_delivery.php — set_initial_cash
$pdo->prepare("UPDATE sh_drivers SET initial_cash = ? WHERE id = ?")
    ->execute([$cash, $driver_id]);
```

#### 4.4.2 Collected Cash Calculation (Dispatcher Dashboard)

```php
// api_delivery.php — get_dashboard
foreach ($drivers as &$driver) {
    $stmtCash = $pdo->prepare(
        "SELECT SUM(total_price) FROM sh_orders
         WHERE tenant_id = ? AND driver_id = ? AND payment_method = 'cash'
         AND status = 'completed' AND DATE(created_at) = CURDATE()"
    );
    $stmtCash->execute([$tenant_id, $driver['id']]);
    $driver['collected_cash'] = (float)$stmtCash->fetchColumn();
    $driver['expected_total'] = $driver['initial_cash'] + $driver['collected_cash'];
}
```

**Formulas:**

$$\text{collected\_cash} = \sum \text{total\_price} \quad \text{(cash + completed + today)}$$

$$\text{expected\_total} = \text{initial\_cash} + \text{collected\_cash}$$

#### 4.4.3 Active Route Cash Breakdown

```javascript
// pos_active_routes.js — wallet logic per route group
if (o.payment_status === 'paid') {
    routeGroups[o.course_id].paidTotal += price;
} else {
    if (o.payment_method === 'card') {
        routeGroups[o.course_id].cardToCollect += price;
    } else {
        // Default: everything unpaid and not card = cash
        routeGroups[o.course_id].cashToCollect += price;
    }
}

// Return to base:
const totalCashToReturn = initialCash + g.cashToCollect;
```

$$\text{totalCashToReturn} = \text{initialCash} + \sum \text{unpaid\_non\_card\_amounts}$$

#### 4.4.4 Driver "Cash in Hand" (Driver App)

```php
// api_driver.php — get_my_routes
$stmtCash = $pdo->prepare(
    "SELECT SUM(total_price) as cash_in_hand
     FROM sh_orders
     WHERE tenant_id = ? AND driver_id = ? AND payment_method = 'cash'
     AND status = 'completed' AND DATE(created_at) = CURDATE()"
);
```

**Bug note:** `driver_id = ?` uses `$user_id` (the `sh_users.id`), while the dispatch dashboard uses `$driver['id']` (the `sh_drivers.id`). These are **different columns** — `sh_drivers.id` ≠ `sh_users.id`. This could produce wrong cashbox values.

### 4.5 Delivery Time / SLA Display

```javascript
// delivery.html — relative to promised_time
const promised = new Date(o.promised_time || now);
const diffMin = Math.floor((promised - now) / 60000);

if (diffMin < 0)       → "Spóźnione ${Math.abs(diffMin)}m" (RED, pulsing)
else if (diffMin <= 5)  → "Za ${diffMin} min" (YELLOW)
else                    → "Za ${diffMin} min" (GREEN)
```

$$\text{diffMin} = \lfloor \frac{\text{promised\_time} - \text{now}}{60000} \rfloor$$

### 4.6 Driver & Order Status State Machine

**Driver status** (`sh_drivers.status`):

```
clock_in → [available]
assign_route → [busy]         (when order goes to 'in_delivery')
all orders completed → [available]  (auto-reset)
clock_out → [offline]
```

```php
// api_driver.php — update_route_status
if ($new_status === 'completed') {
    $pdo->prepare("UPDATE sh_drivers SET status = 'available' WHERE user_id = ?")
        ->execute([$user_id]);
} else if ($new_status === 'in_delivery') {
    $pdo->prepare("UPDATE sh_drivers SET status = 'busy' WHERE user_id = ?")
        ->execute([$user_id]);
}
```

**Driver-allowed transitions (guard):**

```php
if (!in_array($new_status, ['in_delivery', 'completed'])) {
    sendResponse('error', null, 'Nieprawidłowe dane.');
}
```

**Delivery order lifecycle:**

```
[pending] → [ready] → assign_route → [in_delivery] → [completed]
                                            │
                         cancel_route ──> [ready] (driver_id = NULL)
```

**Audit trail:**

```php
// api_driver.php
$stmtAudit = $pdo->prepare(
    "INSERT INTO sh_order_audit (order_id, user_id, action, new_value) VALUES (?, ?, 'status_change', ?)"
);
$stmtAudit->execute([$order_id, $user_id, $new_status]);
```

---

## 👥 5. Staff Management & Gamification (Ekipa)

**Source files:** `api_ekipa.php`, `api_auth.php` (stare pliki), `app.html`, `admin_app.html`, `api_kiosk_emp.php`

### 5.1 Clock In / Clock Out

```php
// stare pliki/api_auth.php — clock_action
if ($type === 'clock_in') {
    $stmt = $pdo->prepare(
        "INSERT INTO sh_work_sessions (tenant_id, user_id, start_time) VALUES (?, ?, NOW())"
    );
    $stmt->execute([$tenant_id, $user_id]);

    // Auto-register as driver if not already in sh_drivers
    $stmtCheck = $pdo->prepare("SELECT id FROM sh_drivers WHERE user_id = ?");
    $stmtCheck->execute([$user_id]);
    if (!$stmtCheck->fetch()) {
        $pdo->prepare("INSERT INTO sh_drivers (user_id, status) VALUES (?, 'available')")
            ->execute([$user_id]);
    } else {
        $pdo->prepare("UPDATE sh_drivers SET status = 'available' WHERE user_id = ?")
            ->execute([$user_id]);
    }
} else { // clock_out
    $stmt = $pdo->prepare(
        "UPDATE sh_work_sessions
         SET end_time = NOW(),
             total_time = TIMESTAMPDIFF(MINUTE, start_time, NOW()) / 60.0
         WHERE user_id = ? AND end_time IS NULL
         ORDER BY start_time DESC LIMIT 1"
    );
    $stmt->execute([$user_id]);

    // Remove driver from radar
    $pdo->prepare("UPDATE sh_drivers SET status = 'offline' WHERE user_id = ?")
        ->execute([$user_id]);
}
```

**`total_time` formula (in hours):**

$$\text{total\_time} = \frac{\text{TIMESTAMPDIFF(MINUTE, start\_time, NOW())}}{60.0}$$

### 5.2 Payroll Calculation

```php
// stare pliki/api_ekipa.php — get_profile_data
$rate = (float)($u['hourly_rate'] ?? 0);

// Current month-to-date (1st of month to now)
$st_c = date('Y-m-01 00:00:00');
$en_c = date('Y-m-d H:i:s');
$hrs_c = getVal($pdo, "SELECT IFNULL(SUM(total_time), 0) FROM sh_work_sessions
    WHERE user_id = $uid AND start_time >= '$st_c' AND start_time <= '$en_c'");

// Include currently running shift
$active_hrs = getVal($pdo, "SELECT IFNULL(TIMESTAMPDIFF(MINUTE, start_time, NOW()) / 60, 0)
    FROM sh_work_sessions WHERE user_id = $uid AND end_time IS NULL");
$hrs_c += $active_hrs;

$adv_c = getVal($pdo, "SELECT IFNULL(SUM(amount), 0) FROM sh_deductions
    WHERE user_id = $uid AND created_at >= '$st_c' AND created_at <= '$en_c'");

$gross_c = $hrs_c * $rate;
$net_c = $gross_c - $adv_c;
```

**Formulas:**

$$\text{gross} = \text{hours\_worked} \times \text{hourly\_rate}$$

$$\text{net} = \text{gross} - \text{advances (deductions)}$$

### 5.3 Previous Month Comparison (Precise MTD Match)

```php
// stare pliki/api_ekipa.php — get_profile_data
$prev_time = strtotime('-1 month');
$st_p = date('Y-m-01 00:00:00', $prev_time);
$days_in_prev = date('t', $prev_time);
$target_day = $d > $days_in_prev ? $days_in_prev : $d;
$en_p = date('Y-m-' . $target_day . ' ' . $H, $prev_time);
```

This computes the **equivalent point last month** (same day-of-month and time-of-day), capping to month length to avoid overflow (e.g. March 31 → February 28).

### 5.4 Advanced Reporting (Week/Month/Year Periods)

```php
// stare pliki/api_ekipa.php — get_advanced_report
if ($type === 'week') {
    $start = date('Y-m-d 00:00:00', strtotime("monday this week -$offset weeks"));
    $end = date('Y-m-d 23:59:59', strtotime("sunday this week -$offset weeks"));
} elseif ($type === 'year') {
    $start = date('Y-01-01 00:00:00', strtotime("first day of january -$offset years"));
    $end = date('Y-12-31 23:59:59', strtotime("last day of december -$offset years"));
} else { // month
    $start = date('Y-m-01 00:00:00', strtotime("first day of -$offset months"));
    $end = date('Y-m-t 23:59:59', strtotime("last day of -$offset months"));
}
// Same gross/net formula applies
```

### 5.5 Boss Dashboard — Team Payroll Aggregation

```javascript
// admin_app.html — loadStats()
d.stats.forEach(s => {
    let e = parseFloat(s.gross_earned);
    let ud = d.deductions.filter(x => x.user_id == s.id)
                         .reduce((a, b) => a + parseFloat(b.amount), 0);
    let um = d.meals.filter(x => x.user_id == s.id)
                    .reduce((a, b) => a + parseFloat(b.employee_price), 0);
    let fp = e - ud - um;
    tp += (fp > 0 ? fp : 0); // Negative net excluded from total
});
```

**Per-employee formula:**

$$\text{final\_payout} = \text{gross\_earned} - \text{deductions} - \text{meal\_charges}$$

$$\text{total\_payout} = \sum \max(0, \text{final\_payout}_i)$$

### 5.6 Active Shift Cost (Real-Time)

```javascript
// admin_app.html — loadDashboard()
const hoursRaw = parseFloat(u.elapsed_minutes) / 60;
const currentCost = hoursRaw * parseFloat(u.hourly_rate);
totalShiftCost += currentCost;
```

$$\text{shift\_cost}_i = \frac{\text{elapsed\_minutes}_i}{60} \times \text{hourly\_rate}_i$$

### 5.7 Slice Coins Gamification

**Daily reward wheel:**

```php
// stare pliki/api_ekipa.php — spin_wheel
$claimed = $pdo->query(
    "SELECT COUNT(*) FROM sh_daily_rewards WHERE user_id = $uid AND claimed_date = CURDATE()"
)->fetchColumn() > 0;
if ($claimed) exit; // One spin per day

$won = rand(2, 10);
$pdo->prepare("INSERT INTO sh_daily_rewards (user_id, claimed_date, coins_won) VALUES (?, CURDATE(), ?)")
    ->execute([$uid, $won]);
$pdo->prepare("UPDATE sh_users SET slice_coins = slice_coins + ? WHERE id = ?")
    ->execute([$won, $uid]);
```

**Pizza economy conversion** (display only):

```javascript
// app.html — updatePizzaEconomy()
function updatePizzaEconomy(totalCoins) {
    const coins = parseInt(totalCoins) || 0;
    const pizzas = Math.floor(coins / 64);
    const slices = Math.floor((coins % 64) / 8);
    const kesy = coins % 8;
}
```

$$\text{pizzas} = \lfloor \frac{coins}{64} \rfloor \qquad \text{slices} = \lfloor \frac{coins \mod 64}{8} \rfloor \qquad \text{bites} = coins \mod 8$$

### 5.8 Online Presence Detection

```php
// api_ekipa.php — get_chat_data
IF(ws.id IS NOT NULL, 'blue',
   IF(u.last_seen > NOW() - INTERVAL 5 MINUTE, 'green', 'red')) as status_color
```

- **Blue:** Active work session (clocked in)
- **Green:** Seen within last 5 minutes
- **Red:** Offline

### 5.9 Daily Trivia Rotation

```php
$trivia = ["Prawdziwa Pizza Napoletana...", "Słowo Pizza...", ...];
echo $trivia[date('z') % count($trivia)]; // Day-of-year mod array length
```

---

## 🎨 6. Menu Studio, Recipes & Modifiers

**Source files:** `api_menu_studio.php`, `api_recipes.php`, `studio_*.js`, `menu_builder.html`

### 6.1 Menu Item Structure

**Legacy:** `sh_menu_items` with inline `price`, `type`, `is_deleted`, `is_active`, `is_secret`.

**v2 evolution:** Price removed from item row; moved to **`sh_price_tiers`** with per-channel pricing:

```sql
-- sh_price_tiers composite key
UNIQUE KEY (target_type, target_sku, channel)
-- target_type: 'ITEM' | 'MODIFIER'
-- channel: 'POS' | 'Takeaway' | 'Delivery'
```

### 6.2 ASCII Key Generation

```javascript
// studio_item.js — generateAscii()
generateAscii() {
    const nameField = document.getElementById('insp-name').value;
    const charMap = {
        'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ź':'z','ż':'z'
    };
    let cleanStr = nameField.toLowerCase()
        .replace(/[ąćęłńóśźż]/g, match => charMap[match] || match);
    cleanStr = cleanStr.replace(/[^a-z0-9]/g, '_')
                       .replace(/_+/g, '_')
                       .replace(/^_|_$/g, '');
    asciiField.value = cleanStr;
}
```

Polish diacritics → ASCII, then: non-alphanumeric → underscore, collapse runs, trim edges.

### 6.3 Bulk Price Operations

```php
// api_menu_studio.php — bulk_action
$val = (float)$input['price_value'];
$math = $input['price_action'] === 'add'
    ? "price + ?"
    : ($input['price_action'] === 'sub'
        ? "GREATEST(0, price - ?)"
        : "?");

$pdo->prepare("UPDATE sh_item_variants SET price = $math WHERE item_id IN ($placeholders)")
    ->execute(array_merge([$val], $item_ids));
```

| Action | SQL Formula |
|--------|-----------|
| `add` | `price + val` |
| `sub` | `GREATEST(0, price - val)` (floor at zero) |
| `set` | `val` (absolute replacement) |

Client-side mirror:

```javascript
// studio_item.js — quickAdjustPrices()
if (type === 'add') current += val;
if (type === 'sub') current = Math.max(0, current - val);
if (type === 'set') current = val;
input.value = current.toFixed(2);
```

### 6.4 Recipe System

**Storage:** `sh_recipes` table with `menu_item_id`, `product_id`, `quantity`, `waste_percent`.

```php
// api_recipes.php — save_recipe
$stmtInsert = $pdo->prepare(
    "INSERT INTO sh_recipes (menu_item_id, product_id, quantity, waste_percent) VALUES (?, ?, ?, ?)"
);
foreach ($ingredients as $ing) {
    $prod_id = (int)$ing['product_id'];
    $qty = (float)$ing['quantity'];
    $waste = (float)($ing['waste_percent'] ?? 0);
    if ($prod_id > 0 && $qty > 0) {
        $stmtInsert->execute([$menu_item_id, $prod_id, $qty, $waste]);
    }
}
```

### 6.5 Recipe "Autoscan" Heuristic

```javascript
// studio_recipe.js — autoScan()
autoScan() {
    const desc = document.getElementById('insp-desc').value.toLowerCase();
    this.state.products.forEach(prod => {
        if (desc.includes(prod.name.toLowerCase())
            && !this.state.currentRecipe.find(r => r.product_id == prod.id)) {
            this.state.currentRecipe.push({
                product_id: prod.id,
                quantity: 0.1,      // Default guess
                waste_percent: 0,
                product_name: prod.name,
                unit: prod.unit
            });
        }
    });
}
```

Scans the item **description** for product name substrings and auto-adds them with `quantity = 0.1`.

### 6.6 Margin / Food Cost

**Not implemented.** No legacy file computes `(selling_price − food_cost) / selling_price`. The `waste_percent` is stored and edited but only used during stock deduction (see §7).

---

## 📦 7. Inventory & Warehouse (ERP)

**Source files:** `api_pos.php`, `api_inventory.php`, `api_magazyn_pro.php` (stare pliki), `api_warehouse.php` (current)

### 7.1 Stock Consumption on POS Sale (Recipe Engine)

The core formula with waste factor, applied per cart item:

```php
// api_pos.php — process_order
$calcRecipe = function($menu_id, $multiplier) use ($stmtRecipe, &$products_to_deduct, $removed_ids) {
    if (!$menu_id) return;
    $stmtRecipe->execute([$menu_id]);
    foreach ($stmtRecipe->fetchAll() as $ing) {
        $pid = $ing['product_id'];
        if (in_array($pid, $removed_ids)) continue; // Skip "BEZ" items
        $needed = ($ing['quantity'] * (1 + ($ing['waste_percent'] / 100))) * $multiplier;
        if (!isset($products_to_deduct[$pid])) $products_to_deduct[$pid] = 0;
        $products_to_deduct[$pid] += $needed;
    }
};
```

**Formula per ingredient per line:**

$$\text{needed} = \text{recipe\_qty} \times (1 + \frac{\text{waste\%}}{100}) \times \text{multiplier}$$

**Multiplier rules:**

| Scenario | Multiplier |
|----------|-----------|
| Standard item | `1.0 × qty_sold` |
| Half-half (each half) | `0.5 × qty_sold` |

### 7.2 Added Modifier Stock Deduction

```php
// api_pos.php
if (!empty($item['added'])) {
    foreach ($item['added'] as $added_pid) {
        $unit = $productUnits[$added_pid] ?? 'szt';
        $extra_qty = 1.0;
        if (in_array($unit, ['kg', 'litr', 'l'])) $extra_qty = 0.05;
        $products_to_deduct[$added_pid] += ($extra_qty * $qty_sold);
    }
}
```

| Unit type | Deduction per modifier per sold qty |
|-----------|-----------------------------------|
| `szt` (piece) | 1.0 |
| `kg`, `litr`, `l` | 0.05 |

### 7.3 Stock Level Mechanics

**Deduction (WZ):**

```php
// Attempt update first
$stmtUpdateStock->execute([$final_qty, $warehouse_id, $pid]);
// If no row existed, insert negative
if ($stmtUpdateStock->rowCount() === 0) {
    $stmtInsertStock->execute([$warehouse_id, $pid, -$final_qty]);
}
```

**Stock can go negative** — there is no guard preventing it.

### 7.4 PZ (Goods Receipt)

```php
// api_inventory.php — submit_pz
$stmtStock = $pdo->prepare(
    "INSERT INTO sh_stock_levels (warehouse_id, product_id, quantity)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)"
);
$stmtStock->execute([$wh_id, $p_id, $qty]);
```

$$\text{new\_stock} = \text{old\_stock} + \text{received\_qty}$$

### 7.5 MM (Inter-Warehouse Transfer)

```php
// stare pliki/api_magazyn_pro.php — erp_transfer_mm
$pdo->prepare("UPDATE sh_stock_levels SET quantity = quantity - ?
    WHERE warehouse_id = ? AND product_id = ?")->execute([$qty, $src_w, $p_id]);

$pdo->prepare("INSERT INTO sh_stock_levels (warehouse_id, product_id, quantity)
    VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?")
    ->execute([$tgt_w, $p_id, $qty, $qty]);

$doc_number = 'MM/' . date('Y/m/d/His');
```

### 7.6 WZ Void / Correction (KOR)

```php
// stare pliki/api_magazyn_pro.php — erp_pos_void
// 1. Find original WZ document
$stmt->execute([$order_id]); $old_doc = $stmt->fetch();

// 2. Create correction document
$kor_number = 'KOR/' . $old_doc['document_number'];

// 3. Return each item to stock
foreach ($items as $item) {
    $pdo->prepare("UPDATE sh_stock_levels SET quantity = quantity + ?
        WHERE warehouse_id = ? AND product_id = ?")
        ->execute([$qty, $warehouse_kuchnia_id, $p_id]);
}
```

### 7.7 Inventory Count (Inwentaryzacja)

```php
// stare pliki/api_ekipa.php — submit_inventory_sheet
foreach ($data as $pid => $new_val) {
    $old_val = $pdo->query("SELECT quantity FROM sh_products WHERE id = ".(int)$pid)->fetchColumn();
    $change = (float)$new_val - (float)$old_val;
    if ($change != 0) {
        $pdo->prepare("UPDATE sh_products SET quantity = ? WHERE id = ?")
            ->execute([$new_val, $pid]);
        $pdo->prepare("INSERT INTO sh_inventory_logs
            (product_id, user_id, quantity_changed, action_type) VALUES (?, ?, ?, 'inwentaryzacja')")
            ->execute([$pid, $uid, $change]);
    }
}

// Auto-complete "Rewizja Magazynu" mission if one exists
$rev_mission_id = $pdo->query("SELECT id FROM sh_missions WHERE title = 'Rewizja Magazynu' LIMIT 1")->fetchColumn();
if ($rev_mission_id) {
    $pdo->prepare("INSERT INTO sh_mission_proofs (mission_id, user_id, status) VALUES (?, ?, 'pending')")
        ->execute([$rev_mission_id, $uid]);
}
```

### 7.8 AVCO (Average Cost) — Current System Only

```php
// api/api_warehouse.php — PZ handling (current, non-legacy)
if ($old_qty <= 0) {
    $new_avco = $unitNetCost;
} else {
    $new_avco = (($old_qty * $old_avco) + ($new_qty * $unitNetCost))
                / ($old_qty + $new_qty);
}
```

$$\text{AVCO}_{new} = \frac{(\text{old\_qty} \times \text{old\_AVCO}) + (\text{new\_qty} \times \text{unit\_net\_cost})}{\text{old\_qty} + \text{new\_qty}}$$

This is the **Weighted Average Cost** method for inventory valuation. Only present in the v2 API, not in legacy.

### 7.9 Stock Matrix (Boss View)

```php
// stare pliki/api_magazyn_pro.php — get_matrix
foreach ($products as $p) {
    $matrix[$p_id] = [
        'id' => $p['id'],
        'name' => $p['name'],
        'ascii' => $p['ascii_key'],
        'w1' => 0.000,    // Main warehouse (ID: 1)
        'w2' => 0.000     // Kitchen (ID: 2)
    ];
}
foreach ($stocks as $s) {
    if ($w_id == 1) $matrix[$p_id]['w1'] = (float)$s['quantity'];
    if ($w_id == 2) $matrix[$p_id]['w2'] = (float)$s['quantity'];
}
```

Two warehouses are **hardcoded** (Main = 1, Kitchen = 2).

### 7.10 Document Type Reference

| Code | Name | Stock Effect |
|------|------|-------------|
| `PZ` | Przyjęcie Zewnętrzne (Goods Receipt) | `+quantity` |
| `WZ` | Wydanie Zewnętrzne (Goods Issue) | `-quantity` |
| `MM` | Przesunięcie Międzymagazynowe (Transfer) | `-source`, `+target` |
| `KOR` | Korekta (WZ Reversal) | `+quantity` (return) |
| `IN` / `INW` | Inwentaryzacja (Physical Count) | Set to counted value |
| `RW` | Rozchód Wewnętrzny (Internal Use) | `-quantity` (v2 only) |

---

## 🔐 8. Authentication & Multi-Tenancy

**Source files:** `api_auth.php`, `api_auth_admin.php`, `api_auth_pos.php`, `api_auth_kiosk.php`, `api_session_check.php`, `db_connect.php`

### 8.1 Session Architecture

```php
// db_connect.php
$tenant_id = $_SESSION['tenant_id'] ?? 1;  // Defaults to tenant 1
$user_id = $_SESSION['user_id'] ?? null;

function require_login($type = 'system') {
    if ($type === 'kiosk') {
        if (!isset($_SESSION['kiosk_user_id'])) { /* reject */ }
    } else {
        if (!isset($_SESSION['user_id'])) { /* reject */ }
    }
}
```

Two parallel session tracks: **System** (`user_id`) and **Kiosk** (`kiosk_user_id`).

### 8.2 Login Flows

**System login** (username + password):

```php
// stare pliki/api_auth.php — login
$stmt = $pdo->prepare(
    "SELECT id, username, role, password_hash, tenant_id
     FROM sh_users WHERE username = ? AND is_active = 1"
);
// password_verify($password, $user['password_hash'])
```

**Role-based redirect:**

| Role | Target |
|------|--------|
| `owner` | `admin_app.html` |
| `driver` | `driver.html` |
| `waiter` | `waiter.html` |
| default | `pos.html` / `app.html` |

**Admin login** (restricted roles):

```php
// api_auth_admin.php
$stmt = $pdo->prepare(
    "SELECT ... FROM sh_users WHERE username = ? AND is_active = 1
     AND role IN ('owner', 'admin', 'manager')"
);
```

**Kiosk PIN login** (4-digit):

```php
// api_auth_kiosk.php
$stmt = $pdo->prepare(
    "SELECT id, first_name, tenant_id FROM sh_users
     WHERE pin_code = ? AND is_active = 1 AND role != 'owner'"
);
```

**Schema drift:** Legacy `stare pliki/api_auth.php` uses column `pin`, while newer `api_auth_kiosk.php` uses `pin_code`.

### 8.3 Multi-Tenancy Enforcement

Every query must include `tenant_id = ?` in WHERE clause. The session-level default (`?? 1`) means an unauthenticated request silently falls back to tenant 1 rather than failing — a potential security concern.

### 8.4 Finance Requests

```php
// api_manager.php
$stmt = $pdo->prepare(
    "INSERT INTO sh_finance_requests
     (tenant_id, target_user_id, created_by_id, type, amount, description, is_paid_cash)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
```

Types: `advance`, `bonus`, `meal` — deducted from gross in payroll calculation.

---

## 🧬 9. Implicit Data Structures & Edge Cases

### 9.1 Cart Line Object (JS)

```javascript
{
    id: 5,              // sh_menu_items.id (null for half-half)
    name: "Margherita 32cm",
    price: "34.00",     // String, modified by toggleAddMod
    qty: 2,
    removed: [3, 7],    // product_id[] of removed ingredients
    added: [12],         // product_id[] of added modifiers (+4 PLN each)
    comment: "Extra crispy",
    cart_id: 1774893246437,  // Date.now() — stable identity for diffing
    is_half: false,
    half_a: null,        // sh_menu_items.id of half A
    half_b: null         // sh_menu_items.id of half B
}
```

### 9.2 Order Object (sh_orders row)

```
id, tenant_id, uuid, order_number, source, type, status,
payment_method, payment_status, total_price,
address, customer_phone, customer_name, customer_email, nip,
document_type (receipt|invoice),
driver_id, route_id, route_order_index, is_turned_back,
promised_time, cart_json,
receipt_printed, kitchen_ticket_printed, edited_since_print,
kitchen_changes, course_id, stop_number, is_half,
created_by, created_at
```

**Source enum:** `local`, `online`, `kiosk`, `uber`, `pyszne`
**Type enum:** `delivery`, `takeaway`, `dine_in`
**Status enum:** `new`, `pending`, `ready`, `in_delivery`, `completed`, `cancelled`

### 9.3 Order Line Object (sh_order_items row)

```
id, order_id, menu_item_id (nullable),
snapshot_name, quantity, unit_price,
vat_rate (default 8.00, never populated by POS),
is_half, half_a_id, half_b_id
```

### 9.4 Product Mapping (Invoice → Warehouse)

```php
// api_mapping.php — save_mapping
$stmt = $pdo->prepare(
    "INSERT INTO sh_product_mapping (tenant_id, external_name, product_id) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)"
);
```

**Matching is case-insensitive:**

```javascript
// manager_pz.html
const match = dbMappings.find(m => m.external_name.toUpperCase() === val.toUpperCase());
```

Maps supplier invoice line names to internal `sh_products.id`.

### 9.5 Floor / Table ↔ Order Mapping

Dine-in orders store the **table number in the `address` field**:

```javascript
// waiter.html
const activeOrder = state.orders.find(
    o => o.type === 'dine_in'
      && o.address === t.table_number
      && o.status !== 'completed'
);
```

### 9.6 QR Token Generation (Tables)

```php
// api_floor.php
$qr_key = strtoupper("SH-QR-" . bin2hex(random_bytes(4)));
// Result: e.g. "SH-QR-A3F7B2C1"
```

### 9.7 Edge Cases & Bugs Found

| # | Edge Case | Location | Behavior |
|---|-----------|----------|----------|
| 1 | **Negative stock** | `api_pos.php` | Stock can go below zero. If `UPDATE` hits no row, a new row with **negative quantity** is inserted. No guard, no alert at POS time. |
| 2 | **Order number race condition** | `api_pos.php` | `COUNT(*) + 1` without locking. Concurrent orders can produce duplicate numbers. |
| 3 | **Total price not validated server-side** | `api_pos.php`, `api_online.php` | Client sends `total_price`; server stores it blindly. A modified request can set arbitrary totals. |
| 4 | **SQL injection in promised_time** | `api_online.php` | `$requested_time` is interpolated directly into SQL: `"'" . date('Y-m-d') . " " . $requested_time . ":00'"` |
| 5 | **Driver ID mismatch** | `api_driver.php` vs `api_delivery.php` | Driver app uses `$user_id` (sh_users.id) as `driver_id`, while dispatch uses `sh_drivers.id`. These differ. |
| 6 | **Kiosk PIN variable bug** | `kiosk.html` | Uses `enteredPin` but the variable is actually named `pin`. Fixed in `kiosk (1).html`. |
| 7 | **Broken waiter.html references** | `waiter.html` | `setOrderType()` and `init()` are called but **never defined** in the script. |
| 8 | **Hardcoded warehouse IDs** | `api_magazyn_pro.php` | Kitchen = `2`, Main = `1`. No dynamic warehouse resolution. |
| 9 | **Hardcoded tenant for online** | `api_online.php` | `$tenant_id = 1` — no multi-tenant online ordering. |
| 10 | **Stock quantity as string comparison** | `settings_magazyn.html` | `qty < 0` where `qty` is a string from `toFixed(3)` — lexicographic, not numeric comparison. |
| 11 | **Orphaned code blocks** | `api_magazyn_pro.php` | Dead code after `exit;` at line 316 — unreachable WZ generation block duplicated from `erp_pos_checkout`. |
| 12 | **waste_percent ignored in legacy** | `api_magazyn_pro.php` | The older `erp_pos_checkout` function uses `req_qty = r.quantity * check_item['qty']` **without** waste factor. The newer `api_pos.php` includes it. |
| 13 | **Removal matching by name** | `api_magazyn_pro.php` | Removed ingredients matched by UTF-8 name string comparison (`mb_strtolower`), not by ID. Name changes would break this. In `api_pos.php`, removal uses `product_id` comparison instead. |
| 14 | **Order edit returns stock then re-deducts** | `api_pos.php` | Full stock return of old cart → full deduction of new cart within same transaction. Correct logically, but expensive for unchanged items. |
| 15 | **Cancel with stock return** | `api_pos.php` | `cancel_order` with `return_stock=1` returns ingredients using the same recipe engine. If a recipe was modified after the sale, the return quantities will be **wrong**. |

### 9.8 Features Referenced But Not Implemented in Code

| Feature | Evidence | Status |
|---------|----------|--------|
| Customer loyalty / points | `loyalty_view (1).html` mock | Design only |
| SMS marketing campaigns | `marketing_view (1).html` mock | Design only |
| KSeF e-invoicing | `patch_ksef.php` adds `ksef_code` column | Schema only, no API client |
| KDS station routing | `sh_menu_items.kds_station_id` in v2 schema | Schema only |
| Delivery zones / geofencing | Referenced in UX mocks | Not implemented |
| Food cost / margin % | Referenced in Studio UI plans | Not implemented |
| Auto-dispatch drivers | Referenced in `order_handler_view` mock | Not implemented |
| Minimum order value | Common pattern, expected in online ordering | Not implemented |
| Business hours validation | Expected in online ordering | Not implemented |
| Payment gateway (Stripe/PayU) | Referenced in `order_handler_view` mock | Not implemented |
| Production batching | `prod_max (1).html` mock | Design only |
| Blind inventory count | `in_max.php` mock | Design only |

---

> **End of extraction.** This document covers ~146 files across the legacy codebase,
> with every discoverable algorithm, formula, and business rule extracted and categorized.
