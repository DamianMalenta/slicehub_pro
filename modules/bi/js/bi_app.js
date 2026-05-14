/**
 * SliceHub BI — dashboard P&L (GET api/bi/dashboard_data.php).
 */
(function () {
    'use strict';

    const TOKEN_KEY = 'sh_token';

    function getApiBase() {
        const meta = document.querySelector('meta[name="sh-api-base"]');
        if (meta && meta.content) {
            const b = String(meta.content).trim().replace(/\/+$/, '');
            if (b) return b;
        }
        const path = window.location.pathname || '';
        const marker = '/modules/';
        const idx = path.indexOf(marker);
        if (idx > 0) return path.slice(0, idx) + '/api';
        if (idx === 0) return '/api';
        const m = path.match(/^\/([^/]+)(?:\/|$)/);
        if (m && m[1] && m[1] !== 'api') return '/' + m[1] + '/api';
        return '/slicehub/api';
    }

    function plnFromMinor(minor) {
        const n = Number(minor) || 0;
        return (n / 100).toLocaleString('pl-PL', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }) + '\u00a0zł';
    }

    function pctFromBp(bp) {
        if (bp === null || bp === undefined) return '—';
        const x = Number(bp) / 100;
        return x.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
    }

    function defaultRange() {
        const end = new Date();
        const start = new Date(end);
        start.setDate(start.getDate() - 30);
        return {
            start: start.toISOString().slice(0, 10),
            end: end.toISOString().slice(0, 10),
        };
    }

    function showErr(msg) {
        const el = document.getElementById('bi-error');
        if (!el) return;
        el.textContent = msg || '';
        el.classList.toggle('hidden', !msg);
    }

    function renderCards(d) {
        const net = d.net_sales_minor ?? 0;
        const prime = d.prime_cost_minor ?? 0;
        const opex = d.opex_minor ?? 0;
        const profit = d.operating_profit_minor ?? 0;

        const cards = [
            {
                title: 'Przychód netto',
                sub: 'Po VAT (nagłówki + linie)',
                value: plnFromMinor(net),
                valueClass: 'text-white',
                icon: 'fa-coins',
                accent: 'text-amber-300',
            },
            {
                title: 'Prime Cost',
                sub: 'COGS + praca · ' + pctFromBp(d.prime_cost_pct_bp) + ' przych. netto',
                value: plnFromMinor(prime),
                valueClass: 'text-white',
                icon: 'fa-pizza-slice',
                accent: 'text-orange-300',
            },
            {
                title: 'OPEX',
                sub: pctFromBp(d.opex_pct_net_sales_bp) + ' przych. netto',
                value: plnFromMinor(opex),
                valueClass: 'text-white',
                icon: 'fa-file-invoice-dollar',
                accent: 'text-violet-300',
            },
            {
                title: 'Zysk operacyjny',
                sub: 'Netto − COGS − praca − OPEX',
                value: plnFromMinor(profit),
                valueClass: profit < 0 ? 'alert-86' : 'text-emerald-300',
                icon: 'fa-scale-balanced',
                accent: profit < 0 ? 'text-red-400' : 'text-emerald-300',
            },
        ];

        const host = document.getElementById('bi-cards');
        if (!host) return;
        host.innerHTML = cards
            .map(
                (c) => `
            <div class="glass p-4 border-white/10">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <span class="text-xs uppercase tracking-wide text-gray-400">${c.title}</span>
                    <i class="fa-solid ${c.icon} ${c.accent} opacity-90"></i>
                </div>
                <div class="text-2xl font-semibold ${c.valueClass}">${c.value}</div>
                <div class="text-xs text-gray-500 mt-1">${c.sub}</div>
            </div>`
            )
            .join('');
    }

    function renderFlow(d) {
        const ul = document.getElementById('bi-flow');
        if (!ul) return;
        const steps = d.capital_flow || [];
        ul.innerHTML = steps
            .map((s) => {
                const balClass = s.balance_minor < 0 ? 'alert-86' : 'text-emerald-200';
                return `<li class="flex justify-between gap-4 border-b border-white/5 py-2">
                    <span class="text-gray-300">${s.label}</span>
                    <span class="text-right tabular-nums">
                        <span class="text-gray-500 mr-2">${plnFromMinor(s.delta_minor)}</span>
                        <span class="${balClass} font-medium">${plnFromMinor(s.balance_minor)}</span>
                    </span>
                </li>`;
            })
            .join('');
    }

    function renderOpex(d) {
        const tb = document.getElementById('bi-opex-body');
        if (!tb) return;
        const rows = d.opex_by_category || [];
        if (rows.length === 0) {
            tb.innerHTML =
                '<tr><td colspan="2" class="py-3 text-gray-500">Brak linii EXPENSE w zaakceptowanych fakturach w tym okresie.</td></tr>';
            return;
        }
        tb.innerHTML = rows
            .map(
                (r) => `<tr class="border-b border-white/5">
                <td class="py-2 pr-2 text-gray-200">${escapeHtml(r.category_name)}</td>
                <td class="py-2 text-right tabular-nums text-gray-100">${plnFromMinor(r.total_net_minor)}</td>
            </tr>`
            )
            .join('');
    }

    function escapeHtml(s) {
        const t = document.createElement('div');
        t.textContent = s;
        return t.innerHTML;
    }

    async function load() {
        const token = localStorage.getItem(TOKEN_KEY);
        if (!token) {
            document.getElementById('bi-auth-banner')?.classList.remove('hidden');
            showErr('');
            return;
        }
        document.getElementById('bi-auth-banner')?.classList.add('hidden');

        const start = document.getElementById('bi-start')?.value || '';
        const end = document.getElementById('bi-end')?.value || '';
        if (!start || !end) {
            showErr('Wybierz zakres dat.');
            return;
        }

        showErr('');
        const api = getApiBase();
        const url = `${api}/bi/dashboard_data.php?start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(end)}`;
        const res = await fetch(url, {
            headers: { Accept: 'application/json', Authorization: 'Bearer ' + token },
            credentials: 'same-origin',
        });
        const json = await res.json().catch(() => ({}));
        if (res.status === 401 || res.status === 403) {
            showErr(json.message || 'Brak uprawnień (wymagany owner / admin).');
            document.getElementById('bi-main')?.classList.add('hidden');
            return;
        }
        if (!json.success || !json.data) {
            showErr(json.message || 'Nie udało się wczytać danych.');
            document.getElementById('bi-main')?.classList.add('hidden');
            return;
        }

        const d = json.data;
        document.getElementById('bi-main')?.classList.remove('hidden');
        renderCards(d);
        renderFlow(d);
        renderOpex(d);
    }

    document.getElementById('bi-btn-load')?.addEventListener('click', load);

    const r = defaultRange();
    const sEl = document.getElementById('bi-start');
    const eEl = document.getElementById('bi-end');
    if (sEl) sEl.value = r.start;
    if (eEl) eEl.value = r.end;

    load();
})();
