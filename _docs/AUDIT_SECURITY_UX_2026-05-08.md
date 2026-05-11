# AUDYT: Multi-Tenant Lock · Offline POS · KDS Bump Flow

> **Data:** 2026-05-08
> **Zakres:** warstwa zabezpieczeń (cross-silo tenant_id) · idempotencja offline POS · KDS bump flow + priorytetyzacja spóźnionych
> **Tryb:** read-only audit, bez zmian w kodzie.
> **Powiązane dokumenty:** `_docs/01_KONSTYTUCJA.md` (§2, §9), `_docs/16_RESILIENT_POS.md`, `_docs/17_OFFLINE_POS_BACKLOG.md`, `_docs/04_BAZA_DANYCH.md`.

---

## 1. Multi-tenant Lock — najbardziej rygorystyczne miejsca

### 1.1 Bramka u źródła: `core/auth_guard.php`

`tenant_id` **nigdy nie pochodzi z body requestu**. Endpoint dostaje go z dwóch źródeł, w kolejności:

1. **JWT** (`Authorization: Bearer …`) — dekodowany przez `JwtProvider::decode()`, walidacja `tid > 0 && uid > 0`, w przeciwnym razie `die(401)` (`core/auth_guard.php:49–67`).
2. **Sesja PHP** jako fallback (`core/auth_guard.php:71–82`).

Ten plik jest *include'owany absolutnie wszędzie* po `db_config.php` — jest bramą §2 Konstytucji. Dopiero potem skrypt dostaje `$tenant_id` jako int.

### 1.2 Cross-silo: `core/MmEngine.php` (transfer MM między magazynami)

To najbardziej rygorystyczne miejsce w całej bazie. Każde z **5 reusable statementów** ma `tenant_id = :tid` i to nie tylko w `WHERE`, ale przy LOCK-u i kompozycie z `warehouse_id + sku`:

- `stmtLockStock` — `SELECT … FROM wh_stock WHERE tenant_id=:tid AND warehouse_id=:wid AND sku=:sku FOR UPDATE` (`core/MmEngine.php:89–94`). Pessimistic lock + bariera tenanta — bez `:tid` można by zlockować rekord innego najemcy.
- `stmtDeductSource`, `stmtUpsertTarget`, `stmtLog` — wszystkie filtrowane `tenant_id` (`MmEngine.php:96–127`).
- Cała operacja jest jedną transakcją; `ROLLBACK` w `catch` (`MmEngine.php:252–257`).
- **Brak FK numerycznego cross-silo** — most idzie po `(tenant_id, warehouse_id, sku)`, czyli zgodnie z §9.

To jest wzorzec referencyjny: widać, że nawet gdy operacja "porusza coś między dwiema lokacjami", `tenant_id` jest powtórzony **dla każdej nogi** (source i target) — nie ufamy jednemu sprawdzeniu wcześniej.

### 1.3 KDS auto-readiness — bariera tenanta przy ticket-counting

Najbardziej niedoceniane miejsce. W `api/kds/update_ticket.php` jest moment, w którym ticket bumpie się na `done` i system MUSI policzyć "ile jeszcze ticketów tego zamówienia jest aktywnych" — i tu właśnie najłatwiej o leak:

```sql
SELECT COUNT(*) FROM sh_kds_tickets
 WHERE order_id = :oid AND tenant_id = :tid AND status != 'done'
 FOR UPDATE
```

Trzy bariery jednocześnie (`api/kds/update_ticket.php:134–140`):
- `tenant_id = :tid` (bariera §2),
- `FOR UPDATE` (race condition — dwóch kucharzy klika "GOTOWE" na ostatnich dwóch ticketach jednocześnie),
- `status != 'done'` (filtr biznesowy).

Następny `UPDATE sh_orders SET status='ready'` ma dwie bariery: `tenant_id = :tid` ORAZ `status IN ('accepted','preparing')` (`update_ticket.php:152–157`) — chroni przed wyścigiem z ręcznym `bump_order` i przed zmianą orderu z innego tenantu.

### 1.4 KDS get_board — cross-silo JOIN po `ascii_key` z barierą tenant

W `api/kds/engine.php:91–99` widać czysty wzorzec §9:

