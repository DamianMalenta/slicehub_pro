/**
 * DirectorApp — Hollywood Director's Suite orchestrator.
 * Mounts into an Online Studio tab, wires all panels + magic + shortcuts.
 */
import { SceneStore, createDefaultSpec } from './state/SceneStore.js';
import { SelectionManager } from './state/SelectionManager.js';
import { ShortcutManager } from './lib/ShortcutManager.js';
import { ToolbarPanel } from './panels/ToolbarPanel.js';
import { ViewportPanel } from './panels/ViewportPanel.js';
import { HierarchyPanel } from './panels/HierarchyPanel.js';
import { InspectorPanel } from './panels/InspectorPanel.js';
import { TimelinePanel } from './panels/TimelinePanel.js';
import { ScenographyPanel } from './panels/ScenographyPanel.js';
import { PromotionsPanel } from './panels/PromotionsPanel.js';
import { magicEnhance } from './magic/MagicEnhance.js';
import { magicRelight } from './magic/MagicRelight.js';
import { magicColorGrade } from './magic/MagicColorGrade.js';
import { applyBake } from './magic/MagicBake.js';
import { magicDust } from './magic/MagicDust.js';
import { magicCompanions } from './magic/MagicCompanions.js';
import { profileDish, profileLabel, profileIcon } from './lib/DishProfiler.js';
import { getPreset } from './lib/ScenePresets.js';
import { AssetPicker } from './lib/AssetPicker.js';
import { computeHarmonyScore, scoreTier } from './harmony/HarmonyScore.js';
import { magicConform } from './magic/MagicConform.js';
import { magicHarmonize } from './magic/MagicHarmonize.js';

export class DirectorApp {
    constructor() {
        this._store = new SceneStore();
        this._selection = new SelectionManager();
        this._shortcuts = new ShortcutManager();
        this._root = null;
        this._panels = {};
        this._Studio = null;
        this._Api = null;
        this._dishList = [];
        this._selectedDishSku = null;
        this._companionsLib = [];
        this._workspace = 'compose';
        this._autoSaveTimer = null;
        /** Rekord `sh_atelier_scenes.id` dla aktualnego dania (z load/save). */
        this._atelierSceneId = null;
        /** Numer wersji sceny używany do optimistic locking / 409 conflict modal. */
        this._sceneVersion = 0;
        /** G4 Harmony Score — cache per-dish (scene_id → score), liczone na frontu. */
        this._harmony = { score: 0, layerCount: 0, outliers: [], variance: {} };
        this._harmonyDirty = false;
        this._harmonyPersistTimer = null;
    }

    mount(root, Studio, Api) {
        this._root = root;
        this._Studio = Studio;
        this._Api = Api;
        this._picker = new AssetPicker(Studio);
        this._buildLayout();
        this._registerShortcuts();

        this._store.onChange(() => {
            this._panels.toolbar?.updateUndoRedo(this._store.canUndo(), this._store.canRedo());
            this._recomputeHarmony();
            this._renderStatusBar();
            Studio.markDirty(true);
            this._scheduleAutoSave();
        });

        this._selection.onChange(() => this._renderStatusBar());

        this._loadDishList().then(() => this._renderDishList());
    }

    async onEnter() {
        this._shortcuts.enable();
        if (!this._Studio?.menu) {
            try { await this._Studio?.refreshMenu?.(); } catch (_) {}
        }
        await this._loadDishList();
        this._renderDishList();

        // M022 deep-link: jeśli studio_app.js przekazało pendingItemSku
        // (z URL ?tab=director&item=SKU z Menu Studio) — auto-wybierz danie.
        const pending = this._Studio?.pendingItemSku;
        if (pending && typeof pending === 'string' && pending.length) {
            // Clear najpierw (żeby unikać re-trigger przy kolejnych wejściach do tabu)
            try { this._Studio.pendingItemSku = null; } catch (_) {}
            try {
                await this._selectDish(pending);
            } catch (e) {
                // SKU nie znaleziony? Cicho, user widzi listę i wybiera ręcznie.
            }
        }
    }

    onLeave() {
        this._shortcuts.disable();
    }

