# RAPORT — Zarządzanie obciążeniem lokalu i elastyczność menu

> Audyt repo: 2026-05-08, branch `projektx/raport-load-mgmt-elastycznosc-21ac`.
> Zakres: trzy mechanizmy „pod ręką managera" w trakcie ruchu — Panic Button,
> dynamiczna dostępność produktów oraz konfiguracja tenant-wide w
> `sh_tenant_settings`. Bez halucynacji — wszystko zacytowane z plików w repo.

---

## 1. PANIC BUTTON — globalne +20 min

### 1.1 Lokalizacja kanonicznej logiki

Mimo że istnieje plik `api/orders/panic.php`, w nagłówku wprost
zaznaczono, że jest **legacy duplicate** (audyt 2026-04-19) i kanon
mieszka w `api/pos/engine.php#panic_mode`:

```php
// STATUS: LEGACY DUPLICATE (audit 2026-04-19) — not wired from any frontend.
// Functionality already exposed via `api/pos/engine.php#panic_mode`.
// DECISION PENDING: confirm engine.php version has debounce + sh_panic_log,
// then delete this file. No external callers found.
```
(`api/orders/panic.php`, linie 3–7)

Czyli **kanon = `api/pos/engine.php`, akcja `panic_mode`** (POST, action-based REST,
zgodnie z §4 Konstytucji).

### 1.2 Pełna logika panic_mode (`api/pos/engine.php`, linie 1046–1069)

```php
if ($action === 'panic_mode') {
    $affected = $pdo->prepare(
        "UPDATE sh_orders SET promised_time = DATE_ADD(COALESCE(promised_time, created_at), INTERVAL 20 MINUTE), updated_at=NOW()
         WHERE status IN ('new','accepted','pending','preparing','ready') AND tenant_id = ?"
    );
    $affected->execute([$tenant_id]);
    $cnt = $affected->rowCount();

    $panicId = sprintf('%04x%04x-%04x-...', ... );
    $pdo->prepare(
        "INSERT INTO sh_panic_log (id, tenant_id, triggered_by, delay_minutes, affected_count)
         VALUES (?,?,?,20,?)"
    )->execute([$panicId, $tenant_id, $user_id, $cnt]);

    posResponse(true, ['message' => "Wydłużono czasy o 20 minut ($cnt zamówień)!"]);
}
```

Co dokładnie się dzieje (dosłowne odczytanie kodu):

1. **Bulk-shift `promised_time`** — `DATE_ADD(COALESCE(promised_time, created_at), INTERVAL 20 MINUTE)`
   na wszystkich zamówieniach tenanta w stanach pipeline'u kuchni:
   `new`, `accepted`, `pending` (legacy), `preparing`, `ready`.
   Bariera tenanta: `tenant_id = ?` (§2 — Multi-Tenancy).
2. **Audit-log do `sh_panic_log`** — UUID-v4, kto wcisnął (`triggered_by = $user_id`),
   sztywne `delay_minutes = 20`, ile zamówień zostało dotkniętych (`affected_count`).
3. **Sztywna stała 20 min** — wartość zaszyta w SQL (nie czyta z payloadu).
   Frontend potwierdza to też w UI.

Schemat tabeli (`database/migrations/001_init_slicehub_pro_v2.sql`, linie 472–484):

```sql
CREATE TABLE sh_panic_log (
  id             CHAR(36) NOT NULL,
  tenant_id      INT UNSIGNED NOT NULL,
  triggered_by   BIGINT UNSIGNED NULL,
  delay_minutes  INT NOT NULL,
  affected_count INT NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_panic_tenant_time (tenant_id, created_at),
  CONSTRAINT fk_panic_tenant FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id) ON DELETE CASCADE
)
```

### 1.3 Wariant „enterprise" w legacy duplikacie

`api/orders/panic.php` ma rozszerzoną logikę, ale nikt go nie wywołuje
(`audit 2026-04-19: not wired from any frontend`). Warto wiedzieć, co
stamtąd warto wciągnąć do kanonu jeśli kiedyś będzie refaktor:

- **Konfigurowalne `delay_minutes`** w payloadzie (5–60 min, default 20).
- **2-minutowy debounce** na `sh_panic_log` żeby nie zestackować klików:

  ```php
  "SELECT COUNT(*) FROM sh_panic_log
   WHERE tenant_id = :tid AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
  ```
  → HTTP 429 jeśli ktoś już panikował <2 min temu.
- **Transakcyjne UPDATE+INSERT** (`beginTransaction` / `commit`).
- **Zakres statusów węższy** (tylko `accepted`, `preparing`, `ready` — bez `new`/`pending`).
- **TODO real-time broadcast** — w komentarzu zarezerwowane miejsce na
  Redis Pub/Sub / SSE / WebSocket, dziś **nieaktywne**.

### 1.4 Wywoływanie z frontu (POS)

Topbar POS-a (`modules/pos/index.html`, linia 73):

```html
<button class="topbar-btn danger-subtle" id="btn-panic">
  <i class="fa-solid fa-triangle-exclamation"></i> PANIC (+20M)
