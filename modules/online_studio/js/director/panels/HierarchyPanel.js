/**
 * HierarchyPanel — left panel: scene tree with visibility/lock/delete + add buttons.
 *
 * Uses a `director` callback host to delegate add-layer / add-companion / delete actions
 * back to DirectorApp (so picker modals + history happen in one place).
 */
export class HierarchyPanel {
    constructor(container, store, selection, director) {
        this._el = container;
        this._store = store;
        this._sel = selection;
        this._director = director;
        this._el.classList.add('dt-hierarchy');
        this._store.onChange(() => this.refresh());
        this._sel.onChange(() => this._updateHighlight());
        this.refresh();
    }

    refresh() {
        const spec = this._store.spec;
        this._el.innerHTML = '';

        const header = document.createElement('div');
        header.className = 'dt-panel-head';
        header.innerHTML = '<i class="fa-solid fa-sitemap"></i> Hierarchy';
        this._el.appendChild(header);

        const tree = document.createElement('div');
        tree.className = 'dt-tree';

        tree.appendChild(this._treeItem({
            id: 'stage', icon: 'fa-border-all', label: 'Stage',
            visible: null, locked: null, hasChildren: false,
        }));

        // Pizza node + nested layers
        const pizzaNode = this._treeItem({
            id: 'pizza', icon: 'fa-circle', label: 'Pizza',
            visible: spec.pizza?.visible !== false, locked: null, hasChildren: true,
        });
        const layersContainer = document.createElement('div');
        layersContainer.className = 'dt-tree__children';

        const layers = (spec.pizza?.layers || []);
        if (layers.length === 0) {
            const empty = document.createElement('button');
            empty.className = 'dt-tree-add';
            empty.innerHTML = '<i class="fa-solid fa-plus"></i> Dodaj pierwszą warstwę';
            empty.onclick = (e) => { e.stopPropagation(); this._director?.addLayer(); };
            layersContainer.appendChild(empty);
        } else {
            // Warstwy w kolejności stacku (od dołu do góry wizualnie).
            // Sortujemy po zIndex ASC i odwracamy tak, żeby w hierarchii GÓRNA pozycja
            // = GÓRNA warstwa wizualna (intuicyjne jak w Photoshop/Figma).
            const sortedLayers = [...layers].sort((a, b) => (b.zIndex || 0) - (a.zIndex || 0));
            sortedLayers.forEach((L, visualIdx) => {
                const name = L.layerSku || 'layer';
                const short = name.length > 22 ? name.slice(0, 20) + '…' : name;
                const isFirst = visualIdx === 0;
                const isLast  = visualIdx === sortedLayers.length - 1;
                const node = this._treeItem({
                    id: `layer:${L.layerSku}`, icon: 'fa-image', label: short,
                    visible: L.visible !== false, locked: !!L.locked, hasChildren: false,
                    deletable: true,
                    duplicable: true,
                    canMoveUp:   !isFirst,
                    canMoveDown: !isLast,
                });
                node.draggable = true;
                node.dataset.layerSku = L.layerSku;
                this._wireLayerDrag(node, L.layerSku, sortedLayers);
                layersContainer.appendChild(node);
            });
            const addBtn = document.createElement('button');
            addBtn.className = 'dt-tree-add';
            addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Dodaj warstwę';
            addBtn.onclick = (e) => { e.stopPropagation(); this._director?.addLayer(); };
            layersContainer.appendChild(addBtn);
        }

        pizzaNode.appendChild(layersContainer);
        tree.appendChild(pizzaNode);

        // Companions section header + items
        const compsHeader = document.createElement('div');
        compsHeader.className = 'dt-tree-section';
        const compCount = (spec.companions || []).length;
        compsHeader.innerHTML = `<span><i class="fa-solid fa-mug-hot"></i> Companions <small>(${compCount})</small></span>`;
        const addComp = document.createElement('button');
        addComp.className = 'dt-tree-section__add';
        addComp.title = 'Dodaj companiona';
        addComp.innerHTML = '<i class="fa-solid fa-plus"></i>';
        addComp.onclick = () => this._director?.addCompanion();
        compsHeader.appendChild(addComp);
        tree.appendChild(compsHeader);

        if (compCount === 0) {
            const empty = document.createElement('button');
            empty.className = 'dt-tree-add dt-tree-add--inline';
            empty.innerHTML = '<i class="fa-solid fa-plus"></i> Dodaj produkt towarzyszący';
            empty.onclick = () => this._director?.addCompanion();
            tree.appendChild(empty);
        } else {
            (spec.companions || []).forEach((c, i) => {
                const name = c.name || c.sku || `Companion ${i + 1}`;
                tree.appendChild(this._treeItem({
                    id: `companion:${c.sku || i}`, icon: 'fa-mug-hot', label: name,
                    visible: c.visible !== false, locked: !!c.locked, hasChildren: false,
                    deletable: true,
                    duplicable: true,
                }));
            });
        }

        // Info Block
        tree.appendChild(this._treeItem({
            id: 'infoBlock', icon: 'fa-align-left', label: 'Info Block',
            visible: spec.infoBlock?.visible !== false, locked: !!spec.infoBlock?.locked,
            hasChildren: false,
        }));

        this._el.appendChild(tree);
    }

