/**
 * ScenographyPanel — right panel for workspace 'scenography'.
 *
 * Pokazuje Scene Kit aktywnego template (backgrounds, props, lights, badges)
 * pobierany z /api/backoffice/api_menu_studio.php action=get_scene_kit.
 *
 * Interakcje (Faza 2.3):
 *   - Click na background  → store.patch('stage.boardUrl', url) + visual feedback
 *   - Click na light       → ustawia stage.lightX/Y + ewentualny LUT hint (Faza 2.4+)
 *   - Props / badges       → preview only (w Fazie 2.4 companions panel, 2.6 promotions)
 *
 * Refresh na zmianie dania w DirectorApp (signalled via directorApp._selectedDishSku).
 */

import { StudioApi } from '../../studio_api.js';

export class ScenographyPanel {
    constructor(container, store, directorApp) {
        this._el = container;
        this._store = store;
        this._app = directorApp; // żeby czytać profile z composition_profile / dish meta
        this._currentTemplateKey = null;
        this._kitCache = {};     // templateKey → kit payload
        this._activeSection = 'backgrounds'; // backgrounds | props | lights | badges
        this._selectedBgId = null;
        this._render();
    }

    /** Publiczne — DirectorApp woła po zmianie dania lub wejściu w workspace. */
    async refresh() {
        const templateKey = this._deriveTemplateKey();
        if (!templateKey) {
            this._renderEmpty('Wybierz danie aby zobaczyć Scene Kit.');
            return;
        }
        if (templateKey !== this._currentTemplateKey) {
            this._currentTemplateKey = templateKey;
            await this._loadKit(templateKey);
        }
        this._renderMain();
    }

    // ── Private: which template for current dish? ───────────────────────────

    _deriveTemplateKey() {
        // Priorytet:
        //  1. DishMeta.compositionProfile (z menuList — będzie po re-refresh menu)
        //  2. DishMeta.category → StudioState? — nie mamy tu, więc fallback 'static_hero'
        const meta = this._store?.dishMeta || {};
        if (meta.compositionProfile) return String(meta.compositionProfile);
        // Heurystyka — jeśli spec ma pizza.layers, to 'pizza_top_down', inaczej 'static_hero'
        const hasLayers = Array.isArray(this._store?.spec?.pizza?.layers) && this._store.spec.pizza.layers.length > 0;
        return hasLayers ? 'pizza_top_down' : 'static_hero';
    }

    async _loadKit(templateKey) {
        if (this._kitCache[templateKey]) return;
        this._renderLoading();
        const r = await StudioApi.sceneKitGet(templateKey);
        if (r.success && r.data) {
            this._kitCache[templateKey] = r.data;
        } else {
            this._kitCache[templateKey] = { template: null, kit: { backgrounds: [], props: [], lights: [], badges: [] }, counts: {} };
        }
    }

    // ── Private: render ─────────────────────────────────────────────────────

    _render() {
        this._el.classList.add('sc-scenography');
        this._el.innerHTML = '';
        this._renderEmpty('Wybierz danie aby zobaczyć Scene Kit.');
    }

    _renderLoading() {
        this._el.innerHTML = `
            <div class="sc-pad">
                <div class="sc-head">
                    <i class="fa-solid fa-clapperboard"></i>
                    <span>Scenography</span>
                </div>
                <div class="sc-loading">
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    <span>Ładuję Scene Kit…</span>
                </div>
            </div>
        `;
    }

    _renderEmpty(msg) {
        this._el.innerHTML = `
            <div class="sc-pad">
                <div class="sc-head">
                    <i class="fa-solid fa-clapperboard"></i>
                    <span>Scenography</span>
                </div>
                <div class="sc-empty">
                    <i class="fa-solid fa-image"></i>
                    <p>${msg}</p>
                </div>
            </div>
        `;
    }

