/**
 * ViewportPanel — center stage with live scene render + drag handles.
 */
import { SceneRenderer } from '../renderer/SceneRenderer.js';
import { DragHandler } from '../lib/DragHandler.js';
import { StudioApi } from '../../studio_api.js';

export class ViewportPanel {
    constructor(container, store, selection, director) {
        this._el = container;
        this._store = store;
        this._sel = selection;
        this._director = director;
        this._previewMode = false;
        this._mobilePreview = false;
        this._beforeAfter = false;
        this._beforeSpec = null;
        this._showGrid = false;
        this._showRulers = false;
        /** Sloty promocji z API (scene_promotion_slots_get) — overlay na scenie. */
        this._promotionSlots = [];

        this._el.classList.add('dt-viewport');

        this._canvas = document.createElement('div');
        this._canvas.className = 'dt-viewport__canvas';
        this._el.appendChild(this._canvas);

        this._hud = document.createElement('div');
        this._hud.className = 'dt-viewport__hud';
        this._el.appendChild(this._hud);

        this._emptyState = document.createElement('div');
        this._emptyState.className = 'dt-viewport__empty';
        this._emptyState.innerHTML = `
            <div class="dt-viewport__empty-icon"><i class="fa-solid fa-utensils"></i></div>
            <div class="dt-viewport__empty-title">Wybierz danie</div>
            <div class="dt-viewport__empty-desc">Z lewej listy wybierz pozycję z menu, aby rozpocząć komponowanie sceny.</div>
        `;
        this._el.appendChild(this._emptyState);

        this._renderer = new SceneRenderer(this._canvas);

        this._drag = new DragHandler(this._canvas, {
            onMove: (id, dx, dy) => this._handleMove(id, dx, dy),
            onScale: (id, ratio) => this._handleScale(id, ratio),
            onRotate: (id, delta) => this._handleRotate(id, delta),
            onSelect: (id) => this._handleSelect(id),
            isLocked: (id) => this._isLocked(id),
        });

        this._store.onChange(() => this.refresh());
        this._sel.onChange(() => this.refresh());

        this._canvas.addEventListener('click', (e) => {
            const action = e.target.closest?.('[data-action]')?.dataset?.action;
            if (action === 'add-layer') {
                e.stopPropagation();
                this._director?.addLayer?.();
            }
        });

        this.refresh();
    }

    /** Odświeża overlay promocji z backendu (np. po zapisie slotów / zmianie dania). */
    async reloadPromotionSlots() {
        const sku = this._director?._selectedDishSku;
        if (!sku) {
            this._promotionSlots = [];
            this.refresh();
            return;
        }
        const r = await StudioApi.scenePromotionSlotsGet(sku);
        this._promotionSlots = r.success && Array.isArray(r.data?.slots) ? r.data.slots : [];
        this.refresh();
    }

    refresh() {
        const spec = this._store.spec;
        const enriched = { ...spec, _dishMeta: this._store.dishMeta };
        this._renderer.render(enriched, {
            editable: !this._previewMode,
            selectedId: this._previewMode ? null : this._sel.key,
            promotionSlots: this._promotionSlots,
            // M3 #4 · Auto-perspective — ten sam kadr co w storefroncie
            camera: this._store.dishMeta?.activeCamera || null,
        });

        const hasContent = this._hasContent();
        this._emptyState.classList.toggle('is-visible', !hasContent && !this._previewMode);
        this._canvas.classList.toggle('is-empty', !hasContent);

        this._updateHud();

        if (this._showGrid) this._canvas.classList.add('dt-canvas--grid');
        else this._canvas.classList.remove('dt-canvas--grid');

        if (this._beforeAfter && this._beforeSpec) this._renderBeforeAfter();
    }

    _hasContent() {
        const s = this._store.spec;
        return (s.pizza?.layers?.length > 0) || (s.companions?.length > 0) || !!s.stage?.boardUrl;
    }

    _updateHud() {
        const sel = this._sel;
        const info = this._describeSelection();
        this._hud.innerHTML = `
            <div class="dt-hud__left">
                <span class="dt-hud__chip"><i class="fa-solid fa-crosshairs"></i> ${info}</span>
            </div>
            <div class="dt-hud__right">
                <button class="dt-hud__btn" data-hud="grid" title="Grid (G)"><i class="fa-solid fa-border-all"></i></button>
                <button class="dt-hud__btn" data-hud="fit" title="Fit (F)"><i class="fa-solid fa-expand"></i></button>
            </div>
        `;
        this._hud.querySelector('[data-hud="grid"]').onclick = () => { this._showGrid = !this._showGrid; this.refresh(); };
        this._hud.querySelector('[data-hud="fit"]').onclick = () => this._fit();
    }

    _describeSelection() {
        const t = this._sel.type;
        const id = this._sel.id;
        if (t === 'layer' && id) return `Warstwa: ${id}`;
        if (t === 'companion' && id) return `Companion: ${id}`;
        if (t === 'pizza') return 'Pizza';
        if (t === 'infoBlock') return 'Info Block';
        if (t === 'stage') return 'Stage';
        return 'Scena';
    }

    _fit() {
        // future: center + zoom to fit all elements
    }

    togglePreview() {
        this._previewMode = !this._previewMode;
        this._el.classList.toggle('dt-viewport--preview', this._previewMode);
        this.refresh();
        return this._previewMode;
    }

    toggleMobilePreview() {
        this._mobilePreview = !this._mobilePreview;
        this._el.classList.toggle('dt-viewport--mobile', this._mobilePreview);
        return this._mobilePreview;
    }

