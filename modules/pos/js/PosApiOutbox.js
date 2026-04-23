/**
 * SliceHub POS — Outbox-aware API Wrapper (Resilient POS · Phase 4)
 *
 * Zachowuje 1:1 interfejs `PosAPI` (pos_api.js), ale mutacje przepuszcza przez
 * lokalny outbox (IndexedDB). Dzięki temu pos_app.js nie wie, czy request idzie
 * od razu na serwer czy czeka offline — dostaje zawsze spójny response.
 *
 * Model decyzji dla mutacji:
 *   online + fresh         → PosAPI.X(...args) (pass-through, zwykłe zachowanie)
 *   online + network error → enqueue do outboxu + zwróć response z queued=true
 *   offline                → enqueue do outboxu + zwróć response z queued=true
 *
 * Odpowiedź „queued" ma tę samą kształt co zwykła PosAPI:
 *   { ok: true, status: 0, success: true, message: 'Zakolejkowane offline',
 *     data: { queued: true, op_id, pending_count } }
 * Dzięki temu UI może zdecydować: albo pokazać toast, albo „udawać sukces" z
 * rollbackiem gdy replay zawiedzie.
 *
 * Replay loop:
 *   - Startuje automatycznie przy start() (wywoływany przez pos_sw_register).
 *   - Co 2s (aktywny) / 15s (idle) sprawdza outbox.listPendingOps().
 *   - Dla każdego opa: mapPosApiAction(op.action) → rzeczywisty fetch przez
 *     oryginalny PosAPI, markSent przy ok, markFailed przy !ok (z retry).
 *   - Po 5 retry → markDead. UI może później pokazać dead-letter items.
 *
 * Subskrypcja stanu:
 *   PosApiOutbox.on('replay:done', ({ applied, failed }) => refetchOrders());
 *
 * Tenant isolation: payloady nie zawierają tenantu (serwer bierze z auth_guard).
 */

import PosAPI from './pos_api.js';

const LOG_PREFIX = '[SliceHub POS · Outbox]';

// Lista metod PosAPI, które są *mutacjami* i muszą iść przez outbox.
// Reads (getInitData, getOrders, getItemDetails, getAvailableTables) są
// pomijane — cache w SW już robi swoje.
const MUTATION_METHODS = new Set([
    'processOrder',
    'acceptOrder',
    'updateStatus',
    'printKitchen',
    'printReceipt',
    'settleAndClose',
    'cancelOrder',
    'panicMode',
    'assignRoute',
    'createCourse',
    'assignDriverToCourse',
]);

// Ustandaryzowane nazwy akcji w outboxie (stabilne — nie zmieniać bez
// migracji outboxa u uzytkowników w terenie!). Rebuilt na string, żeby
// zapisywały się czytelnie w sh_pos_op_log na serwerze.
const ACTION_PREFIX = 'pos.';
const methodToAction = (method) => ACTION_PREFIX + method;

// Timing replay loop
const REPLAY_IDLE_DELAY   = 15_000;
const REPLAY_ACTIVE_DELAY = 2_000;
const REPLAY_BACKOFF_CAP  = 60_000;
const REPLAY_MAX_RETRIES  = 5;    // po tylu → markDead
const REPLAY_BATCH_LIMIT  = 20;   // max ops jednym tickiem (liniowo, bo każdy woła inny endpoint)

class PosApiOutboxImpl {
    constructor() {
        this._store = null;
        this._running = false;
        this._handle = null;
        this._inFlight = false;
        this._listeners = new Set();
        this._currentBackoff = REPLAY_ACTIVE_DELAY;
        this._lastReplayAt = null;
        this._lastReplayError = null;
        this._proxy = this._buildProxy();
    }

