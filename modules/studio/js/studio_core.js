globalThis.StudioState = {
    items: [], categories: [], menuTree: [], bulkSelectedItems: [], sceneTemplates: null,
    selectedItemId: null, mode: 'dashboard', currentView: 'menu', isDirty: false,
    markDirty() { this.isDirty = true; this._updateSaveIndicator(); },
    markClean() { this.isDirty = false; this._updateSaveIndicator(); },
    _updateSaveIndicator() {
        const dot = document.getElementById('save-indicator-dot');
        if (dot) dot.classList.toggle('bg-amber-400', this.isDirty);
    },
    routeToMode() {
        if (this.currentView === 'bulk' && this.bulkSelectedItems.length > 1) { this.mode = 'bulk'; return this.mode; }
        if (this.currentView !== 'menu') { this.mode = 'dashboard'; return this.mode; }
        if (this.bulkSelectedItems.length > 1) this.mode = 'bulk';
        else if (this.selectedItemId !== null) this.mode = 'editor';
        else this.mode = 'dashboard';
        return this.mode;
    }
};

globalThis.StudioToast = {
    show(msg, type = 'info', duration = 3000) {
        const colors = { success: 'bg-emerald-600', error: 'bg-red-600', warning: 'bg-amber-600', info: 'bg-blue-600' };
        const toast = document.createElement('div');
        toast.className = `fixed bottom-6 right-6 z-[500] ${colors[type] || colors.info} text-white px-4 py-3 rounded-lg shadow-lg text-sm font-bold`;
        toast.style.transition = 'transform 200ms ease-out';
        toast.style.transform = 'translateY(100%)';
        toast.textContent = msg;
        document.body.appendChild(toast);
        requestAnimationFrame(() => { toast.style.transform = 'translateY(0)'; });
        setTimeout(() => { toast.style.transform = 'translateY(100%)'; setTimeout(() => toast.remove(), 200); }, duration);
    }
};

globalThis.loadMenuTree = async function() {
    const res = await globalThis.apiStudio('get_menu_tree');
    if (res.success === true && res.data) {
        globalThis.StudioState.categories = res.data.categories || [];
        globalThis.StudioState.items = res.data.items || [];
        globalThis.StudioState.menuTree = (res.data.categories || []).map(cat => ({
            ...cat,
            items: (res.data.items || []).filter(it => it.categoryId === cat.id),
        }));
        globalThis.StudioState.modifierGroups = res.data.modifierGroups || globalThis.StudioState.modifierGroups || [];
        return res.data;
    }
    console.error('Błąd API:', res.message);
    return null;
};

/** Zgrupowane pozycje menu (menuTree lub fallback z flat items). */
globalThis.StudioState.getItemsGrouped = function() {
    if (this.menuTree && this.menuTree.length) return this.menuTree;
    return (this.categories || []).map(cat => ({
        ...cat,
        items: (this.items || []).filter(it => it.categoryId === cat.id),
    }));
};

// Dodana funkcja ładująca szczegóły, w pełni zintegrowana z camelCase z backendu
globalThis.loadItemDetails = async function(itemId) {
    const res = await globalThis.apiStudio('get_item_details', { itemId: itemId });
    if (res.success === true && res.data) {
        return res.data;
    }
    console.error('Błąd API:', res.message);
    return null;
};

// M022: lista scene templates (cache per-session w globalThis.StudioState.sceneTemplates)
// Zwraca [{asciiKey, name, kind, photographerBrief, isSystem}]
// Kind: 'item' | 'category' | null (wszystko)
globalThis.loadSceneTemplates = async function(kind = null, force = false) {
    if (!force && globalThis.StudioState.sceneTemplates) {
        return kind
            ? globalThis.StudioState.sceneTemplates.filter(t => t.kind === kind)
            : globalThis.StudioState.sceneTemplates;
    }
    const payload = kind ? { kind } : {};
    const res = await globalThis.apiStudio('list_scene_templates', payload);
    if (res.success === true && res.data) {
        // Gdy pobieramy wszystko — cache'uj. Gdy filtrujemy po kind — nie nadpisuj full cache.
        if (!kind) {
            globalThis.StudioState.sceneTemplates = res.data.templates || [];
        }
        return res.data.templates || [];
    }
    return [];
};

// ── Faza 5: Keyboard Shortcuts ──────────────────────────────────────
(function () {
    document.addEventListener('keydown', function (e) {
        // Don't intercept when typing in inputs/textareas (except Ctrl+S and Esc)
        const tag = (e.target.tagName || '').toUpperCase();
        const isTyping = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target.isContentEditable;

        // Ctrl+S — always works (even in inputs)
        if (e.ctrlKey && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            if (globalThis.StudioState.mode === 'editor' && globalThis.ItemEditor && typeof globalThis.ItemEditor.saveItem === 'function') {
                globalThis.ItemEditor.saveItem();
            }
            return;
        }

        // Esc — close drawer or go back to dashboard
        if (e.key === 'Escape') {
            if (globalThis.ModifierInspector && document.getElementById('modifiers-drawer')?.classList.contains('open')) {
                globalThis.ModifierInspector.closeDrawer();
                return;
            }
            // Close any open modal overlay
            const modal = document.getElementById('category-modal-overlay');
            if (modal) { modal.remove(); return; }
            // If in editor, go back to dashboard
            if (globalThis.StudioState.mode === 'editor' || globalThis.StudioState.mode === 'bulk') {
                if (globalThis.StudioState.isDirty) {
                    if (!confirm('Nie zapisano zmian. Kontynuować?')) return;
                    globalThis.StudioState.markClean();
                }
                globalThis.StudioState.selectedItemId = null;
                globalThis.StudioState.bulkSelectedItems = [];
                globalThis.Core.switchView('menu');
                globalThis.Core.updateInspector();
                globalThis.Core.renderTree();
            }
            return;
        }

        // Ctrl+D — duplicate current item
        if (e.ctrlKey && (e.key === 'd' || e.key === 'D')) {
            e.preventDefault();
            if (globalThis.StudioState.mode === 'editor' && globalThis.StudioState.selectedItemId) {
                if (globalThis.ItemEditor && typeof globalThis.ItemEditor.duplicateItem === 'function') {
                    globalThis.ItemEditor.duplicateItem();
                }
            }
            return;
        }

        // Ctrl+B — toggle bulk mode
        if (e.ctrlKey && (e.key === 'b' || e.key === 'B')) {
            e.preventDefault();
            if (globalThis.StudioState.bulkSelectedItems.length > 0) {
                globalThis.StudioState.bulkSelectedItems = [];
                globalThis.Core.switchView('menu');
            } else {
                globalThis.Core.switchView('bulk');
            }
            globalThis.Core.renderTree();
            globalThis.Core.updateInspector();
            return;
        }

        // Arrow keys + Enter — tree navigation (only when not typing)
        if (isTyping) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (globalThis.Core && typeof globalThis.Core.navigateTree === 'function') globalThis.Core.navigateTree(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (globalThis.Core && typeof globalThis.Core.navigateTree === 'function') globalThis.Core.navigateTree(-1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (globalThis.Core && typeof globalThis.Core.openSelectedItem === 'function') globalThis.Core.openSelectedItem();
        }
    });
})();