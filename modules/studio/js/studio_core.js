window.StudioState = { items: [], categories: [], menuTree: [], bulkSelectedItems: [], sceneTemplates: null };

window.apiStudio = async function(action, payload = {}) {
    payload.action = action;
    const ep = (window.SliceHub && window.SliceHub.apiUrl)
        ? window.SliceHub.apiUrl('/backoffice/api_menu_studio.php')
        : '../../api/backoffice/api_menu_studio.php';
    return await window.ApiClient.post(ep, payload);
};

window.loadMenuTree = async function() {
    const res = await window.apiStudio('get_menu_tree');
    if (res.success === true && res.data) {
        window.StudioState.categories = res.data.categories || [];
        window.StudioState.items = res.data.items || [];
        window.StudioState.menuTree = (res.data.categories || []).map(cat => ({
            ...cat,
            items: (res.data.items || []).filter(it => it.categoryId === cat.id),
        }));
        window.StudioState.modifierGroups = res.data.modifierGroups || window.StudioState.modifierGroups || [];
        return res.data;
    }
    console.error('Błąd API:', res.message);
    return null;
};

/** Zgrupowane pozycje menu (menuTree lub fallback z flat items). */
window.StudioState.getItemsGrouped = function() {
    if (this.menuTree && this.menuTree.length) return this.menuTree;
    return (this.categories || []).map(cat => ({
        ...cat,
        items: (this.items || []).filter(it => it.categoryId === cat.id),
    }));
};

// Dodana funkcja ładująca szczegóły, w pełni zintegrowana z camelCase z backendu
window.loadItemDetails = async function(itemId) {
    const res = await window.apiStudio('get_item_details', { itemId: itemId });
    if (res.success === true && res.data) {
        return res.data;
    }
    console.error('Błąd API:', res.message);
    return null;
};

// M022: lista scene templates (cache per-session w window.StudioState.sceneTemplates)
// Zwraca [{asciiKey, name, kind, photographerBrief, isSystem}]
// Kind: 'item' | 'category' | null (wszystko)
window.loadSceneTemplates = async function(kind = null, force = false) {
    if (!force && window.StudioState.sceneTemplates) {
        return kind
            ? window.StudioState.sceneTemplates.filter(t => t.kind === kind)
            : window.StudioState.sceneTemplates;
    }
    const payload = kind ? { kind } : {};
    const res = await window.apiStudio('list_scene_templates', payload);
    if (res.success === true && res.data) {
        // Gdy pobieramy wszystko — cache'uj. Gdy filtrujemy po kind — nie nadpisuj full cache.
        if (!kind) {
            window.StudioState.sceneTemplates = res.data.templates || [];
        }
        return res.data.templates || [];
    }
    return [];
};