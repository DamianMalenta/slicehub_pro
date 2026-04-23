/**
 * SliceHub POS — Sync Engine (Resilient POS · Phase 3)
 *
 * Warstwa 1 architektury Resilient POS — dedicated async loop, który:
 *   1. Rejestruje terminal na serwerze (POST /api/pos/sync.php action=register_terminal)
 *   2. Pull ops z outboxu (PosLocalStore.listPendingOps) i push batchami do serwera
 *   3. Zaznacza wyniki w outboxie (markSending / markSent / markFailed / markConflict)
 *   4. Adaptive throttle + exponential backoff + dead-letter po 10 retries
 *   5. Emituje eventy dla pill w UI (sync:start, sync:end, error)
 *
 * P3.5 dokłada PULL-loop obok PUSH:
 *   - _pullTick()  → POST action=pull_since, zwraca eventy > cursor
 *   - applyServerEvent(event) → dispatch do subskrybentów (pos_app.js)
 *   - pull i push są niezależne; pull ma lżejszy timing (idle 10s, aktywny 3s)
 *
 * Co NIE robi w P3.5:
 *   - resolve_conflict (UI resolver) — P6
 *   - service worker background sync (sync events) — P5
 *
 * Filozofia timing:
 *   - online + empty outbox  → idle delay (30 s) + nasłuchuje 'outbox:changed'
 *   - online + pending ops   → aktywny pull co 2 s dopóki outbox nie pusty
 *   - offline                → pauza, nasłuchuje 'online' event
 *   - po błędzie sieci       → exponential backoff (2→4→8→16→32→60 s max)
 *   - po błędzie 4xx/5xx     → jak wyżej + logowanie ostatniego błędu
 *
 * Sygnatura:
 *   import PosSyncEngine from './PosSyncEngine.js';
 *   await PosSyncEngine.start({ store: PosLocalStore });
 *   PosSyncEngine.triggerSync();  // manualne kopnięcie
 *   PosSyncEngine.getStatus();    // { isRunning, lastSyncAt, ... }
 *   PosSyncEngine.stop();
 */

const LOG_PREFIX = '[SliceHub POS · Sync]';
const SYNC_ENDPOINT = '/slicehub/api/pos/sync.php';

// Timing stałe — wszystko w ms
const IDLE_DELAY       = 30_000;   // puste outbox + online → wait
const ACTIVE_DELAY     = 2_000;    // non-empty outbox + online → wait
const MIN_BACKOFF      = 2_000;    // po pierwszym failu
const MAX_BACKOFF      = 60_000;   // cap
const BACKOFF_MULT     = 2;        // exponential
const BATCH_LIMIT      = 50;       // max ops per push_batch
const REQUEST_TIMEOUT  = 15_000;   // fetch timeout (15 s)

// P3.5 — pull loop osobne stałe (niezależne od push timing).
const PULL_IDLE_DELAY   = 15_000;  // puste wyniki pulla → czekaj
const PULL_ACTIVE_DELAY = 3_000;   // były eventy → dopytaj szybciej (prawdopodobnie has_more)
const PULL_LIMIT        = 100;     // max eventów per pull_since

// localStorage klucz na ostatni cursor (używamy do szybkiego boot-u, zanim
// pierwszy pull przejdzie). Serwer jest autorytetem — to tylko hint.
const PULL_CURSOR_LS_KEY = 'slicehub_pos_pull_cursor_ts';

class PosSyncEngineImpl {
    constructor() {
        this._store = null;
        this._running = false;
        this._loopHandle = null;
        this._terminalId = null;
        this._currentBackoff = MIN_BACKOFF;
        this._lastSyncAt = null;
        this._lastError = null;
        this._inFlight = false;
        this._outboxUnsub = null;
        this._listeners = new Set();
        this._onlineHandler = null;
        this._offlineHandler = null;

        // P3.5 — pull loop
        this._pullHandle = null;
        this._pullInFlight = false;
        this._pullCursor = null;           // ostatni przyjęty created_at (ISO string)
        this._pullEventsTotal = 0;
        this._pullLastCount = 0;
        this._pullLastAt = null;
        this._pullLastError = null;
        this._eventSubs = new Set();
    }

