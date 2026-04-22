/**
 * SLICEHUB ENTERPRISE: WAREHOUSE CORE ENGINE
 * Tryb: Control Tower
 */

// Bazowy URL API — ścieżka relatywna względem tego pliku (modules/warehouse/js/)
const API_BASE = '../../api/api_warehouse.php';

// Globalny Stan Aplikacji (Źródło Prawdy)
window.WarehouseState = {
    stock: [],
    activeActionSku: null
};

// Formatter Walutowy (PLN)
const formatCurrency = new Intl.NumberFormat('pl-PL', {
    style: 'currency',
    currency: 'PLN'
});

document.addEventListener('DOMContentLoaded', () => {
    initControlTower();
});

function initControlTower() {
    loadStock();

    // Globalny przycisk "Strata (RW)" w nagłówku
    const btnOpenRw = document.getElementById('btn-open-rw');
    if (btnOpenRw) {
        btnOpenRw.addEventListener('click', () => openRWModal());
    }
}

/**
 * 1. POBIERANIE DANYCH (API CALL)
 */
async function loadStock() {
    const result = await window.ApiClient.get(API_BASE, { action: 'GET_STOCK' });

    if (result.success) {
        WarehouseState.stock = result.data;
        calculateDashboard(WarehouseState.stock);
        renderMatrix(WarehouseState.stock);
    } else {
        console.warn('Backend niedostępny, ładuję tryb symulacji (Mock Data)...');
        WarehouseState.stock = [
            { sku: 'MKA_01',  name: 'Mąka Typ 00',           base_unit: 'kg', quantity: 25.5, unit_net_cost: 3.20,  current_avco_price: 3.45  },
            { sku: 'SER_MOZ', name: 'Ser Mozzarella',         base_unit: 'kg', quantity: 0,    unit_net_cost: 15.00, current_avco_price: 15.00 },
            { sku: 'SOS_POM', name: 'Sos Pomidorowy Mutti',   base_unit: 'l',  quantity: 12,   unit_net_cost: 8.50,  current_avco_price: 8.90  }
        ];
        calculateDashboard(WarehouseState.stock);
        renderMatrix(WarehouseState.stock);
    }
}

/**
 * 2. KALKULACJE TOP BARU (DASHBOARD)
 */
function calculateDashboard(data) {
    let alert86Count = 0;
    let totalFrozenValue = 0;

    data.forEach(item => {
        const qty = parseFloat(item.quantity) || 0;
        const avco = parseFloat(item.current_avco_price) || 0;
        
        // Alert 86 (Sold out / Brak)
        if (qty <= 0) alert86Count++;
        
        // Matematyka Zamrożonej Gotówki
        if (qty > 0) totalFrozenValue += (qty * avco);
    });

    document.getElementById('dashAlert86').innerText = alert86Count;
    document.getElementById('dashTotalValue').innerText = formatCurrency.format(totalFrozenValue);
    
    // Dodajemy mały efekt wizualny jeśli braki rosną
    if(alert86Count > 0) {
        document.getElementById('dashAlert86').classList.add('text-red-400');
    }
}

/**
 * 3. RENDEROWANIE MATRYCY (STREFA B)
 */
