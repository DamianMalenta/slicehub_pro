# Sesja: F1 — Pętla zużycia POS↔Magazyn

**Data:** 2026-05-11 (po sesji `constitution_v5` tego samego dnia)
**Czas trwania:** ~1.5h
**Architekt:** AI (Cloud Agent) z udziałem właściciela
**Branch:** `projektx/phase-f1-consume-loop-c3d7`

---

## 1. Cel

Domknąć krytyczną lukę zidentyfikowaną w audycie: `WzEngine::consumeForOrder` ma kompletny kod, ale **0 call-sitów** w produkcyjnym przepływie. Konsekwencja: sprzedaż przez POS / online **nie zmniejszała stanów magazynowych** — system zafałszowywał food cost, AVCO i alerty stanu minimalnego.

To była pozycja #1 na liście `@planned` w Konstytucji v5 § Prawo VIII (Domknięcie Kontraktu).

Architektoniczny zamiar autora (cytat z `_docs/02_ARCHITEKTURA.md`):
> „WzEngine = Zużycie surowców po acceptance"

Czyli hook miał być w momencie tranzycji statusu `→ accepted`, nie w `settle_and_close`.

---

## 2. Pliki dotknięte

| Plik | Co zmieniono |
|---|---|
| `core/WarehouseConsumeHook.php` (NEW) | Helper post-commit hook z resolverem warehouse_id (input → `sh_tenant_settings.orders_default_warehouse_id` → fallback `'MAIN'`). Defensywne try/catch — failure NIE blokuje akceptu. Special-casing dla "no recipe" (skipped flag). |
| `api/pos/engine.php` | Hook wywołany w `accept_order` PO `$pdo->commit()` outer transakcji. Dodany blok `warehouse_consume` w response. |
| `api/orders/accept.php` | Analogiczny hook PO `$pdo->commit()`. Response rozszerzone o `warehouse_consume`. |
| `core/WzEngine.php` | **NAPRAWA 3 BUG-ÓW PDO** (mieszanie named `:tid` + positional `?` w jednym query → `SQLSTATE[HY093]`). Linie ~178, ~219, ~593. Plus zdjęcie `@planned` z docblock-a, dodany opis wpięcia + wyniki testów E2E. |
| `_docs/01_KONSTYTUCJA.md` | Lista `@planned` w § Prawo VIII — `consumeForOrder` przekreślone z notatką o domknięciu. |
| `_docs/02_ARCHITEKTURA.md` | Tabela engines: WzEngine zaktualizowany, dorzucony `WarehouseConsumeHook.php`. |
| `_docs/00_PAMIEC_SYSTEMU.md` | Lista `@planned` w skrócie Prawa VIII zaktualizowana (sekcja "Domknięte"). |
| `.cursorrules` | § 10 Prawo VIII — ta sama aktualizacja co w North Star. |

---

## 3. Decyzje architektoniczne

### 3.1 Hook w callerach (B), nie w OSM (A)

**Alternatywa A:** dodać hook do `OrderStateMachine::transitionOrder` jako side-effect po pomyślnej tranzycji do `'accepted'`.

**Alternatywa B (wybrana):** hook wywoływany **w callerach** PO `$pdo->commit()`, używając helpera `core/WarehouseConsumeHook`.

**Powód:** Konstytucja v5 § Prawo VI (Snajper). Wpinanie konsumpcji do OSM rozszerza odpowiedzialność klasy (state machine + warehouse hook = mixing concerns). Caller (POS / orders/accept) ma kontekst (warehouse_id z payload, user_id) i kontroluje moment wywołania (PO commit-cie, nie wewnątrz transakcji). 2 callery do utrzymania = manageable. W przyszłości jeśli powstanie 3-ci, można zrefaktoryzować na hook registry.

### 3.2 Hook PO commit-cie outer transakcji

**Powód kluczowy:** `WzEngine::consumeForOrder` ma własną `$pdo->beginTransaction()`. Nested transaction w PDO MySQL nie wspiera prawdziwej izolacji bez savepoint-ów. Czystsze: outer (status + KDS tickets + outbox event) commituje, hook startuje własną niezależną transakcję dla magazynu.

**Konsekwencja biznesowa:** failure hook'a NIE cofa akceptu zamówienia. Zamówienie jest semantycznie zaakceptowane (KDS dostał ticket, manager kliknął przycisk, klient widzi promised_time), kuchnia gotuje. Jeśli magazyn padnie (np. brak receptury, ujemny stan, deadlock) — manager naprawi korektą (KOR engine) później. Loggujemy alert do `error_log`.

