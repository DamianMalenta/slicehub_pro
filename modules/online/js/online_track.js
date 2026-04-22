/**
 * SliceHub Online — Track Order (Faza 5.2).
 *
 * Flow:
 *   1. Parse ?token + ?phone from URL (albo z localStorage last_order).
 *   2. Jeśli brak → pokaż prompt form.
 *   3. Poll trackOrder(token, phone) co TRACK_POLL_MS.
 *   4. Renderuj:
 *        - header (order_number, status pill, meta)
 *        - timeline (6 stages, reached/current/future)
 *        - driver card (widoczny tylko gdy `in_delivery` + driver GPS)
 *        - Leaflet map z pozycją kierowcy + adresem dostawy jako drugi pin
 *        - summary (adres, płatność, wartość, ETA)
 *   5. Stop polling gdy status `completed` lub `cancelled`.
 *
 * Ten moduł nie wymaga bundlera — ES module imports, Leaflet jako global.
 */

import OnlineAPI from './online_api.js';

const TRACK_POLL_MS = 10000;        // co 10 s odpytujemy tracker
const TRACK_POLL_FAST_MS = 10000;   // GPS poll też trzymamy na 10 s dla spójności kontraktu

const STAGE_ICONS = {
    new:         'fa-receipt',
    accepted:    'fa-circle-check',
    pending:     'fa-circle-check',   // legacy alias
    preparing:   'fa-fire-flame-curved',
    ready:       'fa-bell-concierge',
    in_delivery: 'fa-motorcycle',
    completed:   'fa-flag-checkered',
    cancelled:   'fa-xmark',
};

const STATUS_PILL_CLASS = {
    new:         'track-pill--blue',
    accepted:    'track-pill--indigo',
    pending:     'track-pill--indigo', // legacy alias
    preparing:   'track-pill--amber',
    ready:       'track-pill--violet',
    in_delivery: 'track-pill--rose',
    completed:   'track-pill--green',
    cancelled:   'track-pill--neutral',
};

const STATUS_LABEL = {
    new:         'Otrzymane',
    accepted:    'Zaakceptowane',
    pending:     'Zaakceptowane',   // legacy alias
    preparing:   'Przygotowanie',
    ready:       'Gotowe',
    in_delivery: 'W drodze',
    completed:   'Dostarczone',     // fallback; server provides per-order-type label via stages
    cancelled:   'Anulowane',
};

const state = {
    token: null,
    phone: null,
    pollTimer: null,
    etaTimer: null,           // live countdown ticker
    etaDeadlineMs: null,      // timestamp (ms) gdy zamówienie "powinno" być gotowe
    map: null,
    driverMarker: null,
    destMarker: null,
    originMarker: null,       // pin restauracji (start trasy)
    lastStatus: null,
    heroImageSet: false,      // żeby nie rerenderować hero co tick
    sse: null,                // EventSource instance (Tracker v2 — push zamiast poll)
    sseConnected: false,
};

function qsParam(name) {
    const u = new URL(window.location.href);
    return u.searchParams.get(name);
}
function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}
function setHidden(id, hidden) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('hidden', !!hidden);
}
function formatMoneyPl(val) {
    if (val == null) return '—';
    const n = typeof val === 'string' ? parseFloat(val.replace(',', '.')) : Number(val);
    if (Number.isNaN(n)) return String(val);
    return n.toFixed(2).replace('.', ',') + ' zł';
}
function formatTimeAgo(iso) {
    if (!iso) return '—';
    const t = new Date(iso.replace(' ', 'T')).getTime();
    if (Number.isNaN(t)) return iso;
    const sec = Math.max(0, Math.floor((Date.now() - t) / 1000));
    if (sec < 45) return `${sec}s temu`;
    if (sec < 120) return '1 min temu';
    const min = Math.floor(sec / 60);
    if (min < 60) return `${min} min temu`;
    const hr = Math.floor(min / 60);
    return `${hr}h ${min % 60}min temu`;
}