    // ─── LIFECYCLE ───────────────────────────────────────────────────────
    async start({ store, label = null, appVersion = null } = {}) {
        if (this._running) return this.getStatus();
        if (!store) throw new Error('store required');
        this._store = store;
        this._running = true;

        // Register terminal (retry przy fail — fail też trafia do backoff loop)
        try {
            await this._registerTerminal({ label, appVersion });
        } catch (e) {
            console.warn(LOG_PREFIX, 'register_terminal failed (will retry in loop)', e);
            this._lastError = 'register_failed:' + (e && e.message ? e.message : 'unknown');
        }

        // Reagujemy na online event — przerywamy backoff i od razu syncujemy
        this._onlineHandler = () => {
            console.info(LOG_PREFIX, 'network online — triggering sync + pull');
            this._currentBackoff = MIN_BACKOFF;
            this.triggerSync();
            this.triggerPull();
        };
        this._offlineHandler = () => {
            console.info(LOG_PREFIX, 'network offline — pausing');
            this._emit('sync:network-change', { online: false });
        };
        window.addEventListener('online', this._onlineHandler);
        window.addEventListener('offline', this._offlineHandler);

        // Reagujemy na outbox:changed z LocalStore — gdy ktoś zrobi enqueueOp,
        // nie czekamy do następnej iteracji idle; od razu kopiemy loop.
        this._outboxUnsub = this._store.subscribeOutbox(() => {
            if (this._running && navigator.onLine && !this._inFlight) {
                this.triggerSync();
            }
        });

        // Load last cursor from localStorage (hint — server jest autorytetem)
        try {
            const cached = localStorage.getItem(PULL_CURSOR_LS_KEY);
            if (cached) this._pullCursor = cached;
        } catch (_) { /* ignore */ }

        // Start loops (push + pull niezależnie)
        this._scheduleNext(0);
        this._schedulePullNext(1500);     // mały delay żeby nie zaciskać się z register_terminal
        this._emit('sync:started', {});
        return this.getStatus();
    }

    async stop() {
        if (!this._running) return;
        this._running = false;
        if (this._loopHandle) { clearTimeout(this._loopHandle); this._loopHandle = null; }
        if (this._pullHandle) { clearTimeout(this._pullHandle); this._pullHandle = null; }
        if (this._outboxUnsub) { this._outboxUnsub(); this._outboxUnsub = null; }
        if (this._onlineHandler)  window.removeEventListener('online',  this._onlineHandler);
        if (this._offlineHandler) window.removeEventListener('offline', this._offlineHandler);
        this._onlineHandler = null;
        this._offlineHandler = null;
        this._emit('sync:stopped', {});
    }

    triggerSync() {
        if (!this._running) return;
        if (this._loopHandle) { clearTimeout(this._loopHandle); this._loopHandle = null; }
        this._scheduleNext(0);
    }

    /**
     * P3.5 — manualne kopnięcie pull loopa (np. po reload, po return-from-offline,
     * po „Refresh" w UI).
     */
    triggerPull() {
        if (!this._running) return;
        if (this._pullHandle) { clearTimeout(this._pullHandle); this._pullHandle = null; }
        this._schedulePullNext(0);
    }

    getStatus() {
        return {
            isRunning:      this._running,
            terminalId:     this._terminalId,
            inFlight:       this._inFlight,
            lastSyncAt:     this._lastSyncAt,
            lastError:      this._lastError,
            currentBackoff: this._currentBackoff,
            // P3.5
            pullInFlight:   this._pullInFlight,
            pullCursor:     this._pullCursor,
            pullEventsTotal: this._pullEventsTotal,
            pullLastCount:  this._pullLastCount,
            pullLastAt:     this._pullLastAt,
            pullLastError:  this._pullLastError,
        };
    }

    on(event, listener) {
        const wrapped = { event, listener };
        this._listeners.add(wrapped);
        return () => this._listeners.delete(wrapped);
    }

    /**
     * P3.5 — subskrybuj eventy z pull streamu. Listener dostaje pojedynczy
     * event: { id, event_type, entity_type, entity_id, payload, origin_kind, ... }.
     * Używane przez pos_app.js do reakcji np. na `order.created` (refetch orders).
     */
    onServerEvent(listener) {
        if (typeof listener !== 'function') return () => {};
        this._eventSubs.add(listener);
        return () => this._eventSubs.delete(listener);
    }

    _emit(event, data) {
        this._listeners.forEach(({ event: e, listener }) => {
            if (e === event || e === '*') {
                try { listener({ event, data, ts: Date.now() }); } catch (err) { console.warn(LOG_PREFIX, 'listener error', err); }
            }
        });
    }

    // ─── LOOP ────────────────────────────────────────────────────────────
    _scheduleNext(delay) {
        if (!this._running) return;
        this._loopHandle = setTimeout(() => this._tick(), delay);
    }