    // ─── PROXY BUILDER ───────────────────────────────────────────────────
    // Zwracany `proxy` ma identyczne API jak `PosAPI`. Mutacje wpadają w
    // _interceptMutation, reszta idzie directly do PosAPI (bind, żeby `this`
    // nie odpłynął w getterach).
    _buildProxy() {
        const self = this;
        return new Proxy({}, {
            get(_target, prop) {
                const orig = PosAPI[prop];
                if (typeof orig !== 'function') return orig;
                if (!MUTATION_METHODS.has(prop)) {
                    return orig.bind(PosAPI);
                }
                return (...args) => self._interceptMutation(prop, args);
            },
            has(_target, prop) {
                return prop in PosAPI;
            },
            ownKeys() {
                return Reflect.ownKeys(PosAPI);
            },
            getOwnPropertyDescriptor(_target, prop) {
                return Object.getOwnPropertyDescriptor(PosAPI, prop);
            },
        });
    }

    /**
     * Eksportuj proxy jako domyślny „PosAPI" dla pos_app.js.
     * Użycie:  import PosAPI from './PosApiOutbox.js';  // lub getProxy()
     */
    get api() {
        return this._proxy;
    }

    // ─── LIFECYCLE ───────────────────────────────────────────────────────
    async start({ store } = {}) {
        if (this._running) return this.getStatus();
        if (!store) throw new Error('PosLocalStore required');
        this._store = store;
        this._running = true;

        // Kopnij replay gdy outbox zmieniony (np. użytkownik zrobił zamówienie
        // offline — zaraz przyjdzie online event + replay).
        this._outboxUnsub = this._store.subscribeOutbox(() => {
            if (this._running && navigator.onLine && !this._inFlight) {
                this.triggerReplay();
            }
        });

        // Online → natychmiastowy replay
        this._onlineHandler = () => {
            if (!this._running) return;
            this._currentBackoff = REPLAY_ACTIVE_DELAY;
            this.triggerReplay();
        };
        window.addEventListener('online', this._onlineHandler);

        this._scheduleNext(REPLAY_ACTIVE_DELAY);
        console.info(LOG_PREFIX, 'started');
        this._emit('started', {});
        return this.getStatus();
    }

    async stop() {
        if (!this._running) return;
        this._running = false;
        if (this._handle) { clearTimeout(this._handle); this._handle = null; }
        if (this._outboxUnsub) { this._outboxUnsub(); this._outboxUnsub = null; }
        if (this._onlineHandler) window.removeEventListener('online', this._onlineHandler);
        this._onlineHandler = null;
    }

    triggerReplay() {
        if (!this._running) return;
        if (this._handle) { clearTimeout(this._handle); this._handle = null; }
        this._scheduleNext(0);
    }

    getStatus() {
        return {
            running:          this._running,
            inFlight:         this._inFlight,
            lastReplayAt:     this._lastReplayAt,
            lastReplayError:  this._lastReplayError,
            currentBackoff:   this._currentBackoff,
        };
    }

    on(event, listener) {
        const wrapped = { event, listener };
        this._listeners.add(wrapped);
        return () => this._listeners.delete(wrapped);
    }

    _emit(event, data) {
        this._listeners.forEach(({ event: e, listener }) => {
            if (e === event || e === '*') {
                try { listener({ event, data, ts: Date.now() }); } catch (err) { console.warn(LOG_PREFIX, 'listener', err); }
            }
        });
    }

    // ─── MUTATION INTERCEPTOR ────────────────────────────────────────────
    async _interceptMutation(method, args) {
        // 1) Online + UI ma aktywny SyncEngine → spróbuj od razu.
        //    Jeśli response jest network error, przełączamy w tryb enqueue.
        if (navigator.onLine) {
            try {
                const res = await PosAPI[method](...args);
                // PosAPI._post zwraca ok=false przy network error — wtedy enqueue.
                if (res && res.ok === false && res.status === 0) {
                    // Sieć padła mimo navigator.onLine=true → kolejkuj.
                    return this._enqueueAndRespond(method, args, 'network-error-on-live');
                }
                return res;
            } catch (err) {
                // PosAPI._post łapie catch wewnątrz; ten branch to jakaś
                // wyjątkowa sytuacja. Kolejkuj, żeby nie zgubić intencji.
                console.warn(LOG_PREFIX, 'live call threw', method, err);
                return this._enqueueAndRespond(method, args, 'exception');
            }
        }
        // 2) Offline → bezpośrednio enqueue
        return this._enqueueAndRespond(method, args, 'offline');
    }