/** Sformatuj liczbę sekund jako countdown mm:ss (lub h:mm:ss gdy >1h). */
function formatCountdown(secondsLeft) {
    if (secondsLeft == null || !Number.isFinite(secondsLeft)) return '—';
    const overdue = secondsLeft < 0;
    let sec = Math.abs(Math.floor(secondsLeft));
    const h = Math.floor(sec / 3600); sec %= 3600;
    const m = Math.floor(sec / 60);   sec %= 60;
    const pad = (n) => String(n).padStart(2, '0');
    const body = h > 0 ? `${h}:${pad(m)}:${pad(sec)}` : `${pad(m)}:${pad(sec)}`;
    return overdue ? `-${body}` : body;
}
function lsKey(tenantId, suffix) { return `online_${suffix}_${tenantId}`; }

// ─── Boot ─────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const urlToken = qsParam('token');
    const urlPhone = qsParam('phone');

    if (urlToken && urlPhone) {
        state.token = urlToken;
        state.phone = urlPhone;
        startTracking();
        return;
    }

    // Spróbuj localStorage (ostatnie zamówienie)
    const tid = OnlineAPI.getTenantId();
    let last = null;
    try {
        last = JSON.parse(localStorage.getItem(lsKey(tid, 'last_order')) || 'null');
    } catch (_) {}

    if (last?.trackingToken && last?.phone) {
        state.token = last.trackingToken;
        state.phone = last.phone;
        startTracking();
        return;
    }

    // Nic nie mamy — pokaż formularz
    showPromptForm();
});

function showPromptForm() {
    setHidden('track-loading', true);
    setHidden('track-view', true);
    setHidden('track-error', true);
    setHidden('track-prompt', false);

    const form = document.getElementById('track-form');
    form?.addEventListener('submit', (e) => {
        e.preventDefault();
        const t = document.getElementById('track-token').value.trim();
        const p = document.getElementById('track-phone').value.trim().replace(/[^\d+]/g, '');
        const err = document.getElementById('track-form-err');
        if (!t || !p) {
            err.innerHTML = '<ul class="checkout-errors"><li>Wpisz token i telefon.</li></ul>';
            return;
        }
        state.token = t;
        state.phone = p;
        setHidden('track-prompt', true);
        startTracking();
    });
}

function showError(msg) {
    if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    setHidden('track-loading', true);
    setHidden('track-view', true);
    setHidden('track-prompt', true);
    setHidden('track-error', false);
    const msgEl = document.getElementById('track-error-msg');
    if (msgEl) msgEl.textContent = msg || 'Nie udało się wczytać zamówienia.';

    document.getElementById('track-retry')?.addEventListener('click', () => {
        setHidden('track-error', true);
        startTracking();
    }, { once: true });
}

async function startTracking() {
    setHidden('track-loading', false);
    setHidden('track-view', true);
    setHidden('track-error', true);
    setHidden('track-prompt', true);

    await tick(); // first fetch immediately

    // Tracker v2: SSE jako primary push channel, poll jako fallback
    startSse();

    // Polling fallback — konieczny jeśli SSE niedostępne lub status in_delivery (GPS)
    schedulePoll();
}

function startSse() {
    if (!window.EventSource) return; // brak wsparcia w przeglądarce

    if (state.sse) {
        state.sse.close();
        state.sse = null;
        state.sseConnected = false;
    }

    const tid = OnlineAPI.getTenantId();
    // Względna ścieżka — działa niezależnie od domeny/subdomeny
    const sseUrl = `/slicehub/api/online/sse.php?tenant=${encodeURIComponent(tid)}&token=${encodeURIComponent(state.token)}&phone=${encodeURIComponent(state.phone)}`;

    try {
        const es = new EventSource(sseUrl);
        state.sse = es;

        es.addEventListener('connected', () => {
            state.sseConnected = true;
        });

        // Nasłuchuj na eventy statusu zamówienia
        const orderEvents = ['order.accepted','order.preparing','order.ready','order.dispatched','order.in_delivery','order.delivered','order.completed','order.cancelled'];
        orderEvents.forEach(evType => {
            es.addEventListener(evType, () => {
                // Natychmiastowy fetch po otrzymaniu push event
                tick();
            });
        });

        es.addEventListener('timeout', () => {
            es.close();
            state.sse = null;
            state.sseConnected = false;
            // Reconnect po chwili (serwer zamknął po SSE_TIMEOUT_S)
            setTimeout(startSse, 1000);
        });

        es.onerror = () => {
            state.sseConnected = false;
            // EventSource automatycznie reconnectuje — nie robimy nic extra
        };
    } catch (e) {
        // SSE niedostępne — polling wystarczy
    }
}

