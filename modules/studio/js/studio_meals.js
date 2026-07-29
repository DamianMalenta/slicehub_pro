/**
 * F-S3 — Meal Packages (Combo) — Studio CRUD
 * API: list_meals, get_meal_details, save_meal, delete_meal
 */
(function () {
    const _e = (s) => {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    };

    let _meals = [];
    let _editingId = 0;

    async function loadMeals() {
        const res = await window.apiStudio('list_meals');
        _meals = (res.success && res.data && res.data.meals) ? res.data.meals : [];
        renderList();
    }

    function renderList() {
        const el = document.getElementById('meals-list');
        if (!el) return;
        if (!_meals.length) {
            el.innerHTML = '<p class="text-slate-500 text-[10px] font-bold uppercase text-center py-8">Brak zestawów — dodaj pierwszy combo</p>';
            return;
        }
        el.innerHTML = _meals.map(m => `
            <button type="button" onclick="window.MealEditor.open(${m.id})"
                class="w-full text-left p-4 rounded-xl border border-white/10 bg-black/40 hover:border-amber-500/40 transition flex justify-between items-center gap-3">
                <div>
                    <div class="text-[11px] font-black uppercase text-white">${_e(m.name)}</div>
                    <div class="text-[9px] text-slate-500 font-mono mt-1">${_e(m.ascii_key)} · ${m.type} · ${m.components_count || 0} skł.</div>
                </div>
                <span class="text-[9px] font-bold px-2 py-1 rounded ${m.publication_status === 'Live' ? 'bg-green-900/40 text-green-400' : 'bg-slate-800 text-slate-400'}">${_e(m.publication_status || 'Draft')}</span>
            </button>
        `).join('');
    }

    function fillCategorySelect(selectedId) {
        const sel = document.getElementById('meal-category-id');
        if (!sel) return;
        sel.innerHTML = '<option value="">— bez kategorii (wszystkie w POS) —</option>';
        (window.StudioState.categories || []).forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name;
            if (String(c.id) === String(selectedId)) opt.selected = true;
            sel.appendChild(opt);
        });
    }

    function renderComponents(components) {
        const wrap = document.getElementById('meal-components-editor');
        if (!wrap) return;
        const rows = (components && components.length) ? components : [{ component_type: 'fixed_item', item_sku: '', category_id: '', qty: 1 }];
        wrap.innerHTML = rows.map((c, i) => `
            <div class="meal-comp-row grid grid-cols-12 gap-2 p-3 bg-black/30 rounded-lg border border-white/5" data-idx="${i}">
                <select class="meal-comp-type col-span-3 bg-black/50 border border-white/10 rounded p-2 text-[10px] text-white">
                    <option value="fixed_item" ${c.component_type === 'fixed_item' ? 'selected' : ''}>Stały SKU</option>
                    <option value="category_choice" ${c.component_type === 'category_choice' ? 'selected' : ''}>Wybór z kategorii</option>
                </select>
                <input type="text" class="meal-comp-sku col-span-4 bg-black/50 border border-white/10 rounded p-2 text-[10px] text-white font-mono" placeholder="item_sku" value="${_e(c.item_sku || '')}">
                <input type="number" class="meal-comp-cat col-span-2 bg-black/50 border border-white/10 rounded p-2 text-[10px] text-white" placeholder="cat_id" value="${c.category_id || ''}">
                <input type="number" class="meal-comp-qty col-span-1 bg-black/50 border border-white/10 rounded p-2 text-[10px] text-white" min="1" value="${c.qty || 1}">
                <button type="button" onclick="window.MealEditor.removeCompRow(${i})" class="col-span-2 text-red-400 text-[10px] font-bold uppercase">Usuń</button>
            </div>
        `).join('');
    }

    function collectComponents() {
        const out = [];
        document.querySelectorAll('#meal-components-editor .meal-comp-row').forEach(row => {
            const type = row.querySelector('.meal-comp-type')?.value || 'fixed_item';
            const sku = (row.querySelector('.meal-comp-sku')?.value || '').trim();
            const cat = parseInt(row.querySelector('.meal-comp-cat')?.value || '0', 10) || null;
            const qty = Math.max(1, parseInt(row.querySelector('.meal-comp-qty')?.value || '1', 10));
            if (type === 'fixed_item' && !sku) return;
            if (type === 'category_choice' && !cat) return;
            out.push({
                component_type: type,
                item_sku: type === 'fixed_item' ? sku : null,
                category_id: type === 'category_choice' ? cat : null,
                qty,
            });
        });
        return out;
    }

    window.MealEditor = {
        init: function () {
            loadMeals();
        },

        newMeal: function () {
            _editingId = 0;
            document.getElementById('meal-form-title').textContent = 'Nowy zestaw combo';
            document.getElementById('meal-id').value = '0';
            document.getElementById('meal-ascii-key').value = '';
            document.getElementById('meal-name').value = '';
            document.getElementById('meal-description').value = '';
            document.getElementById('meal-type').value = 'fixed';
            document.getElementById('meal-final-price').value = '';
            document.getElementById('meal-pub-status').value = 'Live';
            document.getElementById('meal-is-active').checked = true;
            fillCategorySelect(8);
            renderComponents([]);
        },

        open: async function (mealId) {
            const res = await window.apiStudio('get_meal_details', { meal_id: mealId });
            if (!res.success || !res.data) {
                if (window.StudioToast) window.StudioToast.show(res.message || 'Nie udało się wczytać zestawu', 'error');
                return;
            }
            const m = res.data;
            _editingId = mealId;
            document.getElementById('meal-form-title').textContent = m.name;
            document.getElementById('meal-id').value = String(m.id);
            document.getElementById('meal-ascii-key').value = m.ascii_key || '';
            document.getElementById('meal-name').value = m.name || '';
            document.getElementById('meal-description').value = m.description || '';
            document.getElementById('meal-type').value = m.type || 'fixed';
            document.getElementById('meal-final-price').value = m.final_price_grosze != null
                ? (parseInt(m.final_price_grosze, 10) / 100).toFixed(2) : '';
            document.getElementById('meal-pub-status').value = m.publication_status || 'Draft';
            document.getElementById('meal-is-active').checked = !!parseInt(m.is_active, 10);
            fillCategorySelect(m.category_id);
            renderComponents(m.components || []);
        },

        addCompRow: function () {
            const comps = collectComponents();
            comps.push({ component_type: 'fixed_item', item_sku: '', category_id: '', qty: 1 });
            renderComponents(comps);
        },

        removeCompRow: function (idx) {
            const comps = collectComponents();
            comps.splice(idx, 1);
            renderComponents(comps.length ? comps : [{ component_type: 'fixed_item', item_sku: '', qty: 1 }]);
        },

        save: async function () {
            const priceRaw = document.getElementById('meal-final-price').value.trim();
            const payload = {
                id: parseInt(document.getElementById('meal-id').value, 10) || 0,
                ascii_key: document.getElementById('meal-ascii-key').value.trim(),
                name: document.getElementById('meal-name').value.trim(),
                description: document.getElementById('meal-description').value.trim(),
                category_id: document.getElementById('meal-category-id').value || null,
                type: document.getElementById('meal-type').value,
                final_price_grosze: priceRaw !== '' ? Math.round(parseFloat(priceRaw) * 100) : null,
                publication_status: document.getElementById('meal-pub-status').value,
                is_active: document.getElementById('meal-is-active').checked ? 1 : 0,
                components: collectComponents(),
            };
            if (!payload.ascii_key || !payload.name) {
                if (window.StudioToast) window.StudioToast.show('SKU i nazwa są wymagane', 'warning');
                return;
            }
            const res = await window.apiStudio('save_meal', payload);
            if (res.success) {
                if (window.StudioToast) window.StudioToast.show(res.message || 'Zapisano', 'success');
                await loadMeals();
                if (res.data && res.data.meal_id) {
                    await window.MealEditor.open(res.data.meal_id);
                }
            } else {
                if (window.StudioToast) window.StudioToast.show(res.message || 'Błąd zapisu', 'error');
            }
        },

        deleteMeal: async function () {
            const id = parseInt(document.getElementById('meal-id').value, 10);
            if (!id) return;
            if (!confirm('Usunąć ten zestaw (soft delete)?')) return;
            const res = await window.apiStudio('delete_meal', { id });
            if (res.success) {
                if (window.StudioToast) window.StudioToast.show('Usunięto', 'success');
                window.MealEditor.newMeal();
                await loadMeals();
            } else {
                if (window.StudioToast) window.StudioToast.show(res.message || 'Błąd', 'error');
            }
        },
    };
})();
