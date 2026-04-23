# 17. OFFLINE-FIRST POS — BACKLOG & FREEZE MANIFEST

> **STATUS: 🧊 CODE FREEZE — 2026-04-23**
> **Decyzja:** właściciel produktu (Damian). **Powód:** moduł offline-first POS-a został doprowadzony do działającego MVP (P1–P4). Dalsze kroki (P4.5 worker fanout, P5 multi-device, P6 conflict UI + fantom cards, P7 offline auth) zostają świadomie zamrożone — nie są priorytetem biznesowym na tym etapie, a ich dokończenie byłoby przedwczesną optymalizacją kosztem ważniejszych warstw (menu/storefront/payroll/HR/statystyki).
>
> **Ten dokument jest jedynym autorytatywnym źródłem** dla wznowienia prac. Jeśli AI lub człowiek chce ruszyć cokolwiek z listy zamrożonych plików — czyta ten plik PIERWSZY, potem `16_RESILIENT_POS.md` jako spec kompletny.

**Powiązane dokumenty:**
- `_docs/00_PAMIEC_SYSTEMU.md` — sekcja FREEZE NOTICE (wskazuje tutaj)
- `_docs/16_RESILIENT_POS.md` — pełny spec P1–P4 (zamrożony, tylko do czytania)
- `_docs/09_EVENT_SYSTEM.md` — event bus Fazy 7, który będzie producentem dla P4.5

---

## 0. KTO, KIEDY, DLACZEGO

| Pole | Wartość |
|---|---|
| Data freeze | 2026-04-23 |
| Ostatnia merged faza | **P4 (Optimistic Layer MVP)** |
| Ukończone fazy | **P1, P2, P3, P3.5, P4** |
| Zamrożone fazy | **P4.5, P5, P6, P7, P8** |
| Migracje zastosowane | 039, 040 |
| Uzasadnienie freeze | Offline-first jest innowacyjnym wyróżnikiem, ale obecnego MVP wystarczy pilotażowi. Priorytet biznesowy przesuwa się na (a) producentów eventów (musi być gotowy event bus + worker fanout — patrz 7A §7 w 16_RESILIENT_POS.md — zanim dalej), (b) dokończenie sklepu online (scena Counter/Living Table), (c) statystyki i HR. |

---

## 1. CO DZIAŁA (STAN OBECNY — NIE DOTYKAĆ)

### 1.1 Fundament (P1 · P2) — PWA + IndexedDB

