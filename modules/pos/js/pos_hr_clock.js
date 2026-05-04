/**
 * SLICEHUB POS — HR clock (zmiana): clock_status, PIN in/out, opcjonalnie "Moja zmiana" (auth.self).
 * API: PosAPI.hrClock → /api/backoffice/hr/engine.php
 */
export function initPosHrClock(PosAPI, PosUI, tenantId) {
    const modal = document.getElementById('hr-clock-modal');
    const btn = document.getElementById('btn-hr-clock');
    const badge = document.getElementById('hr-clock-badge');
    const dotsEl = document.getElementById('hrclk-dots');
    const listEl = document.getElementById('hrclk-open-list');
    const selfBtn = document.getElementById('hrclk-self');
    let pinBuf = '';
    let pollTimer = null;

    function openModal(open) {
        if (!modal) return;
        modal.classList.toggle('active', open);
        if (open) {
            pinBuf = '';
            updateDots();
            void refreshOpenList();
        }
    }

    function updateDots() {
        if (!dotsEl) return;
        const n = Math.max(pinBuf.length, 0);
        dotsEl.innerHTML = Array.from({ length: 4 }, (_, i) =>
            `<span class="hrclk-dot ${i < n ? 'filled' : ''}"></span>`).join('');
    }

    function renderOpenList(data) {
        if (!listEl) return;
        const sessions = data?.open_sessions || [];
        if (!sessions.length) {
            listEl.innerHTML = '<p class="hrclk-empty">Nikt nie jest odnotowany na zmianie.</p>';
            return;
        }
        listEl.innerHTML = '<div class="hrclk-sessions-title">Na zmianie</div>' + sessions.map((s) => {
            const min = Math.floor(s.elapsed_seconds / 60);
            const h = Math.floor(min / 60);
            const m = min % 60;
            const t = h > 0 ? `${h}h ${m}m` : `${m}m`;
            return `<div class="hrclk-row"><span class="hrclk-name">${escapeHtml(s.employee_display_name)}</span><span class="hrclk-meta">${escapeHtml(s.employee_code)} · ${t}</span></div>`;
        }).join('');
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    async function refreshOpenList() {
        const res = await PosAPI.hrClock('clock_status', { source: 'pos' });
        if (res.success && res.data) {
            renderOpenList(res.data);
        } else {
            listEl.innerHTML = `<p class="hrclk-err">${escapeHtml(res.message || 'Błąd statusu')}</p>`;
        }
    }

    function updateTopBadge(data) {
        if (!badge) return;
        const n = (data?.open_sessions || []).length;
        if (n === 0) {
            badge.textContent = 'Zmiana';
        } else {
            badge.textContent = `Zmiana · ${n}`;
        }
    }

    async function refreshBadgeOnly() {
        const res = await PosAPI.hrClock('clock_status', { source: 'pos' });
        if (res.success && res.data) {
            updateTopBadge(res.data);
        }
    }

    async function smartPinToggle() {
        const pin = pinBuf.trim();
        if (!/^\d{4}$/.test(pin)) {
            PosUI.toast('PIN: dokładnie 4 cyfry', 'warn');
            return;
        }
        const body = { auth: { pin }, source: 'pos' };

        let res = await PosAPI.hrClock('clock_out', body);
        if (res.success) {
            PosUI.toast('Zmiana zakończona', 'success');
            pinBuf = '';
            updateDots();
            await refreshOpenList();
            await refreshBadgeOnly();
            return;
        }

        if (res.code === 'NO_OPEN_SESSION' || (res.message && String(res.message).includes('NO_OPEN_SESSION'))) {
            res = await PosAPI.hrClock('clock_in', body);
            if (res.success) {
                PosUI.toast('Zmiana rozpoczęta', 'success');
                pinBuf = '';
                updateDots();
                await refreshOpenList();
                await refreshBadgeOnly();
            } else {
                PosUI.toast(res.message || 'Nie udało się wejść na zmianę', 'error');
            }
            return;
        }

        PosUI.toast(res.message || 'Błąd', 'error');
    }

    async function smartSelfToggle() {
        const body = { auth: { self: true }, source: 'pos' };
        let res = await PosAPI.hrClock('clock_out', body);
        if (res.success) {
            PosUI.toast('Zmiana zakończona (Twoje konto)', 'success');
            await refreshOpenList();
            await refreshBadgeOnly();
            return;
        }
        if (res.code === 'NO_OPEN_SESSION' || (res.message && String(res.message).includes('NO_OPEN_SESSION'))) {
            res = await PosAPI.hrClock('clock_in', body);
            if (res.success) {
                PosUI.toast('Zmiana rozpoczęta (Twoje konto)', 'success');
                await refreshOpenList();
                await refreshBadgeOnly();
            } else if (res.code === 'EMPLOYEE_NOT_FOUND' || (res.message && /not registered as an employee/i.test(res.message))) {
                PosUI.toast('Brak profilu HR — uzupełnij w module Kadry', 'warn');
            } else {
                PosUI.toast(res.message || 'Błąd wejścia', 'error');
            }
            return;
        }
        if (res.code === 'EMPLOYEE_NOT_FOUND' || (res.message && /not registered as an employee/i.test(res.message))) {
            PosUI.toast('Brak profilu HR — uzupełnij w module Kadry', 'warn');
            return;
        }
        PosUI.toast(res.message || 'Błąd', 'error');
    }

    window._hrclkPin = (v) => {
        if (v === 'clear') {
            pinBuf = '';
        } else if (v === 'back') {
            pinBuf = pinBuf.slice(0, -1);
        } else if (pinBuf.length < 4) {
            pinBuf += v;
        }
        updateDots();
    };

    if (btn) {
        btn.addEventListener('click', () => openModal(true));
    }
    if (modal) {
        modal.querySelectorAll('[data-hrclk-close]').forEach((el) => {
            el.addEventListener('click', () => openModal(false));
        });
    }
    document.getElementById('hrclk-close-x')?.addEventListener('click', () => openModal(false));
    document.getElementById('hrclk-submit')?.addEventListener('click', () => { void smartPinToggle(); });
    selfBtn?.addEventListener('click', () => { void smartSelfToggle(); });

    // Start badge poll
    void refreshBadgeOnly();
    pollTimer = setInterval(() => { void refreshBadgeOnly(); }, 45000);

    return {
        refreshBadge: refreshBadgeOnly,
        dispose: () => { if (pollTimer) clearInterval(pollTimer); },
    };
}