    _renderMain() {
        const data = this._kitCache[this._currentTemplateKey];
        if (!data) return this._renderEmpty('Brak kit-u dla tego template.');

        const tpl = data.template || {};
        const kit = data.kit || {};
        const counts = data.counts || {};
        const tabs = [
            { id: 'backgrounds', label: 'Tło',      icon: 'fa-border-all',   count: counts.backgrounds || 0 },
            { id: 'props',       label: 'Rekwizyty', icon: 'fa-mug-hot',     count: counts.props       || 0 },
            { id: 'lights',      label: 'Światło',   icon: 'fa-sun',         count: counts.lights      || 0 },
            { id: 'badges',      label: 'Odznaki',   icon: 'fa-tag',         count: counts.badges      || 0 },
        ];

        const currentBoardUrl = this._store?.spec?.stage?.boardUrl || '';
        // resolve selected background id (po URL match — działa nawet gdy reload)
        const items = kit[this._activeSection] || [];

        this._el.innerHTML = `
            <div class="sc-pad">
                <div class="sc-head">
                    <i class="fa-solid fa-clapperboard"></i>
                    <span>Scenography</span>
                    <span class="sc-head__tpl">${this._esc(tpl.name || this._currentTemplateKey || '')}</span>
                    <button class="sc-head__edit" id="sc-edit-kit" title="Edytuj Scene Kit (tła, rekwizyty, światła, odznaki)">
                        <i class="fa-solid fa-pen-to-square"></i>
                        <span>Edytuj kit</span>
                    </button>
                </div>
                <div class="sc-tabs">
                    ${tabs.map(t => `
                        <button class="sc-tab ${t.id === this._activeSection ? 'is-active' : ''}"
                                data-section="${t.id}">
                            <i class="fa-solid ${t.icon}"></i>
                            <span>${t.label}</span>
                            <small>${t.count}</small>
                        </button>
                    `).join('')}
                </div>
                <div class="sc-grid" id="sc-grid">
                    ${items.length === 0
                        ? `<div class="sc-empty-grid"><i class="fa-solid fa-circle-info"></i><p>Brak assetów w tej sekcji dla template <code>${this._esc(this._currentTemplateKey)}</code>.</p></div>`
                        : items.map(a => this._renderCard(a, currentBoardUrl)).join('')
                    }
                </div>
                ${this._activeSection === 'props' || this._activeSection === 'badges' ? `
                    <div class="sc-note">
                        <i class="fa-solid fa-flag"></i>
                        ${this._activeSection === 'props'
                            ? 'Dodawanie rekwizytów do sceny — w zakładce <strong>Companions</strong> (Faza 2.4 rozbuduje).'
                            : 'Promocje (badges na scenie) — Faza 2.6 dostarczy edytor w zakładce Promotions.'}
                    </div>
                ` : ''}
                ${tpl.photographerBrief ? `
                    <details class="sc-brief">
                        <summary><i class="fa-solid fa-camera"></i> Brief Fotograficzny</summary>
                        <div class="sc-brief__body">${this._renderMd(tpl.photographerBrief)}</div>
                    </details>
                ` : ''}
            </div>
        `;

        this._bindEvents();
    }

    _renderCard(asset, currentBoardUrl) {
        const isSelected = this._activeSection === 'backgrounds' && asset.url && asset.url === currentBoardUrl;
        const clickable = this._activeSection === 'backgrounds' || this._activeSection === 'lights';
        return `
            <button class="sc-card ${isSelected ? 'is-selected' : ''} ${clickable ? 'is-clickable' : 'is-preview'}"
                    data-asset-id="${asset.id}"
                    data-asset-url="${this._esc(asset.url)}"
                    data-asset-key="${this._esc(asset.ascii_key)}"
                    ${!clickable ? 'disabled' : ''}
                    title="${this._esc(asset.ascii_key)}">
                <div class="sc-card__thumb">
                    <img src="${this._esc(asset.url)}" alt="${this._esc(asset.ascii_key)}" loading="lazy">
                    ${isSelected ? '<div class="sc-card__check"><i class="fa-solid fa-check"></i></div>' : ''}
                </div>
                <div class="sc-card__meta">
                    <span class="sc-card__key">${this._esc(asset.ascii_key)}</span>
                    ${asset.sub_type ? `<small>${this._esc(asset.sub_type)}</small>` : ''}
                </div>
            </button>
        `;
    }

