window.ItemEditor = {
    toAutoSlug: function(val) {
        const map = {
            'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ź':'z','ż':'z',
            'Ą':'A','Ć':'C','Ę':'E','Ł':'L','Ń':'N','Ó':'O','Ś':'S','Ź':'Z','Ż':'Z',
            ' ':'_'
        };
        return (val||'').split('').map(c => map[c]||c).join('').replace(/[^a-zA-Z0-9_]/g, '').toUpperCase();
    },

    ensureOmnichannelForm: function() {
        const form = document.getElementById('item-form');
        if (!form || form.dataset.omnichannelReady === '1') return;

        form.innerHTML = `
            <input type="hidden" id="item-id" value="0">
            
            <div class="grid grid-cols-2 gap-4">
                <div id="lock-item-name-wrapper" class="flex flex-col gap-1.5 franchise-lockable">
                    <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Nazwa Dania</label>
                    <input type="text" id="item-name" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition" placeholder="np. Pizza Margherita">
                </div>
                <div id="lock-item-ascii-wrapper" class="flex flex-col gap-1.5 franchise-lockable">
                    <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">SKU (Klucz systemowy)</label>
                    <input type="text" id="item-ascii-key" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition font-mono" placeholder="np. PIZZA_MARG">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 mt-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Kategoria</label>
                    <select id="item-category-id" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition cursor-pointer">
                        <option value="0">Wybierz kategorię...</option>
                    </select>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Stawka VAT</label>
                    <select id="item-vat-rate" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition cursor-pointer">
                        <option value="23">23%</option>
                        <option value="8">8%</option>
                        <option value="5">5%</option>
                        <option value="0">0%</option>
                    </select>
                </div>
            </div>

            <div class="mt-4">
                <div class="mb-3 p-3 bg-yellow-900/10 border border-yellow-500/30 rounded-xl">
                    <label class="text-[10px] text-yellow-500 font-bold uppercase tracking-wider block mb-1"><i class="fa-solid fa-bolt mr-1"></i> Szybka Cena (Wypełnia macierz)</label>
                    <input type="number" step="0.01" class="bg-black/50 border border-yellow-500/30 text-yellow-400 rounded p-2 text-sm w-full outline-none focus:border-yellow-400" placeholder="Wpisz cenę i patrz na magię..." oninput="document.getElementById('item-price-pos').value = this.value; document.getElementById('item-price-takeaway').value = this.value; document.getElementById('item-price-delivery').value = this.value;">
                </div>

                <div class="flex flex-col gap-2 border border-blue-500/20 bg-blue-900/10 rounded-xl p-4">
                    <label class="text-[10px] text-blue-300 font-bold uppercase tracking-wider">Macierz Cenowa (Omnichannel)</label>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="flex flex-col gap-1">
                            <span class="text-[9px] text-slate-400 font-bold uppercase">POS</span>
                            <input type="number" id="item-price-pos" step="0.01" min="0" value="0.00" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition">
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="text-[9px] text-slate-400 font-bold uppercase">Takeaway</span>
                            <input type="number" id="item-price-takeaway" step="0.01" min="0" value="0.00" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition">
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="text-[9px] text-slate-400 font-bold uppercase">Delivery</span>
                            <input type="number" id="item-price-delivery" step="0.01" min="0" value="0.00" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition">
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 mt-4">
                <div id="lock-item-kds-wrapper" class="flex flex-col gap-1.5 franchise-lockable">
                    <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">KDS Routing Station</label>
                    <select id="item-kds-station-id" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition cursor-pointer">
                        <option value="KITCHEN_1">Kuchnia Główna (KITCHEN_1)</option>
                        <option value="BAR">Bar (BAR)</option>
                        <option value="NONE">Brak (Nie drukuj)</option>
                    </select>
                </div>
            </div>

            <div id="item-publication-panel" class="grid grid-cols-1 gap-3 border border-white/10 bg-black/30 rounded-xl p-4 mt-4">
                <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Panel Publikacji</label>
                <select id="item-publication-status" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition cursor-pointer">
                    <option value="Draft">Draft</option>
                    <option value="Live">Live</option>
                    <option value="Archived">Archived</option>
                </select>
                <div class="grid grid-cols-2 gap-3">
                    <div class="flex flex-col gap-1">
                        <span class="text-[9px] text-slate-500 font-bold uppercase">validFrom</span>
                        <input type="datetime-local" id="item-valid-from" class="bg-black/50 border border-white/10 text-white rounded p-2.5 text-xs focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition">
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[9px] text-slate-500 font-bold uppercase">validTo</span>
                        <input type="datetime-local" id="item-valid-to" class="bg-black/50 border border-white/10 text-white rounded p-2.5 text-xs focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 border border-white/10 bg-black/30 rounded-xl p-4 mt-4">
                <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Prezentacja Wizualna</label>
                <div id="lock-item-description-wrapper" class="flex flex-col gap-1 franchise-lockable">
                    <span class="text-[9px] text-slate-500 font-bold uppercase">Opis Dania (Menu/Kiosk)</span>
                    <textarea id="item-description" rows="4" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition resize-none" placeholder="Opis widoczny na menu/kiosku"></textarea>
                </div>
                <div id="lock-item-image-url-wrapper" class="flex flex-col gap-1 franchise-lockable">
                    <span class="text-[9px] text-slate-500 font-bold uppercase">URL Zdjęcia (Media)</span>
                    <input type="text" id="item-image-url" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition" placeholder="https://cdn.twojadomena.pl/menu/pizza.jpg">
                </div>
            </div>

            <div id="lock-item-tags-wrapper" class="grid grid-cols-1 gap-2 border border-white/10 bg-black/30 rounded-xl p-4 mt-4 franchise-lockable">
                <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">AI Upsell & Marketing</label>
                <input type="text" id="item-marketing-tags" class="bg-black/50 border border-white/10 text-white rounded p-3 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition font-mono" placeholder="[ZIMNY_NAPOJ] [BESTSELLER]">
                <hr class="border-white/5 my-2">
                <div class="flex flex-col gap-2">
                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Kody QR</span>
                    <input type="text" id="item-qr-link" class="bg-black/30 border border-white/10 text-slate-400 rounded p-2 text-xs w-full mb-2" readonly>
                    <button type="button" class="w-full bg-slate-800 hover:bg-slate-700 text-white text-xs py-2 rounded transition"><i class="fa-solid fa-qrcode mr-2"></i>Generuj / Pobierz QR</button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-2 border border-white/10 bg-black/30 rounded-xl p-4 mt-4">
                <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Alergeny (Auto-Traceability)</label>
                <div class="flex flex-wrap gap-2 pointer-events-none opacity-50">
                    <span class="px-2 py-1 text-[10px] border border-white/20 rounded-lg bg-black/40 text-slate-300">Gluten</span>
                    <span class="px-2 py-1 text-[10px] border border-white/20 rounded-lg bg-black/40 text-slate-300">Laktoza</span>
                    <span class="px-2 py-1 text-[10px] border border-white/20 rounded-lg bg-black/40 text-slate-300">Jaja</span>
                    <span class="px-2 py-1 text-[10px] border border-white/20 rounded-lg bg-black/40 text-slate-300">Orzechy</span>
                </div>
                <p class="text-[10px] text-slate-500">Alergeny wyliczane automatycznie z receptury</p>
            </div>

            <div class="pt-5 mt-4 border-t border-white/5">
                <button type="button" onclick="window.ItemEditor.saveItem()" class="w-full bg-blue-600 hover:bg-blue-500 active:bg-blue-700 text-white font-black text-xs uppercase tracking-widest py-3 px-4 rounded transition shadow-[0_0_15px_rgba(37,99,235,0.3)] hover:shadow-[0_0_25px_rgba(37,99,235,0.5)]">
                    <i class="fa-solid fa-floppy-disk mr-2"></i> Zapisz Danie
                </button>
            </div>
        `;

        let modifierGroupsHtml = `
<div class="bg-[#0a0a0f]/60 border border-purple-500/20 p-6 rounded-2xl mb-6 shadow-[0_0_15px_rgba(168,85,247,0.05)]">
    <h5 class="text-[10px] text-purple-400 font-bold uppercase tracking-wider mb-4">Powiązane Grupy Modyfikatorów</h5>
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3" id="modifierGroupsCheckboxContainer">`;

        if (window.StudioState && window.StudioState.modifierGroups && window.StudioState.modifierGroups.length > 0) {
            window.StudioState.modifierGroups.forEach(group => {
                modifierGroupsHtml += `
            <label class="flex items-center gap-3 cursor-pointer group hover:bg-white/5 p-2 rounded-lg transition-colors border border-transparent hover:border-white/10">
                <input type="checkbox" class="modifier-group-checkbox w-4 h-4 rounded border-white/10 bg-black/50 text-purple-500 focus:ring-purple-500 focus:ring-offset-gray-900 cursor-pointer" value="${group.id}" id="modGroup_${group.id}">
                <span class="text-xs text-white font-bold group-hover:text-purple-300 transition-colors">${group.name}</span>
            </label>
        `;
            });
        } else {
            modifierGroupsHtml += '<div class="col-span-full text-slate-500 text-xs font-bold uppercase">Brak grup modyfikatorów w systemie.</div>';
        }
        modifierGroupsHtml += `</div></div>`;
        const publicationPanel = document.getElementById('item-publication-panel');
        if (publicationPanel) {
            publicationPanel.insertAdjacentHTML('beforebegin', modifierGroupsHtml);
        } else {
            form.insertAdjacentHTML('beforeend', modifierGroupsHtml);
        }

        // Magia Auto-SKU
        const nameInput = document.getElementById('item-name');
        const asciiInput = document.getElementById('item-ascii-key');
        if(nameInput && asciiInput) {
            nameInput.addEventListener('input', (e) => {
                const currentId = document.getElementById('item-id') ? document.getElementById('item-id').value : '0';
                if(currentId === '0' || currentId === '') {
                    asciiInput.value = window.ItemEditor.toAutoSlug(e.target.value);
                }
            });
        }

        form.dataset.omnichannelReady = '1';
    },

    applyFranchiseShield: function(isLockedByHq) {
        const lockTargets = [
            { wrapperId: 'lock-item-name-wrapper', inputId: 'item-name' },
            { wrapperId: 'lock-item-description-wrapper', inputId: 'item-description' },
            { wrapperId: 'lock-item-image-url-wrapper', inputId: 'item-image-url' },
            { wrapperId: 'lock-item-ascii-wrapper', inputId: 'item-ascii-key' },
            { wrapperId: 'lock-item-kds-wrapper', inputId: 'item-kds-station-id' },
            { wrapperId: 'lock-item-tags-wrapper', inputId: 'item-marketing-tags' }
        ];

        lockTargets.forEach(target => {
            const wrapper = document.getElementById(target.wrapperId);
            const input = document.getElementById(target.inputId);
            if (!wrapper || !input) return;

            if (isLockedByHq) {
                wrapper.classList.add('pointer-events-none', 'opacity-50');
                input.disabled = true;
            } else {
                wrapper.classList.remove('pointer-events-none', 'opacity-50');
                input.disabled = false;
            }
        });
    },

    loadItemDataToForm: function(itemData) {
        this.ensureOmnichannelForm();

        const idField = document.getElementById('item-id');
        const nameField = document.getElementById('item-name');
        const asciiKeyField = document.getElementById('item-ascii-key');
        const categoryIdField = document.getElementById('item-category-id');
        const vatField = document.getElementById('item-vat-rate');

        // --- ŁATKA: Ładowanie kategorii do wyboru u źródła ---
        if (categoryIdField && categoryIdField.options.length <= 1) {
            const categories = window.StudioState?.categories || [];
            categories.forEach(cat => {
                categoryIdField.add(new Option(cat.name, cat.id));
            });
        }
        // -----------------------------------------------------
        const posPriceField = document.getElementById('item-price-pos');
        const takeawayPriceField = document.getElementById('item-price-takeaway');
        const deliveryPriceField = document.getElementById('item-price-delivery');
        const kdsField = document.getElementById('item-kds-station-id');
        const publicationStatusField = document.getElementById('item-publication-status');
        const validFromField = document.getElementById('item-valid-from');
        const validToField = document.getElementById('item-valid-to');
        const descriptionField = document.getElementById('item-description');
        const imageUrlField = document.getElementById('item-image-url');
        const marketingTagsField = document.getElementById('item-marketing-tags');
        const qrLinkField = document.getElementById('item-qr-link');

        const matrix = itemData.priceMatrix || {};

        if (idField) idField.value = itemData.id || 0;
        if (nameField) nameField.value = itemData.name || '';

        if (asciiKeyField) {
            asciiKeyField.value = itemData.asciiKey || '';
            if (itemData.id > 0) {
                asciiKeyField.disabled = true;
                asciiKeyField.classList.add('cursor-not-allowed', 'opacity-50');
            } else {
                asciiKeyField.disabled = false;
                asciiKeyField.classList.remove('cursor-not-allowed', 'opacity-50');
            }
        }

        if (categoryIdField) categoryIdField.value = itemData.categoryId || 0;
        if (vatField) vatField.value = itemData.vatRate || 23;
        if (posPriceField) posPriceField.value = matrix.POS !== undefined ? matrix.POS : (itemData.price || 0.00);
        if (takeawayPriceField) takeawayPriceField.value = matrix.Takeaway !== undefined ? matrix.Takeaway : (itemData.priceTakeaway || 0.00);
        if (deliveryPriceField) deliveryPriceField.value = matrix.Delivery !== undefined ? matrix.Delivery : (itemData.priceDelivery || 0.00);
        if (kdsField) kdsField.value = itemData.kdsStationId || itemData.printerStation || 'NONE';
        if (publicationStatusField) publicationStatusField.value = itemData.publicationStatus || (itemData.isActive ? 'Live' : 'Draft');
        if (validFromField) validFromField.value = itemData.validFrom || '';
        if (validToField) validToField.value = itemData.validTo || '';
        if (descriptionField) descriptionField.value = itemData.description || '';
        if (imageUrlField) imageUrlField.value = itemData.imageUrl || '';
        if (marketingTagsField) marketingTagsField.value = itemData.marketingTags || '';
        if (qrLinkField) qrLinkField.value = `https://menu.slicehub.app/item/${itemData.asciiKey || ''}`;

        document.querySelectorAll('.modifier-group-checkbox').forEach(cb => { cb.checked = false; });
        if (itemData.modifierGroupIds && Array.isArray(itemData.modifierGroupIds)) {
            itemData.modifierGroupIds.forEach(groupId => {
                const checkbox = document.getElementById(`modGroup_${groupId}`);
                if (checkbox) checkbox.checked = true;
            });
        }

        this.applyFranchiseShield(!!itemData.isLockedByHq);
    },

    saveItem: async function() {
        this.ensureOmnichannelForm();

        const idField = document.getElementById('item-id');
        const nameField = document.getElementById('item-name');
        const asciiKeyField = document.getElementById('item-ascii-key');
        const categoryIdField = document.getElementById('item-category-id');
        const vatField = document.getElementById('item-vat-rate');
        const posPriceField = document.getElementById('item-price-pos');
        const takeawayPriceField = document.getElementById('item-price-takeaway');
        const deliveryPriceField = document.getElementById('item-price-delivery');
        const kdsField = document.getElementById('item-kds-station-id');
        const publicationStatusField = document.getElementById('item-publication-status');
        const validFromField = document.getElementById('item-valid-from');
        const validToField = document.getElementById('item-valid-to');
        const descriptionField = document.getElementById('item-description');
        const imageUrlField = document.getElementById('item-image-url');
        const marketingTagsField = document.getElementById('item-marketing-tags');

        const itemId = idField ? parseInt(idField.value, 10) || 0 : 0;
        const name = nameField ? nameField.value.trim() : '';
        const rawAsciiKey = asciiKeyField ? asciiKeyField.value : '';
        const cleanKey = rawAsciiKey.replace(/[^a-zA-Z0-9_-]/g, '').toUpperCase();
        const categoryId = categoryIdField ? parseInt(categoryIdField.value, 10) || 0 : 0;
        const vatRate = vatField ? parseFloat(vatField.value) || 0.00 : 0.00;

        const pricePos = posPriceField ? parseFloat(posPriceField.value) || 0.00 : 0.00;
        const priceTakeaway = takeawayPriceField ? parseFloat(takeawayPriceField.value) || 0.00 : 0.00;
        const priceDelivery = deliveryPriceField ? parseFloat(deliveryPriceField.value) || 0.00 : 0.00;

        const kdsStationId = kdsField ? kdsField.value : 'NONE';
        const publicationStatus = publicationStatusField ? publicationStatusField.value : 'Draft';
        const validFrom = validFromField ? validFromField.value : '';
        const validTo = validToField ? validToField.value : '';
        const description = descriptionField ? descriptionField.value.trim() : '';
        const imageUrl = imageUrlField ? imageUrlField.value.trim() : '';
        const marketingTags = marketingTagsField ? marketingTagsField.value.trim() : '';

        if (typeof window.SliceValidator !== 'undefined' && typeof window.SliceValidator.validatePrice === 'function') {
            const priceMatrixToValidate = [pricePos, priceTakeaway, priceDelivery];
            for (let i = 0; i < priceMatrixToValidate.length; i++) {
                const validationResult = window.SliceValidator.validatePrice(priceMatrixToValidate[i]);
                if (validationResult && validationResult.error) {
                    alert("Błąd walidacji ceny: " + validationResult.message);
                    return;
                }
            }
        }

        if (!name || !cleanKey || categoryId <= 0) {
            alert("Wypełnij poprawnie wszystkie wymagane pola (Nazwa, SKU, Kategoria).");
            return;
        }

        const action = itemId > 0 ? 'update_item_full' : 'add_item';

        const payload = {
            action: action,
            itemId: itemId,
            name: name,
            asciiKey: cleanKey,
            categoryId: categoryId,
            priceTiers: [
                { channel: 'POS', price: pricePos },
                { channel: 'Takeaway', price: priceTakeaway },
                { channel: 'Delivery', price: priceDelivery }
            ],
            vatRate: vatRate,
            kdsStationId: kdsStationId,
            publicationStatus: publicationStatus,
            validFrom: validFrom,
            validTo: validTo,
            description: description,
            imageUrl: imageUrl,
            marketingTags: marketingTags,
            modifierGroupIds: Array.from(document.querySelectorAll('.modifier-group-checkbox:checked'))
                .map(cb => parseInt(cb.value, 10))
                .filter(Number.isInteger)
        };

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

            const result = await response.json();

            if (result.status === 'success') {
                if (typeof window.loadMenuTree === 'function') {
                    await window.loadMenuTree();
                }
                if (window.Core && typeof window.Core.renderTree === 'function') {
                    window.Core.renderTree();
                }
                alert("Zapisano pomyślnie!");
            } else {
                alert("Błąd zapisu: " + result.message);
                console.error("[ItemEditor]", result.message);
            }
        } catch (error) {
            alert("Wystąpił krytyczny błąd sieci podczas komunikacji z API.");
            console.error("[ItemEditor] Błąd API:", error);
        }
    }
};