/**
 * SliceHub POS — Service Worker registration + connectivity indicator
 *
 * Zadania (Phase 1):
 *   1. Zarejestrować /slicehub/modules/pos/sw.js przy starcie.
 *   2. Obsłużyć cykl życia SW (nowa wersja → toast „Zaktualizowano, odśwież").
 *   3. Wstrzyknąć subtelny wskaźnik stanu połączenia (pill w rogu topbaru)
 *      niezależny od pos_ui.js — działa już na ekranie PIN login.
 *   4. Obsłużyć 'beforeinstallprompt' → button „Zainstaluj POS" w settings.
 *   5. Eksponować window.SliceHubPOS.connectivity — API dla pos_app.js.
 *
 * Ten moduł nie dotyka logiki POS-a. Jedyny kontakt z istniejącym kodem:
 *   window.SliceHubPOS.connectivity.{isOnline, getState, on}
 *   — dostępne dla pos_app.js gdy zechce wyświetlić własny wskaźnik
 *   zgodny z motywem aplikacji.
 *
 * Phase 3/4 (Sync Engine) dobuduje do tego API:
 *   window.SliceHubPOS.outbox  — pending ops count
 *   window.SliceHubPOS.store   — PosLocalStore handle
 */
(function () {
    'use strict';

    const NS = (window.SliceHubPOS = window.SliceHubPOS || {});
    const LOG_PREFIX = '[SliceHub POS · SW]';

    // ── Connectivity singleton ────────────────────────────────────────────
    const listeners = new Set();
    const state = {
        isOnline: navigator.onLine,
        swReady: false,
        swUpdateAvailable: false,
        lastChange: Date.now(),
        // Phase 2 dopisuje: outboxCounts (pending/sending/done/dead/conflict), storeReady
        outboxCounts: { pending: 0, sending: 0, done: 0, dead: 0, conflict: 0 },
        storeReady: false,
        clientUuid: null,
        // Phase 3+ dopisze: lastSyncAt, syncInFlight
    };

    function notify() {
        state.lastChange = Date.now();
        listeners.forEach((fn) => { try { fn({ ...state }); } catch (e) { console.warn(LOG_PREFIX, 'listener error', e); } });
        renderPill();
    }

    NS.connectivity = {
        get isOnline() { return state.isOnline; },
        get swReady()  { return state.swReady; },
        getState()     { return { ...state }; },
        on(fn)         { if (typeof fn === 'function') listeners.add(fn); return () => listeners.delete(fn); },
    };

    // ── Online / offline native events ────────────────────────────────────
    window.addEventListener('online',  () => { state.isOnline = true;  notify(); });
    window.addEventListener('offline', () => { state.isOnline = false; notify(); });

    // ── Visual pill (top-right, niezależne od pos_ui.js) ──────────────────
    let pillEl = null;
    function ensurePill() {
        if (pillEl) return pillEl;
        pillEl = document.createElement('div');
        pillEl.id = 'sh-pos-connectivity-pill';
        pillEl.setAttribute('aria-live', 'polite');
        pillEl.style.cssText = [
            'position:fixed', 'top:10px', 'right:14px', 'z-index:99999',
            'display:flex', 'align-items:center', 'gap:6px',
            'padding:5px 11px', 'border-radius:100px',
            'font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif',
            'font-size:10px', 'font-weight:800',
            'text-transform:uppercase', 'letter-spacing:0.1em',
            'background:rgba(0,0,0,0.55)', 'color:#4ade80',
            'border:1px solid rgba(74,222,128,0.3)',
            'backdrop-filter:blur(8px)', '-webkit-backdrop-filter:blur(8px)',
            'transition:opacity 0.25s, color 0.25s, border-color 0.25s, transform 0.25s',
            'pointer-events:none', 'user-select:none',
            'opacity:0', 'transform:translateY(-4px)',
        ].join(';');
        pillEl.innerHTML = '<span class="sh-pos-pill-dot"></span><span class="sh-pos-pill-text">Online</span>';
        const dot = pillEl.querySelector('.sh-pos-pill-dot');
        dot.style.cssText = 'width:6px;height:6px;border-radius:50%;background:currentColor;box-shadow:0 0 6px currentColor';
        if (document.body) document.body.appendChild(pillEl);
        else document.addEventListener('DOMContentLoaded', () => document.body.appendChild(pillEl), { once: true });
        return pillEl;
    }

    // hide-after-idle: pokazujemy 2 s po zmianie stanu (albo stale gdy offline)
    let hideTimer = null;
    function renderPill() {
        ensurePill();
        if (!pillEl) return;
        const text = pillEl.querySelector('.sh-pos-pill-text');
        const pending = state.outboxCounts.pending + state.outboxCounts.sending;
        const conflict = state.outboxCounts.conflict;
        const dead = state.outboxCounts.dead;

        if (!state.isOnline) {
            pillEl.style.color = '#f87171';
            pillEl.style.borderColor = 'rgba(248,113,113,0.35)';
            text.textContent = pending > 0
                ? `Offline · ${pending} w kolejce`
                : 'Offline — POS działa dalej';
            showNow();
            return;
        }

        if (dead > 0) {
            pillEl.style.color = '#ef4444';
            pillEl.style.borderColor = 'rgba(239,68,68,0.45)';
            text.textContent = `Uwaga · ${dead} nie dostarczonych`;
            showNow();
            return;
        }

        if (conflict > 0) {
            pillEl.style.color = '#fbbf24';
            pillEl.style.borderColor = 'rgba(251,191,36,0.4)';
            text.textContent = `Konflikt · ${conflict} do rozstrzygnięcia`;
            showNow();
            return;
        }

        if (pending > 0) {
            pillEl.style.color = '#fbbf24';
            pillEl.style.borderColor = 'rgba(251,191,36,0.4)';
            text.textContent = `Synchronizuję · ${pending}`;
            showNow();
            return;
        }

        pillEl.style.color = '#4ade80';
        pillEl.style.borderColor = 'rgba(74,222,128,0.3)';
        text.textContent = state.swUpdateAvailable ? 'Online · aktualizacja' : 'Online';
        if (!state.swUpdateAvailable) scheduleHide();
        else showNow();
    }
    function showNow() {
        clearTimeout(hideTimer);
        pillEl.style.opacity = '1';
        pillEl.style.transform = 'translateY(0)';
    }
    function scheduleHide() {
        clearTimeout(hideTimer);
        showNow();
        hideTimer = setTimeout(() => {
            if (!pillEl) return;
            pillEl.style.opacity = '0';
            pillEl.style.transform = 'translateY(-4px)';
        }, 2500);
    }

    // ── Toast system (niezależny od pos_ui.js) ───────────────────────────
    function toast(msg, { variant = 'info', actionLabel = null, onAction = null, durationMs = 5000 } = {}) {
        const el = document.createElement('div');
        const colors = {
            info:    { fg: '#60a5fa', bg: 'rgba(96,165,250,0.12)', bd: 'rgba(96,165,250,0.35)' },
            success: { fg: '#4ade80', bg: 'rgba(74,222,128,0.12)', bd: 'rgba(74,222,128,0.35)' },
            warn:    { fg: '#fbbf24', bg: 'rgba(251,191,36,0.12)', bd: 'rgba(251,191,36,0.35)' },
            error:   { fg: '#f87171', bg: 'rgba(248,113,113,0.12)', bd: 'rgba(248,113,113,0.35)' },
        }[variant] || { fg: '#f3c76d', bg: 'rgba(243,199,109,0.12)', bd: 'rgba(243,199,109,0.35)' };

        el.style.cssText = [
            'position:fixed', 'bottom:24px', 'left:50%',
            'transform:translate(-50%,100px)', 'z-index:99998',
            'display:flex', 'align-items:center', 'gap:14px',
            'padding:12px 18px', 'border-radius:12px',
            `background:${colors.bg}`, `color:${colors.fg}`,
            `border:1px solid ${colors.bd}`,
            'backdrop-filter:blur(12px)',
            'font-family:Inter,system-ui,-apple-system,sans-serif',
            'font-size:13px', 'font-weight:700',
            'box-shadow:0 8px 32px rgba(0,0,0,0.4)',
            'transition:transform 0.3s cubic-bezier(.2,.9,.3,1.2), opacity 0.2s',
            'max-width:min(560px,92vw)',
        ].join(';');

        const msgEl = document.createElement('span');
        msgEl.textContent = msg;
        el.appendChild(msgEl);

        if (actionLabel && typeof onAction === 'function') {
            const btn = document.createElement('button');
            btn.textContent = actionLabel;
            btn.style.cssText = [
                'font-family:inherit', 'font-size:11px', 'font-weight:800',
                'text-transform:uppercase', 'letter-spacing:0.08em',
                'padding:7px 14px', 'border-radius:8px',
                `background:${colors.fg}`, 'color:#0a0806',
                'border:none', 'cursor:pointer',
            ].join(';');
            btn.addEventListener('click', () => {
                try { onAction(); } finally { dismiss(); }
            });
            el.appendChild(btn);
        }

        document.body.appendChild(el);
        requestAnimationFrame(() => { el.style.transform = 'translate(-50%,0)'; });

        let dismissed = false;
        function dismiss() {
            if (dismissed) return;
            dismissed = true;
            el.style.transform = 'translate(-50%,100px)';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 320);
        }
        setTimeout(dismiss, durationMs);
        return { dismiss };
    }
    NS.toast = toast;

    // ── Install prompt (PWA installability) ──────────────────────────────
    let deferredInstallPrompt = null;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredInstallPrompt = e;
        NS.installAvailable = true;
        toast('Możesz zainstalować POS jako aplikację', {
            variant: 'info',
            actionLabel: 'Zainstaluj',
            onAction: () => NS.installPWA(),
            durationMs: 8000,
        });
    });
    NS.installPWA = async function () {
        if (!deferredInstallPrompt) {
            toast('Instalacja niedostępna (już zainstalowane lub nieobsługiwane w tej przeglądarce)', { variant: 'warn' });
            return false;
        }
        try {
            deferredInstallPrompt.prompt();
            const { outcome } = await deferredInstallPrompt.userChoice;
            deferredInstallPrompt = null;
            NS.installAvailable = false;
            if (outcome === 'accepted') {
                toast('POS zainstalowany', { variant: 'success' });
                return true;
            }
        } catch (e) {
            console.warn(LOG_PREFIX, 'install failed', e);
        }
        return false;
    };
    window.addEventListener('appinstalled', () => {
        deferredInstallPrompt = null;
        NS.installAvailable = false;
    });

    // ── Service worker registration ──────────────────────────────────────
    if (!('serviceWorker' in navigator)) {
        console.warn(LOG_PREFIX, 'Service Worker API niedostępne');
        notify();
        return;
    }

    // Nie rejestrujemy SW gdy strona została otwarta przez devtools pod HTTP
    // na hoście innym niż localhost (SW wymaga HTTPS lub localhost).
    const isSecureContext = window.isSecureContext;
    const isLocalhost = /^(localhost|127\.0\.0\.1|::1)$/.test(location.hostname);
    if (!isSecureContext && !isLocalhost) {
        console.warn(LOG_PREFIX, 'SW pomijany — brak secure context (ani HTTPS ani localhost)');
        notify();
        return;
    }

    navigator.serviceWorker.addEventListener('message', (event) => {
        const data = event.data || {};
        if (data.type === 'SW_UPDATED') {
            state.swUpdateAvailable = true;
            notify();
            toast('Zaktualizowano POS do nowej wersji', {
                variant: 'info',
                actionLabel: 'Odśwież',
                onAction: () => location.reload(),
                durationMs: 10000,
            });
        }
    });

    // ── Local Store (IndexedDB) + Sync Engine bootstrap ──────────────────
    // Dynamic import — PosLocalStore i PosSyncEngine to ES moduły. Ten plik
    // to classical script. Ładujemy bez blokowania SW registration. Eksponu-
    // jemy na window.SliceHubPOS.{store,sync} dla pos_app.js i debug konsoli.
    (async () => {
        try {
            const storeMod = await import('/slicehub/modules/pos/js/PosLocalStore.js');
            const store = storeMod.PosLocalStore;
            await store.open();
            NS.store = store;
            NS.uuidv7 = storeMod.uuidv7;
            state.storeReady = true;
            state.clientUuid = storeMod.getClientUuid();

            store.subscribeOutbox((counts) => {
                state.outboxCounts = counts;
                notify();
            });
            const initialCounts = await store.outboxCountByStatus();
            state.outboxCounts = initialCounts;
            notify();

            console.info(LOG_PREFIX, 'PosLocalStore ready', {
                clientUuid: state.clientUuid,
                counts: initialCounts,
            });
        } catch (err) {
            console.warn(LOG_PREFIX, 'PosLocalStore bootstrap failed', err);
            return;
        }

        // Sync Engine — tylko jeśli store się podniosło.
        try {
            const engineMod = await import('/slicehub/modules/pos/js/PosSyncEngine.js');
            const engine = engineMod.PosSyncEngine;
            NS.sync = engine;

            engine.on('terminal:registered', ({ data }) => {
                console.info(LOG_PREFIX, 'terminal registered', data);
            });
            engine.on('sync:batch-done', ({ data }) => {
                console.info(LOG_PREFIX, 'batch done', data);
                if (data.conflict > 0) {
                    NS.toast(`Konflikt synchronizacji · ${data.conflict} op${data.conflict === 1 ? '' : '.'}`, {
                        variant: 'warn',
                        durationMs: 6000,
                    });
                }
                if (data.rejected > 0) {
                    NS.toast(`Serwer odrzucił ${data.rejected} op${data.rejected === 1 ? '' : '.'} — sprawdź dead-letter`, {
                        variant: 'error',
                        durationMs: 8000,
                    });
                }
            });
            engine.on('sync:batch-error', ({ data }) => {
                console.warn(LOG_PREFIX, 'batch error', data);
            });

            // Sync engine ma sensowne default behaviour: rejestruje terminal
            // przy pierwszym online, potem pętla 30s idle / 2s active / backoff on fail.
            await engine.start({
                store: NS.store,
                label: null,
                appVersion: 'slicehub-pos-v4',
            });
            console.info(LOG_PREFIX, 'PosSyncEngine started', engine.getStatus());

            // P3.5 — gdy pull przyniesie eventy, publikuj do window event bus,
            // pos_app.js może na to zareagować (np. refetch orders).
            engine.onServerEvent((ev) => {
                try {
                    window.dispatchEvent(new CustomEvent('slicehub-pos:server-event', { detail: ev }));
                } catch (_) { /* ignore */ }
            });
            engine.on('pull:done', ({ data }) => {
                if (data && data.count > 0) {
                    console.info(LOG_PREFIX, 'pull delta', data);
                }
            });
        } catch (err) {
            console.warn(LOG_PREFIX, 'PosSyncEngine bootstrap failed', err);
        }

        // P4 — PosApiOutbox replay loop (niezależny od sync.php — replayuje
        // mutacje przez oryginalne engine.php endpoints)
        try {
            const outboxMod = await import('/slicehub/modules/pos/js/PosApiOutbox.js');
            const outbox = outboxMod.PosApiOutbox;
            NS.apiOutbox = outbox;

            outbox.on('replay:done', ({ data }) => {
                if (!data) return;
                if (data.applied > 0) {
                    NS.toast(`Zsynchronizowano ${data.applied} operacj${data.applied === 1 ? 'ę' : 'e'} offline`, {
                        variant: 'success',
                        durationMs: 3500,
                    });
                    // Sygnał do pos_app.js żeby odświeżyło listę zamówień.
                    try {
                        window.dispatchEvent(new CustomEvent('slicehub-pos:outbox-replayed', { detail: data }));
                    } catch (_) {}
                }
                if (data.dead > 0) {
                    NS.toast(`${data.dead} operacj${data.dead === 1 ? 'a' : 'e'} odrzucon${data.dead === 1 ? 'a' : 'e'} — wymagają manualnej akcji`, {
                        variant: 'error',
                        durationMs: 8000,
                    });
                }
            });

            await outbox.start({ store: NS.store });
            console.info(LOG_PREFIX, 'PosApiOutbox started', outbox.getStatus());
        } catch (err) {
            console.warn(LOG_PREFIX, 'PosApiOutbox bootstrap failed', err);
        }
    })();

    window.addEventListener('load', async () => {
        try {
            const reg = await navigator.serviceWorker.register(
                '/slicehub/modules/pos/sw.js',
                { scope: '/slicehub/modules/pos/' }
            );
            state.swReady = true;
            notify();
            console.info(LOG_PREFIX, 'registered', reg.scope);

            // Gdy pojawi się nowa wersja w trakcie sesji
            reg.addEventListener('updatefound', () => {
                const nw = reg.installing;
                if (!nw) return;
                nw.addEventListener('statechange', () => {
                    if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                        state.swUpdateAvailable = true;
                        notify();
                        toast('Nowa wersja POS gotowa', {
                            variant: 'info',
                            actionLabel: 'Aktywuj i odśwież',
                            onAction: () => {
                                nw.postMessage({ type: 'SKIP_WAITING' });
                                setTimeout(() => location.reload(), 250);
                            },
                            durationMs: 12000,
                        });
                    }
                });
            });

            // Auto-check co 10 min — POS działa całe zmiany, stara wersja to
            // realne ryzyko. 10 min to kompromis między aktualnością a kosztem.
            setInterval(() => { reg.update().catch(() => {}); }, 10 * 60 * 1000);
        } catch (err) {
            console.warn(LOG_PREFIX, 'registration failed', err);
        }
    });

    notify();
})();