    async _tick() {
        if (!this._running) return;
        if (this._inFlight) {
            this._scheduleNext(ACTIVE_DELAY);
            return;
        }

        if (!navigator.onLine) {
            this._scheduleNext(IDLE_DELAY);
            return;
        }

        // Jeśli nie mamy terminalId, spróbuj rejestracji ponownie
        if (!this._terminalId) {
            try {
                await this._registerTerminal({});
            } catch (e) {
                this._lastError = 'register_failed';
                this._currentBackoff = this._nextBackoff();
                this._scheduleNext(this._currentBackoff);
                return;
            }
        }

        // Pull pending ops
        let pending;
        try {
            pending = await this._store.listPendingOps({ limit: BATCH_LIMIT });
        } catch (e) {
            console.warn(LOG_PREFIX, 'listPendingOps failed', e);
            this._scheduleNext(IDLE_DELAY);
            return;
        }

        if (!pending || pending.length === 0) {
            this._currentBackoff = MIN_BACKOFF;
            this._scheduleNext(IDLE_DELAY);
            return;
        }

        // Push batch
        this._inFlight = true;
        this._emit('sync:batch-start', { count: pending.length });

        try {
            const opsPayload = pending.map((op) => ({
                opId:       op.opId,
                action:     op.action,
                payload:    op.payload,
                createdAt:  op.createdAt,
                clientUuid: op.clientUuid,
            }));

            // markSending per op (non-blocking — LocalStore writes async)
            await Promise.all(pending.map((op) => this._store.markSending(op.opId).catch(() => {})));

            const res = await this._fetchSync({
                action:      'push_batch',
                terminal_id: this._terminalId,
                ops:         opsPayload,
            });

            if (!res.success) {
                // Batch-level failure — wszystkie ops zostają w 'sending' i przejdą
                // z powrotem w 'pending' przez markFailed.
                this._lastError = res.message || 'batch failed';
                await Promise.all(pending.map((op) =>
                    this._store.markFailed(op.opId, res.message || 'batch error').catch(() => {})));
                this._currentBackoff = this._nextBackoff();
                this._inFlight = false;
                this._emit('sync:batch-error', { error: this._lastError });
                this._scheduleNext(this._currentBackoff);
                return;
            }

            // Per-op results
            const results = Array.isArray(res.data?.results) ? res.data.results : [];
            const resultsByOpId = new Map(results.map((r) => [r.op_id, r]));

            let appliedCount = 0;
            let rejectedCount = 0;
            let conflictCount = 0;

            for (const op of pending) {
                const r = resultsByOpId.get(op.opId);
                if (!r) {
                    // Nie dostaliśmy odpowiedzi dla tego opa — traktuj jak failed
                    await this._store.markFailed(op.opId, 'no_response').catch(() => {});
                    continue;
                }
                switch (r.status) {
                    case 'applied':
                        await this._store.markSent(op.opId, r).catch(() => {});
                        appliedCount++;
                        break;
                    case 'conflict':
                        await this._store.markConflict(op.opId, r).catch(() => {});
                        conflictCount++;
                        break;
                    case 'rejected':
                    case 'dead':
                        // Rejected = serwer świadomie odrzucił → od razu dead-letter,
                        // żaden retry. UI może zaoferować manualną reakcję usera.
                        await this._store.markDead(op.opId, r.error || 'server_rejected').catch(() => {});
                        rejectedCount++;
                        break;
                    default:
                        await this._store.markFailed(op.opId, 'unknown_status:' + r.status).catch(() => {});
                }
            }

            this._lastSyncAt = Date.now();
            this._lastError = null;
            this._currentBackoff = MIN_BACKOFF;
            this._emit('sync:batch-done', {
                applied: appliedCount,
                rejected: rejectedCount,
                conflict: conflictCount,
                total: pending.length,
            });
        } catch (err) {
            console.warn(LOG_PREFIX, 'batch tick failed', err);
            this._lastError = err && err.message ? err.message : 'unknown error';
            // Wszystkie w 'sending' → fail (z retry increment)
            await Promise.all(pending.map((op) =>
                this._store.markFailed(op.opId, this._lastError).catch(() => {})));
            this._currentBackoff = this._nextBackoff();
            this._emit('sync:batch-error', { error: this._lastError });
        } finally {
            this._inFlight = false;
        }

        // Po udanym batchu: jeśli outbox nadal non-empty, szybki retry
        this._scheduleNext(
            this._lastError ? this._currentBackoff : ACTIVE_DELAY
        );
    }

    _nextBackoff() {
        const next = Math.min(
            MAX_BACKOFF,
            Math.max(MIN_BACKOFF, this._currentBackoff * BACKOFF_MULT)
        );
        return next;
    }

    // ─── PULL LOOP (P3.5) ───────────────────────────────────────────────
    _schedulePullNext(delay) {
        if (!this._running) return;
        this._pullHandle = setTimeout(() => this._pullTick(), delay);
    }

