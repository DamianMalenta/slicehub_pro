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
    let _searchQuery = '';
    let _drawerEmployee = null;
    let _drawerSubTab = 'sessions';
    let _drawerMonthOffset = 0;
    let _roleFilter = '';
    let _clockedInIds = new Set();
    let _clockElapsed = new Map();
    let _clockPollTimer = null;

    const ROLE_GROUPS = {
        kitchen: ['cook'],
        floor: ['waiter', 'runner', 'cashier'],
        delivery: ['driver'],
        mgmt: ['manager', 'owner', 'shift_lead'],
    };

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

    function initials(emp) {
        const fn = (emp.first_name || '').charAt(0).toUpperCase();
        const ln = (emp.last_name || '').charAt(0).toUpperCase();
        return (fn + ln) || '?';
    }

    function roleIcon(role) {
        const icons = {
            waiter: '🍽️', cook: '🔪', driver: '🛵', manager: '📋',
            cashier: '💳', cleaner: '🧹', runner: '🏃', shift_lead: '⏰',
            owner: '👑', team: '👥',
        };
        return icons[role] || '👤';
    }

    function statusDot(status) {
        const cls = status === 'active' ? 'hr-dot--active'
            : status === 'suspended' ? 'hr-dot--suspended'
            : status === 'terminated' ? 'hr-dot--terminated'
            : status === 'on_leave' ? 'hr-dot--on_leave' : 'hr-dot--active';
        return `<span class="hr-dot ${cls}"></span>`;
    }

    function renderCards() {
        const grid = document.getElementById('hr-emp-grid');
        if (!grid) return;
        let list = _employees;
        if (_roleFilter && ROLE_GROUPS[_roleFilter]) {
            list = list.filter(e => ROLE_GROUPS[_roleFilter].includes(e.primary_role));
        }
        if (_searchQuery) {
            const q = _searchQuery.toLowerCase();
            list = list.filter(e =>
                (e.display_name || '').toLowerCase().includes(q) ||
                (e.first_name || '').toLowerCase().includes(q) ||
                (e.last_name || '').toLowerCase().includes(q) ||
                (e.primary_role || '').toLowerCase().includes(q)
            );
        }
        if (!list.length) {
            grid.innerHTML = '<p style="color:#78716c;padding:1.5rem;">Brak pracowników.</p>';
            return;
        }
        grid.innerHTML = list.map((e) => {
            const role = esc(e.primary_role || '');
            const name = esc(e.display_name || `${e.first_name} ${e.last_name}`);
            const avatarClass = `hr-avatar hr-avatar--${e.primary_role || 'team'}`;
            const ledgerHrs = e.current_month_hours != null ? e.current_month_hours : 0;
            const liveSec = _clockElapsed.get(e.id) || 0;
            const liveHrs = liveSec > 0 ? liveSec / 3600 : 0;
            const hrs = ledgerHrs + liveHrs;
            const ledgerEarn = e.current_month_earnings != null ? e.current_month_earnings : 0;
            const rateMinor = e.current_rate_minor || 0;
            const liveEarn = Math.round(liveHrs * rateMinor);
            const earn = ledgerEarn + liveEarn;
            const targetHrs = 160;
            const pct = hrs > 0 ? Math.min(100, (hrs / targetHrs) * 100) : 0;
            const overClass = hrs > targetHrs ? ' hr-progress-fill--over' : '';
            const hrsDisplay = hrs > 0 ? `${hrs.toFixed(1)}h` : '—';
            const earnDisplay = earn > 0 ? plnFromMinor(earn) : '';
            const liveDot = _clockedInIds.has(e.id) ? '<span class="hr-live-dot"></span>' : '';
            return `<div class="hr-emp-card" data-id="${e.id}">
                ${liveDot}
                <div class="hr-emp-card-head">
                    <div class="${avatarClass}">${esc(initials(e))}</div>
                    <div style="min-width:0;">
                        <div class="hr-emp-card-name">${name}</div>
                        <div class="hr-emp-card-role">${roleIcon(e.primary_role)} ${role}</div>
                    </div>
                </div>
                <div class="hr-emp-card-stats">
                    <div class="hr-emp-card-hrs"><span>ten miesiąc</span> <strong>${hrsDisplay}</strong></div>
                    <div class="hr-progress"><div class="hr-progress-fill${overClass}" style="width:${pct}%"></div></div>
                    ${earnDisplay ? `<div class="hr-emp-card-earn">${earnDisplay}</div>` : ''}
                </div>
                <div class="hr-emp-card-status">${statusDot(e.status)} ${esc(e.status || '')}</div>
            </div>`;
        }).join('');

        grid.querySelectorAll('.hr-emp-card[data-id]').forEach((card) => {
            card.addEventListener('click', () => {
                const id = parseInt(card.getAttribute('data-id'), 10);
                const emp = _employees.find((x) => x.id === id);
                if (emp) openEmployeeDrawer(emp);
            });
        });
    }

    async function refreshClockStatus() {
        try {
            const data = await callHr('clock_status', {});
            const sessions = data.sessions || data.open_sessions || [];
            _clockedInIds = new Set(sessions.map(s => s.employee_id).filter(Boolean));
            _clockElapsed = new Map();
            sessions.forEach(s => {
                if (s.employee_id && s.elapsed_seconds != null) {
                    _clockElapsed.set(s.employee_id, s.elapsed_seconds);
                }
            });
            const counter = document.getElementById('hr-live-counter');
            const countEl = document.getElementById('hr-live-count');
            if (counter && countEl) {
                countEl.textContent = String(_clockedInIds.size);
                counter.classList.toggle('hidden', _clockedInIds.size === 0);
            }
            if (_activeTab === 'employees') renderCards();
        } catch (_) {
        }
    }

    function startClockPolling() {
        if (_clockPollTimer) clearInterval(_clockPollTimer);
        refreshClockStatus();
        _clockPollTimer = setInterval(refreshClockStatus, 15000);
    }

    function buildDrawerDetails(emp) {
        const rows = [];
        if (emp.phone) rows.push(`<div class="hr-dr-detail-row"><i class="fa-solid fa-phone"></i> ${esc(emp.phone)}</div>`);
        if (emp.email) rows.push(`<div class="hr-dr-detail-row"><i class="fa-solid fa-envelope"></i> ${esc(emp.email)}</div>`);
        if (emp.hire_date) rows.push(`<div class="hr-dr-detail-row"><i class="fa-solid fa-calendar"></i> zatr. ${esc(emp.hire_date).slice(0, 10)}</div>`);
        if (emp.employee_code) rows.push(`<div class="hr-dr-detail-row"><i class="fa-solid fa-id-badge"></i> <span class="hr-dr-detail-code">${esc(emp.employee_code)}</span></div>`);
        if (emp.account_username) rows.push(`<div class="hr-dr-detail-row"><i class="fa-solid fa-user"></i> ${esc(emp.account_username)} (${esc(emp.account_role || '')})</div>`);
        if (emp.notes) rows.push(`<div class="hr-dr-detail-notes">${esc(emp.notes)}</div>`);
        return rows.length ? `<div class="hr-dr-details">${rows.join('')}</div>` : '';
    }

    function advStepperHtml(status) {
        const steps = ['requested', 'approved', 'paid', 'settled'];
        const labels = ['wniosek', 'zatw.', 'wypł.', 'rozl.'];
        if (status === 'rejected' || status === 'void') {
            const idx = status === 'rejected' ? 0 : 2;
            return `<div class="hr-stepper">${steps.map((s, i) => {
                const dotCls = i < idx ? 'hr-step-dot--done' : i === idx ? (status === 'rejected' ? 'hr-step-dot--rejected' : 'hr-step-dot--void') : '';
                const lineCls = i < idx ? 'hr-step-line--done' : '';
                const lblCls = i < idx ? 'hr-step-label--done' : i === idx ? '' : '';
                return `<div class="hr-step"><div class="hr-step-dot ${dotCls}"></div>${i < steps.length - 1 ? `<div class="hr-step-line ${lineCls}"></div>` : ''}</div>`;
            }).join('')}<div class="hr-step-label ${status === 'rejected' ? '' : ''}" style="color:#ef4444;">${status === 'rejected' ? 'odrzucona' : 'wycofana'}</div></div>`;
        }
        const activeIdx = steps.indexOf(status);
        const lblCls = activeIdx >= 0 ? (activeIdx === 0 ? 'hr-step-label--active' : 'hr-step-label--done') : '';
        return `<div class="hr-stepper">${steps.map((s, i) => {
            const dotCls = i < activeIdx ? 'hr-step-dot--done' : i === activeIdx ? 'hr-step-dot--active' : '';
            const lineCls = i < activeIdx ? 'hr-step-line--done' : '';
            return `<div class="hr-step"><div class="hr-step-dot ${dotCls}"></div>${i < steps.length - 1 ? `<div class="hr-step-line ${lineCls}"></div>` : ''}</div>`;
        }).join('')}<div class="hr-step-label ${lblCls}">${esc(labels[activeIdx] || status)}</div></div>`;
    }

    async function refreshList() {
        showErrorBanner('');
        const inc = document.getElementById('hr-inc-del')?.checked;
        const skel = document.getElementById('hr-emp-skeleton');
        if (skel) skel.style.display = '';
        try {
            const data = await callHr('employees_list', { include_deleted: inc ? 1 : 0 });
            _employees = data.employees || [];
            if (skel) skel.style.display = 'none';
            renderCards();
            startClockPolling();
        } catch (e) {
            if (e.httpCode === 401 || e.httpCode === 403) {
                showAuthBanner(true);
            }
            showErrorBanner(e.message || 'Błąd listy');
            _employees = [];
            renderCards();
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

    function refreshActiveTab() {
        if (_activeTab === 'payroll') return refreshPayroll();
        if (_activeTab === 'advances') return refreshAdvances();
        return refreshList();
    }

    function plnFromMinor(minor) {
        return (Number(minor || 0) / 100).toFixed(2) + ' zł';
    }

    function fmtDt(s) {
        if (!s) return '—';
        return s.replace('T', ' ').slice(0, 16);
    }

    function renderPayroll(report) {
        const tb = document.getElementById('hr-pr-tbody');
        const tf = document.getElementById('hr-pr-tfoot');
        const picker = document.getElementById('hr-pr-period-picker');
        if (!tb || !tf) return;

        if (picker) {
            const p = payrollMonthFromOffset(_payrollPeriodOffset);
            const val = `${p.year}-${String(p.month).padStart(2, '0')}`;
            if (picker.value !== val) picker.value = val;
            picker.disabled = _payrollPeriodType !== 'month';
        }

        const rows = report?.employees || [];
        const t = report.totals || {};

        const statHrs = document.getElementById('hr-pr-stat-hrs');
        const statCost = document.getElementById('hr-pr-stat-cost');
        const statAdv = document.getElementById('hr-pr-stat-adv');
        if (statHrs) statHrs.textContent = esc(t.total_hours || '0');
        if (statCost) statCost.textContent = esc(t.total_labor_cost || '0 zł');
        if (statAdv) statAdv.textContent = esc(t.total_advances_repaid || '0 zł');

        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="7" style="color:#78716c;padding:1.5rem;">Brak danych wypłat w tym okresie.</td></tr>';
            tf.innerHTML = '';
            return;
        }

        tb.innerHTML = rows.map((e, i) => `<tr data-emp-idx="${i}" style="cursor:pointer;">
            <td>${esc(e.name)}</td>
            <td class="num">${esc(e.hours)}</td>
            <td class="num">${esc(e.rate)}</td>
            <td class="num">${esc(e.gross)}</td>
            <td class="num">${esc(e.meals)} / ${esc(e.deductions)}</td>
            <td class="num">${esc(e.advances_repaid)}</td>
            <td class="num">${esc(e.payout)}</td>
        </tr>`).join('');

        tf.innerHTML = `<tr>
            <td>Razem (${rows.length})</td>
            <td class="num">${esc(t.total_hours)}</td>
            <td class="num">—</td>
            <td class="num">${esc(t.total_labor_cost)}</td>
            <td class="num">${esc(t.total_deductions)}</td>
            <td class="num">${esc(t.total_advances_repaid)}</td>
            <td class="num">${esc(t.total_payout)}</td>
        </tr>`;

        tb.querySelectorAll('tr[data-emp-idx]').forEach((row) => {
            row.addEventListener('click', async () => {
                const idx = parseInt(row.getAttribute('data-emp-idx'), 10);
                const emp = rows[idx];
                if (!emp) return;
                const existing = row.nextElementSibling;
                if (existing && existing.classList.contains('hr-pr-expand')) {
                    existing.remove();
                    return;
                }
                const expandRow = document.createElement('tr');
                expandRow.className = 'hr-pr-expand';
                expandRow.innerHTML = '<td colspan="7"><div class="hr-pr-expand-inner"><p style="color:#78716c;font-size:0.75rem;">Ładowanie wpisów…</p></div></td>';
                row.after(expandRow);
                try {
                    const p = payrollMonthFromOffset(_payrollPeriodOffset);
                    const empId = parseInt(emp.employee_id, 10) || _employees.find(x => x.display_name === emp.name)?.id;
                    if (!empId) throw new Error('Nie znaleziono ID pracownika');
                    const data = await callHr('employee_ledger', {
                        employee_id: empId,
                        period_year: p.year,
                        period_month: p.month,
                    });
                    const entries = data.entries || [];
                    if (!entries.length) {
                        expandRow.querySelector('.hr-pr-expand-inner').innerHTML = '<p style="color:#78716c;font-size:0.75rem;">Brak wpisów.</p>';
                        return;
                    }
                    const maxAbs = Math.max(...entries.map(x => Math.abs(x.amount_minor || 0)), 1);
                    expandRow.querySelector('.hr-pr-expand-inner').innerHTML = entries.map(en => {
                        const amt = en.amount_minor || 0;
                        const isPos = amt >= 0;
                        const w = Math.max(3, (Math.abs(amt) / maxAbs) * 100);
                        const typeLabel = en.entry_type === 'work_earnings' ? 'zarobek'
                            : en.entry_type === 'bonus' ? 'premia'
                            : en.entry_type === 'adjustment' ? 'korekta'
                            : en.entry_type === 'meal_deduction' ? 'posiłek'
                            : en.entry_type === 'advance_payout' ? 'zaliczka'
                            : en.entry_type === 'advance_repaid' ? 'spłata'
                            : en.entry_type || '—';
                        return `<div class="hr-pr-expand-row">
                            <span class="hr-ledger-amount ${isPos ? 'hr-ledger-amount--pos' : 'hr-ledger-amount--neg'}">${isPos ? '+' : ''}${plnFromMinor(amt)}</span>
                            <div class="hr-ledger-bar ${isPos ? 'hr-ledger-bar--pos' : 'hr-ledger-bar--neg'}" style="width:${w}%"></div>
                            <span class="hr-ledger-type">${esc(typeLabel)} · ${esc(en.description || '')}</span>
                        </div>`;
                    }).join('');
                } catch (err) {
                    expandRow.querySelector('.hr-pr-expand-inner').innerHTML = `<p style="color:#fca5a5;font-size:0.75rem;">Błąd: ${esc(err.message)}</p>`;
                }
            });
        });
    }

    async function refreshPayroll() {
        showErrorBanner('');
        const tb = document.getElementById('hr-pr-tbody');
        const skel = document.getElementById('hr-pr-skeleton');
        if (tb && skel) tb.innerHTML = '<tr id="hr-pr-skeleton"><td colspan="7"><div class="hr-skeleton-row"></div><div class="hr-skeleton-row"></div><div class="hr-skeleton-row"></div></td></tr>';

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
        const labelEl = document.getElementById('hr-confirm-close-period-label');
        if (labelEl) labelEl.textContent = `Okres: ${label}`;
        openModal('hr-modal-confirm-close', true);
    }

    async function doClosePeriod() {
        const p = payrollMonthFromOffset(_payrollPeriodOffset);
        const label = `${p.year}-${String(p.month).padStart(2, '0')}`;
        openModal('hr-modal-confirm-close', false);
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
            const stepper = advStepperHtml(a.status);
            const expandable = a.repayment_plan === 'installments' && a.installments_count > 0;
            const expandCls = expandable ? ' hr-adv-row--expandable' : '';
            const expandIcon = expandable ? '<i class="fa-solid fa-chevron-down hr-adv-expand-icon"></i>' : '';
            return `<tr data-id="${a.id}" class="hr-adv-row${expandCls}">
                <td>${a.id}</td>
                <td>${esc(a.employee_name)}</td>
                <td class="num">${plnFromMinor(a.amount_minor)}</td>
                <td><span class="${statusTag}">${esc(ADV_STATUS_LABELS[a.status] || a.status)}</span></td>
                <td>${stepper}<div style="font-size:0.72rem;color:#78716c;">${esc(plan)} ${expandIcon}</div></td>
                <td>${esc(a.reason || a.rejection_reason || '—')}</td>
                <td class="hr-actions">${advActionsHtml(a)}</td>
            </tr>
            <tr class="hr-adv-expand" data-expand-for="${a.id}" style="display:none;">
                <td colspan="7"><div class="hr-adv-expand-inner"><p style="color:#78716c;padding:0.5rem;">Ładowanie…</p></div></td>
            </tr>`;
        }).join('');

        tb.querySelectorAll('tr[data-id]').forEach((row) => {
            row.querySelectorAll('button[data-adv]').forEach((btn) => {
                btn.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    advanceAction(parseInt(row.getAttribute('data-id'), 10), btn.getAttribute('data-adv'));
                });
            });
            if (row.classList.contains('hr-adv-row--expandable')) {
                row.style.cursor = 'pointer';
                row.addEventListener('click', () => {
                    const advId = parseInt(row.getAttribute('data-id'), 10);
                    const expandRow = tb.querySelector(`tr[data-expand-for="${advId}"]`);
                    if (!expandRow) return;
                    const isOpen = expandRow.style.display !== 'none';
                    if (isOpen) {
                        expandRow.style.display = 'none';
                        row.classList.remove('hr-adv-row--open');
                    } else {
                        expandRow.style.display = '';
                        row.classList.add('hr-adv-row--open');
                        loadAdvanceInstallments(advId, expandRow);
                    }
                });
            }
        });
    }

    async function loadAdvanceInstallments(advId, expandRow) {
        const inner = expandRow.querySelector('.hr-adv-expand-inner');
        if (!inner) return;
        inner.innerHTML = '<p style="color:#78716c;padding:0.5rem;">Ładowanie…</p>';
        try {
            const data = await callHr('advance_installments', { advance_id: advId });
            const insts = data.installments || [];
            if (!insts.length) {
                inner.innerHTML = '<p style="color:#78716c;padding:0.5rem;">Brak harmonogramu rat.</p>';
                return;
            }
            const statusClasses = {
                pending: 'hr-inst-status--pending',
                applied: 'hr-inst-status--applied',
                skipped: 'hr-inst-status--skipped',
                void: 'hr-inst-status--void',
            };
            const statusLabels = {
                pending: 'oczekuje',
                applied: 'zaksięgowana',
                skipped: 'pominięta',
                void: 'wycofana',
            };
            inner.innerHTML = `<table class="hr-inst-table"><thead><tr>
                <th>#</th><th>Kwota</th><th>Okres</th><th>Status</th><th>Aplikacja</th><th></th>
            </tr></thead><tbody>` + insts.map((i) => {
                const cls = statusClasses[i.status] || '';
                const lbl = statusLabels[i.status] || i.status;
                const period = `${i.scheduled_period_year}-${String(i.scheduled_period_month).padStart(2, '0')}`;
                const applied = i.applied_at ? (i.applied_at).replace('T', ' ').slice(0, 16) : '—';
                const repayBtn = i.status === 'pending'
                    ? `<button type="button" class="hr-inst-repay-btn" data-inst-id="${i.id}"><i class="fa-solid fa-check"></i> Spłać</button>`
                    : '';
                return `<tr>
                    <td>${i.seq_no}</td>
                    <td class="num">${plnFromMinor(i.amount_minor)}</td>
                    <td>${period}</td>
                    <td><span class="hr-inst-status ${cls}">${lbl}</span></td>
                    <td>${esc(applied)}</td>
                    <td>${repayBtn}</td>
                </tr>`;
            }).join('') + `</tbody></table>`;

            inner.querySelectorAll('.hr-inst-repay-btn').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const instId = parseInt(btn.getAttribute('data-inst-id'), 10);
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                    try {
                        await callHr('advance_installment_repay', { installment_id: instId });
                        toast('Rata spłacona', true);
                        loadAdvanceInstallments(advId, expandRow);
                        refreshAdvances();
                    } catch (e) {
                        toast(e.message || 'Błąd spłaty raty', false);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-check"></i> Spłać';
                    }
                });
            });
        } catch (e) {
            inner.innerHTML = `<p style="color:#fca5a5;padding:0.5rem;">Błąd: ${esc(e.message)}</p>`;
        }
    }

    async function refreshAdvances() {
        showErrorBanner('');
        const tb = document.getElementById('hr-adv-tbody');
        if (tb) tb.innerHTML = '<tr id="hr-adv-skeleton"><td colspan="7"><div class="hr-skeleton-row"></div><div class="hr-skeleton-row"></div></td></tr>';
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

    // ---------------------------------------------------------------
    // Employee drawer — slide-out panel z sesjami, księgowością, zaliczkami

    function drawerMonthDate() {
        const d = new Date();
        d.setDate(1);
        d.setMonth(d.getMonth() - _drawerMonthOffset);
        return { year: d.getFullYear(), month: d.getMonth() + 1 };
    }

    function updateDrawerMonthLabel() {
        const lbl = document.getElementById('hr-dr-month-label');
        if (!lbl) return;
        const { year, month } = drawerMonthDate();
        const names = ['styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];
        const isCurrent = _drawerMonthOffset === 0;
        lbl.textContent = `${names[month - 1]} ${year}${isCurrent ? ' (bieżący)' : ''}`;
        const nextBtn = document.getElementById('hr-dr-month-next');
        if (nextBtn) nextBtn.disabled = _drawerMonthOffset === 0;
    }

    function openEmployeeDrawer(emp) {
        _drawerEmployee = emp;
        _drawerMonthOffset = 0;
        const drawer = document.getElementById('hr-drawer');
        const overlay = document.getElementById('hr-drawer-overlay');
        if (!drawer || !overlay) return;

        const avatar = document.getElementById('hr-dr-avatar');
        if (avatar) {
            avatar.className = `hr-avatar hr-avatar--lg hr-avatar--${emp.primary_role || 'team'}`;
            avatar.textContent = initials(emp);
        }

        const nameEl = document.getElementById('hr-dr-name');
        if (nameEl) nameEl.textContent = emp.display_name || `${emp.first_name} ${emp.last_name}`;

        const metaEl = document.getElementById('hr-dr-meta');
        if (metaEl) {
            metaEl.innerHTML = `${roleIcon(emp.primary_role)} ${esc(emp.primary_role || '')} · ${statusDot(emp.status)} ${esc(emp.status || '')}`;
        }

        const detailsEl = document.getElementById('hr-dr-details');
        if (detailsEl) {
            detailsEl.innerHTML = buildDrawerDetails(emp);
        }

        const actionsEl = document.getElementById('hr-dr-actions');
        if (actionsEl) {
            actionsEl.innerHTML = `
                <button type="button" data-act="edit">Edytuj</button>
                <button type="button" data-act="pin">PIN</button>
                <button type="button" data-act="rate">Stawka</button>
                <button type="button" data-act="ledger">Płace</button>
            `;
            actionsEl.querySelectorAll('button[data-act]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const act = btn.getAttribute('data-act');
                    if (act === 'edit') openEmployeeModal(emp);
                    if (act === 'pin') openPinModal(emp.id);
                    if (act === 'rate') openRateModal(emp.id);
                    if (act === 'ledger') openLedgerModal(emp);
                });
            });
        }

        updateDrawerMonthLabel();
        loadDrawerStats(emp);

        _drawerSubTab = 'sessions';
        document.querySelectorAll('.hr-subtab').forEach((b) => {
            b.classList.toggle('active', b.getAttribute('data-sub') === 'sessions');
        });
        document.querySelectorAll('.hr-subpane').forEach((p) => {
            p.classList.toggle('active', p.id === 'hr-dr-pane-sessions');
        });

        drawer.classList.add('open');
        overlay.classList.add('open');

        loadDrawerSessions(emp.id);
        loadDrawerLedger(emp.id);
        loadDrawerAdvances(emp.id);
        loadDrawerRates(emp.id);
    }

    function loadDrawerStats(emp) {
        const statsEl = document.getElementById('hr-dr-stats');
        const progEl = document.getElementById('hr-dr-progress');
        const { year, month } = drawerMonthDate();

        if (statsEl) {
            statsEl.innerHTML = `
                <div class="hr-stat"><div class="hr-stat-value" id="hr-dr-stat-hrs">…</div><div class="hr-stat-label">Godziny</div></div>
                <div class="hr-stat"><div class="hr-stat-value" id="hr-dr-stat-earn">…</div><div class="hr-stat-label">Brutto</div></div>
                <div class="hr-stat"><div class="hr-stat-value" id="hr-dr-adv-total">—</div><div class="hr-stat-label">Zaliczki</div></div>
            `;
        }
        if (progEl) { progEl.style.width = '0%'; }

        const now = new Date();
        Promise.all([
            callHr('employee_ledger', { employee_id: emp.id, period_year: year, period_month: month }),
            callHr('employee_sessions', { employee_id: emp.id, period_year: year, period_month: month }),
        ]).then(([ledgerData, sessData]) => {
            const entries = ledgerData.entries || [];
            let hrs = 0, earn = 0;
            entries.forEach((en) => {
                if (en.entry_type === 'work_earnings') {
                    hrs += (en.hours || 0);
                    earn += (en.amount_minor || 0);
                }
            });

            const sessions = sessData.sessions || [];
            let liveHrs = 0;
            sessions.forEach((s) => {
                if (s.is_open) {
                    const start = new Date(s.start_time.replace(' ', 'T'));
                    const elapsed = (now - start) / 3600000;
                    if (elapsed > 0) liveHrs += elapsed;
                }
            });

            const totalHrs = hrs + liveHrs;
            const rateMinor = (emp.current_rate_minor || (sessions.find(s => s.is_open)?.rate_minor) || 0);
            const liveEarn = Math.round(liveHrs * rateMinor);
            const totalEarn = earn + liveEarn;

            const hrsEl = document.getElementById('hr-dr-stat-hrs');
            const earnEl = document.getElementById('hr-dr-stat-earn');
            if (hrsEl) hrsEl.textContent = `${totalHrs.toFixed(1)}h`;
            if (earnEl) earnEl.textContent = plnFromMinor(totalEarn);
            if (progEl) {
                const pct = Math.min(100, (totalHrs / 160) * 100);
                progEl.style.width = pct + '%';
                progEl.className = 'hr-progress-fill' + (totalHrs > 160 ? ' hr-progress-fill--over' : '');
            }
        }).catch(() => {});
    }

    function reloadDrawerMonth() {
        if (!_drawerEmployee) return;
        updateDrawerMonthLabel();
        loadDrawerStats(_drawerEmployee);
        loadDrawerSessions(_drawerEmployee.id);
        loadDrawerLedger(_drawerEmployee.id);
        loadDrawerAdvances(_drawerEmployee.id);
    }

    function closeEmployeeDrawer() {
        document.getElementById('hr-drawer')?.classList.remove('open');
        document.getElementById('hr-drawer-overlay')?.classList.remove('open');
        _drawerEmployee = null;
    }

    function switchDrawerSubTab(sub) {
        _drawerSubTab = sub;
        document.querySelectorAll('.hr-subtab').forEach((b) => {
            b.classList.toggle('active', b.getAttribute('data-sub') === sub);
        });
        document.querySelectorAll('.hr-subpane').forEach((p) => {
            p.classList.toggle('active', p.id === `hr-dr-pane-${sub}`);
        });
    }

    async function loadDrawerSessions(empId) {
        const container = document.getElementById('hr-dr-sessions');
        if (!container) return;
        container.innerHTML = '<p style="color:#78716c;padding:0.5rem;">Ładowanie…</p>';
        try {
            const { year, month } = drawerMonthDate();
            const data = await callHr('employee_sessions', {
                employee_id: empId,
                period_year: year,
                period_month: month,
            });
            const sessions = data.sessions || [];
            if (!sessions.length) {
                container.innerHTML = '<p style="color:#78716c;padding:0.5rem;">Brak sesji w tym miesiącu.</p>';
                return;
            }
            const sorted = sessions.sort((a, b) => (b.start_time || '').localeCompare(a.start_time || ''));
            container.innerHTML = sorted.map((s) => {
                let hrs;
                if (s.is_open) {
                    const start = new Date(s.start_time.replace(' ', 'T'));
                    hrs = ((new Date() - start) / 3600000).toFixed(1);
                } else {
                    hrs = s.total_hours != null ? s.total_hours.toFixed(1) : '—';
                }
                const startStr = fmtDt(s.start_time);
                const endStr = s.end_time ? fmtDt(s.end_time) : '—';
                const edited = s.adjusted ? ' hr-session-bar--edited' : '';
                const editBtn = !s.is_open
                    ? `<button type="button" class="hr-session-edit-btn" data-sid="${s.id}" data-eid="${empId}">Edytuj</button>`
                    : '<span class="hr-tag hr-tag--off" style="font-size:0.65rem;">otwarta</span>';
                return `<div class="hr-session-row">
                    <span class="hr-session-date">${startStr.slice(5, 10)}</span>
                    <div class="hr-session-bar${edited}">${startStr.slice(11)} → ${endStr.slice(11)}</div>
                    <span class="hr-session-hrs">${hrs}h ${editBtn}</span>
                </div>`;
            }).join('');
            container.querySelectorAll('button[data-sid]').forEach((btn) => {
                btn.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    const sid = parseInt(btn.getAttribute('data-sid'), 10);
                    const eid = parseInt(btn.getAttribute('data-eid'), 10);
                    const session = sessions.find(s => s.id === sid);
                    if (session) openSessionEditModal(session, eid);
                });
            });
        } catch (e) {
            container.innerHTML = `<p style="color:#fca5a5;padding:0.5rem;">Błąd: ${esc(e.message)}</p>`;
        }
    }

    async function loadDrawerLedger(empId) {
        const container = document.getElementById('hr-dr-ledger');
        if (!container) return;
        container.innerHTML = '<p style="color:#78716c;padding:0.5rem;">Ładowanie…</p>';
        try {
            const { year, month } = drawerMonthDate();
            const data = await callHr('employee_ledger', {
                employee_id: empId,
                period_year: year,
                period_month: month,
            });
            const entries = data.entries || [];
            if (!entries.length) {
                container.innerHTML = '<p style="color:#78716c;padding:0.5rem;">Brak wpisów w tym miesiącu.</p>';
                return;
            }
            const maxAbs = Math.max(...entries.map(x => Math.abs(x.amount_minor || 0)), 1);
            let saldo = 0;
            const html = entries.map((en) => {
                const amt = en.amount_minor || 0;
                saldo += amt;
                const isPos = amt >= 0;
                const w = Math.max(3, (Math.abs(amt) / maxAbs) * 100);
                const typeLabel = en.entry_type === 'work_earnings' ? 'zarobek'
                    : en.entry_type === 'bonus' ? 'premia'
                    : en.entry_type === 'adjustment' ? 'korekta'
                    : en.entry_type === 'meal_deduction' ? 'posiłek'
                    : en.entry_type === 'advance_payout' ? 'zaliczka'
                    : en.entry_type === 'advance_repaid' ? 'spłata'
                    : en.entry_type || '—';
                return `<div class="hr-ledger-row">
                    <span class="hr-ledger-amount ${isPos ? 'hr-ledger-amount--pos' : 'hr-ledger-amount--neg'}">${isPos ? '+' : ''}${plnFromMinor(amt)}</span>
                    <div class="hr-ledger-bar ${isPos ? 'hr-ledger-bar--pos' : 'hr-ledger-bar--neg'}" style="width:${w}%"></div>
                    <span class="hr-ledger-type">${esc(typeLabel)}${en.description ? ' · ' + esc(en.description) : ''}</span>
                </div>`;
            }).join('');
            const saldoClass = saldo >= 0 ? 'hr-ledger-amount--pos' : 'hr-ledger-amount--neg';
            container.innerHTML = html + `<div class="hr-ledger-saldo"><span>Saldo</span><span class="${saldoClass}">${saldo >= 0 ? '+' : ''}${plnFromMinor(saldo)}</span></div>`;
        } catch (e) {
            container.innerHTML = `<p style="color:#fca5a5;padding:0.5rem;">Błąd: ${esc(e.message)}</p>`;
        }
    }

    async function loadDrawerAdvances(empId) {
        const container = document.getElementById('hr-dr-advances');
        const totalEl = document.getElementById('hr-dr-adv-total');
        if (!container) return;
        container.innerHTML = '<p style="color:#78716c;padding:0.5rem;">Ładowanie…</p>';
        try {
            const data = await callHr('advances_list', { employee_id: empId });
            const empAdvances = data.advances || [];
            if (totalEl) {
                const total = empAdvances.reduce((sum, a) => sum + (a.amount_minor || 0), 0);
                totalEl.textContent = empAdvances.length ? plnFromMinor(total) : '0 zł';
            }
            if (!empAdvances.length) {
                container.innerHTML = '<p style="color:#78716c;padding:0.5rem;">Brak zaliczek.</p>';
                return;
            }
            container.innerHTML = empAdvances.map((a) => {
                const statusTag = a.status === 'settled' || a.status === 'paid'
                    ? 'hr-tag hr-tag--ok' : 'hr-tag';
                const plan = a.repayment_plan === 'installments'
                    ? `raty ${a.installments_paid}/${a.installments_count}` : 'jednorazowo';
                const repaidMinor = Math.round((a.amount_minor || 0) * (a.installments_paid || 0) / Math.max(1, a.installments_count || 1));
                const remainingMinor = (a.amount_minor || 0) - repaidMinor;
                const repaidInfo = repaidMinor > 0
                    ? `<div style="font-size:0.68rem;color:#78716c;margin-top:0.15rem;">spłacono ${plnFromMinor(repaidMinor)} · pozostało ${plnFromMinor(remainingMinor)}</div>`
                    : '';
                return `<div class="hr-ledger-row" style="grid-template-columns:30px 1fr auto;">
                    <span style="color:var(--hr-muted);">#${a.id}</span>
                    <span style="font-size:0.78rem;"><span class="${statusTag}">${esc(ADV_STATUS_LABELS[a.status] || a.status)}</span> ${esc(plan)}${repaidInfo}</span>
                    <span class="hr-ledger-amount hr-ledger-amount--pos">${plnFromMinor(a.amount_minor)}</span>
                </div>`;
            }).join('');
        } catch (e) {
            container.innerHTML = `<p style="color:#fca5a5;padding:0.5rem;">Błąd: ${esc(e.message)}</p>`;
        }
    }

    async function loadDrawerRates(empId) {
        const container = document.getElementById('hr-dr-rates');
        if (!container) return;
        container.innerHTML = '<p style="color:#78716c;padding:0.5rem;">Ładowanie…</p>';
        try {
            const data = await callHr('employee_rate_history', { employee_id: empId });
            const rates = data.rates || [];
            if (!rates.length) {
                container.innerHTML = '<p style="color:#78716c;padding:0.5rem;">Brak historii stawek.</p>';
                return;
            }
            const reasonIcons = {
                hiring: '🤝', raise: '📈', correction: '🔧',
                demotion: '📉', bulk_adjust: '⚖️', rehire: '🔄',
            };
            container.innerHTML = rates.map((r) => {
                const isCurrent = !r.effective_to;
                const from = (r.effective_from || '').slice(0, 10);
                const to = r.effective_to ? (r.effective_to).slice(0, 10) : 'obecnie';
                const icon = reasonIcons[r.reason] || '💰';
                const cls = isCurrent ? ' hr-rate-row--current' : '';
                const noteHtml = r.note ? `<div class="hr-rate-note">${esc(r.note)}</div>` : '';
                return `<div class="hr-rate-row${cls}">
                    <div class="hr-rate-row-head">
                        <span class="hr-rate-amount">${plnFromMinor(r.amount_minor)}</span>
                        <span class="hr-rate-period">${from} → ${to}</span>
                    </div>
                    <div class="hr-rate-reason">${icon} ${esc(r.reason || '—')}</div>
                    ${noteHtml}
                </div>`;
            }).join('');
        } catch (e) {
            container.innerHTML = `<p style="color:#fca5a5;padding:0.5rem;">Błąd: ${esc(e.message)}</p>`;
        }
    }

    function openSessionEditModal(session, employeeId) {
        const emp = _employees.find(e => e.id === employeeId);
        const nameEl = document.getElementById('hr-se-employee-name');
        if (nameEl) nameEl.textContent = emp ? emp.display_name : `Pracownik #${employeeId}`;
        document.getElementById('hr-se-session-id').value = String(session.id);
        // Convert to datetime-local format (YYYY-MM-DDTHH:MM)
        const startVal = (session.start_time || '').replace(' ', 'T').slice(0, 16);
        const endVal = session.end_time ? (session.end_time).replace(' ', 'T').slice(0, 16) : '';
        document.getElementById('hr-se-start').value = startVal;
        document.getElementById('hr-se-end').value = endVal;
        document.getElementById('hr-se-reason').value = '';
        openModal('hr-modal-session-edit', true);
    }

    async function submitSessionEdit() {
        const sessionId = parseInt(document.getElementById('hr-se-session-id').value, 10);
        const start = document.getElementById('hr-se-start').value.trim();
        const end = document.getElementById('hr-se-end').value.trim();
        const reason = document.getElementById('hr-se-reason').value.trim();

        if (!sessionId) { toast('Brak ID sesji', false); return; }
        if (!start) { toast('Start jest wymagany', false); return; }
        if (!reason) { toast('Powód korekty jest wymagany', false); return; }

        const saveBtn = document.getElementById('hr-se-save');
        if (saveBtn) saveBtn.disabled = true;
        try {
            const payload = {
                session_id: sessionId,
                start_time: start.replace('T', ' '),
                reason,
            };
            if (end) payload.end_time = end.replace('T', ' ');
            await callHr('session_edit', payload);
            toast('Sesja zaktualizowana', true);
            openModal('hr-modal-session-edit', false);
            if (_drawerEmployee) {
                await loadDrawerSessions(_drawerEmployee.id);
                await loadDrawerLedger(_drawerEmployee.id);
            }
        } catch (e) {
            toast(e.message || 'Błąd edycji sesji', false);
        } finally {
            if (saveBtn) saveBtn.disabled = false;
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
        document.getElementById('hr-pr-period-picker')?.addEventListener('change', (e) => {
            const val = e.target.value;
            if (!val) return;
            const [y, m] = val.split('-').map(Number);
            const now = new Date();
            now.setDate(1);
            const cur = new Date(now.getFullYear(), now.getMonth(), 1);
            const sel = new Date(y, m - 1, 1);
            const diff = (cur.getFullYear() - sel.getFullYear()) * 12 + (cur.getMonth() - sel.getMonth());
            if (diff >= 0) {
                _payrollPeriodOffset = diff;
                _payrollPeriodType = 'month';
                document.querySelectorAll('#hr-pr-period-type button').forEach((b) => {
                    b.classList.toggle('active', b.getAttribute('data-period') === 'month');
                });
                refreshPayroll();
            }
        });

        document.getElementById('hr-confirm-close-cancel')?.addEventListener('click', () => openModal('hr-modal-confirm-close', false));
        document.getElementById('hr-confirm-close-ok')?.addEventListener('click', doClosePeriod);
        document.getElementById('hr-modal-confirm-close')?.addEventListener('click', (ev) => {
            if (ev.target.id === 'hr-modal-confirm-close') openModal('hr-modal-confirm-close', false);
        });

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

        ['hr-modal-employee', 'hr-modal-pin', 'hr-modal-rate', 'hr-modal-advance', 'hr-modal-ledger', 'hr-modal-confirm-close'].forEach((mid) => {
            document.getElementById(mid)?.addEventListener('click', (ev) => {
                if (ev.target.id === mid) openModal(mid, false);
            });
        });

        // Drawer
        document.getElementById('hr-dr-close')?.addEventListener('click', closeEmployeeDrawer);
        document.getElementById('hr-drawer-overlay')?.addEventListener('click', closeEmployeeDrawer);
        document.getElementById('hr-dr-month-prev')?.addEventListener('click', () => {
            _drawerMonthOffset++;
            reloadDrawerMonth();
        });
        document.getElementById('hr-dr-month-next')?.addEventListener('click', () => {
            if (_drawerMonthOffset > 0) { _drawerMonthOffset--; reloadDrawerMonth(); }
        });
        document.querySelectorAll('.hr-subtab').forEach((b) => {
            b.addEventListener('click', () => switchDrawerSubTab(b.getAttribute('data-sub')));
        });

        // Search
        document.getElementById('hr-search')?.addEventListener('input', (e) => {
            _searchQuery = e.target.value.trim();
            renderCards();
        });

        // Role filter
        document.querySelectorAll('#hr-role-filter button').forEach((b) => {
            b.addEventListener('click', () => {
                _roleFilter = b.getAttribute('data-role') || '';
                document.querySelectorAll('#hr-role-filter button')
                    .forEach((x) => x.classList.toggle('active', x === b));
                renderCards();
            });
        });

        // Session edit modal
        document.getElementById('hr-se-cancel')?.addEventListener('click', () => openModal('hr-modal-session-edit', false));
        document.getElementById('hr-se-save')?.addEventListener('click', submitSessionEdit);
        document.getElementById('hr-modal-session-edit')?.addEventListener('click', (ev) => {
            if (ev.target.id === 'hr-modal-session-edit') openModal('hr-modal-session-edit', false);
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
