window.StudioState = { items: [], categories: [], bulkSelectedItems: [] };

window.apiStudio = async function(action, payload = {}) {
    payload.action = action;
    
    // Symulacja tokena autoryzacyjnego
    const authToken = 'mock_jwt_token_123';

    try {
        const response = await fetch('../../api/backoffice/api_menu_studio.php', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json', 
                'Accept': 'application/json',
                'Authorization': `Bearer ${authToken}`
            },
            body: JSON.stringify(payload)
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        
        return await response.json();
    } catch (e) { 
        return { 
            status: 'error', 
            payload: null, 
            message: 'Błąd sieci lub krytyczny błąd serwera.' 
        }; 
    }
};

window.loadMenuTree = async function() {
    const res = await window.apiStudio('get_menu_tree');
    if (res.status === 'success' && res.payload) {
        window.StudioState.categories = res.payload.categories || [];
        window.StudioState.items = res.payload.items || [];
        return res.payload;
    }
    console.error('Błąd API:', res.message);
    return null;
};

// Dodana funkcja ładująca szczegóły, w pełni zintegrowana z camelCase z backendu
window.loadItemDetails = async function(itemId) {
    const res = await window.apiStudio('get_item_details', { itemId: itemId });
    if (res.status === 'success' && res.payload) {
        return res.payload.variants || [];
    }
    console.error('Błąd API:', res.message);
    return null;
};