    _treeItem({ id, icon, label, visible, locked, hasChildren, deletable, duplicable, canMoveUp, canMoveDown }) {
        const item = document.createElement('div');
        item.className = 'dt-tree-item';
        item.dataset.treeId = id;
        if (this._sel.key === id) item.classList.add('is-selected');

        const row = document.createElement('div');
        row.className = 'dt-tree-item__row';

        if (hasChildren) {
            const tog = document.createElement('span');
            tog.className = 'dt-tree-item__toggle';
            tog.innerHTML = '<i class="fa-solid fa-caret-down"></i>';
            tog.onclick = (e) => {
                e.stopPropagation();
                const ch = item.querySelector('.dt-tree__children');
                if (ch) ch.classList.toggle('is-collapsed');
                tog.classList.toggle('is-collapsed');
            };
            row.appendChild(tog);
        } else {
            const sp = document.createElement('span');
            sp.className = 'dt-tree-item__spacer';
            row.appendChild(sp);
        }

        const ico = document.createElement('i');
        ico.className = `fa-solid ${icon} dt-tree-item__icon`;
        row.appendChild(ico);

        const lbl = document.createElement('span');
        lbl.className = 'dt-tree-item__label';
        lbl.textContent = label;
        row.appendChild(lbl);

        const actions = document.createElement('span');
        actions.className = 'dt-tree-item__actions';

        if (visible !== null && visible !== undefined) {
            const vis = document.createElement('button');
            vis.className = 'dt-tree-btn';
            vis.title = 'Visibility';
            vis.innerHTML = `<i class="fa-solid ${visible !== false ? 'fa-eye' : 'fa-eye-slash'}"></i>`;
            vis.onclick = (e) => { e.stopPropagation(); this._toggleVisibility(id); };
            actions.appendChild(vis);
        }

        if (locked !== null && locked !== undefined) {
            const lock = document.createElement('button');
            lock.className = 'dt-tree-btn';
            lock.title = 'Lock';
            lock.innerHTML = `<i class="fa-solid ${locked ? 'fa-lock' : 'fa-lock-open'}"></i>`;
            lock.onclick = (e) => { e.stopPropagation(); this._toggleLock(id); };
            actions.appendChild(lock);
        }

        // Move up/down — tylko dla warstw, gdzie ma to sens (hierarchia)
        if (canMoveUp !== undefined || canMoveDown !== undefined) {
            const up = document.createElement('button');
            up.className = 'dt-tree-btn dt-tree-btn--move';
            up.title = 'W górę stacku (])';
            up.disabled = !canMoveUp;
            up.innerHTML = '<i class="fa-solid fa-chevron-up"></i>';
            up.onclick = (e) => {
                e.stopPropagation();
                if (id.startsWith('layer:')) this._director?.moveLayerUp(id.slice(6));
            };
            actions.appendChild(up);

            const down = document.createElement('button');
            down.className = 'dt-tree-btn dt-tree-btn--move';
            down.title = 'W dół stacku ([)';
            down.disabled = !canMoveDown;
            down.innerHTML = '<i class="fa-solid fa-chevron-down"></i>';
            down.onclick = (e) => {
                e.stopPropagation();
                if (id.startsWith('layer:')) this._director?.moveLayerDown(id.slice(6));
            };
            actions.appendChild(down);
        }

        if (duplicable) {
            const dup = document.createElement('button');
            dup.className = 'dt-tree-btn dt-tree-btn--dup';
            dup.title = 'Duplikuj (Ctrl+D)';
            dup.innerHTML = '<i class="fa-solid fa-clone"></i>';
            dup.onclick = (e) => {
                e.stopPropagation();
                if (id.startsWith('layer:')) this._director?.duplicateLayer(id.slice(6));
                else if (id.startsWith('companion:')) this._director?.duplicateCompanion(id.slice(10));
            };
            actions.appendChild(dup);
        }

        if (deletable) {
            const del = document.createElement('button');
            del.className = 'dt-tree-btn dt-tree-btn--del';
            del.title = 'Usuń (Del)';
            del.innerHTML = '<i class="fa-solid fa-trash"></i>';
            del.onclick = (e) => {
                e.stopPropagation();
                if (id.startsWith('layer:')) this._director?.deleteLayer(id.slice(6));
                else if (id.startsWith('companion:')) this._director?.deleteCompanion(id.slice(10));
            };
            actions.appendChild(del);
        }

        row.appendChild(actions);
        item.appendChild(row);

        row.addEventListener('click', () => {
            const [type, subId] = id.includes(':') ? id.split(':') : [id, null];
            this._sel.select(type, subId);
        });

        return item;
    }