    async _enqueueAndRespond(method, args, reason) {
        if (!this._store) {
            // Fallback: brak lokalnego store → cobiedzmy pędząc od razu, żeby
            // UI się nie zamroziło. To stan awaryjny (boot race condition).
            return {
                ok: false, status: 0, success: false,
                message: 'Offline i outbox niedostępny',
                data: null,
            };
        }

        // dedupeKey — rozwiązuje przypadek: user klika „Zatwierdź" trzy razy
        // w offline. Bez dedupe dostalibyśmy trzy opsy, trzy zamówienia po
        // reconnect. Z dedupe: drugi click zwraca ten sam opId.
        const dedupeKey = this._dedupeKeyFor(method, args);

        const opId = await this._store.enqueueOp(
            methodToAction(method),
            { method, args },
            { dedupeKey }
        );
        await this._store.appendEvent('outbox:enqueued', { method, opId, reason });

        // Zwróć „queued" response w kształcie spójnym z PosAPI._post output.
        const counts = await this._store.outboxCountByStatus();
        const humanMsg = this._humanMessageFor(reason);

        // UX: natychmiastowy toast, żeby kasjer wiedział że nie zgubiliśmy
        // intencji. Pomijamy jeśli toast API jeszcze nie wstało.
        try {
            if (typeof window !== 'undefined' && window.SliceHubPOS?.toast) {
                window.SliceHubPOS.toast(humanMsg, {
                    variant:    reason === 'offline' ? 'warn' : 'info',
                    durationMs: 3500,
                });
            }
        } catch (_) { /* ignore */ }

        return {
            ok:      true,
            status:  202,
            success: true,  // świadomie true — UI traktuje jak sukces z queued=true
            message: humanMsg,
            data: {
                queued:         true,
                op_id:          opId,
                pending_count:  counts.pending || 0,
                reason,
            },
        };
    }

    _humanMessageFor(reason) {
        switch (reason) {
            case 'offline':              return 'Zakolejkowane — wyślę gdy sieć wróci';
            case 'network-error-on-live': return 'Zakolejkowane — serwer nieosiągalny';
            case 'exception':             return 'Zakolejkowane — błąd połączenia';
            default:                      return 'Zakolejkowane';
        }
    }

    _dedupeKeyFor(method, args) {
        // Stabilny hash z nazwy metody + argumentów. JSON.stringify wystarczy
        // dla naszych payloadów (żadnych funkcji/Date/Set — same scalary i
        // obiekty plain).
        try {
            return method + '|' + JSON.stringify(args);
        } catch (_) {
            return method + '|' + Math.random().toString(36).slice(2, 10);
        }
    }

    // ─── REPLAY LOOP ─────────────────────────────────────────────────────
    _scheduleNext(delay) {
        if (!this._running) return;
        this._handle = setTimeout(() => this._tick(), delay);
    }