function stopSse() {
    if (state.sse) {
        state.sse.close();
        state.sse = null;
        state.sseConnected = false;
    }
}

function schedulePoll() {
    if (state.pollTimer) clearInterval(state.pollTimer);
    const interval = state.lastStatus === 'in_delivery' ? TRACK_POLL_FAST_MS : TRACK_POLL_MS;
    state.pollTimer = setInterval(tick, interval);
}

async function tick() {
    try {
        const res = await OnlineAPI.trackOrder(state.token, state.phone);
        if (!res.success || !res.data) {
            if (state.lastStatus == null) {
                showError(res.message || 'Zamówienie nie zostało znalezione.');
            }
            return;
        }
        renderView(res.data);

        const status = res.data.order?.status;
        if (state.lastStatus !== status) {
            state.lastStatus = status;
            schedulePoll(); // reschedule with new interval
        }

        // Stop polling + SSE on terminal states
        if (status === 'completed' || status === 'cancelled') {
            if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
            stopEtaTicker();
            stopSse();
        }
    } catch (e) {
        if (state.lastStatus == null) showError(e.message || 'Błąd sieci.');
    }
}

// ─── Render ───────────────────────────────────────────────────────────────
function renderView(data) {
    const o = data.order || {};
    const stages = Array.isArray(data.stages) ? data.stages : [];
    const driver = data.driver || null;
    const gps = data.gps || null;
    const items = Array.isArray(data.items) ? data.items : [];
    const storeCoords = data.storeCoords || null;

    setHidden('track-loading', true);
    setHidden('track-view', false);
    setHidden('track-error', true);

    // Magic Stage Transition — ustawia data-status na body dla ambient palette
    document.body.dataset.status = o.status || '';

    // Header
    const numEl = document.getElementById('tr-order-num');
    if (numEl) numEl.textContent = o.orderNumber || '—';

    const metaBits = [];
    if (o.orderType === 'delivery') metaBits.push('<i class="fa-solid fa-motorcycle"></i> Dostawa');
    else if (o.orderType === 'takeaway') metaBits.push('<i class="fa-solid fa-bag-shopping"></i> Odbiór');
    if (o.customerName) metaBits.push(`<i class="fa-solid fa-user"></i> ${escapeHtml(o.customerName)}`);
    const metaEl = document.getElementById('tr-meta');
    if (metaEl) metaEl.innerHTML = metaBits.join(' · ') || '—';

    const pillEl = document.getElementById('tr-status-pill');
    if (pillEl) {
        // Preferuj label z serwera (stages) dla bieżącego statusu (np. 'Odebrane' vs 'Dostarczone')
        const serverStageLabel = stages.find(s => s.key === o.status || s.key === o.rawStatus)?.label;
        pillEl.textContent = serverStageLabel || STATUS_LABEL[o.status] || o.status || '—';
        pillEl.className = `track-pill ${STATUS_PILL_CLASS[o.status] || 'track-pill--neutral'}`;
    }

    // Hero image (set once, gdy dostaniemy URL po raz pierwszy)
    if (!state.heroImageSet && o.heroImage) {
        const hero = document.getElementById('tr-hero');
        if (hero) {
            hero.style.backgroundImage = `url("${o.heroImage}")`;
            hero.classList.add('track-hero--active');
            state.heroImageSet = true;
        }
    }

    // ETA live countdown
    renderEta(o);

    // Timeline
    renderTimeline(stages, o.status);

    // Stage banner (kontekstowy komunikat pod timeline)
    renderStageBanner(o.status, o.orderType);

    // SSE live dot
    renderLiveDot();

    // Items list
    renderItems(items);

    // Last update indicator
    const upEl = document.getElementById('tr-last-update');
    if (upEl) {
        const msg = o.updatedAt
            ? `Ostatnia aktualizacja ${formatTimeAgo(o.updatedAt)}`
            : `Utworzone ${formatTimeAgo(o.createdAt)}`;
        upEl.textContent = msg + ' · aktualizacja co 10 s';
    }

    // Driver card + Leaflet
    const driverCard = document.getElementById('tr-driver-card');
    if (o.status === 'in_delivery' && gps && driver) {
        driverCard.classList.remove('hidden');
        document.getElementById('tr-driver-name').textContent = driver.name || 'Kierowca';
        const speedEl = document.getElementById('tr-driver-speed');
        if (gps.speed_kmh != null) {
            speedEl.innerHTML = `<i class="fa-solid fa-gauge"></i> ${gps.speed_kmh.toFixed(0)} km/h · zaktualizowano ${formatTimeAgo(gps.updated_at)}`;
        } else {
            speedEl.textContent = 'Zaktualizowano ' + formatTimeAgo(gps.updated_at);
        }
        renderMap(gps, o, storeCoords);
    } else {
        driverCard.classList.add('hidden');
    }

    // Summary
    renderSummary(o);

    // Zmień zamówienie
    document.getElementById('tr-change')?.addEventListener('click', () => {
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
        stopEtaTicker();
        state.token = null;
        state.phone = null;
        state.lastStatus = null;
        state.heroImageSet = false;
        state.etaDeadlineMs = null;
        showPromptForm();
    }, { once: true });
}

