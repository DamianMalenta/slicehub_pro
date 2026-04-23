# 16. RESILIENT POS — Architektura „Local-first, Cloud-synced"

> # 🧊 CODE FREEZE — 2026-04-23
>
> **Ten moduł jest zamrożony w połowie prac.** Ukończono fazy **P1, P2, P3, P3.5, P4** (MVP offline-first działa). Fazy **P4.5 (worker_pos_fanout), P5 (multi-device), P6 (conflict UI + fantom cards), P7 (offline auth), P8 (SSE + demo)** są **świadomie odłożone**.
>
> **Ten plik jest spec'em historycznym + planem — nie TODO.** Autorytatywną instrukcję (freeze manifest, inwentarz plików pod ochroną, kryteria akceptacji, kolejność rozmrażania) trzyma [`_docs/17_OFFLINE_POS_BACKLOG.md`](./17_OFFLINE_POS_BACKLOG.md). Czytaj TAM zanim cokolwiek zaczniesz.
>
> **Zakazy** (wybór — pełna lista w backlogu §4.4):
> - ❌ refaktor `PosApiOutbox.js`, `PosSyncEngine.js`, `PosLocalStore.js`, `pos_sw_register.js`
> - ❌ edycja migracji 039 / 040 ani tabel `sh_pos_terminals` / `sh_pos_sync_cursors` / `sh_pos_op_log` / `sh_pos_server_events`
> - ❌ nowe akcje w `api/pos/sync.php`
> - ❌ bezpośredni `INSERT INTO sh_pos_server_events` z innych modułów — producenci piszą przez `sh_event_outbox` (m026), tłumaczy je `scripts/worker_pos_fanout.php` (P4.5, zamrożone)
>
> **Kto rozmraża:** tylko właściciel produktu, jawną decyzją. AI nie rozmraża samodzielnie.

> **Status (spec):** MVP P1–P4 DONE, dalsze fazy zamrożone 2026-04-23.
> **Poprzedzające:** audyt POS 2026-04-23 (53 KB silnik + 255 KB frontend, zero offline handling).
> **Autorytatywny kierunek dla POS offline/sync.** Każdy konflikt ze starszymi dokumentami — ten plik wygrywa.
>
> **Filozofia:** kasjer nigdy nie widzi „brak połączenia". POS działa na lokalnej bazie, sieć/backend to asynchroniczna replika.

---

## 0. DECYZJA W JEDNYM ZDANIU

> **POS operuje na lokalnym IndexedDB jako Source of Truth. Serwer jest asynchroniczną repliką. Awaria sieci nie dotyka kasjera — widzi tylko dyskretną ikonkę chmury w rogu.**

Konkurencja (Square, Toast, Lightspeed, Quatro, ePOS, SGT) — wszyscy traktują offline jako **degraded mode**. SliceHub traktuje offline jako **domyślny stan**, a online jako jego wzbogacenie. To jest źródłowa zmiana paradygmatu, nie cecha produktu.

---

## 1. DLACZEGO

| Sytuacja | Obecny POS (pre-Resilient) | Resilient POS |
|---|---|---|
| Sieć pada w trakcie uderzania zamówienia | ❌ Spinner, błąd, utrata wprowadzonych danych | ✅ Zamówienie zapisane lokalnie, ikona chmury żółta |
| Panic button bez neta | ❌ Nic się nie dzieje | ✅ Zapisane, wysłane gdy sieć wróci |
| Drukarka paragonów offline | ❌ Błąd drukowania | ✅ Paragon queued, lokalny numer deterministic |
| Dwaj kasjerzy w dwóch POS-ach | ⚠️ Race condition | ✅ BroadcastChannel lock + mirror |
| PIN login bez neta | ❌ Login fail | ✅ Ostatni ważny token offline 24h |
| Snapshot menu po awarii | ❌ Pusty ekran | ✅ Ostatni widoczny snapshot |
| Synchronizacja po powrocie sieci | ❌ Nie dotyczy | ✅ Adaptive throttle, deterministic order, conflict-resolution |

---

## 2. ARCHITEKTURA CZTEROWARSTWOWA

```
┌─────────────────────────────────────────────────────────┐
│  L4: POS UI (pos_app.js, pos_ui.js)                     │
│      Bez zmian w logice. Jedynie dodatkowe              │
│      komponenty wskaźników (ikona chmury, queue count). │
├─────────────────────────────────────────────────────────┤
│  L3: PosAPI-v2 (pos_api.js — rewrite w P4)              │
│      - nie woła fetch() bezpośrednio                    │
│      - woła PosLocalStore.mutate() → natychmiastowy     │
│        zwrot                                            │
│      - w tle PosSyncEngine.push()                       │
│      - read: stale-while-revalidate z cache             │
├─────────────────────────────────────────────────────────┤
│  L2: PosLocalStore (IndexedDB + event log)              │
│      2 object store'y:                                  │
│        - state    — aktualny stan świata (orders,       │
│                     tables, cart, menu snapshot, users) │
│        - outbox   — pending ops do wysłania             │
│      Każda mutacja = {op, payload, clientUuid, ts}      │
│      TTL 30 dni, GC raz na boot, max 500 orders.        │
├─────────────────────────────────────────────────────────┤
│  L1: PosSyncEngine (dedicated worker + adaptive loop)   │
│      - online: push outbox → pull delta (long-poll/SSE) │
│      - offline: pauza, exponential backoff              │
│      - conflict: deterministic merge z op_id UUID v7    │
│      - cross-tab: BroadcastChannel coordination         │
└─────────────────────────────────────────────────────────┘
```

