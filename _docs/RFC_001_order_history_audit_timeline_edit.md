# RFC-001: Ujednolicony moduł Historii, Osi Czasu (Audit Timeline) i Bezpiecznej Edycji Zamówień

**Data:** 2026-08-25
**Status:** DRAFT — oczekuje akceptacji przed wdrożeniem Fazy 1
**Tryb:** Architectural Design & Documentation Audit (read-only)
**Autor:** Devin (na zlecenie Ownera)
**Powiązane:** `_docs/sessions/2026-07-30_phase_e_order_edit_kds_delta.md` (Faza E — edycja aktywnych), `_docs/audits/fiscalization_status.md` (fiskalizacja Elzab), `_docs/00_PAMIEC_SYSTEMU.md` §459 (WzEngine), `AGENTS.md` §14 (architektura)

---

## 0. STRESZCZENIE WYKONAWCZE

SliceHub posiada rozbudowaną infrastrukturę audytową (3 tabele logujące + pełne snapshoty JSON w outboxie) oraz silnik różnicowy `DeltaEngine`, ale **brakuje warstwy odczytowej i UI** które by je wykorzystywały. Ten RFC definiuje ujednolicony moduł Historii Zamówień z 3-poziomowym modelem bezpieczeństwa edycji:

- **Poziom 1** (zamówienia otwarte `new..ready`): Pełna edycja pozycji przez `DeltaEngine` → `kitchen_delta` — **już działa** (Faza E, 2026-07-30).
- **Poziom 2** (metadane zamkniętych `completed/cancelled`): Bezpieczna korekta danych niefiskalnych (klient, telefon, adres, uwagi) + zmiana formy płatności `cash ↔ card` przed raportem dobowym — **NOWE**.
- **Poziom 3** (korekta pozycji w zamkniętym/sfiskalizowanym): Twarde bezpieczniki — `is_corrected`, snapshot przed zmianą, korekta magazynowa `WarehouseReverseHook` + `WzEngine`, blokada cichej edycji — **NOWE**.

Oś czasu (Audit Timeline) agregowana jest **w locie** z 3 istniejących tabel — **zero nowych tabel**.

---

## 1. WYNIKI SKANU DOKUMENTACJI (`_docs/`)

### 1.1. Co już zostało spisane/zaplanowane

| Dokument | Co opisuje | Jak wykorzystujemy |
|----------|-----------|-------------------|
| `_docs/sessions/2026-07-30_phase_e_order_edit_kds_delta.md` | Faza E: edycja aktywnych zamówień przez `edit.php` + `DeltaEngine` → `kitchen_delta` dla KDS. DOMKNIĘTE. | **Baza Poziomu 1.** Rozszerzamy `edit.php` o Poziomy 2 i 3. |
| `_docs/audits/fiscalization_status.md` | Fiskalizacja Elzab Zeta Online (2026-07-29). `fiscal_receipt_number` w `sh_orders` (migracja 062). Guard blokuje podwójną fiskalizację. `receipt_printed=1` = paragon wydrukowany. | **Bezpiecznik Poziomu 3.** Jeśli `fiscal_receipt_number IS NOT NULL` → edycja pozycji wymaga korekty fiskalnej (paragon korygujący). |
| `_docs/00_PAMIEC_SYSTEMU.md` §459 | `WzEngine::consumeForOrder` konsumuje surowce po `accept_order` (post-commit, `WarehouseConsumeHook`). | **Bezpiecznik Poziomu 3.** Edycja pozycji zamkniętego zamówienia wymaga korekty magazynowej: `WarehouseReverseHook` (KOR) + re-konsumpcja. |
| `_docs/02_ARCHITEKTURA.md` §345 | `WarehouseReverseHook` — symetryczny hook do ConsumeHook. Wywoływany z `cancel_order` gdy zamówienie anulowane PO `accepted`. Tworzy `wh_documents` typ `KOR`, zwraca surowce. | **Reutilizacja dla Poziomu 3.** Ten sam hook można wywołać przy korekcie pozycji zamkniętego zamówienia. |
| `_docs/00_PAMIEC_SYSTEMU.md` §510 | `OrderEventPublisher::publishOrderLifecycle()` — auto-snapshot order header + lines w payloadzie eventu. | **SSOT dla Osi Czasu.** Każdy event (`order.created`, `order.edited`, `order.completed`) ma pełny snapshot w `sh_event_outbox.payload`. |
| `_docs/01_KONSTYTUCJA.md` §91 | `WzEngine::consumeForOrder` DOMKNIĘTE F1. `WarehouseConsumeHook` w `accept_order`. | Potwierdza że konsumpcja magazynowa jest aktywna — korekta musi przez KOR. |
| `_docs/PROMPT_SPARK_WNIOSEK_OD_ZERA.md` §56 | "Reverse stock on cancel — anulowanie zamówienia automatycznie tworzy KOR, surowce wracają na stan." | Wyróżnik konkurencyjny — korekta zamkniętego zamówienia musi zachować tę symetrię. |
| `_docs/audits/bi_foundation_audit.md` §119-123 | Ryzyko wielokrotnego WZ na jedno zamówienie — `wh_documents.order_id` bez unikalnego indeksu. `WzEngine` nie sprawdza "czy WZ już istnieje". | **Ostrzeżenie dla Poziomu 3.** Korekta pozycji musi sprawdzić czy WZ istnieje i czy KOR został już utworzony. |
| `_docs/sessions/2026-05-11_phase_f5_pos_integrity_and_f6_geocoder.md` | F5-C: `WarehouseReverseHook::onOrderCancelled` — call-site w `cancel_order`. Reverse tylko z pierwszego warehouse_id. | **Adaptacja.** Nowa metoda `WarehouseReverseHook::onOrderCorrected($orderId, $delta)` dla korekty częściowej. |
| `_docs/00_PAMIEC_SYSTEMU.md` §578 | Raport dobowy: `fiscal_daily_report` z poziomu POS topbar. | **Bezpiecznik Poziomu 2.** Zmiana `cash ↔ card` możliwa tylko jeśli raport dobowy nie został jeszcze wykonany dla dnia zamówienia. |
| `_docs/audits/bi_foundation_audit.md` | Pełny audyt fundamentu BI: `BiEngine` czyta `sh_orders` (`status='completed'`, `created_at` okno) + `wh_documents` (`type='WZ'`) + `sh_payroll_ledger` + `sh_expenses`. Ryzyko wielokrotnego WZ. KOR z KSeF Inbox nie przelicza AVCO. | **Krytyczne dla Poziomu 3.** Korekta pozycji zamkniętego → KOR magazynowy musi być wliczony do COGS (obecnie BiEngine filtruje tylko `type='WZ'`). Reopen zmienia `status` → zamówienie znika z P&L. |
| `_docs/sessions/2026-05-14_bi_engine.md` | Faza BI: `BiEngine::generateDashboard` — COGS/OPEX/net sales, `stock_value_minor`. `api/bi/dashboard_data.php` wrapper HTTP, RBAC owner/admin/manager. | **Podstawa analizy wpływu.** BiEngine czyta live dane, nie snapshoty — korekty natychmiast wpływają na P&L. |
| `_docs/sessions/2026-05-21_bi_opex_flow_from_pr28.md` | BI OPEX flow: `opex_by_category`, `capital_flow`, `prime_cost_*`, rozbicie brutto/VAT. | OPEX i labor niezależne od korekt zamówień — brak wpływu. |
| `core/BiEngine.php:14-15` | Komentarz: "revenue, VAT, and COGS-from-WZ use `sh_orders.created_at`, not `updated_at`, so late edits do not shift revenue across accounting periods." | **Korzystne:** korekty nie przesuwają revenu do innego okresu P&L. Ale korekta `grand_total` zmienia bieżący P&L dla dnia `created_at`. |