</button>
```

Handler (`modules/pos/js/pos_app.js`, linie 91, 638–645):

```js
_on('#btn-panic', 'click', _triggerPanic);
…
async function _triggerPanic() {
    if (!confirm('Dodać +20 minut do wszystkich zamówień w toku?')) return;
    const r = await PosAPI.panicMode();
    if (r.success) { PosUI.toast('Wydłużono czasy o 20 minut!', 'success'); _fetchOrders(); }
}
```

Warstwa API klienta (`modules/pos/js/pos_api.js`, linia 47):

```js
panicMode: () => engine('panic_mode'),
```

Dodatkowo `PosApiOutbox.js` ma `'panicMode'` na białej liście akcji
synchronizowanych po reconnectcie (`api/pos/engine.php` jest celem
„resilient POS" §16 docs).

### 1.5 Propagacja do modułu Online — odpowiedź wprost

**Krótko: nie ma push-propagacji. Storefront/Online widzi efekt panic
poprzez ten sam wiersz `sh_orders.promised_time` przy najbliższym GET-cie.**

Konkrety, co potwierdza repo:

- `api/pos/engine.php#panic_mode` aktualizuje **wyłącznie** `sh_orders.promised_time`
  + dopisuje do `sh_panic_log`. **Nie woła ani SSE, ani webhooków, ani
  Redis Pub/Sub** — zero side-effects po stronie kanału Online.
- W legacy `api/orders/panic.php` propagacja real-time jest jawnie **TODO**:

  ```php
  // 5. TODO: BROADCAST REAL-TIME EVENT
  // When a real-time transport is wired (Redis Pub/Sub, SSE, WebSocket relay),
  // push this payload so every connected POS/KDS/Driver screen refreshes its
  // promised-time timers without a page reload.
  ```
  (`api/orders/panic.php`, linie 137–157)

- **Online Engine** (`api/online/engine.php`) nie pollu­je `sh_panic_log`
  ani nie odczytuje `delay_minutes` z `sh_tenant_settings`. Liczy własne
  ETA przez `core/PromisedTimeEngine.php`, czyli `base_prep_minutes` +
  load-factor wg liczby aktywnych zamówień (`status IN ('accepted','preparing')`)
  + bufor kanałowy (`delivery=15`, `takeaway=5`, `dine_in=0`) —
  `core/PromisedTimeEngine.php`, linie 17–82. Po panic'u kuchnia jest
  „cięższa" tylko o tyle, o ile nowe zamówienia zostaną dosztoplowane do
  istniejących `accepted/preparing` — load-factor liczy się **z liczby
  zamówień, nie z ich `promised_time`**, więc panic nie podbija ETA dla
  nowych checkoutów online.
