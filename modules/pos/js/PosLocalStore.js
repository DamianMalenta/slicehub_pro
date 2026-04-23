/**
 * SliceHub POS — Local Store (Resilient POS · Phase 2)
 *
 * Warstwa 2 architektury Resilient POS:
 *   IndexedDB wrapper dający POS-owi „Source of Truth" niezależny od sieci.
 *
 * Trzy object store'y:
 *   - `state`     — aktualny stan świata (orders, menu snapshot, users, cart,
 *                   sesja PIN, ustawienia). key = string name, value = { data,
 *                   updatedAt, ttl }. TTL opcjonalne (menu 7 dni, orders 30 dni).
 *   - `outbox`    — mutacje czekające na synchronizację z backendem. key = opId
 *                   (UUID v7), indeksy po status/createdAt/retries.
 *   - `event_log` — append-only log zdarzeń do audytu/replay. key = eventId v7.
 *
 * Cross-tab: BroadcastChannel('slicehub-pos') emituje 'state:changed' i
 * 'outbox:changed' żeby drugi tab/POS odświeżył widok.
 *
 * UUID v7: własny generator time-sorted (spec draft RFC 9562). Dzięki temu
 * ops z dwóch offline POS-ów po sync lądują na serwerze w realnej chronologii.
 *
 * Ten moduł NIE dotyka jeszcze pos_api.js — to fundament pod Sync Engine (P3)
 * i Optimistic layer (P4). W P2 jedynie udostępniamy API. Integracja w P4.
 */

const DB_NAME    = 'slicehub-pos';
const DB_VERSION = 1;
const LOG_PREFIX = '[SliceHub POS · Store]';

// ═════════════════════════════════════════════════════════════════════════
// UUID v7 — time-sorted. Gwarantuje że opy kolejkowane w tym samym ms mają
// chronologiczny ordering z 74-bitową losowością. Zgodne z draft-ietf-uuidrev-
// rfc4122bis-14 §5.7.
// ═════════════════════════════════════════════════════════════════════════
export function uuidv7() {
    const ts = Date.now();
    const tsHex = ts.toString(16).padStart(12, '0');
    const randBytes = new Uint8Array(10);
    crypto.getRandomValues(randBytes);
    // version 7 (0111) w byte 6, variant RFC 4122 (10) w byte 8
    randBytes[0] = (randBytes[0] & 0x0f) | 0x70;
    randBytes[2] = (randBytes[2] & 0x3f) | 0x80;
    const hex = Array.from(randBytes, b => b.toString(16).padStart(2, '0')).join('');
    return [
        tsHex.slice(0, 8),
        tsHex.slice(8, 12),
        hex.slice(0, 4),
        hex.slice(4, 8),
        hex.slice(8, 20),
    ].join('-');
}

// ═════════════════════════════════════════════════════════════════════════
// PosLocalStore — singleton wrapper. Otwierany raz przez PosLocalStore.open().
// ═════════════════════════════════════════════════════════════════════════
class PosLocalStoreImpl {
    constructor() {
        this._db = null;
        this._openPromise = null;
        this._subs = new Map();        // key → Set<listener>
        this._outboxSubs = new Set();
        this._bc = null;
        this._gcDone = false;
    }

