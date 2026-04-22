'use strict';

/**
 * SliceHub — Customer Inbox (SMS Skrzynka)
 *
 * Komunika się z api/inbox/engine.php
 * Polling co 30s dla nowych wiadomości.
 */

const API_BASE = '/slicehub/api/inbox/engine.php';
const TENANT_ID = parseInt(
    document.querySelector('meta[name="sh-tenant-id"]')?.content ?? '1', 10
);

const state = {
    filter:       'unread',
    search:       '',
    messages:     [],
    offset:       0,
    total:        0,
    limit:        50,
    currentMsg:   null,
    pollTimer:    null,
};

// ── API helpers ──────────────────────────────────────────

async function api(action, extra = {}) {
    const resp = await fetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, tenant_id: TENANT_ID, ...extra }),
    });
    const data = await resp.json().catch(() => ({ success: false, error: 'JSON parse error' }));
    if (!data.success) throw new Error(data.error || 'API error');
    return data;
}

// ── Toast ────────────────────────────────────────────────

function toast(msg, type = 'info') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = `toast ${type}`;
    clearTimeout(el._timer);
    el._timer = setTimeout(() => el.classList.add('hidden'), 3500);
}

// ── Stats ────────────────────────────────────────────────

async function loadStats() {
    try {
        const { stats } = await api('inbox_stats');
        document.getElementById('badge-unread').textContent = stats.unread  || '';
        document.getElementById('badge-cancels').textContent = stats.unread_cancels || '';
        document.getElementById('stat-today').textContent  = stats.today  ?? '—';
        document.getElementById('stat-total').textContent  = stats.total  ?? '—';
    } catch (_) {}
}

// ── Message list ─────────────────────────────────────────

async function loadMessages(append = false) {
    if (!append) state.offset = 0;

    const params = {
        filter: state.filter === 'cancels' ? 'unread' : state.filter,
        search: state.search,
        limit:  state.limit,
        offset: state.offset,
    };

    try {
        const res = await api('inbox_list', params);
        let msgs = res.messages;

        // Client-side filter for cancel intent if needed
        if (state.filter === 'cancels') {
            msgs = msgs.filter(m => m.intent === 'cancel_request');
        }

        state.total = res.total;
        if (append) {
            state.messages.push(...msgs);
        } else {
            state.messages = msgs;
        }
        state.offset = state.messages.length;

        renderList();
        const footer = document.getElementById('list-footer');
        footer.style.display = state.messages.length < state.total ? '' : 'none';
    } catch (e) {
        toast('Błąd ładowania: ' + e.message, 'error');
    }
}

function renderList() {
    const container = document.getElementById('message-list');
    const empty     = document.getElementById('empty-state');

    if (!state.messages.length) {
        container.innerHTML = '';
        container.appendChild(empty);
        empty.style.display = '';
        return;
    }
    empty.style.display = 'none';

    container.innerHTML = state.messages.map(m => {
        const isUnread = !m.read_at;
        const isCancel = m.intent === 'cancel_request';
        const cardClass = [
            'msg-card',
            isUnread && !isCancel ? 'unread' : '',
            isUnread && isCancel ? 'unread-cancel' : '',
            state.currentMsg?.id === m.id ? 'selected' : '',
        ].filter(Boolean).join(' ');

        const avatar = intentEmoji(m.intent);
        const name   = m.contact_name ? `<span class="msg-name">${esc(m.contact_name)}</span>` : '';
        const intent = m.intent && m.intent !== 'manager_reply'
            ? `<span class="msg-intent intent-${m.intent}">${intentLabel(m.intent)}</span>`
            : '';
        const time   = formatTime(m.received_at);

        return `<div class="msg-card ${cardClass}" data-id="${m.id}" onclick="openMessage(${m.id})">
            <div class="msg-avatar">${avatar}</div>
            <div class="msg-content">
                <div class="msg-top">
                    <span class="msg-phone">${esc(m.from_phone)}${name}</span>
                    <span class="msg-time">${time}</span>
                </div>
                <div class="msg-preview">${esc(m.body)}</div>
                ${intent}
            </div>
        </div>`;
    }).join('');
}

// ── Detail view ──────────────────────────────────────────

window.openMessage = async function(id) {
    const msg = state.messages.find(m => m.id == id);
    if (!msg) return;
    state.currentMsg = msg;

    // Mark selected in list
    document.querySelectorAll('.msg-card').forEach(c => c.classList.toggle('selected', c.dataset.id == id));

    // Switch to detail
    document.getElementById('list-view').style.display   = 'none';
    document.getElementById('detail-view').classList.remove('hidden');

    // Render detail
    document.getElementById('detail-phone').textContent   = msg.from_phone;
    document.getElementById('detail-contact').textContent = [
        msg.contact_name,
        msg.contact_email,
        msg.order_count ? `${msg.order_count} zamówień` : null,
    ].filter(Boolean).join(' · ');
    document.getElementById('detail-time').textContent    = formatTime(msg.received_at, true);

    const intentBadge = document.getElementById('detail-intent-badge');
    intentBadge.textContent = intentLabel(msg.intent || 'other');
    intentBadge.className   = `detail-intent-badge msg-intent intent-${msg.intent || 'other'}`;

    document.getElementById('detail-body').textContent = msg.body;

    const autoWrap = document.getElementById('detail-auto-reply-wrap');
    if (msg.auto_replied && msg.auto_reply_body) {
        document.getElementById('detail-auto-reply-body').textContent = msg.auto_reply_body;
        autoWrap.style.display = '';
    } else {
        autoWrap.style.display = 'none';
    }

    const orderWrap = document.getElementById('detail-order-wrap');
    if (msg.order_id) {
        document.getElementById('detail-order-link').textContent = 'Zamówienie #' + msg.order_id;
        document.getElementById('detail-order-link').href = '#order-' + msg.order_id;
        orderWrap.style.display = '';
    } else {
        orderWrap.style.display = 'none';
    }

    // Mark as read
    if (!msg.read_at) {
        try {
            await api('inbox_read', { id: msg.id });
            msg.read_at = new Date().toISOString();
            renderList();
            loadStats();
        } catch (_) {}
    }

    // Prefill reply with phone
    document.getElementById('reply-textarea').value = '';
    updateCharCount();
};