function renderMatrix(data) {
    const container = document.getElementById('matrixBody');
    container.innerHTML = '';

    if(data.length === 0) {
        container.innerHTML = `<div class="p-6 text-center text-gray-500">Brak surowców w bazie.</div>`;
        return;
    }

    data.forEach(item => {
        const qty        = parseFloat(item.quantity)           || 0;
        const unitCost   = parseFloat(item.unit_net_cost)      || 0;
        const avco       = parseFloat(item.current_avco_price) || 0;
        const baseUnit   = _esc(item.base_unit || '');
        const stockValue = qty * avco;

        // Alert 86: qty <= 0 → czerwony, pozytywny → szary/biały
        const qtyClass = qty <= 0 ? 'text-red-500 font-bold' : 'text-gray-200';

        // Pasek stanu półki (wizualizacja DOH uproszczona; 50 j. = 100%)
        let statusPercent = Math.min((qty / 50) * 100, 100);
        let barColor      = 'bg-emerald-500';

        if (qty <= 0)       { statusPercent = 0; barColor = 'bg-gray-700'; }
        else if (qty <= 5)  { barColor = 'bg-red-500';    statusPercent = Math.max(statusPercent, 5); }
        else if (qty <= 15) { barColor = 'bg-yellow-400'; }

        // Wyszarzenie wierszy z zerowym stanem (widoczne, ale stonowane)
        const rowDim = qty <= 0 ? 'opacity-50 hover:opacity-80' : '';

        const row = document.createElement('div');
        row.id        = `row-${item.sku}`;
        row.className = `grid grid-cols-12 gap-4 px-6 py-4 border-b border-white/5 items-center
                         transition-all hover:bg-white/5 ${rowDim}`;

        row.innerHTML = `
            <div class="col-span-2 font-mono text-xs text-gray-500 truncate" title="${item.sku}">${_esc(item.sku)}</div>
            <div class="col-span-3 font-medium text-white truncate" title="${_esc(item.name)}">${_esc(item.name)}</div>
            <div class="col-span-1 text-right text-base ${qtyClass}">
                ${qty.toFixed(3)}
                ${baseUnit ? `<span class="text-[10px] font-normal text-gray-500 ml-0.5">${baseUnit}</span>` : ''}
            </div>
            <div class="col-span-1 text-right text-gray-500 text-sm">
                ${formatCurrency.format(unitCost)}
            </div>
            <div class="col-span-2 text-right text-gray-400 text-sm">
                ${formatCurrency.format(avco)}
            </div>
            <div class="col-span-2">
                <div class="text-right text-emerald-400 font-semibold text-sm mb-1">
                    ${formatCurrency.format(stockValue)}
                </div>
                <div class="w-full bg-black/50 h-1 rounded-full overflow-hidden">
                    <div class="h-full ${barColor} transition-all duration-700" style="width: ${statusPercent}%"></div>
                </div>
            </div>
            <div class="col-span-1 text-center">
                <button onclick="triggerActionPanel('${_esc(item.sku)}')"
                        title="Protokół operacyjny"
                        class="bg-violet-600/80 hover:bg-violet-500 active:scale-95 text-white rounded-lg
                               p-2 text-xs border border-violet-400/30 transition-all shadow-lg shadow-violet-900/20">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </button>
            </div>
        `;
        container.appendChild(row);
    });
}

/**
 * 4. OBSŁUGA SMART ACTION PANEL (STREFA C)
 */
window.triggerActionPanel = function(sku) {
    // Aktualizacja Stanu
    WarehouseState.activeActionSku = sku;
    const item = WarehouseState.stock.find(i => i.sku === sku);

    // Oczyszczanie starych podświetleń
    document.querySelectorAll('#matrixBody > div').forEach(el => {
        el.classList.remove('row-active');
        el.style.borderLeft = "none";
    });

    // Podświetlenie aktywnego wiersza
    const activeRow = document.getElementById(`row-${sku}`);
    if(activeRow) {
        activeRow.classList.add('row-active');
        activeRow.style.borderLeft = "4px solid #8b5cf6";
    }

    // Wstrzykiwanie widoku do Prawego Panelu
    const panel = document.getElementById('actionPanelContent');
    
    panel.innerHTML = `
        <div class="animate-fade-in-up">
            <div class="bg-black/30 rounded-lg p-4 mb-6 border border-white/5">
                <div class="text-xs text-gray-500 font-mono mb-1">${item.sku}</div>
                <h3 class="text-xl font-bold text-white">${item.name}</h3>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-sm text-gray-400">Stan obecny:</span>
                    <span class="text-lg font-bold ${item.quantity <= 0 ? 'text-red-500' : 'text-gray-200'}">${item.quantity}</span>
                </div>
            </div>

            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Wybierz Protokół</h4>
            
            <div class="grid grid-cols-1 gap-3">
                <button onclick="openPZModal('${item.sku}')"
                        class="w-full bg-blue-600/20 hover:bg-blue-600/40 text-blue-400 border border-blue-500/30 rounded-lg p-4 flex items-center justify-between transition-all group active:scale-95">
                    <div class="flex items-center gap-3">
                        <div class="bg-blue-500/20 p-2 rounded-md group-hover:bg-blue-500 transition-colors">
                            <svg class="w-5 h-5 text-blue-400 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        </div>
                        <span class="font-semibold tracking-wide">+ Przyjęcie (PZ)</span>
                    </div>
                </button>

                <button onclick="openRWModal('${item.sku}')" 
                        class="w-full bg-red-600/20 hover:bg-red-600/40 text-red-400 border border-red-500/30 rounded-lg p-4 flex items-center justify-between transition-all group">
                    <div class="flex items-center gap-3">
                        <div class="bg-red-500/20 p-2 rounded-md group-hover:bg-red-500 transition-colors">
                            <svg class="w-5 h-5 text-red-400 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path></svg>
                        </div>
                        <span class="font-semibold tracking-wide">- Strata / Rozchód (RW)</span>
                    </div>
                </button>
                
                <button onclick="alert('TODO: Inwentaryzacja Ślepa')" 
                        class="w-full bg-gray-600/20 hover:bg-gray-600/40 text-gray-300 border border-gray-500/30 rounded-lg p-4 flex items-center justify-between transition-all group mt-4">
                    <span class="font-semibold tracking-wide">Inwentaryzacja (INW)</span>
                </button>
            </div>
        </div>
    `;
};