    // ─── OPEN / INIT ─────────────────────────────────────────────────────
    open() {
        if (this._db) return Promise.resolve(this);
        if (this._openPromise) return this._openPromise;

        this._openPromise = new Promise((resolve, reject) => {
            if (!('indexedDB' in window)) {
                return reject(new Error('IndexedDB niedostępne w tej przeglądarce'));
            }
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onerror = () => reject(req.error || new Error('Otwarcie DB nie powiodło się'));
            req.onblocked = () => console.warn(LOG_PREFIX, 'open blocked — zamknij inne taby POS-a');

            req.onupgradeneeded = (event) => {
                const db = req.result;
                const oldVersion = event.oldVersion || 0;
                if (oldVersion < 1) {
                    if (!db.objectStoreNames.contains('state')) {
                        db.createObjectStore('state', { keyPath: 'key' });
                    }
                    if (!db.objectStoreNames.contains('outbox')) {
                        const outbox = db.createObjectStore('outbox', { keyPath: 'opId' });
                        outbox.createIndex('status',    'status',    { unique: false });
                        outbox.createIndex('createdAt', 'createdAt', { unique: false });
                        outbox.createIndex('retries',   'retries',   { unique: false });
                    }
                    if (!db.objectStoreNames.contains('event_log')) {
                        const el = db.createObjectStore('event_log', { keyPath: 'eventId' });
                        el.createIndex('ts', 'ts', { unique: false });
                    }
                }
                // future: if (oldVersion < 2) { ... }
            };

            req.onsuccess = () => {
                this._db = req.result;
                this._db.onversionchange = () => {
                    console.warn(LOG_PREFIX, 'versionchange — zamykam DB, odśwież tab');
                    this._db.close();
                    this._db = null;
                };
                this._initBroadcast();
                this._scheduleGc();
                resolve(this);
            };
        });
        return this._openPromise;
    }

    _initBroadcast() {
        if (this._bc) return;
        if (typeof BroadcastChannel === 'undefined') return;
        try {
            this._bc = new BroadcastChannel('slicehub-pos');
            this._bc.addEventListener('message', (event) => {
                const msg = event.data || {};
                if (msg.type === 'state:changed') {
                    this._emitStateChange(msg.key, msg.data, /* broadcast */ false);
                } else if (msg.type === 'outbox:changed') {
                    this._emitOutboxChange(/* broadcast */ false);
                }
            });
        } catch (e) {
            console.warn(LOG_PREFIX, 'BroadcastChannel unavailable', e);
        }
    }

    _scheduleGc() {
        if (this._gcDone) return;
        this._gcDone = true;
        // garbage collect expired state entries + dead-letter outbox (async, nie blokujemy boota)
        setTimeout(() => this.gc().catch(() => {}), 4000);
    }

    // ─── STATE STORE ─────────────────────────────────────────────────────
    /**
     * Put wartość pod kluczem. TTL w ms (opcjonalne).
     * @param {string} key
     * @param {any} data
     * @param {object} [opts]
     * @param {number} [opts.ttlMs]
     * @param {boolean} [opts.silent] — nie emituj broadcast (przy naprawach)
     */
    async putState(key, data, opts = {}) {
        await this.open();
        const entry = {
            key,
            data,
            updatedAt: Date.now(),
            ttl: opts.ttlMs ? Date.now() + opts.ttlMs : null,
        };
        await this._tx('state', 'readwrite', (store) => store.put(entry));
        if (!opts.silent) this._emitStateChange(key, data, /* broadcast */ true);
        return entry;
    }

    async getState(key) {
        await this.open();
        const entry = await this._tx('state', 'readonly', (store) => store.get(key));
        if (!entry) return null;
        if (entry.ttl && entry.ttl < Date.now()) {
            // expired — usuń asynchronicznie
            this.deleteState(key).catch(() => {});
            return null;
        }
        return entry.data;
    }

    async deleteState(key) {
        await this.open();
        await this._tx('state', 'readwrite', (store) => store.delete(key));
        this._emitStateChange(key, null, true);
    }

    async listStateKeys() {
        await this.open();
        return this._tx('state', 'readonly', (store) => store.getAllKeys());
    }

    subscribeState(key, listener) {
        if (typeof listener !== 'function') return () => {};
        if (!this._subs.has(key)) this._subs.set(key, new Set());
        this._subs.get(key).add(listener);
        return () => {
            const set = this._subs.get(key);
            if (set) set.delete(listener);
        };
    }

    _emitStateChange(key, data, broadcast) {
        const set = this._subs.get(key);
        if (set) {
            set.forEach((fn) => { try { fn(data); } catch (e) { console.warn(LOG_PREFIX, 'listener error', e); } });
        }
        if (broadcast && this._bc) {
            try { this._bc.postMessage({ type: 'state:changed', key, data, ts: Date.now() }); } catch (_) {}
        }
    }