```sql
LEFT JOIN sh_kds_tickets kt ON kt.id = ol.kds_ticket_id AND kt.tenant_id = :tid_kt
LEFT JOIN sh_menu_items  mi ON mi.ascii_key = ol.item_sku AND mi.tenant_id = :tid_mi
```

JOIN po **kluczu znakowym** (`ascii_key = item_sku`), a tenant_id powtórzony **w każdym ON**, nie tylko w `WHERE`. Dwa różne placeholdery (`:tid_kt`, `:tid_mi`) na ten sam tenant — żeby PDO nie podstawił złego, gdy ktoś zrobi reorder parametrów.

### 1.5 POS sync — walidacja terminala vs tenant

`api/pos/sync.php:227–231` przy `push_batch`:

```sql
SELECT id FROM sh_pos_terminals WHERE id = ? AND tenant_id = ? LIMIT 1
```

Jeżeli klient wyśle `terminal_id` z innego najemcy — **403 "unknown terminal for this tenant"**. Bez tego sprawdzenia `op_id` (PK globalne) mogłoby zostać zalogowane na cudze konto. Identyczna walidacja dla `pull_since` (`sync.php:471–475`).

---

## 2. Optymalizacja Offline POS — idempotencja przy konflikcie 2 terminali

System jest **świadomie zamrożony** na P4 MVP (`_docs/17_OFFLINE_POS_BACKLOG.md`) — pełny multi-device merge to P5/P6 (zamrożone). Ale **3 warstwy ochrony już działają**:

### 2.1 Warstwa 1: Dedupe lokalny per-terminal — `PosApiOutbox._dedupeKeyFor`

Klucz: `method + JSON.stringify(args)` (`modules/pos/js/PosApiOutbox.js:267–276`). Kasjer w panice klika 3× "Zatwierdź" offline → trzeci klik dostaje **ten sam `opId`** co pierwszy, bo `PosLocalStore.enqueueOp` przed insertem szuka po `dedupeKey` w outboxie (`PosLocalStore.js:216–218`):

```js
if (opts.dedupeKey) {
    const existing = await this._findOpByDedupeKey(opts.dedupeKey);
    if (existing) return existing.opId;
}
```

Filtr `_findOpByDedupeKey` ignoruje `done`/`dead` — czyli zakończony op nie blokuje legitymowanego retry tego samego payloadu (`PosLocalStore.js:344`).

### 2.2 Warstwa 2: Deterministyczny **UUID v7** jako op_id

Każdy op dostaje `opId` (RFC 9562 §5.7, time-sorted, 48-bit unix_ms). Generator własny (`PosLocalStore.js`, sekcja 6.3 `16_RESILIENT_POS.md`). To znaczy:

- Dwa terminale offline nie wygenerują kolizji (kryptograficzne 74 bity entropii w rand_a+rand_b).
- Po reconnect serwer dostaje opsy w **rzeczywistej chronologii zegara** klientów — bez negocjacji czasu.

### 2.3 Warstwa 3: Idempotencja serwerowa — `op_id` jako PK

`api/pos/sync.php:267–280` — przed apply jest **idempotency check**:

```php
$check = $pdo->prepare("SELECT status, server_ref, error_text FROM sh_pos_op_log WHERE op_id = ? LIMIT 1");
if ($existing) {
    $results[] = […, 'idempotent' => true];
    continue;
}
```

`op_id` jest **PRIMARY KEY** w `sh_pos_op_log` (migracja 039). Drugie wysłanie tego samego opa po timeoucie sieci → serwer zwraca **poprzedni wynik z `idempotent: true`**, bez side-effectów. Dzięki temu replay loop może retry'ować bez obawy o duplikat.

### 2.4 Co z dwoma terminalami dodającymi do tego samego zamówienia?

**Tu jest jawny gap** — przyznany w `_docs/17_OFFLINE_POS_BACKLOG.md` §3.2 i §3.3:

