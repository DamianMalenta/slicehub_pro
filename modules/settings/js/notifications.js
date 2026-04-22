/**
 * SliceHub — Settings · Zakładka Powiadomienia (Notification Director)
 *
 * Sekcje:
 *   1. Kanały  — CRUD + health dot + test-send
 *   2. Routing — event × kanał matrix z fallback_order + consent flags
 *   3. Szablony — edytor per event+channel z podglądem zmiennych
 *
 * Wymaga window.stOpenModal / window.stCloseModal / window.stToast
 * eksponowanych przez settings_app.js po DOMContentLoaded.
 */
(function () {
    'use strict';

    // ── Stałe ────────────────────────────────────────────────────────────────

    // Relatywnie jak settings_app.js — działa przy VHost / innej ścieżce niż /slicehub/…
    const API_URL = '../../api/settings/engine.php';

    const EVENT_TYPES = [
        { key: 'order.created',    label: 'Zamówienie złożone',     icon: 'fa-cart-plus',        color: '#60a5fa' },
        { key: 'order.accepted',   label: 'Zamówienie przyjęte',    icon: 'fa-check-circle',     color: '#4ade80' },
        { key: 'order.preparing',  label: 'W przygotowaniu',        icon: 'fa-fire-burner',      color: '#fb923c' },
        { key: 'order.ready',      label: 'Gotowe do odbioru',      icon: 'fa-bell',             color: '#facc15' },
        { key: 'order.dispatched', label: 'Wydane kierowcy',        icon: 'fa-motorcycle',       color: '#a78bfa' },
        { key: 'order.in_delivery',label: 'W drodze',               icon: 'fa-truck-fast',       color: '#38bdf8' },
        { key: 'order.delivered',  label: 'Dostarczone',            icon: 'fa-house-circle-check',color: '#4ade80' },
        { key: 'order.completed',  label: 'Zakończone',             icon: 'fa-flag-checkered',   color: '#86efac' },
        { key: 'order.cancelled',  label: 'Anulowane',              icon: 'fa-ban',              color: '#f87171' },
        { key: 'marketing.campaign',label: 'Kampania marketingowa', icon: 'fa-bullhorn',         color: '#fbbf24' },
        { key: 'reorder.nudge',    label: 'Reorder Nudge',          icon: 'fa-pizza-slice',      color: '#fb923c' },
    ];

    const CH_ICONS = {
        in_app:         'fa-mobile-screen-button',
        email:          'fa-envelope',
        personal_phone: 'fa-mobile-alt',
        sms_gateway:    'fa-comment-sms',
    };
    const CH_LABELS = {
        in_app:         'In-App (SSE)',
        email:          'Email (SMTP)',
        personal_phone: 'Mój Telefon',
        sms_gateway:    'Bramka SMS',
    };
    const CH_COLORS = {
        in_app:         '#60a5fa',
        email:          '#a78bfa',
        personal_phone: '#4ade80',
        sms_gateway:    '#fbbf24',
    };

    const PROVIDER_CRED_TEMPLATES = {
        smtp:                 '{\n  "host": "smtp.gmail.com",\n  "port": 587,\n  "encryption": "tls",\n  "username": "",\n  "password": "",\n  "from_email": "",\n  "from_name": ""\n}',
        smsgateway_android:   '{\n  "provider": "smsgateway_android",\n  "base_url": "http://192.168.0.1:8080",\n  "username": "",\n  "password": "",\n  "webhook_secret": "",\n  "smsgateway_mode": "auto"\n}',
        generic_http:         '{\n  "provider": "generic_http",\n  "url": "http://192.168.x.x:8080",\n  "bearer_token": ""\n}',
        smsapi_pl:            '{\n  "provider": "smsapi_pl",\n  "token": "",\n  "sender": "Pizza"\n}',
        twilio:               '{\n  "provider": "twilio",\n  "account_sid": "",\n  "auth_token": "",\n  "from_number": "+48..."\n}',
        sse:                  '{}',
    };

    const TPL_VARS = [
        '{{customer_name}}','{{order_number}}','{{order_type}}',
        '{{eta_minutes}}','{{tracking_url}}','{{store_name}}',
        '{{store_phone}}','{{store_url}}','{{total_pln}}',
        '{{delivery_address}}','{{promised_time}}','{{payment_method}}',
    ];

    // ── CSRF + API ────────────────────────────────────────────────────────────

    let _csrf = null;
    const READ_ONLY = new Set([
        'notifications_channels_list','notifications_routes_get','notifications_templates_get',
    ]);

    function parseJsonResponse(text, action, status) {
        const t = (text || '').trim();
        if (!t.startsWith('{') && !t.startsWith('[')) {
            console.error('[notifications] Non-JSON response', action, status, t.slice(0, 240));
            return {
                success: false,
                message: `Serwer zwrócił ${status} (HTML zamiast JSON). Sprawdź ścieżkę do api/settings/engine.php i log PHP.`,
            };
        }
        try {
            return JSON.parse(t);
        } catch (e) {
            console.error('[notifications] JSON parse error', action, e);
            return { success: false, message: 'Odpowiedź serwera nie jest poprawnym JSON.' };
        }
    }

    async function getToken() {
        if (_csrf) return _csrf;
        const r = await fetch(API_URL, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'csrf_token' }),
        });
        const raw = await r.text();
        const d = parseJsonResponse(raw, 'csrf_token', r.status);
        _csrf = d.data?.token || null;
        return _csrf;
    }

    async function api(action, data = {}) {
        const tid  = window.SLICEHUB_TENANT_ID || 1;
        const hdrs = { 'Content-Type': 'application/json' };
        if (!READ_ONLY.has(action)) {
            const tok = await getToken();
            if (tok) hdrs['X-CSRF-Token'] = tok;
        }
        const r = await fetch(API_URL, {
            method: 'POST', credentials: 'same-origin', headers: hdrs,
            body: JSON.stringify({ action, tenantId: tid, ...data }),
        });
        const raw = await r.text();
        return parseJsonResponse(raw, action, r.status);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    const esc = v => {
        const d = document.createElement('div');
        d.textContent = v ?? '';
        return d.innerHTML;
    };

    function toast(msg, ok = true) {
        if (typeof window.stToast === 'function') { window.stToast(msg, ok); return; }
        const root = document.getElementById('st-toast-root');
        if (!root) return;
        const t = document.createElement('div');
        t.className = `st-toast ${ok ? 'st-toast--ok' : 'st-toast--err'}`;
        t.textContent = msg;
        root.appendChild(t);
        setTimeout(() => t.remove(), 3500);
    }

    function openModal(content) {
        if (typeof window.stOpenModal === 'function') {
            window.stOpenModal(content);
        } else {
            // Fallback: bezpośredni DOM jeśli settings_app.js jeszcze nie uruchomił
            const root = document.getElementById('st-modal-root');
            if (!root) return;
            root.innerHTML = '';
            root.appendChild(content);
            root.classList.add('st-modal-root--open');
            root.addEventListener('click', e => { if (e.target === root) closeModal(); }, { once: true });
        }
    }

    function closeModal() {
        if (typeof window.stCloseModal === 'function') {
            window.stCloseModal();
        } else {
            const root = document.getElementById('st-modal-root');
            if (root) { root.classList.remove('st-modal-root--open'); setTimeout(() => root.innerHTML = '', 250); }
        }
    }

    function makeField(label, inputHtml, hint = '') {
        return `
        <div class="st-field">
            <label>${label}</label>
            ${inputHtml}
            ${hint ? `<p style="color:var(--muted);font-size:11px;margin:4px 0 0">${hint}</p>` : ''}
        </div>`;
    }

    function spinner() {
        return `<div class="st-empty"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;opacity:.5"></i></div>`;
    }

    function emptyState(icon, msg) {
        return `<div class="st-empty"><i class="fa-solid ${icon}"></i><p>${msg}</p></div>`;
    }

    // ── Główny render ─────────────────────────────────────────────────────────

    async function renderNotifications(container) {
        container.innerHTML = `
        <div style="padding:4px 0 20px">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
                <div style="width:40px;height:40px;background:linear-gradient(135deg,#ffb84d,#ff8c42);border-radius:10px;display:grid;place-items:center;color:#1a1a1a;font-size:20px;flex-shrink:0">
                    <i class="fa-solid fa-bell"></i>
                </div>
                <div>
                    <h2 style="margin:0;font-size:18px;font-weight:700">Notification Director</h2>
                    <p style="margin:2px 0 0;color:var(--muted);font-size:13px">Wielokanałowe powiadomienia: SMS, Email, In-App (SSE), Bramka</p>
                </div>
            </div>

            <div class="nd-subtabs" id="nd-subtabs">
                <button class="nd-stab nd-stab--active" data-sub="channels">
                    <i class="fa-solid fa-tower-broadcast"></i> Kanały
                </button>
                <button class="nd-stab" data-sub="routing">
                    <i class="fa-solid fa-route"></i> Routing
                </button>
                <button class="nd-stab" data-sub="templates">
                    <i class="fa-solid fa-file-lines"></i> Szablony
                </button>
            </div>

            <div id="nd-pane-channels"></div>
            <div id="nd-pane-routing"   style="display:none"></div>
            <div id="nd-pane-templates" style="display:none"></div>
        </div>
        <style>
        .nd-subtabs { display:flex; gap:6px; margin-bottom:20px; border-bottom:1px solid var(--border); padding-bottom:0; }
        .nd-stab {
            display:inline-flex; align-items:center; gap:7px;
            padding:9px 16px; border:none; background:transparent;
            color:var(--muted); font-family:inherit; font-size:13px; font-weight:600;
            cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px;
            transition:color .15s,border-color .15s;
        }
        .nd-stab:hover { color:var(--text); }
        .nd-stab--active { color:var(--accent); border-bottom-color:var(--accent); }

        .nd-ch-grid { display:grid; gap:10px; }
        .nd-ch-card {
            background:var(--panel); border:1px solid var(--border);
            border-radius:var(--radius); padding:14px 18px;
            display:flex; align-items:center; gap:14px;
            transition:border-color .15s;
        }
        .nd-ch-card:hover { border-color:var(--panel-3); }
        .nd-ch-icon {
            width:44px; height:44px; border-radius:10px;
            display:grid; place-items:center; font-size:20px;
            flex-shrink:0; position:relative;
        }
        .nd-health-dot {
            position:absolute; bottom:-2px; right:-2px;
            width:11px; height:11px; border-radius:50%;
            border:2px solid var(--bg,#0b0e13);
        }
        .nd-ch-body { flex:1; min-width:0; }
        .nd-ch-name { font-weight:700; font-size:15px; display:flex; align-items:center; gap:8px; }
        .nd-ch-meta { color:var(--muted); font-size:12px; margin-top:3px; display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
        .nd-chip {
            display:inline-block; padding:2px 8px; border-radius:4px;
            font-size:11px; font-weight:600; font-family:var(--mono,monospace);
            background:var(--panel-2); border:1px solid var(--border); color:var(--muted);
        }
        .nd-chip--ok   { color:var(--ok);   border-color:rgba(74,222,128,.3); background:rgba(74,222,128,.08); }
        .nd-chip--warn { color:var(--warn); border-color:rgba(245,158,11,.3); background:rgba(245,158,11,.08); }
        .nd-chip--err  { color:var(--err);  border-color:rgba(239,68,68,.3);  background:rgba(239,68,68,.08); }

        .nd-actions { display:flex; gap:6px; flex-shrink:0; }

        /* Routing matrix */
        .nd-rt-row { margin-bottom:12px; }
        .nd-rt-event {
            display:flex; align-items:center; gap:8px; padding:10px 14px;
            background:var(--panel); border:1px solid var(--border); border-radius:var(--radius);
            cursor:pointer; transition:border-color .15s;
        }
        .nd-rt-event:hover { border-color:var(--panel-3); }
        .nd-rt-event-label { flex:1; font-weight:600; font-size:13px; }
        .nd-rt-event-count { font-size:12px; color:var(--muted); }
        .nd-rt-channels {
            margin-top:6px; padding:12px; background:var(--panel-2);
            border:1px solid var(--border); border-top:none;
            border-radius:0 0 var(--radius) var(--radius);
            display:none; gap:8px; flex-wrap:wrap;
        }
        .nd-rt-row.open .nd-rt-channels { display:flex; }
        .nd-rt-row.open .nd-rt-event { border-radius:var(--radius) var(--radius) 0 0; border-color:var(--accent); }
        .nd-rt-ch-toggle {
            display:flex; flex-direction:column; gap:6px;
            padding:10px 14px; border-radius:8px; min-width:160px;
            background:var(--panel); border:1px solid var(--border);
            transition:border-color .15s;
        }
        .nd-rt-ch-toggle.is-active { border-color:rgba(74,222,128,.4); background:rgba(74,222,128,.04); }

        /* Templates */
        .nd-tpl-event { margin-bottom:8px; }
        .nd-tpl-hd {
            display:flex; align-items:center; gap:8px; padding:10px 14px;
            background:var(--panel); border:1px solid var(--border);
            border-radius:var(--radius); cursor:pointer; transition:border-color .15s;
        }
        .nd-tpl-hd:hover { border-color:var(--panel-3); }
        .nd-tpl-body {
            margin-top:6px; background:var(--panel-2); border:1px solid var(--border);
            border-top:none; border-radius:0 0 var(--radius) var(--radius);
            display:none; flex-wrap:wrap; gap:10px; padding:12px;
        }
        .nd-tpl-event.open .nd-tpl-hd  { border-radius:var(--radius) var(--radius) 0 0; border-color:var(--accent); }
        .nd-tpl-event.open .nd-tpl-body { display:flex; }
        .nd-tpl-card {
            flex:1; min-width:260px; background:var(--panel);
            border:1px solid var(--border); border-radius:8px; padding:12px;
        }
        .nd-tpl-card-head {
            display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;
        }
        .nd-tpl-card-label { font-weight:600; font-size:13px; display:flex; align-items:center; gap:6px; }
        </style>
        `;

        // Subtab switching
        container.querySelectorAll('.nd-stab').forEach(btn => {
            btn.addEventListener('click', () => {
                container.querySelectorAll('.nd-stab').forEach(b => b.classList.remove('nd-stab--active'));
                ['channels','routing','templates'].forEach(s => {
                    container.querySelector(`#nd-pane-${s}`).style.display = 'none';
                });
                btn.classList.add('nd-stab--active');
                const sub = btn.dataset.sub;
                container.querySelector(`#nd-pane-${sub}`).style.display = '';
                if (sub === 'channels')  loadChannels(container);
                if (sub === 'routing')   loadRouting(container);
                if (sub === 'templates') loadTemplates(container);
            });
        });

        loadChannels(container);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. KANAŁY
    // ══════════════════════════════════════════════════════════════════════════

    async function loadChannels(container) {
        const pane = container.querySelector('#nd-pane-channels');
        pane.innerHTML = spinner();
        const res = await api('notifications_channels_list');
        if (!res.success) {
            pane.innerHTML = emptyState('fa-triangle-exclamation', esc(res.message));
            return;
        }
        const channels = res.data || [];
        renderChannels(pane, channels, container);
    }

    function renderChannels(pane, channels, container) {
        pane.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <div>
                <h3 style="margin:0;font-size:15px;font-weight:700">Kanały powiadomień</h3>
                <p style="margin:2px 0 0;color:var(--muted);font-size:12px">${channels.length} skonfigurowanych</p>
            </div>
            <button class="st-btn st-btn--primary" id="nd-add-ch">
                <i class="fa-solid fa-plus"></i> Dodaj kanał
            </button>
        </div>
        <div class="nd-ch-grid" id="nd-ch-list"></div>
        `;

        const list = pane.querySelector('#nd-ch-list');

        if (!channels.length) {
            list.innerHTML = emptyState('fa-tower-broadcast', 'Brak kanałów. Dodaj pierwszy, aby zacząć wysyłać powiadomienia.');
        } else {
            list.innerHTML = channels.map(ch => buildChannelCard(ch)).join('');
            list.querySelectorAll('[data-action="edit"]').forEach(b => {
                const ch = channels.find(c => c.id == b.dataset.id);
                b.addEventListener('click', () => showChannelModal(ch, container));
            });
            list.querySelectorAll('[data-action="del"]').forEach(b => {
                b.addEventListener('click', async () => {
                    if (!confirm(`Usunąć kanał "${b.dataset.name}"?\nPowiązane reguły routingu przestaną działać.`)) return;
                    const r = await api('notifications_channels_delete', { id: +b.dataset.id });
                    toast(r.message || 'Usunięto', r.success);
                    if (r.success) loadChannels(container);
                });
            });
            list.querySelectorAll('[data-action="test"]').forEach(b => {
                b.addEventListener('click', async () => {
                    const recipient = prompt('Email lub numer telefonu do testu (np. +48500111222):');
                    if (!recipient) return;
                    b.disabled = true;
                    b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                    const r = await api('notifications_channels_test', { id: +b.dataset.id, recipient });
                    toast(r.message || (r.success ? '✓ Wysłano testową wiadomość' : '✗ Błąd wysyłki'), r.success);
                    b.disabled = false;
                    b.innerHTML = '<i class="fa-solid fa-vial"></i> Test';
                });
            });
        }

        pane.querySelector('#nd-add-ch').addEventListener('click', () => showChannelModal(null, container));
    }

    function buildChannelCard(ch) {
        const color = CH_COLORS[ch.channel_type] || '#8a93a6';
        const isPaused = ch.paused_until && new Date(ch.paused_until) > new Date();
        const dotColor = !ch.is_active ? '#6b7280'
            : isPaused ? '#f59e0b'
            : ch.last_health_status === 'ok' ? '#4ade80'
            : ch.last_health_status === 'error' ? '#ef4444'
            : ch.consecutive_failures > 2 ? '#ef4444'
            : '#6b7280';
        const dotTitle = !ch.is_active ? 'Wyłączony'
            : isPaused ? `Pauza do ${ch.paused_until}`
            : ch.last_health_status === 'ok' ? 'Działa poprawnie'
            : ch.last_health_status === 'error' ? 'Ostatni test: błąd'
            : 'Nieprzetestowany';

        const chips = [
            `<span class="nd-chip">${esc(CH_LABELS[ch.channel_type] || ch.channel_type)}</span>`,
            `<span class="nd-chip">${esc(ch.provider)}</span>`,
            `<span class="nd-chip">Prio: ${ch.priority}</span>`,
            ch.rate_limit_per_hour ? `<span class="nd-chip nd-chip--warn">Max ${ch.rate_limit_per_hour}/h</span>` : '',
            ch.rate_limit_per_day  ? `<span class="nd-chip">Max ${ch.rate_limit_per_day}/d</span>` : '',
            ch.consecutive_failures > 0 ? `<span class="nd-chip nd-chip--err">${ch.consecutive_failures} błędów</span>` : '',
            isPaused ? `<span class="nd-chip nd-chip--warn">⏸ Pauza</span>` : '',
        ].filter(Boolean).join('');

        return `
        <div class="nd-ch-card">
            <div class="nd-ch-icon" style="background:${color}18">
                <i class="fa-solid ${CH_ICONS[ch.channel_type] || 'fa-bell'}" style="color:${color}"></i>
                <span class="nd-health-dot" style="background:${dotColor};box-shadow:0 0 6px ${dotColor}80" title="${esc(dotTitle)}"></span>
            </div>
            <div class="nd-ch-body">
                <div class="nd-ch-name">
                    ${esc(ch.name)}
                    <span class="nd-chip ${ch.is_active ? 'nd-chip--ok' : ''}">${ch.is_active ? 'Aktywny' : 'Wyłączony'}</span>
                </div>
                <div class="nd-ch-meta">${chips}</div>
            </div>
            <div class="nd-actions">
                <button class="st-btn st-btn--ghost st-btn--sm" data-action="test" data-id="${ch.id}">
                    <i class="fa-solid fa-vial"></i> Test
                </button>
                <button class="st-btn st-btn--ghost st-btn--sm" data-action="edit" data-id="${ch.id}">
                    <i class="fa-solid fa-pen"></i>
                </button>
                <button class="st-btn st-btn--danger st-btn--sm" data-action="del" data-id="${ch.id}" data-name="${esc(ch.name)}">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        </div>`;
    }

    function showChannelModal(ch, container) {
        const isEdit = !!ch;
        const selectedType = ch?.channel_type || 'email';
        const selectedProv = ch?.provider || 'smtp';

        const wrap = document.createElement('div');
        wrap.className = 'st-modal';
        wrap.style.maxWidth = '540px';
        wrap.innerHTML = `
        <h3 style="margin:0 0 20px;display:flex;align-items:center;gap:10px">
            <i class="fa-solid ${isEdit ? 'fa-pen' : 'fa-plus'}" style="color:var(--accent)"></i>
            ${isEdit ? 'Edytuj kanał' : 'Nowy kanał powiadomień'}
        </h3>
        <form id="nd-ch-form">
            ${makeField('Nazwa kanału',
                `<input class="st-field__input" type="text" name="name" value="${esc(ch?.name || '')}" required placeholder="np. Mój Samsung S23">`,
                'Przyjazna nazwa widoczna w routingu'
            )}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                ${makeField('Typ kanału',
                    `<select class="st-field__input" name="channel_type" id="nd-ch-type">
                        ${Object.entries(CH_LABELS).map(([v,l]) =>
                            `<option value="${v}" ${selectedType===v?'selected':''}>${l}</option>`
                        ).join('')}
                    </select>`
                )}
                ${makeField('Provider',
                    `<select class="st-field__input" name="provider" id="nd-ch-provider">
                        <option value="smtp"               ${selectedProv==='smtp'?'selected':''}>smtp</option>
                        <option value="smsgateway_android" ${selectedProv==='smsgateway_android'?'selected':''}>smsgateway_android</option>
                        <option value="generic_http"       ${selectedProv==='generic_http'?'selected':''}>generic_http</option>
                        <option value="smsapi_pl"          ${selectedProv==='smsapi_pl'?'selected':''}>smsapi_pl</option>
                        <option value="twilio"             ${selectedProv==='twilio'?'selected':''}>twilio</option>
                        <option value="sse"                ${selectedProv==='sse'?'selected':''}>sse (in-app)</option>
                    </select>`
                )}
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
                ${makeField('Priorytet',
                    `<input class="st-field__input" type="number" name="priority" value="${ch?.priority ?? 10}" min="0" max="99">`,
                    'Niższy = wyższy'
                )}
                ${makeField('Max/godzinę',
                    `<input class="st-field__input" type="number" name="rate_limit_per_hour" value="${ch?.rate_limit_per_hour ?? ''}" min="1" placeholder="∞">`,
                    'Puste = brak limitu'
                )}
                ${makeField('Max/dobę',
                    `<input class="st-field__input" type="number" name="rate_limit_per_day" value="${ch?.rate_limit_per_day ?? ''}" min="1" placeholder="∞">`
                )}
            </div>
            <div class="st-field" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--panel-2);border-radius:8px;border:1px solid var(--border)">
                <input type="checkbox" name="is_active" id="nd-ch-active" ${ch?.is_active !== 0 ? 'checked' : ''} style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                <label for="nd-ch-active" style="cursor:pointer;font-size:14px;font-weight:500;margin:0">Kanał aktywny</label>
            </div>
            <div class="st-field" style="margin-top:14px">
                <label>Credentials JSON
                    <button type="button" id="nd-cred-template" class="st-btn st-btn--ghost st-btn--sm" style="margin-left:8px;vertical-align:middle">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Wstaw szablon
                    </button>
                </label>
                <textarea class="st-field__input" name="credentials" id="nd-cred" rows="7"
                    style="font-family:var(--mono,monospace);font-size:12px;resize:vertical"
                    placeholder="{}">${isEdit ? '' : (PROVIDER_CRED_TEMPLATES[selectedProv] || '{}')}</textarea>
                <p style="color:var(--muted);font-size:11px;margin:4px 0 0">
                    ${isEdit ? '<i class="fa-solid fa-info-circle"></i> Zostaw puste = bez zmian credentials' : 'Dane są przechowywane per tenant w bazie (opcjonalnie szyfrowane przez CredentialVault).'}
                </p>
            </div>
            <div class="st-modal__footer">
                <button type="button" class="st-btn st-btn--ghost" id="nd-ch-cancel">Anuluj</button>
                <button type="submit" class="st-btn st-btn--primary">
                    <i class="fa-solid fa-save"></i> ${isEdit ? 'Zapisz zmiany' : 'Utwórz kanał'}
                </button>
            </div>
        </form>`;

        const typeSel = wrap.querySelector('#nd-ch-type');
        const provSel = wrap.querySelector('#nd-ch-provider');
        const credTa  = wrap.querySelector('#nd-cred');

        /** Dopasuj provider + szablon credentials do typu kanału (unikaj np. personal_phone + smtp). */
        function syncProviderFromChannelType() {
            const map = { email: 'smtp', personal_phone: 'smsgateway_android', in_app: 'sse', sms_gateway: 'smsapi_pl' };
            const t = typeSel.value;
            const p = map[t] || 'smtp';
            provSel.value = p;
            if (!isEdit) {
                credTa.value = PROVIDER_CRED_TEMPLATES[p] || '{}';
            }
        }

        typeSel.addEventListener('change', syncProviderFromChannelType);

        // Dynamic: provider template button
        wrap.querySelector('#nd-cred-template').addEventListener('click', () => {
            const prov = provSel.value;
            credTa.value = PROVIDER_CRED_TEMPLATES[prov] || '{}';
        });

        openModal(wrap);
        if (!isEdit) {
            syncProviderFromChannelType();
        }
        wrap.querySelector('#nd-ch-cancel').addEventListener('click', closeModal);

        wrap.querySelector('#nd-ch-form').addEventListener('submit', async ev => {
            ev.preventDefault();
            const fd = new FormData(ev.target);
            const credRaw = fd.get('credentials').trim();
            let cred = null;
            if (credRaw && credRaw !== '{}' && credRaw !== '(pozostaw puste = bez zmian)') {
                try { cred = JSON.parse(credRaw); }
                catch (_) { toast('Nieprawidłowy JSON w Credentials!', false); return; }
            }
            const chType = fd.get('channel_type');
            const map = { email: 'smtp', personal_phone: 'smsgateway_android', in_app: 'sse', sms_gateway: 'smsapi_pl' };
            let provider = fd.get('provider');
            if (map[chType] && provider === 'smtp' && chType !== 'email') {
                provider = map[chType];
            }
            const payload = {
                id: ch?.id || 0,
                name: fd.get('name'),
                channel_type: chType,
                provider,
                priority: fd.get('priority'),
                rate_limit_per_hour: fd.get('rate_limit_per_hour') || null,
                rate_limit_per_day:  fd.get('rate_limit_per_day')  || null,
                is_active: fd.has('is_active') ? 1 : 0,
            };
            if (cred !== null) payload.credentials = cred;
            const r = await api('notifications_channels_upsert', payload);
            toast(r.message || (r.success ? '✓ Zapisano' : '✗ Błąd'), r.success);
            if (r.success) { closeModal(); loadChannels(container); }
        });
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. ROUTING
    // ══════════════════════════════════════════════════════════════════════════

    async function loadRouting(container) {
        const pane = container.querySelector('#nd-pane-routing');
        pane.innerHTML = spinner();

        const [rRes, cRes] = await Promise.all([
            api('notifications_routes_get'),
            api('notifications_channels_list'),
        ]);
        const routes   = rRes.data || [];
        const channels = cRes.data || [];

        if (!channels.length) {
            pane.innerHTML = emptyState('fa-tower-broadcast', 'Brak kanałów. Najpierw dodaj kanał w zakładce "Kanały".');
            return;
        }

        pane.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <div>
                <h3 style="margin:0;font-size:15px;font-weight:700">Routing: zdarzenie → kanał</h3>
                <p style="margin:2px 0 0;color:var(--muted);font-size:12px">Kliknij zdarzenie aby rozwinąć i skonfigurować kanały. Fallback 0 = primary.</p>
            </div>
            <button class="st-btn st-btn--primary" id="nd-save-routing">
                <i class="fa-solid fa-save"></i> Zapisz wszystko
            </button>
        </div>
        <div id="nd-rt-matrix"></div>`;

        const matrix = pane.querySelector('#nd-rt-matrix');
        matrix.innerHTML = EVENT_TYPES.map(et => {
            const eventRoutes = routes.filter(r => r.event_type === et.key);
            const activeCount = eventRoutes.filter(r => r.is_active).length;
            const chHtml = channels.map(ch => {
                const route = eventRoutes.find(r => r.channel_id == ch.id);
                const active = route?.is_active;
                const fo   = route?.fallback_order ?? 0;
                const rsms = route?.requires_sms_consent ?? (et.key !== 'marketing.campaign' ? 0 : 0);
                const rmkt = route?.requires_marketing_consent ?? (et.key === 'marketing.campaign' ? 1 : 0);
                return `
                <div class="nd-rt-ch-toggle ${active ? 'is-active' : ''}" id="rttog-${et.key.replace(/\./g,'_')}-${ch.id}">
                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-weight:600;font-size:13px">
                        <input type="checkbox" name="rt_active" data-event="${esc(et.key)}" data-ch="${ch.id}" ${active?'checked':''}
                            style="width:14px;height:14px;accent-color:var(--accent);cursor:pointer">
                        <i class="fa-solid ${CH_ICONS[ch.channel_type]||'fa-bell'}" style="color:${CH_COLORS[ch.channel_type]||'#8a93a6'}"></i>
                        ${esc(ch.name)}
                    </label>
                    <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted)">
                        <label style="display:flex;align-items:center;gap:4px;cursor:pointer">
                            Fallback:
                            <input type="number" name="rt_fo" data-event="${esc(et.key)}" data-ch="${ch.id}"
                                value="${fo}" min="0" max="9"
                                style="width:38px;padding:2px 5px;background:var(--panel-3);border:1px solid var(--border);border-radius:4px;color:var(--text);font-size:12px">
                        </label>
                    </div>
                    <div style="display:flex;gap:12px;font-size:11px;color:var(--muted)">
                        <label style="display:flex;align-items:center;gap:4px;cursor:pointer">
                            <input type="checkbox" name="rt_rsms" data-event="${esc(et.key)}" data-ch="${ch.id}"
                                ${rsms?'checked':''} style="accent-color:var(--accent)"> Zgoda SMS
                        </label>
                        <label style="display:flex;align-items:center;gap:4px;cursor:pointer">
                            <input type="checkbox" name="rt_rmkt" data-event="${esc(et.key)}" data-ch="${ch.id}"
                                ${rmkt?'checked':''} style="accent-color:var(--accent)"> Zgoda MKT
                        </label>
                    </div>
                </div>`;
            }).join('');

            return `
            <div class="nd-rt-row" id="ndrt-${et.key.replace(/\./g,'_')}">
                <div class="nd-rt-event">
                    <i class="fa-solid ${et.icon}" style="color:${et.color};width:18px;text-align:center"></i>
                    <span class="nd-rt-event-label">${esc(et.label)} <code style="font-size:11px;color:var(--muted);font-family:var(--mono)">${esc(et.key)}</code></span>
                    <span class="nd-rt-event-count">${activeCount ? `<span style="color:var(--ok)">${activeCount} aktywnych</span>` : '<span style="color:var(--muted)">brak</span>'}</span>
                    <i class="fa-solid fa-chevron-down" style="color:var(--muted);font-size:11px;transition:transform .15s"></i>
                </div>
                <div class="nd-rt-channels">${chHtml}</div>
            </div>`;
        }).join('');

        // Toggle rows
        matrix.querySelectorAll('.nd-rt-event').forEach(hd => {
            hd.addEventListener('click', () => {
                const row = hd.closest('.nd-rt-row');
                row.classList.toggle('open');
                hd.querySelector('.fa-chevron-down').style.transform = row.classList.contains('open') ? 'rotate(180deg)' : '';
            });
        });

        // Live toggle active class on checkbox change
        matrix.querySelectorAll('[name="rt_active"]').forEach(cb => {
            cb.addEventListener('change', () => {
                const et = cb.dataset.event.replace(/\./g,'_');
                const ch = cb.dataset.ch;
                const tog = document.getElementById(`rttog-${et}-${ch}`);
                if (tog) tog.classList.toggle('is-active', cb.checked);
            });
        });

        pane.querySelector('#nd-save-routing').addEventListener('click', async btn => {
            const saveBtn = pane.querySelector('#nd-save-routing');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Zapisuję…';
            const routeData = [];
            matrix.querySelectorAll('[name="rt_active"]').forEach(cb => {
                const et = cb.dataset.event;
                const ch = +cb.dataset.ch;
                const fo   = +(matrix.querySelector(`[name="rt_fo"][data-event="${et}"][data-ch="${ch}"]`)?.value || 0);
                const rsms = +(matrix.querySelector(`[name="rt_rsms"][data-event="${et}"][data-ch="${ch}"]`)?.checked || 0);
                const rmkt = +(matrix.querySelector(`[name="rt_rmkt"][data-event="${et}"][data-ch="${ch}"]`)?.checked || 0);
                routeData.push({ event_type: et, channel_id: ch, fallback_order: fo,
                    requires_sms_consent: rsms, requires_marketing_consent: rmkt,
                    is_active: cb.checked ? 1 : 0 });
            });
            const r = await api('notifications_routes_set', { routes: routeData });
            toast(r.message || (r.success ? '✓ Routing zapisany!' : '✗ Błąd'), r.success);
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fa-solid fa-save"></i> Zapisz wszystko';
        });
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. SZABLONY
    // ══════════════════════════════════════════════════════════════════════════

    async function loadTemplates(container) {
        const pane = container.querySelector('#nd-pane-templates');
        pane.innerHTML = spinner();

        const res = await api('notifications_templates_get');
        const tpls = res.data || [];
        const chTypes = ['email', 'personal_phone', 'sms_gateway', 'in_app'];

        pane.innerHTML = `
        <div style="margin-bottom:16px">
            <h3 style="margin:0 0 4px;font-size:15px;font-weight:700">Szablony wiadomości</h3>
            <p style="margin:0;color:var(--muted);font-size:12px">
                Dostępne zmienne: ${TPL_VARS.map(v => `<code style="background:var(--panel-2);padding:1px 5px;border-radius:4px;font-family:var(--mono);font-size:11px;color:var(--accent)">${esc(v)}</code>`).join(' ')}
            </p>
        </div>
        <div id="nd-tpl-list"></div>`;

        const list = pane.querySelector('#nd-tpl-list');

        EVENT_TYPES.forEach(et => {
            const row = document.createElement('div');
            row.className = 'nd-tpl-event';
            const tplsForEvent = tpls.filter(t => t.event_type === et.key);
            const configured = chTypes.filter(ct => tplsForEvent.some(t => t.channel_type === ct && t.body)).length;

            row.innerHTML = `
            <div class="nd-tpl-hd">
                <i class="fa-solid ${et.icon}" style="color:${et.color};width:18px;text-align:center"></i>
                <span style="flex:1;font-weight:600;font-size:13px">
                    ${esc(et.label)}
                    <code style="font-size:11px;color:var(--muted);font-family:var(--mono)">${esc(et.key)}</code>
                </span>
                <span style="font-size:12px;color:${configured ? 'var(--ok)' : 'var(--muted)'}">
                    ${configured}/${chTypes.length} szablonów
                </span>
                <i class="fa-solid fa-chevron-down" style="color:var(--muted);font-size:11px;margin-left:8px;transition:transform .15s"></i>
            </div>
            <div class="nd-tpl-body"></div>`;

            row.querySelector('.nd-tpl-hd').addEventListener('click', () => {
                row.classList.toggle('open');
                row.querySelector('.fa-chevron-down').style.transform = row.classList.contains('open') ? 'rotate(180deg)' : '';
            });

            const body = row.querySelector('.nd-tpl-body');
            chTypes.forEach(ct => {
                const tpl = tplsForEvent.find(t => t.channel_type === ct) || null;
                const card = document.createElement('div');
                card.className = 'nd-tpl-card';
                const hasBody = !!tpl?.body;
                card.innerHTML = `
                <div class="nd-tpl-card-head">
                    <span class="nd-tpl-card-label">
                        <i class="fa-solid ${CH_ICONS[ct]||'fa-bell'}" style="color:${CH_COLORS[ct]||'#8a93a6'}"></i>
                        ${esc(CH_LABELS[ct]||ct)}
                    </span>
                    <span class="nd-chip ${hasBody ? 'nd-chip--ok' : ''}">${hasBody ? 'Skonfigurowany' : 'Brak'}</span>
                </div>
                ${ct === 'email' ? `
                    <input type="text" placeholder="Temat emaila…" value="${esc(tpl?.subject||'')}"
                        class="tpl-subject st-field__input"
                        style="margin-bottom:6px;font-size:12px">` : ''}
                <textarea class="tpl-body st-field__input" rows="${ct==='email'?6:3}"
                    style="font-family:var(--mono,monospace);font-size:12px;resize:vertical"
                    placeholder="Treść wiadomości… (puste = wyłączone)">${esc(tpl?.body||'')}</textarea>
                <div style="display:flex;justify-content:flex-end;margin-top:8px">
                    <button class="st-btn st-btn--primary st-btn--sm tpl-save"
                        data-id="${tpl?.id||0}" data-et="${esc(et.key)}" data-ct="${esc(ct)}">
                        <i class="fa-solid fa-save"></i> Zapisz
                    </button>
                </div>`;

                card.querySelector('.tpl-save').addEventListener('click', async ev => {
                    const btn = ev.currentTarget;
                    const bodyVal = card.querySelector('.tpl-body').value.trim();
                    const subj    = card.querySelector('.tpl-subject')?.value.trim() || '';
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                    const r = await api('notifications_templates_set', {
                        id: +btn.dataset.id, event_type: btn.dataset.et,
                        channel_type: btn.dataset.ct, lang: 'pl',
                        subject: subj, body: bodyVal, is_active: bodyVal ? 1 : 0,
                    });
                    toast(r.message || (r.success ? '✓ Szablon zapisany!' : '✗ Błąd'), r.success);
                    if (r.success && r.data?.id) {
                        btn.dataset.id = r.data.id;
                        const badge = card.querySelector('.nd-chip');
                        if (badge) {
                            badge.textContent = bodyVal ? 'Skonfigurowany' : 'Brak';
                            badge.className = `nd-chip ${bodyVal ? 'nd-chip--ok' : ''}`;
                        }
                    }
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-save"></i> Zapisz';
                });

                body.appendChild(card);
            });

            list.appendChild(row);
        });
    }

    // ── Eksport ───────────────────────────────────────────────────────────────
    window.NotificationsTab = { render: renderNotifications };

})();
