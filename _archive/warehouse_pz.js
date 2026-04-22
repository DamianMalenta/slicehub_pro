let pzItems = [];
let availableProducts = [];

document.addEventListener('DOMContentLoaded', function() {
    if(typeof SliceValidator === 'undefined') {
        console.error("[CRITICAL] Brak połączenia z core_validator.js!");
    }
    initPZ();
});

// 🧠 Inicjalizacja: Pobieranie magazynów i produktów
window.initPZ = async function() {
    try {
        const res = await fetch('../../../api/api_inventory.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_pz_init' }) // Wykorzystujemy stary endpoint init z IN
        }).then(r => r.json());

        if(res.status === 'success') {
            // Ładowanie Magazynów
            const whSelect = document.getElementById('pz-warehouse');
            if(whSelect) {
                whSelect.innerHTML = '<option value="">-- Wybierz Magazyn --</option>' + 
                    res.payload.warehouses.map(w => `<option value="${w.id}">${w.name}</option>`).join('');
            }
            
            // Zapisanie bazy produktów do auto-podpowiedzi (jeśli byśmy ich potrzebowali)
            availableProducts = res.payload.products;
            
            // Dodajemy pierwszy, pusty wiersz na start
            addRow();
        }
    } catch(e) {
        console.error("Błąd inicjalizacji PZ:", e);
    }
}

// Generowanie wiersza
window.addRow = function() {
    const tbody = document.getElementById('pz-items');
    const rowId = Date.now();
    
    // Budujemy opcje dla selecta z produktami (Mózg - Produkty Wewnętrzne)
    const productOptions = availableProducts.map(p => 
        `<option value="${p.id}" data-unit="${p.unit}">${p.name} [${p.unit}]</option>`
    ).join('');

    const tr = document.createElement('tr');
    tr.className = "group border-l-2 border-transparent hover:border-green-500 transition-colors duration-300";
    tr.id = `row-${rowId}`;
    
    tr.innerHTML = `
        <td class="py-4 pr-4">
            <input type="text" placeholder="Wpisz nazwę z faktury..." class="w-full bg-black/50 border border-white/5 rounded-lg p-3 text-xs text-slate-300 outline-none focus:border-green-500 transition invoice-name">
        </td>
        <td class="py-4 pr-4">
            <select class="w-full bg-black border border-white/10 rounded-lg p-3 text-xs font-bold text-green-400 outline-none focus:border-green-500 appearance-none transition system-product">
                <option value="">-- Przypisz do produktu systemowego --</option>
                ${productOptions}
            </select>
        </td>
        <td class="py-4 px-2 text-center">
            <input type="number" step="0.001" placeholder="0.00" class="w-24 bg-black border border-white/10 rounded-lg p-3 text-center text-white font-mono outline-none focus:border-green-500 transition invoice-qty">
        </td>
        <td class="py-4 px-2 text-center">
            <select class="bg-black border border-white/10 rounded-lg p-3 text-xs text-slate-400 outline-none focus:border-green-500 appearance-none text-center invoice-unit">
                <option value="szt">szt</option>
                <option value="kg">kg</option>
                <option value="g">g</option>
                <option value="l">l</option>
                <option value="ml">ml</option>
            </select>
        </td>
        <td class="py-4 text-right">
            <button onclick="removeRow(${rowId})" class="text-slate-600 hover:text-red-500 transition px-3 opacity-0 group-hover:opacity-100">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(tr);
}

window.removeRow = function(rowId) {
    const row = document.getElementById(`row-${rowId}`);
    if(row) row.remove();
}

window.savePZ = async function() {
    const docNum = document.getElementById('pz-number').value;
    const wid = document.getElementById('pz-warehouse').value;
    
    if(!docNum || !wid) return alert("Podaj numer faktury i wybierz magazyn!");

    const rows = document.querySelectorAll('#pz-items tr');
    let itemsToSave = [];
    let hasErrors = false;

    rows.forEach(row => {
        const extName = row.querySelector('.invoice-name').value;
        const sysProductId = row.querySelector('.system-product').value;
        const rawQty = parseFloat(row.querySelector('.invoice-qty').value);
        const inputUnit = row.querySelector('.invoice-unit').value;

        if(!sysProductId || isNaN(rawQty) || rawQty <= 0) {
            hasErrors = true;
            return; // Przeskakujemy błędny wiersz
        }

        // 🧠 UŻYCIE MÓZGU: Standaryzacja jednostek z faktury na systemowe
        let finalQty = rawQty;
        if(typeof SliceValidator !== 'undefined') {
            const conversion = SliceValidator.standardizeUnit(rawQty, inputUnit);
            finalQty = conversion.value;
        }

        itemsToSave.push({
            product_id: sysProductId,
            external_name: extName, // Do budowy słownika KSeF w locie
            quantity: finalQty
        });
    });

    if(hasErrors && itemsToSave.length === 0) {
        return alert("Wypełnij poprawnie przynajmniej jedną linię (Wybierz produkt i podaj ilość > 0).");
    }

    if(hasErrors) {
        if(!confirm("Część wierszy jest pusta lub ma błędną ilość. Czy zapisać tylko poprawne pozycje?")) return;
    }

    const btn = document.getElementById('btn-save');
    const originalText = btn.innerHTML;
    btn.innerHTML = `<i class="fa-solid fa-circle-notch fa-spin"></i> Zapisywanie...`;
    btn.disabled = true;

    try {
        const res = await fetch('../../../api/api_inventory.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_pz',
                doc_number: docNum,
                warehouse_id: wid,
                items: itemsToSave
            })
        }).then(r => r.json());

        btn.innerHTML = originalText;
        btn.disabled = false;

        if(res.status === 'success') {
            alert("✅ Dokument PZ zapisany poprawnie! Stany magazynowe zostały zasilone.");
            document.getElementById('pz-number').value = '';
            document.getElementById('pz-items').innerHTML = '';
            addRow(); // Resetujemy do 1 pustego wiersza
        } else {
            alert("Błąd zapisu: " + res.error);
        }

    } catch(e) {
        btn.innerHTML = originalText;
        btn.disabled = false;
        console.error("Błąd zapisu PZ:", e);
        alert("Wystąpił błąd komunikacji z Mózgiem systemu.");
    }
}