To jest **świadoma decyzja**: lepiej zaakceptowane zamówienie z niewykonaną dedukcją magazynu (rozwiązywalne) niż klient czekający z pizzą bo magazyn padł (błąd UX).

### 3.3 Naprawa 3 bug-ów PDO przy okazji (Prawo VI w działaniu)

Pierwszy uruchomiony test wykazał `SQLSTATE[HY093]: Invalid parameter number: mixed named and positional parameters`. WzEngine miał w 3 miejscach:

```php
WHERE tenant_id = :tid     -- named
  AND menu_item_sku IN ({$ph})  -- positional (?)
```

PDO MySQL nie wspiera tego mixa. Naprawa: zamiana `:tid` na pierwsze `?` z `array_merge([$tenantId], $skus)` w execute.

**Co ważne:** te bug-i siedziały też w `checkAvailability` (linia 590), które JEST już wpięte produkcyjnie w online checkout. Czyli każdy online checkout przepuszczany przez `checkAvailability` z half-half albo composite SKU **rzucał exception** który był łapany w generic try/catch w `online/engine.php:1187` i logowany — ale availability **nigdy nie zwracało prawdziwego wyniku**. Klient mógł kupować pizze bez stanów. To bug który Prawo VIII miało wykryć — teraz naprawiony przy okazji F1.

Konstytucja § Prawo VI Snajper: napraw `A`, zostaw `B`. **Zmiana ścisła: tylko 3 miejsca z literalnie tym bug-iem**, reszta WzEngine nietknięta.

### 3.4 Resolver warehouse_id

Wzorzec skopiowany z istniejącego `api/online/engine.php:1101`:
1. Z payload `input['warehouse_id']` (jeśli caller przekazał).
2. Z `sh_tenant_settings.setting_key='orders_default_warehouse_id'`.
3. Fallback `'MAIN'`.

`sh_orders` nie ma kolumny `warehouse_id` (sprawdzone w bazie). Dorzucanie kolumny = invasive migration. Lepiej zostawić current pattern + ewentualne rozszerzenie do `sh_orders.warehouse_id` w kolejnej sesji jeśli multi-warehouse stanie się aktualnym scenariuszem operacyjnym.

### 3.5 Special-case "no recipe" → skipped, nie error

Klient może mieć w menu pozycje bez receptury (np. promo, gift card, kawa kupowana z półki). `consumeForOrder` zwraca wtedy `error: 'No stock deductions computed — recipes may not be configured.'`. Klasyfikacja jako error w response byłaby myląca dla managera ("zamówienie odrzucone" — nie odrzucone).

**Decyzja:** hook rozpoznaje ten typ "błędu" przez `str_contains($errorMsg, 'recipes may not be configured')` i zwraca `success=false, skipped=true` zamiast `error`. UI może wtedy wyciszyć alert.

---

## 4. Otwarte pytania

### 4.1 Reverse hook (KOR) przy `cancel` po `accepted`

Obecnie: `transitionOrder('cancelled')` po accept-cie nie zwraca surowców do magazynu. To powoduje że anulowane zamówienia "zjadają" stany.

Plan: F1.5 albo F2 — analogiczny `WarehouseConsumeHook::onOrderCancelled` wywołujący `KorEngine::correctForOrder` (jeśli istnieje). Sprawdzić czy `KorEngine` ma symetryczną metodę albo trzeba dorobić.

### 4.2 Half-Half + composite "A+B" — test produkcyjny

Konstytucja § II wymaga multiplier × 0.5 dla half-half. Kod WzEngine to obsługuje (linie 243-275). W obecnym seedzie nie ma zamówień half-half, więc test E2E pokrył tylko `multiplier = 1.0`.

Plan: w F2 (AutoScan) lub osobnej sesji wystawić order half-half manualnie i potwierdzić × 0.5 spadek per ingredient.

### 4.3 Multi-warehouse routing

Obecny resolver: 1 warehouse per tenant (default). W przyszłości tenant z 2 lokalami = 2 magazyny → potrzebne `sh_orders.warehouse_id` lub mapping `order_type / location_id → warehouse_id`. Decyzja architektoniczna otwarta — koreluje z planowaną M046 (multi-loc, sh_locations).

### 4.4 Performance pod wysokim obciążeniem

