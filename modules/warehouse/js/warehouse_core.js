/**
 * SLICEHUB ENTERPRISE: WAREHOUSE CORE ENGINE
 * Tryb: Control Tower
 */

window.WarehouseState = {
    stock: [],
    activeActionSku: null,
    warehouses: [],
    refreshTimer: null,
};

const REFRESH_INTERVAL_MS = 30_000;

const formatCurrency = new Intl.NumberFormat('pl-PL', {
    style: 'currency',
    currency: 'PLN'
});

document.addEventListener('DOMContentLoaded', () => {
    initControlTower();
});

async function initControlTower() {
    await loadWarehouseList();
    loadStock();
    startAutoRefresh();

    const btnOpenRw = document.getElementById('btn-open-rw');
    if (btnOpenRw) {
        btnOpenRw.addEventListener('click', () => openRWModal());
    }

    const picker = document.getElementById('warehousePicker');
    if (picker) {
        picker.addEventListener('change', () => {
            localStorage.setItem('sh_warehouse_id', picker.value);
            loadStock();
        });
    }
}

function getActiveWarehouse() {
    const picker = document.getElementById('warehousePicker');
    return picker ? picker.value : (localStorage.getItem('sh_warehouse_id') || 'MAIN');
}

async function loadWarehouseList() {
    const result = await window.WarehouseApi.getWarehouseList();
    const picker = document.getElementById('warehousePicker');
    if (!picker) return;

    if (result.success && Array.isArray(result.data) && result.data.length > 0) {
        WarehouseState.warehouses = result.data;
        picker.innerHTML = '';
        result.data.forEach(wh => {
            const opt = document.createElement('option');
            opt.value = wh.warehouse_id || wh.id || wh;
            opt.textContent = typeof wh === 'string' ? wh : (wh.name || wh.warehouse_id || wh.id);
            picker.appendChild(opt);
        });
    }

    const saved = localStorage.getItem('sh_warehouse_id');
    if (saved) {
        const exists = Array.from(picker.options).some(o => o.value === saved);
        if (exists) picker.value = saved;
    }
    localStorage.setItem('sh_warehouse_id', picker.value);
}

function startAutoRefresh() {
    if (WarehouseState.refreshTimer) clearInterval(WarehouseState.refreshTimer);
    WarehouseState.refreshTimer = setInterval(() => loadStock(), REFRESH_INTERVAL_MS);
}

function updateTimestamp() {
    const el = document.getElementById('lastUpdated');
    if (el) el.textContent = 'Odświeżono: ' + new Date().toLocaleTimeString('pl-PL');
}

/**
 * 1. POBIERANIE DANYCH (API CALL)
 */
async function loadStock() {
    const warehouseId = getActiveWarehouse();
    const result = await window.WarehouseApi.stockList(warehouseId);

    if (result.success) {
        WarehouseState.stock = result.data;
        calculateDashboard(WarehouseState.stock);
        renderMatrix(WarehouseState.stock);
        updateTimestamp();
    } else {
        WarehouseState.stock = [];
        renderMatrix([]);
        showConnectionError(result.message);
    }

    loadDraftCount();
}

async function loadDraftCount() {
    const res = await window.WarehouseApi.getDocumentsList({ status: 'draft' });
    const el = document.getElementById('dashDrafts');
    if (!el) return;
    if (res.success && res.data) {
        el.textContent = res.data.total ?? (res.data.documents || []).length;
    } else {
        el.textContent = '—';
    }
}

function showConnectionError(msg) {
    const container = document.getElementById('matrixBody');
    container.innerHTML = `
        <div class="flex flex-col items-center justify-center h-full text-center p-10">
            <svg class="w-12 h-12 text-red-500/60 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
            <p class="text-red-400 font-semibold mb-1">Brak połączenia z serwerem</p>
            <p class="text-gray-600 text-sm max-w-md">${_esc(msg || 'Nie udało się pobrać danych magazynowych. Sprawdź połączenie i spróbuj ponownie.')}</p>
            <button onclick="loadStock()"
                    class="mt-4 px-4 py-2 text-sm bg-white/5 border border-white/10 rounded-lg text-gray-400
                           hover:bg-white/10 hover:text-white transition-all">
                Spróbuj ponownie
            </button>
        </div>
    `;
    document.getElementById('dashAlert86').textContent = '—';
    document.getElementById('dashTotalValue').textContent = '—';
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
        if (qty <= 0) alert86Count++;
        if (qty > 0) totalFrozenValue += (qty * avco);
    });

    const el86 = document.getElementById('dashAlert86');
    el86.innerText = alert86Count;
    el86.classList.toggle('text-red-400', alert86Count > 0);

    document.getElementById('dashTotalValue').innerText = formatCurrency.format(totalFrozenValue);
}

/**
 * 3. RENDEROWANIE MATRYCY (STREFA B)
 */