    // ─── OUTBOX ──────────────────────────────────────────────────────────
    /**
     * Zakolejkowanie mutacji. Zwraca opId (UUID v7). Ta funkcja NIE wysyła
     * niczego do backendu — to zrobi PosSyncEngine (P3).
     * @param {string} action   nazwa akcji API (np. 'process_order')
     * @param {any} payload
     * @param {object} [opts]
     * @param {string} [opts.dedupeKey] — gdy podany, druga próba z tym samym
     *        kluczem zwraca istniejący opId zamiast nowego wpisu
     */
    async enqueueOp(action, payload, opts = {}) {
        await this.open();
        if (opts.dedupeKey) {
            const existing = await this._findOpByDedupeKey(opts.dedupeKey);
            if (existing) return existing.opId;
        }
        const op = {
            opId:       uuidv7(),
            action,
            payload,
            status:     'pending',
            createdAt:  Date.now(),
            retries:    0,
            attempts:   [],
            lastError:  null,
            dedupeKey:  opts.dedupeKey || null,
            clientUuid: _getOrCreateClientUuid(),
        };
        await this._tx('outbox', 'readwrite', (store) => store.add(op));
        this._emitOutboxChange(true);
        return op.opId;
    }

    async getOp(opId) {
        await this.open();
        return this._tx('outbox', 'readonly', (store) => store.get(opId));
    }

    async listPendingOps({ limit = 100 } = {}) {
        await this.open();
        return this._tx('outbox', 'readonly', (store) => {
            return new Promise((resolve, reject) => {
                const index = store.index('status');
                const req = index.getAll('pending', limit);
                req.onsuccess = () => {
                    const arr = req.result || [];
                    arr.sort((a, b) => a.createdAt - b.createdAt);
                    resolve(arr);
                };
                req.onerror = () => reject(req.error);
            });
        });
    }

    async markSending(opId) {
        return this._updateOp(opId, (op) => {
            op.status = 'sending';
            op.attempts.push({ at: Date.now(), result: 'start' });
        });
    }

    async markSent(opId, serverResponse) {
        return this._updateOp(opId, (op) => {
            op.status = 'done';
            op.attempts.push({ at: Date.now(), result: 'ok', serverResponse });
        });
    }

    async markFailed(opId, errorMessage, { incrementRetry = true } = {}) {
        return this._updateOp(opId, (op) => {
            op.status = incrementRetry && op.retries + 1 >= 10 ? 'dead' : 'pending';
            op.retries = (op.retries || 0) + (incrementRetry ? 1 : 0);
            op.lastError = String(errorMessage || 'unknown');
            op.attempts.push({ at: Date.now(), result: 'fail', error: op.lastError });
        });
    }

    async markConflict(opId, conflictInfo) {
        return this._updateOp(opId, (op) => {
            op.status = 'conflict';
            op.conflictInfo = conflictInfo || null;
            op.attempts.push({ at: Date.now(), result: 'conflict', info: conflictInfo });
        });
    }

    /**
     * Oznacz operację jako permanentnie martwą (dead-letter). Używane dla
     * 'rejected' z serwera gdzie retry nie ma sensu (ręczna interwencja).
     */
    async markDead(opId, reason) {
        return this._updateOp(opId, (op) => {
            op.status = 'dead';
            op.lastError = String(reason || 'dead_letter');
            op.attempts.push({ at: Date.now(), result: 'dead', reason: op.lastError });
        });
    }

    async deleteOp(opId) {
        await this.open();
        await this._tx('outbox', 'readwrite', (store) => store.delete(opId));
        this._emitOutboxChange(true);
    }

    async outboxCountByStatus() {
        await this.open();
        return this._tx('outbox', 'readonly', (store) => {
            return new Promise((resolve, reject) => {
                const req = store.getAll();
                req.onsuccess = () => {
                    const out = { pending: 0, sending: 0, done: 0, dead: 0, conflict: 0 };
                    (req.result || []).forEach((o) => {
                        out[o.status] = (out[o.status] || 0) + 1;
                    });
                    resolve(out);
                };
                req.onerror = () => reject(req.error);
            });
        });
    }