---

## 3. PIĘĆ INNOWACJI, KTÓRYCH NIE MA KONKURENCJA

### 3.1 Optimistic UI z **rollback animation** (nie „error toast")

Kliknięcie „Zatwierdź zamówienie" od razu pojawia się na pulpicie. Gdy kiedyś (rzadko) serwer odrzuci (konflikt stolika, stan magazynu), POS **animuje cofnięcie** z podświetleniem: „Stolik 4 został właśnie zajęty przez inny POS — wybierz inny". Zamiast błędu — automatyczna rekonstrukcja.

### 3.2 Deterministic UUID v7 + event sourcing

Każde zamówienie/płatność ma **clientUuid (UUID v7, time-sorted)**. Gdy POS A był offline 3h, a POS B non-stop pchał — serwer po synchronizacji widzi wszystko w poprawnej chronologii. Żaden polski konkurent tego nie robi.

### 3.3 Multi-device mirror via BroadcastChannel

Dwa POS-y w tej samej pizzerii → **widzą się nawzajem przez BroadcastChannel API**, bez pośrednictwa serwera. POS A uderza zamówienie → POS B w sąsiednim pomieszczeniu widzi je za 200 ms — nawet gdy oba mają wyłączony WiFi. Scenariusz: światłowód padł na ulicy, obie kasy pracują, synchronizują się nawzajem. Gdy sieć wraca — cała chronologia leci do serwera.

Technologie:
- `BroadcastChannel('slicehub-pos')` — state mirror
- `navigator.locks.request(...)` — prevent-double-submit critical sections
- `clientId` generated per POS device (persisted w localStorage)

### 3.4 Self-healing sync

Awaria sieci była 2h, accumulated 400 ops. **Naive approach:** wal wszystko naraz → backend pada. **SliceHub approach:** adaptive throttle — pcha pasami po 20 ops, mierzy p95 latency, zwalnia jak widzi spadek, przyspiesza jak backend wraca do formy. **Priorytetyzuje:**
1. Zamówienia zaakceptowane (payment collected)
2. Zamówienia wywołane (process_order)
3. Fire_course / kitchen events
4. Kasjerskie operacje edycyjne
5. Cache reheat (read-only pulls)

Dead-letter queue po 10 nieudanych próbach → UI alert dla kasjera.

### 3.5 Receipt ledger deterministic — fiskalizacja offline-safe

Numery paragonów generowane lokalnie formułą `{tenant}-{pos_id}-{yyyymmdd}-{seq}`. 3 kasy offline nie wygenerują kolidujących numerów. Serwer **waliduje chronologię**, nie generuje numerów. Drukarka fiskalna odbierze paragon gdy wróci (lokalny agent ESC/POS lub Web Bluetooth).

---

## 4. ROADMAPA

| Faza | Cel | Pliki | Status |
|------|-----|-------|--------|
| **P1** | Fundament PWA — instalowalność + precache + offline fallback | `manifest.webmanifest`, `icon.svg`, `icon-maskable.svg`, `sw.js`, `offline.html`, `pos_sw_register.js`, `index.html` hooks | ✅ **DONE 2026-04-23** |
| **P2** | IndexedDB Store — schema `state` + `outbox` + `event_log` + TTL/GC + cross-tab | `js/PosLocalStore.js`, integracja w `pos_sw_register.js`, UUID v7 generator | ✅ **DONE 2026-04-23** |
| **P3** | Sync Engine (slice) — register_terminal + push_batch + adaptive throttle + dead-letter | `js/PosSyncEngine.js`, `api/pos/sync.php`, migracja `039_resilient_pos.sql` | ✅ **DONE 2026-04-23** |
| **P3.5** | Server→Client delta stream — akcja `pull_since`, tabela `sh_pos_server_events`, pull-loop w `PosSyncEngine` | `api/pos/sync.php` (+pull_since +publish_test_event), migracja `040_pos_server_events.sql`, `js/PosSyncEngine.js` (+_pullTick, onServerEvent) | ✅ **DONE 2026-04-23** |
| **P4** | Optimistic layer MVP — `PosApiOutbox` wrapper (mutacje przez outbox gdy offline), replay loop, toast UX | `js/PosApiOutbox.js`, hook w `pos_sw_register.js`, jedna linia w `pos_app.js` | ✅ **DONE 2026-04-23** (MVP — rollback animations + fantom-cards w P6) |
| **P5** | Multi-device mirror — BroadcastChannel + navigator.locks | `js/PosBroadcast.js` | 📋 TODO |
| **P6** | Conflict resolution + rollback animation UX + fantom-cards | `js/pos_conflict.js`, CSS rollback, `pos_ui.js` fantom | 📋 TODO |
| **P7** | Offline PIN login + token cache 24h | `api/auth/login.php` (zmiana), `js/PosAuthCache.js` | 📋 TODO |
| **P8** | Playbook + dokumentacja + demo | ten plik (finalizacja), `_docs/canvasy/RESILIENT_POS.md` | 📋 TODO |