### 1.2. Czego w dokumentacji NIE MA (luki do uzupełnienia)

1. **Brak planu Historii Zamówień** — żadny dokument nie opisuje widoku listy wszystkich zamówień (z `completed`/`cancelled`).
2. **Brak planu Osi Czasu** — `sh_order_audit` i `sh_order_logs` są opisane jako "audit trail" ale nigdzie nie ma planu endpointu ani UI które by je czytały.
3. **Brak planu Reopen** — `OrderStateMachine::STRICT_TRANSITIONS` ma `completed => []` i `cancelled => []` (terminalne). Brak dokumentu opisującego procedurę ponownego otwarcia.
4. **Brak planu Undo/Revert** — `HistoryStack.js` istnieje w Online Studio ale nigdzie nie jest planowane zastosowanie dla zamówień.
5. **Brak planu edycji metadanych zamkniętych** — `edit.php:94` hardcodo blokuje `completed`/`cancelled` bez rozróżnienia na metadane vs pozycje.

### 1.3. Zestawienie wytycznych z faktycznym stanem kodu

| Wytyczna z `_docs/` | Stan w kodzie | Zgodność |
|---------------------|---------------|----------|
| Faza E: edycja aktywnych przez DeltaEngine | `edit.php` + `DeltaEngine.php` + `hub_order_edit.js` + `order_edit_app.js` | ✅ Działa |
| `kitchen_delta` dla KDS | `sh_orders.kitchen_delta` JSON + `kds_app.js` highlight | ✅ Działa |
| `OrderEventPublisher` auto-snapshot | `publishOrderLifecycle()` w każdej transakcji | ✅ Działa, ale nie czytane |
| `sh_order_audit` audit trail | INSERT w OSM, edit.php, process_order, inbound | ✅ Działa, ale nie czytane |
| `sh_order_logs` structured log | `writeLog()` w OSM | ✅ Działa, ale nie czytane |
| `WarehouseReverseHook` KOR | `onOrderCancelled` w `cancel_order` | ✅ Działa, ale tylko dla cancel |
| Fiskalizacja Elzab + `fiscal_receipt_number` | `ElzabFiscalEngine` + migracja 062 | ✅ Działa |
| `receipt_printed` flaga | `print_receipt` akcja + SettlementEngine | ✅ Działa |
| Edycja zamkniętych | `edit.php:94` blokuje 409 | ❌ Brak |
| Historia zamówień (widok) | Brak endpointu + UI | ❌ Brak |
| Oś czasu (audit timeline) | Brak endpointu + UI | ❌ Brak |
| Undo/revert | Brak mechanizmu | ❌ Brak |

---

## 2. MAPA ROZSZERZENIA KODU

### 2.1. Pliki istniejące — rozszerzane

| Plik | Obecnie | Rozszerzenie |
|------|---------|-------------|
| `api/orders/edit.php` | Poziom 1 (edycja pozycji aktywnych). Blokuje `completed`/`cancelled` 409. | + Poziom 2 (metadane zamkniętych) + Poziom 3 (korekta pozycji z `force_edit`). Parametr `edit_scope: 'lines' \| 'metadata' \| 'force'`. |
| `api/orders/get_for_edit.php` | `list_orders` filtruje `status NOT IN ('completed','cancelled')`. | + Nowa akcja `list_all_orders` (bez filtra terminalnych, z paginacją + filtrami). |
| `modules/hub/js/hub_order_edit.js` | Modal edycji aktywnych. Lista zamówień z `list_orders`. | + Przełącznik "Pokaż zamknięte" → `list_all_orders`. + Pola metadanych (klient, telefon, adres). + Przycisk "Oś czasu" → timeline drawer. |
| `modules/hub/index.html` | Kafel "Edytuj zamówienie" → `HubOrderEdit.open()`. | + Kafel "Historia zamówień" → `HubOrderHistory.open()`. |
| `core/OrderStateMachine.php` | `STRICT_TRANSITIONS`: `completed => []`, `cancelled => []`. | + Metoda `reopenOrder()` (tylko owner/admin) z `writeLog('reopen', {reason})`. Nowa transition: `completed → pending` z flagą `force`. |
| `core/WarehouseReverseHook.php` | `onOrderCancelled($orderId)` — pełny KOR przy cancel. | + `onOrderCorrected($orderId, $delta)` — częściowy KOR dla zmienionych/usuniętych linii + re-konsumpcja dla dodanych. |
| `core/BiEngine.php` | `aggregateCogsMinor` filtruje `wd.type = 'WZ'` — KOR nie wliczany do COGS. | (Faza 3) `wd.type = 'WZ'` → `wd.type IN ('WZ', 'KOR')` — KOR z ujemną wartością redukuje COGS. + Pole `gross_revenue_corrected_minor` (SUM gdzie `is_corrected = 1`). |

### 2.2. Nowe pliki — minimalne, odczytowe (Faza 1)

| Plik | Typ | Opis |
|------|-----|------|
| `api/orders/history.php` | Backend (read-only) | Lista wszystkich zamówień z filtrami + paginacją. Agreguje z `sh_orders` + `sh_order_lines` (count) + `sh_order_audit` (last action). |
| `api/orders/audit.php` | Backend (read-only) | Oś czasu per zamówienie: agregacja z `sh_order_audit` + `sh_order_logs` + `sh_event_outbox` w jeden chronologiczny strumień DTO. |
| `modules/hub/js/hub_order_history.js` | Frontend (Vanilla JS IIFE) | Widok tabeli historii + filtry + drawer z osią czasu. Reutilizacja `HistoryStack.js` logiki (labels, cursor). |
| `modules/hub/css/hub_order_history.css` | CSS | Style tabeli, filtrów, drawera, timeline. |