    toggleBeforeAfter() {
        this._beforeAfter = !this._beforeAfter;
        if (this._beforeAfter) this._beforeSpec = structuredClone(this._store.spec);
        this._el.classList.toggle('dt-viewport--ba', this._beforeAfter);
        this.refresh();
        return this._beforeAfter;
    }

    toggleGrid() { this._showGrid = !this._showGrid; this.refresh(); return this._showGrid; }

    _renderBeforeAfter() {
        const ba = document.createElement('div');
        ba.className = 'dt-ba-overlay';
        ba.innerHTML = '<div class="dt-ba-label dt-ba-label--before">BEFORE</div><div class="dt-ba-label dt-ba-label--after">AFTER</div>';
        this._canvas.appendChild(ba);
    }

    _handleSelect(id) {
        if (!id) { this._sel.deselect(); return; }
        if (id === 'stage') { this._sel.select('stage'); return; }
        if (id === 'pizza') { this._sel.select('pizza'); return; }
        if (id === 'infoBlock') { this._sel.select('infoBlock'); return; }
        if (id.startsWith('layer:')) { this._sel.select('layer', id.slice(6)); return; }
        if (id.startsWith('companion:')) { this._sel.select('companion', id.slice(10)); return; }
    }

    _isLocked(id) {
        const s = this._store.spec;
        if (id === 'infoBlock') return !!s.infoBlock?.locked;
        if (id.startsWith('layer:')) {
            const sku = id.slice(6);
            return !!(s.pizza?.layers || []).find(l => l.layerSku === sku)?.locked;
        }
        if (id.startsWith('companion:')) {
            const key = id.slice(10);
            return !!(s.companions || []).find((c, i) => c.sku === key || String(i) === key)?.locked;
        }
        return false;
    }

    _handleMove(id, dx, dy) {
        if (id === 'pizza') {
            const p = this._store.spec.pizza || {};
            this._store.patch('pizza', { x: (p.x ?? 50) + dx, y: (p.y ?? 55) + dy }, 'Przesuń pizzę');
        } else if (id === 'infoBlock') {
            const ib = this._store.spec.infoBlock || {};
            this._store.patch('infoBlock', { x: (ib.x ?? 55) + dx, y: (ib.y ?? 8) + dy }, 'Przesuń info');
        } else if (id.startsWith('companion:')) {
            const key = id.slice(10);
            const comps = structuredClone(this._store.spec.companions || []);
            const c = comps.find((x, i) => x.sku === key || String(i) === key);
            if (c) {
                c.x = (c.x ?? 50) + dx;
                c.y = (c.y ?? 75) + dy;
                this._store.patch('companions', comps, 'Przesuń companion');
            }
        } else if (id.startsWith('layer:')) {
            const sku = id.slice(6);
            const layers = structuredClone(this._store.spec.pizza?.layers || []);
            const L = layers.find(l => l.layerSku === sku);
            if (L) {
                L.offsetX = Math.max(-0.5, Math.min(0.5, (L.offsetX ?? 0) + dx / 100));
                L.offsetY = Math.max(-0.5, Math.min(0.5, (L.offsetY ?? 0) + dy / 100));
                this._store.patch('pizza.layers', layers, 'Offset warstwy');
            }
        }
    }

    _handleScale(id, ratio) {
        const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
        if (id === 'pizza') {
            const p = this._store.spec.pizza || {};
            const s = clamp((p.scale ?? 1) * ratio, 0.2, 3);
            this._store.patch('pizza', { scale: s }, 'Skaluj pizzę');
        } else if (id.startsWith('companion:')) {
            const key = id.slice(10);
            const comps = structuredClone(this._store.spec.companions || []);
            const c = comps.find((x, i) => x.sku === key || String(i) === key);
            if (c) {
                c.scale = clamp((c.scale ?? 1) * ratio, 0.2, 2.5);
                this._store.patch('companions', comps, 'Skaluj companion');
            }
        } else if (id.startsWith('layer:')) {
            const sku = id.slice(6);
            const layers = structuredClone(this._store.spec.pizza?.layers || []);
            const L = layers.find(l => l.layerSku === sku);
            if (L) {
                L.calScale = clamp((L.calScale ?? 1) * ratio, 0.2, 3);
                this._store.patch('pizza.layers', layers, 'Skaluj warstwę');
            }
        } else if (id === 'infoBlock') {
            const ib = this._store.spec.infoBlock || {};
            this._store.patch('infoBlock', {
                w: clamp((ib.w ?? 42) * ratio, 10, 90),
                h: clamp((ib.h ?? 38) * ratio, 10, 90),
            }, 'Skaluj info');
        }
    }

    _handleRotate(id, delta) {
        if (id === 'pizza') {
            const p = this._store.spec.pizza || {};
            this._store.patch('pizza', { rotation: ((p.rotation ?? 0) + delta) % 360 }, 'Obróć pizzę');
        } else if (id.startsWith('companion:')) {
            const key = id.slice(10);
            const comps = structuredClone(this._store.spec.companions || []);
            const c = comps.find((x, i) => x.sku === key || String(i) === key);
            if (c) {
                c.rotation = ((c.rotation ?? 0) + delta) % 360;
                this._store.patch('companions', comps, 'Obróć companion');
            }
        } else if (id.startsWith('layer:')) {
            const sku = id.slice(6);
            const layers = structuredClone(this._store.spec.pizza?.layers || []);
            const L = layers.find(l => l.layerSku === sku);
            if (L) {
                L.calRotate = ((L.calRotate ?? 0) + delta) % 360;
                this._store.patch('pizza.layers', layers, 'Obróć warstwę');
            }
        }
    }

    destroy() { this._drag.destroy(); }
}
