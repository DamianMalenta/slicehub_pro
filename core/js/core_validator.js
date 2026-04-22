/**
 * 🛡️ SLICEHUB CORE VALIDATOR v1.0 (Ultimate)
 * Cel: Jedno źródło prawdy dla konwersji jednostek i czystości danych (ASCII).
 */

const SliceValidator = {
    // 1. Twardy słownik jednostek bazowych
    units: {
        'kg':  { type: 'weight', ratio: 1,      label: 'Kilogram' },
        'g':   { type: 'weight', ratio: 0.001,  label: 'Gram' },
        'l':   { type: 'volume', ratio: 1,      label: 'Litr' },
        'ml':  { type: 'volume', ratio: 0.001,  label: 'Mililitr' },
        'szt': { type: 'count',  ratio: 1,      label: 'Sztuka' },
        'por': { type: 'count',  ratio: 1,      label: 'Porcja' },
        'opak':{ type: 'count',  ratio: 1,      label: 'Opakowanie' } // Gotowe pod KSeF
    },

    // 2. Strażnik ASCII - Zamienia "Mąka pszenna" na "MAKA_PSZENNA" do celów technicznych
    sanitizeKey: function(str) {
        if (typeof str !== 'string') return '';
        return str.normalize("NFD")
                  .replace(/[\u0300-\u036f]/g, "") // Usuwanie polskich znaków (diakrytyków)
                  .replace(/\s+/g, '_')            // Spacje na podkreślenia
                  .replace(/[^a-zA-Z0-9_]/g, '')   // Usuwanie znaków specjalnych
                  .toUpperCase();
    },

    // 3. Inteligentny Konwerter Jednostek
    convert: function(value, fromUnit, toUnit) {
        const val = parseFloat(value.toString().replace(',', '.'));
        if (isNaN(val) || val < 0) return { error: 'Nieprawidłowa wartość liczbowa.' };

        const unitFrom = this.units[fromUnit.toLowerCase()];
        const unitTo   = this.units[toUnit.toLowerCase()];

        // Flaga do Szybkiej Naprawy (Inline Fix) jeśli jednostki nie ma w słowniku
        if (!unitFrom || !unitTo) {
            return { 
                needsFix: true, 
                msg: `Brak przelicznika dla: ${!unitFrom ? fromUnit : toUnit}`,
                originalValue: val 
            };
        }

        // Blokada mieszania typów (np. waga na sztuki) bez dodatkowego przelicznika
        if (unitFrom.type !== unitTo.type) {
            return { 
                error: 'Konflikt typów', 
                msg: `Nie można bezpośrednio przeliczyć ${unitFrom.type} na ${unitTo.type}. Wymagany przelicznik dedykowany.` 
            };
        }

        // Operacja matematyczna sprowadzająca do bazy
        const baseValue = val * unitFrom.ratio;
        const finalValue = baseValue / unitTo.ratio;

        return { 
            success: true, 
            value: finalValue, 
            baseValue: baseValue,
            formatted: `${finalValue.toFixed(3)} ${toUnit}`
        };
    },

    // 4. Moduł Receptur (Strażnik do studio_recipe.js)
    validateRecipeRow: function(qty, userUnit, stockUnit) {
        const result = this.convert(qty, userUnit, stockUnit);
        
        if (result.needsFix) {
            console.warn("🚨 [Core Validator] Wykryto nieznaną jednostkę. Gotowość do wywołania Inline Fix.");
            return { status: 'fix_required', data: result };
        }

        if (result.error) {
            console.error("🚨 [Core Validator] Błąd krytyczny: ", result.msg);
            return { status: 'error', msg: result.msg };
        }

        return { status: 'ok', value: result.value };
    }
};

// Zabezpieczenie przed nadpisaniem logiki przez inne skrypty
Object.freeze(SliceValidator);