    async _tick() {
        if (!this._running) return;
        if (this._inFlight) { this._scheduleNext(REPLAY_ACTIVE_DELAY); return; }
        if (!navigator.onLine) { this._scheduleNext(REPLAY_IDLE_DELAY); return; }

        let pending;
        try {
            pending = await this._store.listPendingOps({ limit: REPLAY_BATCH_LIMIT });
        } catch (e) {
            console.warn(LOG_PREFIX, 'listPendingOps failed', e);
            this._scheduleNext(REPLAY_IDLE_DELAY);
            return;
        }

        // Filtruj tylko akcje z prefiksem `pos.` (ignoruj smoke-test ops itp.)
        pending = (pending || []).filter(op => typeof op.action === 'string' && op.action.startsWith(ACTION_PREFIX));

        if (pending.length === 0) {
            this._currentBackoff = REPLAY_ACTIVE_DELAY;
            this._scheduleNext(REPLAY_IDLE_DELAY);
            return;
        }

        this._inFlight = true;
        this._emit('replay:start', { count: pending.length });

        let applied = 0;
        let failed = 0;
        let dead = 0;

        for (const op of pending) {
            const method = op.payload?.method;
            const args = Array.isArray(op.payload?.args) ? op.payload.args : [];
            if (!method || !MUTATION_METHODS.has(method)) {
                // Nieznana metoda → markDead, bo retry nie pomoże.
                await this._store.markDead(op.opId, 'unknown-method:' + String(method)).catch(() => {});
                dead++;
                continue;
            }

            try {
                await this._store.markSending(op.opId);
                const res = await PosAPI[method](...args);

                if (res && res.success) {
                    await this._store.markSent(op.opId, {
                        status: 'applied',
                        server_ref: res.data?.order_id || res.data?.id || null,
                        http_status: res.status,
                    });
                    applied++;
                    continue;
                }

                // res.ok === false, status 0 → to network issue, retry with backoff.
                if (res && res.ok === false && res.status === 0) {
                    await this._store.markFailed(op.opId, 'network-error');
                    failed++;
                    // Sieć się znowu wywróciła w trakcie replay — kończymy tickiem,
                    // czekamy na kolejną szansę.
                    break;
                }

                // Serwer odrzucił (4xx/5xx) — dla idempotencji traktujemy to
                // jako: jeśli retries < MAX → retry, bo czasem 500 to blip;
                // jeśli >= MAX → dead-letter (nie walczymy z serwerem).
                const retries = (op.retries || 0) + 1;
                if (retries >= REPLAY_MAX_RETRIES) {
                    await this._store.markDead(op.opId, 'server-rejected:' + (res?.message || 'unknown'));
                    dead++;
                } else {
                    await this._store.markFailed(op.opId, res?.message || 'server-rejected');
                    failed++;
                }
            } catch (err) {
                // Throw z fetchy → retry (to prawie zawsze network).
                console.warn(LOG_PREFIX, 'replay threw', op.opId, err);
                await this._store.markFailed(op.opId, err?.message || 'exception').catch(() => {});
                failed++;
            }
        }

        this._lastReplayAt = Date.now();
        this._lastReplayError = failed > 0 ? 'partial' : null;
        this._inFlight = false;
        this._emit('replay:done', { applied, failed, dead, total: pending.length });

        // Po applied ops UI chce się odświeżyć (nowe ID orderów z serwera).
        if (applied > 0) this._emit('refetch:orders', { applied });

        // Jeśli wciąż są pending — wznów szybciej; jeśli same fails — backoff.
        if (applied > 0 && failed === 0) {
            this._currentBackoff = REPLAY_ACTIVE_DELAY;
            this._scheduleNext(REPLAY_ACTIVE_DELAY);
        } else if (failed > 0) {
            this._currentBackoff = Math.min(REPLAY_BACKOFF_CAP, this._currentBackoff * 2);
            this._scheduleNext(this._currentBackoff);
        } else {
            this._scheduleNext(REPLAY_IDLE_DELAY);
        }
    }
}

const singleton = new PosApiOutboxImpl();

// Domyślny eksport: proxy, który wygląda dokładnie jak PosAPI.
// Dzięki temu pos_app.js zmienia tylko ścieżkę importu.
export default singleton.api;

// Nazwany eksport: sama instancja wrappera — do start()/stop()/on() z
// pos_sw_register.js.
export const PosApiOutbox = singleton;