    /**
     * Drag-and-drop reorder warstw w hierarchii.
     * Visual order = kolejność w panelu (top-to-bottom w hierarchii = od góry do dołu stacku).
     * Po dropie wywołujemy director.reorderLayers(newOrder) który liniowo przelicza zIndexy.
     */
    _wireLayerDrag(node, layerSku, sortedLayers) {
        node.addEventListener('dragstart', (e) => {
            node.classList.add('dt-tree-item--dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/slicehub-layer-sku', layerSku);
        });
        node.addEventListener('dragend', () => {
            node.classList.remove('dt-tree-item--dragging');
            // Wyczyść wszelkie `drop-target` podpowiedzi
            this._el.querySelectorAll('.dt-tree-item--drop-above, .dt-tree-item--drop-below')
                .forEach(el => el.classList.remove('dt-tree-item--drop-above', 'dt-tree-item--drop-below'));
        });
        node.addEventListener('dragover', (e) => {
            const from = e.dataTransfer.types.includes('text/slicehub-layer-sku');
            if (!from) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const rect = node.getBoundingClientRect();
            const isUpper = (e.clientY - rect.top) < rect.height / 2;
            node.classList.toggle('dt-tree-item--drop-above', isUpper);
            node.classList.toggle('dt-tree-item--drop-below', !isUpper);
        });
        node.addEventListener('dragleave', () => {
            node.classList.remove('dt-tree-item--drop-above', 'dt-tree-item--drop-below');
        });
        node.addEventListener('drop', (e) => {
            e.preventDefault();
            const draggedSku = e.dataTransfer.getData('text/slicehub-layer-sku');
            if (!draggedSku || draggedSku === layerSku) return;
            const rect = node.getBoundingClientRect();
            const above = (e.clientY - rect.top) < rect.height / 2;
            node.classList.remove('dt-tree-item--drop-above', 'dt-tree-item--drop-below');

            // Budujemy nową kolejność na podstawie aktualnego sortu
            const orderTop = sortedLayers.map(L => L.layerSku);
            const filtered = orderTop.filter(s => s !== draggedSku);
            const insertIdx = filtered.indexOf(layerSku) + (above ? 0 : 1);
            filtered.splice(insertIdx, 0, draggedSku);
            // orderTop to kolejność TOP → BOTTOM (highest zIndex first).
            // reorderLayers oczekuje BOTTOM → TOP, więc odwracamy.
            const orderBottomUp = filtered.slice().reverse();
            this._director?.reorderLayers(orderBottomUp);
        });
    }

    _updateHighlight() {
        this._el.querySelectorAll('.dt-tree-item').forEach(el => {
            el.classList.toggle('is-selected', el.dataset.treeId === this._sel.key);
        });
    }

    _toggleVisibility(id) {
        const spec = this._store.spec;
        if (id === 'pizza') {
            this._store.patch('pizza', { visible: !(spec.pizza?.visible !== false) }, 'Toggle pizza visibility');
        } else if (id === 'infoBlock') {
            this._store.patch('infoBlock', { visible: !(spec.infoBlock?.visible !== false) }, 'Toggle info visibility');
        } else if (id.startsWith('layer:')) {
            const sku = id.slice(6);
            const layers = structuredClone(spec.pizza?.layers || []);
            const L = layers.find(l => l.layerSku === sku);
            if (L) { L.visible = !(L.visible !== false); this._store.patch('pizza.layers', layers, 'Toggle layer visibility'); }
        } else if (id.startsWith('companion:')) {
            const key = id.slice(10);
            const comps = structuredClone(spec.companions || []);
            const c = comps.find((c, i) => c.sku === key || String(i) === key);
            if (c) { c.visible = !(c.visible !== false); this._store.patch('companions', comps, 'Toggle companion visibility'); }
        }
    }

    _toggleLock(id) {
        const spec = this._store.spec;
        if (id === 'infoBlock') {
            this._store.patch('infoBlock', { locked: !spec.infoBlock?.locked }, 'Toggle info lock');
        } else if (id.startsWith('layer:')) {
            const sku = id.slice(6);
            const layers = structuredClone(spec.pizza?.layers || []);
            const L = layers.find(l => l.layerSku === sku);
            if (L) { L.locked = !L.locked; this._store.patch('pizza.layers', layers, 'Toggle layer lock'); }
        } else if (id.startsWith('companion:')) {
            const key = id.slice(10);
            const comps = structuredClone(spec.companions || []);
            const c = comps.find((c, i) => c.sku === key || String(i) === key);
            if (c) { c.locked = !c.locked; this._store.patch('companions', comps, 'Toggle companion lock'); }
        }
    }
}
