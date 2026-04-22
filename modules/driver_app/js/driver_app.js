/**
 * SLICEHUB DRIVER APP — Main Application Controller
 * PIN auth, 10s polling, GPS 15s, payment lock enforcement, emergency recall.
 * 3-Pillar State Machine: payment_status = to_pay | online_unpaid | cash | card | online_paid
 */
const DriverApp = (() => {
    const POLL_INTERVAL = 10000;
    const GPS_INTERVAL = 15000;
    const RECALL_CHECK_INTERVAL = 12000;
    const TENANT_ID = 1;

    const LS_DISMISSED = 'sh_dismissed_courses';
    const LS_AGE_VERIFIED = 'sh_age_verified';

    function _loadSet(key) {
        try { const raw = localStorage.getItem(key); return raw ? new Set(JSON.parse(raw)) : new Set(); }
        catch { return new Set(); }
    }
    function _saveSet(key, set) {
        try { localStorage.setItem(key, JSON.stringify([...set])); } catch {}
    }

    const state = {
        pin: '',
        user: null,
        orders: [],
        wallet: null,
        walletDetail: null,
        activeTab: 'runs',
        pollTimer: null,
        gpsTimer: null,
        recallTimer: null,
        cancelOrderId: null,
        dismissedCourses: _loadSet(LS_DISMISSED),
        ageVerified: _loadSet(LS_AGE_VERIFIED),
        holdTimer: null,
    };

    function formatGrosze(g) {
        return (parseInt(g, 10) / 100).toFixed(2);
    }

    function isPaid(ps) {
        return ['cash', 'card', 'online_paid'].includes(ps);
    }

    // ── PIN AUTH ──
    function pinDigit(d) {
        if (state.pin.length >= 4) return;
        state.pin += d;
        updatePinDots();
        if (state.pin.length === 4) pinSubmit();
    }

    function pinClear() {
        state.pin = state.pin.slice(0, -1);
        updatePinDots();
    }

    function updatePinDots() {
        document.querySelectorAll('#pin-dots .pin-dot').forEach((dot, i) => {
            dot.classList.toggle('filled', i < state.pin.length);
        });
    }

    async function pinSubmit() {
        if (state.pin.length !== 4) return;
        const res = await DriverAPI.loginPin(TENANT_ID, state.pin);
        if (res.success && res.data && res.data.token) {
            DriverAPI.setToken(res.data.token);
            state.user = res.data.user || res.data;
            localStorage.setItem('sh_token', res.data.token);
            localStorage.setItem('sh_user', JSON.stringify(state.user));
            enterApp();
        } else {
            state.pin = '';
            updatePinDots();
            toast(res.message || 'Nieprawidłowy PIN', 'error');
        }
    }

    function enterApp() {
        document.getElementById('pin-screen').classList.add('hidden');
        document.getElementById('app-root').classList.remove('hidden');
        const u = state.user;
        document.getElementById('topbar-name').textContent = u.first_name || u.username || 'Kierowca';
        poll();
        state.pollTimer = setInterval(poll, POLL_INTERVAL);
        startGPS();
        state.recallTimer = setInterval(checkRecall, RECALL_CHECK_INTERVAL);
    }

    function logout() {
        clearInterval(state.pollTimer);
        clearInterval(state.gpsTimer);
        clearInterval(state.recallTimer);
        localStorage.removeItem('sh_token');
        localStorage.removeItem('sh_user');
        localStorage.removeItem(LS_DISMISSED);
        localStorage.removeItem(LS_AGE_VERIFIED);
        state.dismissedCourses.clear();
        state.ageVerified.clear();
        state.user = null;
        state.pin = '';
        updatePinDots();
        document.getElementById('pin-screen').classList.remove('hidden');
        document.getElementById('app-root').classList.add('hidden');
    }

    function tryAutoLogin() {
        const token = localStorage.getItem('sh_token');
        const userData = localStorage.getItem('sh_user');
        if (token && userData) {
            try {
                state.user = JSON.parse(userData);
                DriverAPI.setToken(token);
                enterApp();
            } catch { /* pin screen */ }
        }
    }

    // ── TABS ──
    function switchTab(tab) {
        state.activeTab = tab;
        document.querySelectorAll('.tab-bar-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
        document.querySelectorAll('.tab-content').forEach(p => p.classList.toggle('active', p.id === `tab-${tab}`));
        if (tab === 'wallet') loadWallet();
    }

    // ── POLLING ──
    async function poll() {
        const res = await DriverAPI.getDriverRuns();
        console.log('POLLING DATA RECEIVED:', res);
        if (!res.success) {
            console.warn('POLL FAILED — server response:', res.message, '| full res:', res);
            if (res.message && (res.message.toLowerCase().includes('unauthorized') || res.message.toLowerCase().includes('token'))) {
                toast('Sesja wygasła — zaloguj się ponownie', 'error');
                logout();
            }
            return;
        }
        state.orders = res.data.orders || [];
        state.wallet = res.data.wallet || null;
        console.log(`POLL OK — ${state.orders.length} order(s) in delivery:`, state.orders);
        renderRuns();
        document.getElementById('topbar-status').textContent = state.orders.length > 0 ? `${state.orders.length} zamówień w trasie` : 'Brak kursów';
    }

    // ── GPS ──
    function startGPS() {
        if (!navigator.geolocation) return;
        const sendPos = () => {
            navigator.geolocation.getCurrentPosition(
                pos => {
                    DriverAPI.updateLocation(
                        pos.coords.latitude, pos.coords.longitude,
                        pos.coords.heading, pos.coords.speed ? (pos.coords.speed * 3.6) : null,
                        pos.coords.accuracy
                    );
                },
                () => {}, { enableHighAccuracy: true, timeout: 10000, maximumAge: 5000 }
            );
        };
        sendPos();
        state.gpsTimer = setInterval(sendPos, GPS_INTERVAL);
    }

    // ── EMERGENCY RECALL ──
    async function checkRecall() {
        const res = await DriverAPI.checkRecall();
        if (res.success && res.data && res.data.recalled) {
            document.getElementById('emergency-overlay').classList.add('active');
            if (navigator.vibrate) navigator.vibrate([500, 200, 500, 200, 500]);
        }
    }

    async function acknowledgeRecall() {
        await DriverAPI.clearRecall();
        document.getElementById('emergency-overlay').classList.remove('active');
        toast('Sygnał potwierdzony — wracaj do bazy', 'info');
    }

    // ── DRIVER ACTION HELPERS ──
    const ACTION_LABELS = {
        pack_cold: '❄️ ZIMNE', pack_separate: '🌿 OSOBNO', check_id: '🔞 WIEK',
    };

    function _hasSpecialItems(orders) {
        return orders.some(o => (o.lines || []).some(l => l.driver_action_type && l.driver_action_type !== 'none'));
    }

    function _collectSpecialItems(orders) {
        const groups = { pack_cold: [], pack_separate: [], check_id: [] };
        orders.forEach(o => {
            const num = '#' + (o.order_number || '').split('/').pop();
            (o.lines || []).forEach(l => {
                const act = l.driver_action_type;
                if (act && act !== 'none' && groups[act]) {
                    groups[act].push({ name: l.snapshot_name, qty: l.quantity, order: num });
                }
            });
        });
        return groups;
    }

    function _orderHasCheckId(o) {
        return (o.lines || []).some(l => l.driver_action_type === 'check_id');
    }

    // ── PRE-FLIGHT ──
    function checkPreFlight() {
        const courseGroups = {};
        state.orders.forEach(o => {
            const cid = o.course_id || 'SINGLE';
            if (!courseGroups[cid]) courseGroups[cid] = [];
            courseGroups[cid].push(o);
        });

        for (const [cid, orders] of Object.entries(courseGroups)) {
            if (state.dismissedCourses.has(cid)) continue;
            if (!_hasSpecialItems(orders)) continue;
            showPreFlight(cid, orders);
            return;
        }
    }

    function showPreFlight(courseId, orders) {
        const groups = _collectSpecialItems(orders);
        const el = document.getElementById('pf-groups');
        let html = '';

        if (groups.pack_cold.length) {
            html += `<div class="pf-group"><div class="pf-group-header cold">❄️ ZIMNE — Napoje / Sosy</div>`;
            groups.pack_cold.forEach(i => { html += `<div class="pf-item"><span>${i.qty}x ${i.name}</span><span class="pf-item-order">${i.order}</span></div>`; });
            html += `</div>`;
        }
        if (groups.pack_separate.length) {
            html += `<div class="pf-group"><div class="pf-group-header separate">🌿 OSOBNO — Rukola / Dodatki</div>`;
            groups.pack_separate.forEach(i => { html += `<div class="pf-item"><span>${i.qty}x ${i.name}</span><span class="pf-item-order">${i.order}</span></div>`; });
            html += `</div>`;
        }
        if (groups.check_id.length) {
            html += `<div class="pf-group"><div class="pf-group-header check_id">🔞 DOKUMENTY — Sprawdź Wiek</div>`;
            groups.check_id.forEach(i => { html += `<div class="pf-item"><span>${i.qty}x ${i.name}</span><span class="pf-item-order">${i.order}</span></div>`; });
            html += `</div>`;
        }

        el.innerHTML = html;
        const overlay = document.getElementById('preflight-overlay');
        overlay.classList.add('active');

        _wireHoldButton(courseId);
    }

    function _wireHoldButton(courseId) {
        const btn = document.getElementById('pf-hold-btn');
        const fill = document.getElementById('pf-hold-fill');

        btn.classList.remove('holding', 'confirmed');
        fill.style.width = '0%';
        fill.style.transition = 'none';

        const onStart = (e) => {
            e.preventDefault();
            btn.classList.add('holding');
            fill.style.transition = 'width 1.5s linear';
            fill.style.width = '100%';
            state.holdTimer = setTimeout(() => {
                btn.classList.remove('holding');
                btn.classList.add('confirmed');
                state.dismissedCourses.add(courseId);
                _saveSet(LS_DISMISSED, state.dismissedCourses);
                if (navigator.vibrate) navigator.vibrate([100, 50, 100, 50, 200]);
                setTimeout(() => {
                    document.getElementById('preflight-overlay').classList.remove('active');
                    toast('Kontrola potwierdzona — ruszaj!', 'success');
                }, 400);
            }, 1500);
        };

        const onEnd = (e) => {
            e.preventDefault();
            if (state.holdTimer) { clearTimeout(state.holdTimer); state.holdTimer = null; }
            if (!btn.classList.contains('confirmed')) {
                btn.classList.remove('holding');
                fill.style.transition = 'width 0.2s';
                fill.style.width = '0%';
            }
        };

        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
        newBtn.addEventListener('pointerdown', onStart);
        newBtn.addEventListener('pointerup', onEnd);
        newBtn.addEventListener('pointerleave', onEnd);
        newBtn.addEventListener('pointercancel', onEnd);
    }

    // ── RENDER RUNS ──
    function renderRuns() {
        const el = document.getElementById('runs-list');

        if (state.orders.length === 0) {
            el.innerHTML = `<div class="empty-state"><i class="fa-solid fa-couch"></i><h3>Brak kursów</h3><p>Czekaj na przypisanie zamówień przez dyspozytora</p></div>`;
            return;
        }

        const courseGroups = {};
        state.orders.forEach(o => {
            const cid = o.course_id || 'SINGLE';
            if (!courseGroups[cid]) courseGroups[cid] = [];
            courseGroups[cid].push(o);
        });

        let html = '';
        Object.keys(courseGroups).forEach(cid => {
            const items = courseGroups[cid].sort((a, b) => {
                const na = parseInt((a.stop_number || 'L99').replace('L', ''));
                const nb = parseInt((b.stop_number || 'L99').replace('L', ''));
                return na - nb;
            });

            html += `<div class="run-header"><i class="fa-solid fa-route"></i> Kurs ${cid} — ${items.length} przystanków</div>`;

            items.forEach(o => {
                const total = formatGrosze(o.grand_total);
                const addr = o.delivery_address || 'Brak adresu';
                const phone = o.customer_phone || '';
                const mapsUrl = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(addr)}`;
                const telUrl = phone ? `tel:${phone.replace(/\s+/g, '')}` : '#';
                const hasCheckId = _orderHasCheckId(o);
                const ageOk = state.ageVerified.has(o.id);

                const lines = (o.lines || []).map(l => {
                    const act = l.driver_action_type || 'none';
                    if (act !== 'none') {
                        const glowCls = act === 'pack_cold' ? 'glow-cold' : act === 'pack_separate' ? 'glow-separate' : 'glow-check_id';
                        const tagCls = act === 'pack_cold' ? 'tag-cold' : act === 'pack_separate' ? 'tag-separate' : 'tag-check_id';
                        return `<div class="d-card-line action-glow ${glowCls}" style="display:flex;align-items:center;gap:8px">
                            <span>${l.quantity}x ${l.snapshot_name}</span>
                            <span class="d-line-action-tag ${tagCls}">${ACTION_LABELS[act] || act}</span>
                        </div>${l.comment ? `<div class="d-card-comment">${l.comment}</div>` : ''}`;
                    }
                    let text = `<div class="d-card-line">${l.quantity}x ${l.snapshot_name}</div>`;
                    if (l.comment) text += `<div class="d-card-comment">${l.comment}</div>`;
                    return text;
                }).join('');

                const ageGate = hasCheckId && !ageOk
                    ? `<div class="age-verify-gate"><input type="checkbox" id="age-${o.id}" onchange="DriverApp.verifyAge('${o.id}')"><label for="age-${o.id}">Zweryfikowano wiek klienta</label></div>`
                    : '';

                let payBadge, actionButtons;
                const deliverDisabled = (hasCheckId && !ageOk) ? 'disabled' : '';

                if (o.payment_status === 'online_paid') {
                    payBadge = `<div class="pay-mega prepaid"><i class="fa-solid fa-check-circle"></i> OPŁACONE ONLINE — NIE POBIERAJ</div>`;
                    actionButtons = `
                        <a href="${mapsUrl}" target="_blank" class="d-action nav"><i class="fa-solid fa-diamond-turn-right"></i> Nawiguj</a>
                        ${phone ? `<a href="${telUrl}" class="d-action call"><i class="fa-solid fa-phone"></i> Dzwoń</a>` : ''}
                        <button class="d-action deliver" ${deliverDisabled} onclick="DriverApp.deliverOrder('${o.id}')"><i class="fa-solid fa-check-double"></i> Dostarczono</button>`;
                } else if (o.payment_status === 'cash' || o.payment_status === 'card') {
                    const methodLabel = o.payment_status === 'cash' ? 'GOTÓWKA' : 'KARTA';
                    payBadge = `<div class="pay-mega prepaid"><i class="fa-solid fa-check-circle"></i> ZAPŁACONO (${methodLabel})</div>`;
                    actionButtons = `
                        <a href="${mapsUrl}" target="_blank" class="d-action nav"><i class="fa-solid fa-diamond-turn-right"></i> Nawiguj</a>
                        ${phone ? `<a href="${telUrl}" class="d-action call"><i class="fa-solid fa-phone"></i> Dzwoń</a>` : ''}
                        <button class="d-action deliver" ${deliverDisabled} onclick="DriverApp.deliverOrder('${o.id}')"><i class="fa-solid fa-check-double"></i> Dostarczono</button>`;
                } else {
                    const label = o.payment_status === 'online_unpaid' ? 'ONLINE — DO ZAPŁATY' : 'DO ZAPŁATY';
                    payBadge = `<div class="pay-mega to-collect"><i class="fa-solid fa-hand-holding-dollar"></i> ${label} — ${total} zł</div>`;
                    actionButtons = `
                        <a href="${mapsUrl}" target="_blank" class="d-action nav"><i class="fa-solid fa-diamond-turn-right"></i> Nawiguj</a>
                        ${phone ? `<a href="${telUrl}" class="d-action call"><i class="fa-solid fa-phone"></i></a>` : ''}
                        <button class="d-action collect-cash" onclick="DriverApp.collectPayment('${o.id}','cash')"><i class="fa-solid fa-money-bill-wave"></i> Gotówka</button>
                        <button class="d-action collect-card" onclick="DriverApp.collectPayment('${o.id}','card')"><i class="fa-solid fa-credit-card"></i> Karta</button>
                        <button class="d-action locked" disabled><i class="fa-solid fa-lock"></i> Dostarcz</button>`;
                }

                html += `
                <div class="d-card">
                    <div class="d-card-header">
                        <div style="display:flex; align-items:center">
                            <div class="d-card-stop">${o.stop_number || '—'}</div>
                            <span class="d-card-num">#${(o.order_number || '').split('/').pop()}</span>
                        </div>
                        <span class="d-card-total">${total} zł</span>
                    </div>
                    <div class="d-card-body">
                        <div class="d-card-addr"><i class="fa-solid fa-location-dot" style="margin-right:6px; color:var(--accent-blue)"></i>${addr}</div>
                        ${phone ? `<div class="d-card-phone"><i class="fa-solid fa-phone" style="margin-right:6px"></i>${phone}</div>` : ''}
                        ${o.customer_name ? `<div class="d-card-phone"><i class="fa-solid fa-user" style="margin-right:6px"></i>${o.customer_name}</div>` : ''}
                        ${payBadge}
                        ${lines ? `<div class="d-card-items">${lines}</div>` : ''}
                        ${ageGate}
                    </div>
                    <div class="d-card-actions">${actionButtons}</div>
                    <div class="d-card-cancel-row">
                        <button class="d-action-cancel" onclick="DriverApp.openCancelModal('${o.id}')"><i class="fa-solid fa-ban"></i> Anuluj zamówienie</button>
                    </div>
                </div>`;
            });
        });

        el.innerHTML = html;

        checkPreFlight();
    }

    function verifyAge(orderId) {
        const cb = document.getElementById(`age-${orderId}`);
        if (cb && cb.checked) {
            state.ageVerified.add(orderId);
            _saveSet(LS_AGE_VERIFIED, state.ageVerified);
            if (navigator.vibrate) navigator.vibrate(50);
            renderRuns();
        }
    }

    // ── PAYMENT ACTIONS ──
    async function collectPayment(orderId, type) {
        const label = type === 'cash' ? 'gotówkę' : 'kartę';
        const res = await DriverAPI.collectPayment(orderId, type);
        if (res.success) {
            toast(`Pobrano ${label}!`, 'success');
            if (navigator.vibrate) navigator.vibrate(100);
            await poll();
        } else {
            toast(res.message || 'Błąd pobierania płatności', 'error');
        }
    }

    async function deliverOrder(orderId) {
        const res = await DriverAPI.deliverOrder(orderId);
        if (res.success) {
            toast('Zamówienie dostarczone!', 'success');
            if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
            await poll();
        } else {
            if (res.message && res.message.includes('PAYMENT_LOCK')) {
                toast('Najpierw pobierz płatność!', 'error');
            } else {
                toast(res.message || 'Błąd dostawy', 'error');
            }
        }
    }

    // ── CANCEL ORDER MODAL ──
    function openCancelModal(orderId) {
        state.cancelOrderId = orderId;
        document.getElementById('cancel-reason-input').value = '';
        const overlay = document.getElementById('modal-cancel');
        overlay.classList.add('active');
        const btn = document.getElementById('cancel-confirm-btn');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-ban"></i> Potwierdź anulowanie';
    }

    function closeCancelModal() {
        document.getElementById('modal-cancel').classList.remove('active');
        state.cancelOrderId = null;
    }

    async function confirmCancelOrder() {
        if (!state.cancelOrderId) return;
        const reason = document.getElementById('cancel-reason-input').value.trim();
        if (reason.length < 3) {
            toast('Wpisz powód anulowania (min. 3 znaki)', 'error');
            return;
        }

        const btn = document.getElementById('cancel-confirm-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Anulowanie...';

        const res = await DriverAPI.cancelOrder(state.cancelOrderId, reason);
        closeCancelModal();

        if (res.success) {
            toast('Zamówienie anulowane', 'success');
            if (navigator.vibrate) navigator.vibrate(200);
            await poll();
        } else {
            toast(res.message || 'Błąd anulowania', 'error');
        }
    }

    // ── WALLET ──
    async function loadWallet() {
        const res = await DriverAPI.getDriverWallet();
        if (!res.success) return;
        state.walletDetail = res.data;
        renderWallet();
    }

    function renderWallet() {
        const el = document.getElementById('wallet-content');
        const w = state.walletDetail;
        if (!w) {
            el.innerHTML = '<div class="empty-state"><i class="fa-solid fa-wallet"></i><h3>Ładowanie portfela...</h3></div>';
            return;
        }

        const deliveries = w.deliveries || [];
        const historyHtml = deliveries.length === 0
            ? '<p style="color:var(--text-muted); font-size:12px; text-align:center; padding:20px">Brak dostaw dzisiaj</p>'
            : deliveries.map(d => {
                const amt = formatGrosze(d.grand_total);
                const ps = d.payment_status || 'to_pay';
                const methodLabel = { cash: 'GOTÓWKA', card: 'KARTA', online_paid: 'ONLINE', to_pay: 'DO ZAPŁATY' }[ps] || ps.toUpperCase();
                const methodClass = { cash: 'cash', card: 'card', online_paid: 'online' }[ps] || 'cash';
                return `
                <div class="wh-item">
                    <div>
                        <div class="wh-item-left">#${(d.order_number || '').split('/').pop()}</div>
                        <div class="wh-item-addr">${d.delivery_address || ''}</div>
                    </div>
                    <div style="text-align:right">
                        <div class="wh-item-amount">${amt} zł</div>
                        <span class="wh-item-method ${methodClass}">${methodLabel}</span>
                    </div>
                </div>`;
            }).join('');

        el.innerHTML = `
            <div class="wallet-hero">
                <div class="wallet-label">Gotówka w ręku</div>
                <div class="wallet-amount">${w.total_in_hand} zł</div>
            </div>
            <div class="wallet-breakdown">
                <div class="wb-card"><div class="wb-label">Pogotowie kasowe</div><div class="wb-value start">${w.initial_cash} zł</div></div>
                <div class="wb-card"><div class="wb-label">Zebrana gotówka</div><div class="wb-value cash">${w.cash_collected} zł</div></div>
                <div class="wb-card"><div class="wb-label">Karta (terminal)</div><div class="wb-value card">${w.card_collected} zł</div></div>
                <div class="wb-card"><div class="wb-label">Opłacone online</div><div class="wb-value prepaid">${w.prepaid_total || '0.00'} zł</div></div>
                <div class="wb-card"><div class="wb-label">Dostawy dzisiaj</div><div class="wb-value count">${w.delivery_count}</div></div>
            </div>
            <div class="wallet-history">
                <div class="wh-title"><i class="fa-solid fa-clock-rotate-left"></i> Historia dostaw</div>
                ${historyHtml}
            </div>`;
    }

    // ── TOAST ──
    function toast(msg, type = 'info') {
        const c = document.getElementById('toast-container');
        const t = document.createElement('div');
        t.className = `toast ${type}`;
        t.textContent = msg;
        c.appendChild(t);
        setTimeout(() => t.remove(), 4000);
    }

    // ── INIT ──
    document.addEventListener('DOMContentLoaded', () => {
        tryAutoLogin();
    });

    return Object.freeze({
        pinDigit, pinClear, pinSubmit, logout,
        switchTab, poll, collectPayment, deliverOrder,
        acknowledgeRecall, loadWallet,
        openCancelModal, closeCancelModal, confirmCancelOrder,
        verifyAge,
    });
})();
