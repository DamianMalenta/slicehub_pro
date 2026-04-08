window.BulkEditor = {
    init: function() {
        this.mountOmnichannelPanels();

        const btnApplyBulk = document.getElementById('btn-apply-bulk');
        if (btnApplyBulk) btnApplyBulk.addEventListener('click', () => this.executeBulkUpdate());
    },

    mountOmnichannelPanels: function() {
        const bulkView = document.getElementById('bulk-inspector-view');
        if (!bulkView || bulkView.dataset.omnichannelBulkReady === '1') return;

        const oldPriceAction = document.getElementById('bulk-price-action');
        const oldActive = document.getElementById('bulk-active');
        if (oldPriceAction) {
            const oldPriceCard = oldPriceAction.closest('.bg-\\[\\#0a0a0f\\]\\/60');
            if (oldPriceCard) oldPriceCard.remove();
        }
        if (oldActive) {
            const oldActiveWrapper = oldActive.closest('.col-span-2');
            if (oldActiveWrapper) oldActiveWrapper.remove();
        }

        const content = bulkView.querySelector('.p-10.max-w-4xl');
        if (!content) return;

        const publicationPanel = document.createElement('div');
        publicationPanel.className = 'bg-[#0a0a0f]/60 backdrop-blur-md border border-cyan-500/20 p-8 rounded-3xl space-y-6 relative overflow-hidden shadow-[0_0_20px_rgba(6,182,212,0.08)] hover:border-cyan-500/40 transition-colors group';
        publicationPanel.innerHTML = `
            <div class="absolute top-0 left-0 w-1.5 h-full bg-cyan-500 shadow-[0_0_10px_rgba(6,182,212,0.8)]"></div>
            <h3 class="text-[12px] font-black uppercase text-cyan-400 tracking-widest relative z-10"><i class="fa-solid fa-calendar-days mr-3"></i> Panel Harmonogramu Masowego</h3>
            <div class="grid grid-cols-3 gap-4 relative z-10">
                <select id="bulk-publication-status" class="w-full bg-[#050508] border border-white/10 rounded-xl p-5 text-[12px] font-bold text-white outline-none focus:border-cyan-500 transition-all">
                    <option value="NO_CHANGE">Bez Zmian</option>
                    <option value="Draft">Draft</option>
                    <option value="Live">Live</option>
                    <option value="Archived">Archived</option>
                </select>
                <input type="datetime-local" id="bulk-valid-from" class="w-full bg-[#050508] border border-white/10 rounded-xl p-5 text-[12px] font-bold text-white outline-none focus:border-cyan-500 transition-all">
                <input type="datetime-local" id="bulk-valid-to" class="w-full bg-[#050508] border border-white/10 rounded-xl p-5 text-[12px] font-bold text-white outline-none focus:border-cyan-500 transition-all">
            </div>
        `;

        const omnichannelPanel = document.createElement('div');
        omnichannelPanel.className = 'bg-[#0a0a0f]/60 backdrop-blur-md border border-yellow-500/20 p-8 rounded-3xl space-y-6 relative overflow-hidden shadow-[0_0_20px_rgba(234,179,8,0.08)] hover:border-yellow-500/40 transition-colors group';
        omnichannelPanel.innerHTML = `
            <div class="absolute top-0 left-0 w-1.5 h-full bg-yellow-500 shadow-[0_0_10px_rgba(234,179,8,0.8)]"></div>
            <h3 class="text-[12px] font-black uppercase text-yellow-500 tracking-widest relative z-10"><i class="fa-solid fa-layer-group mr-3"></i> Masowy Modyfikator Omnichannel</h3>
            <div class="grid grid-cols-3 gap-4 relative z-10">
                <select id="bulk-channel-target" class="w-full bg-[#050508] border border-white/10 rounded-xl p-5 text-[12px] font-bold text-white outline-none focus:border-yellow-500 transition-all">
                    <option value="POS">POS</option>
                    <option value="Takeaway">Takeaway</option>
                    <option value="Delivery">Delivery</option>
                </select>
                <select id="bulk-omni-price-op" class="w-full bg-[#050508] border border-white/10 rounded-xl p-5 text-[12px] font-bold text-white outline-none focus:border-yellow-500 transition-all">
                    <option value="set_amount">Ustaw sztywną kwotę</option>
                    <option value="increase_percent">Zwiększ o %</option>
                    <option value="increase_pln">Zwiększ o PLN</option>
                </select>
                <input type="number" id="bulk-omni-price-value" step="0.01" placeholder="Wartość operacji" class="w-full bg-[#050508] border border-white/10 rounded-xl p-5 text-[12px] font-black text-white outline-none focus:border-yellow-500 transition-all">
            </div>
        `;

        const firstCard = content.querySelector('.bg-\\[\\#0a0a0f\\]\\/60');
        if (firstCard) {
            firstCard.insertAdjacentElement('afterend', publicationPanel);
            publicationPanel.insertAdjacentElement('afterend', omnichannelPanel);
        } else {
            content.appendChild(publicationPanel);
            content.appendChild(omnichannelPanel);
        }

        bulkView.dataset.omnichannelBulkReady = '1';
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
            // BEZPOŚREDNIE UDERZENIE W API
            const response = await fetch('../../api/backoffice/api_menu_studio.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer mock_jwt_token_123' },
                body: JSON.stringify(payload)
            });
            
            const result = await response.json();
            
            if (result.status === 'success') {
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