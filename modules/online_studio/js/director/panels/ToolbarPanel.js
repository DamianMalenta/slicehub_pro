/**
 * ToolbarPanel — top bar with workspace tabs, tools, magic palette,
 * preview toggle, undo/redo, save.
 */
import { PRESETS } from '../lib/ScenePresets.js';

const WORKSPACES = [
    { id: 'compose', label: 'Compose', icon: 'fa-vector-square' },
    { id: 'color', label: 'Color', icon: 'fa-palette' },
    { id: 'light', label: 'Light', icon: 'fa-sun' },
    { id: 'scenography', label: 'Scenography', icon: 'fa-clapperboard' },
    { id: 'companions', label: 'Companions', icon: 'fa-mug-hot' },
    { id: 'promotions', label: 'Promocje', icon: 'fa-tags' },
    { id: 'preview', label: 'Preview', icon: 'fa-eye' },
];

const TOOLS = [
    { id: 'select', label: 'Select (V)', icon: 'fa-arrow-pointer', key: 'V' },
    { id: 'pan', label: 'Pan (H)', icon: 'fa-hand', key: 'H' },
];

const MAGIC = [
    { id: 'enhance', label: 'Magic Enhance', icon: 'fa-wand-magic-sparkles', key: 'M', accent: true },
    { id: 'conform', label: 'Magic Conform (spójność)', icon: 'fa-compass-drafting' },
    { id: 'harmonize', label: 'Magic Harmonize (napraw outliery)', icon: 'fa-arrows-to-dot' },
    { id: 'relight', label: 'Relight', icon: 'fa-lightbulb' },
    { id: 'companions', label: 'Auto Companions', icon: 'fa-mug-hot' },
    { id: 'colorgrade', label: 'Color Grade', icon: 'fa-swatchbook' },
    { id: 'bake', label: 'Magic Bake', icon: 'fa-fire-flame-curved' },
    { id: 'dust', label: 'Magic Dust', icon: 'fa-wand-sparkles' },
];

/* M3 · #4 Auto-perspective — dropdown kamery w toolbarze.
   Klucze muszą być identyczne z CAMERA_PRESETS w core/js/scene_renderer.js. */
const CAMERAS = [
    { id: '',                    label: 'Kamera: domyślna',        icon: 'fa-camera' },
    { id: 'top_down',            label: 'Top-down (płasko 90°)',   icon: 'fa-circle' },
    { id: 'hero_three_quarter',  label: 'Hero 3/4 (lekki kąt)',    icon: 'fa-compass' },
    { id: 'macro_close',         label: 'Makro (bliska)',          icon: 'fa-magnifying-glass-plus' },
    { id: 'wide_establishing',   label: 'Establishing (szeroko)',  icon: 'fa-expand' },
    { id: 'dutch_angle',         label: 'Dutch angle (skos)',      icon: 'fa-arrow-turn-up' },
    { id: 'rack_focus',          label: 'Rack focus (DOF)',        icon: 'fa-aperture' },
];

export class ToolbarPanel {
    constructor(container, { onWorkspace, onTool, onMagic, onPreset, onUndo, onRedo, onSave, onPreview, onBeforeAfter, onMobilePreview, onShortcutLegend, onAddLayer, onAddCompanion, onCamera }) {
        this._el = container;
        this._handlers = { onWorkspace, onTool, onMagic, onPreset, onUndo, onRedo, onSave, onPreview, onBeforeAfter, onMobilePreview, onShortcutLegend, onAddLayer, onAddCompanion, onCamera };
        this._activeWs = 'compose';
        this._activeTool = 'select';
        this._activeCamera = '';
        this._render();
    }

    setCamera(id) {
        this._activeCamera = id || '';
        const sel = this._el.querySelector('.dt-camera-select');
        if (sel) sel.value = this._activeCamera;
        const label = this._el.querySelector('.dt-camera-label');
        if (label) {
            const found = CAMERAS.find(c => c.id === this._activeCamera);
            label.textContent = found ? found.label.replace(/^Kamera:\s*/, '') : 'domyślna';
        }
    }

    setWorkspace(id) {
        this._activeWs = id;
        this._el.querySelectorAll('.dt-ws-btn').forEach(b => b.classList.toggle('is-active', b.dataset.ws === id));
    }