### 2.3. Nowe pliki — modyfikujące (Fazy 2-3)

| Plik | Typ | Opis |
|------|-----|------|
| `api/orders/revert.php` | Backend (mutacja) | Cofnięcie do snapshotu z `sh_event_outbox`. Wymaga owner/admin. Zapisuje snapshot obecny + przywraca stan. |
| `core/OrderReopenEngine.php` | Core | Bezpieczna procedura reopen: walidacja fiskalna + magazynowa + audit. Wywołuje `WarehouseReverseHook` jeśli potrzeba. |

### 2.4. Zasada minimalizmu

- **Zero nowych tabel** w Fazie 1 (agregacja w locie z 3 istniejących).
- **Jedna nowa kolumna** w Fazie 3: `sh_orders.is_corrected TINYINT(1) NOT NULL DEFAULT 0` (migracja 068) — flaga że zamknięte zamówienie było korygowane.
- **Jedna nowa transition** w OSM: `completed → pending` (reopen, tylko z `force=true`).

---

## 3. STRATEGIA TIMELINE & HISTORII (SSOT)

### 3.1. Algorytm agregacji chronologicznej z 3 tabel

Oś czasu per zamówienie (`api/orders/audit.php?action=timeline&order_id=...`) agreguje w locie z 3 źródeł:

```
WEJŚCIE:
  A) sh_order_audit  — {id, order_id, user_id, old_status, new_status, timestamp}
  B) sh_order_logs   — {id, order_id, tenant_id, user_id, action, detail_json, created_at}
  C) sh_event_outbox — {id, tenant_id, event_type, aggregate_id, payload, source, actor_type, actor_id, created_at}

ALGORYTM:
  1. SELECT z A WHERE order_id = :oid ORDER BY timestamp ASC
  2. SELECT z B WHERE order_id = :oid AND tenant_id = :tid ORDER BY created_at ASC
  3. SELECT z C WHERE aggregate_id = :oid AND tenant_id = :tid ORDER BY created_at ASC
  4. UNIFY → sortuj po timestamp (created_at ≈ timestamp)
  5. MAP na ujednolicony DTO:

WYJŚCIE — TimelineEvent DTO:
  {
    ts:           "2026-08-25T14:30:00+02:00",
    source:       "osm" | "edit" | "pos" | "online" | "kds" | "courses" | "gateway" | "system",
    actor_type:   "staff" | "system" | "external_api" | "guest",
    actor_id:     123 | null,
    actor_name:   "Jan Kowalski" | "System" | "ChoiceQR" | null,
    event_type:   "status_change" | "edit" | "payment" | "create" | "complete" | "cancel" | "reopen" | "revert" | "force_edit",
    label_pl:     "Utworzono zamówienie" | "Zmiana statusu: new → accepted" | "Edycja pozycji" | ...,
    old_status:   "new" | null,
    new_status:   "accepted" | null,
    detail:       { ... } | null,
    snapshot:     { header: {...}, lines: [...] } | null,   ← z sh_event_outbox.payload
    delta:        { added: [...], removed: [...], modified: [...] } | null  ← z payload._context.kitchen_delta
  }
```

### 3.2. Słownik etykiet PL (kanoniczny)

| `event_type` | `label_pl` |
|--------------|-----------|
| `order.created` | Utworzono zamówienie |
| `order.accepted` | Zaakceptowano zamówienie |
| `order.preparing` | Rozpoczęto przygotowanie |
| `order.ready` | Zamówienie gotowe |
| `order.completed` | Zamówienie zakończone |
| `order.cancelled` | Zamówienie anulowane |
| `order.edited` | Edycja pozycji zamówienia |
| `order.recalled` | Wycofanie z kuchni (recall) |
| `order.dispatched` | Wysłano dostawcą |
| `order.in_delivery` | W trakcie dostawy |
| `order.delivered` | Dostarczone |
| `payment.change` | Zmiana formy płatności |
| `metadata.edit` | Edycja metadanych (klient/telefon/adres) |
| `force_edit` | Wymuszona korekta pozycji (zamknięte) |
| `reopen` | Ponowne otwarcie zamówienia |
| `revert` | Cofnięcie do stanu ze snapshotu |
| `fiscal.print` | Wydruk paragonu fiskalnego |
| `fiscal.daily_report` | Raport dobowy (Z-report) |
| `wh.consume` | Konsumpcja magazynowa (WZ) |
| `wh.reverse` | Korekta magazynowa (KOR) |

### 3.3. Wykorzystanie snapshotów z `sh_event_outbox` do audytu i cofania

**Kluczowa obserwacja:** `OrderEventPublisher::publishOrderLifecycle()` zapisuje **pełny snapshot** order header + lines w `sh_event_outbox.payload` przy **każdym** zdarzeniu biznesowym. To jest naturalna historia stanów.

```
Snapshots w sh_event_outbox dla order_id = X:
  [0] order.created   → snapshot stanu PO utworzeniu
  [1] order.accepted  → snapshot stanu PO akceptacji (magazyn skonsumowany)
  [2] order.edited    → snapshot stanu PO edycji (delta w payload._context.kitchen_delta)
  [3] order.ready     → snapshot stanu PO przygotowaniu
  [4] order.completed → snapshot stanu PO zakończeniu (fiskalizacja w payload)

Undo do stanu [2] (przed drugą edycją):
  1. SELECT payload FROM sh_event_outbox WHERE aggregate_id = X AND event_type = 'order.edited' ORDER BY created_at ASC LIMIT 1 OFFSET 1
  2. Parsuj snapshot.header + snapshot.lines
  3. POST api/orders/revert.php { order_id: X, snapshot_event_id: ... }
  4. revert.php:
     a. Zapisz obecny stan jako nowy snapshot w sh_order_logs (action='revert', detail_json={reverted_from, reverted_to})
     b. Przywróć linie przez DELETE + INSERT z snapshotu
     c. Przywróć header (status, payment_status, grand_total, ...)
     d. Jeśli zamknięte: wywołaj WarehouseReverseHook dla linii zmienionych
     e. Opublikuj order.recalled przez OrderEventPublisher
     f. COMMIT
```

### 3.4. Adaptacja `HistoryStack.js` dla UI

`modules/online_studio/js/director/state/HistoryStack.js` jest klasą generyczną undo/redo. Logika (push/undo/redo/labels/cursor) zostaje **skopiowana i zaadaptowana** do `hub_order_history.js`:

```js
// hub_order_history.js — adaptacja HistoryStack
class OrderTimelineStack {
    constructor(events) {           // events = TimelineEvent[] z audit.php
        this._stack = events.map(e => ({
            snapshot: e.snapshot,
            label: e.label_pl,
            ts: e.ts,
            event_id: e.event_id,
        }));
        this._cursor = this._stack.length - 1;  // kursor na ostatnim (obecnym)
    }
    // canUndo(), canRedo(), undo(), redo(), current(), labels() — identyczne jak HistoryStack
}
```

UI: pionowa oś czasu z punktami. Klik na punkt → podgląd snapshotu. Przycisk "Przywróć ten stan" (tylko owner/admin) → `revert.php`.

---

## 4. KONTRAKTY API (JSON REQUEST / RESPONSE)

### 4.1. `api/orders/history.php` — Lista wszystkich zamówień

**Request:**
```
POST /api/orders/history.php
Authorization: Bearer <token>
Content-Type: application/json

{
  "action": "list",
  "filters": {
    "date_from": "2026-08-01",        // ISO date, opcjonalne
    "date_to": "2026-08-25",          // ISO date, opcjonalne
    "status": ["completed", "cancelled"],  // opcjonalne, domyślnie wszystkie
    "order_type": ["delivery", "takeaway"], // opcjonalne
    "payment_status": ["cash", "card"],     // opcjonalne, słownik kanoniczny
    "source": ["pos", "online"],            // opcjonalne
    "search": "Kowalski",              // szukaj w order_number, customer_name, customer_phone
    "fiscalized": true                 // true = tylko z fiscal_receipt_number, false = bez, null = wszystkie
  },
  "pagination": {
    "page": 1,
    "per_page": 50                     // max 200
  },
  "sort": {
    "field": "created_at",            // created_at | grand_total | status
    "dir": "desc"
  }
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "orders": [
      {
        "id": "550e8400-...",
        "order_number": "POS/20260825/0042",
        "status": "completed",
        "order_type": "delivery",
        "channel": "Delivery",
        "source": "pos",
        "payment_status": "cash",
        "payment_method": "cash",
        "grand_total_formatted": "53.00",
        "customer_name": "Jan Kowalski",
        "customer_phone": "+48 600 100 200",
        "fiscal_receipt_number": "000123",
        "receipt_printed": true,
        "is_corrected": false,
        "line_count": 3,
        "created_at": "2026-08-25 14:30:00",
        "updated_at": "2026-08-25 15:45:00",
        "last_action_label": "Zamówienie zakończone",
        "last_action_ts": "2026-08-25 15:45:00"
      }
    ],
    "pagination": {
      "page": 1,
      "per_page": 50,
      "total": 342,
      "total_pages": 7
    }
  }
}
```

**Bezpieczeństwo:**
- `WHERE tenant_id = :tid` bezwzględnie (Prawo VI).
- Wymaga roli `owner`/`admin`/`manager` (auth_guard).
- `payment_status` walidowane przeciw słownikowi kanonicznemu (`to_pay`/`online_unpaid`/`cash`/`card`/`online_paid`).

### 4.2. `api/orders/audit.php` — Oś czasu per zamówienie

**Request:**
```
POST /api/orders/audit.php
Authorization: Bearer <token>
Content-Type: application/json

{
  "action": "timeline",
  "order_id": "550e8400-..."
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "order_id": "550e8400-...",
    "order_number": "POS/20260825/0042",
    "timeline": [
      {
        "event_id": "evt_001",
        "ts": "2026-08-25T14:30:00+02:00",
        "source": "pos",
        "actor_type": "staff",
        "actor_id": 12,
        "actor_name": "Jan Kowalski",
        "event_type": "order.created",
        "label_pl": "Utworzono zamówienie",
        "old_status": null,
        "new_status": "new",
        "detail": null,
        "snapshot": {
          "header": { "status": "new", "payment_status": "to_pay", "grand_total": 5300, "..." : "..." },
          "lines": [ { "item_sku": "PIZZA_MARGHERITA", "quantity": 1, "..." : "..." } ]
        },
        "delta": null
      },
      {
        "event_id": "evt_002",
        "ts": "2026-08-25T14:32:00+02:00",
        "source": "pos",
        "actor_type": "staff",
        "actor_id": 12,
        "actor_name": "Jan Kowalski",
        "event_type": "order.accepted",
        "label_pl": "Zaakceptowano zamówienie",
        "old_status": "new",
        "new_status": "accepted",
        "detail": null,
        "snapshot": { "header": { "..." : "..." }, "lines": [ "..." ] },
        "delta": null
      },
      {
        "event_id": "evt_003",
        "ts": "2026-08-25T14:35:00+02:00",
        "source": "edit",
        "actor_type": "staff",
        "actor_id": 5,
        "actor_name": "Anna Nowak",
        "event_type": "order.edited",
        "label_pl": "Edycja pozycji zamówienia",
        "old_status": "accepted",
        "new_status": "accepted",
        "detail": { "kitchen_delta": { "added": 1, "removed": 0, "modified": 1 } },
        "snapshot": { "header": { "..." : "..." }, "lines": [ "..." ] },
        "delta": { "added": [ { "snapshot_name": "Cola 0.5L", "quantity": 1 } ], "modified": [ "..." ] }
      },
      {
        "event_id": "evt_004",
        "ts": "2026-08-25T15:45:00+02:00",
        "source": "pos",
        "actor_type": "staff",
        "actor_id": 12,
        "actor_name": "Jan Kowalski",
        "event_type": "order.completed",
        "label_pl": "Zamówienie zakończone",
        "old_status": "ready",
        "new_status": "completed",
        "detail": { "fiscal_receipt_number": "000123" },
        "snapshot": { "..." : "..." },
        "delta": null
      }
    ],
    "can_revert": true,
    "can_reopen": true,
    "can_force_edit": true
  }
}
```

**Agregacja (SQL):**
```sql
-- A: sh_order_audit
SELECT id, order_id, user_id, old_status, new_status, timestamp
FROM sh_order_audit WHERE order_id = :oid ORDER BY timestamp ASC;

-- B: sh_order_logs
SELECT id, order_id, user_id, action, detail_json, created_at
FROM sh_order_logs WHERE order_id = :oid AND tenant_id = :tid ORDER BY created_at ASC;

-- C: sh_event_outbox (snapshoty)
SELECT id, event_type, payload, source, actor_type, actor_id, created_at
FROM sh_event_outbox WHERE aggregate_id = :oid AND tenant_id = :tid ORDER BY created_at ASC;
```

**Mapowanie w PHP:**
- `sh_order_audit` → `event_type='status_change'`, `old_status`/`new_status` z kolumn, `actor_name` z JOIN `sh_users`.
- `sh_order_logs` → `event_type` = `action`, `detail` = `detail_json` (decoded), `actor_name` z JOIN.
- `sh_event_outbox` → `event_type` = `event_type`, `snapshot` = `payload` (decoded), `source`/`actor_type`/`actor_id` z kolumn.
- Unify → sort po `ts` → map na `TimelineEvent` DTO z `label_pl` ze słownika §3.2.