    subscribeOutbox(listener) {
        if (typeof listener !== 'function') return () => {};
        this._outboxSubs.add(listener);
        return () => this._outboxSubs.delete(listener);
    }

    _emitOutboxChange(broadcast) {
        this.outboxCountByStatus().then((counts) => {
            this._outboxSubs.forEach((fn) => { try { fn(counts); } catch (e) { console.warn(LOG_PREFIX, 'outbox listener', e); } });
        });
        if (broadcast && this._bc) {
            try { this._bc.postMessage({ type: 'outbox:changed', ts: Date.now() }); } catch (_) {}
        }
    }

    async _findOpByDedupeKey(dedupeKey) {
        return this._tx('outbox', 'readonly', (store) => {
            return new Promise((resolve, reject) => {
                const req = store.getAll();
                req.onsuccess = () => {
                    const match = (req.result || []).find((o) => o.dedupeKey === dedupeKey && o.status !== 'done' && o.status !== 'dead');
                    resolve(match || null);
                };
                req.onerror = () => reject(req.error);
            });
        });
    }

    async _updateOp(opId, mutator) {
        await this.open();
        const op = await this._tx('outbox', 'readwrite', (store) => {
            return new Promise((resolve, reject) => {
                const getReq = store.get(opId);
                getReq.onsuccess = () => {
                    const entry = getReq.result;
                    if (!entry) return resolve(null);
                    try { mutator(entry); } catch (e) { return reject(e); }
                    const putReq = store.put(entry);
                    putReq.onsuccess = () => resolve(entry);
                    putReq.onerror = () => reject(putReq.error);
                };
                getReq.onerror = () => reject(getReq.error);
            });
        });
        this._emitOutboxChange(true);
        return op;
    }

    // ─── EVENT LOG ───────────────────────────────────────────────────────
    /**
     * Append-only audit log. Zastosowanie: replay stanu, dochodzenie incydentu.
     */
    async appendEvent(type, data) {
        await this.open();
        const ev = { eventId: uuidv7(), type, data, ts: Date.now() };
        await this._tx('event_log', 'readwrite', (store) => store.add(ev));
        return ev;
    }

    async listEvents({ since = 0, limit = 500 } = {}) {
        await this.open();
        return this._tx('event_log', 'readonly', (store) => {
            return new Promise((resolve, reject) => {
                const index = store.index('ts');
                const range = IDBKeyRange.lowerBound(since, true);
                const req = index.getAll(range, limit);
                req.onsuccess = () => resolve(req.result || []);
                req.onerror = () => reject(req.error);
            });
        });
    }

    // ─── GC ──────────────────────────────────────────────────────────────
    async gc() {
        await this.open();
        const now = Date.now();
        let expiredState = 0;
        let oldDeadOps = 0;
        let oldEvents = 0;

        // 1. State entries z ttl < now
        await this._tx('state', 'readwrite', (store) => {
            return new Promise((resolve) => {
                const req = store.openCursor();
                req.onsuccess = () => {
                    const cursor = req.result;
                    if (!cursor) return resolve();
                    const v = cursor.value;
                    if (v.ttl && v.ttl < now) {
                        cursor.delete();
                        expiredState++;
                    }
                    cursor.continue();
                };
                req.onerror = () => resolve();
            });
        });

        // 2. Outbox 'done' starsze niż 7 dni + 'dead' starsze niż 30 dni
        const WEEK = 7 * 24 * 60 * 60 * 1000;
        const MONTH = 30 * 24 * 60 * 60 * 1000;
        await this._tx('outbox', 'readwrite', (store) => {
            return new Promise((resolve) => {
                const req = store.openCursor();
                req.onsuccess = () => {
                    const cursor = req.result;
                    if (!cursor) return resolve();
                    const v = cursor.value;
                    const age = now - (v.createdAt || 0);
                    if ((v.status === 'done' && age > WEEK) ||
                        (v.status === 'dead' && age > MONTH)) {
                        cursor.delete();
                        oldDeadOps++;
                    }
                    cursor.continue();
                };
                req.onerror = () => resolve();
            });
        });

        // 3. Event log starsze niż 30 dni
        await this._tx('event_log', 'readwrite', (store) => {
            return new Promise((resolve) => {
                const index = store.index('ts');
                const range = IDBKeyRange.upperBound(now - MONTH, false);
                const req = index.openCursor(range);
                req.onsuccess = () => {
                    const cursor = req.result;
                    if (!cursor) return resolve();
                    cursor.delete();
                    oldEvents++;
                    cursor.continue();
                };
                req.onerror = () => resolve();
            });
        });

        if (expiredState + oldDeadOps + oldEvents > 0) {
            console.info(LOG_PREFIX, 'gc', { expiredState, oldDeadOps, oldEvents });
        }
        return { expiredState, oldDeadOps, oldEvents };
    }