**Razem: ~9–10 sesji.** To nie hotfix — to produktowy kierunek.

---

## 5. FAZA 1 — PWA FOUNDATION (zaimplementowano 2026-04-23)

### 5.1 Pliki dodane

| Plik | Rola | Uwagi |
|------|------|-------|
| `modules/pos/manifest.webmanifest` | PWA install metadata | `display: fullscreen`, `orientation: landscape`, 3 shortcuts (Nowe zamówienie, Sala, Kursy) |
| `modules/pos/icon.svg` | Main app icon | Skrót POS: kasa z monogramem SH + POS |
| `modules/pos/icon-maskable.svg` | Maskable icon (Android adaptive) | Uproszczony monogram na jednolitym tle |
| `modules/pos/sw.js` | Service Worker | 3 cache'y (static, runtime, api), 3 strategie (nav network-first, static SWR, api-read SWR) |
| `modules/pos/offline.html` | Offline fallback | Dark glass UI, net indicator, auto-reload gdy sieć wraca |
| `modules/pos/js/pos_sw_register.js` | SW registration + connectivity API | Eksponuje `window.SliceHubPOS.connectivity`, toast system, install prompt |
| `modules/pos/index.html` | PWA hooks | `<link rel=manifest>`, theme-color, apple-touch-icon, defer register script |

### 5.2 Strategie cache'a w sw.js

| Routing | Strategia | Cache | Fallback |
|---------|-----------|-------|----------|
| Navigation (HTML) | network-first (3.5s timeout) | `static` | cached → `offline.html` → plain-text 503 |
| Static (css/js/svg/ico/font) | stale-while-revalidate | `runtime` | — |
| API read (`get_init_data`, `get_item_details`, `get_orders`, `estimate.php`) | network-first (2.5s) | `api` | cached z `X-SliceHub-Cache: stale` header |
| API mutations (POST) | NETWORK ONLY | — | — |

**Świadomie nie kolejkujemy mutacji w P1.** Wszystkie POST-y lecą prosto do backendu — bo kolejkowanie bez LocalStore (P2) i Sync Engine (P3) to ryzyko utraty danych. Lepiej zero udawania w P1 niż udawane gwarancje.

### 5.3 Wersjonowanie i update flow

- `CACHE_VERSION = 'slicehub-pos-v1'` — bump przy każdej zmianie listy `PRECACHE`.
- Install: `skipWaiting()` — nie czekamy na zamknięcie wszystkich tabów (POS chodzi całą zmianę).
- Activate: usuwa stare cache'e, claim wszystkich klientów, wysyła `SW_UPDATED` do każdego.
- Klient (`pos_sw_register.js`): toast „Zaktualizowano, odśwież" z przyciskiem.
- Auto-check co 10 minut — stara wersja w POS to realne ryzyko dryfu.

### 5.4 API dla reszty POS

`window.SliceHubPOS.connectivity`:
```javascript
{
  isOnline: boolean,       // window.navigator.onLine
  swReady:  boolean,       // SW zarejestrowany
  swUpdateAvailable: boolean,
  lastChange: number,      // Date.now() ostatniej zmiany
  getState(): object,      // immutable snapshot
  on(listener): unsubscribe, // subscribe na zmiany
}
```

`window.SliceHubPOS.toast(msg, { variant, actionLabel, onAction, durationMs })`:
- Niezależny od `pos_ui.toast()` — działa też na PIN screen (przed bootem app).
- Warianty: `info` | `success` | `warn` | `error`.

`window.SliceHubPOS.installPWA()`:
- Wywołuje zachowany `beforeinstallprompt`.
- Zwraca `true` gdy user zaakceptował, `false` wpp.

### 5.5 Sukces P1 — weryfikacja

**Testy manualne (user):**
1. ✅ Otwórz `modules/pos/index.html` — w DevTools → Application → Service Workers → status `activated`.
2. ✅ Application → Manifest — widać ikonę, 3 shortcuty, nazwę „SliceHub POS".
3. ✅ W Chrome lub Edge — ikona „Zainstaluj aplikację" w pasku adresu.
4. ✅ Po instalacji — POS otwiera się jako osobne okno bez chrome'a przeglądarki.
5. ✅ DevTools → Network → Offline → F5 — POS ładuje się nadal (z cache), widać pill „Offline".
6. ✅ Kliknięcie przycisku przy pustym cache API — fallback JSON `{ success: false, _offline: true }`.

**Znane ograniczenia P1:**
- Mutacje (proces_order, accept_order itd.) NIE są kolejkowane. Kasjer, który kliknie „Zatwierdź" offline, zobaczy błąd. **Rozwiązanie: P3.**
- IndexedDB nie jest jeszcze używany. **P2.**
- Brak multi-device mirror. **P5.**
- Brak offline PIN login. **P7.**

---

## 6. FAZA 2 — IndexedDB Store (zaimplementowano 2026-04-23)

### 6.1 Schema

Baza `slicehub-pos`, wersja 1, trzy object store'y:

```javascript
'state': {
  keyPath: 'key',                   // 'orders', 'menu', 'users', 'currentUser', ...
  // value: { key, data, updatedAt, ttl }   // ttl = timestamp lub null
}

'outbox': {
  keyPath: 'opId',                  // UUID v7 (time-sorted)
  indexes: {
    'status':    'status',          // 'pending' | 'sending' | 'done' | 'dead' | 'conflict'
    'createdAt': 'createdAt',       // epoch ms
    'retries':   'retries',         // int
  },
  // value: { opId, action, payload, status, createdAt, retries, attempts[],
  //          lastError, dedupeKey, clientUuid, conflictInfo }
}

'event_log': {
  keyPath: 'eventId',               // UUID v7
  indexes: { 'ts': 'ts' },
  // value: { eventId, type, data, ts } — append-only audit
}
```

### 6.2 Client UUID (device identity)

Każde urządzenie POS dostaje stabilne ID (UUID v7) przy pierwszym uruchomieniu:

```javascript
// localStorage: 'slicehub_pos_client_uuid'
// Przeżywa odinstalowanie PWA → re-install (bo localStorage nie jest czyszczone
// razem z IndexedDB). Używane przez Sync Engine P3 do identyfikacji źródła ops.
```

### 6.3 UUID v7 generator

Własna implementacja zgodna z RFC 9562 §5.7 (time-sorted):
```
<48-bit unix_ts_ms> <4-bit version=7> <12-bit rand_a>
<2-bit variant=10> <62-bit rand_b>
```

Gwarancja: dwa POS-y offline generują UUIDs, które po synchronizacji z backendem
lądują w serwerowej bazie w realnej chronologii — bez negocjacji zegara.

### 6.4 Publiczne API

```javascript
import PosLocalStore, { uuidv7, getClientUuid } from './PosLocalStore.js';
// (albo: window.SliceHubPOS.store — jeśli już po load)

// ── STATE ─────────────────────────────────────────────────────────
await PosLocalStore.putState('menu', menuArr, { ttlMs: 7 * 24 * 3600 * 1000 });
const menu = await PosLocalStore.getState('menu');  // null gdy TTL expired
await PosLocalStore.deleteState('menu');

const unsub = PosLocalStore.subscribeState('orders', (updated) => {
    // wywołane po każdym putState('orders', ...) — z tego taba LUB innego
});

// ── OUTBOX ────────────────────────────────────────────────────────
const opId = await PosLocalStore.enqueueOp('process_order', payload, {
    dedupeKey: 'order_<clientOrderUuid>',   // opcjonalne — zapobiega podwójnemu dodaniu
});

await PosLocalStore.markSending(opId);
await PosLocalStore.markSent(opId, serverResponse);
await PosLocalStore.markFailed(opId, 'Network timeout', { incrementRetry: true });
await PosLocalStore.markConflict(opId, { reason: 'table_taken', serverState });

const pending = await PosLocalStore.listPendingOps({ limit: 100 });
const counts  = await PosLocalStore.outboxCountByStatus();
// { pending: 3, sending: 0, done: 47, dead: 0, conflict: 1 }

const unsubOutbox = PosLocalStore.subscribeOutbox((counts) => {
    // wywołane po enqueueOp / markSent / markFailed itd.
});

// ── EVENT LOG (audit) ─────────────────────────────────────────────
await PosLocalStore.appendEvent('cart:cleared', { orderId });
const events = await PosLocalStore.listEvents({ since: yesterdayMs, limit: 500 });

// ── DIAGNOSTICS ───────────────────────────────────────────────────
const diag = await PosLocalStore.diag();
// { dbName, dbVersion, clientUuid, stateKeys[], outboxCounts, lastEventAt,
//   broadcastChannelSupported }

// ── DANGER ZONE ───────────────────────────────────────────────────
await PosLocalStore.reset();           // wipe DB (np. factory reset z settings)
```

### 6.5 Cross-tab synchronization (fundament P5)

`BroadcastChannel('slicehub-pos')` emituje `state:changed` i `outbox:changed`.
Każdy otwarty tab POS-a dostaje update w tym samym momencie bez pośrednictwa
serwera. Nawet offline. To jest MVP multi-device mirror (P5 rozbuduje o
`navigator.locks` dla critical sections).

Backward-compat: gdy przeglądarka nie wspiera BroadcastChannel (Safari < 15.4),
mechanizm jest wyłączony; aplikacja nadal działa, tylko bez cross-tab events.

### 6.6 Garbage Collection

Automatyczny GC przy każdym bootcie (4 s delay, nie blokuje startu):
- `state` entries z `ttl < now` → delete
- `outbox` entries status `done` starsze niż 7 dni → delete
- `outbox` entries status `dead` starsze niż 30 dni → delete
- `event_log` entries starsze niż 30 dni → delete

Pierwsze odpalenie po dniu bez GC nigdy nie spowoduje memory spike — wszystko
async z `openCursor()`, małe batche.

### 6.7 Integracja w pos_sw_register.js

`window.SliceHubPOS.store` jest dostępny po `load` + dynamic import:
```javascript
window.SliceHubPOS.store       // singleton PosLocalStore
window.SliceHubPOS.uuidv7      // generator
window.SliceHubPOS.connectivity.getState().clientUuid   // device UUID
```

Wskaźnik w prawym górnym rogu (pill) pokazuje agregat outboxu:
- `Online` — wszystko zsynchronizowane
- `Synchronizuję · N` — N opów w kolejce, trwa push
- `Offline · N w kolejce` — brak sieci, POS działa dalej
- `Konflikt · N` — żółty alert, user powinien rozstrzygnąć
- `Uwaga · N nie dostarczonych` — czerwony, dead-letter queue