### 4.3. `api/orders/edit.php` — Rozszerzenie o Poziomy 2 i 3

**Request — Poziom 2 (metadane zamkniętego):**
```json
{
  "order_id": "550e8400-...",
  "edit_scope": "metadata",
  "metadata": {
    "customer_name": "Jan Kowalski (poprawione)",
    "customer_phone": "+48 600 200 300",
    "delivery_address": "ul. Nowa 5, 00-001 Warszawa",
    "comment": "Klient prosił o kontakt po dostawie"
  }
}
```

**Bezpieczniki Poziomu 2:**
- Wymaga roli `owner`/`admin`/`manager`.
- Status musi być `completed` lub `cancelled`.
- **NIE edytuje:** pozycji, `grand_total`, `payment_status` (chyba że `cash ↔ card` — patrz niżej), `fiscal_receipt_number`, `receipt_printed`.
- Zapis do `sh_order_logs`: `action='metadata.edit'`, `detail_json={field, old, new}`.
- Publikuje `order.metadata_edited` przez `OrderEventPublisher`.

**Request — Poziom 2 (zmiana formy płatności):**
```json
{
  "order_id": "550e8400-...",
  "edit_scope": "payment_method",
  "payment_status": "card",
  "payment_method": "card",
  "reason": "Klient zapłacił kartą, nie gotówką"
}
```

**Bezpieczniki zmiany płatności:**
- Wymaga `owner`/`admin`/`manager`.
- Status = `completed`.
- `fiscal_receipt_number` musi być `NULL` (paragon fiskalny jeszcze nie wydrukowany) **LUB** raport dobowy nie wykonany dla dnia zamówienia.
- Dozwolone tylko: `cash ↔ card` (nie można zmienić na `online_paid` — to wymaga integracji).
- Zapis do `sh_order_logs`: `action='payment.change'`, `detail_json={old, new, reason}`.
- Aktualizuje `sh_order_payments` (nowy wpis payment, stary oznaczony jako `reversed`).

**Request — Poziom 3 (korekta pozycji zamkniętego/sfiskalizowanego):**
```json
{
  "order_id": "550e8400-...",
  "edit_scope": "force",
  "force_edit": true,
  "reason": "Klient zwrócił pizzę — usunięcie pozycji + zwrot",
  "lines": [
    { "line_id": "abc-123", "item_sku": "PIZZA_MARGHERITA", "quantity": 1 }
  ]
}
```

**Bezpieczniki Poziomu 3:**
- Wymaga roli `owner`/`admin`.
- `reason` obowiązkowe (min 10 znaków).
- **Snapshot przed zmianą** zapisywany w `sh_order_logs`: `action='pre_force_edit_snapshot'`, `detail_json={header, lines}`.
- Jeśli `receipt_printed = 1` lub `fiscal_receipt_number IS NOT NULL`:
  - Oznacza `is_corrected = 1` na `sh_orders`.
  - Loguje `action='force_edit'` z `reason`.
  - **Nie drukuje** paragonu korygującego automatycznie — manager robi to ręcznie przez `_fiscalReprint` z `force=true`.
- Jeśli zamówienie było `accepted` (magazyn skonsumowany):
  - `WarehouseReverseHook::onOrderCorrected($orderId, $delta)` — KOR dla usuniętych/zmienionych linii.
  - `WzEngine::consumeForOrder` dla dodanych linii (re-konsumpcja).
- `DeltaEngine::computeDelta` jak zwykle — delta zapisywana w `kitchen_delta`.
- Publikuje `order.force_edited` przez `OrderEventPublisher`.

**Response (wszystkie poziomy):**
```json
{
  "success": true,
  "data": {
    "order_id": "550e8400-...",
    "edit_scope": "force",
    "grand_total": "45.00",
    "delta": { "added": [], "removed": [ { "snapshot_name": "Pizza Margherita", "quantity": 1 } ], "modified": [] },
    "is_corrected": true,
    "wh_kor_document": "KOR/20260825/0003",
    "audit_logged": true
  }
}
```

### 4.4. `api/orders/revert.php` — Cofnięcie do snapshotu (Faza 3)

**Request:**
```json
{
  "order_id": "550e8400-...",
  "snapshot_event_id": 12345,
  "reason": "Błędna edycja — cofamy do stanu po akceptacji"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "order_id": "550e8400-...",
    "reverted_to_ts": "2026-08-25T14:32:00+02:00",
    "reverted_to_event": "order.accepted",
    "new_snapshot_event_id": 12350,
    "wh_kor_document": "KOR/20260825/0004"
  }
}
```

---

## 5. PROJEKT UI W HUB / BACKOFFICE

