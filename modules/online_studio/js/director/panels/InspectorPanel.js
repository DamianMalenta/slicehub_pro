/**
 * InspectorPanel — right panel: context-aware properties.
 */
import { LUTS } from '../lib/LutLibrary.js';
import { scoreTier } from '../harmony/HarmonyScore.js';

function slider(label, value, min, max, step, onChange, fmt) {
    const row = document.createElement('div');
    row.className = 'dt-prop';
    row.innerHTML = `<label class="dt-prop__label">${label}</label><div class="dt-prop__ctrl"></div>`;
    const ctrl = row.querySelector('.dt-prop__ctrl');

    const inp = document.createElement('input');
    inp.type = 'range';
    inp.min = min; inp.max = max; inp.step = step;
    inp.value = value;
    inp.className = 'dt-slider';

    const val = document.createElement('span');
    val.className = 'dt-prop__val';
    const formatter = fmt || ((v) => Number(v).toFixed(step < 1 ? 2 : 0));
    val.textContent = formatter(value);

    inp.oninput = () => {
        const v = Number(inp.value);
        val.textContent = formatter(v);
        onChange(v);
    };
    ctrl.appendChild(inp);
    ctrl.appendChild(val);
    return row;
}

function dropdown(label, value, options, onChange) {
    const row = document.createElement('div');
    row.className = 'dt-prop';
    row.innerHTML = `<label class="dt-prop__label">${label}</label><div class="dt-prop__ctrl"></div>`;
    const sel = document.createElement('select');
    sel.className = 'dt-select';
    options.forEach(o => {
        const opt = document.createElement('option');
        opt.value = typeof o === 'string' ? o : o.value;
        opt.textContent = typeof o === 'string' ? o : o.label;
        if (opt.value === value) opt.selected = true;
        sel.appendChild(opt);
    });
    sel.onchange = () => onChange(sel.value);
    row.querySelector('.dt-prop__ctrl').appendChild(sel);
    return row;
}

function toggle(label, value, onChange) {
    const row = document.createElement('div');
    row.className = 'dt-prop dt-prop--toggle';
    const lbl = document.createElement('label');
    lbl.className = 'dt-prop__label dt-prop__label--toggle';
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.checked = !!value;
    cb.className = 'dt-checkbox';
    cb.onchange = () => onChange(cb.checked);
    lbl.appendChild(cb);
    lbl.appendChild(document.createTextNode(` ${label}`));
    row.appendChild(lbl);
    return row;
}

function sectionHead(text) {
    const h = document.createElement('div');
    h.className = 'dt-section-head';
    h.textContent = text;
    return h;
}

const CTX = {
    stage:      { icon: 'fa-border-all',  title: 'Stage' },
    pizza:      { icon: 'fa-circle',      title: 'Pizza' },
    layer:      { icon: 'fa-image',       title: 'Warstwa' },
    companion:  { icon: 'fa-mug-hot',     title: 'Companion' },
    infoBlock:  { icon: 'fa-align-left',  title: 'Info Block' },
};

export class InspectorPanel {
    constructor(container, store, selection, director) {
        this._el = container;
        this._store = store;
        this._sel = selection;
        this._director = director;
        this._el.classList.add('dt-inspector');
        this._store.onChange(() => this.refresh());
        this._sel.onChange(() => this.refresh());
        this.refresh();
    }

    refresh() {
        this._el.innerHTML = '';

        const type = this._sel.type;
        const id = this._sel.id;
        const ctx = CTX[type] || CTX.stage;

        const subtitle = this._subtitleFor(type, id);

        const header = document.createElement('div');
        header.className = 'dt-inspector__head';
        header.innerHTML = `
            <div class="dt-inspector__head-left">
                <i class="fa-solid ${ctx.icon}"></i>
                <div class="dt-inspector__head-text">
                    <div class="dt-inspector__head-title">${ctx.title}</div>
                    ${subtitle ? `<div class="dt-inspector__head-sub">${subtitle}</div>` : ''}
                </div>
            </div>
        `;
        this._el.appendChild(header);

        const quality = this._director?._harmony || null;
        if (quality) {
            const tier = scoreTier(quality.score || 0);
            const scoreCard = document.createElement('div');
            scoreCard.className = `dt-inspector__quality dt-inspector__quality--${tier.tier}`;
            scoreCard.innerHTML = `
                <div class="dt-inspector__quality-label">Auto-Match Quality</div>
                <div class="dt-inspector__quality-score">
                    <i class="fa-solid ${tier.iconClass}"></i>
                    <strong>${quality.score || 0}/100</strong>
                    <span>${tier.label}</span>
                </div>
            `;
            this._el.appendChild(scoreCard);
        }

        const body = document.createElement('div');
        body.className = 'dt-inspector__body';

        if (type === 'stage') this._renderStage(body);
        else if (type === 'pizza') this._renderPizza(body);
        else if (type === 'layer') this._renderLayer(body, id);
        else if (type === 'companion') this._renderCompanion(body, id);
        else if (type === 'infoBlock') this._renderInfoBlock(body);
        else this._renderStage(body);

        this._el.appendChild(body);
    }

