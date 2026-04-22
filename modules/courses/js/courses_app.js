/**
 * SLICEHUB DISPATCHER — Main Application Controller
 * Auth, polling, dispatch workflow, modals.
 */
const App = (() => {
    const POLL_INTERVAL = 8000;
    const TENANT_ID = 1;

    const state = {
        pin: '',
        user: null,
        orders: [],
        drivers: [],
        courses: [],
        selectedDriverId: null,
        selectedOrderIds: [],
        activeTab: 'orders',
        pollTimer: null,
        cashModalDriverId: null,
        reconcileDriverId: null,
        recallDriverId: null,
        batchCourseId: null,
        assignCourseId: null,
    };

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
        const res = await CoursesAPI.loginPin(TENANT_ID, state.pin);
        if (res.success && res.data && res.data.token) {
            CoursesAPI.setToken(res.data.token);
            state.user = res.data.user || res.data;
            localStorage.setItem('sh_token', res.data.token);
            localStorage.setItem('sh_user', JSON.stringify(state.user));
            enterApp();
        } else {
            state.pin = '';
            updatePinDots();
            CoursesUI.toast(res.message || 'Nieprawidłowy PIN', 'error');
        }
    }

    function enterApp() {
        document.getElementById('pin-screen').classList.add('hidden');
        document.getElementById('app-root').classList.remove('hidden');
        const u = state.user;
        const uname = u.first_name || u.name || u.username || 'Operator';
        document.getElementById('topbar-user').textContent = uname;
        const navBadge = document.getElementById('nav-user-badge');
        if (navBadge) navBadge.textContent = uname;
        // Universal nav bar — hard URL redirects
        document.querySelectorAll('#nav-tabs .nav-tab[data-href]').forEach(tab => {
            tab.addEventListener('click', () => { window.location.href = tab.dataset.href; });
        });
        startClock();
        poll();
        state.pollTimer = setInterval(poll, POLL_INTERVAL);
    }

    function logout() {
        clearInterval(state.pollTimer);
        localStorage.removeItem('sh_token');
        localStorage.removeItem('sh_user');
        state.user = null;
        state.pin = '';
        updatePinDots();
        document.getElementById('pin-screen').classList.remove('hidden');
        document.getElementById('app-root').classList.add('hidden');
    }

    function startClock() {
        const tick = () => {
            const now = new Date();
            document.getElementById('topbar-clock').textContent = now.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        };
        tick();
        setInterval(tick, 1000);
    }

    // ── AUTO-LOGIN ──
    function tryAutoLogin() {
        const token = localStorage.getItem('sh_token');
        const userData = localStorage.getItem('sh_user');
        if (token && userData) {
            try {
                state.user = JSON.parse(userData);
                CoursesAPI.setToken(token);
                enterApp();
            } catch { /* fall through to pin screen */ }
        }
    }

    // ── TABS ──
    function switchTab(tab) {
        state.activeTab = tab;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === `tab-${tab}`));
        if (tab === 'map' && CoursesMap.isInitialized()) {
            setTimeout(() => CoursesMap.updateMarkers(state.orders, state.drivers), 100);
        }
    }

    // ── POLLING ──
    async function poll() {
        const res = await CoursesAPI.getDashboard();
        if (!res.success) return;

        state.orders = res.data.orders || [];
        state.drivers = res.data.drivers || [];
        state.courses = res.data.courses || [];

        render();
    }

    function render() {
        CoursesUI.renderDriversList(state.drivers, state.selectedDriverId);
        CoursesUI.renderOrdersGrid(state.orders, state.selectedOrderIds);
        CoursesUI.renderCoursesGrid(state.orders, state.courses, state.drivers);
        updateDispatchBar();

        if (CoursesMap.isInitialized()) {
            CoursesMap.updateMarkers(state.orders, state.drivers);
        }
    }

    // ── DISPATCH WORKFLOW ──
    function selectDriver(id) {
        if (state.selectedDriverId === id) {
            state.selectedDriverId = null;
        } else {
            state.selectedDriverId = id;
            if (state.selectedOrderIds.length === 0) {
                CoursesUI.toast('Wybierz zamówienia z listy, aby zbudować kurs.', 'info');
            }
        }
        render();
    }

    function toggleOrder(id, isReady) {
        if (!isReady) {
            CoursesUI.toast('To zamówienie nie jest jeszcze GOTOWE. Tylko gotowe zamówienia mogą być wysłane.', 'error');
            return;
        }
        const idx = state.selectedOrderIds.indexOf(id);
        if (idx === -1) {
            state.selectedOrderIds.push(id);
        } else {
            state.selectedOrderIds.splice(idx, 1);
        }
        render();
    }

    function updateDispatchBar() {
        const bar = document.getElementById('dispatch-bar');
        const sendBtn = document.getElementById('dispatch-send-btn');
        const createBtn = document.getElementById('dispatch-create-btn');
        const driverLabel = document.getElementById('dispatch-driver-name');
        const countLabel = document.getElementById('dispatch-count');

        if (state.selectedOrderIds.length > 0) {
            bar.classList.remove('hidden');
            countLabel.textContent = state.selectedOrderIds.length;

            if (state.selectedDriverId) {
                const driver = state.drivers.find(d => String(d.id) === String(state.selectedDriverId));
                driverLabel.textContent = driver ? (driver.first_name || driver.name) : '—';
                sendBtn.classList.remove('hidden');
                if (createBtn) createBtn.classList.add('hidden');
            } else {
                driverLabel.textContent = 'nie wybrano';
                sendBtn.classList.add('hidden');
                if (createBtn) createBtn.classList.remove('hidden');
            }
        } else {
            bar.classList.add('hidden');
        }
    }

    function cancelDispatch() {
        state.selectedDriverId = null;
        state.selectedOrderIds = [];
        render();
    }

    async function sendDispatch() {
        if (!state.selectedDriverId || state.selectedOrderIds.length === 0) return;

        const btn = document.getElementById('dispatch-send-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Wysyłanie...';

        const res = await CoursesAPI.dispatch(state.selectedDriverId, state.selectedOrderIds);

        if (res.success) {
            CoursesUI.toast(`Kurs ${res.data.course_id} wysłany!`, 'success');
            state.selectedDriverId = null;
            state.selectedOrderIds = [];
            switchTab('courses');
            await poll();
        } else if (res.data && res.data.reason === 'driver_busy') {
            btn.disabled = false;
            btn.textContent = 'Wyślij Kurs';
            openBatchModal(res.data.driver_name, res.data.active_course_id);
            return;
        } else {
            CoursesUI.toast(res.message || 'Błąd wysyłki kursu', 'error');
        }

        btn.disabled = false;
        btn.textContent = 'Wyślij Kurs';
    }

    // ── MAP ──
    function initMap() {
        CoursesMap.init();
        CoursesMap.updateMarkers(state.orders, state.drivers);
    }

    // ── MODALS ──
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    function openCashModal(driverId) {
        state.cashModalDriverId = driverId;
        document.getElementById('cash-input').value = '';
        openModal('modal-cash');
    }

    async function submitInitialCash() {
        const amount = parseFloat(document.getElementById('cash-input').value);
        if (isNaN(amount) || amount < 0) {
            CoursesUI.toast('Wpisz prawidłową kwotę', 'error');
            return;
        }
        const res = await CoursesAPI.setInitialCash(state.cashModalDriverId, amount);
        if (res.success) {
            CoursesUI.toast('Pogotowie kasowe ustawione', 'success');
            closeModal('modal-cash');
            await poll();
        } else {
            CoursesUI.toast(res.message, 'error');
        }
    }

    function openReconcileModal(driverId) {
        state.reconcileDriverId = driverId;
        document.getElementById('reconcile-input').value = '';
        document.getElementById('reconcile-result').style.display = 'none';
        openModal('modal-reconcile');
    }

    async function submitReconcile() {
        const counted = parseFloat(document.getElementById('reconcile-input').value);
        if (isNaN(counted) || counted < 0) {
            CoursesUI.toast('Wpisz prawidłową kwotę', 'error');
            return;
        }
        const res = await CoursesAPI.reconcile(state.reconcileDriverId, counted);
        if (res.success) {
            const d = res.data;
            const resultEl = document.getElementById('reconcile-result');
            const varianceColor = parseFloat(d.variance) === 0 ? 'var(--accent-green)' : 'var(--accent-red)';
            resultEl.innerHTML = `
                <div style="background:rgba(0,0,0,0.3); border-radius:8px; padding:12px; text-align:left; font-size:11px">
                    <div style="display:flex; justify-content:space-between; margin-bottom:4px"><span style="color:var(--text-muted)">Oczekiwane:</span><strong>${d.expected} zł</strong></div>
                    <div style="display:flex; justify-content:space-between; margin-bottom:4px"><span style="color:var(--text-muted)">Policzone:</span><strong>${d.counted} zł</strong></div>
                    <div style="display:flex; justify-content:space-between; border-top:1px solid var(--border); padding-top:6px; margin-top:4px"><span style="color:var(--text-muted)">Różnica:</span><strong style="color:${varianceColor}; font-size:14px">${d.variance} zł</strong></div>
                    <div style="text-align:center; margin-top:8px; font-size:10px; font-weight:900; color:${d.flag === 'OK' ? 'var(--accent-green)' : 'var(--accent-red)'}">${d.flag === 'OK' ? '✓ ROZLICZENIE OK' : '⚠ WYMAGA PRZEGLĄDU'}</div>
                </div>`;
            resultEl.style.display = 'block';
            CoursesUI.toast('Zmiana rozliczona', 'success');
            await poll();
        } else {
            CoursesUI.toast(res.message, 'error');
        }
    }

    // ── RECALL MODAL (Two-Step) ──
    function openRecallModal(driverId, driverName) {
        state.recallDriverId = driverId;
        document.getElementById('recall-driver-name').textContent = driverName || 'Kierowca';
        const btn = document.getElementById('recall-confirm-btn');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Tak, Zawróć';
        openModal('modal-recall');
    }

    async function confirmRecall() {
        if (!state.recallDriverId) return;
        const btn = document.getElementById('recall-confirm-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Wysyłanie...';

        const res = await CoursesAPI.emergencyRecall(state.recallDriverId);
        closeModal('modal-recall');

        if (res.success) {
            CoursesUI.toast('Sygnał ZAWRÓĆ wysłany!', 'success');
        } else {
            CoursesUI.toast(res.message || 'Błąd wysyłki sygnału', 'error');
        }
        state.recallDriverId = null;
    }

    // ── BATCH MODAL (Smart Batching) ──
    function openBatchModal(driverName, activeCourseId) {
        document.getElementById('batch-driver-name').textContent = driverName || 'Kierowca';
        document.getElementById('batch-course-id').textContent = activeCourseId || '—';
        document.getElementById('batch-append-id').textContent = activeCourseId || '';
        state.batchCourseId = activeCourseId;
        openModal('modal-batch');
    }

    async function batchAppend() {
        if (!state.batchCourseId || state.selectedOrderIds.length === 0) return;
        closeModal('modal-batch');

        const btn = document.getElementById('dispatch-send-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Dołączanie...';

        const res = await CoursesAPI.appendToCourse(state.batchCourseId, state.selectedOrderIds);

        if (res.success) {
            const total = res.data.total_stops || '?';
            CoursesUI.toast(`Dodano do kursu ${state.batchCourseId} (${total} przystanków)`, 'success');
            state.selectedDriverId = null;
            state.selectedOrderIds = [];
            state.batchCourseId = null;
            switchTab('courses');
            await poll();
        } else {
            CoursesUI.toast(res.message || 'Błąd dołączania do kursu', 'error');
        }

        btn.disabled = false;
        btn.textContent = 'Wyślij Kurs';
    }

    async function batchNewRun() {
        if (!state.selectedDriverId || state.selectedOrderIds.length === 0) return;
        closeModal('modal-batch');

        const btn = document.getElementById('dispatch-send-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Tworzenie...';

        const res = await CoursesAPI.dispatchForce(state.selectedDriverId, state.selectedOrderIds);

        if (res.success) {
            CoursesUI.toast(`Nowy kurs ${res.data.course_id} utworzony w kolejce!`, 'success');
            state.selectedDriverId = null;
            state.selectedOrderIds = [];
            state.batchCourseId = null;
            switchTab('courses');
            await poll();
        } else {
            CoursesUI.toast(res.message || 'Błąd tworzenia kursu', 'error');
        }

        btn.disabled = false;
        btn.textContent = 'Wyślij Kurs';
    }

    // ── CREATE UNASSIGNED COURSE ──
    async function createUnassignedCourse() {
        if (state.selectedOrderIds.length === 0) return;

        const btn = document.getElementById('dispatch-create-btn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Tworzenie...'; }

        const res = await CoursesAPI.createCourse(state.selectedOrderIds);

        if (res.success) {
            CoursesUI.toast(`Kurs ${res.data.course_id} utworzony (bez kierowcy)`, 'success');
            state.selectedDriverId = null;
            state.selectedOrderIds = [];
            switchTab('courses');
            await poll();
        } else {
            CoursesUI.toast(res.message || 'Błąd tworzenia kursu', 'error');
        }

        if (btn) { btn.disabled = false; btn.textContent = 'Stwórz Kurs (bez kierowcy)'; }
    }

    // ── ASSIGN DRIVER TO COURSE (modal) ──
    function openAssignDriverModal(courseId) {
        state.assignCourseId = courseId;
        const onlineDrivers = state.drivers.filter(d => d.driver_status === 'available');
        const listEl = document.getElementById('assign-driver-list');

        if (onlineDrivers.length === 0) {
            listEl.innerHTML = '<div class="empty-state" style="padding:20px"><p>Brak dostępnych kierowców</p></div>';
        } else {
            listEl.innerHTML = onlineDrivers.map(d => {
                const name = d.first_name || d.name || 'Kierowca';
                return `<div class="assign-drv-option" data-did="${d.id}"><i class="fa-solid fa-motorcycle" style="color:var(--accent-blue);margin-right:8px"></i><span>${name}</span></div>`;
            }).join('');
            listEl.querySelectorAll('.assign-drv-option').forEach(el => {
                el.addEventListener('click', () => confirmAssignDriver(el.dataset.did));
            });
        }

        openModal('modal-assign-driver');
    }

    async function confirmAssignDriver(driverId) {
        if (!state.assignCourseId || !driverId) return;
        closeModal('modal-assign-driver');

        const res = await CoursesAPI.assignDriverToCourse(state.assignCourseId, driverId);
        if (res.success) {
            CoursesUI.toast(`Kierowca przypisany do ${state.assignCourseId}!`, 'success');
            state.assignCourseId = null;
            await poll();
        } else {
            CoursesUI.toast(res.message || 'Błąd przypisania kierowcy', 'error');
        }
    }

    // ── INIT ──
    document.addEventListener('DOMContentLoaded', () => {
        tryAutoLogin();
    });

    return Object.freeze({
        pinDigit, pinClear, pinSubmit, logout,
        switchTab, poll, selectDriver, toggleOrder,
        cancelDispatch, sendDispatch, initMap,
        openCashModal, submitInitialCash,
        openReconcileModal, submitReconcile,
        openRecallModal, confirmRecall,
        batchAppend, batchNewRun,
        createUnassignedCourse, openAssignDriverModal,
        closeModal,
    });
})();
