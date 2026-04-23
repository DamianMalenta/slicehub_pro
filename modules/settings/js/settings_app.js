/**
 * SliceHub Settings Panel — single-file mini SPA (Sesja 7.5).
 *
 * Zakładki:
 *   • Integrations (sh_tenant_integrations)    — CRUD + Test Ping
 *   • Webhooks     (sh_webhook_endpoints)       — CRUD + rotate_secret + Test Ping
 *   • API Keys     (sh_gateway_api_keys)        — list + generate + revoke
 *   • Dead Letters (outbox DLQ + integration DLQ) — list + replay
 *   • Health       — vault status, endpoint counts, stats
 *
 * Bez frameworków (CSP compliance + rule book).
 */
(() => {
    'use strict';

    // ─── API client ──────────────────────────────────────────────────────
    const API_URL = '../../api/settings/engine.php';

    // Akcje read-only — backend (settings_csrfCheck) nie wymaga od nich
    // headera X-CSRF-Token. Trzymamy to zsynchronizowane z listą w engine.php.
    const CSRF_READONLY = new Set([
        'csrf_token',
        'integrations_list', 'webhooks_list', 'api_keys_list',
        'dlq_list', 'inbound_list', 'health_summary',
        'notifications_channels_list', 'notifications_routes_get',
        'notifications_templates_get',
    ]);

    let _csrfToken = null;

    async function fetchCsrfToken() {
        const resp = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ action: 'csrf_token' }),
        });
        const data = await resp.json();
        if (!resp.ok || data.success === false) {
            throw new Error(data?.message || `CSRF bootstrap failed (HTTP ${resp.status})`);
        }
        _csrfToken = data.data?.token || null;
        return _csrfToken;
    }

    async function ensureCsrfToken() {
        if (_csrfToken) return _csrfToken;
        return fetchCsrfToken();
    }

    async function callApi(action, payload = {}) {
        const body = Object.assign({ action }, payload);
        const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };

        if (!CSRF_READONLY.has(action)) {
            try {
                const tok = await ensureCsrfToken();
                if (tok) headers['X-CSRF-Token'] = tok;
            } catch (e) {
                console.warn('[settings] CSRF bootstrap failed, mutation may 403', e);
            }
        }

        const doFetch = async () => fetch(API_URL, {
            method: 'POST',
            headers,
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });

        try {
            let resp = await doFetch();
            let data = await resp.json();

            // Retry raz, jeśli token wygasł/sesja wymieniona (403 z komunikatem CSRF).
            const csrfFail = resp.status === 403
                && !CSRF_READONLY.has(action)
                && typeof data?.message === 'string'
                && /csrf/i.test(data.message);
            if (csrfFail) {
                _csrfToken = null;
                const freshTok = await fetchCsrfToken();
                if (freshTok) {
                    headers['X-CSRF-Token'] = freshTok;
                    resp = await doFetch();
                    data = await resp.json();
                }
            }

            if (!resp.ok || data.success === false) {
                const err = new Error(data.message || `HTTP ${resp.status}`);
                err.httpCode = resp.status;
                err.data = data;
                throw err;
            }
            return data.data;
        } catch (e) {
            console.error('[settings] API error', action, e);
            throw e;
        }
    }

    // ─── DOM helpers ────────────────────────────────────────────────────
    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

    const el = (tag, props = {}, ...children) => {
        const n = document.createElement(tag);
        for (const [k, v] of Object.entries(props)) {
            if (k === 'class') n.className = v;
            else if (k === 'html') n.innerHTML = v;
            else if (k.startsWith('on') && typeof v === 'function') n.addEventListener(k.slice(2).toLowerCase(), v);
            else if (k === 'dataset') Object.assign(n.dataset, v);
            else if (v !== null && v !== undefined) n.setAttribute(k, v);
        }
        for (const c of children.flat()) {
            if (c === null || c === undefined) continue;
            n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        }
        return n;
    };

    const escHtml = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));

    const fmtDate = (iso) => {
        if (!iso) return '—';
        try { return new Date(iso.replace(' ', 'T') + 'Z').toLocaleString('pl-PL'); }
        catch { return iso; }
    };

    // ─── Toast ──────────────────────────────────────────────────────────
    function toast(msg, kind = 'info', timeoutMs = 4000) {
        const root = $('#st-toast-root');
        const t = el('div', { class: `st-toast st-toast--${kind}` }, msg);
        root.appendChild(t);
        setTimeout(() => { t.style.transition = 'opacity .3s'; t.style.opacity = '0'; }, timeoutMs - 300);
        setTimeout(() => t.remove(), timeoutMs);
    }

    // ─── Modal ──────────────────────────────────────────────────────────
    function openModal(contentNode) {
        const root = $('#st-modal-root');
        root.innerHTML = '';
        root.appendChild(contentNode);
        root.classList.add('st-modal-root--open');
        root.addEventListener('click', (e) => { if (e.target === root) closeModal(); }, { once: true });
    }
    function closeModal() {
        const root = $('#st-modal-root');
        root.classList.remove('st-modal-root--open');
        setTimeout(() => root.innerHTML = '', 250);
    }

    // ─── State ──────────────────────────────────────────────────────────
    const state = {
        activeTab: 'integrations',
        vaultReady: null,
    };

    // ─── Tab switching ──────────────────────────────────────────────────
    function switchTab(name) {
        state.activeTab = name;
        $$('.st-tab').forEach(t => t.classList.toggle('st-tab--active', t.dataset.tab === name));
        $$('.st-pane').forEach(p => p.classList.toggle('st-pane--active', p.id === `st-pane-${name}`));
        loadTab(name);
    }

    async function loadTab(name) {
        switch (name) {
            case 'integrations':  return renderIntegrations();
            case 'webhooks':      return renderWebhooks();
            case 'api_keys':      return renderApiKeys();
            case 'dlq':           return renderDlq();
            case 'inbound':       return renderInbound();
            case 'health':        return renderHealth();
            case 'notifications': return renderNotificationsPane();
        }
    }

    function renderNotificationsPane() {
        const pane = $('#st-pane-notifications');
        if (!pane) return;
        if (window.NotificationsTab) {
            window.NotificationsTab.render(pane);
        } else {
            pane.innerHTML = '<p class="st-empty">Ładowanie modułu powiadomień…</p>';
            const script = document.createElement('script');
            script.src = 'js/notifications.js';
            script.onload = () => {
                if (window.NotificationsTab) window.NotificationsTab.render(pane);
            };
            document.head.appendChild(script);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // INTEGRATIONS PANE
    // ═══════════════════════════════════════════════════════════════════

    async function renderIntegrations() {
        const pane = $('#st-pane-integrations');
        pane.innerHTML = '<div class="st-empty"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading…</p></div>';

        try {
            const data = await callApi('integrations_list');
            updateVaultBadge(data.vault_ready);

            pane.innerHTML = '';
            pane.appendChild(el('div', { class: 'st-section-head' },
                el('h2', {}, 'Integration Adapters'),
                el('span', { class: 'st-subtitle' }, '3rd-party POS/delivery providers (async push)'),
                el('button', { class: 'st-btn st-btn--primary', onClick: () => openIntegrationEditor(null, data.available_providers) },
                    el('i', { class: 'fa-solid fa-plus' }), ' Add Integration')
            ));

            if (!data.integrations.length) {
                pane.appendChild(el('div', { class: 'st-empty' },
                    el('i', { class: 'fa-solid fa-plug' }),
                    el('p', {}, 'No integrations configured yet.'),
                    el('p', { class: 'st-subtitle' }, 'Click "Add Integration" to connect your first 3rd-party POS.')
                ));
                return;
            }

            data.integrations.forEach(int => {
                pane.appendChild(renderIntegrationCard(int, data.available_providers));
            });
        } catch (e) {
            pane.innerHTML = '';
            pane.appendChild(el('div', { class: 'st-empty' },
                el('i', { class: 'fa-solid fa-triangle-exclamation' }),
                el('p', {}, 'Failed to load: ' + e.message)
            ));
        }
    }

    function renderIntegrationCard(int, providers) {
        const providerLabel = providers[int.provider] || int.provider;
        const statusChip = int.is_active == 1
            ? el('span', { class: 'st-chip st-chip--ok' }, 'active')
            : el('span', { class: 'st-chip st-chip--err' }, 'paused');

        const failChip = (int.consecutive_failures || 0) > 0
            ? el('span', { class: 'st-chip st-chip--warn' }, `${int.consecutive_failures} fails`)
            : null;

        const events = Array.isArray(int.events_bridged)
            ? int.events_bridged
            : (JSON.parse(int.events_bridged || '[]') || []);

        return el('div', { class: 'st-card' },
            el('div', { class: 'st-card__main' },
                el('h3', {},
                    el('i', { class: 'fa-solid fa-plug' }),
                    ' ' + int.display_name,
                    statusChip,
                    failChip
                ),
                el('div', { class: 'st-meta', html:
                    `<b>${escHtml(providerLabel)}</b> · <code>${escHtml(int.api_base_url || 'no base url')}</code><br>` +
                    `Events: ${events.map(e => `<span class="st-chip">${escHtml(e)}</span>`).join('')}<br>` +
                    `Credentials: <code>${escHtml(int.credentials_redacted || 'none')}</code> ` +
                    (int.credentials_encrypted ? '<span class="st-chip st-chip--ok">encrypted</span>' : '<span class="st-chip st-chip--warn">plaintext</span>') +
                    `<br>Direction: <code>${escHtml(int.direction)}</code> · Timeout: ${int.timeout_seconds}s · Max retries: ${int.max_retries}` +
                    (int.last_sync_at ? `<br>Last sync: ${fmtDate(int.last_sync_at)}` : '')
                })
            ),
            el('div', { class: 'st-card__actions' },
                el('button', { class: 'st-btn st-btn--sm', onClick: () => testIntegrationPing(int.id) },
                    el('i', { class: 'fa-solid fa-wave-square' }), ' Test'),
                el('button', { class: 'st-btn st-btn--sm', onClick: () => toggleIntegration(int.id, !int.is_active) },
                    el('i', { class: `fa-solid fa-${int.is_active == 1 ? 'pause' : 'play'}` }),
                    int.is_active == 1 ? ' Pause' : ' Enable'),
                el('button', { class: 'st-btn st-btn--sm', onClick: () => openIntegrationEditor(int, providers) },
                    el('i', { class: 'fa-solid fa-pen' }), ' Edit'),
                el('button', { class: 'st-btn st-btn--sm st-btn--danger', onClick: () => deleteIntegration(int.id, int.display_name) },
                    el('i', { class: 'fa-solid fa-trash' }))
            )
        );
    }

    function openIntegrationEditor(integration, providers) {
        const isEdit = !!integration;
        const modal = el('div', { class: 'st-modal' },
            el('h3', {}, isEdit ? `Edit: ${integration.display_name}` : 'Add Integration')
        );

        const providerOpts = Object.entries(providers).map(([key, label]) =>
            el('option', { value: key, selected: isEdit && integration.provider === key ? '' : null }, label)
        );
        // pozwól też "custom" i "webhook" dla managerów którzy wiedzą co robią
        providerOpts.push(el('option', { value: 'custom' }, 'Custom (manual)'));

        const providerInput = el('select', { id: 'fld-provider' }, providerOpts);
        const nameInput = el('input', { type: 'text', id: 'fld-name', value: integration?.display_name || '' });
        const urlInput = el('input', { type: 'url', id: 'fld-url', value: integration?.api_base_url || '' });
        const dirSelect = el('select', { id: 'fld-direction' },
            ['push', 'pull', 'bidirectional'].map(d =>
                el('option', { value: d, selected: integration?.direction === d ? '' : null }, d))
        );
        const eventsInput = el('input', { type: 'text', id: 'fld-events', value:
            (Array.isArray(integration?.events_bridged) ? integration.events_bridged : JSON.parse(integration?.events_bridged || '["order.created"]')).join(', ')
        });
        const credsInput = el('textarea', { id: 'fld-creds', placeholder: '{"api_key": "xxx", "cloud_id": "…"}' });
        const timeoutInput = el('input', { type: 'number', min: '1', max: '30', id: 'fld-timeout', value: integration?.timeout_seconds || 8 });
        const retriesInput = el('input', { type: 'number', min: '1', max: '20', id: 'fld-retries', value: integration?.max_retries || 6 });
        const activeInput = el('input', { type: 'checkbox', id: 'fld-active' });
        activeInput.checked = integration ? integration.is_active == 1 : true;

        modal.appendChild(el('div', { class: 'st-field' },
            el('label', {}, 'Provider'),
            providerInput,
            el('div', { class: 'st-field__hint' }, 'Koniecznie w PROVIDER_MAP zarejestrowany (AdapterRegistry.php).')
        ));
        modal.appendChild(el('div', { class: 'st-field' },
            el('label', {}, 'Display Name'),
            nameInput
        ));
        modal.appendChild(el('div', { class: 'st-field' },
            el('label', {}, 'API Base URL'),
            urlInput,
            el('div', { class: 'st-field__hint' }, 'np. https://api.papu.io/v1 (sandbox: dev-api.papu.io/v1)')
        ));
        modal.appendChild(el('div', { class: 'st-grid-2' },
            el('div', { class: 'st-field' }, el('label', {}, 'Direction'), dirSelect),
            el('div', { class: 'st-field' }, el('label', {}, 'Events (comma-separated)'), eventsInput)
        ));
        modal.appendChild(el('div', { class: 'st-field' },
            el('label', {}, 'Credentials (JSON)'),
            credsInput,
            el('div', { class: 'st-field__hint' }, isEdit
                ? 'Zostaw PUSTE aby zachować bieżące credentials. Wpisz nowy JSON aby zastąpić. Zapisywane jako vault:v1:… (XChaCha20-Poly1305).'
                : 'np. {"api_key":"pk_live_xxx","tenant_ext":"restaurant_42"}. Szyfrowane przy zapisie.'
            )
        ));
        modal.appendChild(el('div', { class: 'st-grid-2' },
            el('div', { class: 'st-field' }, el('label', {}, 'Timeout (s)'), timeoutInput),
            el('div', { class: 'st-field' }, el('label', {}, 'Max Retries'),  retriesInput)
        ));
        modal.appendChild(el('div', { class: 'st-field st-field--check' },
            activeInput,
            el('label', { for: 'fld-active' }, 'Active — worker pushuje eventy do tego providera')
        ));

        modal.appendChild(el('div', { class: 'st-modal__footer' },
            el('button', { class: 'st-btn', onClick: closeModal }, 'Cancel'),
            el('button', { class: 'st-btn st-btn--primary', onClick: async () => {
                const credsRaw = credsInput.value.trim();
                let credsPayload = undefined;
                if (credsRaw !== '') {
                    try { credsPayload = JSON.parse(credsRaw); }
                    catch (e) { toast('Credentials JSON invalid: ' + e.message, 'err'); return; }
                }
                const payload = {
                    id: integration?.id,
                    provider: providerInput.value,
                    display_name: nameInput.value.trim(),
                    api_base_url: urlInput.value.trim(),
                    direction: dirSelect.value,
                    events_bridged: eventsInput.value.split(',').map(s => s.trim()).filter(Boolean),
                    timeout_seconds: parseInt(timeoutInput.value, 10),
                    max_retries: parseInt(retriesInput.value, 10),
                    is_active: activeInput.checked ? 1 : 0,
                };
                if (credsPayload !== undefined) payload.credentials = credsPayload;

                try {
                    await callApi('integrations_save', payload);
                    toast(isEdit ? 'Integration updated.' : 'Integration created.', 'ok');
                    closeModal();
                    renderIntegrations();
                } catch (e) { toast(e.message, 'err'); }
            }}, 'Save')
        ));

        openModal(modal);
    }

    async function toggleIntegration(id, active) {
        try {
            await callApi('integrations_toggle', { id, active: active ? 1 : 0 });
            toast(active ? 'Enabled.' : 'Paused.', 'ok');
            renderIntegrations();
        } catch (e) { toast(e.message, 'err'); }
    }

    async function deleteIntegration(id, name) {
        if (!confirm(`Delete integration "${name}"? This is permanent.`)) return;
        try {
            await callApi('integrations_delete', { id });
            toast('Deleted.', 'ok');
            renderIntegrations();
        } catch (e) { toast(e.message, 'err'); }
    }

    async function testIntegrationPing(id) {
        const modal = el('div', { class: 'st-modal' },
            el('h3', {}, 'Test Ping — in progress…'),
            el('div', { class: 'st-ping-result' },
                el('i', { class: 'fa-solid fa-spinner fa-spin' }), ' Sending synthetic order.created event…')
        );
        openModal(modal);

        try {
            const report = await callApi('integrations_test_ping', { id });
            modal.innerHTML = '';
            modal.appendChild(el('h3', {}, report.ok ? '✅ Test Passed' : '❌ Test Failed'));
            modal.appendChild(el('div', { class: 'st-ping-result st-ping-result--' + (report.ok ? 'ok' : 'err') },
                JSON.stringify(report, null, 2)
            ));
            modal.appendChild(el('div', { class: 'st-modal__footer' },
                el('button', { class: 'st-btn', onClick: closeModal }, 'Close')
            ));
        } catch (e) {
            modal.innerHTML = '';
            modal.appendChild(el('h3', {}, '❌ Error'));
            modal.appendChild(el('div', { class: 'st-ping-result st-ping-result--err' }, e.message));
            modal.appendChild(el('div', { class: 'st-modal__footer' }, el('button', { class: 'st-btn', onClick: closeModal }, 'Close')));
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // WEBHOOKS PANE
    // ═══════════════════════════════════════════════════════════════════

    async function renderWebhooks() {
        const pane = $('#st-pane-webhooks');
        pane.innerHTML = '<div class="st-empty"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading…</p></div>';

        try {
            const data = await callApi('webhooks_list');
            updateVaultBadge(data.vault_ready);

            pane.innerHTML = '';
            pane.appendChild(el('div', { class: 'st-section-head' },
                el('h2', {}, 'Webhook Endpoints'),
                el('span', { class: 'st-subtitle' }, 'Generic HTTP POST (HMAC-SHA256 signed)'),
                el('button', { class: 'st-btn st-btn--primary', onClick: () => openWebhookEditor(null) },
                    el('i', { class: 'fa-solid fa-plus' }), ' Add Endpoint')
            ));

            if (!data.endpoints.length) {
                pane.appendChild(el('div', { class: 'st-empty' },
                    el('i', { class: 'fa-solid fa-satellite-dish' }),
                    el('p', {}, 'No webhook endpoints configured.'),
                    el('p', { class: 'st-subtitle' }, 'Generic HTTP webhooks for Slack, Zapier, analytics, custom systems.')
                ));
                return;
            }

            data.endpoints.forEach(wh => pane.appendChild(renderWebhookCard(wh)));
        } catch (e) {
            pane.innerHTML = '';
            pane.appendChild(el('div', { class: 'st-empty' },
                el('i', { class: 'fa-solid fa-triangle-exclamation' }),
                el('p', {}, 'Failed to load: ' + e.message)
            ));
        }
    }

    function renderWebhookCard(wh) {
        const events = Array.isArray(wh.events_subscribed)
            ? wh.events_subscribed
            : JSON.parse(wh.events_subscribed || '["*"]');
        const statusChip = wh.is_active == 1
            ? el('span', { class: 'st-chip st-chip--ok' }, 'active')
            : el('span', { class: 'st-chip st-chip--err' }, 'paused');
        const failChip = (wh.consecutive_failures || 0) > 0
            ? el('span', { class: 'st-chip st-chip--warn' }, `${wh.consecutive_failures} fails`)
            : null;

        return el('div', { class: 'st-card' },
            el('div', { class: 'st-card__main' },
                el('h3', {},
                    el('i', { class: 'fa-solid fa-satellite-dish' }),
                    ' ' + wh.name, statusChip, failChip),
                el('div', { class: 'st-meta', html:
                    `<code>${escHtml(wh.url)}</code><br>` +
                    `Events: ${events.map(e => `<span class="st-chip">${escHtml(e)}</span>`).join('')}<br>` +
                    `Secret: <code>${escHtml(wh.secret_redacted)}</code> ` +
                    (wh.secret_encrypted ? '<span class="st-chip st-chip--ok">encrypted</span>' : '<span class="st-chip st-chip--warn">plaintext</span>') +
                    `<br>Timeout: ${wh.timeout_seconds}s · Max retries: ${wh.max_retries}` +
                    (wh.last_success_at ? `<br>Last success: ${fmtDate(wh.last_success_at)}` : '') +
                    (wh.last_failure_at ? `<br>Last failure: ${fmtDate(wh.last_failure_at)}` : '')
                })
            ),
            el('div', { class: 'st-card__actions' },
                el('button', { class: 'st-btn st-btn--sm', onClick: () => testWebhookPing(wh.id) },
                    el('i', { class: 'fa-solid fa-wave-square' }), ' Test'),
                el('button', { class: 'st-btn st-btn--sm', onClick: () => toggleWebhook(wh.id, !wh.is_active) },
                    el('i', { class: `fa-solid fa-${wh.is_active == 1 ? 'pause' : 'play'}` }),
                    wh.is_active == 1 ? ' Pause' : ' Enable'),
                el('button', { class: 'st-btn st-btn--sm', onClick: () => openWebhookEditor(wh) },
                    el('i', { class: 'fa-solid fa-pen' }), ' Edit'),
                el('button', { class: 'st-btn st-btn--sm st-btn--danger', onClick: () => deleteWebhook(wh.id, wh.name) },
                    el('i', { class: 'fa-solid fa-trash' }))
            )
        );
    }

    function openWebhookEditor(wh) {
        const isEdit = !!wh;
        const modal = el('div', { class: 'st-modal' });

        const nameInput = el('input', { type: 'text', id: 'wh-name', value: wh?.name || '' });
        const urlInput = el('input', { type: 'url', id: 'wh-url', value: wh?.url || '' });
        const events = Array.isArray(wh?.events_subscribed) ? wh.events_subscribed : JSON.parse(wh?.events_subscribed || '["*"]');
        const eventsInput = el('input', { type: 'text', id: 'wh-events', value: events.join(', ') });
        const timeoutInput = el('input', { type: 'number', min: '1', max: '30', id: 'wh-timeout', value: wh?.timeout_seconds || 5 });
        const retriesInput = el('input', { type: 'number', min: '1', max: '20', id: 'wh-retries', value: wh?.max_retries || 5 });
        const activeInput = el('input', { type: 'checkbox', id: 'wh-active' });
        activeInput.checked = wh ? wh.is_active == 1 : true;
        const rotateInput = el('input', { type: 'checkbox', id: 'wh-rotate' });

        modal.appendChild(el('h3', {}, isEdit ? `Edit: ${wh.name}` : 'Add Webhook Endpoint'));
        modal.appendChild(el('div', { class: 'st-field' }, el('label', {}, 'Name'), nameInput));
        modal.appendChild(el('div', { class: 'st-field' },
            el('label', {}, 'URL'),
            urlInput,
            el('div', { class: 'st-field__hint' }, 'Public HTTPS endpoint which will receive POST signed by HMAC-SHA256.')
        ));
        modal.appendChild(el('div', { class: 'st-field' },
            el('label', {}, 'Events Subscribed'),
            eventsInput,
            el('div', { class: 'st-field__hint' }, 'Comma-separated list, np. "order.created, order.ready". Użyj "*" dla wszystkich.')
        ));
        modal.appendChild(el('div', { class: 'st-grid-2' },
            el('div', { class: 'st-field' }, el('label', {}, 'Timeout (s)'), timeoutInput),
            el('div', { class: 'st-field' }, el('label', {}, 'Max Retries'),  retriesInput)
        ));
        modal.appendChild(el('div', { class: 'st-field st-field--check' },
            activeInput, el('label', { for: 'wh-active' }, 'Active')
        ));
        if (isEdit) {
            modal.appendChild(el('div', { class: 'st-field st-field--check' },
                rotateInput, el('label', { for: 'wh-rotate' }, '🔄 Rotate secret (invaliduje stary secret!)')
            ));
        }

        modal.appendChild(el('div', { class: 'st-modal__footer' },
            el('button', { class: 'st-btn', onClick: closeModal }, 'Cancel'),
            el('button', { class: 'st-btn st-btn--primary', onClick: async () => {
                const payload = {
                    id: wh?.id,
                    name: nameInput.value.trim(),
                    url: urlInput.value.trim(),
                    events_subscribed: eventsInput.value.split(',').map(s => s.trim()).filter(Boolean),
                    timeout_seconds: parseInt(timeoutInput.value, 10),
                    max_retries: parseInt(retriesInput.value, 10),
                    is_active: activeInput.checked ? 1 : 0,
                    rotate_secret: isEdit ? rotateInput.checked : false,
                };
                try {
                    const result = await callApi('webhooks_save', payload);
                    toast((result.updated ? 'Updated.' : 'Created.'), 'ok');
                    closeModal();
                    if (result.new_secret) {
                        showRevealSecret('Webhook secret', result.new_secret,
                            'This secret is used to sign outgoing webhooks (X-Slicehub-Signature). ' +
                            'Copy it NOW — it will not be shown again. Subscribers must use this value to verify HMAC.');
                    }
                    renderWebhooks();
                } catch (e) { toast(e.message, 'err'); }
            }}, isEdit ? 'Save' : 'Create')
        ));

        openModal(modal);
    }

    async function toggleWebhook(id, active) {
        try {
            await callApi('webhooks_toggle', { id, active: active ? 1 : 0 });
            toast(active ? 'Enabled.' : 'Paused.', 'ok');
            renderWebhooks();
        } catch (e) { toast(e.message, 'err'); }
    }

    async function deleteWebhook(id, name) {
        if (!confirm(`Delete webhook "${name}"? This is permanent.`)) return;
        try {
            await callApi('webhooks_delete', { id });
            toast('Deleted.', 'ok');
            renderWebhooks();
        } catch (e) { toast(e.message, 'err'); }
    }

    async function testWebhookPing(id) {
        const modal = el('div', { class: 'st-modal' },
            el('h3', {}, 'Test Ping — sending…'),
            el('div', { class: 'st-ping-result' },
                el('i', { class: 'fa-solid fa-spinner fa-spin' }), ' Signing and POSTing synthetic order.created event…')
        );
        openModal(modal);

        try {
            const report = await callApi('webhooks_test_ping', { id });
            modal.innerHTML = '';
            modal.appendChild(el('h3', {}, report.ok ? '✅ Test Passed' : '❌ Test Failed'));
            modal.appendChild(el('div', { class: 'st-ping-result st-ping-result--' + (report.ok ? 'ok' : 'err') },
                JSON.stringify(report, null, 2)
            ));
            modal.appendChild(el('div', { class: 'st-modal__footer' }, el('button', { class: 'st-btn', onClick: closeModal }, 'Close')));
        } catch (e) {
            modal.innerHTML = '';
            modal.appendChild(el('h3', {}, '❌ Error'));
            modal.appendChild(el('div', { class: 'st-ping-result st-ping-result--err' }, e.message));
            modal.appendChild(el('div', { class: 'st-modal__footer' }, el('button', { class: 'st-btn', onClick: closeModal }, 'Close')));
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // API KEYS PANE
    // ═══════════════════════════════════════════════════════════════════

    async function renderApiKeys() {
        const pane = $('#st-pane-api_keys');
        pane.innerHTML = '<div class="st-empty"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading…</p></div>';
        try {
            const data = await callApi('api_keys_list');
            pane.innerHTML = '';
            pane.appendChild(el('div', { class: 'st-section-head' },
                el('h2', {}, 'Gateway API Keys'),
                el('span', { class: 'st-subtitle' }, 'Public API authentication (Uber/Glovo/kiosk/mobile app)'),
                el('button', { class: 'st-btn st-btn--primary', onClick: () => openApiKeyGenerator() },
                    el('i', { class: 'fa-solid fa-key' }), ' Generate Key')
            ));

            if (!data.api_keys.length) {
                pane.appendChild(el('div', { class: 'st-empty' },
                    el('i', { class: 'fa-solid fa-key' }),
                    el('p', {}, 'No API keys yet.'),
                    el('p', { class: 'st-subtitle' }, 'Generate keys for external callers using POST /api/gateway/intake.php.')
                ));
                return;
            }

            data.api_keys.forEach(k => pane.appendChild(renderApiKeyCard(k)));
        } catch (e) {
            pane.innerHTML = '';
            pane.appendChild(el('div', { class: 'st-empty' },
                el('i', { class: 'fa-solid fa-triangle-exclamation' }),
                el('p', {}, 'Failed to load: ' + e.message)));
        }
    }

    function renderApiKeyCard(k) {
        const isRevoked = !!k.revoked_at;
        const isExpired = k.expires_at && new Date(k.expires_at.replace(' ','T')+'Z') < new Date();
        const scopes = Array.isArray(k.scopes) ? k.scopes : JSON.parse(k.scopes || '[]');
        const status = isRevoked ? 'revoked' : (isExpired ? 'expired' : (k.is_active == 1 ? 'active' : 'disabled'));
        const chipClass = status === 'active' ? 'st-chip--ok' : (status === 'revoked' ? 'st-chip--err' : 'st-chip--warn');

        return el('div', { class: 'st-card' },
            el('div', { class: 'st-card__main' },
                el('h3', {}, el('i', { class: 'fa-solid fa-key' }), ' ' + k.name,
                    el('span', { class: `st-chip ${chipClass}` }, status)),
                el('div', { class: 'st-meta', html:
                    `Prefix: <code>${escHtml(k.key_prefix)}</code> · Source: <span class="st-chip st-chip--accent">${escHtml(k.source)}</span><br>` +
                    `Scopes: ${scopes.map(s => `<span class="st-chip">${escHtml(s)}</span>`).join('')}<br>` +
                    `Rate limit: ${k.rate_limit_per_min}/min · ${k.rate_limit_per_day}/day<br>` +
                    `Created: ${fmtDate(k.created_at)}` +
                    (k.last_used_at ? ` · Last used: ${fmtDate(k.last_used_at)} (${escHtml(k.last_used_ip || '-')})` : '') +
                    (k.expires_at ? `<br>Expires: ${fmtDate(k.expires_at)}` : '') +
                    (k.revoked_at ? `<br><span style="color:var(--err)">Revoked: ${fmtDate(k.revoked_at)}</span>` : '')
                })
            ),
            el('div', { class: 'st-card__actions' },
                !isRevoked
                    ? el('button', { class: 'st-btn st-btn--sm st-btn--danger', onClick: () => revokeApiKey(k.id, k.name) },
                          el('i', { class: 'fa-solid fa-ban' }), ' Revoke')
                    : null
            )
        );
    }

    function openApiKeyGenerator() {
        const modal = el('div', { class: 'st-modal' });

        const nameInput = el('input', { type: 'text', id: 'k-name', placeholder: 'np. "Uber Eats Integration"' });
        const srcSelect = el('select', { id: 'k-source' },
            ['web', 'mobile_app', 'kiosk', 'pos_3rd', 'public_api', 'aggregator',
             'aggregator_uber', 'aggregator_glovo', 'aggregator_pyszne', 'aggregator_wolt']
                .map(s => el('option', { value: s }, s))
        );
        srcSelect.value = 'public_api';
        const scopesInput = el('input', { type: 'text', id: 'k-scopes', value: 'order:create' });
        const rpmInput = el('input', { type: 'number', min: '1', id: 'k-rpm', value: 60 });
        const rpdInput = el('input', { type: 'number', min: '1', id: 'k-rpd', value: 10000 });
        const expInput = el('input', { type: 'datetime-local', id: 'k-exp' });

        modal.appendChild(el('h3', {}, 'Generate Gateway API Key'));
        modal.appendChild(el('div', { class: 'st-field' }, el('label', {}, 'Name'), nameInput));
        modal.appendChild(el('div', { class: 'st-field' }, el('label', {}, 'Source'), srcSelect,
            el('div', { class: 'st-field__hint' }, 'Key będzie source-bound: klucz "aggregator_uber" nie puszcza zamówień jako "aggregator_glovo".')));
        modal.appendChild(el('div', { class: 'st-field' }, el('label', {}, 'Scopes'), scopesInput,
            el('div', { class: 'st-field__hint' }, 'Comma-separated. Dostępne: order:create, order:read, menu:read. "*" = wszystkie.')));
        modal.appendChild(el('div', { class: 'st-grid-2' },
            el('div', { class: 'st-field' }, el('label', {}, 'Rate Limit / min'), rpmInput),
            el('div', { class: 'st-field' }, el('label', {}, 'Rate Limit / day'),  rpdInput)
        ));
        modal.appendChild(el('div', { class: 'st-field' }, el('label', {}, 'Expires At (opcjonalne)'), expInput,
            el('div', { class: 'st-field__hint' }, 'Pusty = never expires. Zalecane do rotacji sekretów w 3rd-party integracjach.')));

        modal.appendChild(el('div', { class: 'st-modal__footer' },
            el('button', { class: 'st-btn', onClick: closeModal }, 'Cancel'),
            el('button', { class: 'st-btn st-btn--primary', onClick: async () => {
                const payload = {
                    name: nameInput.value.trim(),
                    source: srcSelect.value,
                    scopes: scopesInput.value.split(',').map(s => s.trim()).filter(Boolean),
                    rate_limit_per_min: parseInt(rpmInput.value, 10),
                    rate_limit_per_day: parseInt(rpdInput.value, 10),
                    expires_at: expInput.value ? expInput.value.replace('T', ' ') + ':00' : null,
                };
                try {
                    const result = await callApi('api_keys_generate', payload);
                    closeModal();
                    showRevealSecret('Full API Key', result.full_key,
                        `Send this as X-API-Key header to POST /api/gateway/intake.php. ` +
                        `Prefix: ${result.prefix} · Source: ${result.source}`);
                    renderApiKeys();
                } catch (e) { toast(e.message, 'err'); }
            }}, 'Generate')
        ));

        openModal(modal);
    }

    async function revokeApiKey(id, name) {
        if (!confirm(`Revoke API key "${name}"? This cannot be undone — all requests with this key will fail immediately.`)) return;
        try {
            await callApi('api_keys_revoke', { id });
            toast('Key revoked.', 'ok');
            renderApiKeys();
        } catch (e) { toast(e.message, 'err'); }
    }

    // ═══════════════════════════════════════════════════════════════════
    // DLQ PANE
    // ═══════════════════════════════════════════════════════════════════

    async function renderDlq() {
        const pane = $('#st-pane-dlq');
        pane.innerHTML = '<div class="st-empty"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading…</p></div>';

        try {
            const data = await callApi('dlq_list', { channel: 'all', limit: 100 });

            pane.innerHTML = '';
            pane.appendChild(el('div', { class: 'st-section-head' },
                el('h2', {}, 'Dead Letter Queue'),
                el('span', { class: 'st-subtitle' },
                    `Webhooks: ${data.counts.webhooks} · Integrations: ${data.counts.integrations} · ` +
                    `Ostatnie 100 rekordów`)
            ));

            if (!data.webhooks.length && !data.integrations.length) {
                pane.appendChild(el('div', { class: 'st-empty' },
                    el('i', { class: 'fa-solid fa-check-circle' }),
                    el('p', {}, 'Brak dead letters — wszystko delivered!')
                ));
                return;
            }

            if (data.webhooks.length) {
                pane.appendChild(el('h3', { style: 'margin-top:20px' },
                    el('i', { class: 'fa-solid fa-satellite-dish' }), ' Webhook Events (outbox)'));
                data.webhooks.forEach(r => pane.appendChild(renderDlqCard('webhooks', r)));
            }
            if (data.integrations.length) {
                pane.appendChild(el('h3', { style: 'margin-top:24px' },
                    el('i', { class: 'fa-solid fa-plug' }), ' Integration Deliveries'));
                data.integrations.forEach(r => pane.appendChild(renderDlqCard('integrations', r)));
            }
        } catch (e) {
            pane.innerHTML = '';
            pane.appendChild(el('div', { class: 'st-empty' },
                el('i', { class: 'fa-solid fa-triangle-exclamation' }),
                el('p', {}, 'Failed to load: ' + e.message)));
        }
    }

    function renderDlqCard(channel, r) {
        return el('div', { class: 'st-card' },
            el('div', { class: 'st-card__main' },
                el('h3', {},
                    el('i', { class: 'fa-solid fa-skull-crossbones' }),
                    ' ', r.event_type || '?',
                    el('span', { class: 'st-chip st-chip--err' }, `${r.attempts} attempts`),
                    r.provider ? el('span', { class: 'st-chip st-chip--accent' }, r.provider) : null,
                    r.http_code ? el('span', { class: 'st-chip' }, `HTTP ${r.http_code}`) : null
                ),
                el('div', { class: 'st-meta', html:
                    `Aggregate: <code>${escHtml(r.aggregate_id || '-')}</code> · ID: <code>${r.id}</code><br>` +
                    `Error: <code>${escHtml((r.last_error || '-').substring(0, 300))}</code><br>` +
                    `Created: ${fmtDate(r.created_at)}` +
                    (r.last_attempted_at ? ` · Last attempt: ${fmtDate(r.last_attempted_at)}` : '') +
                    (r.completed_at ? ` · Marked dead: ${fmtDate(r.completed_at)}` : '')
                })
            ),
            el('div', { class: 'st-card__actions' },
                el('button', { class: 'st-btn st-btn--sm st-btn--primary',
                    onClick: () => replayDlq(channel, r.id) },
                    el('i', { class: 'fa-solid fa-rotate-right' }), ' Replay')
            )
        );
    }

    async function replayDlq(channel, id) {
        try {
            const result = await callApi('dlq_replay', { channel, id });
            toast(`Re-queued: ${result.channel} #${result.id}`, 'ok');
            renderDlq();
        } catch (e) { toast(e.message, 'err'); }
    }

    // ═══════════════════════════════════════════════════════════════════
    // INBOUND PANE (m029: sh_inbound_callbacks)
    // ═══════════════════════════════════════════════════════════════════

    async function renderInbound() {
        const pane = $('#st-pane-inbound');
        pane.innerHTML = '<div class="st-empty"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading…</p></div>';

        try {
            const data = await callApi('inbound_list', { limit: 100 });

            pane.innerHTML = '';

            const counts = data.counts_24h || {};
            const countsStr = ['processed','pending','rejected','ignored','error']
                .map(k => `${k}: ${counts[k] || 0}`)
                .join(' · ');

            pane.appendChild(el('div', { class: 'st-section-head' },
                el('h2', {}, 'Inbound Callbacks'),
                el('span', { class: 'st-subtitle' },
                    `3rd-party → api/integrations/inbound.php — ostatnie 100 · 24h: ${countsStr}`)
            ));

            if (data.table_ready === false) {
                pane.appendChild(el('div', { class: 'st-empty' },
                    el('i', { class: 'fa-solid fa-database' }),
                    el('p', {}, data.note || 'Tabela sh_inbound_callbacks nie istnieje.'),
                    el('p', { class: 'st-subtitle' }, 'Uruchom: php scripts/apply_migrations_chain.php (m029_infrastructure_completion).')
                ));
                return;
            }

            if (!data.rows.length) {
                pane.appendChild(el('div', { class: 'st-empty' },
                    el('i', { class: 'fa-solid fa-inbox' }),
                    el('p', {}, 'Brak inbound callbacków.'),
                    el('p', { class: 'st-subtitle' }, 'Gdy 3rd-party (Papu/Dotykacka/…) zacznie pushować status-update, pojawi się tutaj.')
                ));
                return;
            }

            data.rows.forEach(r => pane.appendChild(renderInboundCard(r)));
        } catch (e) {
            pane.innerHTML = '';
            pane.appendChild(el('div', { class: 'st-empty' },
                el('i', { class: 'fa-solid fa-triangle-exclamation' }),
                el('p', {}, 'Failed to load: ' + e.message)));
        }
    }

    function renderInboundCard(r) {
        const statusClass = {
            processed: 'st-chip--ok',
            pending:   'st-chip--warn',
            rejected:  'st-chip--err',
            error:     'st-chip--err',
            ignored:   'st-chip',
        }[r.status] || 'st-chip';

        const sigChip = r.signature_verified == 1
            ? el('span', { class: 'st-chip st-chip--ok', title: 'HMAC/OAuth signature verified by adapter' }, '🔏 signed')
            : el('span', { class: 'st-chip st-chip--err', title: 'Signature NOT verified — potential bad-sig attack' }, '⚠ unsigned');

        return el('div', { class: 'st-card' },
            el('div', { class: 'st-card__main' },
                el('h3', {},
                    el('i', { class: 'fa-solid fa-inbox' }),
                    ' ', (r.event_type || 'unknown'),
                    el('span', { class: 'st-chip st-chip--accent' }, r.provider || '?'),
                    el('span', { class: `st-chip ${statusClass}` }, r.status),
                    sigChip
                ),
                el('div', { class: 'st-meta', html:
                    (r.external_ref ? `Ext ref: <code>${escHtml(r.external_ref)}</code>` : 'Ext ref: —') +
                    (r.external_event_id ? ` · Ext evt: <code>${escHtml(r.external_event_id)}</code>` : '') +
                    (r.mapped_order_id ? ` · Mapped: <code>sh_orders#${r.mapped_order_id}</code>` : '') +
                    `<br>Received: ${fmtDate(r.received_at)}` +
                    (r.processed_at ? ` · Processed: ${fmtDate(r.processed_at)}` : '') +
                    (r.remote_ip ? ` · IP: <code>${escHtml(r.remote_ip)}</code>` : '') +
                    (r.error_message ? `<br>Error: <code>${escHtml(r.error_message.substring(0, 300))}</code>` : '') +
                    (r.raw_body_preview ? `<br>Body: <code>${escHtml((r.raw_body_preview || '').substring(0, 240))}${(r.raw_body_preview||'').length>=240?'…':''}</code>` : '')
                })
            )
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // HEALTH PANE
    // ═══════════════════════════════════════════════════════════════════

    async function renderHealth() {
        const pane = $('#st-pane-health');
        pane.innerHTML = '<div class="st-empty"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading…</p></div>';
        try {
            const s = await callApi('health_summary');
            updateVaultBadge(s.vault_ready);
            updatePlaintextBanner(s.plaintext);

            pane.innerHTML = '';
            pane.appendChild(el('div', { class: 'st-section-head' },
                el('h2', {}, 'System Health'),
                el('span', { class: 'st-subtitle' }, 'Snapshot z ostatnich 7 dni')
            ));

            const grid = el('div', { class: 'st-health-grid' });

            // Vault
            grid.appendChild(el('div', { class: 'st-health-card' },
                el('h4', {}, 'Credential Vault'),
                el('div', { class: 'st-metric', style: `color: var(--${s.vault_ready ? 'ok' : 'err'})` },
                    s.vault_ready ? '🔒 Ready' : '⚠️ Disabled'),
                el('div', { class: 'st-submetric' },
                    `libsodium: ${s.vault_has_sodium ? 'available' : 'missing'} · ` +
                    `key: ${s.vault_ready ? 'set' : 'not configured'}`)
            ));

            // Outbox
            const outbox = s.outbox || {};
            grid.appendChild(el('div', { class: 'st-health-card' },
                el('h4', {}, 'Outbox Events (7d)'),
                el('div', { class: 'st-metric' }, Object.values(outbox).reduce((a,b)=>a+Number(b), 0) || 0),
                el('div', { class: 'st-submetric', html:
                    Object.entries(outbox).map(([k,v]) =>
                        `<span class="st-chip st-chip--${k==='delivered'?'ok':(k==='dead'?'err':(k==='failed'?'warn':''))}">${escHtml(k)}: ${v}</span>`
                    ).join(' ')
                })
            ));

            // Webhooks
            const wh = s.webhooks || {};
            grid.appendChild(el('div', { class: 'st-health-card' },
                el('h4', {}, 'Webhook Endpoints'),
                el('div', { class: 'st-metric' }, `${wh.active || 0} / ${wh.total || 0}`),
                el('div', { class: 'st-submetric' }, `${wh.paused || 0} auto-paused (consecutive failures)`)
            ));

            // API Keys
            const api = s.api_keys || {};
            grid.appendChild(el('div', { class: 'st-health-card' },
                el('h4', {}, 'Gateway API Keys'),
                el('div', { class: 'st-metric' }, `${api.active || 0} / ${api.total || 0}`),
                el('div', { class: 'st-submetric' }, 'active / total')
            ));

            pane.appendChild(grid);

            // Inbound snapshot (24h)
            const inb = s.inbound;
            if (inb && !inb.error) {
                pane.appendChild(el('h3', { style: 'margin-top:24px' }, 'Inbound Callbacks (24h)'));

                const tots = inb.totals || {};
                const allZero = Object.values(tots).every(v => !v);
                const badSig = inb.bad_signature_count || 0;

                pane.appendChild(el('div', { class: 'st-card' },
                    el('div', { class: 'st-card__main' },
                        el('h3', {},
                            el('i', { class: 'fa-solid fa-inbox' }), ' Last 24h',
                            badSig > 0
                                ? el('span', { class: 'st-chip st-chip--err', title: 'Requests z niezweryfikowaną sygnaturą — patrz tab Inbound' }, `${badSig} unsigned`)
                                : el('span', { class: 'st-chip st-chip--ok' }, 'signatures ok')
                        ),
                        el('div', { class: 'st-meta', html: allZero
                            ? '<span class="st-subtitle">Żadnych callbacków w ostatnich 24h.</span>'
                            : ['processed','pending','rejected','ignored','error']
                                .map(k => `<span class="st-chip st-chip--${k==='processed'?'ok':(k==='rejected'||k==='error'?'err':(k==='pending'?'warn':''))}">${k}: ${tots[k] || 0}</span>`)
                                .join(' ')
                        })
                    )
                ));
            }

            // Integrations table
            pane.appendChild(el('h3', { style: 'margin-top:24px' }, 'Integrations Health'));
            if (!s.integrations || !s.integrations.length) {
                pane.appendChild(el('div', { class: 'st-empty' },
                    el('p', {}, 'No integrations configured yet.')
                ));
            } else {
                s.integrations.forEach(i => {
                    pane.appendChild(el('div', { class: 'st-card' },
                        el('div', { class: 'st-card__main' },
                            el('h3', {}, el('i', { class: 'fa-solid fa-plug' }), ' ' + i.provider,
                                i.is_active == 1
                                    ? el('span', { class: 'st-chip st-chip--ok' }, 'active')
                                    : el('span', { class: 'st-chip st-chip--err' }, 'paused')),
                            el('div', { class: 'st-meta', html:
                                `Consecutive failures: <b>${i.consecutive_failures || 0}</b>` +
                                (i.last_sync_at ? `<br>Last sync OK: ${fmtDate(i.last_sync_at)}` : '') +
                                (i.last_failure_at ? `<br>Last failure: ${fmtDate(i.last_failure_at)}` : '')
                            })
                        )
                    ));
                });
            }
        } catch (e) {
            pane.innerHTML = '';
            pane.appendChild(el('div', { class: 'st-empty' },
                el('i', { class: 'fa-solid fa-triangle-exclamation' }),
                el('p', {}, 'Failed to load: ' + e.message)));
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // SHARED HELPERS
    // ═══════════════════════════════════════════════════════════════════

    function showRevealSecret(title, secret, explanation) {
        const modal = el('div', { class: 'st-modal' },
            el('h3', {}, '🔑 ' + title + ' — zapisz TERAZ'),
            el('div', { class: 'st-secret-reveal' },
                el('h4', {}, '⚠️ Ten sekret jest pokazywany TYLKO RAZ'),
                el('code', {}, secret),
                el('small', {}, explanation)
            ),
            el('div', { class: 'st-modal__footer' },
                el('button', { class: 'st-btn', onClick: async () => {
                    try { await navigator.clipboard.writeText(secret); toast('Skopiowane do schowka.', 'ok'); }
                    catch { toast('Copy failed — skopiuj ręcznie.', 'err'); }
                }}, el('i', { class: 'fa-solid fa-copy' }), ' Copy'),
                el('button', { class: 'st-btn st-btn--primary', onClick: closeModal }, 'Done')
            )
        );
        openModal(modal);
    }

    function updateVaultBadge(ready) {
        state.vaultReady = ready;
        const badge = $('#st-vault-badge');
        if (!badge) return;
        badge.className = 'st-badge st-badge--' + (ready ? 'ok' : 'warn');
        badge.querySelector('span').textContent = ready ? 'vault ready' : 'PLAINTEXT';
        badge.title = ready
            ? 'CredentialVault active — credentials encrypted at rest (libsodium).'
            : 'CredentialVault DISABLED — credentials stored as plaintext. Set SLICEHUB_VAULT_KEY or install libsodium.';
    }

    /**
     * Banner „X plaintext credentials — uruchom rotate_credentials_to_vault.php".
     * Wstrzykiwany nad <main>. Zero = ukryty; >0 = sticky warning do czasu rotacji.
     */
    function updatePlaintextBanner(plaintext) {
        const host = $('#st-global-banners');
        if (!host) return;
        host.innerHTML = '';
        if (!plaintext || plaintext.error) return;

        const total = (plaintext.total | 0);
        if (total <= 0) return;

        const cmd = plaintext.rotate_cmd || 'php scripts/rotate_credentials_to_vault.php';
        const parts = [];
        if (plaintext.integrations) parts.push(`${plaintext.integrations} integration${plaintext.integrations > 1 ? 's' : ''}`);
        if (plaintext.webhooks)     parts.push(`${plaintext.webhooks} webhook${plaintext.webhooks > 1 ? 's' : ''}`);

        const banner = el('div', { class: 'st-banner st-banner--warn', role: 'alert' },
            el('div', { class: 'st-banner__main' },
                el('i', { class: 'fa-solid fa-triangle-exclamation' }),
                ' ',
                el('b', {}, `${total} plaintext credential${total > 1 ? 's' : ''} w bazie`),
                ' — ',
                parts.join(' + '),
                '. Zaszyfruj przez: ',
                el('code', {}, cmd)
            ),
            el('div', { class: 'st-banner__actions' },
                el('button', { class: 'st-btn st-btn--sm', onClick: async () => {
                    try { await navigator.clipboard.writeText(cmd); toast('Komenda skopiowana.', 'ok'); }
                    catch { toast('Copy failed — skopiuj ręcznie.', 'err'); }
                }}, el('i', { class: 'fa-solid fa-copy' }), ' Copy cmd')
            )
        );
        host.appendChild(banner);
    }

    /** Lekki healthcheck odpalany raz przy boot — tylko żeby zaktualizować banner plaintext. */
    async function bootHealthCheck() {
        try {
            const s = await callApi('health_summary');
            updateVaultBadge(s.vault_ready);
            updatePlaintextBanner(s.plaintext);
        } catch { /* cicho — banner po prostu nie wskoczy */ }
    }

    // ─── Boot ───────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        // Expose globals for sub-modules (notifications.js)
        const metaTenant = document.querySelector('meta[name="sh-tenant-id"]');
        window.SLICEHUB_TENANT_ID = metaTenant ? parseInt(metaTenant.content, 10) : 1;
        window.stToast      = (msg, ok) => toast(msg, ok ? 'ok' : 'err');
        window.stOpenModal  = openModal;
        window.stCloseModal = closeModal;

        $$('.st-tab').forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));
        $('#st-refresh-btn').addEventListener('click', () => loadTab(state.activeTab));

        // Prefetch CSRF tokena — tanie, znika race przy pierwszym save/toggle.
        fetchCsrfToken().catch(() => { /* zostawiamy — callApi ma retry */ });

        // Global snapshot: plaintext banner + vault badge bez czekania na tab Health.
        bootHealthCheck();

        switchTab('integrations');
    });

})();