    _buildLayout() {
        this._root.innerHTML = '';
        /* Musi zostać klasa `tab` — inaczej switchTab nie ukrywa panelu na innych zakładkach. */
        this._root.className = 'tab director-root';

        this._root.innerHTML = `
            <div class="dt-shell">
                <div class="dt-shell__toolbar" id="dt-toolbar"></div>
                <div class="dt-shell__body">
                    <div class="dt-shell__left" id="dt-left">
                        <div class="dt-dish-picker" id="dt-dish-picker">
                            <div class="dt-panel-head"><i class="fa-solid fa-utensils"></i> Menu <span class="dt-badge" id="dt-dish-count">0</span></div>
                            <div class="dt-search-wrap">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <input class="dt-search" id="dt-dish-search" placeholder="Szukaj dania..." type="search">
                            </div>
                            <div id="dt-dish-list" class="dt-dish-list"></div>
                        </div>
                        <div id="dt-hierarchy"></div>
                    </div>
                    <div class="dt-shell__center" id="dt-center"></div>
                    <div class="dt-shell__right" id="dt-right"></div>
                    <div class="dt-shell__right dt-shell__scenography" id="dt-scenography" hidden></div>
                    <div class="dt-shell__right dt-shell__promotions" id="dt-promotions" hidden></div>
                </div>
                <div class="dt-shell__bottom" id="dt-bottom"></div>
                <div class="dt-statusbar" id="dt-statusbar"></div>
            </div>
        `;

        this._panels.toolbar = new ToolbarPanel(
            this._root.querySelector('#dt-toolbar'),
            {
                onWorkspace: (id) => this._setWorkspace(id),
                onTool: (id) => this._setTool(id),
                onMagic: (id) => this._runMagic(id),
                onPreset: (name) => this._applyPreset(name),
                onUndo: () => this._store.undo(),
                onRedo: () => this._store.redo(),
                onSave: () => this._save(),
                onPreview: () => this._panels.viewport?.togglePreview(),
                onBeforeAfter: () => this._panels.viewport?.toggleBeforeAfter(),
                onMobilePreview: () => this._panels.viewport?.toggleMobilePreview(),
                onShortcutLegend: () => this._showShortcutLegend(),
                onAddLayer: () => this.addLayer(),
                onAddCompanion: () => this.addCompanion(),
                onCamera: (id) => this._setActiveCamera(id),
            }
        );

        this._panels.viewport = new ViewportPanel(
            this._root.querySelector('#dt-center'),
            this._store,
            this._selection,
            this
        );

        this._panels.hierarchy = new HierarchyPanel(
            this._root.querySelector('#dt-hierarchy'),
            this._store,
            this._selection,
            this
        );

        this._panels.inspector = new InspectorPanel(
            this._root.querySelector('#dt-right'),
            this._store,
            this._selection,
            this
        );

        this._panels.scenography = new ScenographyPanel(
            this._root.querySelector('#dt-scenography'),
            this._store,
            this
        );

        this._panels.promotions = new PromotionsPanel(this._root.querySelector('#dt-promotions'), this);

        this._panels.timeline = new TimelinePanel(
            this._root.querySelector('#dt-bottom'),
            this._store,
            this._selection
        );

        this._root.querySelector('#dt-dish-search')?.addEventListener('input', (e) => {
            this._renderDishList(e.target.value);
        });

        this._root.addEventListener('focusout', (e) => {
            const target = e.target;
            if (!(target instanceof HTMLElement)) return;
            if (!target.closest('.dt-inspector')) return;
            if (!(target.matches('input, select, textarea'))) return;
            clearTimeout(this._blurSaveTimer);
            this._blurSaveTimer = setTimeout(() => this._save(true), 120);
        });

        this._renderDishList();
        this._renderStatusBar();
    }

    _renderStatusBar() {
        const bar = this._root.querySelector('#dt-statusbar');
        if (!bar) return;
        const profile = profileDish(this._store.dishMeta || {});
        const layerCount = this._store.spec.pizza?.layers?.length || 0;
        const compCount = this._store.spec.companions?.length || 0;
        const dish = this._store.dishMeta?.name || 'Brak dania';

        const h = this._harmony || { score: 0, outliers: [] };
        const tier = scoreTier(h.score);
        const outCnt = (h.outliers || []).length;
        const harmonyHtml = this._selectedDishSku
            ? `<button class="dt-stat-item dt-stat-item--harmony dt-stat-item--${tier.tier}"
                    id="dt-harmony-badge"
                    title="Harmony Score ${h.score}/100 · ${tier.label}${outCnt ? ` · ${outCnt} outlier(s)` : ''} — kliknij, aby zobaczyć co rozbija scenę">
                 <i class="fa-solid ${tier.iconClass}"></i>
                 <strong>Harmony ${h.score}</strong>
                 ${outCnt ? `<span class="dt-stat-item__count">${outCnt}</span>` : ''}
               </button>`
            : '';

        bar.innerHTML = `
            <div class="dt-statusbar__left">
                <span class="dt-stat-item"><i class="fa-solid fa-utensils"></i> ${dish}</span>
                ${this._selectedDishSku ? `<span class="dt-stat-item"><i class="fa-solid ${profileIcon(profile)}"></i> ${profileLabel(profile)}</span>` : ''}
                <span class="dt-stat-item"><i class="fa-solid fa-layer-group"></i> ${layerCount} warstw</span>
                <span class="dt-stat-item"><i class="fa-solid fa-mug-hot"></i> ${compCount} companions</span>
                ${harmonyHtml}
            </div>
            <div class="dt-statusbar__right">
                <span class="dt-stat-item" id="dt-autosave"><i class="fa-solid fa-cloud-check"></i> Zapisano</span>
            </div>
        `;

        const badge = bar.querySelector('#dt-harmony-badge');
        if (badge) badge.onclick = () => this._showHarmonyOutliers();
    }

    /** G4: Zapis Harmony do cache (best-effort, silent).
     *  2026-04-19: wysyłamy też breakdown (completeness/polish/consistency) — backend
     *  spakuje w outliers_json.breakdown, żeby Style Conductor widział dlaczego scena
     *  ma taki a nie inny score (nie tylko sam numer).
     */
    async _persistHarmony() {
        if (!this._harmonyDirty) return;
        if (!this._selectedDishSku) return;
        this._harmonyDirty = false;
        try {
            const h = this._harmony || {};
            await this._Api?.call?.('scene_harmony_save', {
                itemSku:       this._selectedDishSku,
                harmonyScore:  h.score || 0,
                layerCount:    h.layerCount || 0,
                outliers:      h.outliers || [],           // actionable hints (UI-level)
                layerOutliers: h.layerOutliers || [],      // per-layer (Magic Harmonize)
                breakdown:     h.breakdown || null,        // completeness/polish/consistency
                variance:      h.variance || {},
            });
        } catch (_) {}
    }

