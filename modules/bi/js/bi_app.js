/**
 * SliceHub BI P&L — JWT z localStorage (sh_token), kwoty PLN tylko w UI.
 */
(function () {
    'use strict';

    const TOKEN_KEY = 'sh_token';

    function getApiBase() {
        const path = window.location.pathname || '';
        const marker = '/modules/';
        const idx = path.indexOf(marker);
        if (idx > 0) return path.slice(0, idx) + '/api';
        if (idx === 0) return '/api';
        const m = path.match(/^\/([^/]+)(?:\/|$)/);
        if (m && m[1] && m[1] !== 'api') return '/' + m[1] + '/api';
        return '/slicehub/api';
    }

    function fmtPln(minor) {
        const n = Number(minor) || 0;
        return (n / 100).toLocaleString('pl-PL', { style: 'currency', currency: 'PLN', minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function pad2(n) {
        return String(n).padStart(2, '0');
    }

    function toYmd(d) {
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function setPreset(which) {
        const now = new Date();
        const start = document.getElementById('bi-start');
        const end = document.getElementById('bi-end');
        if (!start || !end) return;
        let s;
        let e = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        if (which === 'today') {
            s = new Date(e);
        } else if (which === 'yesterday') {
            e = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
            s = new Date(e);
        } else if (which === 'this_month') {
            s = new Date(now.getFullYear(), now.getMonth(), 1);
        } else if (which === 'last_30') {
            s = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 29);
        } else return;
        start.value = toYmd(s);
        end.value = toYmd(e);
    }

    function pct(part, gross) {
        if (!gross || gross <= 0) return 0;
        return Math.min(100, Math.max(0, (part / gross) * 100));
    }

    function renderWaterfall(am) {
        const el = document.getElementById('bi-waterfall');
        if (!el) return;
        const G = am.gross_revenue || 0;
        const vat = am.output_vat || 0;
        const net = am.net_revenue || 0;
        const cogs = am.cogs || 0;
        const labor = am.labor_cost || 0;
        const opex = am.opex_cost || 0;
        const comm = am.commissions || 0;
        const profit = am.operating_profit || 0;

        const rows = [
            { label: 'Przychód brutto', w: 100, color: 'bg-violet-500/70', amount: G, kind: 'base' },
            { label: 'VAT (w cenie sprzedaży)', w: pct(vat, G), color: 'bg-amber-500/60', amount: vat, deduct: true },
            { label: 'Przychód netto (po VAT)', w: pct(net, G), color: 'bg-emerald-600/50', amount: net },
            { label: 'COGS (WZ / magazyn)', w: pct(cogs, G), color: 'bg-orange-600/55', amount: cogs, deduct: true },
            { label: 'Koszt pracy', w: pct(labor, G), color: 'bg-sky-600/50', amount: labor, deduct: true },
            { label: 'OPEX (faktury EXPENSE)', w: pct(opex, G), color: 'bg-rose-600/50', amount: opex, deduct: true },
            { label: 'Prowizje', w: pct(comm, G), color: 'bg-fuchsia-600/45', amount: comm, deduct: true },
            { label: 'Zysk operacyjny', w: pct(Math.abs(profit), G), color: profit > 0 ? 'bg-lime-600/50' : 'bg-red-600/60', amount: profit },
        ];

        el.innerHTML = rows.map((r) => {
            const border = r.deduct ? 'border-l-2 border-white/20' : '';
            const note = r.deduct ? '<span class="text-[10px] text-gray-500 ml-2">odjęcie</span>' : '';
            return (
                '<div class="flex flex-col gap-1 ' + border + ' pl-2">' +
                '<div class="flex justify-between text-xs"><span class="text-gray-400">' + r.label + note + '</span>' +
                '<span class="font-mono text-gray-200">' + fmtPln(r.amount) + '</span></div>' +
                '<div class="h-3 rounded-full bg-black/40 overflow-hidden border border-white/5">' +
                '<div class="h-full rounded-full ' + r.color + ' transition-all duration-500" style="width:' + r.w.toFixed(2) + '%"></div>' +
                '</div></div>'
            );
        }).join('');
    }

    function renderOpexTable(list) {
        const el = document.getElementById('bi-opex-table');
        if (!el) return;
        if (!list || list.length === 0) {
            el.innerHTML = '<p class="text-gray-500">Brak linii OPEX w okresie.</p>';
            return;
        }
        const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        const rows = list.map((c) => (
            '<div class="flex justify-between py-2 border-b border-white/5">' +
            '<span class="text-gray-300">' + esc(c.category_name) + '</span>' +
            '<span class="font-mono text-gray-200">' + fmtPln(c.total_minor) + '</span></div>'
        )).join('');
        el.innerHTML = rows;
    }

    function setKpi(am, primePct) {
        const gross = document.getElementById('kpi-gross');
        const prime = document.getElementById('kpi-prime');
        const opex = document.getElementById('kpi-opex');
        const profit = document.getElementById('kpi-profit');
        if (gross) gross.textContent = fmtPln(am.gross_revenue);
        if (prime) {
            prime.textContent = primePct.toFixed(2) + '%';
            prime.className = 'text-2xl font-bold tabular-nums ' + (primePct > 65 ? 'text-red-500 font-bold' : 'text-white');
        }
        if (opex) opex.textContent = fmtPln(am.opex_cost);
        if (profit) {
            const p = am.operating_profit;
            profit.textContent = fmtPln(p);
            profit.className = 'text-2xl font-bold tabular-nums ' + (p <= 0 ? 'text-red-500 font-bold' : 'text-emerald-400');
        }
    }

    async function loadData() {
        const errBanner = document.getElementById('bi-error-banner');
        const authBanner = document.getElementById('bi-auth-banner');
        if (authBanner) authBanner.classList.add('hidden');
        if (errBanner) { errBanner.classList.add('hidden'); errBanner.textContent = ''; }

        const token = localStorage.getItem(TOKEN_KEY);
        if (!token) {
            if (authBanner) authBanner.classList.remove('hidden');
            return;
        }

        const start = document.getElementById('bi-start')?.value;
        const end = document.getElementById('bi-end')?.value;
        if (!start || !end) {
            if (errBanner) { errBanner.textContent = 'Uzupełnij zakres dat.'; errBanner.classList.remove('hidden'); }
            return;
        }

        const url = getApiBase() + '/bi/dashboard_data.php?' + new URLSearchParams({ start_date: start, end_date: end });
        let res;
        try {
            res = await fetch(url, {
                headers: { Accept: 'application/json', Authorization: 'Bearer ' + token },
                credentials: 'same-origin',
            });
        } catch (e) {
            if (errBanner) { errBanner.textContent = 'Błąd sieci: ' + (e.message || ''); errBanner.classList.remove('hidden'); }
            return;
        }

        const json = await res.json().catch(() => ({}));
        if (res.status === 401 || res.status === 403) {
            if (errBanner) {
                errBanner.textContent = json.message || ('HTTP ' + res.status + ' — brak uprawnień.');
                errBanner.classList.remove('hidden');
            }
            return;
        }
        if (!res.ok || json.success !== true || !json.data) {
            if (errBanner) {
                errBanner.textContent = json.message || ('HTTP ' + res.status);
                errBanner.classList.remove('hidden');
            }
            return;
        }

        const d = json.data;
        const am = d.amounts_minor || {};
        setKpi(am, Number(d.prime_cost_pct) || 0);
        renderWaterfall(am);
        renderOpexTable(d.opex_by_category);
    }

    document.addEventListener('DOMContentLoaded', () => {
        setPreset('this_month');
        document.querySelectorAll('.bi-preset').forEach((btn) => {
            btn.addEventListener('click', () => setPreset(btn.getAttribute('data-preset')));
        });
        document.getElementById('bi-btn-load')?.addEventListener('click', () => { void loadData(); });
        void loadData();
    });
})();