### 5.1. Widok tabeli historii (`hub_order_history.js`)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  HISTORIA ZAMÓWIEŃ                                              [Filtruj ▼] │
├─────────────────────────────────────────────────────────────────────────────┤
│  Od: [2026-08-01]  Do: [2026-08-25]  Status: [Wszystkie ▼]  Szukaj: [____] │
│  Typ: [Wszystkie ▼]  Płatność: [Wszystkie ▼]  Źródło: [Wszystkie ▼]        │
│  ☐ Tylko sfiskalizowane  ☐ Tylko skorygowane                                │
├──────┬──────────────────┬──────────┬───────────┬──────────┬────────┬───────┤
│ Nr   │ Klient           │ Status   │ Typ       │ Płatność │ Total  │ Czas  │
├──────┼──────────────────┼──────────┼───────────┼──────────┼────────┼───────┤
│ 0042 │ Jan Kowalski     │ ✓ Zakoń. │ Dostawa   │ Gotówka  │ 53.00  │ 14:30 │
│ 0041 │ Anna Nowak       │ ✓ Zakoń. │ Wynos     │ Karta    │ 28.00  │ 14:15 │
│ 0040 │ Piotr Wiśniewski │ ✗ Anulow.│ Dostawa   │ Online   │ 45.00  │ 14:02 │
│ 0039 │ Stolik 5         │ ⚠ Skor.  │ Sala      │ Gotówka  │ 120.00 │ 13:45 │
│ ...  │ ...              │ ...      │ ...       │ ...      │ ...    │ ...   │
├──────┴──────────────────┴──────────┴───────────┴──────────┴────────┴───────┤
│  Strona 1 z 7  [← Poprzednia]  [Następna →]              [50 / 200 na str] │
└─────────────────────────────────────────────────────────────────────────────┘
```

- Klik na wiersz → otwiera Drawer (§5.2).
- Badge "⚠ Skor." = `is_corrected = 1`.
- Badge "✓ Zakoń." = `completed`, "✗ Anulow." = `cancelled`.
- Kolor wiersza: zielony = `completed`, czerwony = `cancelled`, żółty = `is_corrected`.

### 5.2. Szuflada boczna (Drawer) ze szczegółami + Oś Czasu

```
┌──────────────────────────────────────────────────┐
│  ZAMÓWIENIE #POS/20260825/0042           [×]    │
│  Status: ✓ Zakończone  |  53.00 zł  |  14:30    │
├──────────────────────────────────────────────────┤
│  KLIENT                                         │
│  Jan Kowalski                                   │
│  +48 600 100 200                                │
│  ul. Marszałkowska 12, 00-001 Warszawa          │
│                                                 │
│  PŁATNOŚĆ                                       │
│  Gotówka (cash)  |  Paragon fisk.: 000123       │
│                                                 │
│  POZYCJE (3)                                    │
│  • Pizza Margherita × 1     28.00 zł           │
│  • Cola 0.5L × 1             8.00 zł           │
│  • Dostawa                  17.00 zł           │
│                                                 │
│  [Edytuj metadane]  [Edytuj pozycje (force)]    │
│  [Cofnij do snapshotu]  [Otwórz ponownie]       │
├──────────────────────────────────────────────────┤
│  OŚ CZASU                                       │
│                                                 │
│  ●─── 14:30  Utworzono zamówienie              │
│  │     Jan Kowalski (kelner)                    │
│  │     Snapshot: 3 linie, 53.00 zł              │
│  │                                              │
│  ●─── 14:32  Zaakceptowano zamówienie          │
│  │     Jan Kowalski (kelner)                    │
│  │     Magazyn: WZ/20260825/0012 skonsumowany   │
│  │                                              │
│  ●─── 14:35  Edycja pozycji zamówienia         │
│  │     Anna Nowak (manager)                     │
│  │     +Cola 0.5L ×1  ~Margherita: qty 2→1     │
│  │     [Przywróć ten stan]                      │
│  │                                              │
│  ●─── 15:45  Zamówienie zakończone             │
│  │     Jan Kowalski (kelner)                    │
│  │     Paragon fisk.: 000123                    │
│  │                                              │
│  ●─── 15:46  Wydruk paragonu fiskalnego        │
│        System                                   │
│                                                 │
└──────────────────────────────────────────────────┘
```

**Interakcje:**
- Klik na punkt osi czasu → rozwija szczegóły (snapshot, delta, actor).
- **[Przywróć ten stan]** — tylko dla owner/admin, tylko dla eventów z snapshotem. Confirm dialog → `revert.php`.
- **[Edytuj metadane]** — otwiera formularz (klient, telefon, adres, uwagi) → `edit.php` z `edit_scope: 'metadata'`.
- **[Edytuj pozycje (force)]** — otwiera edytor linii (jak `hub_order_edit.js`) → `edit.php` z `edit_scope: 'force'`. Wymaga `reason`.
- **[Otwórz ponownie]** — `completed → pending` przez `OrderReopenEngine`. Wymaga `reason`.
- Przyciski widoczne zależnie od roli + statusu + flag (`can_revert`, `can_reopen`, `can_force_edit` z `audit.php`).

### 5.3. Style i responsywność

- Drawer: 480px szerokość na desktopie, full-width na mobile.
- Tabela: scroll horizontal na mobile, kolumny zwijane (ukryj telefon/adres na <768px).
- Oś czasu: pionowa, punkty 12px, linia 2px, kolor punktów zależny od `event_type` (zielony=create, niebieski=accept, żółty=edit, czerwony=cancel, fioletowy=reopen/revert).
- Reutilizacja CSS z `hub_order_edit.css` (modale, karty, badge).

---

## 6. HARMONOGRAM WDRAŻANIA W FAZACH

### FAZA 1: Silnik historii, `audit.php` i widok w Hubie (READ-ONLY — 0% ryzyka regresji)

**Cel:** Pełna widoczność historii zamówień i osi czasu bez jakiejkolwiek modyfikacji danych.

**Zakres:**
1. `api/orders/history.php` — endpoint listy wszystkich zamówień (z `completed`/`cancelled`), filtry, paginacja.
2. `api/orders/audit.php` — endpoint osi czasu (agregacja z 3 tabel w locie).
3. `modules/hub/js/hub_order_history.js` — UI tabeli + drawer + oś czasu.
4. `modules/hub/css/hub_order_history.css` — style.
5. `modules/hub/index.html` — kafel "Historia zamówień".

**Bezpieczeństwo:**
- **Zero mutacji** — oba endpointy są read-only (SELECT).
- **Zero nowych tabel** — agregacja w locie z istniejących.
- **Zero zmian w istniejących plikach produkcyjnych** — tylko nowe pliki + 1 kafel w `index.html`.
- Wymaga roli `owner`/`admin`/`manager`.

**Weryfikacja:**
- `php -l` na 2 nowych backendach.
- Headless test runner: 62/62 PASS (brak regresji — nowe endpointy nie dotykają istniejących).
- Manualny smoke: lista zamówień pokazuje `completed`/`cancelled`, oś czasu pokazuje zdarzenia z 3 źródeł.

**Ryzyko:** 0% — czysty dodatek read-only.

---

### FAZA 2: Bezpieczna edycja metadanych zamkniętych zamówień + korekty formy płatności

**Cel:** Manager może poprawić dane klienta i zmienić `cash ↔ card` na zamkniętym zamówieniu (przed raportem dobowym).

**Zakres:**
1. `api/orders/edit.php` — rozszerzenie o `edit_scope: 'metadata'` i `edit_scope: 'payment_method'`.
2. `modules/hub/js/hub_order_history.js` — przyciski "Edytuj metadane" w drawerze.
3. Walidacja: `fiscal_receipt_number IS NULL` LUB raport dobowy nie wykonany (dla zmiany płatności).
4. `sh_order_logs` logowanie każdej zmiany metadanych (`action='metadata.edit'`, `action='payment.change'`).
5. `OrderEventPublisher::publishOrderLifecycle` z nowymi event types: `order.metadata_edited`, `order.payment_changed`.

**Bezpieczeństwo:**
- **NIE dotyka** pozycji, `grand_total`, `fiscal_receipt_number`, `receipt_printed`.
- **NIE dotyka** magazynu (metadane nie wpływają na WzEngine).
- Zmiana `cash ↔ card` aktualizuje `sh_order_payments` (nowy wpis + stary `reversed`).
- Wymaga `owner`/`admin`/`manager`.

**Weryfikacja:**
- `php -l` na `edit.php`.
- Test: edycja metadanych na `completed` → 200 OK, `sh_order_logs` wpis, `sh_orders` zaktualizowane pola.
- Test: zmiana `cash → card` na sfiskalizowanym (z `fiscal_receipt_number`) → 409 odrzucone.
- Test: zmiana `cash → card` na nie-fiskalizowanym → 200 OK, `sh_order_payments` zaktualizowane.
- Headless test runner: 62/62 PASS.

**Ryzyko:** Niskie — edycja tylko niefiskalnych pól, walidacja blokuje fiskalne.

---

### FAZA 3: Zaawansowane korekty pozycji, procedura reopen i mechanizm undo

**Cel:** Owner/admin może korygować pozycje zamkniętego zamówienia (z KOR magazynowym), otwierać ponownie i cofać do snapshotu.

**Zakres:**
1. `api/orders/edit.php` — rozszerzenie o `edit_scope: 'force'` z `WarehouseReverseHook::onOrderCorrected`.
2. `api/orders/revert.php` — nowy endpoint cofania do snapshotu z `sh_event_outbox`.
3. `core/OrderReopenEngine.php` — bezpieczna procedura `completed → pending` (OSM + KOR + audit).
4. `core/WarehouseReverseHook.php` — nowa metoda `onOrderCorrected($orderId, $delta)`.
5. `core/OrderStateMachine.php` — nowa transition `completed → pending` z `force=true`.
6. Migracja 068: `sh_orders.is_corrected TINYINT(1) NOT NULL DEFAULT 0`.
7. `modules/hub/js/hub_order_history.js` — przyciski "Edytuj pozycje (force)", "Cofnij do snapshotu", "Otwórz ponownie".
8. Adaptacja `HistoryStack.js` logiki do `OrderTimelineStack` w `hub_order_history.js`.

**Bezpieczniki:**
- **Snapshot przed zmianą** — zapis w `sh_order_logs` (`action='pre_force_edit_snapshot'`).
- **`is_corrected = 1`** — flaga na `sh_orders` po korekcie zamkniętego.
- **KOR magazynowy** — `WarehouseReverseHook::onOrderCorrected` tworzy `wh_documents` typ `KOR` dla usuniętych/zmienionych linii.
- **Re-konsumpcja** — `WzEngine::consumeForOrder` dla dodanych linii.
- **Blokada podwójnego KOR** — sprawdzenie czy `wh_documents` typ `KOR` dla `order_id` już istnieje (patrz `bi_foundation_audit.md` §123).
- **Fiskalizacja** — jeśli `fiscal_receipt_number IS NOT NULL`, manager musi ręcznie wydrukować paragon korygujący przez `_fiscalReprint` z `force=true`.
- **Reopen** — `OrderReopenEngine` waliduje: status `completed`, `fiscal_receipt_number` (loguje ostrzeżenie), magazyn (KOR jeśli potrzeba), zapisuje `reason`.
- Wymaga `owner`/`admin`.

**Weryfikacja:**
- `php -l` na wszystkich nowych/zmienionych backendach.
- Test: korekta pozycji na `completed` z `receipt_printed=1` → 200 OK, `is_corrected=1`, KOR utworzony, `sh_order_logs` snapshot.
- Test: revert do snapshotu → stan przywrócony, nowy snapshot zapisany.
- Test: reopen `completed → pending` → status zmieniony, `writeLog('reopen')`, KOR jeśli magazyn.
- Test: podwójny KOR zablokowany.
- Headless test runner: 62/62 PASS.
- Migracja 068 dodana do `_migrations_chain.php`.

**Ryzyko:** Średnie — modyfikacja zamkniętych zamówień z korektą magazynową. Wymaga dokładnego testowania KOR + re-konsumpcji. Bezpieczniki (snapshot, `is_corrected`, blokada podwójnego KOR) minimalizują ryzyko.

---

## 6.4. WPŁYW NA BI (BiEngine) — KRYTYCZNA ANALIZA

### Problem

`core/BiEngine.php` czyta bezpośrednio z `sh_orders` i `wh_documents` do raportu P&L. Korekty zamkniętych zamówień (Poziom 2 i 3) **mogą zaburzyć agregaty BI** jeśli nie są odpowiednio obsługiwane.

### Co BiEngine czyta (źródła danych)

| Agregat BI | Źródło SQL | Filtr |
|------------|-----------|-------|
| **Gross Revenue** | `SUM(sh_orders.grand_total)` | `status = 'completed'` + `created_at` w oknie |
| **Output VAT** | `SUM(sh_order_lines.vat_amount)` JOIN `sh_orders` | `status = 'completed'` + `created_at` w oknie |
| **COGS** | `SUM(wh_document_lines.line_net_value)` JOIN `wh_documents` JOIN `sh_orders` | `wh_documents.type = 'WZ'` + `o.status = 'completed'` + `o.created_at` w oknie |
| **Labor** | `SUM(sh_payroll_ledger.amount_minor)` | `entry_type = 'work_earnings'` + okres |
| **OPEX** | `SUM(sh_expenses.amount_net)` | kategoria + okres |
| **Stock Value** | `SUM(wh_stock.quantity * current_avco_price)` | stan bieżący (snapshot, bez filtra dat) |

### Wpływ korekt na BI — macierz ryzyka

| Korekta (RFC) | Gross Revenue | Output VAT | COGS | Stock Value | Wpływ na P&L |
|----------------|:---:|:---:|:---:|:---:|:---|
| **Poziom 2: metadane (klient, telefon, adres)** | — | — | — | — | **Brak** — metadane nie są czytane przez BiEngine |
| **Poziom 2: zmiana `cash ↔ card`** | — | — | — | — | **Brak** — `payment_status` nie jest czytane przez BiEngine (czyta `grand_total` + `status='completed'`) |
| **Poziom 3: korekta pozycji (zmiana `grand_total`)** | ⚠️ | ⚠️ | — | — | **Tak** — `SUM(grand_total)` i `SUM(vat_amount)` zmienią się po korekcie |
| **Poziom 3: korekta pozycji (KOR magazynowy)** | — | — | ⚠️ | ⚠️ | **Tak** — KOR dodaje ujemne `line_net_value` do `wh_document_lines`; `wh_stock.quantity` rośnie (zwrot surowca) |
| **Reopen `completed → pending`** | ⚠️ | ⚠️ | ⚠️ | — | **Tak** — `status` zmienia się z `completed` na `pending`; BiEngine filtruje `status = 'completed'` → zamówienie **znika z P&L** |
| **Revert do snapshotu** | ⚠️ | ⚠️ | ⚠️ | ⚠️ | **Tak** — przywrócenie `grand_total` + `status` + linii magazynowych |

### Kluczowa decyzja architektoniczna: `created_at` vs `updated_at`

`BiEngine.php:14-15` (komentarz w klasie):
> **Order date window (P&L):** revenue, VAT, and COGS-from-WZ use `sh_orders.created_at`, not
> `updated_at`, so late edits (notes, metadata) do not shift revenue across accounting periods.

**To jest korzystne dla naszych korekt:**
- Poziom 2 (metadane): `created_at` nietknięte → **brak wpływu na okno P&L** ✅
- Poziom 3 (korekta `grand_total`): `created_at` nietknięte → korekta wpływa na **ten sam dzień P&L**, nie przesuwa do innego okresu ✅
- Reopen: `created_at` nietknięte → jeśli zamówienie wraca do `completed`, wpada w **ten sam dzień P&L** ✅

**Ale:** jeśli korekta `grand_total` następuje w **innym dniu** niż `created_at` (np. zamówienie z 2026-08-20 korygowane 2026-08-25), BiEngine z dnia 2026-08-20 **pokaże nowy grand_total** (bo czyta bieżący stan `sh_orders`, nie historyczny). To jest **zgodne z obecnym zachowaniem** — BiEngine czyta live dane, nie snapshoty.

### Bezpieczniki BI dla Poziomu 3 i Reopen

1. **`is_corrected` flaga** — BiEngine może odfiltrować korygowane zamówienia lub oznaczyć je w dashboardzie:
   ```sql
   -- Opcja A: BiEngine pokazuje korygowane osobno
   SELECT
     SUM(CASE WHEN is_corrected = 0 THEN grand_total ELSE 0 END) AS gross_revenue_clean,
     SUM(CASE WHEN is_corrected = 1 THEN grand_total ELSE 0 END) AS gross_revenue_corrected,
     SUM(grand_total) AS gross_revenue_total
   FROM sh_orders WHERE status = 'completed' AND ...
   ```

2. **KOR w COGS** — `BiEngine::aggregateCogsMinor` filtruje `wd.type = 'WZ'`. KOR ma `type = 'KOR'` więc **NIE jest wliczany do COGS** automatycznie. To oznacza:
   - Usunięcie pozycji z zamkniętego zamówienia → KOR z ujemnym `line_net_value` → **COGS nie zmniejsza się** (KOR nie jest WZ).
   - **Rozwiązanie:** Rozszerzyć `aggregateCogsMinor` o `type IN ('WZ', 'KOR')` — KOR z ujemną wartością naturalnie redukuje COGS. **Wymaga aktualizacji BiEngine w Fazie 3.**

3. **Reopen a P&L** — jeśli `completed → pending`, zamówienie znika z P&L (bo `status != 'completed'`). Jeśli potem `pending → completed` (ponowne zakończenie), wpada z powrotem. **To jest poprawne zachowanie** — P&L odzwierciedla bieżący stan.

4. **Revert a P&L** — przywrócenie `grand_total` do wartości ze snapshotu zmienia bieżący P&L dla dnia `created_at`. **To jest poprawne** — revert oznacza "to była błędna korekta, przywracamy stan prawdziwy".

### Aktualizacje BiEngine wymagane w Fazie 3

| Zmiana | Plik | Opis |
|--------|------|------|
| COGS z KOR | `core/BiEngine.php:aggregateCogsMinor` | Zmiana `wd.type = 'WZ'` → `wd.type IN ('WZ', 'KOR')` — KOR z ujemną wartością redukuje COGS |
| Oznaczenie korygowanych | `core/BiEngine.php:aggregateGrossRevenueMinor` | Dodatkowe pole `gross_revenue_corrected_minor` (SUM gdzie `is_corrected = 1`) |
| Dashboard UI | `modules/bi/js/bi_app.js` (jeśli istnieje) | Badge "Korygowane: X zł" w sekcji Gross Revenue |

### Faza 1 i 2 — brak wpływu na BI

- **Faza 1** (historia + audit): read-only, zero mutacji → **brak wpływu na BI** ✅
- **Faza 2** (metadane + `cash ↔ card`): metadane i `payment_status` nie są czytane przez BiEngine → **brak wpływu na BI** ✅
- **Faza 3** (korekta pozycji + reopen + revert): wymaga aktualizacji BiEngine (KOR w COGS + `is_corrected`) → **wpływ na BI, wymaga koordynacji** ⚠️

---

## 7. ZALEŻNOŚCI I OGRANICZENIA

### 7.1. Zależności techniczne
- `sh_event_outbox` musi istnieć (migracja 026 — ✅ w chain).
- `sh_order_logs` musi istnieć (migracja 037 — ✅ w chain, może sypać błędami na XAMPP 10.4 ale nie blokuje).
- `sh_order_audit` musi istnieć (migracja 001 — ✅).
- `fiscal_receipt_number` kolumna (migracja 062 — ✅).
- `WarehouseReverseHook` (F5-C — ✅ aktywny).
- `OrderEventPublisher::publishOrderLifecycle` (Faza 7 — ✅ aktywny).
- `BiEngine` (Faza BI — ✅ aktywny, czyta `sh_orders` + `wh_documents`).

### 7.2. Ograniczenia (świadome decyzje)
- **Oś czasu nie pokazuje zmian z bezpośrednich modyfikacji DB** (np. ręczny SQL) — tylko zdarzenia które przeszły przez `OrderEventPublisher` / `OrderStateMachine` / `edit.php`.
- **Undo cofa do snapshotu z outboxu** — jeśli event nie został opublikowany (np. błąd transakcji), snapshot nie istnieje.
- **Reopen `cancelled → pending` NIE jest wspierany** w Fazie 3 — tylko `completed → pending`. Anulowane zamówienia zostają anulowane (symetria z ChoiceQR które anuluje po 3 brakach 200).
- **Zmiana `payment_status` na `online_paid`** NIE jest wspierana w Fazie 2 — wymaga integracji z bramką płatności (poza zakresem).

### 7.3. Kompatybilność z XAMPP 10.4
- Migracja 068 (`ALTER TABLE ADD COLUMN`) — działa na 10.4 (proste ADD COLUMN).
- Agregacja 3 SELECT-ów w `audit.php` — działa na 10.4 (brak CTE / window functions).
- `JSON` column type w `sh_event_outbox` / `sh_order_logs` — działa na 10.4 (MariaDB 10.2.7+).

---

## 8. AKCEPTACJA

Po akceptacji tego RFC przystępujemy do wdrożenia **Fazy 1** (read-only, 0% ryzyka regresji):
1. `api/orders/history.php`
2. `api/orders/audit.php`
3. `modules/hub/js/hub_order_history.js` + CSS
4. Kafel w `modules/hub/index.html`

Fazy 2 i 3 po weryfikacji Fazy 1.