- **PWA instalowalna** (`modules/pos/manifest.webmanifest`, ikony, screenshots wide/narrow, shortcut „Nowe zamówienie").
- **Service Worker** (`modules/pos/sw.js`, `CACHE_VERSION = 'slicehub-pos-v5'`) z 3 strategiami (nav network-first, static SWR, api-read SWR), offline fallback w `offline.html`.
- **Connectivity pill** (top-right, reaktywny na online/offline + outbox counts) w `pos_sw_register.js`.
- **IndexedDB store** (`modules/pos/js/PosLocalStore.js`, DB `slicehub-pos`, wersja 1) z trzema object store'ami (`state`, `outbox`, `event_log`), UUID v7 generator, BroadcastChannel cross-tab, GC (TTL 30 dni state / 7 dni done / 30 dni dead).

### 1.2 Sync push (P3) — klient → serwer

- **Endpoint `api/pos/sync.php`** z akcjami: `register_terminal`, `push_batch`, `diag`.
- **Migracja 039** (`sh_pos_terminals`, `sh_pos_sync_cursors`, `sh_pos_op_log`).
- **`PosSyncEngine.js`** — pętla push: adaptive throttle 2s/30s, exponential backoff, dead-letter po 10 retry, idempotency przez `op_id` (UUID v7, PK w `sh_pos_op_log`).

### 1.3 Sync pull (P3.5) — serwer → klient

- **Akcje w `sync.php`:** `pull_since`, `publish_test_event` (DEV helper).
- **Migracja 040** (`sh_pos_server_events` append-only log + rozszerzenie `sh_pos_sync_cursors` o liczniki pull).
- **`PosSyncEngine._pullTick`** — pętla pull: 3s aktywna / 15s idle, paginacja `has_more`, cursor w localStorage + DB, DOM event `slicehub-pos:server-event`.

### 1.4 Optimistic outbox (P4) — mutacje offline

- **`modules/pos/js/PosApiOutbox.js`** — Proxy nad `PosAPI` (pos_api.js). Mutacje (`processOrder`, `acceptOrder`, `updateStatus`, `printKitchen`, `printReceipt`, `settleAndClose`, `cancelOrder`, `panicMode`, `assignRoute`, `createCourse`, `assignDriverToCourse`) lecą przez interceptor:
  - online + fresh → pass-through do engine.php
  - online + network error → enqueue + toast „Zakolejkowane — serwer nieosiągalny"
  - offline → enqueue + toast „Zakolejkowane — wyślę gdy sieć wróci"
- **Replay loop** co 2s/15s, max 5 retry → dead-letter, dedupe po `method + JSON(args)`.
- **Integracja:** `pos_app.js` zmienił jedną linię importu (`./pos_api.js` → `./PosApiOutbox.js`). Pełna kompatybilność response shape.
- **Reakcje UI:** event `slicehub-pos:outbox-replayed` → `_fetchOrders()`, event `slicehub-pos:server-event` → `_fetchOrders()` dla `order.created`/`order.status`.

### 1.5 Cache headers + MIME (Storefront + POS)

- `modules/pos/.htaccess`, `modules/online/.htaccess` — AddType `application/manifest+json`, `image/svg+xml`, `text/javascript`; `Service-Worker-Allowed`; no-cache dla `sw.js` + `.webmanifest`.

---

## 2. FREEZE MANIFEST — PLIKI POD OCHRONĄ

> **Kategoryczny zakaz** refaktoryzacji, przenoszenia, usuwania, przepisywania tych plików bez jawnego rozmrożenia przez właściciela produktu. Edycje kosmetyczne (styl, komentarze) — też zakazane.

### 2.1 Backend — API

| Plik | Wersja zamrożona | Uwagi |
|---|---|---|
| `api/pos/sync.php` | akcje: register_terminal, push_batch, pull_since, publish_test_event, diag | Nie dodawać nowych akcji. Jeśli trzeba publikować eventy serwerowe → nowy plik `workers/worker_pos_fanout.php` (patrz §3.1). |

### 2.2 Backend — Migracje

| Plik | Data | Tabele |
|---|---|---|
| `database/migrations/039_resilient_pos.sql` | 2026-04-23 | `sh_pos_terminals`, `sh_pos_sync_cursors`, `sh_pos_op_log` |
| `database/migrations/040_pos_server_events.sql` | 2026-04-23 | `sh_pos_server_events` + rozszerzenie `sh_pos_sync_cursors` |

**Zakaz:** ALTER/DROP tych tabel. Nowe kolumny → tylko przez nową migrację z pełnym uzasadnieniem i rozmrożeniem freeze.

### 2.3 Frontend — POS (offline-first stack)

| Plik | Rola |
|---|---|
| `modules/pos/manifest.webmanifest` | PWA install metadata |
| `modules/pos/sw.js` | Service Worker (v5) |
| `modules/pos/offline.html` | Fallback offline |
| `modules/pos/.htaccess` | MIME + cache headers |
| `modules/pos/icon.svg` | Main app icon |
| `modules/pos/icon-maskable.svg` | Maskable (Android adaptive) |
| `modules/pos/icons/shortcut-new.svg` | Shortcut „Nowe zamówienie" |
| `modules/pos/screenshots/wide.svg` | PWA install screenshot 1280×720 |
| `modules/pos/screenshots/narrow.svg` | PWA install screenshot 390×844 |
| `modules/pos/js/pos_sw_register.js` | SW registration + pill + bootstrap store/sync/outbox |
| `modules/pos/js/PosLocalStore.js` | IndexedDB wrapper |
| `modules/pos/js/PosSyncEngine.js` | Push + pull loops |
| `modules/pos/js/PosApiOutbox.js` | Proxy wrapper nad PosAPI |

**`modules/pos/js/pos_app.js`** — zamrożony tylko w zakresie **importu PosAPI** (linia `import PosAPI from './PosApiOutbox.js'`) i listenerów `slicehub-pos:outbox-replayed` + `slicehub-pos:server-event` w `_startPolling`. Reszta pliku jest regularną logiką POS — **może być edytowana pod zadania niezwiązane z offline**.

### 2.4 Frontend — Storefront (cleanup PWA przy okazji)

| Plik | Rola |
|---|---|
| `modules/online/manifest.webmanifest` | PWA metadata (sizes, shortcuts, screenshots) |
| `modules/online/sw.js` | Service Worker (v2) |
| `modules/online/.htaccess` | MIME + cache |
| `modules/online/icon-maskable.svg` | Maskable icon |
| `modules/online/screenshots/wide.svg` | PWA install screenshot wide |
| `modules/online/screenshots/narrow.svg` | PWA install screenshot narrow |

### 2.5 Dokumentacja

| Plik | Rola |
|---|---|
| `_docs/16_RESILIENT_POS.md` | Kanoniczny spec P1–P4 + plan P5–P8 (do czytania, nie edytować) |
| `_docs/17_OFFLINE_POS_BACKLOG.md` | **TEN PLIK** — freeze manifest + backlog |

---

## 3. BACKLOG — SZCZEGÓŁOWY PLAN (ZAMROŻONE)

Każda pozycja backlogu zawiera: **cel, dependency, kontrakt, pliki, ryzyka, kryterium akceptacji**. AI ani człowiek nie zaczynają implementacji bez jawnego rozmrożenia.

### 3.1 P4.5 — Worker POS Fanout (priorytet 1 gdy wznawiamy)

**Cel:** wpiąć `sh_pos_server_events` w istniejący event bus Fazy 7 bez łamania §4 Konstytucji (Klocki Lego). Producenci (checkout online, KDS, admin) **nie dotykają `sh_pos_server_events`** — emitują eventy do `sh_event_outbox` (to już robią, patrz `core/OrderEventPublisher.php`). Dedykowany worker konsumuje outbox i fanout'uje do streamu POS-a po translation / anti-corruption layer.

**Dependency (musi być przed startem):**
- ✅ Migracja 026 (`sh_event_outbox`) — jest
- ✅ `OrderEventPublisher` — jest
- ✅ Wzorzec worker CLI (`scripts/worker_webhooks.php`, `scripts/worker_integrations.php`) — jest
- ✅ Migracja 040 (`sh_pos_server_events`) — jest
- ⏸️ Audit producentów: czy `api/online/engine.php#guest_checkout`, `api/kds/engine.php`, `api/backoffice/api_menu_studio.php` faktycznie publikują `order.created`, `order.status_changed`, `menu.published`. Jeśli nie — **przed startem P4.5** dokładamy emity u producentów (bez dotykania POS-a).

**Kontrakt translation:**

| Event outbox (źródło) | Event POS (cel) | Mapowanie payload |
|---|---|---|
| `order.created` (gateway/online/POS/aggregator) | `order.created` | `{ id, order_number, status, type, total_gross, lines[] }` |
| `order.accepted` / `order.preparing` / `order.ready` / `order.dispatched` / `order.delivered` / `order.completed` / `order.cancelled` | `order.status` | `{ id, status, prev_status, changed_at }` |
| `menu.published` (jeśli istnieje — inaczej dodaje producent) | `menu.updated` | `{ reason: 'published', full_refresh: true }` |
| `table.reserved` (future) | `table.reserved` | `{ table_id, status, until }` |

Wszystko inne — **worker ignoruje** (wyraźny whitelist). Nieznany event w outboxie nie jest błędem — po prostu nie dotyczy POS-a.

**Pliki do stworzenia (szkic, do doprecyzowania przy rozmrożeniu):**
- `scripts/worker_pos_fanout.php` — CLI, analogiczny do `worker_webhooks.php`, `worker_integrations.php`
  - Flags: `--loop --sleep=N --batch=N --dry-run --max-batches=N -v --help`
  - PID lock (`logs/worker_pos_fanout.pid`)
  - SIGTERM/SIGINT graceful
  - Exit codes: 0 OK / 1 DB / 2 locked / 3 exception
- `core/Pos/PosFanoutDispatcher.php` — logika translacji (handler map event_type → builder payload POS)
- `core/Pos/PosEventPublisher.php` (opcjonalnie) — helper `publishServerEvent($tenantId, $eventType, $entityType, $entityId, $payload, $originKind, $originRef)` — **jedyna droga** do pisania w `sh_pos_server_events` po P4.5
- `_docs/11_WEBHOOK_DISPATCHER.md` pattern → rozszerzenie o sekcję POS Fanout (retry policy, dead-letter)

**Dodatkowa migracja (opcjonalnie, decyzja przy rozmrożeniu):**
- `sh_pos_fanout_cursors` (terminal_id, last_outbox_id, last_fanout_at) — alternatywnie cursor w `sh_event_outbox.pos_fanout_dispatched_at` jako nowa kolumna. **Nie rekomendowane** — osobna tabela jest czystsza.

**Retry policy:** identyczny wzorzec co `WebhookDispatcher` — 30s → 2min → 10min → 30min → 2h → 6h → 24h, max 6 attempts → status `dead`.

**Idempotency:** `sh_pos_server_events` nie ma UNIQUE na event źródłowym — trzeba dodać kolumnę `source_event_id` (INT, nullable, UNIQUE per tenant). Albo prostsza droga: worker trzyma cursor i nigdy nie cofa — at-least-once w złych warunkach OK, bo konsumenci POS dostają event raz zanim będą różnice (event_type `order.status` jest idempotentny z definicji — ten sam status dwa razy = no-op).

**Kryterium akceptacji:**
- [ ] Tworząc zamówienie na storefronta (`guest_checkout`), w ciągu ≤ 15s (worker sleep + pull POS) wszystkie zalogowane POSy w tym tenancie widzą nowe zamówienie bez F5.
- [ ] Zmiana statusu w KDS → analogicznie POS widzi zmianę bez klikania „odśwież".
- [ ] Worker w dry-run zwraca listę eventów które by wypchnął (audit przed włączeniem produkcji).
- [ ] Dead-letter queue: event, który nie pasuje do translation, jest zignorowany cicho (z `-v` log).
- [ ] Tenant isolation — event tenantu A nigdy nie ląduje w POS tenantu B (test z 2 tenantami).

**Ryzyka:**
- Producent nie publikuje eventu → fanout nic nie robi. Mitigation: audit przed startem (p. dependency).
- Worker crash → cursor zostaje, po restarcie od razu łapie zaległości (at-least-once).
- Event types w outboxie zmienią nazwy (np. `order.status.changed` zamiast `order.accepted`) → worker ignoruje. Mitigation: whitelist + log przy `-v`.

---

### 3.2 P5 — Multi-device mirror

**Cel:** dwa (lub więcej) POSy w jednej pizzerii pracują na tym samym stanie bez dublowania operacji. Przykłady: waiter przenosi zamówienie na inny stolik, drugi POS musi to od razu widzieć; dwa POSy próbują zaksięgować ten sam order — jeden wygrywa, drugi dostaje „już zaakceptowane przez X".

**Dependency:**
- P4.5 musi być gotowe (fanout dostarcza eventy cross-device w obrębie tenantu).
- Decyzja: `BroadcastChannel` działa tylko w obrębie tego samego origin + przeglądarki na jednym urządzeniu. Między urządzeniami idzie przez serwer (pull_since z P3.5 lub SSE z P8).

**Plan:**
- Cross-tab na jednym urządzeniu — `BroadcastChannel('slicehub-pos')` **już jest** w `PosLocalStore` (event `outbox:changed`, `state:changed`). Rozszerzenie o `lock:acquired`, `lock:released`.
- Cross-device — nowy typ eventu `pos.terminal_claim` w `sh_pos_server_events` (mapowany przez P4.5 z outbox events typu `order.locked`).
- **`navigator.locks`** API (jest w wszystkich major przeglądarkach od 2022) — lock na `order:{id}` przy edycji. Drugi POS próbujący edytować → czeka albo dostaje notyfikację „edytowane przez X na terminalu Y".

**Pliki do stworzenia:**
- `modules/pos/js/PosBroadcast.js` — wrapper nad BroadcastChannel + navigator.locks
- Rozszerzenie `PosSyncEngine.onServerEvent` o dispatch locks
- Rozszerzenie `PosApiOutbox` o dedupe cross-device po `order_id` (jeśli dwa POSy zakolejkowały ten sam accept — pierwszy wygrywa)

**Kryterium akceptacji:**
- [ ] Scenariusz „dwa POSy na tym samym pizzerii klikają Accept na tym samym zamówieniu" — tylko jeden przechodzi, drugi dostaje toast „już obsłużone przez kasę 2".
- [ ] Waiter app + POS zsynchronizowane w obrębie 2s (live state).

**Ryzyka:**
- `navigator.locks` nie działa cross-origin. Akceptujemy — POS i waiter idą z tej samej domeny.
- Network partition (POS A online, POS B offline) → konflikt przy reconnect. Rozwiązanie: conflict resolver z P6.

---

### 3.3 P6 — Conflict Resolution + Rollback + Fantom Cards + Dead-letter UI

**Cel:** pełny optimistic UI i obsługa sytuacji gdy optymizm się nie potwierdzi.

**Komponenty:**

**A) Fantom cards** (moje fantom UI, świadomie odłożone w P4 MVP):
- Przed server ACK optymistycznie renderujemy kartę zamówienia z `opId` zamiast realnego `order_id`.
- Wizualny wskaźnik „commit pending" — delikatna pulsacja, badge `queued`.
- Po replay success → animacja „settle" + swap `opId` → realny `order_id`.
- Wymaga restrukturyzacji `_orders[]` w `pos_app.js` — dodanie mechanizmu identyfikacji optymistycznych vs realnych.

