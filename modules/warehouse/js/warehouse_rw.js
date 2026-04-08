let currentItems = [];
let totalBleed = 0;

// MOCK DATA - Symulacja danych z API
const mockProducts = [
    { id: 1, name: "Mąka do Pizzy typ 00", sku: "MAKA_00", base_unit: "kg", avg_cost: 4.50 },
    { id: 2, name: "Ser Mozzarella Fior di Latte", sku: "SER_MOZZ_FDL", base_unit: "kg", avg_cost: 28.00 },
    { id: 3, name: "Coca-Cola 0.5L PET", sku: "COLA_05_PET", base_unit: "szt", avg_cost: 2.15 },
    { id: 4, name: "Wódka Absolut 40%", sku: "ALC_ABSOLUT", base_unit: "l", avg_cost: 65.00 }
];

document.addEventListener('DOMContentLoaded', function() {
    if(typeof SliceValidator === 'undefined') {
        console.error("[CRITICAL] Brak połączenia z core_validator.js!");
    }
    loadProducts();
});

// Wymuszenie czystości ASCII w numerze dokumentu
window.sanitizeDocNumber = function() {
    const input = document.getElementById('doc_number');
    if(typeof SliceValidator !== 'undefined') {
        input.value = SliceValidator.sanitizeKey(input.value).toUpperCase();
    }
}

function loadProducts() {
    const sel = document.getElementById('product_selector');
    sel.innerHTML = '<option value="">-- Wybierz lub Skanuj --</option>';
    mockProducts.forEach(p => {
        let opt = document.createElement('option');
        opt.value = p.id;
        opt.text = `${p.name} [${p.sku}] - ${p.avg_cost.toFixed(2)} PLN/${p.base_unit}`;
        sel.appendChild(opt);
    });
}

window.addItemToTable = function() {
    const pid = parseInt(document.getElementById('product_selector').value);
    const rawQty = parseFloat(document.getElementById('prod_qty').value);
    const inputUnit = document.getElementById('prod_unit').value;
    const reason = document.getElementById('prod_reason').value;

    if (!pid || isNaN(rawQty) || rawQty <= 0) {
        return alert('Wybierz produkt i podaj poprawną ilość (powyżej 0)!');
    }

    const product = mockProducts.find(p => p.id === pid);
    
    // 🧠 UŻYCIE MÓZGU: Przeliczanie jednostki
    let finalQty = rawQty;
    let standardUnit = inputUnit;
    
    if(typeof SliceValidator !== 'undefined') {
        const conversion = SliceValidator.standardizeUnit(rawQty, inputUnit);
        finalQty = conversion.value;
        standardUnit = conversion.unit;
        
        if((product.base_unit === 'szt' && standardUnit !== 'szt') || 
           (product.base_unit !== 'szt' && standardUnit === 'szt')) {
            return alert(`Błąd jednostki. Towar bazuje na: ${product.base_unit}`);
        }
    }

    const itemCost = finalQty * product.avg_cost;

    currentItems.push({
        product_id: product.id,
        name: product.name,
        sku: product.sku,
        raw_qty: rawQty,
        input_unit: inputUnit,
        sys_qty: finalQty,
        sys_unit: standardUnit,
        reason: reason,
        cost: itemCost
    });

    document.getElementById('prod_qty').value = 1;
    renderTable();
}

function renderTable() {
    const tbody = document.getElementById('added_items_list');
    document.getElementById('items-count').innerText = `Pozycji: ${currentItems.length}`;
    
    totalBleed = 0;

    if(currentItems.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="py-20 text-center text-slate-600 font-bold uppercase text-xs tracking-widest italic">Dokument jest pusty. Zeskanuj towar.</td></tr>`;
        document.getElementById('total_bleed').innerText = "0.00";
        return;
    }
    
    tbody.innerHTML = '';
    currentItems.forEach((item, index) => {
        totalBleed += item.cost;
        tbody.innerHTML += `
            <tr class="hover:bg-white/5 transition group">
                <td class="p-4 border-b border-white/5">
                    <div class="font-bold text-red-400 text-xs">${item.name}</div>
                    <div class="text-[9px] text-slate-600 tracking-widest mt-1 uppercase">SKU: ${item.sku}</div>
                </td>
                <td class="p-4 border-b border-white/5 text-center">
                    <span class="font-mono text-white text-sm bg-black/50 px-2 py-1 rounded border border-white/10">
                        ${item.sys_qty.toFixed(3)} <span class="text-[10px] text-slate-500 ml-1">${item.sys_unit}</span>
                    </span>
                </td>
                <td class="p-4 border-b border-white/5 text-xs font-bold text-slate-400">${item.reason}</td>
                <td class="p-4 border-b border-white/5 text-right font-mono text-red-500 font-bold">
                    -${item.cost.toFixed(2)} PLN
                </td>
                <td class="p-4 border-b border-white/5 text-center">
                    <button onclick="removeItem(${index})" class="text-slate-600 hover:text-red-500 transition px-2">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </td>
            </tr>`;
    });

    document.getElementById('total_bleed').innerText = totalBleed.toFixed(2);
}

window.removeItem = function(index) {
    currentItems.splice(index, 1);
    renderTable();
}

window.saveDocument = async function() {
    const doc = document.getElementById('doc_number').value;
    const wid = document.getElementById('warehouse_id').value;
    const reqPin = document.getElementById('req_pin').checked;
    
    if (!doc) return alert('Wymagany numer dokumentu!');
    if (currentItems.length === 0) return alert('Brak towaru na liście zrzutu!');

    if(reqPin && totalBleed > 50) {
        const pin = prompt("STRATA PRZEKRACZA 50 PLN. Podaj PIN Kierownika:");
        if(pin !== "1234") return alert("Odmowa dostępu. Nieprawidłowy PIN.");
    }

    document.getElementById('loading-overlay').classList.remove('hidden');

    try {
        // Docelowy strzał - UWAGA NA 3 POZIOMY W GÓRĘ!
        // const res = await fetch('../../../api/api_inventory.php', { ... });
        
        setTimeout(() => {
            document.getElementById('loading-overlay').classList.add('hidden');
            alert(`✅ Zapisano pomyślnie. Towar zrzucony. Strata dla firmy: -${totalBleed.toFixed(2)} PLN`);
            currentItems = [];
            renderTable();
            document.getElementById('doc_number').value = '';
        }, 1000);

    } catch(e) {
        document.getElementById('loading-overlay').classList.add('hidden');
        console.error("Błąd zapisu:", e);
        alert("Wystąpił błąd komunikacji z Mózgiem systemu.");
    }
}