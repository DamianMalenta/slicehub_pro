/**
 * SharedSceneRenderer — single scene renderer for Director + storefront.
 *
 * DOM backend is the default and source of truth. Canvas backend plugs into the
 * same API later for higher layer counts.
 */
import { getLut } from '../../../modules/online_studio/js/director/lib/LutLibrary.js';
import {
    renderLayersInto,
    resolveAssetUrl,
    CLASS_PACKS,
    applyCameraPerspective,
} from '../scene_renderer.js';

function esc(s) {
    return String(s || '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[c]));
}

export class SharedSceneRenderer {
    constructor(container, options = {}) {
        this._el = container;
        this._defaultBackend = options.backend || 'dom';
        if (this._el) {
            this._el.classList.add('sr-root');
        }
    }

    render(spec, opts = {}) {
        const backend = this._resolveBackend(opts.backend || this._defaultBackend);
        if (backend === 'canvas' && typeof this._renderCanvas === 'function' && this._renderCanvas(spec, opts)) {
            return;
        }
        this._renderDom(spec, opts);
    }

    _resolveBackend(requested) {
        if (requested !== 'canvas') {
            return 'dom';
        }
        if (typeof window === 'undefined' || typeof window.HTMLCanvasElement === 'undefined') {
            return 'dom';
        }
        return 'canvas';
    }

    _renderDom(spec, opts = {}) {
        const {
            editable = false,
            selectedId = null,
            promotionSlots = [],
            camera = null,
            sceneMode = 'preview',
        } = opts;

        const normalized = spec || {};
        const s = normalized.stage || {};
        const lut = getLut(s.lutName);

        this._el.innerHTML = '';
        this._el.classList.toggle('sr-root--storefront', sceneMode !== 'edit');
        this._el.style.setProperty('--sr-aspect', s.aspect || '16/10');

        const stage = document.createElement('div');
        stage.className = 'sr-stage';
        stage.dataset.sceneId = 'stage';
        stage.dataset.sceneMode = sceneMode;
        if (selectedId === 'stage') {
            stage.classList.add('sr--selected-bg');
        }

        if (s.boardUrl) {
            stage.style.backgroundImage = `url("${resolveAssetUrl(s.boardUrl)}")`;
            stage.style.backgroundSize = 'cover';
            stage.style.backgroundPosition = 'center';
        } else {
            stage.style.background = [
                'radial-gradient(ellipse at 50% 40%, #3a2a1c 0%, #1a120b 70%)',
                '#0f0a05',
            ].join(',');
        }

        if (lut.filters) {
            stage.style.filter = lut.filters;
        }

        this._renderLighting(stage, s);
        this._renderVignette(stage, s);
        this._renderGrain(stage, s);
        this._renderLetterbox(stage, s);

        if (normalized.pizza?.visible !== false) {
            const pizzaEl = this._renderPizza(normalized.pizza || {}, editable, selectedId);
            stage.appendChild(pizzaEl);
            if (camera) {
                const disk = pizzaEl.querySelector('.sr-disk');
                if (disk) {
                    applyCameraPerspective(pizzaEl, disk, camera);
                }
            }
        }

        (normalized.companions || []).forEach((comp, i) => {
            if (comp.visible === false) {
                return;
            }
            stage.appendChild(this._renderCompanion(comp, i, editable, selectedId));
        });

        if (normalized.infoBlock?.visible !== false) {
            stage.appendChild(this._renderInfoBlock(normalized.infoBlock || {}, normalized, editable, selectedId));
        }

        this._renderPromotionSlots(stage, promotionSlots);
        this._renderAmbient(stage, normalized.ambient || {});

        this._el.appendChild(stage);

        if (normalized.modifierGrid?.visible !== false && normalized.modifierGrid?.position === 'below-stage') {
            this._el.appendChild(this._renderModGrid(normalized.modifierGrid));
        }
    }

    _renderLighting(stage, s) {
        const light = document.createElement('div');
        light.className = 'sr-light';
        const intensity = s.lightIntensity ?? 14;
        light.style.background = `radial-gradient(ellipse 60% 55% at ${s.lightX ?? 50}% ${s.lightY ?? 15}%, rgba(255,210,150,${intensity / 100}), transparent 70%)`;
        stage.appendChild(light);
    }

    _renderVignette(stage, s) {
        const v = s.vignetteIntensity ?? 35;
        if (v <= 0) {
            return;
        }
        const el = document.createElement('div');
        el.className = 'sr-vignette';
        el.style.background = `radial-gradient(ellipse at 50% 50%, transparent 40%, rgba(0,0,0,${v / 100}) 100%)`;
        stage.appendChild(el);
    }

    _renderGrain(stage, s) {
        const g = s.grainIntensity ?? 6;
        if (g <= 0) {
            return;
        }
        const el = document.createElement('div');
        el.className = 'sr-grain';
        el.style.opacity = String(g / 100);
        stage.appendChild(el);
    }

    _renderLetterbox(stage, s) {
        const lb = s.letterbox ?? 0;
        if (lb <= 0) {
            return;
        }
        const top = document.createElement('div');
        top.className = 'sr-letterbox sr-letterbox--top';
        top.style.height = `${lb}%`;
        const bot = document.createElement('div');
        bot.className = 'sr-letterbox sr-letterbox--bot';
        bot.style.height = `${lb}%`;
        stage.appendChild(top);
        stage.appendChild(bot);
    }

    _renderPizza(pizza, editable, selectedId) {
        const wrap = document.createElement('div');
        wrap.className = 'sr-pizza';
        wrap.dataset.sceneId = 'pizza';
        if (selectedId === 'pizza') {
            wrap.classList.add('sr--selected');
        }
        wrap.style.left = `${pizza.x ?? 50}%`;
        wrap.style.top = `${pizza.y ?? 55}%`;
        wrap.style.transform = [
            'translate(-50%,-50%)',
            'translate3d(var(--sc-parallax-x, 0px), var(--sc-parallax-y, 0px), 0)',
            `rotate(var(--sc-parallax-rot, 0deg))`,
            `scale(${pizza.scale ?? 1})`,
            `rotate(${pizza.rotation ?? 0}deg)`,
        ].join(' ');

        const disk = document.createElement('div');
        disk.className = 'sr-disk';
        const layers = pizza.layers || [];
        const mode = pizza.mode || 'full';
        const secondaryLayers = pizza.secondaryLayers || [];

        if (layers.length === 0 && secondaryLayers.length === 0) {
            disk.classList.add('sr-disk--empty');
            const hint = document.createElement('button');
            hint.className = 'sr-disk__hint';
            hint.type = 'button';
            hint.dataset.action = 'add-layer';
            hint.innerHTML = '<i class="fa-solid fa-circle-plus"></i><span>Dodaj warstwę</span><small>Kliknij lub naciśnij A</small>';
            disk.appendChild(hint);
        } else if (mode === 'halfHorizontal') {
            const topStack = this._buildDiskStack(layers, selectedId, 'sr-disk__stack sr-disk__stack--qtr-top');
            const bottomStack = this._buildDiskStack(secondaryLayers, selectedId, 'sr-disk__stack sr-disk__stack--qtr-bot');
            disk.appendChild(topStack);
            disk.appendChild(bottomStack);
        } else {
            const stackClass = mode === 'half'
                ? 'sr-disk__stack sr-disk__stack--half'
                : 'sr-disk__stack';
            disk.appendChild(this._buildDiskStack(layers, selectedId, stackClass));
        }

        wrap.appendChild(disk);
        if (editable) {
            wrap.appendChild(this._handles('pizza', { rotate: true }));
        }
        return wrap;
    }

    _buildDiskStack(layers, selectedId, className) {
        const stack = document.createElement('div');
        stack.className = className;
        renderLayersInto(stack, layers, {
            ...CLASS_PACKS.director,
            selectedId: selectedId || null,
            onLayerBuilt: (el, layer) => {
                if (layer.shadowStrength == null) {
                    const existing = el.style.filter || '';
                    el.style.filter = (existing + ' drop-shadow(2px 3px 3px rgba(30,15,5,0.45))').trim();
                }
            },
        });
        return stack;
    }

    _renderCompanion(comp, idx, editable, selectedId) {
        const el = document.createElement('div');
        const id = `companion:${comp.sku || idx}`;
        el.className = 'sr-companion';
        el.dataset.sceneId = id;
        if (selectedId === id) {
            el.classList.add('sr--selected');
        }

        el.style.left = `${comp.x ?? 50}%`;
        el.style.top = `${comp.y ?? 75}%`;
        el.style.width = `${comp.width ?? 14}%`;
        const tilt = comp.tilt ?? 0;
        el.style.transform = [
            'translate(-50%,-50%)',
            'translate3d(calc(var(--sc-parallax-x, 0px) * -0.4), calc(var(--sc-parallax-y, 0px) * -0.4), 0)',
            `scale(${comp.scale ?? 1})`,
            `rotate(${comp.rotation ?? 0}deg)`,
            'perspective(400px)',
            `rotateX(${tilt}deg)`,
        ].join(' ');

        const url = resolveAssetUrl(comp.assetUrl || comp.heroUrl || '');
        if (url) {
            const img = document.createElement('img');
            img.className = 'sr-companion__img';
            img.src = url;
            img.alt = comp.name || '';
            img.draggable = false;
            el.appendChild(img);
        } else {
            const ph = document.createElement('div');
            ph.className = 'sr-companion__ph';
            ph.innerHTML = `<i class="fa-solid fa-mug-hot"></i><span>${esc((comp.name || '?').slice(0, 14))}</span>`;
            el.appendChild(ph);
        }

        const shadow = document.createElement('div');
        shadow.className = 'sr-companion__shadow';
        el.appendChild(shadow);

        if (comp.labelVisible !== false && (comp.label || comp.name)) {
            const label = document.createElement('div');
            label.className = 'sr-companion__label';
            label.textContent = comp.label || comp.name || '';
            el.appendChild(label);
        }

        if (editable) {
            el.appendChild(this._handles(id, { rotate: true }));
        }
        return el;
    }

    _renderInfoBlock(info, spec, editable, selectedId) {
        const el = document.createElement('div');
        el.className = `sr-info sr-info--${info.theme || 'glass-dark'}`;
        el.dataset.sceneId = 'infoBlock';
        if (selectedId === 'infoBlock') {
            el.classList.add('sr--selected');
        }

        el.style.left = `${info.x ?? 55}%`;
        el.style.top = `${info.y ?? 8}%`;
        el.style.width = `${info.w ?? 42}%`;
        el.style.minHeight = `${info.h ?? 20}%`;
        el.style.textAlign = info.align || 'left';
        el.style.setProperty('--info-bg-alpha', String(info.bgOpacity ?? 0.85));

        const meta = spec._dishMeta || {};
        const priceStr = meta.price != null ? Number(meta.price).toFixed(2).replace('.', ',') + ' zł' : '—';
        const desc = (meta.description || '').slice(0, 160);
        el.innerHTML = `
            <div class="sr-info__cat">${esc(meta.categoryName || 'Danie')}</div>
            <div class="sr-info__title">${esc(meta.name || 'Nazwa dania')}</div>
            ${desc ? `<div class="sr-info__desc">${esc(desc)}</div>` : ''}
            <div class="sr-info__price">${priceStr}</div>
        `;

        if (editable) {
            el.appendChild(this._handles('infoBlock', { rotate: false }));
        }
        return el;
    }

    _renderPromotionSlots(stage, slots) {
        if (!slots?.length) {
            return;
        }
        slots.forEach((slot) => {
            const text = (slot.badgeText || slot.name || slot.ruleKind || 'Promo').trim() || 'Promo';
            const rawStyle = String(slot.badgeStyle || 'amber').replace(/[^a-z0-9_]/gi, '');
            const styleClass = rawStyle || 'amber';
            const el = document.createElement('div');
            el.className = `sr-promo-badge sr-promo-badge--${styleClass}`;
            el.style.left = `${slot.slotX ?? 50}%`;
            el.style.top = `${slot.slotY ?? 50}%`;
            const z = Number(slot.slotZIndex);
            el.style.zIndex = String(Number.isFinite(z) ? Math.min(600, Math.max(5, z)) : 120);
            el.textContent = text;
            el.title = text;
            stage.appendChild(el);
        });
    }

    _renderModGrid(cfg) {
        const el = document.createElement('div');
        el.className = 'sr-modgrid';
        el.innerHTML = '<div class="sr-modgrid__ph">[ Modifiers grid placeholder ]</div>';
        return el;
    }

    _renderAmbient(stage, ambient) {
        if (ambient.steam?.count > 0) {
            const n = Math.min(ambient.steam.count, 6);
            const intensity = (ambient.steam.intensity ?? 50) / 200;
            stage.style.setProperty('--steam-peak', String(intensity));
            for (let i = 0; i < n; i++) {
                const w = document.createElement('div');
                w.className = 'sr-steam';
                w.style.left = `${35 + i * 8}%`;
                w.style.animationDelay = `${i * 1.2}s`;
                stage.appendChild(w);
            }
        }

        if (ambient.oilSheen?.enabled) {
            const sheen = document.createElement('div');
            sheen.className = 'sr-oil-sheen';
            sheen.style.left = `${ambient.oilSheen.x ?? 45}%`;
            sheen.style.top = `${ambient.oilSheen.y ?? 35}%`;
            stage.appendChild(sheen);
        }

        if (ambient.crumbs?.count > 0) {
            const seed = ambient.crumbs.seed || 'default';
            const n = Math.min(ambient.crumbs.count, 30);
            for (let i = 0; i < n; i++) {
                const h = this._hash(seed + i);
                const c = document.createElement('div');
                c.className = 'sr-crumb';
                c.style.left = `${(h % 90) + 5}%`;
                c.style.top = `${((h * 7) % 85) + 8}%`;
                c.style.transform = `rotate(${h % 360}deg) scale(${0.5 + (h % 50) / 100})`;
                stage.appendChild(c);
            }
        }
    }

    _handles(sceneId, { rotate = true } = {}) {
        const g = document.createElement('div');
        g.className = 'sr-handles';
        const corners = `
            <div class="sr-handle sr-handle--tl" data-drag-role="scale" title="Skaluj"></div>
            <div class="sr-handle sr-handle--tr" data-drag-role="scale" title="Skaluj"></div>
            <div class="sr-handle sr-handle--bl" data-drag-role="scale" title="Skaluj"></div>
            <div class="sr-handle sr-handle--br" data-drag-role="scale" title="Skaluj"></div>`;
        const rot = rotate
            ? '<div class="sr-handle sr-handle--rot" data-drag-role="rotate" title="Obróć (Shift = 15°)"><i class="fa-solid fa-rotate"></i></div>'
            : '';
        g.innerHTML = corners + rot;
        g.dataset.sceneId = sceneId;
        return g;
    }

    _hash(str) {
        let h = 0;
        for (let i = 0; i < str.length; i++) {
            h = ((h << 5) - h) + str.charCodeAt(i);
            h |= 0;
        }
        return Math.abs(h);
    }
}
