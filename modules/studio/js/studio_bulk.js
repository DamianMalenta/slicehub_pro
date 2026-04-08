window.BulkEditor = {
    init: function() {
        const btnApplyBulk = document.getElementById('btn-apply-bulk');
        if (btnApplyBulk) {
            btnApplyBulk.addEventListener('click', () => this.executeBulkUpdate());
        }
    },
    
    async executeBulkUpdate() {
        if (!window.StudioState || !window.StudioState.bulkSelectedItems || window.StudioState.bulkSelectedItems.length === 0) {
            console.warn('Brak zaznaczonych elementów do edycji masowej.');
            return;
        }

        const payload = {
            itemIds: window.StudioState.bulkSelectedItems,
            vatId: document.getElementById('bulk-vat')?.value || '',
            printerGroup: document.getElementById('bulk-printer')?.value || '',
            badgeType: document.getElementById('bulk-badge')?.value || '',
            isSecret: document.getElementById('bulk-secret')?.value || '',
            isActive: document.getElementById('bulk-active')?.value || '',
            priceAction: document.getElementById('bulk-price-action')?.value || '',
            priceValue: document.getElementById('bulk-price-value')?.value || ''
        };
        
        const response = await window.apiStudio('save_bulk', payload);
        
        if (response.status === 'success') {
            console.log(response.message);
            if (typeof window.loadMenuTree === 'function') {
                await window.loadMenuTree();
            }
        } else {
            console.error(response.message);
        }
    }
};

document.addEventListener('DOMContentLoaded', () => {
    window.BulkEditor.init();
});