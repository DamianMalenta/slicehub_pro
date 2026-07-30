# Sesja: 3 bugi odkryte podczas przeklikiwania (online checkout + POS empty cart + KDS routing)

**Data:** 2026-07-30
**Powiązane:** `2026-07-30_r1_r2_domkniecie.md` (poprzednia sesja), `2026-07-30_audit_sql_konstytucja_naruszenia.md`

---

## 1. Kontekst

Po resecie bazy + seedzie demo + domknięciu R1/R2, użytkownik zaczął przeklikiwać aplikację. Zgłoszony błąd: **"Order has no lines to route"** przy próbie potwierdzenia zamówienia online. Audyt `error.log` Apache wykazał 3 powiązane bugi.

---

## 2. Bug 1: WzEngine::checkAvailability — mixed named/positional params

### Objaw
```
[GuestCheckout.availability] SQLSTATE[HY093]: Invalid parameter number:
mixed named and positional parameters
```
w `error.log` przy każdym online checkout (referer: `modules/online/index.html`).

### Root cause
`core/WzEngine.php:706-715` — `checkAvailability()` mieszało parametry nazwane (`:tid`, `:wid`) z pozycyjnymi (`?` z `placeholders()`) w jednym prepared statement:
```php
// BEFORE (broken):
WHERE tenant_id = :tid AND warehouse_id = :wid AND sku IN ({$ph})
$stmt->execute(array_merge([$tenantId, $warehouseId], $skus));
```
PDO MySQL nie wspiera mieszania named + positional w jednym query → `SQLSTATE[HY093]`.

### Fix
```php
// AFTER (fixed):
WHERE tenant_id = ? AND warehouse_id = ? AND sku IN ({$ph})
$stmt->execute(array_merge([$tenantId, $warehouseId], $skus));
```
Wszystkie parametry pozycyjne (`?`). Spójne z istniejącym wzorcem w innych metodach WzEngine (linia 356-357 ma już komentarz: "PDO MySQL nie pozwala mieszać named (:tid) i positional (?) parameters").

### Wpływ
Online checkout (`guest_checkout`) — `WzEngine::checkAvailability` rzucał wyjątek przy każdej próbie. Checkout nie był blokowany (try/catch w `engine.php:1303` loguje error i kontynuuje), ale **warehouse preflight był wyłączony** — zamówienia przechodziły bez sprawdzenia stanu magazynowego.

**Plik:** `core/WzEngine.php:706-715`

---

## 3. Bug 2: POS pozwala tworzyć zamówienia z pustym koszykiem

### Objaw
Zamówienie `ORD/20260730/0017` (status `new`, `lines_count=0`, `cart_json=[]`) — utworzone przez POS z pustym koszykiem. Przy próbie akceptacji → `KdsAcceptRouting` rzuca "Order has no lines to route".

### Root cause
`api/pos/engine.php#process_order` (linia 577-581) — brak walidacji czy `$cart` jest niepusty przed utworzeniem zamówienia. Pusty koszyk przechodził przez cały flow (INSERT order header, INSERT 0 lines, commit).

### Fix
Dodany guard na początku `process_order` (poza inner try, z rollBack):
```php
if (!is_array($cart) || count($cart) === 0) {
    $pdo->rollBack();
    posResponse(false, null, 'Koszyk jest pusty — dodaj pozycje przed złożeniem zamówienia.');
}
```

### Test
`tests/test_runner.html` T56 — zmieniono z `success:true` (puste zamówienie OK) na `success:false` (pusty koszyk odrzucony). Stary test promował antywzorzec — puste zamówienia nie powinny istnieć.

**Plik:** `api/pos/engine.php:583-589`, `tests/test_runner.html:1452-1469`

---

## 4. Bug 3: KdsAcceptRouting rzuca wyjątek przy 0 linii

### Objaw
Komunikat "Order has no lines to route" przy akceptacji zamówienia z 0 linii (legacy/empty cart).

### Root cause
`core/KdsAcceptRouting.php:37-39` — rzucał `\InvalidArgumentException` gdy `count($lines) === 0`. Wyjątek propagował do `api/pos/engine.php#accept_order` (linia 1240-1242) → rollBack + error response. Zamówienie nie mogło być zaakceptowane.

### Fix
Zamiast rzucać wyjątek, zwraca pustą tablicę ticketów (graceful degradation):
```php
if (count($lines) === 0) {
    // Defensive: nie rzucaj wyjątku — skip KDS ticket creation.
    // Zamówienie bez linii (legacy/empty cart) nadal może być zaakceptowane,
    // po prostu nie ma ticketów kuchennych do routowania.
    return [];
}
```
Zamówienie bez linii może być zaakceptowane (np. do anulowania/rozliczenia), po prostu nie generuje ticketów KDS. `accept_order` w POS obsługuje puste `$kdsTickets` poprawnie (linia 1236: `$kdsTickets = []`).

### Wpływ
Defensive fix — chroni przed istniejącymi zamówieniami z 0 linii (stworzonymi przed Bug 2 fix). Nowe zamówienia z pustym koszykiem są blokowane przez Bug 2 fix, ale stare nadal mogą być zaakceptowane bez błędu.

**Plik:** `core/KdsAcceptRouting.php:34-42`

---

## 5. Weryfikacja

### Lint
```
php -l core/WzEngine.php           → No syntax errors detected
php -l core/KdsAcceptRouting.php   → No syntax errors detected
php -l api/pos/engine.php          → No syntax errors detected
```

### Testy E2E (62 suite, headless Chrome)
```
{ "pass": "61", "fail": "0", "warn": "1", "total": "62", "badge": "1 WARNINGS · 61 passed" }
```
Brak regresji. T56 zmieniony z `success:true` → `success:false` (pusty koszyk odrzucony). T58 (panic_mode debounce) — environmental (rate limiter 2-min, wymaga czyszczenia `sh_panic_log` między przebiegami).

### Smoke test online checkout
Po fixie Bug 1, `WzEngine::checkAvailability` wykonuje się bez `SQLSTATE[HY093]` — warehouse preflight działa poprawnie przy online checkout.

---

## 6. Pozostałe rekomendacje (status)

| ID | Priorytet | Status |
|----|-----------|--------|
| R1 | 🔴 wysoki | ✅ DOMKNIĘTE 2026-07-30 (poprzednia sesja) |
| R2 | 🔴 wysoki | ✅ DOMKNIĘTE 2026-07-30 (poprzednia sesja) |
| Bug 1 (WzEngine HY093) | 🔴 wysoki | ✅ DOMKNIĘTE 2026-07-30 |
| Bug 2 (POS empty cart) | 🔴 wysoki | ✅ DOMKNIĘTE 2026-07-30 |
| Bug 3 (KdsAcceptRouting throw) | 🟡 średni | ✅ DOMKNIĘTE 2026-07-30 |
| R3 | 🟡 średni | otwarte — edycja modyfikatorów w UI |
| R4 | 🟡 średni | otwarte — kalibracja progów marży |
| R5 | 🟡 średni | otwarte — bulk Food Cost Report |
| R6 | 🟢 niski | otwarte — estimate.php scheduled-picker |
| R7 | 🟢 niski | otwarte — UX brak receptury |
| R8 | 🟢 niski | otwarte — kitchen delta historia |