document.getElementById('btn-back').addEventListener('click', () => {
    document.getElementById('list-view').style.display   = '';
    document.getElementById('detail-view').classList.add('hidden');
    state.currentMsg = null;
});

// ── Reply ────────────────────────────────────────────────

document.getElementById('reply-textarea').addEventListener('input', updateCharCount);

function updateCharCount() {
    const len = document.getElementById('reply-textarea').value.length;
    const el  = document.getElementById('reply-chars');
    el.textContent = `${len} / 160`;
    el.classList.toggle('over', len > 160);
}

document.getElementById('btn-send-reply').addEventListener('click', async () => {
    if (!state.currentMsg) return;
    const btn = document.getElementById('btn-send-reply');
    const msg = document.getElementById('reply-textarea').value.trim();
    if (!msg) { toast('Wpisz wiadomość', 'error'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Wysyłam…';
    try {
        await api('inbox_reply', { id: state.currentMsg.id, message: msg });
        document.getElementById('reply-textarea').value = '';
        updateCharCount();
        toast('SMS wysłany ✓', 'success');
    } catch (e) {
        toast('Błąd: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-paper-plane"></i> Wyślij SMS';
    }
});

// ── Nav / filter ─────────────────────────────────────────

document.querySelectorAll('.nav-btn[data-filter]').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        state.filter = btn.dataset.filter;
        const titles = { unread: 'Nieprzeczytane', all: 'Wszystkie', cancels: 'Prośby o anulowanie' };
        document.getElementById('list-title').textContent = titles[state.filter] || state.filter;
        loadMessages();

        // Go back to list if in detail
        if (!document.getElementById('detail-view').classList.contains('hidden')) {
            document.getElementById('list-view').style.display   = '';
            document.getElementById('detail-view').classList.add('hidden');
        }
    });
});

document.getElementById('btn-refresh').addEventListener('click', () => {
    loadMessages();
    loadStats();
});

document.getElementById('btn-load-more').addEventListener('click', () => loadMessages(true));

document.getElementById('btn-mark-all-read').addEventListener('click', async () => {
    const ids = state.messages.filter(m => !m.read_at).map(m => m.id);
    if (!ids.length) { toast('Brak nieprzeczytanych'); return; }
    try {
        await api('inbox_bulk_read', { ids });
        ids.forEach(id => {
            const m = state.messages.find(m => m.id === id);
            if (m) m.read_at = new Date().toISOString();
        });
        renderList();
        loadStats();
        toast(`Oznaczono ${ids.length} jako przeczytane`, 'success');
    } catch (e) {
        toast('Błąd: ' + e.message, 'error');
    }
});

// ── Search ───────────────────────────────────────────────

let searchTimer;
document.getElementById('search-input').addEventListener('input', e => {
    state.search = e.target.value.trim();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadMessages(), 350);
});

// ── Polling ──────────────────────────────────────────────

function startPolling() {
    loadStats();
    state.pollTimer = setInterval(() => {
        loadStats();
        // Reload list only if on list view
        if (document.getElementById('detail-view').classList.contains('hidden')) {
            loadMessages();
        }
    }, 30_000);
}

// ── Helpers ──────────────────────────────────────────────

function esc(str) {
    return String(str ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function intentLabel(intent) {
    return {
        eta_query:      'ETA / Status',
        cancel_request: 'Anulowanie',
        stop:           'STOP',
        info_query:     'Informacje',
        reorder:        'Ponów',
        other:          'Inne',
        manager_reply:  'Odpowiedź',
    }[intent] ?? intent;
}

function intentEmoji(intent) {
    return {
        eta_query:      '🕐',
        cancel_request: '❌',
        stop:           '🚫',
        info_query:     'ℹ️',
        reorder:        '🔄',
        other:          '💬',
        manager_reply:  '👤',
    }[intent] ?? '💬';
}

function formatTime(iso, full = false) {
    if (!iso) return '—';
    const d = new Date(iso.replace(' ', 'T'));
    if (full) {
        return d.toLocaleString('pl-PL', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
    }
    const now = new Date();
    const diff = now - d;
    if (diff < 60_000)   return 'teraz';
    if (diff < 3_600_000) return Math.floor(diff/60_000) + ' min temu';
    if (diff < 86_400_000) return Math.floor(diff/3_600_000) + ' godz. temu';
    return d.toLocaleDateString('pl-PL', { day:'2-digit', month:'2-digit' });
}

// ── Init ─────────────────────────────────────────────────

(function init() {
    loadMessages();
    loadStats();
    startPolling();
})();