**B) Rollback animation:**
- Gdy replay failuje definitywnie (5× → dead) → karta fantom znika z efektem „dissolve" + toast `error`.
- Stan zamówienia odtwarzany z ostatniego znanego server state (pull_since snapshot).

**C) Dead-letter UI:**
- Nowa sekcja w POS („Nie wysłano" badge w topbar). Lista z rejected opsów + akcje:
  - „Spróbuj ponownie" (re-enqueue z retries=0)
  - „Odrzuć" (markDead → soft delete z event_log wpis)
  - „Pokaż szczegóły" (payload + last_error + timestamp).

**D) Conflict resolver:**
- Op wrócił ze statusu `conflict` (teraz w `PosLocalStore.markConflict` już jest infrastruktura) — modal z wyborem „Twoja wersja" / „Serwera" / „Merge".
- Przykład: dwóch waiterów dodało różne itemy do tego samego zamówienia offline → merge lines, duplicaty po SKU łączymy, conflict resolver pokazuje diff.

**Pliki do stworzenia (szkic):**
- `modules/pos/js/pos_optimistic.js` — stan fantom cards
- `modules/pos/js/pos_rollback.js` — animacje
- `modules/pos/js/pos_conflict.js` — UI conflict modal
- `modules/pos/css/optimistic.css` — fantom + rollback animations (respekt `prefers-reduced-motion`)
- Rozszerzenie `pos_ui.js` o rendering fantom kart (największa praca)

