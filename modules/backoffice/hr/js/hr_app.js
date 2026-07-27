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
    let _advStatusFilter = '';
    let _activeTab = 'employees';

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
                    <button type="button" data-act="ledger">Płace</button>
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
                    if (act === 'ledger') openLedgerModal(emp);
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
        document.getElementById('hr-view-advances')?.classList.toggle('hidden', tab !== 'advances');
        _activeTab = tab;
        if (tab === 'payroll') refreshPayroll();
        if (tab === 'advances') refreshAdvances();
    }

    // SSOT odswiezania: KAZDA mutacja konczy sie tym wywolaniem, zamiast
    // zgadywac per-handler, ktory widok przeladowac (wczesniej submitLedger
    // nie odswiezal nic i tabela wyplat zostawala nieaktualna).
    function refreshActiveTab() {
        if (_activeTab === 'payroll') return refreshPayroll();
        if (_activeTab === 'advances') return refreshAdvances();
        return refreshList();
    }

    function plnFromMinor(minor) {
        return (Number(minor || 0) / 100).toFixed(2) + ' zł';
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
        refreshPeriodLockUi();
    }

    // ---------------------------------------------------------------
    // Zamykanie miesiąca (PayrollLedger::lockPeriod — jednokierunkowe).
    // Lock operuje na pełnych miesiącach, więc UI pokazujemy tylko w widoku
    // 'month' dla okresów minionych (offset >= 1) — spójnie z guardem API.

    function payrollMonthFromOffset(offset) {
        const d = new Date();
        d.setDate(1);
        d.setMonth(d.getMonth() - offset);
        return { year: d.getFullYear(), month: d.getMonth() + 1 };
    }

    async function refreshPeriodLockUi() {
        const btn = document.getElementById('hr-pr-close-period');
        const badge = document.getElementById('hr-pr-lock-badge');
        if (!btn || !badge) return;

        btn.classList.add('hidden');
        badge.classList.add('hidden');

        if (_payrollPeriodType !== 'month' || _payrollPeriodOffset === 0) return;

        const p = payrollMonthFromOffset(_payrollPeriodOffset);
        try {
            const st = await callHr('payroll_period_status', {
                period_year: p.year,
                period_month: p.month,
            });
            if (st.is_locked) {
                badge.textContent = 'Okres zamknięty';
                badge.className = 'hr-tag hr-tag--ok';
            } else if (st.entries_total > 0) {
                btn.classList.remove('hidden');
            } else {
                badge.textContent = 'Brak wpisów';
                badge.className = 'hr-tag hr-tag--off';
            }
        } catch (e) {
            console.warn('[hr] payroll_period_status', e);
        }
    }

    async function closePeriod() {
        const p = payrollMonthFromOffset(_payrollPeriodOffset);
        const label = `${p.year}-${String(p.month).padStart(2, '0')}`;
        const msg = `Zamknąć okres ${label}?\n\nOperacja jest JEDNOKIERUNKOWA — zamkniętego miesiąca nie da się odblokować. Korekty po zamknięciu księguje się jako 'adjustment' w kolejnym otwartym okresie.`;
        if (!window.confirm(msg)) return;
        try {
            const res = await callHr('payroll_close_period', {
                period_year: p.year,
                period_month: p.month,
            });
            const repaid = res.installments_repaid?.length ?? 0;
            toast(`Okres ${label} zamknięty (${res.locked_entries} wpisów${repaid > 0 ? `, ${repaid} rat zaliczek spłaconych` : ''})`, true);
            await refreshPayroll();
        } catch (e) {
            toast(e.message || 'Błąd zamykania okresu', false);
        }
    }

    // ---------------------------------------------------------------
    // Zaliczki (sh_advances, lifecycle przez AdvanceEngine)

    const ADV_STATUS_LABELS = {
        requested: 'wniosek', approved: 'zatwierdzona', rejected: 'odrzucona',
        paid: 'wypłacona', settled: 'rozliczona', void: 'wycofana',
    };

    function advActionsHtml(a) {
        if (a.status === 'requested') {
            return `<button type="button" data-adv="approve">Zatwierdź</button>
                    <button type="button" data-adv="reject">Odrzuć</button>`;
        }
        if (a.status === 'approved') {
            return `<button type="button" data-adv="pay-cash">Wypłać gotówką</button>
                    <button type="button" data-adv="pay-transfer">Przelew</button>`;
        }
        if (a.status === 'paid' && a.installments_paid === 0) {
            return `<button type="button" data-adv="void">Wycofaj</button>`;
        }
        return '';
    }

    function renderAdvances(rows) {
        const tb = document.getElementById('hr-adv-tbody');
        if (!tb) return;
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="7" style="color:#78716c;padding:1.5rem;">Brak zaliczek.</td></tr>';
            return;
        }
        tb.innerHTML = rows.map((a) => {
            const plan = a.repayment_plan === 'installments'
                ? `raty ${a.installments_paid}/${a.installments_count}`
                : 'jednorazowo';
            const statusTag = a.status === 'settled' || a.status === 'paid'
                ? 'hr-tag hr-tag--ok' : 'hr-tag';
            return `<tr data-id="${a.id}">
                <td>${a.id}</td>
                <td>${esc(a.employee_name)}</td>
                <td class="num">${plnFromMinor(a.amount_minor)}</td>
                <td><span class="${statusTag}">${esc(ADV_STATUS_LABELS[a.status] || a.status)}</span></td>
                <td>${esc(plan)}</td>
                <td>${esc(a.reason || a.rejection_reason || '—')}</td>
                <td class="hr-actions">${advActionsHtml(a)}</td>
            </tr>`;
        }).join('');

        tb.querySelectorAll('tr[data-id]').forEach((row) => {
            row.querySelectorAll('button[data-adv]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    advanceAction(parseInt(row.getAttribute('data-id'), 10), btn.getAttribute('data-adv'));
                });
            });
        });
    }

    async function refreshAdvances() {
        showErrorBanner('');
        const tb = document.getElementById('hr-adv-tbody');
        if (tb) tb.innerHTML = '<tr><td colspan="7" style="color:#78716c;padding:1.5rem;">Ładowanie…</td></tr>';
        try {
            const payload = _advStatusFilter ? { status: _advStatusFilter } : {};
            const data = await callHr('advances_list', payload);
            renderAdvances(data.advances || []);
        } catch (e) {
            if (e.httpCode === 401 || e.httpCode === 403) showAuthBanner(true);
            showErrorBanner(e.message || 'Błąd listy zaliczek');
            renderAdvances([]);
        }
    }

    async function advanceAction(advanceId, act) {
        try {
            if (act === 'approve') {
                await callHr('advance_approve', { advance_id: advanceId });
                toast('Zaliczka zatwierdzona', true);
            } else if (act === 'reject') {
                const reason = window.prompt('Powód odrzucenia (wymagany):');
                if (!reason || !reason.trim()) return;
                await callHr('advance_reject', { advance_id: advanceId, reason: reason.trim() });
                toast('Zaliczka odrzucona', true);
            } else if (act === 'pay-cash' || act === 'pay-transfer') {
                const method = act === 'pay-cash' ? 'cash' : 'transfer';
                if (!window.confirm('Oznaczyć zaliczkę jako wypłaconą (' + method + ')? Utworzy wpis w ledgerze i harmonogram spłat.')) return;
                await callHr('advance_mark_paid', { advance_id: advanceId, method });
                toast('Zaliczka wypłacona', true);
            } else if (act === 'void') {
                const reason = window.prompt('Powód wycofania (wymagany, audit trail):');
                if (!reason || !reason.trim()) return;
                await callHr('advance_void', { advance_id: advanceId, reason: reason.trim() });
                toast('Zaliczka wycofana (reversal w ledgerze)', true);
            } else {
                return;
            }
            await refreshActiveTab();
        } catch (e) {
            toast(e.message || 'Błąd operacji na zaliczce', false);
        }
    }

    async function openAdvanceModal() {
        const sel = document.getElementById('hr-adv-employee');
        if (!sel) return;
        if (!_employees.length) {
            try {
                const data = await callHr('employees_list', {});
                _employees = data.employees || [];
            } catch (e) { /* banner ustawi refreshList przy następnym wejściu */ }
        }
        const active = _employees.filter((e) => e.status === 'active');
        sel.innerHTML = active.map((e) => `<option value="${e.id}">${esc(e.display_name)}</option>`).join('');
        document.getElementById('hr-adv-amount').value = '';
        document.getElementById('hr-adv-plan').value = 'single';
        document.getElementById('hr-adv-installments').value = '2';
        document.getElementById('hr-adv-inst-wrap')?.classList.add('hidden');
        document.getElementById('hr-adv-reason').value = '';
        openModal('hr-modal-advance', true);
    }

    async function submitAdvance() {
        const employeeId = parseInt(document.getElementById('hr-adv-employee').value, 10);
        const pln = parseFloat(document.getElementById('hr-adv-amount').value);
        const plan = document.getElementById('hr-adv-plan').value;
        const inst = parseInt(document.getElementById('hr-adv-installments').value, 10);
        const reason = document.getElementById('hr-adv-reason').value.trim();
        if (!employeeId) { toast('Wybierz pracownika', false); return; }
        if (Number.isNaN(pln) || pln <= 0) { toast('Podaj kwotę > 0', false); return; }
        if (plan === 'installments' && (Number.isNaN(inst) || inst < 2 || inst > 24)) {
            toast('Liczba rat: 2–24', false); return;
        }
        try {
            await callHr('advance_request', {
                employee_id: employeeId,
                amount_pln: pln,
                repayment_plan: plan,
                installments_count: plan === 'installments' ? inst : 0,
                reason: reason || null,
            });
            toast('Wniosek o zaliczkę złożony', true);
            openModal('hr-modal-advance', false);
            await refreshActiveTab();
        } catch (e) {
            toast(e.message || 'Błąd wniosku', false);
        }
    }

    // ---------------------------------------------------------------
    // Zdarzenie płacowe: premia / korekta / posiłek (modal wspólny)

    function updateLedgerHint() {
        const type = document.getElementById('hr-led-type')?.value;
        const hint = document.getElementById('hr-led-amount-hint');
        if (!hint) return;
        const now = new Date();
        const period = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
        hint.textContent = type === 'bonus' ? `— tylko dodatnia · księgowanie: ${period}`
            : type === 'adjustment' ? `— dodatnia lub ujemna (np. -50.00) · księgowanie: ${period}`
            : `— cena posiłku (potrącana z wypłaty) · księgowanie: ${period}`;
    }

    function openLedgerModal(emp) {
        document.getElementById('hr-led-employee-id').value = String(emp.id);
        const nameEl = document.getElementById('hr-led-employee-name');
        if (nameEl) nameEl.textContent = emp.display_name || '';
        document.getElementById('hr-led-type').value = 'bonus';
        document.getElementById('hr-led-amount').value = '';
        document.getElementById('hr-led-desc').value = '';
        updateLedgerHint();
        openModal('hr-modal-ledger', true);
    }

    async function submitLedger() {
        const employeeId = parseInt(document.getElementById('hr-led-employee-id').value, 10);
        const type = document.getElementById('hr-led-type').value;
        const pln = parseFloat(document.getElementById('hr-led-amount').value);
        const desc = document.getElementById('hr-led-desc').value.trim();

        if (Number.isNaN(pln)) { toast('Podaj kwotę', false); return; }
        if (type === 'bonus' && pln <= 0) { toast('Premia musi być > 0', false); return; }
        if (type === 'adjustment' && pln === 0) { toast('Korekta nie może być 0', false); return; }
        if (type === 'adjustment' && !desc) { toast('Korekta wymaga opisu (audit trail)', false); return; }
        if (type === 'meal' && pln <= 0) { toast('Cena posiłku musi być > 0', false); return; }

        const saveBtn = document.getElementById('hr-led-save');
        if (saveBtn) saveBtn.disabled = true;
        try {
            let msg = 'Zapisano w ledgerze';
            if (type === 'meal') {
                // Klucz idempotencji: retry (podwójny klik, timeout sieci) nie
                // zdubluje ani posiłku, ani potrącenia.
                await callHr('meal_record', {
                    auth: { employee_id: employeeId },
                    price_pln: pln,
                    description: desc || null,
                    idempotency_key: newIdempotencyKey(),
                });
            } else {
                const res = await callHr(type === 'bonus' ? 'bonus_add' : 'adjustment_add', {
                    employee_id: employeeId,
                    amount_pln: pln,
                    description: desc || null,
                });
                if (res && res.period_year && res.period_month) {
                    msg += ` (okres ${res.period_year}-${String(res.period_month).padStart(2, '0')})`;
                }
            }
            toast(msg, true);
            openModal('hr-modal-ledger', false);
            await refreshActiveTab();
        } catch (e) {
            toast(e.message || 'Błąd zapisu', false);
        } finally {
            if (saveBtn) saveBtn.disabled = false;
        }
    }

    function newIdempotencyKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        // Fallback dla starszych przeglądarek / kontekstów bez secure origin.
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            const v = c === 'x' ? r : ((r & 0x3) | 0x8);
            return v.toString(16);
        });
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
            await refreshActiveTab();
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
            await refreshActiveTab();
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
            await refreshActiveTab();
        } catch (e) {
            toast(e.message || 'Błąd stawki', false);
        }
    }

    function wire() {
        fillRoleSelects();
        showAuthBanner(!getToken());

        document.getElementById('hr-btn-refresh')?.addEventListener('click', refreshActiveTab);
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
        document.getElementById('hr-pr-close-period')?.addEventListener('click', closePeriod);

        document.getElementById('hr-form-employee')?.addEventListener('submit', submitEmployee);
        document.getElementById('hr-form-cancel')?.addEventListener('click', () => openModal('hr-modal-employee', false));

        document.getElementById('hr-f-create-login')?.addEventListener('change', (ev) => {
            document.getElementById('hr-create-login-fields')?.classList.toggle('hidden', !ev.target.checked);
        });

        document.getElementById('hr-pin-cancel')?.addEventListener('click', () => openModal('hr-modal-pin', false));
        document.getElementById('hr-pin-save')?.addEventListener('click', submitPin);

        document.getElementById('hr-rate-cancel')?.addEventListener('click', () => openModal('hr-modal-rate', false));
        document.getElementById('hr-rate-save')?.addEventListener('click', submitRate);

        document.getElementById('hr-adv-add')?.addEventListener('click', openAdvanceModal);
        document.getElementById('hr-adv-refresh')?.addEventListener('click', refreshAdvances);
        document.getElementById('hr-adv-cancel')?.addEventListener('click', () => openModal('hr-modal-advance', false));
        document.getElementById('hr-adv-save')?.addEventListener('click', submitAdvance);
        document.getElementById('hr-adv-plan')?.addEventListener('change', (ev) => {
            document.getElementById('hr-adv-inst-wrap')?.classList.toggle('hidden', ev.target.value !== 'installments');
        });
        document.querySelectorAll('#hr-adv-status-filter button').forEach((b) => {
            b.addEventListener('click', () => {
                _advStatusFilter = b.getAttribute('data-status') || '';
                document.querySelectorAll('#hr-adv-status-filter button')
                    .forEach((x) => x.classList.toggle('active', x === b));
                refreshAdvances();
            });
        });

        document.getElementById('hr-led-cancel')?.addEventListener('click', () => openModal('hr-modal-ledger', false));
        document.getElementById('hr-led-save')?.addEventListener('click', submitLedger);
        document.getElementById('hr-led-type')?.addEventListener('change', updateLedgerHint);

        ['hr-modal-employee', 'hr-modal-pin', 'hr-modal-rate', 'hr-modal-advance', 'hr-modal-ledger'].forEach((mid) => {
            document.getElementById(mid)?.addEventListener('click', (ev) => {
                if (ev.target.id === mid) openModal(mid, false);
            });
        });

        switchTab('employees');
        refreshList();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
})();
