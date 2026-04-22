window.StudioState = { items: [], categories: [], bulkSelectedItems: [] };

window.apiStudio = async function(action, payload = {}) {
    payload.action = action;
    return await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', payload);
};

window.loadMenuTree = async function() {
    const res = await window.apiStudio('get_menu_tree');
    if (res.success === true && res.data) {
        window.StudioState.categories = res.data.categories || [];
        window.StudioState.items = res.data.items || [];
        return res.data;
    }
    console.error('Błąd API:', res.message);
    return null;
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