### 6.8 Znane ograniczenia P2

- **Outbox nie jest jeszcze konsumowany** — to robi PosSyncEngine (P3). Dziś enqueueOp tylko zapisuje, nic nie wysyła.
- **Integracja z pos_api.js zero** — świadome. Rewrite `pos_api.js` → warstwa P4 (Optimistic layer). W P2 tylko fundament.
- **Brak encryption at rest** — IndexedDB nie jest szyfrowane. Nie przechowujemy tu danych wrażliwych (PIN-y hashowane). Rozważyć w P7 dla tokenów offline login.

---

## 7. FAZA 3 — Sync Engine (zaimplementowano 2026-04-23, slice MVP)

### 7.1 Endpoint backendu `api/pos/sync.php`

Trzy akcje (P3-slice):

| Akcja | Request | Response |
|-------|---------|----------|
| `register_terminal` | `{ device_uuid, label?, app_version? }` | `{ terminal_id, device_uuid, server_ts }` |
| `push_batch` | `{ terminal_id, ops: [{ opId, action, payload, createdAt, clientUuid }...] }` | `{ results: [{ op_id, status, server_ref?, latency_ms, error? }], summary }` |
| `diag` | — | `{ tenant_id, terminals_count, oplog_24h, known_actions }` |

**Zarezerwowane na P3.5/P4/P6:**
- `pull_since` (delta serwer→klient) — P3.5, gdy manager zmienia coś w web-panelu i POS ma to zobaczyć
- `resolve_conflict` (manualne rozstrzygnięcie) — P6, razem z UI rollback animation

### 7.2 Idempotency guarantee

`op_id` jest PRIMARY KEY w `sh_pos_op_log`. Drugie przesłanie tego samego opa:
- Jeśli istnieje wpis o status='applied' → serwer zwraca poprzedni wynik z `idempotent: true`. Nie wykonuje akcji ponownie.
- Pozwala klientowi bezpiecznie robić retry bez obawy o duplikaty.

Dzięki temu POS, który stracił odpowiedź na batch push (timeout sieci w trakcie odpowiedzi), może spokojnie spróbować ponownie z tym samym opIdem.

### 7.3 Obsługiwane akcje w P3-slice (MVP)

W P3-slice serwer obsługuje **3 akcje diagnostyczne**:

| Action | Zachowanie |
|--------|-----------|
| `test_action` | Echo payloadu z powrotem, status='applied'. Dla smoke testów end-to-end. |
| `ping` | Zwraca server_ts. Health check. |
| `client_event` | Loguje wpis w sh_pos_op_log bez side-effects. Dla telemetrii event_log. |

**Wszystkie inne akcje (process_order, accept_order, settle_and_close itd.) są rejected** z errorem „unsupported action in P3-slice". Integracja z realnymi akcjami POS-a to **P4 (Optimistic Layer)** — wymaga rewrite akcji w `api/pos/engine.php` żeby akceptowały client-side op_id.

### 7.4 PosSyncEngine — timing

```
  online + empty outbox       → IDLE_DELAY   = 30 s
  online + pending ops        → ACTIVE_DELAY =  2 s
  offline                     → IDLE_DELAY,  nasłuchuje 'online'
  po błędzie (network/4xx/5xx)→ exponential backoff: 2 → 4 → 8 → 16 → 32 → 60 s (max)
  BATCH_LIMIT                 = 50 ops per push
  REQUEST_TIMEOUT             = 15 s
```

**Kick-in triggery:**
- `window.online` event → przerywa backoff, od razu sync
- `store.subscribeOutbox` → gdy enqueueOp, od razu sync (jeśli już nie inFlight)
- `PosSyncEngine.triggerSync()` → manualny kick z UI

### 7.5 Migracja `039_resilient_pos.sql`

Trzy tabele (idempotent, MariaDB 10.4+):

- **`sh_pos_terminals`** — id, tenant_id, device_uuid (UUID v7), label, last_seen_at, last_user_id, last_user_agent, last_ip, app_version, ops_received/applied/rejected liczniki
- **`sh_pos_sync_cursors`** — terminal_id, tenant_id, pull_cursor_ts, push_cursor_ts, last_sync_at, last_error
- **`sh_pos_op_log`** — op_id (PK, UUID v7), terminal_id, tenant_id, user_id, action, payload_json, status ENUM, server_ref, applied_at, latency_ms, error_text, client_created_at

Wszystkie tabele z FK do `sh_tenant` (CASCADE DELETE), unique constraintem `(tenant_id, device_uuid)` dla terminal, indeksami dla szybkich query dead-letter i per-action analytics.

### 7.6 Flow end-to-end testu