    setTool(id) {
        this._activeTool = id;
        this._el.querySelectorAll('.dt-tool-btn').forEach(b => b.classList.toggle('is-active', b.dataset.tool === id));
    }

    updateUndoRedo(canUndo, canRedo) {
        const u = this._el.querySelector('[data-action="undo"]');
        const r = this._el.querySelector('[data-action="redo"]');
        if (u) u.disabled = !canUndo;
        if (r) r.disabled = !canRedo;
    }

    _render() {
        this._el.innerHTML = '';
        this._el.classList.add('dt-toolbar');

        const left = document.createElement('div');
        left.className = 'dt-toolbar__left';

        WORKSPACES.forEach(ws => {
            const b = document.createElement('button');
            b.className = `dt-ws-btn ${ws.id === this._activeWs ? 'is-active' : ''}`;
            b.dataset.ws = ws.id;
            b.title = ws.label;
            b.innerHTML = `<i class="fa-solid ${ws.icon}"></i><span>${ws.label}</span>`;
            b.onclick = () => this._handlers.onWorkspace?.(ws.id);
            left.appendChild(b);
        });

        const center = document.createElement('div');
        center.className = 'dt-toolbar__center';

        const toolGroup = document.createElement('div');
        toolGroup.className = 'dt-btn-group';
        TOOLS.forEach(t => {
            const b = document.createElement('button');
            b.className = `dt-tool-btn ${t.id === this._activeTool ? 'is-active' : ''}`;
            b.dataset.tool = t.id;
            b.title = t.label;
            b.innerHTML = `<i class="fa-solid ${t.icon}"></i>`;
            b.onclick = () => this._handlers.onTool?.(t.id);
            toolGroup.appendChild(b);
        });
        center.appendChild(toolGroup);

        const sep1 = document.createElement('div');
        sep1.className = 'dt-sep';
        center.appendChild(sep1);

        const addGroup = document.createElement('div');
        addGroup.className = 'dt-btn-group dt-add-group';
        const addLayer = document.createElement('button');
        addLayer.className = 'dt-btn dt-btn--add';
        addLayer.title = 'Dodaj warstwę do pizzy (A)';
        addLayer.innerHTML = '<i class="fa-solid fa-plus"></i><span>Warstwa</span>';
        addLayer.onclick = () => this._handlers.onAddLayer?.();
        addGroup.appendChild(addLayer);

        const addComp = document.createElement('button');
        addComp.className = 'dt-btn dt-btn--add';
        addComp.title = 'Dodaj companiona (Shift+A)';
        addComp.innerHTML = '<i class="fa-solid fa-plus"></i><span>Companion</span>';
        addComp.onclick = () => this._handlers.onAddCompanion?.();
        addGroup.appendChild(addComp);
        center.appendChild(addGroup);

        const sepA = document.createElement('div');
        sepA.className = 'dt-sep';
        center.appendChild(sepA);

        const presetBtn = document.createElement('button');
        presetBtn.className = 'dt-preset-btn';
        presetBtn.title = 'Scene Presets';
        presetBtn.innerHTML = '<i class="fa-solid fa-film"></i> Preset <i class="fa-solid fa-caret-down" style="margin-left:4px;font-size:9px"></i>';
        presetBtn.onclick = (e) => this._showPresetMenu(e);
        center.appendChild(presetBtn);

        const cameraWrap = document.createElement('label');
        cameraWrap.className = 'dt-camera-wrap';
        cameraWrap.title = 'Kamera sceny (perspektywa) — zapisywana razem ze sceną';
        const camIcon = document.createElement('i');
        camIcon.className = 'fa-solid fa-camera';
        const camSelect = document.createElement('select');
        camSelect.className = 'dt-camera-select';
        CAMERAS.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.label;
            camSelect.appendChild(opt);
        });
        camSelect.value = this._activeCamera;
        camSelect.onchange = (ev) => {
            const val = ev.target.value || null;
            this._activeCamera = val || '';
            this._handlers.onCamera?.(val);
        };
        cameraWrap.appendChild(camIcon);
        cameraWrap.appendChild(camSelect);
        center.appendChild(cameraWrap);

        const sep2 = document.createElement('div');
        sep2.className = 'dt-sep';
        center.appendChild(sep2);

        const magicGroup = document.createElement('div');
        magicGroup.className = 'dt-btn-group dt-magic-group';
        MAGIC.forEach(m => {
            const b = document.createElement('button');
            b.className = `dt-magic-btn ${m.accent ? 'dt-magic-btn--accent' : ''}`;
            b.title = m.label + (m.key ? ` (${m.key})` : '');
            b.innerHTML = `<i class="fa-solid ${m.icon}"></i>${m.accent ? `<span>${m.label}</span>` : ''}`;
            b.onclick = () => this._handlers.onMagic?.(m.id);
            magicGroup.appendChild(b);
        });
        center.appendChild(magicGroup);

        const right = document.createElement('div');
        right.className = 'dt-toolbar__right';

        const ba = document.createElement('button');
        ba.className = 'dt-btn';
        ba.title = 'Before / After';
        ba.innerHTML = '<i class="fa-solid fa-columns"></i>';
        ba.onclick = () => this._handlers.onBeforeAfter?.();
        right.appendChild(ba);

        const prev = document.createElement('button');
        prev.className = 'dt-btn';
        prev.title = 'Customer Preview (P)';
        prev.innerHTML = '<i class="fa-solid fa-eye"></i>';
        prev.onclick = () => this._handlers.onPreview?.();
        right.appendChild(prev);

        const mob = document.createElement('button');
        mob.className = 'dt-btn';
        mob.title = 'Mobile Preview';
        mob.innerHTML = '<i class="fa-solid fa-mobile-screen"></i>';
        mob.onclick = () => this._handlers.onMobilePreview?.();
        right.appendChild(mob);

        const sep3 = document.createElement('div');
        sep3.className = 'dt-sep';
        right.appendChild(sep3);

        const undo = document.createElement('button');
        undo.className = 'dt-btn';
        undo.dataset.action = 'undo';
        undo.title = 'Undo (Ctrl+Z)';
        undo.innerHTML = '<i class="fa-solid fa-rotate-left"></i>';
        undo.onclick = () => this._handlers.onUndo?.();
        right.appendChild(undo);

        const redo = document.createElement('button');
        redo.className = 'dt-btn';
        redo.dataset.action = 'redo';
        redo.title = 'Redo (Ctrl+Shift+Z)';
        redo.innerHTML = '<i class="fa-solid fa-rotate-right"></i>';
        redo.onclick = () => this._handlers.onRedo?.();
        right.appendChild(redo);

        const sep4 = document.createElement('div');
        sep4.className = 'dt-sep';
        right.appendChild(sep4);

        const save = document.createElement('button');
        save.className = 'dt-btn dt-btn--save';
        save.title = 'Save (Ctrl+S)';
        save.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save';
        save.onclick = () => this._handlers.onSave?.();
        right.appendChild(save);

        const help = document.createElement('button');
        help.className = 'dt-btn';
        help.title = 'Keyboard Shortcuts (?)';
        help.innerHTML = '<i class="fa-solid fa-keyboard"></i>';
        help.onclick = () => this._handlers.onShortcutLegend?.();
        right.appendChild(help);

        this._el.appendChild(left);
        this._el.appendChild(center);
        this._el.appendChild(right);
    }

    _showPresetMenu(e) {
        const existing = document.querySelector('.dt-preset-menu');
        if (existing) { existing.remove(); return; }

        const menu = document.createElement('div');
        menu.className = 'dt-preset-menu';
        PRESETS.forEach(p => {
            const item = document.createElement('button');
            item.className = 'dt-preset-menu__item';
            item.innerHTML = `<i class="fa-solid ${p.icon}"></i><div><strong>${p.label}</strong><small>${p.desc}</small></div>`;
            item.onclick = () => { menu.remove(); this._handlers.onPreset?.(p.name); };
            menu.appendChild(item);
        });
        document.body.appendChild(menu);
        const rect = e.target.closest('button').getBoundingClientRect();
        menu.style.top = `${rect.bottom + 4}px`;
        menu.style.left = `${rect.left}px`;
        setTimeout(() => {
            const close = (ev) => { if (!menu.contains(ev.target)) { menu.remove(); document.removeEventListener('pointerdown', close); } };
            document.addEventListener('pointerdown', close);
        }, 10);
    }
}
