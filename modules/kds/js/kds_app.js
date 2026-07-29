/**
 * SLICEHUB KDS — Kitchen Display System
 * Polls active orders, renders tickets with driver action warnings.
 */
const KdsApp = (() => {
    function apiUrl(path) {
        if (typeof window !== 'undefined' && window.SliceHub && window.SliceHub.apiUrl) {
            return window.SliceHub.apiUrl(path);
        }
        const base = (window.SliceHub && window.SliceHub.getApiBase)
            ? window.SliceHub.getApiBase()
            : ((window.SliceHub && window.SliceHub.getApiFallback) ? window.SliceHub.getApiFallback() : '/api');
        const p = String(path || '').trim();
        if (!p) return base;
        return base + (p.startsWith('/') ? p : '/' + p);
    }
    const ENDPOINT = apiUrl('/kds/engine.php');
    const POLL_INTERVAL = 6000;
    const LS_STATION = 'slicehub_kds_station';
    let _timer = null;
    /** @type {string} pusty = wszystkie stacje */
    let _stationFilter = '';
    // Phase A — SLA thresholds z get_board (sh_tenant_settings). Default 10/5.
    let _slaThresholds = { green_min: 10, yellow_min: 5 };

    const ACTION_LABELS = {
        pack_cold:     '❄️ ZIMNE — OSOBNO',
        pack_separate: '⚠️ OSOBNO / KIEROWCA',
        check_id:      '🔞 SPRAWDŹ WIEK',
    };

    const TYPE_LABELS = { dine_in: 'SALA', takeaway: 'WYNOS', delivery: 'DOSTAWA' };

    // Canonical status flow: new → accepted → preparing → ready.
    // Each status has a next bump action + button label/class.
    const BUMP_CONFIG = {
        new:       { next: 'accepted',  label: '📥 AKCEPTUJ',  cls: 'bump-accept'  },
        accepted:  { next: 'preparing', label: '🔥 ROZPOCZNIJ', cls: 'bump-start'   },
        preparing: { next: 'ready',     label: '✅ GOTOWE',     cls: 'bump-done'    },
    };

    const SOURCE_BADGES = {
        WWW: { label: 'WEB',   color: '#3b82f6' },
        POS: { label: 'POS',   color: '#94a3b8' },
        KIO: { label: 'KIOSK', color: '#a855f7' },
        AGG: { label: 'AGREGATOR', color: '#f97316' },
    };

    async function _post(action, data = {}) {
        const headers = { 'Content-Type': 'application/json' };
        const token = localStorage.getItem('sh_token') || '';
        if (token) headers['Authorization'] = 'Bearer ' + token;
        try {
            const res = await fetch(ENDPOINT, {
                method: 'POST',
                headers,
                body: JSON.stringify({ action, ...data }),
            });
            if (res.status === 401 || res.status === 403) {
                _handleUnauthorized();
                return { success: false, message: 'Sesja wygasła — zaloguj się w POS/Tables.' };
            }
            return await res.json();
        } catch { return { success: false }; }
    }

    function _handleUnauthorized() {
        if (_timer) { clearInterval(_timer); _timer = null; }
        const board = document.getElementById('kds-board');
        if (board) {
            board.innerHTML = `
                <div class="kds-empty">
                    <i class="fa-solid fa-lock"></i>
                    <h3>Brak autoryzacji</h3>
                    <p style="color:#94a3b8;margin-top:8px;font-size:14px">
                        KDS wymaga zalogowanej sesji. Zaloguj się w module POS lub Stoliki.
                    </p>
                </div>`;
        }
    }

    function _esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

    function _timerInfo(promisedTime) {
        if (!promisedTime) return { text: 'ASAP', cls: 'ok' };
        const diff = Math.floor((new Date(promisedTime) - new Date()) / 60000);
        if (diff < 0) return { text: `Spóźnione ${Math.abs(diff)}m`, cls: 'urgent' };
        if (diff <= _slaThresholds.yellow_min) return { text: `${diff} min`, cls: 'warning' };
        return { text: `${diff} min`, cls: 'ok' };
    }

    function _initStationFilter() {
        const params = new URLSearchParams(window.location.search || '');
        const q = (params.get('station') || '').trim();
        if (q) {
            _stationFilter = q;
            try { localStorage.setItem(LS_STATION, q); } catch (_) {}
            return;
        }
        try {
            const s = (localStorage.getItem(LS_STATION) || '').trim();
            _stationFilter = s;
        } catch (_) {
            _stationFilter = '';
        }
    }

    function _syncStationSelect(stations) {
        const sel = document.getElementById('kds-station-select');
        if (!sel) return;
        const list = Array.isArray(stations) ? stations.slice() : [];
        const cur = _stationFilter;
        sel.replaceChildren();
        const optAll = document.createElement('option');
        optAll.value = '';
        optAll.textContent = 'Wszystkie stacje';
        sel.appendChild(optAll);
        list.forEach((id) => {
            const o = document.createElement('option');
            o.value = String(id);
            o.textContent = String(id);
            sel.appendChild(o);
        });
        if (cur && !list.includes(cur)) {
            const o = document.createElement('option');
            o.value = cur;
            o.textContent = cur + ' (aktywny)';
            sel.appendChild(o);
        }
        sel.value = cur && [...sel.options].some((op) => op.value === cur) ? cur : '';
    }

    async function refresh() {
        const r = await _post('get_board', _stationFilter ? { station: _stationFilter } : {});
        if (!r.success) return;
        const data = r.data || {};
        if (data.sla_thresholds) {
            const t = data.sla_thresholds;
            _slaThresholds = {
                green_min:  Number.isFinite(+t.green_min)  ? +t.green_min  : 10,
                yellow_min: Number.isFinite(+t.yellow_min) ? +t.yellow_min : 5,
            };
        }
        _syncStationSelect(data.stations);
        render(data.orders || []);
    }

    function setStation(stationId) {
        _stationFilter = (stationId || '').trim();
        try {
            if (_stationFilter) localStorage.setItem(LS_STATION, _stationFilter);
            else localStorage.removeItem(LS_STATION);
        } catch (_) {}
        const params = new URLSearchParams(window.location.search || '');
        if (_stationFilter) params.set('station', _stationFilter);
        else params.delete('station');
        const qs = params.toString();
        const url = qs ? `${window.location.pathname}?${qs}` : window.location.pathname;
        window.history.replaceState({}, '', url);
        refresh();
    }

    // Phase E — kitchen_delta highlight. Zwraca mapę line_id → {type, changes}
    // type: 'added' | 'removed' | 'modified'. 'removed' linie nie są już w o.lines
    // (usunięte z DB), ale delta je listuje — renderujemy je jako przekreślone.
    function _deltaInfo(order) {
        const d = order && order.kitchen_delta;
        if (!d || typeof d !== 'object') return { byLineId: new Map(), removed: [], hasDelta: false };
        const byLineId = new Map();
        (d.added || []).forEach(a => {
            if (a && a.line_id) byLineId.set(a.line_id, { type: 'added', data: a });
        });
        (d.modified || []).forEach(m => {
            if (m && m.line_id) byLineId.set(m.line_id, { type: 'modified', data: m });
        });
        return { byLineId, removed: d.removed || [], hasDelta: true };
    }

    function _deltaLineCls(deltaInfo, lineId) {
        const info = deltaInfo.byLineId.get(lineId);
        if (!info) return '';
        return info.type === 'added' ? 'delta-added'
             : info.type === 'modified' ? 'delta-modified' : '';
    }

    function _deltaLineTag(deltaInfo, lineId) {
        const info = deltaInfo.byLineId.get(lineId);
        if (!info) return '';
        if (info.type === 'added') return '<span class="kds-delta-tag kds-delta-add">+ DODANE</span>';
        if (info.type === 'modified') {
            const ch = info.data && info.data.changes ? Object.keys(info.data.changes) : [];
            const labels = { quantity: 'ilość', modifiers_json: 'modyfikatory', removed_ingredients_json: 'składniki', comment: 'komentarz' };
            const txt = ch.map(c => labels[c] || c).join(', ');
            return `<span class="kds-delta-tag kds-delta-mod">~ ZMIENIONE: ${_esc(txt)}</span>`;
        }
        return '';
    }

    function render(orders) {
        const board = document.getElementById('kds-board');

        if (orders.length === 0) {
            board.innerHTML = '<div class="kds-empty"><i class="fa-solid fa-check-double"></i><h3>Brak zamówień</h3></div>';
            return;
        }

        board.innerHTML = orders.map(o => {
            const num = (o.order_number || '').split('/').pop();
            const timer = _timerInfo(o.promised_time);
            const typeCls = o.order_type === 'delivery' ? 'delivery' : o.order_type === 'takeaway' ? 'takeaway' : '';
            const statusCls = `status-${o.status}`;

            const deliveryBar = o.order_type === 'delivery' && o.delivery_address
                ? `<div class="kds-delivery-bar"><i class="fa-solid fa-truck"></i> ${_esc(o.delivery_address)}</div>`
                : '';

            // Phase E — kitchen_delta highlight (zielony=dodane, czerwony=usunięte, żółty=zmienione)
            const deltaInfo = _deltaInfo(o);
            const editedBanner = (o.edited_since_print && deltaInfo.hasDelta)
                ? `<div class="kds-edited-banner"><i class="fa-solid fa-pen-to-square"></i> ZAMÓWIENIE EDYTOWANE — zobacz zmiany poniżej</div>`
                : '';

            const linesHtml = (o.lines || []).map(l => {
                const act = l.driver_action_type || 'none';
                const actCls = act !== 'none' ? `action-${act}` : '';
                const actTag = act !== 'none' ? `<span class="kds-action-tag">${ACTION_LABELS[act] || act}</span>` : '';
                const deltaCls = _deltaLineCls(deltaInfo, l.id);
                const deltaTag = _deltaLineTag(deltaInfo, l.id);

                let modsHtml = '';
                if (l.modifiers_json) {
                    try {
                        const mods = typeof l.modifiers_json === 'string' ? JSON.parse(l.modifiers_json) : l.modifiers_json;
                        if (Array.isArray(mods) && mods.length) {
                            modsHtml = `<span class="kds-line-mods">+ ${mods.map(m => m.name || m).join(', ')}</span>`;
                        }
                    } catch {}
                }

                const commentHtml = l.comment ? `<span class="kds-line-comment">${_esc(l.comment)}</span>` : '';

                return `<div class="kds-line ${actCls} ${deltaCls}">
                    <div class="kds-line-qty">${l.quantity}x</div>
                    <div style="flex:1"><span class="kds-line-name">${_esc(l.snapshot_name)}</span>${modsHtml}${commentHtml}${deltaTag}</div>
                    ${actTag}
                </div>`;
            }).join('');

            // Phase E — usunięte linie (nie ma już w o.lines, ale delta je listuje)
            const removedHtml = deltaInfo.removed.map(rm => {
                return `<div class="kds-line delta-removed">
                    <div class="kds-line-qty">${rm.quantity}x</div>
                    <div style="flex:1"><span class="kds-line-name">${_esc(rm.snapshot_name)}</span><span class="kds-delta-tag kds-delta-rem">- USUNIĘTE</span></div>
                </div>`;
            }).join('');

            const cfg = BUMP_CONFIG[o.status] || BUMP_CONFIG.new;
            const recallBtn = o.status !== 'new'
                ? `<button class="kds-recall" title="Cofnij status" onclick="KdsApp.recall('${o.id}')"><i class="fa-solid fa-rotate-left"></i></button>`
                : '';

            const sourceBadge = o.source && SOURCE_BADGES[o.source]
                ? `<span class="kds-source-badge" style="background:${SOURCE_BADGES[o.source].color}">${SOURCE_BADGES[o.source].label}</span>`
                : '';

            const payMethodBadge = o.payment_method
                ? `<span class="kds-pay-badge kds-pay-${o.payment_method}" title="Status płatności: ${_esc(o.payment_status || '—')}">${_payLabel(o.payment_method)}</span>`
                : '';

            const customerLine = (o.order_type === 'delivery' && o.customer_phone)
                ? `<div class="kds-customer-line"><i class="fa-solid fa-user"></i> ${_esc(o.customer_name || '—')} · <a href="tel:${_esc(o.customer_phone)}">${_esc(o.customer_phone)}</a></div>`
                : '';

            return `<div class="kds-ticket ${statusCls}">
                <div class="kds-ticket-header">
                    <span class="kds-order-num">#${_esc(num)}</span>
                    <div class="kds-order-meta">
                        <div class="kds-order-badges">
                            ${sourceBadge}
                            <span class="kds-order-type ${typeCls}">${TYPE_LABELS[o.order_type] || 'POS'}</span>
                            ${payMethodBadge}
                        </div>
                        <div class="kds-order-timer ${timer.cls}">${timer.text}</div>
                    </div>
                </div>
                ${customerLine}
                ${deliveryBar}
                ${editedBanner}
                <div class="kds-lines">${linesHtml}${removedHtml}</div>
                <div class="kds-ticket-foot">
                    <button class="kds-bump ${cfg.cls}" onclick="KdsApp.bump('${o.id}','${cfg.next}')">${cfg.label}</button>
                    ${recallBtn}
                </div>
            </div>`;
        }).join('');
    }

    async function bump(orderId, newStatus) {
        const r = await _post('bump_order', { order_id: orderId, new_status: newStatus });
        if (r.success) {
            const msg = {
                accepted:  'Zamówienie zaakceptowane',
                preparing: 'Rozpoczęto przygotowanie',
                ready:     'Zamówienie gotowe!',
            }[newStatus] || 'Status zaktualizowany';
            toast(msg, 'success');
            refresh();
        } else {
            toast(r.message || 'Błąd', 'error');
        }
    }

    async function recall(orderId) {
        if (!confirm('Cofnąć status zamówienia na "Przygotowanie"?')) return;
        const r = await _post('recall_order', { order_id: orderId });
        if (r.success) {
            toast('Status cofnięty — wróciło do kuchni', 'success');
            refresh();
        } else {
            toast(r.message || 'Błąd', 'error');
        }
    }

    function _payLabel(m) {
        switch (m) {
            case 'cash_on_delivery': return '💵 Gotówka';
            case 'card_on_delivery': return '💳 Karta';
            case 'online_transfer':  return '🌐 Online';
            default: return m || '—';
        }
    }

    function toast(msg, type = 'info') {
        const c = document.getElementById('toast-container');
        const t = document.createElement('div');
        t.className = `toast ${type}`;
        t.textContent = msg;
        c.appendChild(t);
        setTimeout(() => t.remove(), 3000);
    }

    function updateClock() {
        const now = new Date();
        document.getElementById('kds-clock').textContent =
            String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
    }

    document.addEventListener('DOMContentLoaded', () => {
        _initStationFilter();
        const sel = document.getElementById('kds-station-select');
        if (sel) {
            sel.addEventListener('change', () => setStation(sel.value));
        }
        refresh();
        _timer = setInterval(refresh, POLL_INTERVAL);
        updateClock();
        setInterval(updateClock, 15000);
    });

    return Object.freeze({ refresh, bump, recall, setStation });
})();