    /** G4: Oblicz harmony score po każdej mutacji spec. */
    _recomputeHarmony() {
        try {
            this._harmony = computeHarmonyScore(this._store.spec);
            this._harmonyDirty = true;
        } catch (e) {
            console.warn('[Director] Harmony compute failed', e);
        }
    }

    /** G4 (v2 · 2026-04-19): Modal z transparentnym rozbiciem wyniku Harmony:
     *   • komponenty: Kompletność / Dopracowanie / Spójność — liczby widoczne, max obok
     *   • actionable outliers („+10 · Dodaj sos") → kliknięcie dodaje sugestię
     *   • Magic Conform / Magic Harmonize w akcjach (gdy jest co naprawiać)
     *   • Per-layer outliers (jeśli są) — do klikania wybranej warstwy
     */
    _showHarmonyOutliers() {
        const h = this._harmony || {};
        const tier = scoreTier(h.score || 0);
        const outliers = h.outliers || [];
        const layerOutliers = h.layerOutliers || [];
        const bd = h.breakdown || {};
        const comp = bd.completeness || { score: 0, max: 50 };
        const pol  = bd.polish       || { score: 0, max: 30 };
        const con  = bd.consistency  || { score: 0, max: 20 };

        const body = document.createElement('div');
        body.className = 'dt-harmony-panel';
        body.innerHTML = `
            <div class="dt-harmony-head" style="color:${tier.color}">
                <i class="fa-solid ${tier.iconClass}"></i>
                <strong>${h.score || 0}/100 — ${tier.label}</strong>
            </div>

            <div class="dt-harmony-breakdown">
                <div class="dt-harmony-metric">
                    <div class="dt-harmony-metric__label"><i class="fa-solid fa-list-check"></i> Kompletność</div>
                    <div class="dt-harmony-metric__bar"><span style="width:${Math.round((comp.score / comp.max) * 100)}%; background:#22c55e"></span></div>
                    <div class="dt-harmony-metric__num">${comp.score}<small>/${comp.max}</small></div>
                </div>
                <div class="dt-harmony-metric">
                    <div class="dt-harmony-metric__label"><i class="fa-solid fa-wand-magic-sparkles"></i> Dopracowanie</div>
                    <div class="dt-harmony-metric__bar"><span style="width:${Math.round((pol.score / pol.max) * 100)}%; background:#facc15"></span></div>
                    <div class="dt-harmony-metric__num">${pol.score}<small>/${pol.max}</small></div>
                </div>
                <div class="dt-harmony-metric">
                    <div class="dt-harmony-metric__label"><i class="fa-solid fa-arrows-left-right-to-line"></i> Spójność</div>
                    <div class="dt-harmony-metric__bar"><span style="width:${Math.round((con.score / con.max) * 100)}%; background:#60a5fa"></span></div>
                    <div class="dt-harmony-metric__num">${con.score}<small>/${con.max}</small></div>
                </div>
            </div>

            <p class="dt-harmony-desc">
                Scena ma <strong>${h.layerCount || 0}</strong> warstw,
                dopracowanych <strong>${pol.polishedLayers || 0}/${pol.totalLayers || 0}</strong>,
                LUT ${pol.hasLut ? '<b style="color:#22c55e">jest</b>' : '<b style="color:#ef4444">brak</b>'},
                companions ${pol.hasCompanions ? '<b style="color:#22c55e">są</b>' : '<b style="color:#ef4444">brak</b>'}.
            </p>

            ${outliers.length ? `<h4 class="dt-harmony-subhead">Co poprawić, żeby podbić wynik</h4>` : ''}
            <div class="dt-harmony-outliers"></div>

            ${layerOutliers.length ? `<h4 class="dt-harmony-subhead">Warstwy odstające od reszty (spójność)</h4>
            <div class="dt-harmony-layers"></div>` : ''}

            <div class="dt-harmony-actions">
                <button class="btn btn--ghost" id="dt-harmony-conform">
                    <i class="fa-solid fa-compass-drafting"></i> Magic Conform (dopasuj wszystkie)
                </button>
                <button class="btn btn--accent" id="dt-harmony-fix" ${layerOutliers.length ? '' : 'disabled'}>
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Napraw odstające (Magic Harmonize)
                </button>
            </div>
        `;

        const listEl = body.querySelector('.dt-harmony-outliers');
        if (outliers.length) {
            outliers.forEach(o => {
                const row = document.createElement('div');
                row.className = `dt-harmony-outlier dt-harmony-outlier--${o.type || 'info'}`;
                row.innerHTML = `
                    <span class="dt-harmony-outlier__icon"><i class="fa-solid ${o.icon || 'fa-circle-info'}"></i></span>
                    <span class="dt-harmony-outlier__label">${o.label}</span>
                    <span class="dt-harmony-outlier__delta">${o.delta || ''}</span>
                `;
                listEl.appendChild(row);
            });
        } else if (listEl) {
            listEl.innerHTML = `<p style="opacity:.6;text-align:center;padding:16px">
                <i class="fa-solid fa-check-circle" style="color:#22c55e;font-size:22px"></i><br>
                Brak sugestii — scena jest kompletna i dopracowana.</p>`;
        }

        const modalRef = this._Studio?.modal?.({ title: 'Harmony Score — rozbicie wyniku', body, wide: false });

        const layersEl = body.querySelector('.dt-harmony-layers');
        if (layersEl && layerOutliers.length) {
            layerOutliers.forEach(o => {
                const row = document.createElement('div');
                row.className = 'dt-harmony-layer';
                row.innerHTML = `
                    <div class="dt-harmony-layer__head">
                        <strong>${o.layerSku}</strong>
                        <span class="dt-harmony-layer__type">${o.type}</span>
                        <span class="dt-harmony-layer__sev">Δ ${o.severity}</span>
                    </div>
                    <small>shadow ±${o.deltas.shadow} · feather ±${o.deltas.feather} · alpha ±${o.deltas.alpha}</small>
                `;
                row.onclick = () => {
                    this._selection.select('layer', o.layerSku);
                    modalRef?.close?.();
                };
                layersEl.appendChild(row);
            });
        }

        body.querySelector('#dt-harmony-conform').onclick = () => {
            this._runMagic('conform');
            modalRef?.close?.();
        };
        const fixBtn = body.querySelector('#dt-harmony-fix');
        if (fixBtn) fixBtn.onclick = () => {
            this._runMagic('harmonize');
            modalRef?.close?.();
        };

        return modalRef;
    }