function renderMatrix(data) {
    const container = document.getElementById('matrixBody');
    container.innerHTML = '';

    if (data.length === 0) {
        container.innerHTML = `<div class="p-6 text-center text-gray-500">Brak surowców w bazie dla tego magazynu.</div>`;
        return;
    }

    data.forEach(item => {
        const qty        = parseFloat(item.quantity)           || 0;
        const unitCost   = parseFloat(item.unit_net_cost)      || 0;
        const avco       = parseFloat(item.current_avco_price) || 0;
        const baseUnit   = _esc(item.base_unit || '');
        const stockValue = qty * avco;

        const qtyClass = qty <= 0 ? 'text-red-500 font-bold' : 'text-gray-200';

        let statusPercent = Math.min((qty / 50) * 100, 100);
        let barColor      = 'bg-emerald-500';

        if (qty <= 0)       { statusPercent = 0; barColor = 'bg-gray-700'; }
        else if (qty <= 5)  { barColor = 'bg-red-500';    statusPercent = Math.max(statusPercent, 5); }
        else if (qty <= 15) { barColor = 'bg-yellow-400'; }

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
    WarehouseState.activeActionSku = sku;
    const item = WarehouseState.stock.find(i => i.sku === sku);

    document.querySelectorAll('#matrixBody > div').forEach(el => {
        el.classList.remove('row-active');
        el.style.borderLeft = "none";
    });

    const activeRow = document.getElementById(`row-${sku}`);
    if (activeRow) {
        activeRow.classList.add('row-active');
        activeRow.style.borderLeft = "4px solid #8b5cf6";
    }

    const panel = document.getElementById('actionPanelContent');

    panel.innerHTML = `
        <div class="animate-fade-in-up">
            <div class="bg-black/30 rounded-lg p-4 mb-6 border border-white/5">
                <div class="text-xs text-gray-500 font-mono mb-1">${_esc(item.sku)}</div>
                <h3 class="text-xl font-bold text-white">${_esc(item.name)}</h3>
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
                        class="w-full bg-red-600/20 hover:bg-red-600/40 text-red-400 border border-red-500/30 rounded-lg p-4 flex items-center justify-between transition-all group active:scale-95">
                    <div class="flex items-center gap-3">
                        <div class="bg-red-500/20 p-2 rounded-md group-hover:bg-red-500 transition-colors">
                            <svg class="w-5 h-5 text-red-400 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path></svg>
                        </div>
                        <span class="font-semibold tracking-wide">- Strata / Rozchód (RW)</span>
                    </div>
                </button>

                <button onclick="window.location.href='manager_in.html'"
                        class="w-full bg-amber-600/20 hover:bg-amber-600/40 text-amber-300 border border-amber-500/30 rounded-lg p-4 flex items-center justify-between transition-all group active:scale-95 mt-4">
                    <div class="flex items-center gap-3">
                        <div class="bg-amber-500/20 p-2 rounded-md group-hover:bg-amber-500 transition-colors">
                            <svg class="w-5 h-5 text-amber-300 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        </div>
                        <span class="font-semibold tracking-wide">Inwentaryzacja (INW)</span>
                    </div>
                </button>
            </div>
        </div>
    `;
};

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

window.addPZRow = function(skuPreselect = '') {
    const container = document.getElementById('pzItemsContainer');

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

window.removePZRow = function(btn) {
    btn.closest('.pz-row').remove();
    _pzSyncEmptyState();
    updatePZSummary();
};

window.updatePZSummary = function() {
    let total = 0;
    document.querySelectorAll('#pzItemsContainer .pz-row').forEach(row => {
        const qty   = parseFloat(row.querySelector('.pz-qty').value)   || 0;
        const price = parseFloat(row.querySelector('.pz-price').value) || 0;
        total += qty * price;
    });
    document.getElementById('pzTotalValue').textContent = formatCurrency.format(total);
};

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
        const result = await window.WarehouseApi.postReceipt({
            warehouse_id:       getActiveWarehouse(),
            supplier_name:      notes || 'PZ — Control Tower',
            supplier_invoice:   '',
            lines: items.map((row) => ({
                resolved_sku:   row.sku,
                quantity:       row.qty,
                unit_net_cost:  row.unit_net_cost,
            })),
        });

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

function _pzSyncEmptyState() {
    const hasRows = document.querySelectorAll('#pzItemsContainer .pz-row').length > 0;
    document.getElementById('pzEmptyState').classList.toggle('hidden', hasRows);
}

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

window.openRWModal = function(skuPreselect = null) {
    const modal  = document.getElementById('rwModal');
    const select = document.getElementById('rw-item-select');
    const qty    = document.getElementById('rw-qty');
    const reason = document.getElementById('rw-reason');

    qty.value    = '';
    reason.value = reason.options[0]?.value ?? '';

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

    if (!sku) {
        sku = (typeof SliceValidator !== 'undefined' && SliceValidator.sanitizeKey)
            ? SliceValidator.sanitizeKey(name)
            : String(name || '')
                  .replace(/\s+/g, '_')
                  .replace(/[^A-Za-z0-9_]/g, '')
                  .toUpperCase()
                  .slice(0, 64);
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
        const result = await window.WarehouseApi.postAddItem({
            name,
            base_unit: unit,
            sku,
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
        const result = await window.WarehouseApi.postInternalRw({
            warehouse_id: getActiveWarehouse(),
            sku,
            qty,
            reason,
        });

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