Hook synchroniczny w `accept_order` dodaje ~50-200ms (zależnie od liczby linii). Dla 1 lokalu OK. Przy 10+ lokali z dużym ruchem (>30 accept/s) lepsze jako async event `order.accepted` → worker. Wymaga cron-a (nie ma na shared hostingu uti.pl). Zostawiamy sync do czasu rzeczywistych problemów performance — zgodnie z Prawem VI Snajper, nie optymalizujemy bez dowodu na problem.

### 4.5 Co dalej?

Sesja F2 — **Shared AutoScan Engine**. Wyciągnięcie logiki z `studio_recipe.js` do `core/AutoScanEngine.php` + endpoint `api/procurement/suggest.php` + confidence scoring. To podstawa pod F3 (KSeF Inbox UI) i F4 (KSeF API client).

---

## Test (E2E)

### Setup
- Lokalna MariaDB 10.11.14 (uti.pl-equivalent)
- `seed_demo_all.php` uruchomione → 4 userów, 33 menu, 44 receptur, 43 surowce, 43 stany
- `manager` user dorzucony manualnie (PIN 0000, hash bcrypt "password")

### Test 1: orders/accept.php (drugi caller, REST endpoint)

**Order:** T4 Pepperoni (pizza z 5 składnikami: MKA_TIPO00, OPAK_PIZZA, PEPP_SALAMI, SER_MOZZ, SOS_POM)

**Wynik:**
```
ok= True
warehouse_consume.success= True
warehouse_consume.doc_number= WZ/2026/05/11/00005
warehouse_consume.total_cost= 11.56 PLN
warehouse_consume.deductions = {
  MKA_TIPO00: 0.255,    SER_MOZZ: 0.18,   SOS_POM: 0.1,
  PEPP_SALAMI: 0.08,    OPAK_PIZZA: 1.0
}
```

**Weryfikacja matematyczna (formuła Konstytucji § II):**
| SKU | base_qty | waste% | multiplier | needed (formuła) | actual (z bazy) | OK |
|---|---|---|---|---|---|---|
| MKA_TIPO00 | 0.25 | 2.0 | 1.0 | 0.255 | 0.255 | ✓ |
| OPAK_PIZZA | 1.0 | 0.0 | 1.0 | 1.000 | 1.000 | ✓ |
| PEPP_SALAMI | 0.08 | 0.0 | 1.0 | 0.080 | 0.080 | ✓ |
| SER_MOZZ | 0.18 | 0.0 | 1.0 | 0.180 | 0.180 | ✓ |
| SOS_POM | 0.10 | 0.0 | 1.0 | 0.100 | 0.100 | ✓ |

`wh_documents.id=5` (typ WZ, status approved), `wh_document_lines` × 5, `wh_stock_logs` × 5.

### Test 2: pos/engine.php#accept_order (pierwszy caller, POS)

**Order:** D7 Quattro Formaggi (pizza z 6 składnikami)

**Wynik:**
```
ok= True
warehouse_consume.success= True
warehouse_consume.doc_number= WZ/2026/05/11/00006
warehouse_consume.total_cost= 12.51 PLN
warehouse_consume.deductions count= 6
```

`wh_stock` spadek per SKU zgodny z formułą:
- MKA_TIPO00: 49.745 → 49.49 (Δ -0.255 ← 0.25 × 1.02 × 1.0) ✓
- OPAK_PIZZA: 199.0 → 198.0 (Δ -1.0) ✓
- SER_CHEDDAR: 4.00 → 3.96 (Δ -0.04) ✓
- SER_GORG: 2.00 → 1.95 (Δ -0.05) ✓
- SER_MOZZ: 18.32 → 18.20 (Δ -0.12) ✓
- SER_PARM: 1.80 → 1.76 (Δ -0.04) ✓

### Test 3: lint
- ✅ `php -l core/WarehouseConsumeHook.php`
- ✅ `php -l core/WzEngine.php`
- ✅ `php -l api/pos/engine.php`
- ✅ `php -l api/orders/accept.php`

---

**Status sesji: ✅ DONE.** Krytyczna luka domknięta. `consumeForOrder` przekreślone z listy `@planned`. WzEngine/checkAvailability bug naprawiony przy okazji (3 miejsca PDO mixed params).

Następna sesja zgodnie z planem: **F2 — Shared AutoScan Engine** (przygotowanie pod KSeF Inbox).