    async _loadDishList() {
        const menu = this._Studio?.menu;
        const items = Array.isArray(menu?.items)
            ? menu.items
            : (Array.isArray(menu) ? menu : []);
        if (!menu) console.warn('[Director] Studio.menu is empty');
        else if (!items.length) console.warn('[Director] Menu loaded but no items', menu);
        this._dishList = items.map(it => ({
            sku: it.sku,
            name: it.name,
            category: it.categoryName || 'Inne',
            description: it.description || '',
            price: it.price,
            heroUrl: it.imageUrl || it.heroUrl || '',
            isActive: it.isActive !== false,
            isPizza: !!it.isPizza,
        }));

        this._companionsLib = this._dishList
            .filter(d => /napoj|drink|sok|cola|piwo|sos|sauce|dip|dodatek|side/i.test(d.category || ''))
            .map(d => ({
                sku: d.sku,
                name: d.name,
                companionType: 'product',
                heroUrl: d.heroUrl,
            }));

        const countEl = this._root.querySelector('#dt-dish-count');
        if (countEl) countEl.textContent = this._dishList.length;
    }

    _renderDishList(filter = '') {
        const list = this._root.querySelector('#dt-dish-list');
        if (!list) return;
        list.innerHTML = '';
        const f = filter.trim().toLowerCase();
        const filtered = f
            ? this._dishList.filter(d =>
                d.name.toLowerCase().includes(f) ||
                (d.sku || '').toLowerCase().includes(f) ||
                (d.category || '').toLowerCase().includes(f))
            : this._dishList;

        if (filtered.length === 0) {
            const menu = this._Studio?.menu;
            const totalInMenu = menu?.items?.length || 0;
            const empty = document.createElement('div');
            empty.className = 'dt-dish-empty';
            if (f) {
                empty.innerHTML = `<i class="fa-solid fa-magnifying-glass"></i><span>Brak wyników dla "${filter}"</span>`;
            } else if (!menu) {
                empty.innerHTML = `
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    <span>Ładowanie menu...</span>
                    <button class="dt-dish-retry" id="dt-dish-retry"><i class="fa-solid fa-rotate"></i> Odśwież menu</button>
                `;
            } else if (totalInMenu === 0) {
                empty.innerHTML = `
                    <i class="fa-solid fa-box-open"></i>
                    <span>Brak dań w menu (sh_menu_items)</span>
                    <small>Dodaj dania w Studio → Menu</small>
                `;
            } else {
                empty.innerHTML = `
                    <i class="fa-solid fa-eye-slash"></i>
                    <span>Wszystkie ${totalInMenu} dań są nieaktywne</span>
                    <small>Aktywuj danie w Studio → Menu</small>
                    <button class="dt-dish-retry" id="dt-dish-retry"><i class="fa-solid fa-rotate"></i> Odśwież</button>
                `;
            }
            list.appendChild(empty);
            const retry = list.querySelector('#dt-dish-retry');
            if (retry) retry.onclick = async () => {
                retry.disabled = true;
                await this._Studio?.refreshMenu?.();
                await this._loadDishList();
                this._renderDishList(filter);
            };
            return;
        }

        const byCat = new Map();
        filtered.forEach(d => {
            if (!byCat.has(d.category)) byCat.set(d.category, []);
            byCat.get(d.category).push(d);
        });

        byCat.forEach((dishes, cat) => {
            const group = document.createElement('div');
            group.className = 'dt-dish-group';
            const head = document.createElement('div');
            head.className = 'dt-dish-group__head';
            head.textContent = cat || 'Inne';
            group.appendChild(head);

            dishes.forEach(d => {
                const row = document.createElement('button');
                const cls = ['dt-dish-row'];
                if (d.sku === this._selectedDishSku) cls.push('is-active');
                if (!d.isActive) cls.push('is-inactive');
                row.className = cls.join(' ');
                const thumbBg = d.heroUrl
                    ? `background-image:url('${String(d.heroUrl).replace(/'/g, "\\'")}');`
                    : '';
                const initial = (d.name || d.sku || '?').charAt(0).toUpperCase();
                row.innerHTML = `
                    <span class="dt-dish-row__thumb" style="${thumbBg}">${d.heroUrl ? '' : initial}</span>
                    <span class="dt-dish-row__body">
                        <span class="dt-dish-row__name">${d.isPizza ? '<i class="fa-solid fa-pizza-slice" style="color:var(--dt-accent);font-size:9px;margin-right:4px"></i>' : ''}${d.name || d.sku}${!d.isActive ? ' <small style="opacity:.5">(off)</small>' : ''}</span>
                        <small class="dt-dish-row__meta">${d.sku}${d.price ? ' · ' + Number(d.price).toFixed(2).replace('.', ',') + ' zł' : ''}</small>
                    </span>
                `;
                row.onclick = () => this._selectDish(d.sku);
                group.appendChild(row);
            });

            list.appendChild(group);
        });
    }