/**
 * Live ETA countdown: używa `etaSeconds` z serwera jako kotwicy (serwerowy czas),
 * a lokalny ticker (1s) odejmuje sekundy między pollami. Przy każdym polling-u
 * kotwica jest resetowana, więc klient nie "ucieka" od rzeczywistego ETA.
 *
 * Widoczny tylko gdy status aktywny (nowe..in_delivery). Po completed/cancelled
 * — ukryty (brak znaczenia).
 */
function renderEta(o) {
    const box = document.getElementById('tr-eta');
    const timeEl = document.getElementById('tr-eta-time');
    const subEl  = document.getElementById('tr-eta-sub');
    if (!box || !timeEl || !subEl) return;

    const activeStatuses = ['new', 'accepted', 'preparing', 'ready', 'in_delivery'];
    if (!activeStatuses.includes(o.status) || o.etaSeconds == null) {
        box.classList.add('hidden');
        stopEtaTicker();
        return;
    }

    // Serwer zwraca etaSeconds (może być ujemne → overdue). Przeliczamy deadline (ms).
    state.etaDeadlineMs = Date.now() + (o.etaSeconds * 1000);

    const label = o.status === 'in_delivery'
        ? 'Dojazd kuriera'
        : (o.orderType === 'delivery' ? 'Do dostawy' : 'Do odbioru');

    box.classList.remove('hidden');
    subEl.textContent = label;

    // Renderuj od razu + uruchom ticker (1s)
    const paintOnce = () => {
        if (state.etaDeadlineMs == null) return;
        const secondsLeft = Math.round((state.etaDeadlineMs - Date.now()) / 1000);
        timeEl.textContent = formatCountdown(secondsLeft);
        // Overdue (<0) → wizualny alert
        box.classList.toggle('track-eta--overdue', secondsLeft < 0);
        // "Zaraz gotowe" (<120s) → pulsująca ramka
        box.classList.toggle('track-eta--soon', secondsLeft >= 0 && secondsLeft < 120);
    };
    paintOnce();
    stopEtaTicker();
    state.etaTimer = setInterval(paintOnce, 1000);
}

