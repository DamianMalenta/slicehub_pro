window.BulkEditor = {
    init: function() {
    },

    async executeBulkUpdate() {
        if (!window.StudioState || !window.StudioState.bulkSelectedItems || window.StudioState.bulkSelectedItems.length === 0) {
            alert('Błąd: Zaznacz przynajmniej jedno danie z listy po lewej stronie!');
            return;
        }

        const publicationStatus = document.getElementById('bulk-publication-status')?.value || 'NO_CHANGE';
        const validFrom = document.getElementById('bulk-valid-from')?.value || '';
        const validTo = document.getElementById('bulk-valid-to')?.value || '';
        const channelTarget = document.getElementById('bulk-channel-target')?.value || 'POS';
        const priceOperation = document.getElementById('bulk-omni-price-op')?.value || '';
        const priceOperationValueRaw = document.getElementById('bulk-omni-price-value')?.value;
        const priceOperationValue = priceOperationValueRaw !== '' && priceOperationValueRaw !== undefined
            ? parseFloat(priceOperationValueRaw)
            : null;

        const shouldApplyPublication = publicationStatus !== 'NO_CHANGE' || !!validFrom || !!validTo;
        const shouldApplyPriceUpdate = priceOperation !== '' && priceOperationValue !== null && !Number.isNaN(priceOperationValue);

        // POTĘŻNY PAYLOAD ZGODNY Z NOWYM BACKENDEM
        const payload = {
            action: 'save_bulk', // TEGO BRAKOWAŁO!
            itemIds: window.StudioState.bulkSelectedItems,
            kdsGroup: document.getElementById('bulk-printer')?.value || '', // Zmiana z printerGroup na kdsGroup
            badgeType: document.getElementById('bulk-badge')?.value || '',
            isSecret: document.getElementById('bulk-secret')?.value || '',
            temporalPublicationPatch: {
                apply: shouldApplyPublication,
                status: publicationStatus,
                validFrom: validFrom || null,
                validTo: validTo || null
            },
            omnichannelPricePatch: {
                apply: shouldApplyPriceUpdate,
                targetChannel: channelTarget,
                operationType: priceOperation,
                operationValue: shouldApplyPriceUpdate ? priceOperationValue : null
            }
        };
        
        try {
            const result = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', payload);
            
            if (result.success === true) {
                alert("SUKCES: " + result.message);
                
                // Resetujemy zaznaczenia i UI po udanej operacji
                window.StudioState.bulkSelectedItems = [];
                if (typeof window.loadMenuTree === 'function') await window.loadMenuTree();
                if (window.Core && typeof window.Core.renderTree === 'function') window.Core.renderTree();
                
                const bulkView = document.getElementById('bulk-inspector-view');
                if (bulkView) {
                    const spans = bulkView.querySelectorAll('span, div');
                    spans.forEach(el => {
                        if (el.innerText.includes('ZAZNACZONO:')) el.innerText = 'ZAZNACZONO: 0 DAŃ';
                    });
                }
            } else {
                alert("BŁĄD: " + result.message);
            }
        } catch (error) {
            alert("Krytyczny błąd sieci podczas masowego zapisu.");
            console.error(error);
        }
    }
};

document.addEventListener('DOMContentLoaded', () => {
    window.BulkEditor.init();
});