    _subtitleFor(type, id) {
        if (type === 'layer' && id) return id;
        if (type === 'companion' && id) {
            const c = (this._store.spec.companions || []).find((x, i) => x.sku === id || String(i) === id);
            return c?.name || id;
        }
        if (type === 'pizza') return `${(this._store.spec.pizza?.layers || []).length} warstw`;
        if (type === 'stage') return 'Globalne ustawienia sceny';
        if (type === 'infoBlock') return 'Karta informacyjna';
        return '';
    }

    _renderStage(body) {
        const s = this._store.spec.stage || {};
        body.appendChild(sectionHead('Oświetlenie'));
        body.appendChild(slider('Light X', s.lightX ?? 50, 0, 100, 1, v => this._store.patch('stage', { lightX: v }, 'Light X'), v => `${v}%`));
        body.appendChild(slider('Light Y', s.lightY ?? 15, 0, 100, 1, v => this._store.patch('stage', { lightY: v }, 'Light Y'), v => `${v}%`));
        body.appendChild(slider('Intensywność', s.lightIntensity ?? 14, 0, 50, 1, v => this._store.patch('stage', { lightIntensity: v }, 'Light intensity')));

        body.appendChild(sectionHead('Atmosfera'));
        body.appendChild(slider('Ziarno (grain)', s.grainIntensity ?? 6, 0, 30, 1, v => this._store.patch('stage', { grainIntensity: v }, 'Grain')));
        body.appendChild(slider('Winiety', s.vignetteIntensity ?? 35, 0, 80, 1, v => this._store.patch('stage', { vignetteIntensity: v }, 'Vignette')));
        body.appendChild(slider('Letterbox', s.letterbox ?? 0, 0, 10, 0.5, v => this._store.patch('stage', { letterbox: v }, 'Letterbox'), v => `${v}%`));

        body.appendChild(sectionHead('Kolor'));
        body.appendChild(dropdown('LUT', s.lutName || 'none', LUTS.map(l => ({ value: l.name, label: l.label })),
            v => this._store.patch('stage', { lutName: v }, 'LUT change')));

        body.appendChild(sectionHead('Kadr'));
        body.appendChild(dropdown('Aspect Ratio', s.aspect || '16/10',
            ['16/10', '16/9', '21/9', '4/3', '1/1'].map(v => ({ value: v, label: v })),
            v => this._store.patch('stage', { aspect: v }, 'Aspect ratio')));
    }

    _renderPizza(body) {
        const p = this._store.spec.pizza || {};
        body.appendChild(sectionHead('Pozycja'));
        body.appendChild(slider('X', p.x ?? 50, -20, 120, 1, v => this._store.patch('pizza', { x: v }, 'Pizza X'), v => `${v}%`));
        body.appendChild(slider('Y', p.y ?? 55, -20, 120, 1, v => this._store.patch('pizza', { y: v }, 'Pizza Y'), v => `${v}%`));
        body.appendChild(slider('Skala', p.scale ?? 1, 0.2, 3, 0.05, v => this._store.patch('pizza', { scale: v }, 'Pizza scale'), v => `${v.toFixed(2)}×`));
        body.appendChild(slider('Rotacja', p.rotation ?? 0, -180, 180, 1, v => this._store.patch('pizza', { rotation: v }, 'Pizza rotation'), v => `${v}°`));
        body.appendChild(sectionHead('Widoczność'));
        body.appendChild(toggle('Widoczna', p.visible !== false, v => this._store.patch('pizza', { visible: v }, 'Pizza visible')));
    }

