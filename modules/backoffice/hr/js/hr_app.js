/**
 * SliceHub — Backoffice HR module (employees list / upsert / PIN / hourly rate).
 * API: SliceHub.apiUrl('/backoffice/hr/engine.php') — session JWT jak POS.
 */
(() => {
    'use strict';

    function hrApiUrl() {
        if (window.SliceHub && window.SliceHub.apiUrl) {
            return window.SliceHub.apiUrl('/backoffice/hr/engine.php');
        }
        return '../../../api/backoffice/hr/engine.php';
    }
    const API = hrApiUrl();

    const PRIMARY_ROLES = ['cook', 'waiter', 'driver', 'manager', 'cashier', 'cleaner', 'runner', 'shift_lead', 'owner', 'team'];

    let _employees = [];
    let _payrollPeriodType = 'month';
    let _payrollPeriodOffset = 0;

    function getToken() {
        return localStorage.getItem('sh_token') || '';
    }

    function showAuthBanner(show) {
        const el = document.getElementById('hr-auth-banner');
        if (!el) return;
        el.classList.toggle('hidden', !show);
    }

    function showErrorBanner(msg) {
        const el = document.getElementById('hr-error-banner');
        if (!el) return;
        if (!msg) {
            el.classList.add('hidden');
            el.textContent = '';
            return;
        }
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    function toast(msg, ok = true) {
        const t = document.getElementById('hr-toast');
        if (!t) return;
        t.textContent = msg;
        t.className = 'hr-toast visible ' + (ok ? 'ok' : 'err');
        clearTimeout(toast._timer);
        toast._timer = setTimeout(() => {
            t.classList.remove('visible');
        }, 3200);
    }

    async function callHr(action, payload = {}) {
        const headers = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        };
        const tok = getToken();
        if (tok) headers.Authorization = 'Bearer ' + tok;

        const res = await fetch(API, {
            method: 'POST',
            headers,
            credentials: 'same-origin',
            body: JSON.stringify({ action, ...payload }),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || json.success === false) {
            const err = new Error(json.message || json.code || 'HTTP ' + res.status);
            err.httpCode = res.status;
            err.code = json.code;
            err.payload = json;
            throw err;
        }
        return json.data;
    }

    function fillRoleSelects() {
        const pr = document.getElementById('hr-f-primary_role');
        if (pr) {
            pr.innerHTML = PRIMARY_ROLES.map((r) => `<option value="${r}">${r}</option>`).join('');
        }
    }

    async function loadUnlinkedUsers() {
        const sel = document.getElementById('hr-f-user');
        if (!sel) return;
        try {
            const data = await callHr('hr_users_unlinked');
            const users = data.users || [];
            const keep = sel.value;
            sel.innerHTML = '<option value="">— brak / pracownik bez loginu —</option>';
            users.forEach((u) => {
                const o = document.createElement('option');
                o.value = String(u.id);
                o.textContent = `${u.username} (${u.display_label || u.role})`;
                sel.appendChild(o);
            });
            if (keep) sel.value = keep;
        } catch (e) {
            console.warn('[hr] hr_users_unlinked', e);
        }
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function renderTable() {
        const tb = document.getElementById('hr-tbody');
        if (!tb) return;
        if (!_employees.length) {
            tb.innerHTML = '<tr><td colspan="7" style="color:#78716c;padding:1.5rem;">Brak pracowników.</td></tr>';
            return;
        }
        tb.innerHTML = _employees.map((e) => {
            const acct = e.account_username
                ? `${esc(e.account_username)} <span class="hr-tag">${esc(e.account_role || '')}</span>`
                : '<span class="hr-tag hr-tag--off">—</span>';
            const pin = e.has_kiosk_pin
                ? '<span class="hr-tag hr-tag--ok">tak</span>'
                : '<span class="hr-tag hr-tag--off">nie</span>';
            const code = esc(e.employee_code);
            const name = esc(e.display_name);
            return `<tr data-id="${e.id}">
                <td><code style="font-size:0.78rem;color:#a8a29e;">${code}</code></td>
                <td>${name}</td>
                <td>${esc(e.primary_role)}</td>
                <td>${esc(e.status)}</td>
                <td>${acct}</td>
                <td>${pin}</td>
                <td class="hr-actions">
                    <button type="button" data-act="edit">Edytuj</button>
                    <button type="button" data-act="pin">PIN</button>
                    <button type="button" data-act="rate">Stawka</button>
                </td>
            </tr>`;
        }).join('');

        tb.querySelectorAll('tr[data-id]').forEach((row) => {
            row.querySelectorAll('button[data-act]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const id = parseInt(row.getAttribute('data-id'), 10);
                    const act = btn.getAttribute('data-act');
                    const emp = _employees.find((x) => x.id === id);
                    if (!emp) return;
                    if (act === 'edit') openEmployeeModal(emp);
                    if (act === 'pin') openPinModal(id);
                    if (act === 'rate') openRateModal(id);
                });
            });
        });
    }

    async function refreshList() {
        showErrorBanner('');
        const inc = document.getElementById('hr-inc-del')?.checked;
        try {
            const data = await callHr('employees_list', { include_deleted: inc ? 1 : 0 });
            _employees = data.employees || [];
            renderTable();
        } catch (e) {
            if (e.httpCode === 401 || e.httpCode === 403) {
                showAuthBanner(true);
            }
            showErrorBanner(e.message || 'Błąd listy');
            _employees = [];
            renderTable();
        }
    }

    // ---------------------------------------------------------------
    // Wypłaty (payroll_report) — wszystkie kwoty przychodzą gotowe z backendu.

    function switchTab(tab) {
        document.querySelectorAll('.hr-tab').forEach((b) => {
            b.classList.toggle('active', b.getAttribute('data-tab') === tab);
        });
        document.getElementById('hr-view-employees')?.classList.toggle('hidden', tab !== 'employees');
        document.getElementById('hr-view-payroll')?.classList.toggle('hidden', tab !== 'payroll');
        if (tab === 'payroll') refreshPayroll();
    }

    function renderPayroll(report) {
        const tb = document.getElementById('hr-pr-tbody');
        const tf = document.getElementById('hr-pr-tfoot');
        const label = document.getElementById('hr-pr-period-label');
        if (!tb || !tf) return;

        if (label) {
            const suffix = _payrollPeriodOffset === 0 ? ' (bieżący)' : '';
            label.textContent = (report?.period || '—') + suffix;
        }

        const rows = report?.employees || [];
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="7" style="color:#78716c;padding:1.5rem;">Brak danych wypłat w tym okresie.</td></tr>';
            tf.innerHTML = '';
            return;
        }

        tb.innerHTML = rows.map((e) => `<tr>
            <td>${esc(e.name)}</td>
            <td class="num">${esc(e.hours)}</td>
            <td class="num">${esc(e.rate)}</td>
            <td class="num">${esc(e.gross)}</td>
            <td class="num">${esc(e.meals)} / ${esc(e.deductions)}</td>
            <td class="num">${esc(e.advances_repaid)}</td>
            <td class="num">${esc(e.payout)}</td>
        </tr>`).join('');

        const t = report.totals || {};
        tf.innerHTML = `<tr>
            <td>Razem (${rows.length})</td>
            <td class="num">${esc(t.total_hours)}</td>
            <td class="num">—</td>
            <td class="num">${esc(t.total_labor_cost)}</td>
            <td class="num">${esc(t.total_deductions)}</td>
            <td class="num">${esc(t.total_advances_repaid)}</td>
            <td class="num">${esc(t.total_payout)}</td>
        </tr>`;
    }

    async function refreshPayroll() {
        showErrorBanner('');
        const tb = document.getElementById('hr-pr-tbody');
        if (tb) tb.innerHTML = '<tr><td colspan="7" style="color:#78716c;padding:1.5rem;">Ładowanie…</td></tr>';

        const nextBtn = document.getElementById('hr-pr-next');
        if (nextBtn) nextBtn.disabled = _payrollPeriodOffset === 0;

        try {
            const data = await callHr('payroll_report', {
                period_type: _payrollPeriodType,
                period_offset: _payrollPeriodOffset,
            });
            renderPayroll(data.payroll_report || {});
        } catch (e) {
            if (e.httpCode === 401 || e.httpCode === 403) showAuthBanner(true);
            showErrorBanner(e.message || 'Błąd raportu wypłat');
            renderPayroll({ employees: [] });
        }
    }

    function openModal(id, open = true) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.toggle('active', open);
        el.setAttribute('aria-hidden', open ? 'false' : 'true');
    }

    function openEmployeeModal(existing = null) {
        const form = document.getElementById('hr-form-employee');
        const title = document.getElementById('hr-modal-employee-title');
        if (!form || !title) return;

        form.reset();
        document.getElementById('hr-f-id').value = existing ? String(existing.id) : '';
        title.textContent = existing ? 'Edytuj pracownika' : 'Nowy pracownik';

        const createCb = document.getElementById('hr-f-create-login');
        const createBox = document.getElementById('hr-create-login-fields');
        if (createCb) createCb.checked = false;
        if (createBox) createBox.classList.add('hidden');

        const hourlyWrap = document.getElementById('hr-field-hourly-wrap');
        if (existing) {
            document.getElementById('hr-f-first_name').value = existing.first_name || '';
            document.getElementById('hr-f-last_name').value = existing.last_name || '';
            document.getElementById('hr-f-display_name').value = existing.display_name || '';
            document.getElementById('hr-f-primary_role').value = existing.primary_role || 'cashier';
            document.getElementById('hr-f-status').value = existing.status || 'active';
            document.getElementById('hr-f-hire_date').value = (existing.hire_date || '').slice(0, 10);
            document.getElementById('hr-f-notes').value = existing.notes || '';
            document.getElementById('hr-f-hourly').value = '';
            document.getElementById('hr-f-user').value = existing.user_id ? String(existing.user_id) : '';
            const pp = document.getElementById('hr-f-cl-pospin');
            if (pp) pp.value = '';
            if (createCb) createCb.disabled = true;
            if (hourlyWrap) hourlyWrap.classList.add('hidden');
        } else {
            if (hourlyWrap) hourlyWrap.classList.remove('hidden');
            document.getElementById('hr-f-hire_date').value = new Date().toISOString().slice(0, 10);
            document.getElementById('hr-f-primary_role').value = 'waiter';
            if (createCb) createCb.disabled = false;
        }

        loadUnlinkedUsers().then(() => {
            if (existing && existing.user_id) {
                const sel = document.getElementById('hr-f-user');
                if (sel && !Array.from(sel.options).some((o) => o.value === String(existing.user_id))) {
                    const o = document.createElement('option');
                    o.value = String(existing.user_id);
                    o.textContent = existing.account_username
                        ? `${existing.account_username} (powiązane)`
                        : `user #${existing.user_id}`;
                    o.selected = true;
                    sel.appendChild(o);
                }
                if (sel) sel.value = String(existing.user_id);
            }
        });

        openModal('hr-modal-employee', true);
    }

    function openPinModal(employeeId) {
        document.getElementById('hr-pin-employee-id').value = String(employeeId);
        document.getElementById('hr-pin-val').value = '';
        openModal('hr-modal-pin', true);
    }

    function openRateModal(employeeId) {
        document.getElementById('hr-rate-employee-id').value = String(employeeId);
        document.getElementById('hr-rate-pln').value = '';
        document.getElementById('hr-rate-note').value = '';
        openModal('hr-modal-rate', true);
    }

    async function submitEmployee(ev) {
        ev.preventDefault();
        const idStr = document.getElementById('hr-f-id').value.trim();
        const payload = {
            first_name: document.getElementById('hr-f-first_name').value.trim(),
            last_name: document.getElementById('hr-f-last_name').value.trim(),
            display_name: document.getElementById('hr-f-display_name').value.trim(),
            primary_role: document.getElementById('hr-f-primary_role').value,
            status: document.getElementById('hr-f-status').value,
            hire_date: document.getElementById('hr-f-hire_date').value,
            notes: document.getElementById('hr-f-notes').value.trim(),
        };
        const uid = document.getElementById('hr-f-user').value;
        if (idStr) {
            payload.user_id = uid ? parseInt(uid, 10) : null;
        } else if (uid) {
            payload.user_id = parseInt(uid, 10);
        }

        const hourly = document.getElementById('hr-f-hourly').value.trim();
        // Początkowa stawka tylko przy tworzeniu profilu (backend ignoruje przy update).
        if (!idStr && hourly !== '') payload.hourly_amount_pln = parseFloat(hourly);

        if (idStr) payload.id = parseInt(idStr, 10);

        const createLogin = document.getElementById('hr-f-create-login')?.checked;
        if (!idStr && createLogin) {
            const posPin = document.getElementById('hr-f-cl-pospin')?.value.trim() ?? '';
            const uname = document.getElementById('hr-f-cl-user').value.trim();
            const pw = document.getElementById('hr-f-cl-pass').value;
            if (!uname) {
                toast('Podaj username dla nowego konta', false);
                return;
            }
            if (!pw || pw.length < 6) {
                toast('Hasło min. 6 znaków', false);
                return;
            }
            if (!/^\d{4}$/.test(posPin)) {
                toast('PIN do kasy: dokładnie 4 cyfry (wymagane z POS)', false);
                return;
            }
            payload.create_login = {
                username: uname,
                password: pw,
                pos_pin: posPin,
            };
        }

        try {
            await callHr('employee_upsert', { employee: payload });
            toast('Zapisano pracownika', true);
            openModal('hr-modal-employee', false);
            await refreshList();
        } catch (e) {
            toast(e.message || 'Błąd zapisu', false);
        }
    }

    async function submitPin() {
        const eid = parseInt(document.getElementById('hr-pin-employee-id').value, 10);
        const pin = document.getElementById('hr-pin-val').value.trim();
        if (!/^\d{4}$/.test(pin)) {
            toast('PIN: dokładnie 4 cyfry', false);
            return;
        }
        try {
            await callHr('employee_pin_set', { employee_id: eid, pin });
            toast('PIN zapisany', true);
            openModal('hr-modal-pin', false);
            await refreshList();
        } catch (e) {
            toast(e.message || 'Błąd PIN', false);
        }
    }

    async function submitRate() {
        const eid = parseInt(document.getElementById('hr-rate-employee-id').value, 10);
        const pln = parseFloat(document.getElementById('hr-rate-pln').value);
        const note = document.getElementById('hr-rate-note').value.trim();
        if (Number.isNaN(pln) || pln < 0) {
            toast('Podaj prawidłową stawkę', false);
            return;
        }
        try {
            await callHr('employee_rate_set', {
                employee_id: eid,
                hourly_amount_pln: pln,
                reason: 'correction',
                note: note || null,
            });
            toast('Stawka zapisana', true);
            openModal('hr-modal-rate', false);
            await refreshList();
        } catch (e) {
            toast(e.message || 'Błąd stawki', false);
        }
    }

    function wire() {
        fillRoleSelects();
        showAuthBanner(!getToken());

        document.getElementById('hr-btn-refresh')?.addEventListener('click', () => {
            const payrollActive = !document.getElementById('hr-view-payroll')?.classList.contains('hidden');
            return payrollActive ? refreshPayroll() : refreshList();
        });
        document.getElementById('hr-btn-add')?.addEventListener('click', () => openEmployeeModal(null));
        document.getElementById('hr-inc-del')?.addEventListener('change', refreshList);

        document.querySelectorAll('.hr-tab').forEach((b) => {
            b.addEventListener('click', () => switchTab(b.getAttribute('data-tab')));
        });
        document.querySelectorAll('#hr-pr-period-type button').forEach((b) => {
            b.addEventListener('click', () => {
                _payrollPeriodType = b.getAttribute('data-period');
                _payrollPeriodOffset = 0;
                document.querySelectorAll('#hr-pr-period-type button')
                    .forEach((x) => x.classList.toggle('active', x === b));
                refreshPayroll();
            });
        });
        document.getElementById('hr-pr-prev')?.addEventListener('click', () => {
            _payrollPeriodOffset += 1;
            refreshPayroll();
        });
        document.getElementById('hr-pr-next')?.addEventListener('click', () => {
            if (_payrollPeriodOffset === 0) return;
            _payrollPeriodOffset -= 1;
            refreshPayroll();
        });
        document.getElementById('hr-pr-refresh')?.addEventListener('click', refreshPayroll);

        document.getElementById('hr-form-employee')?.addEventListener('submit', submitEmployee);
        document.getElementById('hr-form-cancel')?.addEventListener('click', () => openModal('hr-modal-employee', false));

        document.getElementById('hr-f-create-login')?.addEventListener('change', (ev) => {
            document.getElementById('hr-create-login-fields')?.classList.toggle('hidden', !ev.target.checked);
        });

        document.getElementById('hr-pin-cancel')?.addEventListener('click', () => openModal('hr-modal-pin', false));
        document.getElementById('hr-pin-save')?.addEventListener('click', submitPin);

        document.getElementById('hr-rate-cancel')?.addEventListener('click', () => openModal('hr-modal-rate', false));
        document.getElementById('hr-rate-save')?.addEventListener('click', submitRate);

        ['hr-modal-employee', 'hr-modal-pin', 'hr-modal-rate'].forEach((mid) => {
            document.getElementById(mid)?.addEventListener('click', (ev) => {
                if (ev.target.id === mid) openModal(mid, false);
            });
        });

        refreshList();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
})();