```javascript
// 1. POS się ładuje → pos_sw_register.js bootstrap
//    → PosLocalStore.open() → PosSyncEngine.start()
//    → wysyła register_terminal, otrzymuje terminal_id

// 2. W konsoli devtools:
await window.SliceHubPOS.store.enqueueOp('test_action', { hello: 'resilient pos' });
// → outbox: 1 pending, pill "Synchronizuję · 1"

// 3. Engine w loop push_batch → api/pos/sync.php
//    → sh_pos_op_log dostaje wpis, status='applied'
//    → serwer zwraca { op_id, status: 'applied', echo: { hello: 'resilient pos' } }
//    → store.markSent(op_id)
//    → pill znika (wraca "Online")

// 4. Test offline:
//    DevTools → Network → Offline
await window.SliceHubPOS.store.enqueueOp('test_action', { offline_test: true });
// → pill "Offline · 1 w kolejce"

// 5. Network → Online → automatyczny triggerSync → op leci do serwera
// → pill "Online" → w sh_pos_op_log widać 2 wpisy

// 6. Test dead-letter:
await window.SliceHubPOS.store.enqueueOp('nonexistent_action', { foo: 'bar' });
// → serwer rejected → markDead → pill "Uwaga · 1 nie dostarczonych"
```

### 7.7 Publiczne API

```javascript
window.SliceHubPOS.sync       // singleton PosSyncEngine
window.SliceHubPOS.sync.getStatus()
// { isRunning, terminalId, inFlight, lastSyncAt, lastError, currentBackoff }

window.SliceHubPOS.sync.triggerSync()   // manualny kick
window.SliceHubPOS.sync.stop()          // w testach

const unsub = window.SliceHubPOS.sync.on('sync:batch-done', ({ data }) => {
    console.log('batch applied', data.applied, 'rejected', data.rejected);
});
```

### 7.8 Znane ograniczenia P3-slice (snapshot — zobacz 7A/7B co z tego zostało)

- ~~**Brak pull_since**~~ → zaimplementowane w **P3.5** (sekcja 7A).
- ~~**Brak integracji z process_order / accept_order / settle_and_close**~~ → zaimplementowane w **P4** (sekcja 7B).
- **Brak resolve_conflict UI** — conflict ops lądują w outbox jako 'conflict' ale nie ma ekranu dla usera żeby rozstrzygnąć. **P6.**
- **Brak WebSocket / Server-Sent Events** — wszystko long-polling po schedule. **P5/P8** dla real-time KDS push.
- **Brak fiskalizacji offline** — drukarka ESC/POS bridge w **P6+**, Web Bluetooth.

---

## 7A. FAZA 3.5 — SERVER→CLIENT DELTA (zaimplementowano 2026-04-23)

### 7A.1 Problem, który rozwiązuje

P3 daje tylko push: POS → serwer. Ale POS musi też dowiedzieć się o zmianach powstałych poza nim:
- Zamówienie złożone na storefronta (powinno pojawić się w widoku "Nowe online")
- KDS zmienił status zamówienia (POS widzi to natychmiast, nie dopiero po `_fetchOrders` co 8s)
- Admin zmienił menu (POS dostaje sygnał refresh menu cache)
- Drugi POS przesunął rezerwację stolika (multi-device mirror — podstawa pod P5)

### 7A.2 Architektura

```
┌──────────────┐  1) INSERT event     ┌──────────────────────┐
│ storefront / │ ───────────────────► │ sh_pos_server_events │
│ KDS / admin  │                       │  (append-only log)   │
└──────────────┘                       └──────────┬───────────┘
                                                  │ 2) POS robi pull_since
                                                  ▼
┌─────────────────────────┐    POST action=pull_since       ┌────────────────┐
│ PosSyncEngine._pullTick │ ◄─────────────────────────────► │ api/pos/sync   │
│ co 3-15s (adaptive)     │  { since_ts, limit } → events   │   .php         │
└──────────┬──────────────┘                                  └────────────────┘
           │ 3) applyServerEvent → dispatch
           ▼
┌────────────────────────────────────┐
│ window 'slicehub-pos:server-event' │ — pos_app.js reaguje _fetchOrders()
└────────────────────────────────────┘
```

### 7A.3 Nowe akcje sync.php

| Action | Payload (req) | Response | Uwagi |
|--------|---------------|----------|-------|
| `pull_since` | `{ terminal_id, since_ts?, limit? }` | `{ events[], count, has_more, cursor }` | Zwraca max 200 eventów. Cursor to `created_at` ostatniego eventu. |
| `publish_test_event` | `{ label?, payload? }` | `{ id, event_type, server_ts }` | DEV-only — wstrzykuje `system.test` do streamu dla smoke testów. |

### 7A.4 Migracja `040_pos_server_events.sql`

- **`sh_pos_server_events`** — `id BIGINT`, `tenant_id`, `event_type` (np. `order.created`), `entity_type`/`entity_id`, `payload_json`, `origin_kind`/`origin_ref`, `created_at DATETIME(3)`. Indeksy: tenant+created, tenant+type+created, tenant+entity, created (GC).
- Rozszerzenie `sh_pos_sync_cursors`: `pull_events_total`, `pull_last_count`, `pull_last_fetched_at` (diagnostyka zdrowia pull loop).

### 7A.5 Timing pull-loop

```
  online + były eventy + has_more  → 0 ms       (paginacja natychmiast)
  online + były eventy             → 3 s        (może być więcej)
  online + zero eventów            → 15 s       (idle)
  offline                          → pauza na 'online' event
```

### 7A.6 API klienta

```javascript
window.SliceHubPOS.sync.getStatus();
// dodatkowe pola: pullInFlight, pullCursor, pullEventsTotal, pullLastCount, pullLastAt, pullLastError

window.SliceHubPOS.sync.triggerPull();   // manualne kopnięcie pull (np. po F5)

window.SliceHubPOS.sync.onServerEvent((ev) => {
    // ev = { id, event_type, entity_type, entity_id, payload, origin_kind, origin_ref, created_at }
});

// Równolegle emitowane jako DOM event:
window.addEventListener('slicehub-pos:server-event', (e) => {
    // e.detail === ev
});
```