    async _pullTick() {
        if (!this._running) return;
        if (this._pullInFlight) {
            this._schedulePullNext(PULL_ACTIVE_DELAY);
            return;
        }
        if (!navigator.onLine) {
            // offline — przesunięcie na później, online handler i tak triggeruje.
            this._schedulePullNext(PULL_IDLE_DELAY);
            return;
        }
        if (!this._terminalId) {
            // nie zarejestrowani — push loop to załatwi, my tylko czekamy.
            this._schedulePullNext(PULL_IDLE_DELAY);
            return;
        }

        this._pullInFlight = true;
        try {
            const res = await this._fetchSync({
                action:      'pull_since',
                terminal_id: this._terminalId,
                since_ts:    this._pullCursor || '',
                limit:       PULL_LIMIT,
            });

            if (!res.success) {
                this._pullLastError = res.message || 'pull failed';
                this._emit('pull:error', { error: this._pullLastError });
                this._schedulePullNext(PULL_IDLE_DELAY);
                return;
            }

            const events = Array.isArray(res.data?.events) ? res.data.events : [];
            const cursor = res.data?.cursor || null;
            const hasMore = !!res.data?.has_more;

            this._pullLastCount = events.length;
            this._pullLastAt = Date.now();
            this._pullLastError = null;
            this._pullEventsTotal += events.length;

            if (cursor) {
                this._pullCursor = cursor;
                try { localStorage.setItem(PULL_CURSOR_LS_KEY, cursor); } catch (_) { /* ignore */ }
            }

            // Dispatch eventów — każdy subscriber dostaje osobny callback.
            // Event log w IndexedDB dla debug / replay.
            for (const ev of events) {
                try {
                    this._store.appendEvent('server_event', ev).catch(() => {});
                } catch (_) { /* ignore */ }
                this._emitServerEvent(ev);
            }

            this._emit('pull:done', {
                count: events.length,
                cursor: this._pullCursor,
                hasMore,
            });

            // Jeśli has_more → natychmiast drugi pull (paginacja).
            this._schedulePullNext(hasMore ? 0 : (events.length > 0 ? PULL_ACTIVE_DELAY : PULL_IDLE_DELAY));
        } catch (err) {
            this._pullLastError = err && err.message ? err.message : 'pull exception';
            console.warn(LOG_PREFIX, 'pull tick failed', err);
            this._emit('pull:error', { error: this._pullLastError });
            this._schedulePullNext(PULL_IDLE_DELAY);
        } finally {
            this._pullInFlight = false;
        }
    }

    _emitServerEvent(ev) {
        this._eventSubs.forEach((fn) => {
            try { fn(ev); } catch (e) { console.warn(LOG_PREFIX, 'server event listener error', e); }
        });
    }

    // ─── HELPERS ─────────────────────────────────────────────────────────
    async _registerTerminal({ label, appVersion }) {
        const clientUuid = this._getClientUuid();
        if (!clientUuid) throw new Error('client uuid not available');

        const res = await this._fetchSync({
            action:      'register_terminal',
            device_uuid: clientUuid,
            label:       label || null,
            app_version: appVersion || null,
        });

        if (!res.success || !res.data?.terminal_id) {
            throw new Error('register_terminal failed: ' + (res.message || 'unknown'));
        }
        this._terminalId = res.data.terminal_id;
        this._emit('terminal:registered', {
            terminalId: this._terminalId,
            deviceUuid: clientUuid,
        });
        console.info(LOG_PREFIX, 'terminal registered', { terminalId: this._terminalId });
        return this._terminalId;
    }

    _getClientUuid() {
        try { return localStorage.getItem('slicehub_pos_client_uuid'); }
        catch (_) { return null; }
    }

    async _fetchSync(payload) {
        const headers = { 'Content-Type': 'application/json' };
        const token = (() => {
            try { return localStorage.getItem('sh_token') || ''; }
            catch (_) { return ''; }
        })();
        if (token) headers['Authorization'] = 'Bearer ' + token;

        const ctrl = new AbortController();
        const timeout = setTimeout(() => ctrl.abort(), REQUEST_TIMEOUT);

        try {
            const res = await fetch(SYNC_ENDPOINT, {
                method:  'POST',
                headers,
                body:    JSON.stringify(payload),
                signal:  ctrl.signal,
            });
            const json = await res.json().catch(() => ({
                success: false, message: 'invalid json', data: null,
            }));
            return {
                ok:       res.ok,
                status:   res.status,
                success:  json.success === true,
                message:  json.message || '',
                data:     json.data || null,
            };
        } catch (e) {
            return {
                ok:      false,
                status:  0,
                success: false,
                message: e && e.name === 'AbortError' ? 'timeout' : (e && e.message ? e.message : 'network error'),
                data:    null,
            };
        } finally {
            clearTimeout(timeout);
        }
    }

}

const singleton = new PosSyncEngineImpl();
export const PosSyncEngine = singleton;
export default singleton;