    async _selectDish(sku) {
        this._selectedDishSku = sku;
        this._renderDishList(this._root.querySelector('#dt-dish-search')?.value || '');

        const dish = this._dishList.find(d => d.sku === sku);
        const meta = {
            sku,
            name: dish?.name || sku,
            description: dish?.description || '',
            categoryName: dish?.category || 'Danie',
            price: dish?.price,
            modifierNames: [],
        };

        this._setStatus('Ładowanie…', 'loading');

        let sceneRes = null;
        try { sceneRes = await this._Api?.call?.('director_load_scene', { itemSku: sku }); } catch (_) {}

        if (sceneRes?.success && sceneRes.data?.sceneSpec) {
            // M3 #4 · Auto-perspective — propaguj active_camera_preset do dishMeta,
            // żeby ViewportPanel mógł wyrenderować ten sam kadr co storefront.
            const metaWithScene = {
                ...meta,
                activeCamera: sceneRes.data.activeCamera || null,
                activeLut:    sceneRes.data.activeLut    || null,
                atmosphericEffects: Array.isArray(sceneRes.data.atmosphericEffects)
                    ? sceneRes.data.atmosphericEffects : [],
            };
            this._store.load(sceneRes.data.sceneSpec, metaWithScene);
            this._sceneVersion = Number(sceneRes.data.version || 0);
            this._setStatus('Załadowano istniejącą scenę', 'ok');
        } else {
            let layers = [];
            try {
                const composerRes = await this._Api?.composerLoadDish?.(sku);
                if (composerRes?.success && composerRes.data?.layers) layers = composerRes.data.layers;
            } catch (_) {}

            const spec = createDefaultSpec(meta);
            spec.pizza.layers = layers.map(L => ({
                layerSku: L.layerSku || L.layer_sku,
                assetUrl: L.assetUrl || L.asset_url || '',
                zIndex: L.zIndex ?? L.z_index ?? 10,
                calScale: L.calScale ?? L.cal_scale ?? 1,
                calRotate: L.calRotate ?? L.cal_rotate ?? 0,
                offsetX: L.offsetX ?? L.offset_x ?? 0,
                offsetY: L.offsetY ?? L.offset_y ?? 0,
                blendMode: 'normal',
                alpha: 1,
                feather: 0,
                brightness: 1, saturation: 1, hueRotate: 0,
                shadowStrength: 0.45,
                isPopUp: false,
                visible: true, locked: false,
            }));

            const boardAsset = this._findBoardAsset();
            if (boardAsset) spec.stage.boardUrl = boardAsset;

            this._store.load(spec, meta);
            this._sceneVersion = 0;
            this._setStatus(layers.length ? `${layers.length} warstw z Kompozytora` : 'Nowa scena', 'ok');
        }

        this._atelierSceneId = sceneRes?.success ? (sceneRes.data?.sceneId ?? null) : null;

        this._selection.select('pizza');
        this._Studio?.markDirty(false);
        this._renderStatusBar();
        // M3 #4 — zsynchronizuj toolbar z zapisanym presetem kamery
        this._panels.toolbar?.setCamera?.(this._store.dishMeta?.activeCamera || '');

        // M023: Scene Kit refresh — jeśli workspace Scenography aktywny, re-load kit dla nowego dania.
        if (this._workspace === 'scenography') {
            this._panels.scenography?.refresh();
        } else if (this._workspace === 'promotions') {
            this._panels.promotions?.refresh();
        } else {
            // Bufor cache'u czyścimy (różne dania = potencjalnie różne composition_profile)
            this._panels.scenography && (this._panels.scenography._currentTemplateKey = null);
        }

        void this._panels.viewport?.reloadPromotionSlots?.();
    }

    _findBoardAsset() {
        const lib = this._Studio?.library;
        const items = lib?.items || [];
        const board = items.find(a =>
            (a.category && /board|surface|deska/i.test(a.category)) ||
            /board|plate|deska/i.test(a.asciiKey || a.filename || '')
        );
        return board?.url || '';
    }

