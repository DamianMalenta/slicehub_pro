/**
 * Online Studio — COMPANIONS tab
 *
 * Two-pane layout:
 *   Left:  pick a dish (typically a PIZZA SKU)
 *   Right: ordered companions for that dish (drinks, sauces, sides, desserts)
 *
 * companions_save replaces the entire list for the selected dish.
 */

export function mountCompanions(root, Studio, Api) {
    const state = {
        dishSku: null,
        companions: [],
        dirty: false,
    };

    root.innerHTML = `
        <div class="comp-board">
            <div class="comp-col">
                <h3>Danie bazowe</h3>
                <input id="comp2-dish-search" class="input" placeholder="Szukaj dania..." style="margin-bottom:8px">
                <div id="comp2-dishes" class="composer__dishes" style="max-height:calc(100vh - 280px)"></div>
            </div>
            <div class="comp-col">
                <div class="row row--between mb-8">
                    <h3 id="comp2-title">Wybierz danie z lewej</h3>
                    <div class="row">
                        <button id="comp2-reset" class="btn btn--sm btn--ghost" disabled><i class="fa-solid fa-rotate-left"></i></button>
                        <button id="comp2-save"  class="btn btn--sm btn--accent" disabled><i class="fa-solid fa-check"></i> Zapisz</button>
                    </div>
                </div>
                <div id="comp2-list" style="margin-bottom:16px"></div>
                <div class="card">
                    <div class="card__title mb-8">Dodaj towarzyszący</div>
                    <div class="row" style="gap:8px">
                        <select id="comp2-add-sku" class="select" style="flex:2"></select>
                        <select id="comp2-add-type" class="select" style="flex:1">
                            <option value="drink">drink</option>
                            <option value="sauce">sauce</option>
                            <option value="side">side</option>
                            <option value="dessert">dessert</option>
                            <option value="extra">extra</option>
                        </select>
                        <button id="comp2-add-btn" class="btn btn--accent"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>
            </div>
        </div>
    `;

    const $dishes = root.querySelector('#comp2-dishes');
    const $list   = root.querySelector('#comp2-list');
    const $title  = root.querySelector('#comp2-title');
    const $save   = root.querySelector('#comp2-save');
    const $reset  = root.querySelector('#comp2-reset');
    const $addSku = root.querySelector('#comp2-add-sku');
    const $addType= root.querySelector('#comp2-add-type');
    const $addBtn = root.querySelector('#comp2-add-btn');
    const $dishSearch = root.querySelector('#comp2-dish-search');

    function renderDishes() {
        const items = (Studio.menu?.items || []).filter(it => it.isPizza); // only PIZZA hosts companions
        const q = ($dishSearch.value || '').toLowerCase();
        const filtered = items.filter(it => !q || it.sku.toLowerCase().includes(q) || (it.name || '').toLowerCase().includes(q));
        $dishes.innerHTML = filtered.map(it => {
            const thumb = it.imageUrl
                ? `<img src="${it.imageUrl}" alt="" loading="lazy" onerror="this.style.display='none'">`
                : `<i class="fa-solid fa-pizza-slice"></i>`;
            return `
                <div class="composer__dish ${state.dishSku === it.sku ? 'active' : ''}" data-sku="${it.sku}" title="${it.sku}">
                    <div class="composer__dish-thumb">${thumb}</div>
                    <div class="composer__dish-body">
                        <div class="composer__dish-name">${it.name || it.sku}</div>
                        <div class="composer__dish-meta"><span class="pill pill--accent">PIZZA</span></div>
                    </div>
                </div>
            `;
        }).join('');
        $dishes.querySelectorAll('.composer__dish').forEach(el => {
            el.onclick = () => loadDish(el.dataset.sku);
        });
    }

    function renderAddSelect() {
        const items = (Studio.menu?.items || []).filter(it => !it.isPizza && it.isActive);
        const existingSkus = new Set(state.companions.map(c => c.companionSku));
        $addSku.innerHTML = items
            .filter(i => !existingSkus.has(i.sku))
            .map(i => `<option value="${i.sku}" data-name="${i.name || ''}">${i.name || i.sku} · ${i.categoryName || ''}</option>`)
            .join('') || '<option value="" disabled>Brak produktów do dodania</option>';
    }

    function renderList() {
        if (!state.dishSku) { $list.innerHTML = ''; return; }
        if (state.companions.length === 0) {
            $list.innerHTML = '<div class="muted" style="padding:20px;text-align:center">Brak companions. Dodaj z dropdownu poniżej.</div>';
            return;
        }
        const itemBySku = new Map((Studio.menu?.items || []).map(i => [i.sku, i]));
        $list.innerHTML = state.companions.map((c, idx) => {
            const it = itemBySku.get(c.companionSku);
            const thumb = it?.imageUrl
                ? `<img src="${it.imageUrl}" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;border-radius:8px">`
                : `<i class="fa-solid fa-box"></i>`;
            return `
            <div class="comp-slot" data-idx="${idx}" draggable="true">
                <div class="comp-slot__thumb">${thumb}</div>
                <div>
                    <div class="comp-slot__name">${c.name || c.companionSku}</div>
                    <div class="comp-slot__meta">${c.companionSku} · slot ${c.boardSlot} · ${c.companionType}</div>
                </div>
                <div class="row">
                    <select class="select js-type" style="padding:4px 8px;font-size:11px">
                        ${['drink','sauce','side','dessert','extra'].map(t => `<option value="${t}" ${t === c.companionType ? 'selected' : ''}>${t}</option>`).join('')}
                    </select>
                    <input class="input js-slot" type="number" min="0" max="5" value="${c.boardSlot}" style="width:50px;padding:4px 8px;font-size:11px">
                    <button class="icon-btn js-up"><i class="fa-solid fa-arrow-up"></i></button>
                    <button class="icon-btn js-down"><i class="fa-solid fa-arrow-down"></i></button>
                    <button class="icon-btn icon-btn--danger js-del"><i class="fa-solid fa-trash"></i></button>
                </div>
            </div>
        `;
        }).join('');

        $list.querySelectorAll('.comp-slot').forEach(slot => {
            const idx = parseInt(slot.dataset.idx, 10);
            slot.querySelector('.js-type').onchange = (e) => { state.companions[idx].companionType = e.target.value; markDirty(); };
            slot.querySelector('.js-slot').onchange = (e) => { state.companions[idx].boardSlot = Math.max(0, Math.min(5, parseInt(e.target.value, 10) || 0)); markDirty(); };
            slot.querySelector('.js-up').onclick = () => { if (idx > 0) { swap(idx, idx-1); } };
            slot.querySelector('.js-down').onclick = () => { if (idx < state.companions.length - 1) { swap(idx, idx+1); } };
            slot.querySelector('.js-del').onclick = () => {
                if (!confirm(`Usunąć ${state.companions[idx].name}?`)) return;
                state.companions.splice(idx, 1);
                markDirty();
                renderList();
                renderAddSelect();
            };
        });
    }

    function swap(i, j) {
        const t = state.companions[i];
        state.companions[i] = state.companions[j];
        state.companions[j] = t;
        markDirty();
        renderList();
    }

    function markDirty() {
        state.dirty = true;
        $save.disabled  = false;
        $reset.disabled = false;
    }

    async function loadDish(sku) {
        if (state.dirty && !confirm('Niezapisane zmiany — porzucić?')) return;
        const r = await Api.companionsList(sku);
        if (!r.success) { Studio.toast(r.message || 'Błąd.', 'err'); return; }
        state.dishSku = sku;
        state.companions = r.data.companions || [];
        state.dirty = false;
        const item = (Studio.menu?.items || []).find(i => i.sku === sku);
        $title.textContent = item ? `${item.name} · ${state.companions.length} companions` : sku;
        $save.disabled  = true;
        $reset.disabled = true;
        renderDishes();
        renderList();
        renderAddSelect();
    }

    async function save() {
        if (!state.dishSku) return;
        const r = await Api.companionsSave(state.dishSku, state.companions.map((c, i) => ({
            companionSku: c.companionSku,
            companionType: c.companionType,
            boardSlot: c.boardSlot ?? i,
            displayOrder: i,
        })));
        if (r.success) {
            Studio.toast(`Zapisano ${r.data.count} companions.`, 'ok');
            loadDish(state.dishSku);
        } else {
            Studio.toast(r.message || 'Błąd zapisu.', 'err');
        }
    }

    $addBtn.onclick = () => {
        const sku = $addSku.value;
        if (!sku) return;
        const item = (Studio.menu?.items || []).find(i => i.sku === sku);
        state.companions.push({
            companionSku: sku,
            name: item?.name || sku,
            companionType: $addType.value,
            boardSlot: state.companions.length,
            displayOrder: state.companions.length,
            isActive: true,
        });
        markDirty();
        renderList();
        renderAddSelect();
    };

    $save.onclick  = save;
    $reset.onclick = () => { if (confirm('Odrzucić zmiany?')) loadDish(state.dishSku); };
    $dishSearch.oninput = renderDishes;

    return {
        onEnter: async () => {
            if (!Studio.menu) await Studio.refreshMenu();
            renderDishes();
            renderAddSelect();
            if (!state.dishSku) {
                const firstPizza = (Studio.menu?.items || []).find(i => i.isPizza);
                if (firstPizza) loadDish(firstPizza.sku);
            }
        },
    };
}
