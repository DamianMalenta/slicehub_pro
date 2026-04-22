let isBlindMode = true;
let inventoryData = [];

document.addEventListener('DOMContentLoaded', function() {
    if(typeof SliceValidator === 'undefined') {
        console.error("[CRITICAL] Brak połączenia z core_validator.js!");
    }
    init();
});

// 🧠 Inicjalizacja: ładujemy magazyny do selecta
window.init = async function() {
    try {
        const res = await fetch('../../../api/api_inventory.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_pz_init' })
        }).then(r => r.json());

        if(res.status === 'success') {
            const wh = document.getElementById('warehouse_id');
            if(wh) {
                wh.innerHTML = '<option value="">-- Wybierz Magazyn --</option>' + 
                    res.payload.warehouses.map(w => `<option value="${w.id}">${w.name}</option>`).join('');
            }
        }
    } catch(e) {
        console.error("Błąd ładowania magazynów:", e);
    }
}

// 🧠 Ładowanie listy produktów
window.loadInventoryData = async function() {
    const wid = document.getElementById('warehouse_id').value;
    if(!wid) return;

    document.getElementById('loading-overlay').classList.remove('hidden');
    
    try {
        const res = await fetch('../../../api/api_inventory.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_stock_matrix' })
        }).then(r => r.json());

        document.getElementById('loading-overlay').classList.add('hidden');

        if(res.status === 'success') {
            inventoryData = res.payload.products.map(p => {
                const stock = res.payload.stocks.find(s => s.product_id === p.id && s.warehouse_id == wid);
                return {
                    ...p,
                    sys_qty: stock ? parseFloat(stock.quantity) : 0,
                    actual_qty: null
                };
            });
            renderInventory();
        }
    } catch(e) {
        document.getElementById('loading-overlay').classList.add('hidden');
        console.error("Błąd ładowania matrycy:", e);
    }
}

window.renderInventory = function() {
    const tbody = document.getElementById('inventory-list');
    document.getElementById('items-count').innerText = `Wczytano: ${inventoryData.length} pozycji`;
    
    tbody.innerHTML = inventoryData.map((item, idx) => `
        <tr class="hover:bg-white/5 transition group border-l-2 border-transparent hover:border-amber-500">
            <td class="py-4 pr-4">
                <div class="font-bold text-slate-200 text-xs uppercase tracking-wide">${item.name}</div>
                <div class="text-[9px] text-slate-600 font-black tracking-widest uppercase mt-1">ID: ${item.id}</div>
            </td>
            <td class="py-4 text-center sys-qty font-mono text-slate-500 text-sm italic">
                ${item.sys_qty.toFixed(3)}
            </td>
            <td class="py-4 px-4 text-center">
                <input type="number" step="0.001" placeholder="0.000" 
                    class="w-32 bg-black border border-white/10 rounded-xl p-2 text-center text-amber-500 font-black outline-none focus:border-amber-500 transition shadow-inner"
                    oninput="updateActualQty(${idx}, this.value)">
            </td>
            <td class="py-4 text-center text-[10px] font-black text-slate-600 uppercase tracking-tighter bg-black/20 rounded-lg">
                ${item.unit}
            </td>
            <td class="py-4 text-right diff-col font-mono text-xs" id="diff-${idx}">
                --
            </td>
        </tr>
    `).join('');

    applyBlindModeStyles();
}

window.updateActualQty = function(idx, val) {
    const qty = parseFloat(val);
    inventoryData[idx].actual_qty = isNaN(qty) ? null : qty;
    
    if(!isBlindMode && !isNaN(qty)) {
        const diff = qty - inventoryData[idx].sys_qty;
        const cell = document.getElementById(`diff-${idx}`);
        cell.innerText = (diff > 0 ? '+' : '') + diff.toFixed(3);
        cell.className = `py-4 text-right diff-col font-mono text-xs ${diff === 0 ? 'text-slate-500' : (diff > 0 ? 'text-green-500' : 'text-red-500')}`;
    }
}

window.toggleBlindMode = function() {
    isBlindMode = !isBlindMode;
    const icon = document.getElementById('icon-blind');
    if(icon) {
        icon.className = isBlindMode ? 'fa-solid fa-toggle-on text-amber-500 text-xl' : 'fa-solid fa-toggle-off text-slate-600 text-xl';
    }
    applyBlindModeStyles();
}

window.applyBlindModeStyles = function() {
    const table = document.getElementById('in-table');
    if(table) {
        if(isBlindMode) table.classList.add('blind-mode-active');
        else table.classList.remove('blind-mode-active');
    }
}

window.saveInventory = async function() {
    const docNum = document.getElementById('doc_number').value;
    const wid = document.getElementById('warehouse_id').value;

    if(!docNum || !wid) return alert("Podaj numer dokumentu i wybierz magazyn!");
    
    const itemsToSave = inventoryData.filter(i => i.actual_qty !== null);
    if(itemsToSave.length === 0) return alert("Nie wpisałeś żadnych ilości!");

    if(!confirm("KRYTYCZNE: Czy na pewno chcesz nadpisać stany w bazie danych?")) return;

    document.getElementById('loading-overlay').classList.remove('hidden');

    try {
        let finalDocNum = docNum;
        if(typeof SliceValidator !== 'undefined') {
            finalDocNum = SliceValidator.sanitizeKey(docNum).toUpperCase();
        }

        const res = await fetch('../../../api/api_inventory.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_inventory',
                doc_number: finalDocNum,
                warehouse_id: wid,
                items: itemsToSave.map(i => ({ product_id: i.id, quantity: i.actual_qty }))
            })
        }).then(r => r.json());

        document.getElementById('loading-overlay').classList.add('hidden');

        if(res.status === 'success') {
            alert("Sukces! Stany zostały wyrównane. Wygenerowano raport różnic.");
            window.location.reload();
        } else {
            alert("Błąd: " + res.error);
        }
    } catch(e) {
        document.getElementById('loading-overlay').classList.add('hidden');
        console.error("Błąd zapisu inwentaryzacji:", e);
        alert("Wystąpił błąd komunikacji sieciowej.");
    }
}