### 7A.7 Test end-to-end (DevTools)

```javascript
// 1. Sprawdź status pulla
window.SliceHubPOS.sync.getStatus();
// → { pullCursor: null, pullEventsTotal: 0, ... }

// 2. Wstrzyknij testowy event przez DEV helper
await fetch('/slicehub/api/pos/sync.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + localStorage.sh_token },
    body: JSON.stringify({ action: 'publish_test_event', label: 'smoke-1' })
}).then(r => r.json());

// 3. Po max 15s (idle) albo natychmiast po triggerPull:
window.SliceHubPOS.sync.triggerPull();

// 4. Za chwilę w konsoli: [SliceHub POS · SW] pull delta { count: 1, cursor: '2026-04-23 10:00:00.123' }
// 5. window.SliceHubPOS.sync.getStatus() → pullEventsTotal: 1
```

---

## 7B. FAZA 4 — OPTIMISTIC LAYER MVP (zaimplementowano 2026-04-23)

### 7B.1 Problem, który rozwiązuje

W P3 outbox był pusty — POS dalej pukał direct do `pos_api.js` → `engine.php`. Efekt:
- Offline click „Zatwierdź" → `Network error` toast → user traci intencję.
- Po powrocie sieci trzeba ręcznie klikać jeszcze raz.
- Outbox IndexedDB nie był używany dla realnych mutacji.

P4 MVP integruje outbox z mutacjami POS bez przepisywania `engine.php`.

### 7B.2 Architektura

```
pos_app.js
  import PosAPI from './PosApiOutbox.js';     ← jedyna zmiana!
  await PosAPI.processOrder(payload);         ← wygląda jak stare PosAPI

                    ▼
      ┌────────────────────────────┐
      │ PosApiOutbox (Proxy nad    │
      │   oryginalnym PosAPI)      │
      └──────────────┬─────────────┘
                     │
          online?    ▼    reads (getOrders)
      ┌──────────────┴────────────────┐
      │ YES: fetch engine.php         │
      │  OK: return live response     │
      │  network err: → enqueue       │
      │                                │
      │ NO (offline):                  │
      │  enqueue do PosLocalStore      │
      │  toast: "Zakolejkowane…"       │
      │  return { success:true,        │
      │           data:{queued:true}}  │
      └──────────────┬────────────────┘
                     │
        ┌────────────▼────────────┐
        │ _replay loop co 2-15 s  │
        │ online + pending > 0    │
        └────────────┬────────────┘
                     │
                     ▼ markSent
              engine.php (realny)
                     │
                     ▼
        refetch:orders event → _fetchOrders()
```

### 7B.3 Interceptor — kiedy enqueue, kiedy pass-through

| Stan | Zachowanie | Response |
|------|-----------|----------|
| `navigator.onLine === true` + engine.php odpowiada 200 | Pass-through | Live response (stary kształt) |
| `navigator.onLine === true` ale engine.php zwraca `{ok:false, status:0}` (network) | Enqueue + toast | `{success:true, data:{queued:true, op_id, pending_count, reason:'network-error-on-live'}}` |
| `navigator.onLine === false` | Enqueue od razu + toast | jw. z `reason:'offline'` |
| throw z fetch (rzadkie) | Enqueue + toast | jw. z `reason:'exception'` |

### 7B.4 Dedupe

Klucz: `method + JSON.stringify(args)`. Zapobiega sytuacji „kasjer w panice klika 3× Zatwierdź offline" → jeden op w outboxie zamiast trzech.

### 7B.5 Replay loop — polityka retry

- **Co 2s** (aktywny, są pending) / **15s** (idle).
- **Per-op:** `markSending` → `PosAPI[method](...args)` → `markSent` przy `success:true`.
- **Network error** (status 0): `markFailed` + backoff 2→4→8→16→32→60s.
- **Server rejected** (4xx/5xx z `success:false`): `markFailed` + retry. Po **5 retries** → `markDead` (dead-letter). Toast „X operacji odrzuconych — wymagają manualnej akcji".
- **Unknown method w payloadzie**: od razu `markDead` (stara wersja outboxa → nowa wersja klienta nie zna metody).

### 7B.6 API klienta

```javascript
window.SliceHubPOS.apiOutbox          // singleton
window.SliceHubPOS.apiOutbox.getStatus()
// { running, inFlight, lastReplayAt, lastReplayError, currentBackoff }

window.SliceHubPOS.apiOutbox.triggerReplay()    // manualny kick

window.SliceHubPOS.apiOutbox.on('replay:done', ({ data }) => {
    // { applied, failed, dead, total }
});
```

### 7B.7 Jak UI wie że coś jest „queued"

Response z queued=true ma `success: true` (żeby stare ścieżki success→toast działały), ale dodatkowo `data.queued = true`. Plus **automatyczny toast** „Zakolejkowane — wyślę gdy sieć wróci" (variant `warn`/`info` zależnie od reason).

Pill (top-right) pokazuje realny stan: `Offline · 3 w kolejce` / `Synchronizuję · 1` / `Online`.