    _runMagic(id) {
        if (!this._selectedDishSku) {
            this._Studio?.toast?.('Wybierz najpierw danie', 'warn', 1800);
            return;
        }

        const profile = profileDish(this._store.dishMeta || {});

        switch (id) {
            case 'enhance':
                magicEnhance(this._store, this._companionsLib);
                this._Studio?.toast?.('Magic Enhance zastosowany!', 'ok', 2000);
                break;
            case 'relight': {
                const cfg = magicRelight(profile);
                this._store.patch('stage', cfg, 'Magic Relight');
                this._Studio?.toast?.('Magic Relight', 'ok', 1500);
                break;
            }
            case 'colorgrade': {
                const cfg = magicColorGrade(profile);
                this._store.patch('stage', cfg, 'Magic Color Grade');
                this._Studio?.toast?.('Magic Color Grade', 'ok', 1500);
                break;
            }
            case 'bake':
                applyBake(this._store);
                this._Studio?.toast?.('Magic Bake', 'ok', 1500);
                break;
            case 'dust': {
                const cfg = magicDust(profile);
                this._store.patch('ambient', cfg, 'Magic Dust');
                this._Studio?.toast?.('Magic Dust', 'ok', 1500);
                break;
            }
            case 'companions': {
                const comps = magicCompanions(this._store.spec, this._store.dishMeta, this._companionsLib);
                this._store.patch('companions', comps, 'Magic Companions');
                this._Studio?.toast?.(`Dodano ${comps.length} companions`, 'ok', 1500);
                break;
            }
            case 'conform': {
                const layersIn = this._store.spec.pizza?.layers || [];
                if (!layersIn.length) {
                    this._Studio?.toast?.('Brak warstw do dopasowania.', 'warn', 1500);
                    break;
                }
                const next = magicConform(layersIn, this._store.spec.stage || {});
                this._store.patch('pizza.layers', next, 'Magic Conform');
                this._Studio?.toast?.('Magic Conform — wszystkie warstwy dopasowane do sceny.', 'ok', 2000);
                break;
            }
            case 'harmonize': {
                const layersIn = this._store.spec.pizza?.layers || [];
                // 2026-04-19: używamy layerOutliers (per-layer odstające od mediany grupy)
                // zamiast actionable outliers[] (które są UI-hintami dla managera).
                const skus = (this._harmony?.layerOutliers || []).map(o => o.layerSku);
                if (!skus.length) {
                    this._Studio?.toast?.('Brak odstających warstw — scena już jest spójna.', 'ok', 1500);
                    break;
                }
                const next = magicHarmonize(layersIn, skus);
                this._store.patch('pizza.layers', next, `Magic Harmonize (${skus.length} warstw)`);
                this._Studio?.toast?.(`Magic Harmonize — naprawiono ${skus.length} warstw(y).`, 'ok', 2000);
                break;
            }
        }
        this._renderStatusBar();
    }

    _applyPreset(name) {
        const preset = getPreset(name);
        if (!preset) return;
        const partial = preset.apply();
        const spec = structuredClone(this._store.spec);
        if (partial.pizza) Object.assign(spec.pizza, partial.pizza);
        if (partial.infoBlock) Object.assign(spec.infoBlock, partial.infoBlock);
        if (partial.stage) Object.assign(spec.stage, partial.stage);
        this._store.replace(spec, `Preset: ${preset.label}`);
        this._Studio?.toast?.(`Preset: ${preset.label}`, 'ok', 1500);
    }

    _scheduleAutoSave() {
        if (!this._selectedDishSku) return;
        if (this._autoSaveTimer) clearTimeout(this._autoSaveTimer);
        this._setStatus('Edytowanie…', 'dirty');
        this._autoSaveTimer = setTimeout(() => this._save(true), 2500);
    }

    async _save(silent = false, options = {}) {
        if (!this._selectedDishSku) {
            if (!silent) this._Studio?.toast?.('Wybierz najpierw danie', 'warn');
            return;
        }
        const { forceSave = false } = options;
        this._setStatus('Zapisywanie…', 'loading');
        try {
            const res = await this._Api?.call?.('director_save_scene', {
                itemSku: this._selectedDishSku,
                specJson: JSON.stringify(this._store.spec),
                snapshotLabel: silent ? 'Auto-save' : `Save ${new Date().toLocaleTimeString('pl')}`,
                expectedVersion: this._sceneVersion,
                forceSave,
                // M3 #4 · Auto-perspective — zapisz wybrany preset kamery wraz ze sceną
                activeCamera: this._store.dishMeta?.activeCamera || null,
            });
            if (res?.success) {
                const hadSceneRow = this._atelierSceneId != null;
                if (res.data?.sceneId != null) this._atelierSceneId = res.data.sceneId;
                if (res.data?.version != null) this._sceneVersion = Number(res.data.version) || this._sceneVersion;
                this._Studio?.markDirty(false);
                this._setStatus('Zapisano', 'ok');
                this._persistHarmony();
                if (!silent) this._Studio?.toast?.('Scena zapisana!', 'ok', 1800);
                // Promocje: odśwież po ręcznym zapisie lub gdy pierwszy raz powstał rekord sceny (auto-save).
                if (
                    this._workspace === 'promotions' &&
                    (!silent || !hadSceneRow)
                ) {
                    this._panels.promotions?.refresh();
                }
                void this._panels.viewport?.reloadPromotionSlots?.();
            } else {
                if (res?.status === 409) {
                    this._setStatus('Konflikt wersji', 'err');
                    this._showSaveConflictModal(res);
                    return;
                }
                this._setStatus('Błąd zapisu', 'err');
                if (!silent) this._Studio?.toast?.(res?.message || 'Błąd zapisu', 'err');
            }
        } catch (e) {
            this._setStatus('Błąd zapisu', 'err');
            if (!silent) this._Studio?.toast?.('Błąd sieci', 'err');
        }
    }

    _showSaveConflictModal(res) {
        const body = document.createElement('div');
        body.className = 'dt-conflict-modal';
        body.innerHTML = `
            <p>Ta scena została zmieniona w innym oknie lub po wcześniejszym zapisie.</p>
            <p><strong>Twoja wersja:</strong> ${this._sceneVersion} · <strong>Aktualna wersja:</strong> ${res?.data?.currentVersion ?? 'n/a'}</p>
            <div class="dt-harmony-actions">
                <button class="btn btn--ghost" id="dt-conflict-reload">
                    <i class="fa-solid fa-rotate"></i> Przeładuj z serwera
                </button>
                <button class="btn btn--accent" id="dt-conflict-force">
                    <i class="fa-solid fa-floppy-disk"></i> Nadpisz mimo konfliktu
                </button>
            </div>
        `;
        const modal = this._Studio?.modal?.({ title: 'Konflikt zapisu 409', body, wide: false });
        body.querySelector('#dt-conflict-reload')?.addEventListener('click', async () => {
            modal?.close?.();
            if (this._selectedDishSku) await this._selectDish(this._selectedDishSku);
        });
        body.querySelector('#dt-conflict-force')?.addEventListener('click', async () => {
            modal?.close?.();
            this._sceneVersion = Number(res?.data?.currentVersion || this._sceneVersion);
            await this._save(false, { forceSave: true });
        });
    }