// Pomocnicza animacja z Tailwind (dodana w locie via JS styl)
if (!document.getElementById('animStyles')) {
    const style = document.createElement('style');
    style.id = 'animStyles';
    style.innerHTML = `
        @keyframes fadeInUp   { from { opacity: 0; transform: translateY(10px); }  to { opacity: 1; transform: translateY(0); } }
        @keyframes slideInRight { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
        .animate-fade-in-up    { animation: fadeInUp    0.3s ease-out forwards; }
        .animate-slide-in-right{ animation: slideInRight 0.25s ease-out forwards; }
    `;
    document.head.appendChild(style);
}

// =============================================================================
// 5. MODAL PZ — SILNIK (Przyjęcie Zewnętrzne)
// =============================================================================

/**
 * Otwiera modal PZ. Opcjonalnie pre-selektuje konkretne SKU w pierwszym wierszu.
 */
window.openPZModal = function(skuPreselect = null) {
    const modal = document.getElementById('pzModal');
    document.getElementById('pzNotes').value = '';
    document.getElementById('pzItemsContainer').innerHTML = '';
    document.getElementById('pzTotalValue').textContent = '0,00 zł';
    _pzSyncEmptyState();

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';

    if (skuPreselect) {
        addPZRow(skuPreselect);
    }
};

