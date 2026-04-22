/**
 * Menu Studio · M025 — edytor układu dań na wspólnej scenie kategorii (The Table).
 * Współrzędne x,y ∈ [0..1] w spec_json.placements (sh_atelier_scenes, scene_kind=category).
 */

(function () {
    const _e = (s) => {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    };

    function defaultPlacement(index, total) {
        if (total <= 0) return { x: 0.5, y: 0.5, scale: 1, z_index: 40 };
        const cols = Math.ceil(Math.sqrt(total));
        const rows = Math.ceil(total / cols);
        const row = Math.floor(index / cols);
        const col = index % cols;
        return {
            x: ((col + 0.5) / cols) * 0.75 + 0.125,
            y: ((row + 0.5) / rows) * 0.65 + 0.15,
            scale: 1,
            z_index: 30 + index,
        };
    }

    window.CategoryTableEditor = {
        async open(categoryId) {
            try {
                const r = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', {
                    action: 'get_category_scene_editor',
                    categoryId,
                });
                if (!r.success) {
                    alert(r.message || 'Błąd pobierania sceny kategorii.');
                    return;
                }
                this._renderOverlay(r.data, categoryId);
            } catch (err) {
                console.error('[CategoryTableEditor]', err);
                alert('Błąd sieci / API.');
            }
        },

        _renderOverlay(data, categoryId) {
            const existing = document.getElementById('category-table-editor-overlay');
            if (existing) existing.remove();

            const items = data.items || [];
            const templateKey = data.templateKey || 'category_flat_table';
            const catTemplates = (window.StudioState?.sceneTemplates || []).filter((t) => t.kind === 'category');
            let tplOptions = '';
            if (catTemplates.length > 0) {
                const hasCurrent = catTemplates.some((t) => t.asciiKey === templateKey);
                tplOptions = catTemplates
                    .map(
                        (t) =>
                            `<option value="${_e(t.asciiKey)}" ${t.asciiKey === templateKey ? 'selected' : ''}>${_e(t.name)}</option>`
                    )
                    .join('');
                if (!hasCurrent && templateKey) {
                    tplOptions =
                        `<option value="${_e(templateKey)}" selected>${_e(templateKey)} (zapisany)</option>` + tplOptions;
                }
            } else {
                tplOptions = `<option value="category_flat_table" ${templateKey === 'category_flat_table' ? 'selected' : ''}>Stół płaski (category_flat_table)</option>
                       <option value="category_hero_wall" ${templateKey === 'category_hero_wall' ? 'selected' : ''}>Ściana hero (category_hero_wall)</option>`;
            }

            const overlay = document.createElement('div');
            overlay.id = 'category-table-editor-overlay';
            overlay.className =
                'fixed inset-0 z-[10001] flex items-center justify-center bg-black/80 backdrop-blur-md p-4 overflow-y-auto';
            overlay.innerHTML = `
            <div class="bg-[#0c0f1a] border border-white/10 rounded-2xl shadow-2xl w-full max-w-3xl max-h-[95vh] flex flex-col">
                <div class="flex items-start justify-between gap-4 p-6 border-b border-white/10">
                    <div>
                        <h3 class="text-white text-sm font-black uppercase tracking-widest">Układ stołu — kategoria</h3>
                        <p class="text-[10px] text-slate-500 mt-1 font-bold uppercase">${_e(data.categoryName || '')} · ${ _e(data.layoutMode || '') }</p>
                    </div>
                    <button type="button" class="text-slate-500 hover:text-white w-10 h-10 rounded-lg hover:bg-white/10 transition" id="cte-close" aria-label="Zamknij">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="p-6 space-y-4 overflow-y-auto flex-1">
                    <div class="flex flex-col gap-2">
                        <label class="text-[9px] font-black uppercase text-slate-500">Szablon sceny kategorii</label>
                        <select id="cte-template" class="bg-black/50 border border-white/10 text-white rounded-lg p-2.5 text-xs outline-none focus:border-violet-500">${tplOptions}</select>
                    </div>
                    <p class="text-[8px] text-slate-600 leading-relaxed">
                        Przeciągnij karty dań na drewnianym stole. Pozycja zapisuje się jako x/y (środek karty) w zakresie 0–1.
                        Jeśli kategoria nie ma jeszcze rekordu sceny, zostanie utworzony przy pierwszym zapisie.
                    </p>
                    <div id="cte-stage-wrap" class="relative w-full rounded-xl border border-violet-500/20 overflow-hidden shadow-inner bg-gradient-to-br from-amber-900/30 via-stone-900/80 to-stone-950 select-none touch-none" style="aspect-ratio: 16/10;">
                        <div class="absolute inset-0 opacity-25 pointer-events-none" style="background-image: radial-gradient(circle at 20% 30%, rgba(255,255,255,0.08) 0, transparent 40%), radial-gradient(circle at 80% 70%, rgba(0,0,0,0.4) 0, transparent 35%);"></div>
                        <span class="absolute bottom-2 left-3 text-[8px] font-black uppercase text-white/25 tracking-widest pointer-events-none">The Table · podgląd</span>
                        <div id="cte-stage" class="absolute inset-0"></div>
                    </div>
                    <p class="text-[8px] text-slate-600"><span id="cte-count"></span></p>
                </div>
                <div class="flex gap-3 p-6 border-t border-white/10">
                    <button type="button" id="cte-save" class="flex-1 bg-violet-600 hover:bg-violet-500 text-white font-black text-xs uppercase tracking-widest py-3 rounded-lg transition">
                        <i class="fa-solid fa-floppy-disk mr-2"></i> Zapisz układ
                    </button>
                    <button type="button" id="cte-cancel" class="px-6 bg-white/5 border border-white/10 text-slate-400 hover:text-white font-black text-xs uppercase rounded-lg">Anuluj</button>
                </div>
            </div>`;

            document.body.appendChild(overlay);

            const stage = overlay.querySelector('#cte-stage');
            const countEl = overlay.querySelector('#cte-count');
            const tplSel = overlay.querySelector('#cte-template');

            /** @type {Map<string, {el: HTMLElement, x: number, y: number, scale: number, z: number}>} */
            const chips = new Map();

            function syncCount() {
                countEl.textContent = `Pozycje: ${chips.size} · scena ${data.sceneId ? '#' + data.sceneId : '(nowa przy zapisie)'}`;
            }

            items.forEach((it, idx) => {
                let x;
                let y;
                let sc;
                let z;
                if (it.placement && typeof it.placement.x === 'number') {
                    x = it.placement.x;
                    y = it.placement.y;
                    sc = it.placement.scale != null ? it.placement.scale : 1;
                    z = it.placement.z_index != null ? it.placement.z_index : 40;
                } else {
                    const d = defaultPlacement(idx, items.length);
                    x = d.x;
                    y = d.y;
                    sc = d.scale;
                    z = d.z_index;
                }

                const el = document.createElement('div');
                el.className =
                    'absolute flex flex-col items-center justify-center gap-0.5 px-2 py-1.5 rounded-lg border border-white/20 bg-black/70 shadow-lg cursor-grab active:cursor-grabbing min-w-[72px] max-w-[120px]';
                el.style.left = `${x * 100}%`;
                el.style.top = `${y * 100}%`;
                el.style.transform = 'translate(-50%, -50%)';
                el.style.zIndex = String(z);
                el.dataset.sku = it.sku;
                el.innerHTML = `<span class="text-[8px] font-black text-violet-300 uppercase truncate max-w-[108px]">${_e(it.name)}</span><span class="text-[7px] font-mono text-slate-500">${_e(it.sku)}</span>`;

                let drag = false;
                el.addEventListener('pointerdown', (ev) => {
                    drag = true;
                    el.setPointerCapture(ev.pointerId);
                    el.classList.add('ring-2', 'ring-violet-500');
                });
                el.addEventListener('pointermove', (ev) => {
                    if (!drag) return;
                    const rect = stage.getBoundingClientRect();
                    let nx = (ev.clientX - rect.left) / rect.width;
                    let ny = (ev.clientY - rect.top) / rect.height;
                    nx = Math.max(0.04, Math.min(0.96, nx));
                    ny = Math.max(0.04, Math.min(0.96, ny));
                    el.style.left = `${nx * 100}%`;
                    el.style.top = `${ny * 100}%`;
                    const rec = chips.get(it.sku);
                    if (rec) {
                        rec.x = nx;
                        rec.y = ny;
                    }
                });
                el.addEventListener('pointerup', (ev) => {
                    drag = false;
                    try {
                        el.releasePointerCapture(ev.pointerId);
                    } catch (_) {}
                    el.classList.remove('ring-2', 'ring-violet-500');
                });
                el.addEventListener('pointercancel', () => {
                    drag = false;
                    el.classList.remove('ring-2', 'ring-violet-500');
                });

                stage.appendChild(el);
                chips.set(it.sku, { el, x, y, scale: sc, z: z });
            });

            if (items.length === 0) {
                stage.innerHTML =
                    '<div class="absolute inset-0 flex items-center justify-center text-[10px] text-slate-500 font-bold uppercase">Brak aktywnych dań w tej kategorii</div>';
            }

            syncCount();

            const close = () => overlay.remove();
            overlay.querySelector('#cte-close').onclick = close;
            overlay.querySelector('#cte-cancel').onclick = close;
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) close();
            });

            overlay.querySelector('#cte-save').onclick = async () => {
                const btn = overlay.querySelector('#cte-save');
                btn.disabled = true;
                const placements = [];
                chips.forEach((rec, sku) => {
                    placements.push({
                        sku,
                        x: rec.x,
                        y: rec.y,
                        scale: rec.scale,
                        z_index: rec.z,
                    });
                });
                try {
                    const r = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', {
                        action: 'save_category_scene_layout',
                        categoryId,
                        templateKey: tplSel.value || templateKey,
                        placements,
                    });
                    if (r.success) {
                        if (typeof window.loadMenuTree === 'function') {
                            await window.loadMenuTree();
                            if (window.Core && typeof window.Core.renderTree === 'function') window.Core.renderTree();
                        }
                        close();
                    } else {
                        alert(r.message || 'Błąd zapisu.');
                    }
                } catch (err) {
                    console.error(err);
                    alert('Błąd sieci.');
                } finally {
                    btn.disabled = false;
                }
            };
        },
    };
})();