    _renderLayer(body, layerSku) {
        const layers = this._store.spec.pizza?.layers || [];
        const L = layers.find(l => l.layerSku === layerSku);
        if (!L) {
            body.innerHTML = '<div class="dt-inspector__empty">Nie znaleziono warstwy</div>';
            return;
        }

        const update = (key, val, label) => {
            const ls = structuredClone(layers);
            const target = ls.find(l => l.layerSku === layerSku);
            if (target) { target[key] = val; this._store.patch('pizza.layers', ls, label); }
        };

        // Pasek szybkich akcji dla warstwy (widoczne przyciski zamiast ukrytych skrótów)
        const actions = document.createElement('div');
        actions.className = 'dt-quick-actions';
        actions.innerHTML = `
            <button class="dt-qa dt-qa--dup" title="Duplikuj warstwę (Ctrl+D)">
                <i class="fa-solid fa-clone"></i> Duplikuj
            </button>
            <button class="dt-qa" data-act="up" title="Przenieś w górę ( ] )">
                <i class="fa-solid fa-arrow-up"></i>
            </button>
            <button class="dt-qa" data-act="down" title="Przenieś w dół ( [ )">
                <i class="fa-solid fa-arrow-down"></i>
            </button>
            <button class="dt-qa" data-act="replace" title="Zamień asset (R)">
                <i class="fa-solid fa-image-portrait"></i>
            </button>
            <button class="dt-qa dt-qa--danger" data-act="delete" title="Usuń (Del)">
                <i class="fa-solid fa-trash"></i>
            </button>
        `;
        actions.querySelector('.dt-qa--dup').onclick = () => this._director?.duplicateLayer(layerSku);
        actions.querySelector('[data-act="up"]').onclick     = () => this._director?.moveLayerUp(layerSku);
        actions.querySelector('[data-act="down"]').onclick   = () => this._director?.moveLayerDown(layerSku);
        actions.querySelector('[data-act="replace"]').onclick = () => this._director?.replaceLayerAsset(layerSku);
        actions.querySelector('[data-act="delete"]').onclick  = () => this._director?.deleteLayer(layerSku);
        body.appendChild(actions);

        body.appendChild(sectionHead('Pozycja'));
        body.appendChild(slider('Z-Index', L.zIndex ?? 0, 0, 100, 1, v => update('zIndex', v, 'Layer z-index')));
        body.appendChild(slider('Offset X', L.offsetX ?? 0, -0.5, 0.5, 0.01, v => update('offsetX', v, 'Layer offset X'), v => `${(v * 100).toFixed(0)}%`));
        body.appendChild(slider('Offset Y', L.offsetY ?? 0, -0.5, 0.5, 0.01, v => update('offsetY', v, 'Layer offset Y'), v => `${(v * 100).toFixed(0)}%`));
        body.appendChild(slider('Skala', L.calScale ?? 1, 0.1, 3, 0.05, v => update('calScale', v, 'Layer scale'), v => `${v.toFixed(2)}×`));
        body.appendChild(slider('Rotacja', L.calRotate ?? 0, -360, 360, 1, v => update('calRotate', v, 'Layer rotation'), v => `${v}°`));

        body.appendChild(sectionHead('Kompozycja'));
        body.appendChild(dropdown('Blend Mode', L.blendMode || 'normal',
            ['normal', 'multiply', 'overlay', 'screen', 'soft-light', 'hard-light', 'color-dodge', 'color-burn', 'darken', 'lighten'],
            v => update('blendMode', v, 'Layer blend mode')));
        body.appendChild(slider('Krycie', L.alpha ?? 1, 0, 1, 0.05, v => update('alpha', v, 'Layer alpha'), v => `${(v * 100).toFixed(0)}%`));
        body.appendChild(slider('Feather', L.feather ?? 0, 0, 100, 1, v => update('feather', v, 'Layer feather'), v => `${v}%`));

        body.appendChild(sectionHead('Kolor'));
        body.appendChild(slider('Jasność', L.brightness ?? 1, 0.5, 1.5, 0.02, v => update('brightness', v, 'Layer brightness'), v => `${v.toFixed(2)}`));
        body.appendChild(slider('Nasycenie', L.saturation ?? 1, 0, 2, 0.02, v => update('saturation', v, 'Layer saturation'), v => `${v.toFixed(2)}`));
        body.appendChild(slider('Odcień', L.hueRotate ?? 0, -30, 30, 1, v => update('hueRotate', v, 'Layer hue'), v => `${v}°`));

        body.appendChild(sectionHead('Cień'));
        body.appendChild(slider('Siła cienia', L.shadowStrength ?? 0.45, 0, 1, 0.05, v => update('shadowStrength', v, 'Layer shadow'), v => v.toFixed(2)));
        body.appendChild(toggle('Pop-up (nad serem)', !!L.isPopUp, v => update('isPopUp', v, 'Layer pop-up')));

        body.appendChild(sectionHead('Stan'));
        body.appendChild(toggle('Widoczna', L.visible !== false, v => update('visible', v, 'Layer visible')));
        body.appendChild(toggle('Zablokowana', !!L.locked, v => update('locked', v, 'Layer lock')));
    }