function stopEtaTicker() {
    if (state.etaTimer) { clearInterval(state.etaTimer); state.etaTimer = null; }
}

/** Lista pozycji zamówienia z miniaturami. */
function renderItems(items) {
    const card = document.getElementById('tr-items-card');
    const ul   = document.getElementById('tr-items');
    if (!card || !ul) return;

    if (!items.length) {
        card.classList.add('hidden');
        return;
    }
    card.classList.remove('hidden');
    ul.innerHTML = items.map(it => `
        <li class="track-items__row">
            <span class="track-items__thumb">
                ${it.imageUrl
                    ? `<img src="${escapeHtml(it.imageUrl)}" alt="" loading="lazy">`
                    : `<i class="fa-solid fa-pizza-slice"></i>`}
            </span>
            <span class="track-items__name">${escapeHtml(it.name || it.sku || '—')}</span>
            <span class="track-items__qty">× ${Number(it.qty) || 1}</span>
        </li>
    `).join('');
}

function renderTimeline(stages, currentStatus) {
    const root = document.getElementById('tr-timeline');
    if (!root) return;

    if (currentStatus === 'cancelled') {
        root.innerHTML = `
            <li class="track-step is-cancelled">
                <span class="track-step__dot"><i class="fa-solid fa-xmark"></i></span>
                <span class="track-step__label">Zamówienie anulowane</span>
            </li>`;
        return;
    }

    const html = stages.map((s) => {
        const isCurrent = s.key === currentStatus || (s.key === 'accepted' && currentStatus === 'pending');
        const isReached = !!s.reached;
        const cls = [
            'track-step',
            isReached ? 'is-reached' : '',
            isCurrent ? 'is-current' : '',
        ].filter(Boolean).join(' ');
        const icon = STAGE_ICONS[s.key] || 'fa-circle';
        return `
            <li class="${cls}">
                <span class="track-step__dot"><i class="fa-solid ${icon}"></i></span>
                <span class="track-step__label">${escapeHtml(s.label)}</span>
            </li>
        `;
    }).join('');
    root.innerHTML = html;
}

function renderSummary(o) {
    const dl = document.getElementById('tr-summary');
    if (!dl) return;
    const rows = [];
    rows.push(['Wartość', `<strong>${escapeHtml(formatMoneyPl(o.grandTotal))}</strong>`]);
    rows.push(['Status płatności', escapeHtml(prettyPay(o.paymentStatus))]);
    if (o.deliveryAddress) rows.push(['Adres', escapeHtml(o.deliveryAddress)]);
    if (o.promisedTime) rows.push(['Obiecane', escapeHtml(o.promisedTime)]);
    if (o.createdAt) rows.push(['Złożone', escapeHtml(o.createdAt)]);
    dl.innerHTML = rows.map(([k, v]) => `<div><dt>${escapeHtml(k)}</dt><dd>${v}</dd></div>`).join('');
}

function prettyPay(p) {
    switch ((p || '').toLowerCase()) {
        case 'to_pay': return 'Do zapłaty przy dostawie';
        case 'paid':   return 'Opłacone';
        case 'unpaid': return 'Nieopłacone';
        case 'refunded': return 'Zwrócone';
        default: return p || '—';
    }
}

// ─── Stage Banner & Live Dot ──────────────────────────────────────────────

const STAGE_BANNERS = {
    new:         { emoji: '🍕', msg: 'Zamówienie przyjęte! Czekamy aż restauracja potwierdzi.' },
    accepted:    { emoji: '✅', msg: 'Restauracja zaakceptowała i zaczyna przygotowania.' },
    preparing:   { emoji: '🔥', msg: 'Twoja pizza jest w piecu. Zaraz gotowe!' },
    ready:       { emoji: '🔔', msg: 'Gotowe! Kierowca zaraz odbierze Twoje zamówienie.' },
    in_delivery: { emoji: '🛵', msg: 'Kierowca jedzie do Ciebie. Prosimy o gotowość.' },
    completed:   { emoji: '🎉', msg: 'Dostarczone! Smacznego! Dziękujemy za zamówienie.' },
    cancelled:   { emoji: '😔', msg: 'Zamówienie zostało anulowane. Przepraszamy za niedogodności.' },
};