window.closePZModal = function() {
    const modal = document.getElementById('pzModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
};

/**
 * Dodaje nowy wiersz pozycji dostawy do listy w modalu.
 * @param {string} skuPreselect - opcjonalne SKU do pre-selekcji w dropdownie
 */
window.addPZRow = function(skuPreselect = '') {
    const container = document.getElementById('pzItemsContainer');

    // Budujemy opcje selecta z aktualnego stanu magazynu
    const options = WarehouseState.stock
        .map(s => `<option value="${_esc(s.sku)}" ${s.sku === skuPreselect ? 'selected' : ''}>
                       ${_esc(s.name)} (${_esc(s.sku)})
                   </option>`)
        .join('');

    const row = document.createElement('div');
    row.className = 'pz-row grid grid-cols-12 gap-3 items-center bg-white/5 border border-white/8 ' +
                    'rounded-xl px-3 py-3 hover:border-white/15 transition-all animate-fade-in-up';

    row.innerHTML = `
        <div class="col-span-5">
            <select onchange="updatePZSummary()"
                    class="pz-sku w-full bg-[#0a0a0f] border border-white/10 rounded-lg px-3 py-2
                           text-gray-200 text-sm focus:outline-none focus:border-blue-500/50 cursor-pointer
                           appearance-none">
                <option value="">— wybierz surowiec —</option>
                ${options}
            </select>
        </div>
        <div class="col-span-3">
            <input type="number" step="0.001" min="0.001" placeholder="0.000"
                   oninput="updatePZSummary()"
                   class="pz-qty w-full bg-[#0a0a0f] border border-white/10 rounded-lg px-3 py-2
                          text-gray-200 text-sm text-right focus:outline-none focus:border-blue-500/50
                          [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none
                          [&::-webkit-inner-spin-button]:appearance-none">
        </div>
        <div class="col-span-3">
            <div class="relative">
                <input type="number" step="0.01" min="0" placeholder="0.00"
                       oninput="updatePZSummary()"
                       class="pz-price w-full bg-[#0a0a0f] border border-white/10 rounded-lg px-3 py-2 pr-7
                              text-gray-200 text-sm text-right focus:outline-none focus:border-blue-500/50
                              [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none
                              [&::-webkit-inner-spin-button]:appearance-none">
                <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-600 text-xs pointer-events-none">zł</span>
            </div>
        </div>
        <div class="col-span-1 flex justify-center">
            <button onclick="removePZRow(this)"
                    title="Usuń pozycję"
                    class="text-gray-600 hover:text-red-400 active:scale-90 transition-all p-1.5 rounded-lg hover:bg-red-500/10">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </button>
        </div>
    `;

    container.appendChild(row);
    _pzSyncEmptyState();
    updatePZSummary();
};

/**
 * Usuwa wiersz pozycji klikając ikonę kosza.
 */
window.removePZRow = function(btn) {
    btn.closest('.pz-row').remove();
    _pzSyncEmptyState();
    updatePZSummary();
};

/**
 * Przelicza i wyświetla sumę wartości dostawy w stopce modalu.
 */
window.updatePZSummary = function() {
    let total = 0;
    document.querySelectorAll('#pzItemsContainer .pz-row').forEach(row => {
        const qty   = parseFloat(row.querySelector('.pz-qty').value)   || 0;
        const price = parseFloat(row.querySelector('.pz-price').value) || 0;
        total += qty * price;
    });
    document.getElementById('pzTotalValue').textContent = formatCurrency.format(total);
};

/**
 * Wysyła formularz PZ do backendu i obsługuje odpowiedź.
 */
window.submitPZ = async function() {
    const notes = document.getElementById('pzNotes').value.trim();
    const rows  = document.querySelectorAll('#pzItemsContainer .pz-row');

    if (rows.length === 0) {
        showToast('error', 'Dodaj przynajmniej jedną pozycję do dostawy.');
        return;
    }

    const items  = [];
    let hasError = false;

    rows.forEach((row, idx) => {
        if (hasError) return;
        const num    = idx + 1;
        const sku    = row.querySelector('.pz-sku').value;
        const qty    = parseFloat(row.querySelector('.pz-qty').value);
        const price  = parseFloat(row.querySelector('.pz-price').value);

        if (!sku)              { showToast('error', `Pozycja ${num}: wybierz surowiec.`);         hasError = true; return; }
        if (!qty || qty <= 0)  { showToast('error', `Pozycja ${num}: ilość musi być > 0.`);       hasError = true; return; }
        if (isNaN(price) || price < 0) { showToast('error', `Pozycja ${num}: nieprawidłowa cena.`); hasError = true; return; }

        items.push({ sku, qty, unit_net_cost: price });
    });

    if (hasError) return;

    // UI: stan ładowania przycisku
    const btn = document.getElementById('pzSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = `
        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
        Księguję...
    `;

    try {
        const result = await window.ApiClient.post(API_BASE, { action: 'PROCESS_PZ', notes, items });

        if (result.success) {
            closePZModal();
            showToast('success', result.message || 'Dostawa PZ zaksięgowana pomyślnie.');
            loadStock();
        } else {
            showToast('error', result.message || 'Błąd podczas księgowania dostawy.');
        }
    } catch (err) {
        showToast('error', 'Błąd połączenia z serwerem: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = `
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            Zapisz Dostawę
        `;
    }
};

/** Pokazuje/ukrywa placeholder "brak pozycji" zależnie od zawartości listy. */
function _pzSyncEmptyState() {
    const hasRows = document.querySelectorAll('#pzItemsContainer .pz-row').length > 0;
    document.getElementById('pzEmptyState').classList.toggle('hidden', hasRows);
}

/** Mini-helper: escapuje tekst przed wstawieniem do innerHTML. */
function _esc(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
}

// =============================================================================
// 6. TOAST — natywny system powiadomień
// =============================================================================

function showToast(type, message) {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const isSuccess = type === 'success';
    const toast     = document.createElement('div');

    toast.className = [
        'pointer-events-auto flex items-center gap-3 px-5 py-3.5 rounded-xl border shadow-2xl',
        'text-sm font-medium max-w-sm w-full animate-slide-in-right',
        isSuccess
            ? 'bg-emerald-950/95 border-emerald-500/40 text-emerald-200'
            : 'bg-red-950/95    border-red-500/40     text-red-200'
    ].join(' ');

    const iconSuccess = `<svg class="w-5 h-5 shrink-0 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;
    const iconError   = `<svg class="w-5 h-5 shrink-0 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;

    toast.innerHTML = `
        ${isSuccess ? iconSuccess : iconError}
        <span class="flex-grow leading-snug">${message}</span>
        <button onclick="this.closest('[data-toast]').remove()"
                class="opacity-40 hover:opacity-100 transition-opacity shrink-0 ml-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    `;
    toast.dataset.toast = '1';
    container.appendChild(toast);

    // Auto-dismiss po 5s z płynnym wyjściem
    setTimeout(() => {
        toast.style.transition = 'opacity 0.35s ease, transform 0.35s ease';
        toast.style.opacity    = '0';
        toast.style.transform  = 'translateX(24px)';
        setTimeout(() => toast.remove(), 380);
    }, 5000);
}

// =============================================================================
// 7. SILNIK MODALU RW — Rozchód Wewnętrzny / Strata
// =============================================================================

/**
 * Otwiera modal RW. Zaludnia select surowcami z WarehouseState.stock.
 * Opcjonalnie pre-selektuje przekazane SKU (wywołanie z Action Panel).
 */
window.openRWModal = function(skuPreselect = null) {
    const modal  = document.getElementById('rwModal');
    const select = document.getElementById('rw-item-select');
    const qty    = document.getElementById('rw-qty');
    const reason = document.getElementById('rw-reason');

    // Reset formularza
    qty.value    = '';
    reason.value = reason.options[0]?.value ?? '';

    // Zaludnienie selecta aktualnymi surowcami
    select.innerHTML = '<option value="">— wybierz surowiec —</option>';
    WarehouseState.stock.forEach(item => {
        const opt      = document.createElement('option');
        opt.value      = item.sku;
        opt.textContent = `${item.name} (${item.sku}) — stan: ${parseFloat(item.quantity).toFixed(3)}`;
        if (item.sku === skuPreselect) opt.selected = true;
        select.appendChild(opt);
    });

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
};

window.closeRWModal = function() {
    const modal = document.getElementById('rwModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
};

// =============================================================================
// 8. SILNIK MODALU: NOWY SUROWIEC (Słownik sys_items)
// =============================================================================

window.openAddItemModal = function() {
    const modal = document.getElementById('addItemModal');
    document.getElementById('new-item-name').value  = '';
    document.getElementById('new-item-unit').value  = '';
    document.getElementById('new-item-vat').value   = '5';
    document.getElementById('new-item-sku').value   = '';
    document.getElementById('modal-item-ksef').value = '';

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';

    setTimeout(() => document.getElementById('new-item-name').focus(), 50);
};

window.closeAddItemModal = function() {
    const modal = document.getElementById('addItemModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
};

window.saveNewItem = async function() {
    const name     = document.getElementById('new-item-name').value.trim();
    const unit     = document.getElementById('new-item-unit').value;
    const vat      = document.getElementById('new-item-vat').value;
    const ksefCode = document.getElementById('modal-item-ksef').value.trim() || null;
    let   sku      = document.getElementById('new-item-sku').value.trim().toUpperCase();

    if (!name) {
        showToast('error', 'Podaj nazwę surowca.');
        document.getElementById('new-item-name').focus();
        return;
    }
    if (!unit) {
        showToast('error', 'Wybierz jednostkę bazową.');
        return;
    }

    // Auto-generowanie SKU z nazwy jeśli pole pozostało puste
    if (!sku) {
        sku = SliceValidator.sanitizeKey(name);
    }

    const btn = document.getElementById('btn-save-new-item');
    btn.disabled = true;
    btn.innerHTML = `
        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
        Zapisuję...
    `;

    try {
        const result = await window.ApiClient.post(API_BASE, {
            action:             'ADD_ITEM',
            name,
            base_unit:          unit,
            vat_rate_purchase:  parseInt(vat, 10),
            sku,
            ksef_code:          ksefCode,
        });

        if (result.success) {
            closeAddItemModal();
            showToast('success', result.message || 'Surowiec dodany do słownika.');
            loadStock();
        } else {
            showToast('error', result.message || 'Błąd podczas dodawania surowca.');
        }
    } catch (err) {
        showToast('error', 'Błąd połączenia z serwerem: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = `
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            Zapisz do bazy
        `;
    }
};

/**
 * Waliduje dane, wysyła POST action=PROCESS_RW, obsługuje odpowiedź.
 */
window.submitRW = async function() {
    const sku    = document.getElementById('rw-item-select').value;
    const qty    = parseFloat(document.getElementById('rw-qty').value);
    const reason = document.getElementById('rw-reason').value;

    if (!sku)           { showToast('error', 'Wybierz surowiec.');          return; }
    if (!qty || qty <= 0) { showToast('error', 'Ilość musi być > 0.');      return; }

    const btn = document.getElementById('btn-save-rw');
    btn.disabled = true;
    btn.innerHTML = `
        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
        Księguję...
    `;

    try {
        const result = await window.ApiClient.post(API_BASE, { action: 'PROCESS_RW', sku, qty, reason });

        if (result.success) {
            closeRWModal();
            showToast('success', result.message || 'Rozchód RW zaksięgowany.');
            loadStock();
        } else {
            showToast('error', result.message || 'Błąd podczas księgowania rozchodu.');
        }
    } catch (err) {
        showToast('error', 'Błąd połączenia z serwerem: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = `
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
            Zaksięguj Rozchód
        `;
    }
};