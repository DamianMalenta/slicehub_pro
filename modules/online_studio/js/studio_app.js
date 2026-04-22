/**
 * SliceHub · Online Studio — Main app boot
 * Loads auth context, mounts tabs, handles routing + toasts + modals.
 */

import { StudioApi } from './studio_api.js';
import { mountAssets }     from './tabs/assets.js';
import { mountCompanions } from './tabs/companions.js';
import { mountSurface }    from './tabs/surface.js';
import { mountPreview }    from './tabs/preview.js';
import { mountConductor }  from './tabs/conductor.js';
import { mountStorefront } from './tabs/storefront.js';
import { DirectorApp }     from './director/DirectorApp.js';

/* ── Global state (accessible to tabs) ─────────────────────────── */
export const Studio = {
    /** Who am I (tenantId, userId, role, tenantName). */
    identity: null,
    /** Menu items + modifiers (loaded once). */
    menu: null,
    /** Unified Asset Library cache (SSOT: api/assets/engine.php action=list).
     *  Shape: { items:[], total, page, perPage }. Items mają:
     *    asciiKey, displayName, tags[], publicUrl, bucket, category, subType,
     *    cookState, width, height, hasAlpha, zOrderHint, linkCount, duplicateCount
     */
    assets: null,
    /** @deprecated alias kept 1 sprint for any straggler reading Studio.library */
    get library() { return Studio.assets; },
    /** Last loaded dish composer snapshot. */
    dishSnapshot: null,
    /** Global dirty flag — set by tabs to warn on nav/close. */
    _dirty: false,

    // TAB REGISTRY
    _tabs: {},
    _active: null,

    markDirty(is = true) { Studio._dirty = !!is; },
    isDirty() { return !!Studio._dirty; },

    refreshMenu: async () => {
        const r = await StudioApi.menuList();
        if (r.success) { Studio.menu = r.data; return r.data; }
        return null;
    },

    /**
     * Load/refresh unified asset library from api/assets/engine.php (SSOT).
     * Używamy per_page=500 żeby pokryć typowy lokal (do ~500 assetów).
     * Jeśli przekroczysz → Asset Studio M5 ma już pagination dla widoku.
     */
    refreshAssets: async (force = false) => {
        if (Studio.assets && !force) return Studio.assets;
        const r = await StudioApi.assetsList({ per_page: 500, page: 1 });
        if (r.success) { Studio.assets = r.data; return r.data; }
        return null;
    },
    /** @deprecated use refreshAssets — zachowujemy shim żeby nie zepsuć nie-przepiętych callerów */
    refreshLibrary: async (force = false) => Studio.refreshAssets(force),

    // ── Toast ──
    toast(msg, type = 'info', ms = 3500) {
        const root = document.getElementById('toast-root');
        if (!root) return;
        const icon = { ok: 'fa-circle-check', err: 'fa-circle-exclamation', info: 'fa-circle-info', warn: 'fa-triangle-exclamation' }[type] || 'fa-circle-info';
        const el = document.createElement('div');
        el.className = `toast toast--${type}`;
        el.innerHTML = `<i class="fa-solid ${icon}"></i><span>${msg}</span>`;
        root.appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateX(20px)'; }, ms - 200);
        setTimeout(() => el.remove(), ms);
    },

    // ── Modal ──
    modal({ title, body, footer, wide = false, onClose }) {
        const root = document.getElementById('modal-root');
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal ${wide ? 'modal--wide' : ''}">
                <div class="modal__head">
                    <div class="modal__title">${title}</div>
                    <button class="modal__close" type="button" aria-label="Zamknij"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal__body"></div>
                ${footer ? '<div class="modal__foot"></div>' : ''}
            </div>
        `;
        root.appendChild(backdrop);

        const close = () => {
            backdrop.remove();
            if (typeof onClose === 'function') onClose();
        };

        backdrop.querySelector('.modal__close').onclick = close;
        backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
        document.addEventListener('keydown', function esc(e) {
            if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc); }
        });

        const bodyEl   = backdrop.querySelector('.modal__body');
        const footerEl = backdrop.querySelector('.modal__foot');
        if (typeof body === 'string') bodyEl.innerHTML = body;
        else if (body instanceof HTMLElement) bodyEl.appendChild(body);
        if (footerEl && footer) {
            if (typeof footer === 'string') footerEl.innerHTML = footer;
            else if (footer instanceof HTMLElement) footerEl.appendChild(footer);
        }

        return { close, root: backdrop, body: bodyEl, footer: footerEl };
    },

    switchTab(name) {
        if (Studio._active === name) return;
        if (Studio._dirty) {
            if (!confirm('Masz niezapisane zmiany — na pewno przełączyć zakładkę?')) return;
            Studio._dirty = false;
        }
        // Notify outgoing tab
        const prev = Studio._tabs[Studio._active];
        if (prev?.onLeave) prev.onLeave();

        document.querySelectorAll('.nav-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
        document.querySelectorAll('.stage > section[id^="tab-"]').forEach((t) => {
            if (!t.classList.contains('tab')) t.classList.add('tab');
            t.classList.toggle('tab--active', t.id === `tab-${name}`);
        });
        const dirTab = document.getElementById('tab-director');
        if (dirTab) {
            dirTab.classList.add('tab');
            dirTab.classList.toggle('tab--active', name === 'director');
        }

        const meta = {
            library:    ['Asset Studio', 'Drop Zone Wizard — wgrywasz plik i jedno okno prowadzi Cię: co to jest → gdzie przypisać.'],
            director:   ['Scene Studio', 'Jedyny edytor sceny dania — warstwy, scenografia, kamera, LUT, atmospheric, companions, promocje. (Hollywood-grade)'],
            conductor:  ['Style Conductor', 'Dyrygujesz całym teatrem — jeden klik przekształca kategorię lub całe menu w spójny styl wizualny. (G1/G2 bulk)'],
            companions: ['Companions', 'Produkty towarzyszące — napoje, sosy, dodatki polecane obok dania.'],
            surface:    ['Surface', 'Tło sceny (deska, marmur, blat) — warstwa tła pod kompozycją dania.'],
        preview:    ['Live Preview', 'Storefront modules/online w iframe — podgląd na żywo dla klienta.'],
        storefront: ['Ustawienia sklepu', 'Marka, kontakt, godziny otwarcia, kanały, mapa. Dane widoczne dla klienta na Scenie Drzwi.'],
    }[name] || ['', ''];
        document.getElementById('stage-title').textContent = meta[0];
        document.getElementById('stage-desc').textContent  = meta[1];

        document.body.classList.toggle('director-active', name === 'director');

        Studio._active = name;
        const tab = Studio._tabs[name];
        if (tab?.onEnter) tab.onEnter();
    },
};

/* ── Boot ──────────────────────────────────────────────────────── */
async function boot() {
    const bootMsg = document.getElementById('boot-msg');
    const bootEl  = document.getElementById('boot');
    const appEl   = document.getElementById('app');

    bootMsg.textContent = 'Sprawdzanie autoryzacji…';
    const who = await StudioApi.whoami();
    if (who._unauth) {
        bootMsg.textContent = 'Brak sesji — przekierowanie do logowania…';
        setTimeout(() => StudioApi.redirectToLogin(), 900);
        return;
    }
    if (!who.success) {
        bootMsg.textContent = 'Błąd: ' + (who.message || 'nie można zweryfikować sesji');
        return;
    }
    Studio.identity = who.data;

    bootMsg.textContent = 'Ładowanie menu…';
    const menu = await StudioApi.menuList();
    if (!menu.success) {
        bootMsg.textContent = 'Błąd menu: ' + (menu.message || 'nieznany');
        return;
    }
    Studio.menu = menu.data;

    bootMsg.textContent = 'Ładowanie biblioteki…';
    await Studio.refreshAssets(true);

    bootEl.classList.add('hidden');
    appEl.classList.remove('hidden');

    document.getElementById('nav-library-count').textContent = Studio.assets?.total ?? '·';

    // Tenant badge
    const tenantSpan = document.querySelector('#tenant-badge span');
    if (tenantSpan && Studio.identity) {
        const tName = Studio.identity.tenantName || ('tenant #' + Studio.identity.tenantId);
        tenantSpan.textContent = tName + ' · ' + (Studio.identity.role || '');
        document.getElementById('tenant-badge')?.setAttribute('title', tName + ' · ' + (Studio.identity.role || ''));
    }

    // Mount tabs (Director is the sole visual composer; legacy Composer removed in Etap 0 cleanup)
    Studio._tabs.library    = mountAssets    (document.getElementById('tab-library'),    Studio, StudioApi);
    Studio._tabs.conductor  = mountConductor (document.getElementById('tab-conductor'),  Studio, StudioApi);
    Studio._tabs.companions = mountCompanions(document.getElementById('tab-companions'), Studio, StudioApi);
    Studio._tabs.surface    = mountSurface   (document.getElementById('tab-surface'),    Studio, StudioApi);
    Studio._tabs.preview    = mountPreview   (document.getElementById('tab-preview'),    Studio, StudioApi);
    Studio._tabs.storefront = mountStorefront(document.getElementById('tab-storefront'), Studio, StudioApi);

    // Visual Director — the single authoritative scene composer
    const directorRoot = document.getElementById('tab-director');
    if (directorRoot) {
        const director = new DirectorApp();
        director.mount(directorRoot, Studio, StudioApi);
        Studio._tabs.director = {
            onEnter: () => director.onEnter(),
            onLeave: () => director.onLeave(),
        };
    }

    // Wire up sidebar nav
    document.querySelectorAll('.nav-btn').forEach(btn => {
        btn.addEventListener('click', () => Studio.switchTab(btn.dataset.tab));
    });

    // Keyboard shortcuts: 1–6 switch tabs, / focuses first search, Esc blurs it
    // (m025 · usunięto zakładkę "magic"; 2026-04-19 dodano "conductor" G1/G2)
    const tabOrder = ['library', 'director', 'conductor', 'companions', 'surface', 'preview'];
    document.addEventListener('keydown', (e) => {
        const tag = (e.target?.tagName || '').toLowerCase();
        const isTyping = (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target?.isContentEditable);
        if (!isTyping && /^[1-6]$/.test(e.key)) {
            const idx = parseInt(e.key, 10) - 1;
            if (tabOrder[idx]) { e.preventDefault(); Studio.switchTab(tabOrder[idx]); }
        } else if (!isTyping && e.key === '/') {
            const active = document.querySelector('.tab--active');
            const search = active?.querySelector('input[type="search"], input[placeholder*="szuk" i]');
            if (search) { e.preventDefault(); search.focus(); search.select?.(); }
        } else if (isTyping && e.key === 'Escape') {
            e.target.blur?.();
        }
    });

    // Browser-level dirty-state guard
    window.addEventListener('beforeunload', (e) => {
        if (Studio._dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    document.getElementById('btn-global-refresh').onclick = async () => {
        document.getElementById('btn-global-refresh').classList.add('rotate');
        await Studio.refreshAssets(true);
        await Studio.refreshMenu();
        document.getElementById('nav-library-count').textContent = Studio.assets?.total ?? '·';
        Studio._tabs[Studio._active]?.onEnter?.();
        Studio.toast('Odświeżono.', 'ok', 1600);
    };

    document.getElementById('btn-open-preview').onclick = async () => {
        const r = await StudioApi.previewUrl('');
        if (r.success && r.data?.iframeUrl) window.open(r.data.iframeUrl, '_blank');
        else Studio.toast(r.message || 'Nie można uzyskać URL.', 'err');
    };

    // Deep-link from Menu Studio: ?tab=director&item=SKU
    // Kluczowa kolejność: najpierw ustawiamy pendingItemSku, DOPIERO potem switchTab().
    // Inaczej DirectorApp.onEnter() zobaczy null i auto-select dania się nie uruchomi.
    const qs = new URLSearchParams(window.location.search);
    const initialTab  = qs.get('tab');
    const initialItem = qs.get('item');
    const validTabs = new Set(['library', 'director', 'conductor', 'companions', 'surface', 'preview']);
    if (initialTab && validTabs.has(initialTab)) {
        if (initialTab === 'director' && initialItem) {
            Studio.pendingItemSku = initialItem;
            // Breadcrumb / visual hint — pokazujemy krótki toast "Deep-linked z Menu Studio"
            setTimeout(() => Studio.toast(`🔗 Deep-link z Menu Studio · ${initialItem}`, 'info', 3500), 350);
        }
        Studio.switchTab(initialTab);
    } else {
        Studio.switchTab('library');
    }
}

window.Studio = Studio; // debug handle
boot();