    _setStatus(msg, tone = 'ok') {
        const el = this._root.querySelector('#dt-autosave');
        if (!el) return;
        el.className = `dt-stat-item dt-stat-item--${tone}`;
        const icons = {
            ok: 'fa-cloud-check',
            dirty: 'fa-pen-clip',
            loading: 'fa-spinner fa-spin',
            err: 'fa-triangle-exclamation',
        };
        el.innerHTML = `<i class="fa-solid ${icons[tone] || 'fa-cloud'}"></i> ${msg}`;
    }

    /**
     * M3 · #4 — Ustaw aktywny preset kamery i odśwież viewport.
     * Zapis do DB odbywa się w najbliższym _save() (także auto-save).
     */
    _setActiveCamera(presetKey) {
        if (!this._store.dishMeta) return;
        const prev = this._store.dishMeta.activeCamera || null;
        const next = presetKey || null;
        if (prev === next) return;
        this._store.dishMeta.activeCamera = next;
        this._Studio?.markDirty(true);
        this._panels.viewport?.refresh?.();
        this._setStatus(next ? `Kamera: ${next}` : 'Kamera: domyślna', 'ok');
    }

    _setWorkspace(id) {
        this._workspace = id;
        this._panels.toolbar.setWorkspace(id);
        this._root.classList.remove(
            'dt-ws--compose',
            'dt-ws--color',
            'dt-ws--light',
            'dt-ws--scenography',
            'dt-ws--companions',
            'dt-ws--promotions',
            'dt-ws--preview'
        );
        this._root.classList.add(`dt-ws--${id}`);

        // Prawa kolumna: Inspector | Scenography | Promotions (wzajemnie wykluczalne).
        const inspectorEl = this._root.querySelector('#dt-right');
        const scenographyEl = this._root.querySelector('#dt-scenography');
        const promotionsEl = this._root.querySelector('#dt-promotions');
        if (id === 'scenography') {
            if (inspectorEl) inspectorEl.hidden = true;
            if (scenographyEl) scenographyEl.hidden = false;
            if (promotionsEl) promotionsEl.hidden = true;
            this._panels.scenography?.refresh();
        } else if (id === 'promotions') {
            if (inspectorEl) inspectorEl.hidden = true;
            if (scenographyEl) scenographyEl.hidden = true;
            if (promotionsEl) promotionsEl.hidden = false;
            this._panels.promotions?.refresh();
        } else {
            if (inspectorEl) inspectorEl.hidden = false;
            if (scenographyEl) scenographyEl.hidden = true;
            if (promotionsEl) promotionsEl.hidden = true;
        }

        if (id === 'preview') this._panels.viewport?.togglePreview();
    }

    _setTool(id) {
        this._panels.toolbar.setTool(id);
    }

    _registerShortcuts() {
        const s = this._shortcuts;
        s.register('v', 'Select tool', () => this._setTool('select'));
        s.register('h', 'Pan tool', () => this._setTool('pan'));
        s.register('m', 'Magic Enhance', () => this._runMagic('enhance'));
        s.register('p', 'Customer Preview', () => this._panels.viewport?.togglePreview());
        s.register('g', 'Grid toggle', () => this._panels.viewport?.toggleGrid());
        s.register('[', 'Z-order back', () => this._zOrder(-1));
        s.register(']', 'Z-order forward', () => this._zOrder(1));
        s.register('ctrl+z', 'Undo', () => this._store.undo());
        s.register('ctrl+shift+z', 'Redo', () => this._store.redo());
        s.register('ctrl+y', 'Redo', () => this._store.redo());
        s.register('ctrl+s', 'Save', () => this._save());
        s.register('ctrl+d', 'Duplicate', () => this._duplicate());
        s.register('delete', 'Delete selected', () => this._deleteSelected());
        s.register('backspace', 'Delete selected', () => this._deleteSelected());
        s.register('?', 'Shortcut legend', () => this._showShortcutLegend());
        s.register('esc', 'Deselect', () => this._selection.deselect());
        s.register('a', 'Dodaj warstwę', () => this.addLayer());
        s.register('shift+a', 'Dodaj companiona', () => this.addCompanion());
        s.register('r', 'Zamień asset warstwy', () => this.replaceLayerAsset());
    }

    /** Open AssetPicker and append a new pizza layer from the chosen asset. */
    async addLayer() {
        if (!this._selectedDishSku) {
            this._Studio?.toast?.('Najpierw wybierz danie z menu', 'warn');
            return;
        }
        const existing = (this._store.spec.pizza?.layers || []).map(L => L.layerSku);
        const picked = await this._picker.open('layer', { excludeSkus: existing });
        if (!picked) return;
        const layers = structuredClone(this._store.spec.pizza?.layers || []);
        const maxZ = layers.reduce((m, L) => Math.max(m, L.zIndex || 0), 0);
        const newLayer = {
            layerSku:  picked.sku,
            assetUrl:  picked.url,
            zIndex:    maxZ + 10,
            calScale:  1,
            calRotate: 0,
            offsetX:   0,
            offsetY:   0,
            blendMode: 'normal',
            alpha:     1,
            feather:   0,
            brightness: 1,
            saturation: 1,
            hueRotate:  0,
            shadowStrength: 0.45,
            isPopUp:   false,
            visible:   true,
            locked:    false,
        };
        layers.push(newLayer);
        this._store.patch('pizza.layers', layers, `Dodaj warstwę ${picked.sku}`);
        this._selection.select('layer', picked.sku);
        this._Studio?.toast?.(`Dodano warstwę: ${picked.sku}`, 'ok', 1500);
    }

