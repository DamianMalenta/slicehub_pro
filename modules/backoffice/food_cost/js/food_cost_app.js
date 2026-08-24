/**
 * SliceHub — Food Cost Report (frontend)
 * Vanilla JS, brak frameworków per Konstytucja §3.
 *
 * Auth: token JWT z localStorage['sh_token'] (zapisywany przy loginie w Hub).
 * Pickera zasilają istniejące endpointy (Snajper — bez nowych backendów):
 *   - magazyny:  GET  api/warehouse/warehouse_list.php
 *   - menu:      POST api/backoffice/api_menu_studio.php  action=get_menu_tree
 * Raport:
 *   - GET api/reports/food_cost.php?item_sku=...&warehouse_id=...
 *   (FoodCostEngine: AVCO, składniki, modyfikatory, marża per kanał)
 *
 * Prawo IV (Zero Zaufania): raport jest read-only; ceny/koszt czytane z serwera.
 */
(function () {
    'use strict';

    function apiUrl(path) {
        if (typeof globalThis !== 'undefined' && globalThis.SliceHub && globalThis.SliceHub.apiUrl) {
            return globalThis.SliceHub.apiUrl(path);
        }
        const base = (globalThis.SliceHub && globalThis.SliceHub.getApiBase)
            ? globalThis.SliceHub.getApiBase()
            : ((globalThis.SliceHub && globalThis.SliceHub.getApiFallback) ? globalThis.SliceHub.getApiFallback() : '/api');
        const p = String(path || '').trim();
        if (!p) return base;
        return base + (p.startsWith('/') ? p : '/' + p);
    }

    const MENU_ENDPOINT = apiUrl('/backoffice/api_menu_studio.php');
    const WAREHOUSE_ENDPOINT = apiUrl('/warehouse/warehouse_list.php');
    const FOOD_COST_ENDPOINT = apiUrl('/reports/food_cost.php');
    const $ = (sel) => document.querySelector(sel);

    let state = {
        warehouses: [],
        items: [],        // [{asciiKey, name, categoryId, isActive}]
        categories: [],   // [{id, name}]
    };

    // -------------------------------------------------------------------------
    // API helpers
    // -------------------------------------------------------------------------
    function getToken() {
        return localStorage.getItem('sh_token') || '';
    }

    function authHeaders(json) {
        const h = {};
        const tok = getToken();
        if (tok) h['Authorization'] = 'Bearer ' + tok;
        if (json) h['Content-Type'] = 'application/json';
        return h;
    }

    async function getJson(url) {
        const tok = getToken();
        if (!tok) return { success: false, message: 'Brak tokenu — zaloguj się w Hub i wróć.', code: 'NO_TOKEN' };
        let res;
        try {
            res = await fetch(url, { method: 'GET', headers: authHeaders(false) });
        } catch (netErr) {
            return { success: false, message: 'Błąd sieci: ' + (netErr.message || netErr), code: 'NETWORK' };
        }
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch (e) {
            return { success: false, message: 'Serwer zwrócił nie-JSON (HTTP ' + res.status + ').', code: 'NON_JSON', raw: text.slice(0, 300) };
        }
        return json;
    }

    async function postJson(url, body) {
        const tok = getToken();
        if (!tok) return { success: false, message: 'Brak tokenu — zaloguj się w Hub i wróć.', code: 'NO_TOKEN' };
        let res;
        try {
            res = await fetch(url, {
                method: 'POST',
                headers: authHeaders(true),
                body: JSON.stringify(body),
            });
        } catch (netErr) {
            return { success: false, message: 'Błąd sieci: ' + (netErr.message || netErr), code: 'NETWORK' };
        }
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch (e) {
            return { success: false, message: 'Serwer zwrócił nie-JSON (HTTP ' + res.status + ').', code: 'NON_JSON', raw: text.slice(0, 300) };
        }
        return json;
    }

    // -------------------------------------------------------------------------
    // UI helpers
    // -------------------------------------------------------------------------
    function _esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function showBanner(id, html, show) {
        const el = $(id);
        if (!el) return;
        if (html) el.innerHTML = html;
        el.classList.toggle('hidden', !show);
    }

    function fmtMoney(s) {
        const n = Number(s);
        if (!Number.isFinite(n)) return '—';
        return n.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function fmtPct(s) {
        const n = Number(s);
        if (!Number.isFinite(n)) return '—';
        return n.toFixed(1) + '%';
    }

    // -------------------------------------------------------------------------
    // Boot: auth check + load pickers
    // -------------------------------------------------------------------------
    async function init() {
        if (!getToken()) {
            showBanner('#fc-auth-banner', null, true);
            return;
        }

        const refreshBtn = $('#fc-btn-refresh');
        if (refreshBtn) refreshBtn.addEventListener('click', () => loadAll());

        const computeBtn = $('#fc-btn-compute');
        if (computeBtn) computeBtn.addEventListener('click', onCompute);

        const itemSel = $('#fc-item');
        if (itemSel) itemSel.addEventListener('change', updateComputeEnabled);

        const whSel = $('#fc-warehouse');
        if (whSel) whSel.addEventListener('change', updateComputeEnabled);

        await loadAll();
    }

    function updateComputeEnabled() {
        const wh = $('#fc-warehouse').value;
        const it = $('#fc-item').value;
        const btn = $('#fc-btn-compute');
        btn.disabled = !(wh && it);
    }

    async function loadAll() {
        showBanner('#fc-error-banner', null, false);
        await Promise.all([loadWarehouses(), loadMenu()]);
        updateComputeEnabled();
    }

    async function loadWarehouses() {
        const sel = $('#fc-warehouse');
        const r = await getJson(WAREHOUSE_ENDPOINT);
        if (!r.success) {
            sel.innerHTML = '<option value="">— błąd wczytywania —</option>';
            showBanner('#fc-error-banner', 'Magazyny: ' + _esc(r.message || 'błąd'), true);
            return;
        }
        const rows = Array.isArray(r.data) ? r.data : [];
        state.warehouses = rows;
        if (rows.length === 0) {
            sel.innerHTML = '<option value="">— brak magazynów —</option>';
            return;
        }
        sel.innerHTML = rows.map(w => {
            const id = _esc(w.warehouse_id);
            const cnt = Number(w.item_count) || 0;
            const val = fmtMoney(w.total_value);
            return `<option value="${id}">${id} · ${cnt} SKU · ${val} zł</option>`;
        }).join('');
    }

    async function loadMenu() {
        const sel = $('#fc-item');
        const r = await postJson(MENU_ENDPOINT, { action: 'get_menu_tree' });
        if (!r.success) {
            sel.innerHTML = '<option value="">— błąd wczytywania —</option>';
            showBanner('#fc-error-banner', 'Menu: ' + _esc(r.message || 'błąd'), true);
            return;
        }
        const data = r.data || {};
        state.categories = Array.isArray(data.categories) ? data.categories : [];
        const items = Array.isArray(data.items) ? data.items : [];
        state.items = items.filter(it => it && it.asciiKey);

        const catName = (cid) => {
            const c = state.categories.find(c => c.id === cid);
            return c ? c.name : 'Inne';
        };
        // Grupuj po kategorii (optgroup)
        const byCat = new Map();
        for (const it of state.items) {
            const cn = catName(it.categoryId);
            if (!byCat.has(cn)) byCat.set(cn, []);
            byCat.get(cn).push(it);
        }
        const parts = ['<option value="">— wybierz danie —</option>'];
        for (const [cn, list] of byCat) {
            const opts = list.map(it =>
                `<option value="${_esc(it.asciiKey)}">${_esc(it.name)}${it.isActive ? '' : ' (nieaktywne)'}</option>`
            ).join('');
            parts.push(`<optgroup label="${_esc(cn)}">${opts}</optgroup>`);
        }
        sel.innerHTML = parts.join('');
    }

    // -------------------------------------------------------------------------
    // Compute + render report
    // -------------------------------------------------------------------------
    async function onCompute() {
        const warehouseId = $('#fc-warehouse').value;
        const itemSku = $('#fc-item').value;
        if (!warehouseId || !itemSku) return;

        const report = $('#fc-report');
        report.classList.remove('hidden');
        report.innerHTML = '<div class="fc-loading"><i class="fa-solid fa-spinner fa-spin"></i> Obliczanie kosztu…</div>';
        showBanner('#fc-error-banner', null, false);

        const url = FOOD_COST_ENDPOINT + '?item_sku=' + encodeURIComponent(itemSku)
            + '&warehouse_id=' + encodeURIComponent(warehouseId);
        const r = await getJson(url);

        if (!r.success) {
            report.innerHTML = '';
            showBanner('#fc-error-banner', _esc(r.message || 'Nie udało się pobrać raportu.'), true);
            return;
        }

        renderReport(r.data || {});
    }

    function renderReport(data) {
        const report = $('#fc-report');
        const a = (data.food_cost_analysis) || {};

        const recipeCost = fmtMoney(a.recipe_cost);
        const wasteCost = fmtMoney(a.waste_cost);
        const totalCost = fmtMoney(a.total_food_cost);
        const lastAvco = a.last_avco_update ? _esc(a.last_avco_update) : '—';
        const missing = !!a.missing_avco_warning;

        const warnHtml = missing
            ? `<div class="fc-warn"><i class="fa-solid fa-triangle-exclamation"></i>
               Brak ceny AVCO dla co najmniej jednego składnika/modyfikatora — koszt teoretyczny może być zaniżony (0,00 zł zamiast rzeczywistej ceny).</div>`
            : '';

        const ingredients = Array.isArray(a.ingredients) ? a.ingredients : [];
        const modifiers = Array.isArray(a.modifiers) ? a.modifiers : [];
        const channels = Array.isArray(a.channels) ? a.channels : [];

        const ingRows = ingredients.map(ing => {
            const pack = ing.is_packaging ? '<span class="fc-tag fc-tag--pack">opakowanie</span>' : '';
            const miss = ing.avco_missing ? '<span class="fc-tag fc-tag--missing">brak AVCO</span>' : '';
            return `<tr>
                <td><code>${_esc(ing.warehouse_sku)}</code></td>
                <td class="num">${ing.quantity}</td>
                <td class="num">${fmtMoney(ing.avco)}</td>
                <td class="num">${Number(ing.waste_percent).toFixed(0)}%</td>
                <td class="num">${fmtMoney(ing.base_cost)}</td>
                <td class="num">${fmtMoney(ing.waste_cost)}</td>
                <td class="num"><strong>${fmtMoney(ing.total_cost)}</strong></td>
                <td>${pack}${miss}</td>
            </tr>`;
        }).join('');

        const modRows = modifiers.length ? modifiers.map(m => {
            const prices = m.selling_prices || {};
            const priceStr = Object.entries(prices).map(([ch, p]) =>
                `<span class="fc-tag">${_esc(ch)}: ${fmtMoney(p)} zł</span>`).join(' ');
            const def = m.is_default ? '<span class="fc-tag fc-tag--default">domyślny</span>' : '';
            const miss = m.avco_missing ? '<span class="fc-tag fc-tag--missing">brak AVCO</span>' : '';
            const linked = m.linked_warehouse_sku ? `<code>${_esc(m.linked_warehouse_sku)}</code>` : '<span class="fc-info-line">—</span>';
            return `<tr>
                <td><code>${_esc(m.modifier_sku)}</code></td>
                <td>${_esc(m.name)}</td>
                <td>${linked}</td>
                <td class="num">${m.linked_quantity}</td>
                <td class="num">${fmtMoney(m.food_cost)}</td>
                <td>${def}${miss}</td>
                <td>${priceStr || '<span class="fc-info-line">—</span>'}</td>
            </tr>`;
        }).join('') : '<tr><td colspan="7" class="fc-info-line">Brak modyfikatorów powiązanych z tym daniem.</td></tr>';

        const chRows = channels.length ? channels.map(c => {
            const cls = 'fc-status--' + (c.status || 'critical');
            return `<tr>
                <td><strong>${_esc(c.channel)}</strong></td>
                <td class="num">${fmtMoney(c.price)}</td>
                <td class="num">${fmtPct(c.food_cost_pct)}</td>
                <td class="num"><strong>${fmtPct(c.margin_pct)}</strong></td>
                <td><span class="fc-status ${cls}">${_esc(c.status)}</span></td>
            </tr>`;
        }).join('') : '<tr><td colspan="5" class="fc-info-line">Brak cen (sh_price_tiers) dla tego dania — ustaw ceny per kanał w Menu Studio.</td></tr>';

        report.innerHTML = `
            <div class="fc-summary">
                <div class="fc-stat">
                    <div class="fc-stat-label">Koszt receptury</div>
                    <div class="fc-stat-value zl">${recipeCost}</div>
                </div>
                <div class="fc-stat">
                    <div class="fc-stat-label">Koszt marnotrawstwa</div>
                    <div class="fc-stat-value zl">${wasteCost}</div>
                </div>
                <div class="fc-stat">
                    <div class="fc-stat-label">Całkowity Food Cost</div>
                    <div class="fc-stat-value zl">${totalCost}</div>
                </div>
                <div class="fc-stat">
                    <div class="fc-stat-label">Ostatnia aktualizacja AVCO</div>
                    <div class="fc-stat-value" style="font-size:14px">${lastAvco}</div>
                </div>
            </div>
            ${warnHtml}
            <div class="fc-card">
                <h3 class="fc-section-title"><i class="fa-solid fa-list"></i> Składniki (receptura)</h3>
                <table class="fc-table">
                    <thead><tr>
                        <th>SKU magazynu</th><th class="num">Ilość</th><th class="num">AVCO/j</th>
                        <th class="num">Waste</th><th class="num">Koszt bazowy</th>
                        <th class="num">Koszt waste</th><th class="num">Razem</th><th>Tagi</th>
                    </tr></thead>
                    <tbody>${ingRows || '<tr><td colspan="8" class="fc-info-line">Brak składników.</td></tr>'}</tbody>
                </table>
            </div>
            <div class="fc-card">
                <h3 class="fc-section-title"><i class="fa-solid fa-sliders"></i> Modyfikatory</h3>
                <table class="fc-table">
                    <thead><tr>
                        <th>SKU</th><th>Nazwa</th><th>Linked SKU</th><th class="num">Ilość</th>
                        <th class="num">Food cost</th><th>Tagi</th><th>Ceny per kanał</th>
                    </tr></thead>
                    <tbody>${modRows}</tbody>
                </table>
            </div>
            <div class="fc-card">
                <h3 class="fc-section-title"><i class="fa-solid fa-chart-line"></i> Marża per kanał</h3>
                <table class="fc-table">
                    <thead><tr>
                        <th>Kanał</th><th class="num">Cena sprzedaży</th>
                        <th class="num">Food cost %</th><th class="num">Marża %</th><th>Status</th>
                    </tr></thead>
                    <tbody>${chRows}</tbody>
                </table>
            </div>
        `;
    }

    document.addEventListener('DOMContentLoaded', init);
})();
