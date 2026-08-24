globalThis.BulkEditor = {
    init: function() {
    },

    async executeBulkUpdate() {
        if (!globalThis.StudioState || !globalThis.StudioState.bulkSelectedItems || globalThis.StudioState.bulkSelectedItems.length === 0) {
            if (globalThis.StudioToast) globalThis.StudioToast.show('Zaznacz przynajmniej jedno danie w drzewie!', 'warning');
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
            itemIds: globalThis.StudioState.bulkSelectedItems,
            kdsGroup: document.getElementById('bulk-printer')?.value || '',
            badgeType: document.getElementById('bulk-badge')?.value || '',
            isSecret: document.getElementById('bulk-secret')?.value || '',
            // F-S4 (2026-05-11): VAT bulk update — drift naprawiony.
            // Jeden select #bulk-vat aplikuje stawkę do OBU pól (dine_in + takeaway).
            // Puste '' = brak zmiany (backend ignoruje).
            vatRateDineIn: document.getElementById('bulk-vat')?.value || '',
            vatRateTakeaway: document.getElementById('bulk-vat')?.value || '',
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
            const result = await globalThis.StudioApi.postPayload(payload);
            
            if (result.success === true) {
                if (globalThis.StudioToast) globalThis.StudioToast.show(result.message || 'Masowo zaktualizowano!', 'success');
                
                // Resetujemy zaznaczenia i UI po udanej operacji
                globalThis.StudioState.bulkSelectedItems = [];
                if (typeof globalThis.loadMenuTree === 'function') await globalThis.loadMenuTree();
                if (globalThis.Core && typeof globalThis.Core.renderTree === 'function') globalThis.Core.renderTree();
                
                const bulkView = document.getElementById('bulk-inspector-view');
                if (bulkView) {
                    const spans = bulkView.querySelectorAll('span, div');
                    spans.forEach(el => {
                        if (el.innerText.includes('ZAZNACZONO:')) el.innerText = 'ZAZNACZONO: 0 DAŃ';
                    });
                }
            } else {
                if (globalThis.StudioToast) globalThis.StudioToast.show('Błąd: ' + result.message, 'error');
            }
        } catch (error) {
            if (globalThis.StudioToast) globalThis.StudioToast.show('Krytyczny błąd sieci podczas masowego zapisu.', 'error');
            console.error(error);
        }
    }
};

document.addEventListener('DOMContentLoaded', () => {
    globalThis.BulkEditor.init();
});