- Tracking po stronie klienta — Online ma `tracking_token` na zamówieniu
  (`api/online/engine.php` linia 167: `SELECT tracking_token FROM sh_orders LIMIT 0`)
  i strona statusu zamówienia po prostu re-fetchuje wiersz `sh_orders` —
  zaktualizowane `promised_time` (po panic'u +20 min) zostanie zwrócone
  przy najbliższym polu z UI i tak zaobserwuje przesunięcie.

**Wniosek architektoniczny:** propagacja Panic→Online dziś jest
**pasywna / pull-based** przez wspólne źródło prawdy (`sh_orders.promised_time`).
Brak event-busa to znany dług (§137-157 w `panic.php` + komentarz
`[FF-HOOK]` w `OrderStateMachine.php`). Już istniejący zalążek to
`scripts/worker_driver_fanout.php`, który konsumuje eventy
(`employee.clocked_in/out`) — analogiczny wzorzec można wykorzystać do
broadcastu `panic_mode` w przyszłości.

---

## 2. DYNAMICZNE STATUSY DOSTĘPNOŚCI — „86 the item"

Hidowanie produktów w czasie rzeczywistym opiera się o **trzy
niezależne, kompozycyjne osie filtrów**, czytane przez Online Engine
przy każdym `get_menu` / `get_dish` (czyli efektywnie polling pull-based,
bez WS-a):

### 2.1 Oś A — flagi w `sh_menu_items` (manualny toggle managera)

Schemat (`database/migrations/001_init_slicehub_pro_v2.sql`, linie 122–159):

```sql
CREATE TABLE sh_menu_items (
  …
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,   -- ⬅ "ukryj/pokaż" 1-click
  is_deleted         TINYINT(1)   NOT NULL DEFAULT 0,   -- ⬅ soft-delete
  publication_status VARCHAR(32)  NULL,                 -- Draft / Published / Archived
  valid_from         DATE         NULL,                 -- Temporal Tables (sezonowe)
  valid_to           DATE         NULL,
  available_days     …            -- np. '1,2,3,4,5,6,7' (pn-nd) → patrz menu_studio
  available_start    TIME         NULL,                 -- np. lunch-only 11:00
  available_end      TIME         NULL,                 -- np. lunch-only 15:00
  is_secret          TINYINT(1)   NOT NULL DEFAULT 0,   -- ukryte z menu publicznego (POS-only)
  stock_count        INT          NULL DEFAULT 0,       -- twardy licznik ("87 left")
  is_locked_by_hq    TINYINT(1)   NOT NULL DEFAULT 0,   -- HQ-lock (franczyzy)
  …
);
```

Online Engine **konsekwentnie** filtruje po `is_active = 1 AND is_deleted = 0`:

```php
// api/online/engine.php, get_menu (linia 529)
WHERE mi.tenant_id = :tid AND mi.is_active = 1 AND mi.is_deleted = 0

// get_dish (linia 627)
WHERE mi.tenant_id = :tid AND mi.ascii_key = :sku
  AND mi.is_active = 1 AND mi.is_deleted = 0

// get_modifiers (linie 646–647)
mg.tenant_id = :tid AND mg.is_active = 1 AND mg.is_deleted = 0
JOIN sh_modifiers m ON m.group_id = mg.id AND m.is_active = 1 AND m.is_deleted = 0

// board_companions (linia 704)
bc.tenant_id = :tid AND bc.item_sku = :sku AND bc.is_active = 1
```

Konsekwencja: **manager wyłącza switch w Menu Studio → flag `is_active = 0`
→ kolejny `get_menu` z storefronta zwraca już bez tego SKU.** Latency
zależy od interwału fetchu/odświeżenia po stronie klienta — mechanizm
jest **stateless / poll-based**, brak push-eventu.

UI managera — `api/backoffice/api_menu_studio.php`:
- linia 1157 — listing z wybitym `is_active, stock_count` oraz `is_secret`,
- linia 1454 — `UPDATE sh_menu_items SET … is_active = ?, stock_count = ?, available_days = ?, available_start = ?, available_end = ? …`
- akcje `add_item` / `update_item_full` (linie 1378+): `isActive`, `availableDays`, `availableStart`, `availableEnd`, `stockCount`, `isSecret` jako kontrakt JSON.

### 2.2 Oś B — Temporal Tables (`publication_status` + `valid_from/to`)

Pole `publication_status` (`'Draft' | 'Published' | 'Archived'`) plus
para `valid_from / valid_to` to ramy czasowe pozycji menu. Konstytucja
§1 wymienia je jawnie:

> **Menu (Rdzeń):** `sh_menu_items` (klucz: `ascii_key`). Wspiera
> Temporal Tables (`publication_status`).

W repo Temporal Tables są obecne strukturą, ale Online Engine w
`get_menu` filtruje **tylko po `is_active`/`is_deleted`** — nie czyta
`valid_from/to`. To realny gap (do potencjalnego dopisania w refaktorze).

### 2.3 Oś C — magazyn jako prawda ostateczna (`WzEngine::checkAvailability`)

Dla pozycji z recepturą (`sh_recipes`) faktyczna dostępność = stan
magazynu (`wh_stock` przez most `sku`/`ascii_key` — §9). Egzekucja przy
checkoutcie online:

```php
// api/online/engine.php, linie 1169–1191
// 3.5. Warehouse preflight — stop online checkout if stock is already gone.
$availability = WzEngine::checkAvailability(
    $pdo, $tenantId, $checkoutWarehouse, $calc['lines_raw'] ?? []
);
if (($availability['available'] ?? true) === false) {
    onlineResponse(false, [
        'shortages'    => $availability['shortages'] ?? [],
        'warehouse_id' => $availability['warehouse_id'] ?? $checkoutWarehouse,
    ], 'Brak stanu magazynowego dla czesci skladnikow. Odswiez koszyk lub zmien pozycje.');
}
```

`WzEngine::checkAvailability` (`core/WzEngine.php`, linie 425–491):

1. `resolveDeductionsForPayloadLines` → liczy zużycie surowca z `sh_recipes`.
2. `SELECT sku, quantity FROM wh_stock WHERE tenant_id=… AND warehouse_id=… AND sku IN(…)`.
3. Zwraca tablicę `shortages[]` z dokładnym deficytem per surowiec.

To jest dynamiczny gate — pozycja w menu może mieć `is_active=1`, ale
przy checkoutcie zostanie odbita od ściany jeśli magazyn poszedł na zero.

### 2.4 Podsumowanie architektury hidingu

| Mechanizm | Tabela | Źródło zmiany | Latencja → Online |
|-----------|--------|---------------|-------------------|
| Manualny toggle "Hide" | `sh_menu_items.is_active` | Manager w Menu Studio | Najbliższy `get_menu` (pull) |
| Soft-delete | `sh_menu_items.is_deleted` | Manager / migracje | j.w. |
| Sezony / promocje | `publication_status`, `valid_from/to` | HQ / Manager | **niewdrożone w `get_menu`** (gap) |
| Day-parting (śniadania, lunch) | `available_days`, `available_start/end` | Manager | **niewdrożone w `get_menu`** (gap; pole zapisane w `update_item_full`) |
| Stock-count (per item) | `sh_menu_items.stock_count` | Manager / POS | **nie filtruje w `get_menu`** — tylko zwracane do klienta |
| Magazyn (receptury) | `wh_stock` przez `sku` | Sprzedaż / dokumenty WH | Hard-block w checkoutcie (`WzEngine`) |

**Kluczowy wniosek:** "ukrywanie produktów w czasie rzeczywistym" działa
dziś w pełni dla **Osi A (`is_active`)** i **Osi C (magazyn na checkoutcie)**.
Osie B i day-parting są w schemacie i Menu Studio (pisze do nich), ale
Online Engine ich nie egzekwuje — to zidentyfikowany dług produktowy.

---

## 3. `sh_tenant_settings` — flagi managera nad cyklem życia zamówienia

### 3.1 Schemat tabeli (dwurolowy)

`database/migrations/001_init_slicehub_pro_v2.sql`, linie 65–81:

```sql
CREATE TABLE sh_tenant_settings (
  tenant_id               INT UNSIGNED NOT NULL,
  setting_key             VARCHAR(64)  NOT NULL DEFAULT '',
  is_active               TINYINT(1)   NULL DEFAULT 1,
  min_order_value         INT          NULL DEFAULT 0 COMMENT 'Grosze',
  opening_hours_json      JSON         NULL,
  min_prep_time_minutes   INT          NULL DEFAULT 30,
  sla_green_min           INT          NULL DEFAULT 10,
  sla_yellow_min          INT          NULL DEFAULT 5,
  base_prep_minutes       INT          NULL DEFAULT 25,
  min_lead_time_minutes   INT          NULL DEFAULT 30,
  setting_value           VARCHAR(255) NULL COMMENT 'KV rows (e.g. half_half_surcharge)',
  PRIMARY KEY (tenant_id, setting_key),
  CONSTRAINT fk_tenant_settings_tenant FOREIGN KEY (tenant_id) REFERENCES sh_tenant (id) ON DELETE CASCADE
)
```

Tabela ma **dwie role** — udokumentowane w `_docs/04_BAZA_DANYCH.md` (linie 34–36):

> - `setting_key = ''` → kolumny SLA (`min_prep_time_minutes`, `sla_green_min`, …)
> - `setting_key = '<klucz>'` → czyste KV (`setting_value`)

### 3.2 Flagi cyklu życia zamówienia (rola SLA / wiersz `setting_key=''`)

Te kolumny manager edytuje per lokal i mają **realny wpływ na pipeline zamówienia**:

| Kolumna | Default | Kto czyta | Wpływ na cykl zamówienia |
|---------|---------|-----------|---------------------------|
| `is_active` | 1 | `api/gateway/intake.php` (l. 277), `_docs/10_GATEWAY_API.md` step 7 | **Master switch lokalu** — `is_active=0` → `TENANT_ACTIVE` gate w gateway → wszystkie inbound zamówienia (online, integracje) odbijane. |
| `min_order_value` (grosze) | 0 | `api/gateway/intake.php`, `_docs/10_GATEWAY_API.md` step 9 | Minimalna wartość koszyka (skip dla kiosk). |
| `opening_hours_json` | NULL | `core/PromisedTimeEngine.php` (l. 55–65, `validateBusinessHours`); `api/online/engine.php` `get_doorway` (l. 387) | Bramka godzin pracy — `STORE_CLOSED` w intake; status `open / closing_soon / closed` w storefront. Ten sam JSON służy też do wyliczenia `nextOpenAt`. |
| `min_prep_time_minutes` | 30 | `api/orders/sla_monitor.php` (l. 55) | Twarda minimalna „obietnica" prep'u (cooking floor) — używane do detekcji breachu SLA. |
| `sla_green_min` | 10 | `sla_monitor.php` | Próg SLA „zielony" (KDS / Dispatcher). |
| `sla_yellow_min` | 5 | `sla_monitor.php` | Próg SLA „żółty" — alert 86 (§6 — `text-red-500 font-bold` po przekroczeniu). |
| `base_prep_minutes` | 25 | `core/PromisedTimeEngine.php::calculate` (l. 63, 80) | Bazowy czas przygotowania, mnożony przez **load-factor** (`1 + active_orders/20`, max 2.0×). To jest fundament obietnicy ETA dla kanałów online/POS. **Edytowalne z UI od 2026-08-24 (PR #65):** Online Studio → Storefront → „Czas zamówień (PromisedTime)" (5–120 min). |
| `min_lead_time_minutes` | 30 | `core/PromisedTimeEngine.php::calculate` (l. 64, 98–104) | Minimum „w przyszłość" dla zamówień scheduled — chroni kuchnię przed „daj za 5 minut" o 17:55. **Edytowalne z UI od 2026-08-24 (PR #65):** Online Studio → Storefront → „Czas zamówień (PromisedTime)" (15–240 min). |

### 3.3 Flagi KV (oddzielne wiersze `setting_key=<nazwa>`)

Flagi czysto kluczowe (`setting_value` jako VARCHAR — manager je
przełącza w panelu ustawień):

#### Storefront / Online (sklep konsumencki)

Czytane głównie przez `api/online/engine.php` + `api/online_studio/engine.php` +
`scripts/cron_reorder_nudge.php`:

- `storefront_tagline`, `storefront_address`, `storefront_city`,
  `storefront_phone`, `storefront_email`, `storefront_lat`, `storefront_lng` —
  identyfikacja lokalu w doorway/checkoutcie.
- `storefront_channels_json` — JSON-array dostępnych kanałów
  (`["delivery","takeaway","dine_in"]`) → blokuje wybór order_type.
- `storefront_preorder_enabled` (`'0'/'1'`) — czy klient może w ogóle
  zamówić scheduled.
- `storefront_preorder_min_lead_minutes` — minimalny lead-time pre-order
  (uzupełnia `min_lead_time_minutes` per-channel).
- `storefront_surface_bg` — UI (background atelier) — bez wpływu na cykl.
- `storefront_name` — nazwa marki (Notification dispatcher, cron reorder nudge).

#### Defaulty modułu Online (migracja 017)

`database/migrations/017_online_module_extensions.sql`, linie 130–166:

- `online_min_order_value` — alternatywny per-kanał minimum (legacy/duplikat `min_order_value`).
- `online_default_eta_min` — fallback ETA wyświetlane w UI.
- `online_guest_checkout` (`'1'/'0'`) — czy guest może checkoutować bez konta.
- `online_apple_pay_enabled` (`'1'/'0'`) — feature flag bramki płatności.
- `online_promotion_banner` — string promocyjny na storefroncie.

#### Pricing / Half & Half

- `half_half_surcharge` — opłata za pizzę half&half (`api/cart/CartEngine.php`
  l. 385–386). Default `'2.00'` (seedowany w 001_init_… l. 711–712).
  Formuła z `_docs/OPTIMIZED_CORE_LOGIC_V2.md` l. 177:
  `max(priceA, priceB) + half_half_surcharge`.

#### Integracje zewnętrzne

- `papu_api_key` (`api/pos/engine.php` l. 724–725) — credential bramki
  Pyszne/Papu (czytany do wywołania zewnętrznego).

#### HR / Drivery (event-driven)

- `HR_USE_EVENT_DRIVER_FANOUT` (`'1'/'0'`, default OFF) —
  `scripts/worker_driver_fanout.php` (l. 27, 74). **Per-tenant
  feature-flag** decydujący czy `employee.clocked_in/out` ma fluktuować
  `sh_drivers.status`. Polityka: `busy` NIGDY nie nadpisany (kierowca w
  trasie nie traci kursu po clock_out). Patrz `_docs/18_BACKOFFICE_HR_LOGIC.md`.

#### AI Budget

`scripts/setup_database.php`, linie 716–720:

- `ai_monthly_budget_zl` (default `'50.00'`) — twardy budżet AI per tenant.
- `ai_current_month_spent_zl` — licznik bieżący.
- `ai_budget_reset_at` — timestamp resetu.

#### Feature-flags zamówień (zarezerwowane, nieaktywne)

`core/OrderStateMachine.php` linie 422–448 — `loadTenantFlags()`:

```php
$stmt = $pdo->prepare(
    "SELECT setting_value FROM sh_tenant_settings
     WHERE tenant_id = :tid AND setting_key = 'feature_flags'"
);
…
return $decoded;  // { skip_kitchen, auto_complete, disable_kds, … }
```

Loader istnieje, ale w komentarzu (l. 18–22):

> When `sh_tenant_settings` gains a JSON `feature_flags` column, load it…

Mapa flag jaką planowano (komentarze `[FF-HOOK]` rozsiane po `pos/engine.php`):
- `skip_kitchen` — pomijaj `preparing/ready`, ide bezpośrednio do dispatch.
- `auto_complete` — auto-close po dispatch.
- `disable_kds` — orders nie trafiają na ekran KDS.
- `skip_dispatch` — zamówienia delivery zamykane bez ridera.
- `auto_stock_return` — przy `cancel_order` automatyczna reverse-deduction WH.

To są **zaplanowane** przełączniki managera nad cyklem życia, ale obecnie
loader zawsze zwraca `[]` lub odczytany JSON (czyli można je już
wpisać ręcznie do bazy, choć UI nie istnieje).

### 3.4 Tabelka „uprawnienia managera nad cyklem życia zamówienia"

| Etap pipeline'u | Konfigurowalne przez tenant_settings |
|-----------------|--------------------------------------|
| **Otwarcie/zamknięcie lokalu** | `is_active`, `opening_hours_json` |
| **Akceptacja zamówienia (intake)** | `min_order_value`, `online_min_order_value`, `storefront_channels_json`, `online_guest_checkout` |
| **Pre-order / scheduled** | `min_lead_time_minutes` (UI: Online Studio → Storefront), `storefront_preorder_enabled`, `storefront_preorder_min_lead_minutes` |
| **Wycena (`promised_time`)** | `base_prep_minutes` (× load-factor; UI: Online Studio → Storefront), `min_prep_time_minutes`, `online_default_eta_min` |
| **Pricing zaawansowany** | `half_half_surcharge` |
| **Monitorowanie SLA** | `sla_green_min`, `sla_yellow_min` |
| **Globalna interwencja kuchni** | (nie z `tenant_settings` — z `sh_panic_log` przez `panic_mode`) |
| **Płatności / kanały płatności** | `online_apple_pay_enabled` |
| **Driver auto-fanout** | `HR_USE_EVENT_DRIVER_FANOUT` |
| **Pipeline overrides (planowane)** | `feature_flags` JSON: `skip_kitchen`, `auto_complete`, `disable_kds`, `skip_dispatch`, `auto_stock_return` |
| **Marketing / banner** | `online_promotion_banner` |
| **Branding** | `storefront_*` (kontakt, mapa, surface_bg, tagline) |
| **AI budget** | `ai_monthly_budget_zl`, `ai_current_month_spent_zl`, `ai_budget_reset_at` |

---

## 4. Wnioski końcowe

1. **Panic Button** żyje w `api/pos/engine.php#panic_mode` (kanon),
   z legacy duplikatem w `api/orders/panic.php` (do usunięcia po
   spięciu transakcji + debounce + payloadowego `delay_minutes`).
   Sztywne 20 min, audit do `sh_panic_log`.
2. **Brak push-propagacji do Online** — Online dostaje aktualne
   `promised_time` przez ten sam `sh_orders` przy najbliższym fetchu.
   Real-time bus jest zaplanowany (TODO w `panic.php` + worker
   `worker_driver_fanout.php` jako wzorzec).
3. **Hiding produktów** dziś działa pewnie po `is_active`/`is_deleted`
   (Online Engine) + `WzEngine::checkAvailability` (magazyn). Reszta
   wymiarów (`publication_status`, `valid_from/to`, day-parting,
   `stock_count`) jest w schemacie i Menu Studio, ale Online Engine ich
   nie egzekwuje — **to zidentyfikowany dług produktowy, nie bug
   pojedynczy**.
4. **`sh_tenant_settings`** jest hybryda — wiersz `setting_key=''`
   trzyma SLA/prep/godziny pracy (kolumny natywne), pozostałe wiersze
   to KV. Dziewięć grup tematycznych (Storefront, Online module,
   Pricing, Integracje, HR, AI, feature-flags planowane).
   Loader `OrderStateMachine::loadTenantFlags` jest gotowy na
   `feature_flags` JSON — UI managera do tego jeszcze nie istnieje.

---

_Plik wygenerowany w ramach audytu „Zarządzanie obciążeniem lokalu i
elastyczność menu", branch `projektx/raport-load-mgmt-elastycznosc-21ac`._