    /** Replace current layer's asset with a different one. */
    async replaceLayerAsset(layerSku) {
        const sku = layerSku || (this._selection.type === 'layer' ? this._selection.id : null);
        if (!sku) { this._Studio?.toast?.('Wybierz najpierw warstwę', 'warn'); return; }
        const picked = await this._picker.open('replace');
        if (!picked) return;
        const layers = structuredClone(this._store.spec.pizza?.layers || []);
        const L = layers.find(x => x.layerSku === sku);
        if (!L) return;
        L.layerSku = picked.sku;
        L.assetUrl = picked.url;
        this._store.patch('pizza.layers', layers, 'Zmień asset warstwy');
        this._selection.select('layer', picked.sku);
        this._Studio?.toast?.(`Asset zmieniony: ${picked.sku}`, 'ok', 1500);
    }

    /** Open product picker and add as companion to the scene. */
    async addCompanion() {
        if (!this._selectedDishSku) {
            this._Studio?.toast?.('Najpierw wybierz danie z menu', 'warn');
            return;
        }
        const existing = (this._store.spec.companions || []).map(c => c.sku);
        const picked = await this._picker.open('companion', { excludeSkus: existing });
        if (!picked) return;
        const comps = structuredClone(this._store.spec.companions || []);
        const offset = comps.length * 4;
        const newComp = {
            sku:    picked.sku,
            name:   picked.label,
            assetUrl: picked.url,
            x:      72 + offset,
            y:      78,
            width:  14,
            scale:  1,
            rotation: 0,
            tilt:   0,
            visible: true,
            locked:  false,
            labelVisible: true,
        };
        comps.push(newComp);
        this._store.patch('companions', comps, `Dodaj companion ${picked.sku}`);
        this._selection.select('companion', picked.sku);
        this._Studio?.toast?.(`Dodano: ${picked.label}`, 'ok', 1500);
    }

    deleteLayer(layerSku) {
        const layers = (this._store.spec.pizza?.layers || []).filter(l => l.layerSku !== layerSku);
        this._store.patch('pizza.layers', layers, `Usuń warstwę ${layerSku}`);
        if (this._selection.type === 'layer' && this._selection.id === layerSku) this._selection.deselect();
    }

    deleteCompanion(sku) {
        const comps = (this._store.spec.companions || []).filter(c => c.sku !== sku);
        this._store.patch('companions', comps, `Usuń companion ${sku}`);
        if (this._selection.type === 'companion' && this._selection.id === sku) this._selection.deselect();
    }

    _zOrder(dir) {
        if (this._selection.type !== 'layer' || !this._selection.id) return;
        const layers = structuredClone(this._store.spec.pizza?.layers || []);
        const L = layers.find(l => l.layerSku === this._selection.id);
        if (L) {
            L.zIndex = Math.max(0, (L.zIndex || 0) + dir * 5);
            this._store.patch('pizza.layers', layers, 'Z-order');
        }
    }

    _duplicate() {
        if (this._selection.type === 'layer' && this._selection.id) {
            const layers = structuredClone(this._store.spec.pizza?.layers || []);
            const L = layers.find(l => l.layerSku === this._selection.id);
            if (L) {
                const clone = { ...structuredClone(L), layerSku: L.layerSku + '_copy_' + Date.now().toString(36) };
                layers.push(clone);
                this._store.patch('pizza.layers', layers, 'Duplicate layer');
            }
        } else if (this._selection.type === 'companion' && this._selection.id) {
            const comps = structuredClone(this._store.spec.companions || []);
            const orig = comps.find((c, i) => c.sku === this._selection.id || String(i) === this._selection.id);
            if (orig) {
                const dup = { ...structuredClone(orig), sku: (orig.sku || 'c') + '_copy_' + Date.now().toString(36), x: (orig.x || 50) + 6, y: (orig.y || 75) + 3 };
                comps.push(dup);
                this._store.patch('companions', comps, 'Duplicate companion');
            }
        }
    }

    _deleteSelected() {
        const { type, id } = this._selection;
        if (type === 'layer' && id) {
            const layers = (this._store.spec.pizza?.layers || []).filter(l => l.layerSku !== id);
            this._store.patch('pizza.layers', layers, 'Delete layer');
            this._selection.deselect();
        } else if (type === 'companion' && id) {
            const comps = (this._store.spec.companions || []).filter((c, i) => c.sku !== id && String(i) !== id);
            this._store.patch('companions', comps, 'Delete companion');
            this._selection.deselect();
        }
    }

    _showShortcutLegend() {
        const legend = this._shortcuts.legend();
        const body = document.createElement('div');
        body.className = 'dt-legend';
        legend.forEach(e => {
            const row = document.createElement('div');
            row.className = 'dt-legend__row';
            row.innerHTML = `<kbd>${e.combo}</kbd><span>${e.description}</span>`;
            body.appendChild(row);
        });
        this._Studio?.modal?.({ title: 'Skróty klawiaturowe', body, wide: false });
    }
}
