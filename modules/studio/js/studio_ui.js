window.Core = {
    switchView: function(viewId) {
        console.log("[UI] Przełączanie na widok:", viewId);
        const container = document.getElementById('view-container');
        if (!container) return;
        Array.from(container.children).forEach(child => {
            if(child.id.includes('-view')) child.classList.add('hidden');
        });
        const targetView = document.getElementById(viewId + '-view');
        const bulkView = document.getElementById('bulk-inspector-view');
        if (viewId === 'bulk' && bulkView) bulkView.classList.remove('hidden');
        else if (targetView) targetView.classList.remove('hidden');
        document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
        const navBtn = document.getElementById('nav-' + viewId);
        if (navBtn) navBtn.classList.add('active');
    },
    renderTree: function() {
        const container = document.getElementById('dynamic-tree-container');
        if (!container) return;
        const categories = window.StudioState?.categories || [];
        const items = window.StudioState?.items || [];
        if (categories.length === 0) {
            container.innerHTML = '<div class="text-center mt-10 text-slate-500 font-bold text-[10px] uppercase">Brak kategorii w bazie.</div>';
            return;
        }
        let html = '<div class="space-y-3">';
        categories.forEach(cat => {
            const catItems = items.filter(item => item.categoryId == cat.id);
            html += `
            <div class="bg-black/40 border border-white/5 rounded-xl overflow-hidden shadow-[0_4px_15px_rgba(0,0,0,0.5)]">
                <div class="p-3 bg-white/5 flex items-center justify-between cursor-pointer hover:bg-white/10 transition" onclick="window.Core.toggleCategory(${cat.id})">
                    <div class="flex items-center gap-2">
                        <i id="icon-cat-${cat.id}" class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition-transform"></i>
                        <span class="text-[11px] font-black uppercase text-white tracking-wider">${cat.name}</span>
                    </div>
                    <span class="text-[9px] font-bold text-slate-500 bg-black/50 px-2 py-0.5 rounded border border-white/5">${catItems.length} dań</span>
                </div>
                <div id="cat-items-${cat.id}" class="flex flex-col border-t border-white/5 transition-all">
            `;
            if (catItems.length === 0) {
                html += `<div class="p-3 text-[10px] text-slate-600 italic pl-8">Kategoria jest pusta</div>`;
            } else {
                catItems.forEach(item => {
                    const statusIcon = item.isActive 
                        ? '<i class="fa-solid fa-circle-check text-green-500 text-[10px]" title="Aktywne"></i>' 
                        : '<i class="fa-solid fa-eye-slash text-red-500 text-[10px]" title="Ukryte na POS"></i>';
                    html += `
                    <div class="p-3 pl-8 flex items-center justify-between border-b border-white/5 last:border-0 hover:bg-blue-500/10 cursor-pointer transition group" onclick="window.Core.openItemEditor(${item.id}, '${item.asciiKey}')">
                        <div class="flex items-center gap-3">
                            <i class="fa-solid fa-grip-vertical text-slate-600 text-[10px] opacity-0 group-hover:opacity-100 transition-opacity"></i>
                            <span class="text-[11px] font-bold text-slate-300 group-hover:text-blue-400 transition-colors">${item.name}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-[9px] text-slate-600 font-mono hidden group-hover:block transition-all">${item.asciiKey}</span>
                            ${statusIcon}
                        </div>
                    </div>
                    `;
                });
            }
            html += `</div></div>`;
        });
        html += '</div>';
        container.innerHTML = html;
    },
    toggleCategory: function(catId) {
        const container = document.getElementById(`cat-items-${catId}`);
        const icon = document.getElementById(`icon-cat-${catId}`);
        if (container) { container.classList.toggle('hidden'); if (icon) icon.classList.toggle('-rotate-90'); }
    },
    openItemEditor: async function(itemId, asciiKey) {
        console.log("[UI] Wybrano danie SKU:", asciiKey);
        window.Core.switchView('menu');
        
        // 1. Zasilenie listy kategorii w select (żeby menedżer miał z czego wybierać)
        const catSelect = document.getElementById('item-category-id');
        if (catSelect && catSelect.options.length <= 1) {
            const categories = window.StudioState?.categories || [];
            categories.forEach(cat => {
                catSelect.add(new Option(cat.name, cat.id));
            });
        }

        // 2. Uderzenie do bazy po PEŁNE dane księgowe (Cena, VAT, Drukarka)
        try {
            const response = await fetch('../../api/backoffice/api_menu_studio.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer mock_jwt_token_123' },
                body: JSON.stringify({ action: 'get_item_details', itemId: itemId })
            });
            const result = await response.json();
            
            if (result.status === 'success' && window.ItemEditor) {
                // Wstrzykujemy twarde dane z bazy do formularza po lewej stronie
                window.ItemEditor.loadItemDataToForm(result.payload);
            } else {
                console.error("[UI] Błąd pobierania detali dania:", result.message);
            }
        } catch (e) { 
            console.error("[UI] Błąd komunikacji z API przy pobieraniu dania:", e); 
        }

        // 3. Zasilenie Receptur i aktualizacja nagłówka dla prawej kolumny
        const skuDisplay = document.getElementById('current-sku-display');
        if(skuDisplay) skuDisplay.innerText = "SKU: " + asciiKey;

        if(typeof window.RecipeMapper !== 'undefined') {
            window.RecipeMapper.loadItemRecipe(asciiKey);
        }
    },
    addCategory: function() {
        console.log("[UI] Kliknięto dodaj kategorię");
        alert("Otwieram panel tworzenia nowej kategorii...");
    }
};

document.addEventListener('DOMContentLoaded', async () => {
    const treeContainer = document.getElementById('dynamic-tree-container');
    if (typeof window.loadMenuTree !== 'function') {
        if(treeContainer) treeContainer.innerHTML = '<div class="text-center mt-10 text-red-500 font-bold text-[10px]">Błąd Krytyczny: Brak pliku Mózgu (studio_core.js)</div>';
        return;
    }
    const data = await window.loadMenuTree();
    if (data) window.Core.renderTree();
    else if(treeContainer) treeContainer.innerHTML = '<div class="text-center mt-10 text-red-500 font-bold text-[10px]">Błąd pobierania danych z API. Sprawdź konsolę.</div>';
});