- **Per-device dedupe działa** — POS A nie zduplikuje sam swojego clicka.
- **Cross-device merge NIE działa** — dwóch waiterów dodaje różne pozycje do `order_id=X` offline → po reconnect oba opsy lecą na serwer, każdy tworzy własny line. Brak resolverka.
- **`PosLocalStore.markConflict()`** istnieje (`PosLocalStore.js:281`) — infrastruktura jest, ale UI rozstrzygania (modal "Twoja wersja / Serwera / Merge") to P6, **zamrożone**.
- **`navigator.locks.request('order:{id}')`** zapowiedziane w spec'u (`16_RESILIENT_POS.md` §3.3, `BroadcastChannel` + Web Locks) — niezaimplementowane.

W praktyce gap jest mitygowany na poziomie biznesowym: jeden POS = jeden kasjer/stacja. Multi-POS-per-order to scenariusz `waiter` (kelner z tabletem) + `POS` (kasa) — i ten flow obecnie wymaga online (waiter pisze do `sh_orders` przez serwer, nie przez outbox).

### 2.5 Priorytetyzacja replay loop (self-healing sync)

`PosApiOutbox._tick` (`PosApiOutbox.js:284–384`) pcha pasami `REPLAY_BATCH_LIMIT = 20`, exponential backoff `2 → 4 → 8 → 16 → 32 → 60s`, `REPLAY_MAX_RETRIES = 5` → `markDead`. Spec (`16_RESILIENT_POS.md` §3.4) zapowiada hierarchię: payment-collected → process_order → fire_course → edytów kasjerskie → cache reheat — **w MVP P4 jest FIFO po `createdAt`**, czyli ta hierarchia czeka na P5+.

---

## 3. KDS — Bump Flow i priorytetyzacja spóźnionych

### 3.1 Bump flow — dwie warstwy state machine

Są **dwie state machine** współistniejące w KDS, świadomie:

**A) Order-level (`api/kds/engine.php#bump_order`)** — przejścia całego zamówienia, sterowane ręcznie z UI:

```
new → accepted → preparing → ready
```

Twarda walidacja w `$validTransitions` (`engine.php:179–183`); próba przejścia poza dozwolony krok → `409 "Cannot transition from 'X' to 'Y'"`. Zapisuje audit do `sh_order_audit` + emituje event do `OrderEventPublisher` z `idempotencyKey = orderId + ':' + eventType + ':' + currentStatus` (`engine.php:236–246`).

**B) Ticket-level (`api/kds/update_ticket.php`)** — przejścia per-stacja KDS:

```
pending → preparing → done
```

Z auto-promocją zamówienia: gdy **ostatni** ticket osiąga `done` (sprawdzone pod `FOR UPDATE`), order leci na `ready` automatycznie (`update_ticket.php:120–172`). To jest wzorzec **uniwersalnej gotowości** z blueprintu — kucharz nie musi nigdzie klikać dodatkowo, KDS sam zamknie zamówienie gdy wszystkie stacje skończą.

### 3.2 Recall (cofnięcie) — nietypowy mechanizm

`engine.php#recall_order` (`engine.php:264–306`) — pozwala cofnąć **tylko** `ready → preparing`:

```php
if ($currentStatus !== 'ready') {
    kdsResponse(false, null, "Cannot recall from '{$currentStatus}'. Only 'ready' may be recalled.");
}
```

Emituje event `order.recalled` z `idempotencyKey = orderId + ':order.recalled:' + date('YmdHis')` — uwaga: **timestamp w idempotencyKey** oznacza, że dwa szybkie recall-e w tej samej sekundzie się zderzą; ostatni wygrywa. Pozostałe transitions back są niedozwolone (np. `preparing → accepted` nie jest możliwe).

### 3.3 Priorytetyzacja spóźnionych zamówień — **brak prawdziwej priorytetyzacji w KDS**

Tu jest mocny finding. Sortowanie boardu w `api/kds/engine.php:102–110`:

```sql
ORDER BY
  CASE o.status
    WHEN 'new'       THEN 0
    WHEN 'accepted'  THEN 1
    WHEN 'preparing' THEN 2
    ELSE 9
  END,
  o.created_at ASC
```

— **NIE bierze pod uwagę `promised_time`**. Spóźnione zamówienie (preparing, promised_time minęło 10 min temu) ląduje **po** świeżym preparing, jeśli przyszło wcześniej. UX kompensuje to wizualnie: `_timerInfo()` w `modules/kds/js/kds_app.js:71–77` zwraca trzy klasy CSS:

