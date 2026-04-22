/**
 * SliceHub — SliceValidator (globalny walidator / konwerter jednostek).
 * Załaduj przed warehouse_*.js / studio_*.js.
 */
(function () {
    'use strict';

    const UNITS_CANON = {
        kg:   { base: 'kg', factor: 1 },
        g:    { base: 'kg', factor: 0.001 },
        dag:  { base: 'kg', factor: 0.01 },
        l:    { base: 'l',  factor: 1 },
        ml:   { base: 'l',  factor: 0.001 },
        szt:  { base: 'szt', factor: 1 },
        pcs:  { base: 'szt', factor: 1 },
        op:   { base: 'op',  factor: 1 },
    };

    function sanitizeKey(value) {
        return String(value || '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, '_')
            .replace(/[^A-Za-z0-9_/-]/g, '')
            .toUpperCase();
    }

    function standardizeUnit(qty, fromUnit) {
        const from = String(fromUnit || '').toLowerCase().trim();
        const map = UNITS_CANON[from];
        if (!map) return { value: qty, unit: from };
        return { value: qty * map.factor, unit: map.base };
    }

    function convertUnit(qty, fromUnit, toUnit) {
        const from = String(fromUnit || '').toLowerCase().trim();
        const to   = String(toUnit || '').toLowerCase().trim();
        const fMap = UNITS_CANON[from];
        const tMap = UNITS_CANON[to];
        if (!fMap || !tMap || fMap.base !== tMap.base) return null;
        return (qty * fMap.factor) / tMap.factor;
    }

    function validatePrice(val) {
        const n = parseFloat(val);
        return Number.isFinite(n) && n >= 0 ? n : null;
    }

    function generateSku(name) {
        return sanitizeKey(name).replace(/-/g, '_').slice(0, 32);
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    /**
     * Safe numeric parser — handles comma decimals, empty strings, NaN.
     * Returns a finite number or null.
     */
    function safeParse(raw) {
        if (raw == null || raw === '') return null;
        const n = parseFloat(String(raw).replace(',', '.'));
        return Number.isFinite(n) ? n : null;
    }

    /**
     * convert(qty, fromUnit, toUnit)
     *
     * High-level unit converter used by recipe / food-cost UI.
     * Parses user input defensively (comma→dot, NaN guard), converts via
     * UNITS_CANON, and returns a structured result safe for downstream math.
     *
     * @returns {{ success: boolean, value: number, msg?: string }}
     */
    function convert(qty, fromUnit, toUnit) {
        const parsed = safeParse(qty);
        if (parsed === null) {
            return { success: false, value: 0, msg: `Nieprawidłowa wartość liczbowa: "${qty}"` };
        }

        const from = String(fromUnit || '').toLowerCase().trim();
        const to   = String(toUnit   || '').toLowerCase().trim();

        if (from === to) {
            return { success: true, value: parsed };
        }

        const fMap = UNITS_CANON[from];
        const tMap = UNITS_CANON[to];

        if (!fMap || !tMap) {
            const unknown = !fMap ? from : to;
            return { success: false, value: 0, msg: `Nieznana jednostka: "${unknown}"` };
        }

        if (fMap.base !== tMap.base) {
            return { success: false, value: 0, msg: `Niezgodne grupy jednostek: "${from}" (${fMap.base}) → "${to}" (${tMap.base})` };
        }

        const result = (parsed * fMap.factor) / tMap.factor;
        return { success: true, value: result };
    }

    /**
     * validateRecipeRow(qty, usageUnit, baseUnit)
     *
     * Pre-flight check before adding / updating a recipe ingredient row.
     * Verifies that usageUnit can be converted to baseUnit.
     *
     * @returns {{ status: 'ok'|'error', msg?: string }}
     */
    function validateRecipeRow(qty, usageUnit, baseUnit) {
        const parsed = safeParse(qty);
        if (parsed === null || parsed < 0) {
            return { status: 'error', msg: `Nieprawidłowa ilość: "${qty}"` };
        }

        const from = String(usageUnit || '').toLowerCase().trim();
        const to   = String(baseUnit  || '').toLowerCase().trim();

        if (from === to) return { status: 'ok' };

        const fMap = UNITS_CANON[from];
        const tMap = UNITS_CANON[to];

        if (!fMap || !tMap || fMap.base !== tMap.base) {
            return { status: 'error', msg: `Niezgodność jednostek: "${from}" → "${to}"` };
        }

        return { status: 'ok' };
    }

    window.SliceValidator = Object.freeze({
        UNITS_CANON,
        sanitizeKey,
        standardizeUnit,
        convertUnit,
        convert,
        validateRecipeRow,
        safeParse,
        validatePrice,
        generateSku,
        escapeHtml,
    });
})();