    _bindEvents() {
        this._el.querySelectorAll('.sc-tab').forEach(btn => {
            btn.onclick = () => {
                this._activeSection = btn.dataset.section;
                this._renderMain();
            };
        });
        this._el.querySelectorAll('.sc-card.is-clickable').forEach(card => {
            card.onclick = () => this._handleCardClick(card);
        });
        const editBtn = this._el.querySelector('#sc-edit-kit');
        if (editBtn) editBtn.onclick = () => this._openKitEditor();
    }

    // ── Scene Kit Editor (M023.7) ───────────────────────────────────────────

    async _openKitEditor() {
        if (!this._currentTemplateKey) return;
        const data = this._kitCache[this._currentTemplateKey];
        if (!data) return;

        const existing = document.getElementById('sc-kit-editor-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.id = 'sc-kit-editor-overlay';
        overlay.className = 'sc-kit-overlay';

        const kinds = [
            { id: 'backgrounds', label: 'Tło',        icon: 'fa-border-all' },
            { id: 'props',       label: 'Rekwizyty',  icon: 'fa-mug-hot' },
            { id: 'lights',      label: 'Światło',    icon: 'fa-sun' },
            { id: 'badges',      label: 'Odznaki',    icon: 'fa-tag' },
        ];

        const selectedByKind = {
            backgrounds: new Map(),
            props:       new Map(),
            lights:      new Map(),
            badges:      new Map(),
        };
        for (const k of Object.keys(selectedByKind)) {
            for (const a of (data.kit?.[k] || [])) {
                selectedByKind[k].set(a.id, {
                    id: a.id,
                    asciiKey: a.ascii_key,
                    previewUrl: a.url,
                    category: a.category,
                    roleHint: null,
                    subType: a.sub_type,
                });
            }
        }
        let activeKind = this._activeSection && selectedByKind[this._activeSection]
            ? this._activeSection
            : 'backgrounds';
        let libraryFilter = '';
        let libraryAssets = null;

        overlay.innerHTML = `
            <div class="sc-kit-modal">
                <header class="sc-kit-head">
                    <div>
                        <h3>Scene Kit — ${this._esc(data.template?.name || this._currentTemplateKey)}</h3>
                        <p><code>${this._esc(this._currentTemplateKey)}</code> · zmiany zapisują się do tenant-owned wersji szablonu.</p>
                    </div>
                    <button class="sc-kit-close" id="sc-kit-close" aria-label="Zamknij"><i class="fa-solid fa-xmark"></i></button>
                </header>
                <nav class="sc-kit-kinds" id="sc-kit-kinds">
                    ${kinds.map(k => `
                        <button class="sc-kit-kind ${k.id === activeKind ? 'is-active' : ''}" data-kind="${k.id}">
                            <i class="fa-solid ${k.icon}"></i>
                            <span>${k.label}</span>
                            <small class="sc-kit-kind__count" data-count-for="${k.id}">${selectedByKind[k.id].size}</small>
                        </button>
                    `).join('')}
                </nav>
                <div class="sc-kit-body">
                    <section class="sc-kit-selected">
                        <h4>W tym kubełku</h4>
                        <div class="sc-kit-chips" id="sc-kit-chips">
                            <p class="sc-kit-empty">Brak assetów.</p>
                        </div>
                    </section>
                    <section class="sc-kit-library">
                        <div class="sc-kit-library__head">
                            <h4>Biblioteka</h4>
                            <input type="search" id="sc-kit-search" placeholder="Szukaj (ascii_key, sub_type, kategoria)..." />
                        </div>
                        <div class="sc-kit-library__grid" id="sc-kit-lib">
                            <p class="sc-kit-empty"><i class="fa-solid fa-spinner fa-spin"></i> Ładuję bibliotekę…</p>
                        </div>
                    </section>
                </div>
                <footer class="sc-kit-foot">
                    <button class="sc-kit-btn sc-kit-btn--ghost" id="sc-kit-cancel">Anuluj</button>
                    <button class="sc-kit-btn sc-kit-btn--primary" id="sc-kit-save">
                        <i class="fa-solid fa-floppy-disk"></i> Zapisz kit
                    </button>
                </footer>
            </div>
        `;
        document.body.appendChild(overlay);

        const libEl = overlay.querySelector('#sc-kit-lib');
        const chipsEl = overlay.querySelector('#sc-kit-chips');
        const searchEl = overlay.querySelector('#sc-kit-search');
        const close = () => overlay.remove();

        const renderChips = () => {
            const sel = selectedByKind[activeKind];
            if (sel.size === 0) {
                chipsEl.innerHTML = '<p class="sc-kit-empty">Brak assetów — kliknij element w bibliotece aby dodać.</p>';
                return;
            }
            chipsEl.innerHTML = Array.from(sel.values()).map(a => `
                <div class="sc-kit-chip" data-id="${a.id}" title="${this._esc(a.asciiKey)}">
                    <img src="${this._esc(a.previewUrl || '')}" alt="${this._esc(a.asciiKey)}" loading="lazy">
                    <span>${this._esc(a.asciiKey)}</span>
                    <button type="button" class="sc-kit-chip__rm" data-rm-id="${a.id}" aria-label="Usuń">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            `).join('');
            chipsEl.querySelectorAll('[data-rm-id]').forEach(btn => {
                btn.onclick = () => {
                    const id = Number(btn.dataset.rmId);
                    selectedByKind[activeKind].delete(id);
                    renderChips();
                    renderLibrary();
                    updateCounts();
                };
            });
        };

        const renderLibrary = () => {
            if (!libraryAssets) return;
            const q = libraryFilter.trim().toLowerCase();
            const selected = selectedByKind[activeKind];
            const filtered = libraryAssets.filter(a => {
                if (!q) return true;
                const blob = `${a.asciiKey} ${a.subType || ''} ${a.category || ''}`.toLowerCase();
                return blob.includes(q);
            });
            if (filtered.length === 0) {
                libEl.innerHTML = '<p class="sc-kit-empty">Brak wyników w bibliotece.</p>';
                return;
            }
            libEl.innerHTML = filtered.map(a => {
                const isSel = selected.has(a.id);
                return `
                    <button class="sc-kit-libitem ${isSel ? 'is-selected' : ''}" data-asset-id="${a.id}" type="button"
                            title="${this._esc(a.asciiKey)}">
                        <img src="${this._esc(a.previewUrl || '')}" alt="${this._esc(a.asciiKey)}" loading="lazy">
                        <span class="sc-kit-libitem__key">${this._esc(a.asciiKey)}</span>
                        <small class="sc-kit-libitem__meta">${this._esc(a.category || '—')} · ${this._esc(a.subType || '—')}</small>
                        ${isSel ? '<span class="sc-kit-libitem__check"><i class="fa-solid fa-check"></i></span>' : ''}
                    </button>
                `;
            }).join('');
            libEl.querySelectorAll('[data-asset-id]').forEach(btn => {
                btn.onclick = () => {
                    const id = Number(btn.dataset.assetId);
                    const asset = libraryAssets.find(x => x.id === id);
                    if (!asset) return;
                    if (selected.has(id)) selected.delete(id);
                    else selected.set(id, asset);
                    renderChips();
                    renderLibrary();
                    updateCounts();
                };
            });
        };

        const updateCounts = () => {
            overlay.querySelectorAll('[data-count-for]').forEach(el => {
                const k = el.dataset.countFor;
                el.textContent = String(selectedByKind[k].size);
            });
        };

        overlay.querySelectorAll('.sc-kit-kind').forEach(btn => {
            btn.onclick = () => {
                activeKind = btn.dataset.kind;
                overlay.querySelectorAll('.sc-kit-kind').forEach(b => b.classList.toggle('is-active', b.dataset.kind === activeKind));
                renderChips();
                renderLibrary();
            };
        });
        searchEl.addEventListener('input', (e) => {
            libraryFilter = String(e.target.value || '');
            renderLibrary();
        });
        overlay.querySelector('#sc-kit-close').onclick = close;
        overlay.querySelector('#sc-kit-cancel').onclick = close;
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        overlay.querySelector('#sc-kit-save').onclick = async () => {
            const btn = overlay.querySelector('#sc-kit-save');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Zapisuję…';
            const kit = {};
            for (const k of Object.keys(selectedByKind)) {
                kit[k] = Array.from(selectedByKind[k].keys());
            }
            try {
                const r = await StudioApi.sceneKitSave(this._currentTemplateKey, kit);
                if (r.success) {
                    this._toast(r.data?.cloned
                        ? 'Zapisano (utworzono tenant-owned wersję szablonu).'
                        : 'Zapisano scene kit.');
                    delete this._kitCache[this._currentTemplateKey];
                    await this._loadKit(this._currentTemplateKey);
                    this._renderMain();
                    close();
                } else {
                    alert(r.message || 'Nie udało się zapisać kit-u.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Zapisz kit';
                }
            } catch (err) {
                console.error('[ScenographyPanel.saveKit]', err);
                alert('Błąd sieci podczas zapisu.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Zapisz kit';
            }
        };

        // Renderuj chipy od razu; bibliotekę asynchronicznie
        renderChips();
        updateCounts();
        try {
            const r = await StudioApi.assetsListCompact(800);
            libraryAssets = (r.success && Array.isArray(r.data?.assets)) ? r.data.assets : [];
        } catch (_) {
            libraryAssets = [];
        }
        renderLibrary();
    }

    _handleCardClick(card) {
        const url = card.dataset.assetUrl;
        const key = card.dataset.assetKey;
        if (!url || !key) return;

        if (this._activeSection === 'backgrounds') {
            this._store.patch('stage.boardUrl', url, `Set background: ${key}`);
            // Visual feedback — re-render żeby pokazać check
            this._renderMain();
            this._toast(`Tło: ${key}`);
            return;
        }
        if (this._activeSection === 'lights') {
            // Prosty mapping ascii_key → (lightX, lightY) preset
            const lightPresets = {
                'light_warm_top':       { x: 50, y: 15 },
                'light_warm_rim':       { x: 80, y: 40 },
                'light_cold_side':      { x: 20, y: 50 },
                'light_soft_box':       { x: 50, y: 30 },
                'light_candle_glow':    { x: 65, y: 70 },
                'light_golden_hour':    { x: 30, y: 20 },
                'light_dramatic_rim':   { x: 90, y: 20 },
                'light_neon_pink':      { x: 10, y: 80 },
            };
            const p = lightPresets[key] || { x: 50, y: 20 };
            this._store.patch('stage.lightX', p.x, `Light preset: ${key}`);
            this._store.patch('stage.lightY', p.y, `Light preset Y: ${key}`);
            this._renderMain();
            this._toast(`Światło: ${key}`);
            return;
        }
    }

    _toast(msg) {
        // Lekki toast — jeśli Studio (parent window) ma toast, użyj. Inaczej fallback.
        try { window.Studio?.toast?.(msg, 'ok', 1600); }
        catch (_) {}
    }

    // ── Private: markdown (subset) + escape ─────────────────────────────────

    _renderMd(md) {
        const s = this._esc(md);
        return s
            .replace(/^##\s+(.*?)$/gm, '<h4>$1</h4>')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n- /g, '\n• ')
            .replace(/\n/g, '<br>');
    }

    _esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    }
}