| Stan | Klasa | Tekst |
|---|---|---|
| `diff < 0` | `urgent` | "Spóźnione N m" |
| `0 ≤ diff ≤ 5` | `warning` | "N min" |
| `diff > 5` | `ok` | "N min" |

Czyli kucharz **widzi czerwoną pluskwę** "Spóźnione 12m", ale ticket fizycznie **nie skacze na górę** boardu. Reorder jest wyłącznie wizualnie-kolorystyczny, nie strukturalny.

Realna priorytetyzacja late orders istnieje **poza KDS** — w `api/orders/sla_monitor.php` (oznaczony jako ORPHAN, niewpięty z frontendu). Klasyfikuje tier `ON_TRACK → AT_RISK → CRITICAL → BREACHED` po progach `sla_green_min`, `sla_yellow_min` z `sh_tenant_settings` i UPSERT-uje do `sh_sla_breaches`. Ale ten endpoint:
- nie jest wołany z KDS,
- jest tylko dla `order_type = 'delivery'`,
- przeznaczony dla admin_hub Faza 3 (komentarz w pliku `sla_monitor.php:1–7`).

### 3.4 Filtrowanie po stacji — ważny cross-silo detal

`get_board` przy `station != ''` filtruje przez **dwa źródła stacji** z `COALESCE`:

```sql
COALESCE(kt.station_id, NULLIF(mi.kds_station_id, ''), 'KITCHEN_MAIN')
```

(`engine.php:95`). Czyli stacja może być wymuszona na biletcie KDS (`sh_kds_tickets.station_id`), albo wynikać z domyślnej stacji menu-item-u (`sh_menu_items.kds_station_id`), z fallbackiem na `KITCHEN_MAIN`. Most cross-silo wewnątrz `sh_*` po `ascii_key` (§9). Każdy `JOIN` ma `tenant_id` w `ON`.

---

## TL;DR — Kluczowe wnioski

| Obszar | Najmocniejszy mechanizm | Najsłabszy punkt / gap |
|---|---|---|
| **Multi-tenant lock** | `MmEngine.processTransfer` — `FOR UPDATE` z `tenant_id` na obu nogach transferu (source + target), tx atomowa, wszystkie 5 statementów filtrowane. | — (brak gapu w cross-silo write paths; rygor §2 + §9 trzymany konsekwentnie). |
| **Offline POS / idempotencja** | Trzywarstwowa: dedupeKey lokalny → UUID v7 globalny → `op_id PRIMARY KEY` z `sh_pos_op_log` (zwraca `idempotent: true` przy retry). | Cross-device merge dwóch terminali do tego samego `order_id` — infrastruktura `markConflict` jest, **UI/resolver zamrożony do P6**. Replay FIFO bez biznesowej priorytetyzacji (planowane P5+). |
| **KDS bump flow** | Dwie state machine (order-level + ticket-level) z auto-readiness pod `FOR UPDATE` na ostatnim ticketie. Recall ograniczony tylko `ready → preparing`. | **Brak priorytetyzacji spóźnionych** w SQL — sort wyłącznie po `status, created_at ASC`. Spóźnienie sygnalizowane wizualnie (`urgent` CSS), nie strukturalnie. `sla_monitor.php` jest osierocony i delivery-only. |

---

## Rekomendacje (do dyskusji, nie wdrożenia bez zlecenia)

1. **KDS: dołożyć sort po `promised_time`** — zmienić `ORDER BY` w `get_board` na coś w stylu `CASE WHEN promised_time < NOW() THEN 0 ELSE 1 END, promised_time ASC, created_at ASC`. Spóźnione fizycznie skaczą na górę. Zachowuje obecny color-coding.
2. **Recall idempotencyKey** — `date('YmdHis')` ma rozdzielczość 1s. Wymienić na `microtime(true)` albo deterministyczny licznik recall-i z bazy. Niski priorytet (rzadki edge case).
3. **Cross-device merge offline POS** — to jest świadomie zamrożone do P6; nie ruszać bez rozmrożenia przez właściciela produktu (`_docs/17_OFFLINE_POS_BACKLOG.md` §4).

---

> Audyt wykonany w trybie read-only. Nie wprowadzono żadnych zmian w kodzie.
