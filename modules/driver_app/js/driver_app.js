/**
 * SLICEHUB DRIVER APP — Main Application Controller
 * Login + hasło (mode: system), SSE + polling fallback, GPS 15s, payment lock, emergency recall.
 * 3-Pillar State Machine: payment_status = to_pay | online_unpaid | cash | card | online_paid
 * Driver App 2.0 — Full Connect: Start Shift, Status Toggle, SLA Badges, Reconcile, SSE, Service Worker
 */
const DriverApp = (() => {
    const POLL_INTERVAL = 10000;
    const GPS_INTERVAL = 15000;
    const RECALL_CHECK_INTERVAL = 12000;

    const LS_DISMISSED = 'sh_dismissed_courses';
    const LS_AGE_VERIFIED = 'sh_age_verified';
    const LS_SHIFT_ACTIVE = 'sh_driver_shift_active';

    function _loadSet(key) {
        try { const raw = localStorage.getItem(key); return raw ? new Set(JSON.parse(raw)) : new Set(); }
        catch { return new Set(); }
    }
    function _saveSet(key, set) {
        try { localStorage.setItem(key, JSON.stringify([...set])); } catch {}
    }

    const DRIVER_APP_ROLES = ['driver', 'manager', 'admin', 'owner'];

    const state = {
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
        sse: null,
        sseConnected: false,
        shiftActive: localStorage.getItem(LS_SHIFT_ACTIVE) === '1',
        driverStatus: 'offline',
        // Phase A — SLA thresholds z get_driver_runs (sh_tenant_settings). Default 10/5.
        slaThresholds: { green_min: 10, yellow_min: 5 },
    };

    function formatGrosze(g) {
        return (parseInt(g, 10) / 100).toFixed(2);
    }

    function isPaid(ps) {
        return ['cash', 'card', 'online_paid'].includes(ps);
    }

    function roleOkForDriverApp(role) {
        return DRIVER_APP_ROLES.includes(String(role || '').toLowerCase());
    }

    // ── SLA BADGES (Phase A — yellow_min czytane z state.slaThresholds) ──
    function slaClass(promisedTime) {
        if (!promisedTime) return 'sla-green';
        const diff = (new Date(promisedTime) - new Date()) / 60000;
        if (diff < 0) return 'sla-red';
        if (diff <= state.slaThresholds.yellow_min) return 'sla-yellow';
        return 'sla-green';
    }

    function slaText(promisedTime) {
        if (!promisedTime) return '--:--';
        const diff = Math.floor((new Date(promisedTime) - new Date()) / 60000);
        if (diff < 0) return `Spóźnione ${Math.abs(diff)}m`;
        if (diff <= 5) return `${diff}m`;
        return `${diff}m`;
    }

    // ── LOGIN (login + hasło) ──
    async function submitDriverLogin(username, password) {
        const errEl = document.getElementById('driver-login-err');
        if (errEl) errEl.textContent = '';
        const btn = document.getElementById('driver-btn-login');
        if (btn) btn.disabled = true;
        try {
            const res = await DriverAPI.loginSystem(username, password);
            if (res.success && res.data && res.data.token) {
                const u = res.data.user || {};
                if (!roleOkForDriverApp(u.role)) {
                    if (errEl) errEl.textContent = 'To konto nie jest uprawnione do aplikacji kierowcy.';
                    return;
                }
                DriverAPI.setToken(res.data.token);
                state.user = u;
                localStorage.setItem('sh_token', res.data.token);
                localStorage.setItem('sh_user', JSON.stringify(state.user));
                enterApp();
            } else {
                if (errEl) errEl.textContent = res.message || 'Błąd logowania';
            }
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    function enterApp() {
        document.getElementById('pin-screen').classList.add('hidden');
        document.getElementById('app-root').classList.remove('hidden');
        const u = state.user;
        document.getElementById('topbar-name').textContent = u.name || u.username || 'Kierowca';
        updateStatusUI('offline');
        if (!state.shiftActive) {
            showStartShiftModal();
        } else {
            startPollingAndSSE();
        }
    }

    function startPollingAndSSE() {
        poll();
        state.pollTimer = setInterval(poll, POLL_INTERVAL);
        startGPS();
        state.recallTimer = setInterval(checkRecall, RECALL_CHECK_INTERVAL);
        startSse();
    }

    function stopPollingAndSSE() {
        clearInterval(state.pollTimer);
        clearInterval(state.gpsTimer);
        clearInterval(state.recallTimer);
        stopSse();
    }

    function logout() {
        const tok = localStorage.getItem('sh_token') || '';
        void DriverAPI.setDriverStatus('offline');
        stopPollingAndSSE();
        const h = { 'Content-Type': 'application/json' };
        if (tok) {
            h['Authorization'] = 'Bearer ' + tok;
        }
        fetch((window.SliceHub && window.SliceHub.apiUrl)
            ? window.SliceHub.apiUrl('auth/logout.php')
            : ((window.SliceHub && window.SliceHub.getApiFallback) ? window.SliceHub.getApiFallback() : '/api') + '/auth/logout.php', {
            method: 'POST',
            headers: h,
            credentials: 'same-origin',
            body: JSON.stringify({}),
        }).catch(() => {});
        localStorage.removeItem('sh_token');
        localStorage.removeItem('sh_user');
        localStorage.removeItem(LS_DISMISSED);
        localStorage.removeItem(LS_AGE_VERIFIED);
        state.dismissedCourses.clear();
        state.ageVerified.clear();
        state.shiftActive = false;
        state.driverStatus = 'offline';
        localStorage.removeItem(LS_SHIFT_ACTIVE);
        state.user = null;
        document.getElementById('pin-screen').classList.remove('hidden');
        document.getElementById('app-root').classList.add('hidden');
    }

    function tryAutoLogin() {
        const token = localStorage.getItem('sh_token');
        const userData = localStorage.getItem('sh_user');
        if (token && userData) {
            try {
                state.user = JSON.parse(userData);
                if (!roleOkForDriverApp(state.user.role)) {
                    logout();
                    return;
                }
                DriverAPI.setToken(token);
                enterApp();
            } catch { /* login screen */ }
        }
    }

    function bindLoginForm() {
        const form = document.getElementById('driver-form-login');
        if (!form) return;
        form.addEventListener('submit', (ev) => {
            ev.preventDefault();
            const u = document.getElementById('driver-username');
            const p = document.getElementById('driver-password');
            void submitDriverLogin(u?.value || '', p?.value || '');
        });
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
        if (res.data.sla_thresholds) {
            const t = res.data.sla_thresholds;
            state.slaThresholds = {
                green_min:  Number.isFinite(+t.green_min)  ? +t.green_min  : 10,
                yellow_min: Number.isFinite(+t.yellow_min) ? +t.yellow_min : 5,
            };
        }
        // Sync driver status from backend — backend is source of truth
        if (res.data.driver_status) {
            state.shiftActive = !!res.data.shift_active;
            updateStatusUI(res.data.driver_status);
        }
        console.log(`POLL OK — ${state.orders.length} order(s) in delivery:`, state.orders);
        renderRuns();
        if (state.driverStatus === 'busy' || state.orders.length > 0) {
            document.getElementById('topbar-status').textContent = `${state.orders.length} zamówień w trasie`;
        }
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

    // ── SSE (pattern from online_track.js:202-249) ──
    function startSse() {
        if (!window.EventSource) return;
        if (!DriverAPI.getToken()) return;
        if (state.sse) { state.sse.close(); state.sse = null; state.sseConnected = false; }

        try {
            const es = new EventSource(DriverAPI.sseUrl());
            state.sse = es;

            es.addEventListener('connected', () => { state.sseConnected = true; });

            const driverEvents = ['order.assigned','order.dispatched','order.in_delivery','order.delivered','order.completed','order.cancelled','driver.recall','driver.status_change'];
            driverEvents.forEach(evType => {
                es.addEventListener(evType, () => { poll(); });
            });

            es.addEventListener('recall', () => {
                document.getElementById('emergency-overlay').classList.add('active');
                if (navigator.vibrate) navigator.vibrate([500, 200, 500, 200, 500]);
            });

            es.addEventListener('timeout', () => {
                es.close(); state.sse = null; state.sseConnected = false;
                setTimeout(startSse, 2000);
            });

            es.onerror = () => { state.sseConnected = false; };
        } catch (e) { /* SSE unavailable — polling is sufficient */ }
    }

    function stopSse() {
        if (state.sse) { state.sse.close(); state.sse = null; state.sseConnected = false; }
    }

    // ── START SHIFT ──
    function showStartShiftModal() {
        const overlay = document.getElementById('modal-start-shift');
        if (!overlay) { startPollingAndSSE(); return; }
        document.getElementById('shift-cash-input').value = '';
        overlay.classList.add('active');
        const btn = document.getElementById('shift-confirm-btn');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-play"></i> Rozpocznij zmianę';
    }

    function closeStartShiftModal() {
        document.getElementById('modal-start-shift').classList.remove('active');
    }

    async function confirmStartShift() {
        const cashInput = document.getElementById('shift-cash-input');
        const cash = parseFloat(cashInput.value) || 0;
        const btn = document.getElementById('shift-confirm-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rozpoczynanie...';

        const res = await DriverAPI.startShift(cash);
        if (res.success || res.httpCode === 409) {
            state.shiftActive = true;
            localStorage.setItem(LS_SHIFT_ACTIVE, '1');
            closeStartShiftModal();
            updateStatusUI('available');
            if (res.success) {
                toast(`Zmiana rozpoczęta — pogotowie: ${cash.toFixed(2)} zł`, 'success');
            } else {
                toast('Zmiana już aktywna — kontynuuj', 'info');
            }
            if (navigator.vibrate) navigator.vibrate(100);
            startPollingAndSSE();

            // HR clock_in — best effort, does not block shift
            DriverAPI.hrClockIn().then(hr => {
                if (!hr.success && hr.message && !hr.message.includes('ALREADY_CLOCKED_IN')) {
                    console.warn('[DriverApp] HR clock_in failed:', hr.message);
                }
            });
        } else {
            toast(res.message || 'Błąd rozpoczynania zmiany', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-play"></i> Rozpocznij zmianę';
        }
    }

    // ── STATUS TOGGLE ──
    async function toggleStatus() {
        if (state.driverStatus === 'offline') {
            toast('Najpierw rozpocznij zmianę', 'error');
            return;
        }
        const newStatus = state.driverStatus === 'available' ? 'busy' : 'available';
        const res = await DriverAPI.setDriverStatus(newStatus);
        if (res.success) {
            updateStatusUI(newStatus);
            toast(newStatus === 'busy' ? 'Status: Zajęty' : 'Status: Dostępny', 'info');
            if (navigator.vibrate) navigator.vibrate(50);
        } else {
            toast(res.message || 'Błąd zmiany statusu', 'error');
        }
    }

    function updateStatusUI(status) {
        state.driverStatus = status;
        const btn = document.getElementById('topbar-status-btn');
        const label = document.getElementById('topbar-status');
        if (!btn || !label) return;
        if (status === 'available') {
            btn.className = 'topbar-status-btn available';
            btn.innerHTML = '<i class="fa-solid fa-circle"></i>';
            label.textContent = state.orders.length > 0 ? `${state.orders.length} zamówień w trasie` : 'Dostępny';
        } else if (status === 'busy') {
            btn.className = 'topbar-status-btn busy';
            btn.innerHTML = '<i class="fa-solid fa-circle"></i>';
            label.textContent = 'Zajęty';
        } else {
            btn.className = 'topbar-status-btn offline';
            btn.innerHTML = '<i class="fa-solid fa-circle"></i>';
            label.textContent = 'Offline';
        }
    }

    // ── RECONCILE (end shift) ──
    function showReconcileModal() {
        const overlay = document.getElementById('modal-reconcile');
        if (!overlay) return;
        const w = state.walletDetail || state.wallet;
        const expectedEl = document.getElementById('reconcile-expected');
        if (expectedEl && w) {
            const total = formatGrosze(w.total_in_hand_grosze || w.total_in_hand * 100);
            expectedEl.textContent = total + ' zł';
        }
        document.getElementById('reconcile-counted-input').value = '';
        overlay.classList.add('active');
        const btn = document.getElementById('reconcile-confirm-btn');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check-double"></i> Zakończ zmianę';
    }

    function closeReconcileModal() {
        document.getElementById('modal-reconcile').classList.remove('active');
    }

    async function confirmReconcile() {
        const countedInput = document.getElementById('reconcile-counted-input');
        const counted = parseFloat(countedInput.value);
        if (isNaN(counted)) {
            toast('Wpisz policzoną kwotę gotówki', 'error');
            return;
        }
        const btn = document.getElementById('reconcile-confirm-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rozliczanie...';

        const res = await DriverAPI.reconcile(counted);
        if (res.success) {
            const d = res.data;
            closeReconcileModal();
            state.shiftActive = false;
            localStorage.removeItem(LS_SHIFT_ACTIVE);
            stopPollingAndSSE();
            const flagText = d.flag === 'OK' ? '✅ Rozliczenie zgodne' : '⚠️ Wymaga weryfikacji';
            toast(`${flagText} — oczekiwano: ${d.expected} zł, policzono: ${d.counted} zł, różnica: ${d.variance} zł`, d.flag === 'OK' ? 'success' : 'error');
            if (navigator.vibrate) navigator.vibrate(d.flag === 'OK' ? 200 : [200, 100, 200, 100, 200]);
            updateStatusUI('offline');

            // HR clock_out — best effort, does not block
            DriverAPI.hrClockOut().then(hr => {
                if (!hr.success) {
                    console.warn('[DriverApp] HR clock_out failed:', hr.message);
                }
            });

            // After reconcile: stay logged in, show start shift modal for next shift
            setTimeout(() => showStartShiftModal(), 2000);
        } else {
            toast(res.message || 'Błąd rozliczenia', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check-double"></i> Zakończ zmianę';
        }
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

                const sla = slaClass(o.promised_time);
                const slaT = slaText(o.promised_time);
                const tipHtml = (o.tip_amount && parseInt(o.tip_amount) > 0)
                    ? `<div class="d-card-tip"><i class="fa-solid fa-coins"></i> Napiwek: ${formatGrosze(o.tip_amount)} zł</div>`
                    : '';

                html += `
                <div class="d-card ${sla}">
                    <div class="d-card-header">
                        <div style="display:flex; align-items:center">
                            <div class="d-card-stop">${o.stop_number || '—'}</div>
                            <span class="d-card-num">#${(o.order_number || '').split('/').pop()}</span>
                            <span class="d-card-sla ${sla}">${slaT}</span>
                        </div>
                        <span class="d-card-total">${total} zł</span>
                    </div>
                    <div class="d-card-body">
                        <div class="d-card-addr"><i class="fa-solid fa-location-dot" style="margin-right:6px; color:var(--accent-blue)"></i>${addr}</div>
                        ${phone ? `<div class="d-card-phone"><i class="fa-solid fa-phone" style="margin-right:6px"></i>${phone}</div>` : ''}
                        ${o.customer_name ? `<div class="d-card-phone"><i class="fa-solid fa-user" style="margin-right:6px"></i>${o.customer_name}</div>` : ''}
                        ${payBadge}
                        ${tipHtml}
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

        const reconcileBtn = state.shiftActive
            ? `<button class="wallet-reconcile-btn" onclick="DriverApp.showReconcileModal()"><i class="fa-solid fa-check-double"></i> Zakończ zmianę i rozlicz gotówkę</button>`
            : '';

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
            ${reconcileBtn}
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

    // ── SERVICE WORKER REGISTRATION (pattern from pos_sw_register.js) ──
    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return;
        const swUrl = (window.SliceHub && window.SliceHub.appUrl)
            ? window.SliceHub.appUrl('modules/driver_app/sw.js')
            : new URL('sw.js', location.href).href;
        navigator.serviceWorker.register(swUrl, { scope: './' })
            .then(() => { console.log('[DriverApp] SW registered'); })
            .catch(() => { /* SW optional — app works without it */ });
    }

    // ── INIT ──
    document.addEventListener('DOMContentLoaded', () => {
        bindLoginForm();
        tryAutoLogin();
        registerServiceWorker();
    });

    return Object.freeze({
        logout,
        switchTab, poll, collectPayment, deliverOrder,
        acknowledgeRecall, loadWallet,
        openCancelModal, closeCancelModal, confirmCancelOrder,
        verifyAge,
        toggleStatus,
        showStartShiftModal, closeStartShiftModal, confirmStartShift,
        showReconcileModal, closeReconcileModal, confirmReconcile,
    });
})();
