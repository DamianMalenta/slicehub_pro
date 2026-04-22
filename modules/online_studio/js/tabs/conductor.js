/**
 * Online Studio — STYLE CONDUCTOR tab
 *
 * Prawo VII: to NIE grid kategorii z dropdownem "styl". To magiczna konsola
 * dyrygenta — widzi cały teatr (wszystkie kategorie + ich Harmony Score),
 * widzi styl aktywny per kategoria, wybiera preset z galerii cinematic →
 * JEDEN KLIK i cała kategoria / całe menu zmienia tożsamość wizualną.
 *
 * Łączy:
 *   • G1 — `category_style_apply` (backend bulk per kategoria).
 *   • G2 — `menu_style_apply` (backend bulk globalny).
 *   • G4 — wyświetla `scene_harmony_get` agregat (score per dish w tabeli).
 *
 * UI layout:
 *   ┌─ LEFT: Style Gallery (12 cinematic presets z m022 z miniaturą palety)
 *   ├─ CENTER: Tabela kategorii (nazwa, items, aktualny styl, avg Harmony, akcje)
 *   └─ RIGHT: Global actions (Apply to entire menu, Auto-harmonize all, History)
 */

export function mountConductor(root, Studio, Api) {
    const state = {
        presets: [],
        selectedPresetId: null,
        categoryStyles: [], // [{ categoryId, presetKey, ... }]
        harmonyMap: new Map(), // sku → score
        categoryAgg: [], // [{ categoryId, name, itemCount, avgHarmony, currentStyle }]
        loading: false,
    };

    root.innerHTML = `
        <div class="conductor">
            <aside class="conductor__gallery">
                <div class="conductor__panel-head">
                    <i class="fa-solid fa-film"></i>
                    <span>Biblioteka stylów</span>
                </div>
                <div class="conductor__gallery-hint">
                    Wybierz styl → kliknij „Zastosuj" przy kategorii lub „Cały teatr".
                </div>
                <div id="cnd-gallery" class="conductor__gallery-grid"></div>
            </aside>

            <main class="conductor__main">
                <div class="conductor__main-head">
                    <div>
                        <h2 class="conductor__title"><i class="fa-solid fa-wand-magic-sparkles"></i> Style Conductor</h2>
                        <p class="conductor__subtitle">Dyrygujesz całym teatrem jednym gestem. Każda kategoria ma własną tożsamość — a całe menu jeden wspólny styl.</p>
                    </div>
                    <div class="conductor__global-actions">
                        <button id="cnd-apply-all" class="btn btn--accent" disabled>
                            <i class="fa-solid fa-globe"></i> Zastosuj do całego menu
                        </button>
                    </div>
                </div>

                <div class="conductor__table-head">
                    <div class="conductor__col-name">Kategoria</div>
                    <div class="conductor__col-items">Dania</div>
                    <div class="conductor__col-style">Aktualny styl</div>
                    <div class="conductor__col-harmony">Harmony</div>
                    <div class="conductor__col-actions">Akcja</div>
                </div>
                <div id="cnd-table" class="conductor__table"></div>

                <div class="conductor__legend">
                    <span class="conductor__legend-item conductor__legend-item--kino"><i class="fa-solid fa-crown"></i> 90+ kinowa</span>
                    <span class="conductor__legend-item conductor__legend-item--ok"><i class="fa-solid fa-check"></i> 70+ dobra</span>
                    <span class="conductor__legend-item conductor__legend-item--warn"><i class="fa-solid fa-triangle-exclamation"></i> 50+ do poprawki</span>
                    <span class="conductor__legend-item conductor__legend-item--block"><i class="fa-solid fa-xmark"></i> &lt;50 blokada</span>
                </div>
            </main>
        </div>
    `;

    const galEl = root.querySelector('#cnd-gallery');
    const tblEl = root.querySelector('#cnd-table');
    const btnAll = root.querySelector('#cnd-apply-all');

    btnAll.onclick = () => onApplyToEntireMenu();

    function renderGallery() {
        galEl.innerHTML = '';
        if (!state.presets.length) {
            galEl.innerHTML = '<div class="conductor__gallery-empty">Brak stylów. Uruchom migrację m022.</div>';
            return;
        }
        state.presets.forEach(p => {
            const palette = p.palette || {};
            const card = document.createElement('button');
            card.className = 'conductor__preset-card' +
                (p.id === state.selectedPresetId ? ' is-selected' : '');
            card.dataset.id = p.id;
            card.innerHTML = `
                <div class="conductor__preset-swatch" style="
                    background: linear-gradient(135deg, ${palette.bg || '#0a0a0a'} 0%, ${palette.primary || '#d97706'} 50%, ${palette.accent || '#fbbf24'} 100%);
                    color: ${palette.text || '#fafafa'};
                    font-family: ${p.fontFamily ? `'${p.fontFamily}', sans-serif` : 'inherit'};
                ">
                    <strong>${p.name}</strong>
                    <small>${p.cinemaReference || ''}</small>
                </div>
                <div class="conductor__preset-meta">
                    <span class="conductor__preset-lut">${p.defaultLut || 'none'}</span>
                    <span class="conductor__preset-motion">${p.motionPreset || 'spring'}</span>
                </div>
            `;
            card.onclick = () => selectPreset(p.id);
            galEl.appendChild(card);
        });
    }

    function selectPreset(id) {
        state.selectedPresetId = id;
        renderGallery();
        renderTable();
        btnAll.disabled = !state.selectedPresetId;
    }

    function currentStyleFor(categoryId) {
        return state.categoryStyles.find(cs => cs.categoryId === categoryId);
    }

    function harmonyTier(score) {
        if (score >= 90) return 'kino';
        if (score >= 70) return 'ok';
        if (score >= 50) return 'warn';
        return 'block';
    }

    function renderTable() {
        tblEl.innerHTML = '';
        if (!state.categoryAgg.length) {
            tblEl.innerHTML = '<div class="conductor__table-empty">Brak kategorii. Najpierw zdefiniuj kategorie w Menu Studio.</div>';
            return;
        }
        state.categoryAgg.forEach(cat => {
            const currentStyle = currentStyleFor(cat.categoryId);
            const tier = harmonyTier(cat.avgHarmony);
            const row = document.createElement('div');
            row.className = 'conductor__row';
            row.innerHTML = `
                <div class="conductor__col-name">
                    <strong>${cat.name}</strong>
                </div>
                <div class="conductor__col-items">
                    <span class="conductor__count">${cat.itemCount}</span>
                    <small>${cat.scenesCount} scen</small>
                </div>
                <div class="conductor__col-style">
                    ${currentStyle ? `
                        <span class="conductor__style-pill">
                            <i class="fa-solid fa-circle-check"></i>
                            ${currentStyle.presetName}
                        </span>
                    ` : `<span class="conductor__style-pill conductor__style-pill--none">brak</span>`}
                </div>
                <div class="conductor__col-harmony">
                    ${cat.avgHarmony > 0 ? `
                        <span class="conductor__harmony conductor__harmony--${tier}">
                            ${cat.avgHarmony}
                        </span>
                        <small>${cat.scenesCount}/${cat.itemCount}</small>
                    ` : `<small style="opacity:.5">brak scen</small>`}
                </div>
                <div class="conductor__col-actions">
                    <button class="btn btn--sm btn--accent cnd-apply-cat"
                        data-cat="${cat.categoryId}"
                        ${!state.selectedPresetId || cat.scenesCount === 0 ? 'disabled' : ''}
                        title="${!state.selectedPresetId ? 'Wybierz najpierw styl' : (cat.scenesCount === 0 ? 'Brak scen (użyj Scene Studio)' : 'Zastosuj wybrany styl do tej kategorii')}"
                    >
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        Zastosuj
                    </button>
                </div>
            `;
            tblEl.appendChild(row);
        });
        root.querySelectorAll('.cnd-apply-cat').forEach(b => {
            b.onclick = () => onApplyToCategory(parseInt(b.dataset.cat, 10));
        });
    }

    async function onApplyToCategory(categoryId) {
        if (!state.selectedPresetId) {
            Studio.toast('Wybierz najpierw styl z galerii.', 'warn');
            return;
        }
        const preset = state.presets.find(p => p.id === state.selectedPresetId);
        const cat = state.categoryAgg.find(c => c.categoryId === categoryId);
        if (!preset || !cat) return;

        if (!confirm(`Zastosować styl „${preset.name}" do kategorii „${cat.name}" (${cat.scenesCount} scen)?\n\nOperacja nadpisze aktualny styl.`)) return;

        Studio.toast(`Dyryguję… "${preset.name}" × "${cat.name}"`, 'loading', 4000);
        const r = await Api.categoryStyleApply(categoryId, state.selectedPresetId);
        if (r.success) {
            const d = r.data || {};
            Studio.toast(
                `✨ Zastosowano „${d.presetName}" — ${d.updated} scen zaktualizowanych${d.skipped ? ` (${d.skipped} bez sceny)` : ''}.`,
                'ok',
                4200
            );
            await reload();
        } else {
            Studio.toast('Błąd: ' + (r.message || 'nieznany'), 'err');
        }
    }

    async function onApplyToEntireMenu() {
        if (!state.selectedPresetId) return;
        const preset = state.presets.find(p => p.id === state.selectedPresetId);
        const totalScenes = state.categoryAgg.reduce((a, c) => a + c.scenesCount, 0);
        if (!preset) return;

        if (!confirm(
            `UWAGA: Chcesz zastosować „${preset.name}" do WSZYSTKICH ${totalScenes} scen w menu?\n\n` +
            `To gest dyrygenta — cały teatr zmieni tożsamość wizualną.\n` +
            `Operacja nadpisze aktualne style wszystkich kategorii.`
        )) return;

        Studio.toast(`Dyryguję całym teatrem… "${preset.name}"`, 'loading', 8000);
        const r = await Api.menuStyleApply(state.selectedPresetId);
        if (r.success) {
            const d = r.data || {};
            Studio.toast(
                `✨ Cały teatr: "${d.presetName}" — ${d.updated} scen (${d.skipped} pominięto).`,
                'ok',
                5200
            );
            await reload();
        } else {
            Studio.toast('Błąd: ' + (r.message || 'nieznany'), 'err');
        }
    }

    async function reload() {
        state.loading = true;
        const [presetsR, stylesR, menuHarmonyR] = await Promise.all([
            Api.stylePresetsList(),
            Api.categoryStylesList(),
            Api.sceneHarmonyGet('all'),
        ]);
        if (presetsR.success) state.presets = presetsR.data?.presets || [];
        if (stylesR.success)  state.categoryStyles = stylesR.data?.styles || [];

        // Build harmonyMap (itemSku → score)
        state.harmonyMap.clear();
        if (menuHarmonyR.success && Array.isArray(menuHarmonyR.data?.metrics)) {
            menuHarmonyR.data.metrics.forEach(m => {
                if (m.itemSku) state.harmonyMap.set(m.itemSku, m.harmonyScore);
            });
        }

        // Aggregate per category from Studio.menu
        const items = Studio.menu?.items || [];
        const byCat = new Map();
        items.forEach(it => {
            const cid = it.categoryId || 0;
            if (!byCat.has(cid)) byCat.set(cid, {
                categoryId: cid,
                name: it.categoryName || 'Inne',
                itemCount: 0,
                scenesCount: 0,
                harmonySum: 0,
            });
            const b = byCat.get(cid);
            b.itemCount++;
            const score = state.harmonyMap.get(it.sku);
            if (typeof score === 'number' && score > 0) {
                b.scenesCount++;
                b.harmonySum += score;
            }
        });
        state.categoryAgg = [...byCat.values()]
            .map(b => ({
                ...b,
                avgHarmony: b.scenesCount > 0 ? Math.round(b.harmonySum / b.scenesCount) : 0,
            }))
            .sort((a, b) => a.name.localeCompare(b.name, 'pl'));

        state.loading = false;
        renderGallery();
        renderTable();
        btnAll.disabled = !state.selectedPresetId || state.categoryAgg.length === 0;
    }

    return {
        onEnter: async () => {
            if (!Studio.menu) await Studio.refreshMenu?.();
            await reload();
        },
    };
}