function renderStageBanner(status, orderType) {
    const el = document.getElementById('tr-stage-banner');
    if (!el) return;
    const b = STAGE_BANNERS[status];
    if (!b) { el.classList.add('hidden'); return; }

    let msg = b.msg;
    // Personalizuj dla takeaway
    if (status === 'ready' && orderType !== 'delivery') {
        msg = 'Gotowe! Możesz odbierać zamówienie w restauracji.';
    }
    if (status === 'completed' && orderType !== 'delivery') {
        msg = 'Odebrane! Smacznego! Dziękujemy za wizytę.';
    }

    el.innerHTML = `<span class="track-stage-banner__emoji">${b.emoji}</span>${escapeHtml(msg)}`;
    el.classList.remove('hidden');
}

function renderLiveDot() {
    const dot   = document.getElementById('tr-live-dot');
    const label = document.getElementById('tr-live-label');
    if (!dot) return;
    const active = !!state.sseConnected;
    dot.classList.toggle('track-live-dot--active', active);
    if (label) label.textContent = active ? 'Live push' : 'Polling 10s';
}

// ─── Map ──────────────────────────────────────────────────────────────────
/**
 * 2026-04-19 · Faza E: mapa pokazuje teraz 2 piny:
 *   - driver  (pulsujący, z rotacją wg heading)
 *   - origin  (restauracja, statyczny) — jeśli dostaliśmy storeCoords
 *
 * Bez fetchowania geokoderów po adresie — docelowy adres klienta nie jest
 * pokazywany (RODO friendly + client sees driver relatywnie do lokalu).
 */
function renderMap(gps, o, storeCoords) {
    if (typeof L === 'undefined') return;

    const mapDiv = document.getElementById('tr-map');
    if (!mapDiv) return;

    const driverPos = [gps.lat, gps.lng];

    // Init map lazily
    if (!state.map) {
        state.map = L.map(mapDiv, {
            zoomControl: true,
            attributionControl: false,
            scrollWheelZoom: false,
        }).setView(driverPos, 14);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
        }).addTo(state.map);

        // Driver marker
        const driverIcon = L.divIcon({
            className: 'track-marker track-marker--driver',
            html: '<div class="track-marker__pulse"></div><div class="track-marker__ring"><i class="fa-solid fa-motorcycle"></i></div>',
            iconSize: [44, 44],
            iconAnchor: [22, 22],
        });
        state.driverMarker = L.marker(driverPos, { icon: driverIcon }).addTo(state.map);
    } else {
        state.driverMarker.setLatLng(driverPos);
    }

    // Origin marker (restauracja) — jeśli mamy coords i jeszcze nie dodaliśmy.
    if (storeCoords && !state.originMarker) {
        const originIcon = L.divIcon({
            className: 'track-marker track-marker--origin',
            html: '<div class="track-marker__ring"><i class="fa-solid fa-store"></i></div>',
            iconSize: [38, 38],
            iconAnchor: [19, 19],
        });
        state.originMarker = L.marker([storeCoords.lat, storeCoords.lng], { icon: originIcon }).addTo(state.map);
    }

    // Auto-fit bounds gdy mamy oba piny (driver+origin), inaczej panTo na driver.
    if (storeCoords && state.originMarker) {
        const bounds = L.latLngBounds([driverPos, [storeCoords.lat, storeCoords.lng]]);
        state.map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16, animate: true });
    } else {
        state.map.panTo(driverPos, { animate: true, duration: 0.5 });
    }

    // Rotacja markera wg heading (jeśli dostępny)
    if (gps.heading != null && state.driverMarker) {
        const el = state.driverMarker.getElement();
        if (el) {
            const ring = el.querySelector('.track-marker__ring i');
            if (ring) ring.style.transform = `rotate(${gps.heading}deg)`;
        }
    }
}