    // ─── INTERNAL HELPERS ────────────────────────────────────────────────
    _tx(storeName, mode, fn) {
        return new Promise((resolve, reject) => {
            if (!this._db) return reject(new Error('DB not open'));
            const tx = this._db.transaction(storeName, mode);
            const store = tx.objectStore(storeName);
            let result;
            try {
                const maybePromise = fn(store);
                if (maybePromise && typeof maybePromise.then === 'function') {
                    maybePromise.then((v) => { result = v; }, reject);
                } else {
                    const req = maybePromise;
                    if (req && typeof req === 'object' && 'onsuccess' in req) {
                        req.onsuccess = () => { result = req.result; };
                        req.onerror = () => reject(req.error);
                    } else {
                        result = maybePromise;
                    }
                }
            } catch (e) { return reject(e); }
            tx.oncomplete = () => resolve(result);
            tx.onerror = () => reject(tx.error);
            tx.onabort = () => reject(tx.error || new Error('tx aborted'));
        });
    }

    // ─── DIAGNOSTICS ─────────────────────────────────────────────────────
    async diag() {
        await this.open();
        const [outboxCounts, stateKeys, events] = await Promise.all([
            this.outboxCountByStatus(),
            this.listStateKeys(),
            this.listEvents({ limit: 1 }),
        ]);
        return {
            dbName: DB_NAME,
            dbVersion: DB_VERSION,
            clientUuid: _getOrCreateClientUuid(),
            stateKeys,
            outboxCounts,
            lastEventAt: events[0]?.ts || null,
            broadcastChannelSupported: typeof BroadcastChannel !== 'undefined',
        };
    }

    // ─── DANGER ZONE ─────────────────────────────────────────────────────
    async reset() {
        if (this._db) { this._db.close(); this._db = null; }
        this._openPromise = null;
        await new Promise((resolve, reject) => {
            const req = indexedDB.deleteDatabase(DB_NAME);
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error);
            req.onblocked = () => console.warn(LOG_PREFIX, 'reset blocked');
        });
        console.warn(LOG_PREFIX, 'DB wiped');
    }
}

// ═════════════════════════════════════════════════════════════════════════
// Client UUID — stabilne ID tego urządzenia/POS-a. Używane przez Sync Engine
// (P3) do identyfikacji źródła ops. Generowane raz, przechowywane w
// localStorage (przeżywa odinstalowanie PWA → instalację).
// ═════════════════════════════════════════════════════════════════════════
const CLIENT_UUID_KEY = 'slicehub_pos_client_uuid';
function _getOrCreateClientUuid() {
    try {
        let v = localStorage.getItem(CLIENT_UUID_KEY);
        if (!v) {
            v = uuidv7();
            localStorage.setItem(CLIENT_UUID_KEY, v);
        }
        return v;
    } catch (_) {
        return 'unknown-' + Math.random().toString(36).slice(2, 10);
    }
}

// Singleton eksportowany jako default. Pierwsze użycie → auto-open().
const singleton = new PosLocalStoreImpl();

export const PosLocalStore = singleton;
export default singleton;
export const getClientUuid = _getOrCreateClientUuid;
