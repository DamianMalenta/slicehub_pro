/**
 * SLICEHUB ENTERPRISE — Margin Guardian
 * Moduł: Food Cost & Gross Margin Calculator
 *
 * Zależności:
 *   - WarehouseApi.avcoDict() → api/warehouse/avco_dict.php
 *
 * Użycie:
 *   await window.MarginGuardian.init();
 *   const results = window.MarginGuardian.calculate(priceTiers, ingredients);
 *   window.MarginGuardian.render('containerId', results);
 */

window.MarginGuardian = (() => {

    // -------------------------------------------------------------------------
    // Stan wewnętrzny
    // -------------------------------------------------------------------------
    const _state = {
        avcoDict:    {},   // { "SKU": avco_price (float) }
        initialized: false
    };

    // -------------------------------------------------------------------------
    // Formatters
    // -------------------------------------------------------------------------
    const _currency = new Intl.NumberFormat('pl-PL', {
        style:    'currency',
        currency: 'PLN',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const _pct = (n) => `${n.toFixed(1)} %`;

    // -------------------------------------------------------------------------
    // 1. INIT — pobiera słownik AVCO z magazynu
    // -------------------------------------------------------------------------
    async function init() {
        const json = await window.WarehouseApi.avcoDict();

        if (!json.success) {
            console.error('[MarginGuardian] API error:', json.message);
            return false;
        }

        // Normalizacja: upewnij się, że wszystkie wartości są liczbami float
        const raw = json.data ?? {};
        _state.avcoDict = Object.fromEntries(
            Object.entries(raw).map(([sku, price]) => [sku, parseFloat(price) || 0])
        );

        _state.initialized = true;
        return true;
    }

    // -------------------------------------------------------------------------
    // 2. CALCULATE — silnik FC / Margin
    //
    // @param {Array} priceTiers  [{channel: 'POS', price: 35.00, vatRate: 8}, ...]
    //   price    — cena BRUTTO (z VAT) widoczna dla klienta
    //   vatRate  — stawka VAT w % (np. 8 dla 8%)
    //
    // @param {Array} ingredients [{warehouseSku: 'SKU_SER', quantityBase: 0.15, wastePercent: 5}, ...]
    //   quantityBase — ilość bazowa (bez odpadów)
    //   wastePercent — procent odpadu (np. 5 = +5% surowca w zakupie)
    //
    // @returns {{ totalCost: number, channels: Array }}
    // -------------------------------------------------------------------------
    function calculate(priceTiers, ingredients) {
        if (!Array.isArray(priceTiers))   priceTiers  = [];
        if (!Array.isArray(ingredients))  ingredients = [];

        // --- Koszt surowców z korektą odpadu i konwersją jednostek ---
        // Użytkownik może podać ilość w jednostce użytkowej (np. g), podczas gdy AVCO
        // magazynowe jest wyrażone w jednostce bazowej (np. kg). Konwersja jest obowiązkowa.
        const totalCost = ingredients.reduce((sum, ing) => {
            const unitAvco  = _state.avcoDict[ing.warehouseSku] ?? 0;
            const rawQty    = parseFloat(ing.quantityBase) || 0;
            const wastePct  = parseFloat(ing.wastePercent) || 0;

            const targetBaseUnit = ing.baseUnit || 'kg';
            const usageUnit      = ing.unit || targetBaseUnit;
            const conversion     = (typeof window.SliceValidator !== 'undefined')
                ? window.SliceValidator.convert(rawQty, usageUnit, targetBaseUnit)
                : { success: true, value: rawQty };

            let actualQtyInBaseUnits = 0;
            if (conversion.success) {
                actualQtyInBaseUnits = conversion.value;
            } else {
                console.warn(
                    `[SliceHub Math] Unit mismatch or error for ${ing.warehouseSku}:`,
                    conversion.msg || conversion.error
                );
                // Fallback to 0 — prevents catastrophic Food Cost inflation
                // (e.g. 250 liters-of-cheese multiplying raw AVCO/kg price).
            }

            const qtyWithWaste = actualQtyInBaseUnits * (1 + wastePct / 100);
            return sum + (qtyWithWaste * unitAvco);
        }, 0);

        // --- Obliczenia per-kanał z normalizacją VAT ---
        // Ceny w priceTiers są BRUTTO. Marża i FC% muszą być liczone na cenach NETTO.
        const channels = priceTiers.map(tier => {
            const grossPrice = parseFloat(tier.price)   || 0;
            const vatRate    = parseFloat(tier.vatRate) || 0;

            // Ekstrakcja ceny netto z ceny brutto
            const netPrice = grossPrice > 0
                ? grossPrice / (1 + vatRate / 100)
                : 0;

            if (netPrice <= 0) {
                return {
                    channel:         tier.channel ?? '—',
                    grossPrice,
                    netPrice:        0,
                    vatRate,
                    grossProfit:     -totalCost,
                    foodCostPercent: null,   // Dzielenie przez zero — brak sensu kalkulacyjnego
                };
            }

            const grossProfit     = netPrice - totalCost;
            const foodCostPercent = (totalCost / netPrice) * 100;

            return {
                channel: tier.channel ?? '—',
                grossPrice,
                netPrice,
                vatRate,
                grossProfit,
                foodCostPercent,
            };
        });

        return { totalCost, channels };
    }

    // -------------------------------------------------------------------------
    // 3. RENDER — wstrzykuje wyniki do kontenera DOM
    //
    // @param {string} containerId  — id elementu DOM
    // @param {{ totalCost, channels }} results — wynik z calculate()
    // -------------------------------------------------------------------------
    function render(containerId, results) {
        const container = document.getElementById(containerId);
        if (!container) {
            console.warn(`[MarginGuardian] Kontener #${containerId} nie istnieje.`);
            return;
        }

        if (!results || typeof results !== 'object') {
            container.innerHTML = _emptyState('Brak danych do wyświetlenia.');
            return;
        }

        const { totalCost = 0, channels = [] } = results;

        // Budujemy wiersze per-kanał
        const channelRows = channels.length
            ? channels.map(ch => _channelRow(ch)).join('')
            : `<div class="col-span-4 text-center text-gray-600 text-sm py-4">Brak zdefiniowanych kanałów sprzedaży.</div>`;

        container.innerHTML = `
            <div class="bg-[#12121a] border border-gray-800 rounded-2xl overflow-hidden shadow-2xl">

                <!-- Nagłówek modułu -->
                <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-800 bg-black/30 backdrop-blur">
                    <svg class="w-5 h-5 text-violet-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <h3 class="text-sm font-semibold text-gray-200 uppercase tracking-widest">Margin Guardian</h3>
                </div>

                <!-- Koszt Produkcji -->
                <div class="px-6 py-5 border-b border-gray-800/60">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Koszt Surowców (AVCO)</span>
                        <span class="text-2xl font-bold text-white tabular-nums">${_currency.format(totalCost)}</span>
                    </div>
                    ${!_state.initialized ? `
                    <div class="mt-2 flex items-center gap-2 text-xs text-yellow-500/80">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Słownik AVCO niezainicjowany — koszty mogą być zerowe.
                    </div>` : ''}
                </div>

                <!-- Nagłówki tabeli kanałów -->
                <div class="grid grid-cols-4 gap-4 px-6 py-3 border-b border-gray-800/60 bg-black/20">
                    <div class="text-xs font-semibold text-gray-600 uppercase tracking-wider">Kanał</div>
                    <div class="text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Cena Sprzedaży</div>
                    <div class="text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Marża Brutto</div>
                    <div class="text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Food Cost %</div>
                </div>

                <!-- Wiersze kanałów -->
                <div class="divide-y divide-gray-800/40">
                    ${channelRows}
                </div>

            </div>
        `;
    }

    // -------------------------------------------------------------------------
    // Helpers prywatne
    // -------------------------------------------------------------------------

    /** Buduje wiersz HTML dla pojedynczego kanału. */
    function _channelRow(ch) {
        // Wyświetlamy cenę brutto (dla klienta) z subtelną adnotacją ceny netto (podstawa marży)
        const grossPriceStr  = _currency.format(ch.grossPrice);
        const netPriceStr    = _currency.format(ch.netPrice);
        const grossProfitStr = _currency.format(ch.grossProfit);
        const vatLabel       = ch.vatRate > 0 ? `VAT ${ch.vatRate}%` : 'zw. VAT';

        const grossProfitClass = ch.grossProfit >= 0 ? 'text-emerald-400' : 'text-red-500 font-bold';

        let fcBlock;
        if (ch.foodCostPercent === null) {
            fcBlock = `<span class="text-gray-600 text-xs italic">N/D (cena = 0)</span>`;
        } else {
            const fcClass = ch.foodCostPercent > 30 ? 'text-red-500 font-bold' : 'text-emerald-400 font-semibold';
            fcBlock = `<span class="${fcClass} tabular-nums">${_pct(ch.foodCostPercent)}</span>`;
        }

        return `
            <div class="grid grid-cols-4 gap-4 px-6 py-4 hover:bg-white/[0.02] transition-colors">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-violet-500/60 shrink-0"></span>
                    <span class="text-sm text-gray-300 font-medium">${_escHtml(String(ch.channel))}</span>
                </div>
                <div class="text-right">
                    <div class="text-sm text-gray-200 tabular-nums">${grossPriceStr}</div>
                    <div class="text-[10px] text-gray-600 tabular-nums mt-0.5">netto: ${netPriceStr} <span class="text-gray-700">(${_escHtml(vatLabel)})</span></div>
                </div>
                <div class="text-sm ${grossProfitClass} tabular-nums text-right">${grossProfitStr}</div>
                <div class="text-sm text-right">${fcBlock}</div>
            </div>
        `;
    }

    /** Stan pusty / błąd. */
    function _emptyState(message) {
        return `
            <div class="bg-[#12121a] border border-gray-800 rounded-2xl px-6 py-10 text-center text-gray-600 text-sm">
                ${_escHtml(message)}
            </div>
        `;
    }

    /** Escapuje tekst przed wstawieniem do innerHTML. */
    function _escHtml(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    // -------------------------------------------------------------------------
    // 4. BIND REACTIVITY — Event Delegation
    //
    // Zamiast bindować eventy bezpośrednio na inputach (które mogą być dodawane
    // dynamicznie do DOM), nasłuchujemy na stałych kontenerach-rodzicach.
    // Jeden listener obsługuje cały dynamiczny formularz.
    //
    // @param {Function} updateFn  — callback wywoływany po zmianie; typowo
    //                               updateMarginUI() zdefiniowana w pliku hosta.
    // @param {Object}   [opts]
    //   containerIds {string[]}  — id kontenerów do obserwacji
    //                              (domyślnie: recipe-container + price-matrix-container)
    //   debounceMs   {number}    — opóźnienie debounce w ms (domyślnie: 300)
    // -------------------------------------------------------------------------
    function bindReactivity(updateFn, opts = {}) {
        if (typeof updateFn !== 'function') {
            console.error('[MarginGuardian] bindReactivity: updateFn musi być funkcją.');
            return;
        }

        const containerIds = opts.containerIds ?? ['recipe-container', 'price-matrix-container'];
        const debounceMs   = opts.debounceMs   ?? 300;

        // Debounce — zapobiega lawinowym przeliczeniom podczas szybkiego pisania
        const debouncedUpdate = _debounce(updateFn, debounceMs);

        containerIds.forEach(id => {
            const el = document.getElementById(id);
            if (!el) {
                console.warn(`[MarginGuardian] bindReactivity: kontener #${id} nie istnieje — listener pominięty.`);
                return;
            }

            // Jeden listener na kontener; event delegation przez e.target
            el.addEventListener('input', (e) => {
                if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT')) {
                    debouncedUpdate(e);
                }
            });
        });
    }

    /** Klasyczny debounce — opóźnia wywołanie fn do czasu, gdy przez debounceMs nie zajdzie żadne nowe zdarzenie. */
    function _debounce(fn, ms) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------
    return {
        /** Słownik AVCO (readonly dla konsumentów zewnętrznych) */
        get avcoDict() { return { ..._state.avcoDict }; },

        /** Czy init() zakończył się sukcesem */
        get initialized() { return _state.initialized; },

        init,
        calculate,
        render,
        bindReactivity,
    };

})();