**Kryterium akceptacji:**
- [ ] Offline → zamówienie pojawia się natychmiast na liście z fantom-badge.
- [ ] Online → animacja „ugody", realne ID zamienia się bez migotania.
- [ ] Sieć spada po 3 z 5 retry → karta dalej widoczna, toast „Sprawdzam sieć…".
- [ ] 5 retry → fantom znika z animacją, toast „Nie udało się — dodano do `Nie wysłano`".
- [ ] Dead-letter list — można odzyskać („Spróbuj ponownie") albo odrzucić.

**Ryzyka:**
- Duża ingerencja w `pos_ui.js` — kolizja z aktywnymi ficzerami. Mitigation: adapter pattern, fantom jako osobna warstwa renderowania.

---

### 3.4 P7 — Offline PIN login + token cache

**Cel:** POS bez sieci dalej przyjmuje login. Kasjer wpisuje PIN, system sprawdza lokalny zahashowany rekord (ostatnia synchronizacja), puszcza sesję z tokenem „soft-auth" ważnym do 24h lub do następnego sukcesu online login.

**Dependency:**
- Decyzja kryptograficzna: gdzie trzymamy hashe PIN-ów lokalnie. Kandydat: IndexedDB + Argon2id na serwerze, hash hash'a w kliencie (żeby kradzież device nie = kradzież PIN-ów wszystkich userów).
- Synchronizacja hash-table przy online login (`api/auth/login.php` dokleja snapshot hashów wszystkich userów tenantu).

**Pliki do stworzenia:**
- `modules/pos/js/PosAuthCache.js` — wrapper nad localStorage + WebCrypto (SHA-256 double-hash)
- Rozszerzenie `api/auth/login.php` o action `sync_pin_hashes` (tylko dla rozpoznanego terminala z P3)
- Timeboks: soft-auth token ważny 24h, po 24h wymagany online login (prevent stale credentials)

**Kryterium akceptacji:**
- [ ] Kasjer loguje się online → wylogowuje → odłącza sieć → loguje ponownie → działa.
- [ ] PIN zmieniony w panelu admina → przy online re-login sesja się aktualizuje, stara cached sesja wygasza po 24h.
- [ ] Wyszukiwanie w DevTools → nie ma czystych PIN-ów, tylko double-hashe.

**Ryzyka:**
- Compliance (GDPR) — hashe to dane osobowe (można zmapować do konta). Mitigation: double-hash + secure delete przy logout.

---

### 3.5 P8 — Playbook, dokumentacja, demo + SSE/WS

**Cel:** dostarczamy produkcyjny playbook + real-time upgrade (SSE/WebSocket) zamiast long-polling.

**Komponenty:**
- Rozszerzenie `api/pos/sync.php` o endpoint SSE (`action=stream`) — `text/event-stream` trzymający connection, push eventów bez polling. Fallback na pull_since gdy SSE nie działa (firewall / proxy).
- `_docs/canvasy/RESILIENT_POS_DEMO.md` — skrypt pokazowy (scenariusze offline dla pilotażu).
- Metryki operational — nowa akcja `diag_health` z percentylami latency replay, % ops `dead`, średni czas offline.
- Opisanie SLO: „99% ops offline zreplayuje się w ≤ 10s od powrotu sieci".

**Dependency:**
- P4.5 gotowe (producenci emitują eventy) — SSE bez pull_since wymaga strumienia.

**Pliki:**
- `api/pos/sync_stream.php` (osobny endpoint, bo utrzymuje open connection)
- `modules/pos/js/PosSseClient.js` (alternatywa do `_pullTick`)
- `_docs/canvasy/RESILIENT_POS_DEMO.md`
- Rozszerzenie `_docs/16_RESILIENT_POS.md` o sekcję SLO.

**Kryterium akceptacji:**
- [ ] POS dostaje event z serwera w ≤ 1s (vs ≤ 15s dla pull_since).
- [ ] Graceful fallback: gdy SSE pęknie → automatyczny switch na pull_since bez utraty cursora.
- [ ] Demo: 3-minutowe wideo pokazujące offline flow dla pilota.

---

## 4. INSTRUKCJA WZNOWIENIA PRAC

> Nie zaczynaj implementacji bez wykonania **wszystkich kroków** z tej sekcji. Instrukcja celowo jest tarczą — ma chronić przed „szybkim zrefaktoryzowaniem" tego, co działa.

### 4.1 Checklist pre-implementacyjny

- [ ] **Właściciel produktu jawnie rozmroził fazę** (message w chacie: „Rozmrażam offline POS — P4.5 start"). Bez tego — STOP.
- [ ] Przeczytano `_docs/17_OFFLINE_POS_BACKLOG.md` (ten plik) — całość.
- [ ] Przeczytano `_docs/16_RESILIENT_POS.md` — całość (spec P1–P4 ukończone + plan P5–P8).
- [ ] Przeczytano `_docs/09_EVENT_SYSTEM.md` — jeśli rozmrożenie dotyczy P4.5.
- [ ] Przeczytano `.cursorrules` + `_docs/00_PAMIEC_SYSTEMU.md` (§1, §2, §9 — izolacja silosów, tenant_id, prefiksy).
- [ ] Sprawdzono, że od zamrożenia (2026-04-23) baza **nie została rozjechana** — `php scripts/apply_migrations_chain.php --audit` zwraca OK.
- [ ] Nie ma aktywnego TODO w innych dokumentach, które by kolidowało z offline POS (np. przepisanie `pos_api.js` — które by zabiło PosApiOutbox proxy).

### 4.2 Kolejność rozmrażania (rekomendowana)

```
  P4.5 (worker fanout)  ◄── rozmraża sens sieci eventów dla POS
     ▼
  Audit producentów    ◄── jeśli checkout/KDS/admin nie emitują właściwych eventów
     ▼
  P7 (offline auth)    ◄── niezależny od P5/P6, może lecieć równolegle
     ▼
  P5 (multi-device)    ◄── wymaga P4.5 (fanout dostarcza cross-device eventy)
     ▼
  P6 (conflict UI)     ◄── najcięższy, wymaga P5 (lock/conflict eventy)
     ▼
  P8 (SSE + demo)      ◄── ostatni, cementuje produkt
```

### 4.3 Commit hygiene

- Każda faza = osobna gałąź: `resilient-pos/p45`, `resilient-pos/p5`, …
- Każda migracja = nowa liczba (041, 042, …) — NIGDY edycja 039/040.
- Każdy PR referuje ten dokument i pokazuje które kryterium akceptacji spełnia.

### 4.4 Anti-checklist — STOP, jeśli zaczynasz robić któreś z tych:

- ❌ Refactor `PosApiOutbox.js` — „bo Proxy jest nieczytelny" → NIE. Spec zamrożony.
- ❌ Merge `PosSyncEngine` + `PosApiOutbox` w jeden plik — NIE. Osobne pętle z premedytacją.
- ❌ Dodanie nowej akcji do `api/pos/sync.php` oprócz tych 5 — NIE. Producenci piszą przez `sh_event_outbox`, worker fanout tłumaczy.
- ❌ Zmiana schema `sh_pos_server_events` żeby „nie robić workera" — NIE. §4 Konstytucji.
- ❌ Hardcoded `tenant_id = 1` w worker_pos_fanout.php — NIE. §2.
- ❌ `INSERT INTO sh_pos_server_events` z `api/online/engine.php` albo `api/kds/engine.php` — NIE. Monolit. Zawsze przez outbox + worker.

---

## 5. OTWARTE PYTANIA (DO DECYZJI PRZY WZNOWIENIU)

1. **Czy `sh_pos_server_events` ma mieć UNIQUE `(tenant_id, source_outbox_id)` dla idempotency, czy akceptujemy at-least-once?** (patrz §3.1 „Idempotency")
2. **SSE czy WebSocket w P8?** Chrome/Safari oba ok, ale proxy / firewall w restauracji mogą mieć preferencje. Empirycznie trzeba sprawdzić na 2 pilotach.
3. **P7 offline auth — czy cache PIN-ów _wszystkich_ userów tenantu przy login, czy tylko danego usera?** Pierwsza daje continuous offline, druga minimalizuje compliance risk.
4. **Czy dead-letter (P6 C) ma być per-device, czy per-tenant?** Per-device jest prostsze, per-tenant wymaga synchronizacji dead-letter listy między POSami.
5. **Storefront ma swój SW — czy ma sens ujednolicić strategie cache'u między online i POS?** Na razie nie, bo filozofia inna (storefront cache'uje katalog, POS cache'uje UI + outbox mutacji).

---

## 6. KONTAKT

Gdyby kiedykolwiek było niejasne „dlaczego tak a nie inaczej":
- **Architektoniczna dyskusja z constitucją:** zobacz `_docs/01_KONSTYTUCJA.md` + konwersacja z 2026-04-23 (sanity check §4 Klocki Lego + „Prawo zera zaufania między domenami") — to jest powód dla którego NIE publikujemy eventów bezpośrednio z storefrontu.
- **Techniczne decyzje P1–P4:** `_docs/16_RESILIENT_POS.md` sekcje 5–7B.
- **Event system (bus bazowy):** `_docs/09_EVENT_SYSTEM.md`.

---

> **Koniec dokumentu.**
>
> Gdy to czytasz przygotowując się do wznowienia prac — pamiętaj: rozmrożenie to jawna decyzja właściciela produktu, nie inicjatywa AI. Jeśli masz wrażenie że „szybki fix" rozwiąże problem offline — prawdopodobnie chcesz dotknąć zamrożony plik. STOP, spytaj.