    _renderCompanion(body, compKey) {
        const comps = this._store.spec.companions || [];
        const c = comps.find((c, i) => c.sku === compKey || String(i) === compKey);
        if (!c) {
            body.innerHTML = '<div class="dt-inspector__empty">Nie znaleziono companiona</div>';
            return;
        }

        const update = (key, val, label) => {
            const cs = structuredClone(comps);
            const target = cs.find((x, i) => x.sku === compKey || String(i) === compKey);
            if (target) { target[key] = val; this._store.patch('companions', cs, label); }
        };

        const actions = document.createElement('div');
        actions.className = 'dt-quick-actions';
        actions.innerHTML = `
            <button class="dt-qa dt-qa--dup" title="Duplikuj (Ctrl+D)">
                <i class="fa-solid fa-clone"></i> Duplikuj
            </button>
            <button class="dt-qa dt-qa--danger" data-act="delete" title="Usuń (Del)">
                <i class="fa-solid fa-trash"></i>
            </button>
        `;
        actions.querySelector('.dt-qa--dup').onclick = () => this._director?.duplicateCompanion(compKey);
        actions.querySelector('[data-act="delete"]').onclick = () => this._director?.deleteCompanion(compKey);
        body.appendChild(actions);

        body.appendChild(sectionHead('Pozycja'));
        body.appendChild(slider('X', c.x ?? 50, -20, 120, 1, v => update('x', v, 'Companion X'), v => `${v}%`));
        body.appendChild(slider('Y', c.y ?? 75, -20, 120, 1, v => update('y', v, 'Companion Y'), v => `${v}%`));
        body.appendChild(slider('Szerokość', c.width ?? 14, 5, 40, 0.5, v => update('width', v, 'Companion width'), v => `${v}%`));
        body.appendChild(slider('Skala', c.scale ?? 1, 0.2, 2.5, 0.05, v => update('scale', v, 'Companion scale'), v => `${v.toFixed(2)}×`));
        body.appendChild(slider('Rotacja', c.rotation ?? 0, -180, 180, 1, v => update('rotation', v, 'Companion rotation'), v => `${v}°`));
        body.appendChild(slider('Nachylenie 3D', c.tilt ?? 0, -45, 45, 1, v => update('tilt', v, 'Companion tilt'), v => `${v}°`));

        body.appendChild(sectionHead('Etykieta'));
        body.appendChild(toggle('Pokaż etykietę', c.labelVisible !== false, v => update('labelVisible', v, 'Companion label')));

        body.appendChild(sectionHead('Stan'));
        body.appendChild(toggle('Widoczny', c.visible !== false, v => update('visible', v, 'Companion visible')));
        body.appendChild(toggle('Zablokowany', !!c.locked, v => update('locked', v, 'Companion lock')));
    }

    _renderInfoBlock(body) {
        const ib = this._store.spec.infoBlock || {};
        body.appendChild(sectionHead('Pozycja'));
        body.appendChild(slider('X', ib.x ?? 55, 0, 100, 1, v => this._store.patch('infoBlock', { x: v }, 'Info X'), v => `${v}%`));
        body.appendChild(slider('Y', ib.y ?? 8, 0, 100, 1, v => this._store.patch('infoBlock', { y: v }, 'Info Y'), v => `${v}%`));
        body.appendChild(slider('Szerokość', ib.w ?? 42, 10, 80, 1, v => this._store.patch('infoBlock', { w: v }, 'Info width'), v => `${v}%`));
        body.appendChild(slider('Wysokość min.', ib.h ?? 20, 10, 80, 1, v => this._store.patch('infoBlock', { h: v }, 'Info height'), v => `${v}%`));

        body.appendChild(sectionHead('Styl'));
        body.appendChild(slider('Krycie tła', ib.bgOpacity ?? 0.85, 0, 1, 0.05, v => this._store.patch('infoBlock', { bgOpacity: v }, 'Info bg opacity'), v => `${(v * 100).toFixed(0)}%`));
        body.appendChild(dropdown('Motyw', ib.theme || 'glass-dark',
            [{ value: 'glass-dark', label: 'Glass Dark' }, { value: 'glass-light', label: 'Glass Light' }, { value: 'minimal', label: 'Minimal' }],
            v => this._store.patch('infoBlock', { theme: v }, 'Info theme')));
        body.appendChild(dropdown('Wyrównanie', ib.align || 'left',
            ['left', 'center', 'right'],
            v => this._store.patch('infoBlock', { align: v }, 'Info align')));

        body.appendChild(sectionHead('Stan'));
        body.appendChild(toggle('Widoczny', ib.visible !== false, v => this._store.patch('infoBlock', { visible: v }, 'Info visible')));
        body.appendChild(toggle('Zablokowany', !!ib.locked, v => this._store.patch('infoBlock', { locked: v }, 'Info lock')));
    }
}