Po udanym replayie: toast `success`: „Zsynchronizowano N operacji offline" + `window.dispatchEvent('slicehub-pos:outbox-replayed')` → `pos_app.js` robi `_fetchOrders()`.

### 7B.8 Test end-to-end (DevTools)

```javascript
// 1. POS zalogowany (PIN), widzi pusty widok albo zamówienia.

// 2. DevTools → Network → Offline
// 3. Stwórz zamówienie w kreator → "Zatwierdź"
//    → toast "Zakolejkowane — wyślę gdy sieć wróci" (żółty)
//    → pill "Offline · 1 w kolejce"

// 4. Sprawdź outbox:
(await window.SliceHubPOS.store.listPendingOps({ limit: 10 }))
// → [{ opId: '...', action: 'pos.processOrder', payload: { method, args: [...] }, status: 'pending' }]

// 5. DevTools → Network → Online
//    → engine.onlineHandler + outbox replay triggeruje się natychmiast
//    → w Console: "replay:done applied=1 failed=0 dead=0 total=1"
//    → toast zielony "Zsynchronizowano 1 operację offline"
//    → pill znika (Online)
//    → _fetchOrders() refetchuje listę → zamówienie pojawia się z realnym ID

// 6. Test dead-letter (5 fails na tej samej nieistniejącej akcji):
//    Gdyby outbox zawierał op o metodzie 'unknownThing' →
//    markDead od razu (unknown-method) → pill "Uwaga · 1 nie dostarczonych"
```

### 7B.9 Co *nie* wchodzi w P4 MVP (świadomie odłożone)

- **Fantom-cards w `pos_ui.js`** — karta zamówienia z tymczasowym opId widoczna przed server ACK z animacją "commit". Wymaga restrukturyzacji `_orders[]` w `pos_app.js`. **P6.**
- **Rollback animation** — gdy replay zawiedzie → karta znika z efektem. **P6.**
- **Dead-letter UI** — ekran z listą rejected ops + przyciski „Spróbuj ponownie" / „Odrzuć". **P6.**
- **Zapis client op_id na serwerze** — teraz engine.php nie wie, że replay to ten sam op co offline attempt. Przy podwójnym replayu (np. sieć mignęła w trakcie) dostaniemy duplikat. Dla MVP dedupe w outboxie załatwia 99% przypadków; pełne idempotency przez op_id → **P5** (rewrite engine actions z client_op_id).

### 7B.10 Gdzie jest kod

| Plik | Rola | LoC |
|------|------|-----|
| `modules/pos/js/PosApiOutbox.js` | Wrapper, replay loop, interceptor | ~320 |
| `modules/pos/js/pos_sw_register.js` | Bootstrap `outbox.start({ store })` + eventy | +50 |
| `modules/pos/js/pos_app.js` | Import zmieniony na `./PosApiOutbox.js`, listener on `outbox-replayed` + `server-event` | +20 |
| `modules/pos/sw.js` | PRECACHE `PosApiOutbox.js`, bump `v4 → v5` | +1 |

---

## 8. RYZYKA I MITIGATION

| Ryzyko | Prawdopodobieństwo | Mitigation |
|--------|--------------------|-----------|
| IndexedDB quota przekroczona na starym tablecie | Niskie | TTL = 30 dni, GC raz na boot, max 500 orders |
| Konflikt „2 POS robią to samo" | Średnie | `op_id` unique + server deterministic merge w P3 |
| Sync nigdy nie kończy (pętla retry) | Niskie | Dead-letter queue po 10 retries → UI alert |
| Fiskalizacja drukarki offline | Wysokie | ESC/POS local bridge (agent) lub Web Bluetooth — **scope P6+** |
| PWA install UX nieoczywiste | Średnie | „Zainstaluj POS" button w settings, auto-prompt w `pos_sw_register.js` |
| Service worker overrides devtools fetches | Niskie | Scope restricted do `/slicehub/modules/pos/`, whitelist API paths |
| Stara wersja SW kleszczy się | Niskie | auto-update co 10 min, skipWaiting na aktywacji |

---

## 9. RELACJA DO 15_KIERUNEK_ONLINE.md

Storefront ma swoje PWA (`modules/online/sw.js`). Ten dokument **nie zmienia tamtego**. Każdy moduł ma własny scope PWA (`/modules/online/`, `/modules/pos/`, `/modules/driver_app/`) — zero kolizji cache'u.

Wspólny fundament filozoficzny: **local-first, cloud-synced**. W storefroncie to znaczy „klient widzi ostatnie menu nawet offline". W POS to znaczy „kasjer pracuje dalej, niezależnie od sieci".

---

## 10. REFERENCJE

- PWA: [W3C Web App Manifest](https://www.w3.org/TR/appmanifest/), [Service Worker spec](https://www.w3.org/TR/service-workers/)
- UUID v7 (time-sorted): [RFC 9562](https://datatracker.ietf.org/doc/rfc9562/)
- BroadcastChannel: [MDN](https://developer.mozilla.org/en-US/docs/Web/API/BroadcastChannel)
- Web Locks API: [MDN](https://developer.mozilla.org/en-US/docs/Web/API/Web_Locks_API)
- Optimistic UI patterns: Linear, Superhuman, Notion
- Inspiracje: Toast POS (local Linux server model), Square (mobile-first offline)
