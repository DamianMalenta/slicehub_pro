/**
 * TimelinePanel — bottom panel: layer stack.
 * Displays layers top-down (highest z-index first),
 * supports drag-to-reorder with z-index normalization,
 * inline blend/opacity, visibility/lock toggles.
 */
export class TimelinePanel {
    constructor(container, store, selection) {
        this._el = container;
        this._store = store;
        this._sel = selection;
        this._el.classList.add('dt-timeline');
        this._store.onChange(() => this.refresh());
        this._sel.onChange(() => this._updateHighlight());
        this._dragIdx = null;
        this.refresh();
    }

    refresh() {
        const spec = this._store.spec;
        const layers = [...(spec.pizza?.layers || [])].sort((a, b) => (b.zIndex || 0) - (a.zIndex || 0));

        this._el.innerHTML = '';

        const header = document.createElement('div');
        header.className = 'dt-panel-head';
        header.innerHTML = `
            <i class="fa-solid fa-layer-group"></i> Timeline
            <span class="dt-badge">${layers.length}</span>
            <span class="dt-panel-head__hint">Przeciągnij, aby zmienić kolejność</span>
        `;
        this._el.appendChild(header);

        if (layers.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'dt-tl-empty';
            empty.innerHTML = `
                <i class="fa-solid fa-layer-group"></i>
                <div>Brak warstw</div>
                <small>Wybierz danie z menu po lewej lub dodaj warstwy w Kompozytorze</small>
            `;
            this._el.appendChild(empty);
            return;
        }

        const list = document.createElement('div');
        list.className = 'dt-tl-list';

        layers.forEach((L, rowIdx) => {
            const row = document.createElement('div');
            row.className = 'dt-tl-row';
            row.dataset.layerSku = L.layerSku;
            if (this._sel.type === 'layer' && this._sel.id === L.layerSku) row.classList.add('is-selected');

            row.draggable = true;
            row.addEventListener('dragstart', (e) => {
                this._dragIdx = rowIdx;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', L.layerSku);
                row.classList.add('is-dragging');
            });
            row.addEventListener('dragend', () => {
                row.classList.remove('is-dragging');
                this._el.querySelectorAll('.is-dragover').forEach(r => r.classList.remove('is-dragover'));
                this._dragIdx = null;
            });
            row.addEventListener('dragover', (e) => {
                if (this._dragIdx === null || this._dragIdx === rowIdx) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                row.classList.add('is-dragover');
            });
            row.addEventListener('dragleave', () => row.classList.remove('is-dragover'));
            row.addEventListener('drop', (e) => {
                e.preventDefault();
                row.classList.remove('is-dragover');
                if (this._dragIdx !== null && this._dragIdx !== rowIdx) {
                    this._reorder(this._dragIdx, rowIdx, layers);
                }
            });

            const drag = document.createElement('span');
            drag.className = 'dt-tl-grip';
            drag.innerHTML = '<i class="fa-solid fa-grip-vertical"></i>';

            const thumb = document.createElement('div');
            thumb.className = 'dt-tl-thumb';
            const url = L.assetUrl ? this._resolveUrl(L.assetUrl) : '';
            if (url) thumb.style.backgroundImage = `url("${url}")`;
            else thumb.textContent = (L.layerSku || '?').charAt(0).toUpperCase();

            const name = document.createElement('div');
            name.className = 'dt-tl-name';
            const short = (L.layerSku || 'layer').length > 24 ? (L.layerSku || '').slice(0, 22) + '…' : (L.layerSku || 'layer');
            const blendLabel = (L.blendMode || 'normal') === 'normal' ? '' : ` · ${L.blendMode}`;
            name.innerHTML = `<span>${short}</span><small>z:${L.zIndex || 0} · α:${((L.alpha ?? 1) * 100).toFixed(0)}%${blendLabel}</small>`;

            const blend = document.createElement('select');
            blend.className = 'dt-tl-blend';
            ['normal', 'multiply', 'overlay', 'screen', 'soft-light', 'hard-light', 'darken', 'lighten'].forEach(bm => {
                const o = document.createElement('option');
                o.value = bm; o.textContent = bm;
                if (bm === (L.blendMode || 'normal')) o.selected = true;
                blend.appendChild(o);
            });
            blend.onchange = (e) => { e.stopPropagation(); this._updateLayer(L.layerSku, 'blendMode', blend.value, 'Blend mode'); };
            blend.onclick = (e) => e.stopPropagation();

            const opacity = document.createElement('input');
            opacity.type = 'range';
            opacity.className = 'dt-tl-opacity';
            opacity.min = 0; opacity.max = 1; opacity.step = 0.05;
            opacity.value = L.alpha ?? 1;
            opacity.oninput = (e) => { e.stopPropagation(); this._updateLayer(L.layerSku, 'alpha', Number(opacity.value), 'Opacity'); };
            opacity.onclick = (e) => e.stopPropagation();

            const vis = document.createElement('button');
            vis.className = 'dt-tl-btn';
            vis.title = L.visible !== false ? 'Ukryj' : 'Pokaż';
            vis.innerHTML = `<i class="fa-solid ${L.visible !== false ? 'fa-eye' : 'fa-eye-slash'}"></i>`;
            vis.onclick = (e) => { e.stopPropagation(); this._updateLayer(L.layerSku, 'visible', L.visible === false, 'Widoczność'); };

            const lock = document.createElement('button');
            lock.className = 'dt-tl-btn';
            lock.title = L.locked ? 'Odblokuj' : 'Zablokuj';
            lock.innerHTML = `<i class="fa-solid ${L.locked ? 'fa-lock' : 'fa-lock-open'}"></i>`;
            lock.onclick = (e) => { e.stopPropagation(); this._updateLayer(L.layerSku, 'locked', !L.locked, 'Lock'); };

            row.onclick = () => this._sel.select('layer', L.layerSku);

            row.appendChild(drag);
            row.appendChild(thumb);
            row.appendChild(name);
            row.appendChild(blend);
            row.appendChild(opacity);
            row.appendChild(vis);
            row.appendChild(lock);
            list.appendChild(row);
        });

        this._el.appendChild(list);
    }

