/**
 * SLICEHUB — Smart Floor Plan · UI Renderer (Production)
 * Floating positioned panels, context menus, numpad, obstacles, toasts.
 * Zero centered modals for live actions — everything floats next to the table.
 */
const TablesUI = (function () {
    'use strict';

    const SLA_WARN_MS  = 20 * 60 * 1000;
    const OBS_SHAPES   = ['bar', 'wall', 'counter', 'pillar'];

    // ── DOM refs ─────────────────────────────────────────────────────────
    const $grid     = () => document.getElementById('floor-grid');
    const $overlay  = () => document.getElementById('modal-overlay');
    const $modal    = () => document.getElementById('glass-modal');
    const $toasts   = () => document.getElementById('toast-container');
    const $zoneTabs = () => document.getElementById('zone-tabs');

    // ── Helpers ──────────────────────────────────────────────────────────
    function esc(s)   { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function money(g) { return ((g || 0) / 100).toFixed(2) + ' zł'; }
    function isObstacle(t) { return OBS_SHAPES.includes(t.shape); }

    function elapsed(iso) {
        if (!iso) return null;
        const m = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
        if (m < 0) return '0m';
        return m < 60 ? m + 'm' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
    }
    function elapsedMs(iso) { return iso ? Date.now() - new Date(iso).getTime() : 0; }

    function deriveVisualStatus(t) {
        if (isObstacle(t)) return 'obstacle';
        const ps = t.physical_status;
        if (ps === 'free' || ps === 'dirty' || ps === 'reserved' || ps === 'merged') return ps;
        if (ps === 'occupied' && t.order) {
            if (t.order.status === 'ready') return 'ready';
            if (t.order.status === 'preparing') return 'preparing';
        }
        return 'occupied';
    }

    // ── Zone Tabs ────────────────────────────────────────────────────────
    function renderZoneTabs(zones, active, onSwitch) {
        const c = $zoneTabs(); if (!c) return;
        c.innerHTML = '';
        const mk = (label, val) => {
            const b = document.createElement('button');
            b.className = 'zone-tab' + (String(active) === String(val) ? ' active' : '');
            b.textContent = label;
            b.onclick = () => onSwitch(val);
            c.appendChild(b);
        };
        mk('Wszystkie', 'all');
        (zones || []).forEach(z => mk(z.name, z.id));
    }

    // ── Floor Stats ──────────────────────────────────────────────────────
    function updateStats(tables) {
        let free = 0, occ = 0, guests = 0;
        tables.forEach(t => {
            if (isObstacle(t)) return;
            if (t.physical_status === 'free') free++;
            else if (t.physical_status !== 'merged') occ++;
            if (t.order?.guest_count) guests += parseInt(t.order.guest_count) || 0;
        });
        const s = id => document.getElementById(id);
        if (s('stat-free'))     s('stat-free').textContent = free;
        if (s('stat-occupied')) s('stat-occupied').textContent = occ;
        if (s('stat-guests'))   s('stat-guests').textContent = guests;
    }

    // ── Render Floor (Tables + Obstacles) ────────────────────────────────
    function renderFloor(tables, editMode, selectedId, handlers) {
        const grid = $grid(); if (!grid) return;
        grid.innerHTML = '';

        tables.forEach((t, i) => {
            const vs = deriveVisualStatus(t);
            const isObs = isObstacle(t);
            const el = document.createElement('div');

            el.className = 'table-node shape-' + (t.shape || 'square');
            if (isObs)    el.classList.add('obstacle');
            else          el.classList.add('status-' + vs);
            if (editMode) el.classList.add('edit-mode');
            if (String(t.id) === String(selectedId)) el.classList.add('selected');

            el.dataset.tableId = t.id;
            el.style.left = (t.pos_x || 10) + '%';
            el.style.top  = (t.pos_y || 10) + '%';

            // SLA warning
            if (!isObs && (vs === 'occupied' || vs === 'preparing') && t.order) {
                if (elapsedMs(t.order.created_at) > SLA_WARN_MS) el.classList.add('pulse-warning');
            }

            // Inner content
            let html = '';
            if (isObs) {
                html = `<span class="obstacle-label">${esc(t.table_number)}</span>`;
            } else {
                html = `<span class="table-number">${esc(t.table_number)}</span>`;
                html += `<span class="table-seats">${t.seats || '?'} <i class="fa-solid fa-chair" style="font-size:7px"></i></span>`;
                if (t.order) {
                    const el2 = elapsed(t.order.created_at);
                    if (el2) html += `<span class="table-timer">${el2}</span>`;
                    if (t.order.guest_count) html += `<span class="table-guests">${t.order.guest_count}</span>`;
                    if (t.order.order_number) html += `<span class="table-order-badge">#${esc(t.order.order_number)}</span>`;
                    const waiter = t.order.waiter_name || t.order.created_by_name || '';
                    if (waiter) html += `<span class="table-waiter">${esc(waiter)}</span>`;
                }
            }

            if (editMode) {
                html += `<button class="edit-delete" data-delete="${t.id}"><i class="fa-solid fa-xmark"></i></button>`;
            }

            el.innerHTML = html;

            // Click
            el.addEventListener('click', e => {
                if (e.target.closest('.edit-delete')) {
                    if (handlers.onDelete) handlers.onDelete(t);
                    return;
                }
                if (editMode) {
                    if (handlers.onEditSelect) handlers.onEditSelect(t, el);
                    return;
                }
                if (isObs) return;
                if (handlers.onClick) handlers.onClick(t, vs, el);
            });

            grid.appendChild(el);
        });

        updateStats(tables);
    }

    // ── Positioned Floating Panel Engine ─────────────────────────────────
    function _positionPanel(anchor, panel) {
        const ar = anchor.getBoundingClientRect();
        const vw = window.innerWidth, vh = window.innerHeight;

        panel.style.visibility = 'hidden';
        panel.style.display = 'block';
        const pw = panel.offsetWidth, ph = panel.offsetHeight;
        panel.style.visibility = '';

        let left, top;
        const gap = 14;

        if (ar.right + gap + pw < vw - 12) left = ar.right + gap;
        else if (ar.left - gap - pw > 12)   left = ar.left - gap - pw;
        else left = Math.max(12, Math.min(vw - pw - 12, ar.left + ar.width / 2 - pw / 2));

        top = ar.top + ar.height / 2 - ph / 2;
        top = Math.max(12, Math.min(vh - ph - 12, top));

        panel.style.left = left + 'px';
        panel.style.top  = top + 'px';
    }

    function dismissPanel() {
        const bd = document.getElementById('panel-backdrop');
        const fp = document.getElementById('float-panel');
        if (fp) {
            fp.classList.remove('visible');
            fp.classList.add('dismissing');
            setTimeout(() => { bd?.remove(); fp?.remove(); }, 180);
        } else if (bd) bd.remove();
    }

    function _showPanel(anchorEl, cssClass, innerHtml) {
        dismissPanel();

        const bd = document.createElement('div');
        bd.className = 'panel-backdrop'; bd.id = 'panel-backdrop';
        bd.onclick = dismissPanel;

        const panel = document.createElement('div');
        panel.className = 'float-panel ' + cssClass; panel.id = 'float-panel';
        panel.innerHTML = innerHtml;

        document.body.appendChild(bd);
        document.body.appendChild(panel);

        requestAnimationFrame(() => {
            _positionPanel(anchorEl, panel);
            panel.classList.add('visible');
        });

        return panel;
    }

    // ── Floating Panel: Guest Numpad (FREE table) ────────────────────────
    function showGuestPanel(anchorEl, table, onConfirm) {
        let raw = '2', fresh = true;
        const html = `
            <div class="numpad-header">
                <div class="numpad-icon"><i class="fa-solid fa-door-open"></i></div>
                <div class="numpad-info">
                    <div class="numpad-title">Stolik ${esc(table.table_number)}</div>
                    <div class="numpad-sub">${table.seats} miejsc · ${esc(table.zone_name || 'Bez strefy')}</div>
                </div>
            </div>
            <div class="numpad-display">
                <span class="numpad-value" id="np-val">2</span>
                <span class="numpad-label">liczba gości</span>
            </div>
            <div class="numpad-grid">
                ${[1,2,3,4,5,6,7,8,9].map(n => `<button class="numpad-key" data-k="${n}">${n}</button>`).join('')}
                <button class="numpad-key fn" data-k="C">C</button>
                <button class="numpad-key" data-k="0">0</button>
                <button class="numpad-key confirm" data-k="ok"><i class="fa-solid fa-check"></i></button>
            </div>
            <button class="numpad-action" id="np-open">
                <i class="fa-solid fa-play"></i> Otwórz Stolik
            </button>
        `;
        const panel = _showPanel(anchorEl, 'numpad-panel', html);
        const valEl = panel.querySelector('#np-val');

        function updateDisplay() {
            const n = parseInt(raw) || 0;
            valEl.textContent = n > 0 ? n : '—';
        }

        panel.querySelectorAll('.numpad-key').forEach(k => {
            k.onclick = () => {
                const v = k.dataset.k;
                if (v === 'C') { raw = ''; fresh = true; }
                else if (v === 'ok') { doConfirm(); return; }
                else if (fresh) { raw = v; fresh = false; }
                else if (raw.length < 2) { raw += v; }
                if (parseInt(raw) > 30) raw = '30';
                updateDisplay();
            };
        });

        function doConfirm() {
            const count = Math.max(1, parseInt(raw) || 1);
            dismissPanel();
            onConfirm(count);
        }
        panel.querySelector('#np-open').onclick = doConfirm;
    }

    // ── Floating Panel: Context Menu (OCCUPIED table) ────────────────────
    function showContextMenu(anchorEl, table, items) {
        const o = table.order || {};
        const el2 = elapsed(o.created_at) || '—';
        const gStr = o.guest_count ? o.guest_count + ' gości' : '';
        const sub = [el2, gStr, money(o.grand_total)].filter(Boolean).join(' · ');

        let itemsHtml = items.map(it => `
            <button class="ctx-item ${it.color || ''}" data-action="${it.action}">
                <div class="ctx-item-icon"><i class="${it.icon}"></i></div>
                <div class="ctx-item-text">
                    <div class="ctx-item-label">${esc(it.label)}</div>
                    ${it.desc ? `<div class="ctx-item-desc">${esc(it.desc)}</div>` : ''}
                </div>
                <i class="fa-solid fa-chevron-right ctx-item-arrow"></i>
            </button>
        `).join('');

        const html = `
            <div class="ctx-menu-header">
                <div class="ctx-menu-icon" style="background:rgba(234,179,8,0.15);color:var(--accent-yellow)"><i class="fa-solid fa-utensils"></i></div>
                <div>
                    <div class="ctx-menu-title">Stolik ${esc(table.table_number)}</div>
                    <div class="ctx-menu-sub">${sub}</div>
                </div>
            </div>
            ${itemsHtml}
        `;
        const panel = _showPanel(anchorEl, 'ctx-menu', html);

        panel.querySelectorAll('.ctx-item').forEach(btn => {
            btn.onclick = () => {
                const act = btn.dataset.action;
                dismissPanel();
                const found = items.find(i => i.action === act);
                if (found?.handler) found.handler();
            };
        });
    }

    // ── Floating Panel: Single Big Action (READY / DIRTY) ────────────────
    function showActionPanel(anchorEl, table, opts) {
        const html = `
            <div class="action-panel-header">
                <div class="action-panel-icon" style="background:${opts.iconBg};color:${opts.iconColor}">
                    <i class="${opts.icon}"></i>
                </div>
                <div>
                    <div class="action-panel-title">Stolik ${esc(table.table_number)}</div>
                    <div class="action-panel-sub">${esc(opts.subtitle)}</div>
                </div>
            </div>
            <button class="big-action-btn ${opts.btnClass || ''}" id="ap-action">
                <i class="${opts.btnIcon}"></i> ${esc(opts.btnLabel)}
            </button>
        `;
        const panel = _showPanel(anchorEl, 'action-panel', html);
        panel.querySelector('#ap-action').onclick = () => {
            dismissPanel();
            if (opts.onAction) opts.onAction();
        };
    }

    // ── Centered Glass Modal (forms, confirmations) ──────────────────────
    function _showModal(html) {
        const ov = $overlay(), md = $modal();
        ov.classList.remove('hidden', 'closing');
        md.innerHTML = html;
    }
    function closeModal() {
        const ov = $overlay();
        if (ov.classList.contains('hidden')) return;
        ov.classList.add('closing');
        setTimeout(() => { ov.classList.add('hidden'); ov.classList.remove('closing'); }, 200);
    }
    document.addEventListener('DOMContentLoaded', () => {
        const ov = $overlay();
        if (ov) ov.addEventListener('pointerdown', e => { if (e.target === ov) closeModal(); });
    });

    // ── Modal: Create Table ──────────────────────────────────────────────
    function showCreateTableModal(zones, onConfirm) {
        let selectedShape = 'square';
        let opts = '<option value="">— Brak strefy —</option>';
        (zones || []).forEach(z => { opts += `<option value="${z.id}">${esc(z.name)}</option>`; });

        const html = `
            <div class="modal-header">
                <div class="modal-icon blue"><i class="fa-solid fa-plus"></i></div>
                <div class="modal-title-group"><div class="modal-title">Nowy Stolik</div><div class="modal-subtitle">Dodaj stolik do planu sali</div></div>
                <button class="modal-close" id="mc-x"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="modal-form-group"><label class="modal-form-label">Numer stolika</label><input class="modal-form-input" id="ct-num" type="text" placeholder="np. 15" autofocus></div>
                <div class="modal-form-group"><label class="modal-form-label">Liczba miejsc</label><input class="modal-form-input" id="ct-seats" type="number" min="1" max="20" value="4"></div>
                <div class="modal-form-group"><label class="modal-form-label">Strefa</label><select class="modal-form-input" id="ct-zone">${opts}</select></div>
                <div class="modal-form-group"><label class="modal-form-label">Kształt</label>
                    <div class="shape-selector">
                        <div class="shape-option active" data-shape="square"><div class="shape-preview"></div><span>Kwadrat</span></div>
                        <div class="shape-option" data-shape="round"><div class="shape-preview"></div><span>Okrągły</span></div>
                        <div class="shape-option" data-shape="rectangle"><div class="shape-preview"></div><span>Prostokąt</span></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-footer-btn" id="mc-cancel">Anuluj</button>
                <button class="modal-footer-btn primary" id="mc-ok"><i class="fa-solid fa-plus"></i> Dodaj</button>
            </div>`;
        _showModal(html);

        document.querySelectorAll('.shape-option').forEach(o => {
            o.onclick = () => { document.querySelectorAll('.shape-option').forEach(x => x.classList.remove('active')); o.classList.add('active'); selectedShape = o.dataset.shape; };
        });
        document.getElementById('mc-x').onclick = closeModal;
        document.getElementById('mc-cancel').onclick = closeModal;
        document.getElementById('mc-ok').onclick = () => {
            const num = document.getElementById('ct-num').value.trim();
            if (!num) { document.getElementById('ct-num').focus(); return; }
            closeModal();
            onConfirm({
                table_number: num,
                seats: parseInt(document.getElementById('ct-seats').value) || 4,
                shape: selectedShape,
                zone_id: document.getElementById('ct-zone').value || null,
            });
        };
    }

    // ── Modal: Create Obstacle ───────────────────────────────────────────
    function showCreateObstacleModal(onConfirm) {
        let selectedShape = 'bar';
        const html = `
            <div class="modal-header">
                <div class="modal-icon blue"><i class="fa-solid fa-shapes"></i></div>
                <div class="modal-title-group"><div class="modal-title">Nowy Element</div><div class="modal-subtitle">Bar, ściana, lada, filar</div></div>
                <button class="modal-close" id="mc-x"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="modal-form-group"><label class="modal-form-label">Etykieta</label><input class="modal-form-input" id="co-label" type="text" placeholder="np. Bar główny" autofocus></div>
                <div class="modal-form-group"><label class="modal-form-label">Typ</label>
                    <div class="shape-selector">
                        <div class="shape-option active" data-shape="bar"><div class="shape-preview" style="width:48px;height:24px;border-radius:4px"></div><span>Bar</span></div>
                        <div class="shape-option" data-shape="wall"><div class="shape-preview" style="width:48px;height:6px;border-radius:3px"></div><span>Ściana</span></div>
                        <div class="shape-option" data-shape="counter"><div class="shape-preview" style="width:44px;height:22px;border-radius:4px"></div><span>Lada</span></div>
                        <div class="shape-option" data-shape="pillar"><div class="shape-preview" style="width:20px;height:20px;border-radius:50%"></div><span>Filar</span></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-footer-btn" id="mc-cancel">Anuluj</button>
                <button class="modal-footer-btn primary" id="mc-ok"><i class="fa-solid fa-plus"></i> Dodaj</button>
            </div>`;
        _showModal(html);

        document.querySelectorAll('.shape-option').forEach(o => {
            o.onclick = () => { document.querySelectorAll('.shape-option').forEach(x => x.classList.remove('active')); o.classList.add('active'); selectedShape = o.dataset.shape; };
        });
        document.getElementById('mc-x').onclick = closeModal;
        document.getElementById('mc-cancel').onclick = closeModal;
        document.getElementById('mc-ok').onclick = () => {
            const label = document.getElementById('co-label').value.trim();
            if (!label) { document.getElementById('co-label').focus(); return; }
            closeModal();
            onConfirm({ table_number: label, shape: selectedShape, seats: 0 });
        };
    }

    // ── Modal: Merge Tables ──────────────────────────────────────────────
    function showMergeModal(currentTable, allTables, onMerge) {
        const available = allTables.filter(t =>
            t.id !== currentTable.id && !isObstacle(t) &&
            t.physical_status !== 'merged'
        );
        const rows = available.map(t => `
            <button class="merge-option" data-id="${t.id}">
                <span class="merge-num">${esc(t.table_number)}</span>
                <span class="merge-detail">${t.seats} miejsc · ${esc(t.zone_name || '')}</span>
                <span class="merge-status st-${t.physical_status}">${t.physical_status}</span>
            </button>
        `).join('');

        const html = `
            <div class="modal-header">
                <div class="modal-icon blue"><i class="fa-solid fa-table-cells-large"></i></div>
                <div class="modal-title-group"><div class="modal-title">Łączenie — Stolik ${esc(currentTable.table_number)}</div><div class="modal-subtitle">Wybierz stolik do połączenia</div></div>
                <button class="modal-close" id="mc-x"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body"><div class="merge-list">${rows || '<p style="color:var(--text-muted);text-align:center;padding:24px">Brak dostępnych stolików</p>'}</div></div>`;
        _showModal(html);

        document.getElementById('mc-x').onclick = closeModal;
        document.querySelectorAll('.merge-option').forEach(btn => {
            btn.onclick = () => { closeModal(); onMerge(parseInt(btn.dataset.id)); };
        });
    }

    // ── Modal: Confirm Delete ────────────────────────────────────────────
    function showDeleteConfirm(table, onConfirm) {
        const label = isObstacle(table) ? 'element' : 'stolik';
        const html = `
            <div class="modal-header">
                <div class="modal-icon red"><i class="fa-solid fa-trash-can"></i></div>
                <div class="modal-title-group"><div class="modal-title">Usuń ${label} ${esc(table.table_number)}?</div><div class="modal-subtitle">Ta operacja jest nieodwracalna</div></div>
                <button class="modal-close" id="mc-x"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-footer">
                <button class="modal-footer-btn" id="mc-cancel">Anuluj</button>
                <button class="modal-footer-btn danger" id="mc-ok"><i class="fa-solid fa-trash-can"></i> Usuń</button>
            </div>`;
        _showModal(html);
        document.getElementById('mc-x').onclick = closeModal;
        document.getElementById('mc-cancel').onclick = closeModal;
        document.getElementById('mc-ok').onclick = () => { closeModal(); onConfirm(); };
    }

    // ── Access Denied Micro-Modal ────────────────────────────────────────
    function showAccessDenied(waiterName) {
        let overlay = document.getElementById('access-denied-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'access-denied-overlay';
            document.body.appendChild(overlay);
        }

        overlay.className = 'ad-overlay';
        overlay.innerHTML = `
            <div class="ad-backdrop"></div>
            <div class="ad-card">
                <div class="ad-icon-ring">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <div class="ad-title">Brak uprawnień</div>
                <div class="ad-body">Zamówienie obsługuje:</div>
                <div class="ad-waiter">${esc(waiterName)}</div>
                <button class="ad-close-btn" id="ad-close">Rozumiem</button>
            </div>
        `;

        requestAnimationFrame(() => overlay.classList.add('visible'));

        const dismiss = () => {
            overlay.classList.remove('visible');
            overlay.classList.add('leaving');
            setTimeout(() => { overlay.remove(); }, 300);
        };

        overlay.querySelector('.ad-backdrop').onclick = dismiss;
        overlay.querySelector('#ad-close').onclick = dismiss;

        setTimeout(dismiss, 4000);
    }

    // ── Toast ────────────────────────────────────────────────────────────
    function toast(msg, type = 'info') {
        const icons = { success:'fa-solid fa-circle-check', error:'fa-solid fa-circle-xmark', info:'fa-solid fa-circle-info', warning:'fa-solid fa-triangle-exclamation' };
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.innerHTML = `<i class="${icons[type]||icons.info}"></i><span>${esc(msg)}</span>`;
        $toasts().appendChild(el);
        setTimeout(() => { el.classList.add('leaving'); setTimeout(() => el.remove(), 250); }, 3500);
    }

    // ── Public API ───────────────────────────────────────────────────────
    return Object.freeze({
        renderZoneTabs,
        renderFloor,
        updateStats,
        deriveVisualStatus,
        isObstacle,
        showGuestPanel,
        showContextMenu,
        showActionPanel,
        showAccessDenied,
        showCreateTableModal,
        showCreateObstacleModal,
        showMergeModal,
        showDeleteConfirm,
        dismissPanel,
        closeModal,
        toast,
    });
})();