    _updateHighlight() {
        this._el.querySelectorAll('.dt-tl-row').forEach(r => {
            r.classList.toggle('is-selected', this._sel.type === 'layer' && this._sel.id === r.dataset.layerSku);
        });
    }

    _updateLayer(sku, key, value, label) {
        const layers = structuredClone(this._store.spec.pizza?.layers || []);
        const L = layers.find(l => l.layerSku === sku);
        if (L) { L[key] = value; this._store.patch('pizza.layers', layers, label); }
    }

    /**
     * Proper drag-reorder: normalizes z-indexes into step-10 buckets based on
     * final sort order. Dragging a row UP = higher z-index.
     */
    _reorder(fromIdx, toIdx, sortedLayers) {
        const visible = sortedLayers.slice();
        const [moved] = visible.splice(fromIdx, 1);
        visible.splice(toIdx, 0, moved);

        const skuOrder = new Map();
        const n = visible.length;
        visible.forEach((L, i) => {
            skuOrder.set(L.layerSku, (n - i) * 10);
        });

        const allLayers = structuredClone(this._store.spec.pizza?.layers || []);
        allLayers.forEach(L => {
            if (skuOrder.has(L.layerSku)) L.zIndex = skuOrder.get(L.layerSku);
        });
        this._store.patch('pizza.layers', allLayers, 'Zmień kolejność warstw');
    }

    _resolveUrl(u) {
        if (!u) return '';
        const s = String(u).trim();
        if (/^(https?:|data:|blob:)/i.test(s)) return s;
        if (s.startsWith('/slicehub/')) return s;
        if (s.startsWith('/')) return '/slicehub' + s;
        return '/slicehub/' + s;
    }
}
