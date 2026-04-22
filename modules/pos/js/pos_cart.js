/**
 * SLICEHUB POS V2 — Client-Side Cart Engine
 * Mirrors the server CartEngine for instant UI feedback.
 * The server remains the single source of truth at checkout.
 */

const PosCart = (() => {
    let _lines = [];
    let _orderType = 'dine_in';
    let _channel = 'POS';
    let _halfSurchargeGrosze = 200;
    let _listeners = [];
    let _locked = false;
    let _editOrderId = null;

    // Table context — set by URL intent from table map, persists across cart clear
    let _tableId = null;
    let _tableNumber = null;
    let _guestCount = null;

    function _emit() {
        const snapshot = getSnapshot();
        _listeners.forEach(fn => fn(snapshot));
    }

    function subscribe(fn) {
        _listeners.push(fn);
        return () => { _listeners = _listeners.filter(f => f !== fn); };
    }

    function setOrderType(type) {
        _orderType = type;
        _channel = type === 'delivery' ? 'Delivery' : (type === 'takeaway' ? 'Takeaway' : 'POS');
        _emit();
    }

    function getOrderType() { return _orderType; }
    function getChannel() { return _channel; }

    function setHalfSurcharge(grosze) {
        _halfSurchargeGrosze = grosze;
    }

    function isLocked() { return _locked; }
    function setLocked(val) { _locked = !!val; }

    function getEditOrderId() { return _editOrderId; }
    function setEditOrderId(id) { _editOrderId = id || null; }

    function setTableContext(tableId, tableNumber, guestCount) {
        _tableId = tableId || null;
        _tableNumber = tableNumber || null;
        _guestCount = parseInt(guestCount) || null;
    }
    function getTableContext() {
        return { tableId: _tableId, tableNumber: _tableNumber, guestCount: _guestCount };
    }
    function clearTableContext() {
        _tableId = null;
        _tableNumber = null;
        _guestCount = null;
    }

    function _genLineId() {
        return `L${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
    }

    /**
     * @param {Object} item - { ascii_key, name, image_url, priceGrosze, vatDine, vatTake }
     * @param {number} qty
     * @param {Array} addedModifiers - [{ ascii_key, name, priceGrosze }]
     * @param {Array} removedIngredients - [{ sku, name }]
     * @param {string} comment
     */
    function addItem(item, qty = 1, addedModifiers = [], removedIngredients = [], comment = '') {
        if (_locked) return;
        const modTotal = addedModifiers.reduce((s, m) => s + (m.priceGrosze || 0), 0);
        const unitPriceGrosze = item.priceGrosze + modTotal;

        _lines.push({
            lineId: _genLineId(),
            isHalf: false,
            itemSku: item.ascii_key,
            snapshotName: item.name,
            imageUrl: item.image_url || '',
            basePriceGrosze: item.priceGrosze,
            addedModifiers,
            removedIngredients,
            unitPriceGrosze,
            quantity: qty,
            lineTotalGrosze: unitPriceGrosze * qty,
            vatRate: _orderType === 'dine_in' ? item.vatDine : item.vatTake,
            comment,
        });
        _emit();
    }

    function addHalf(itemA, itemB, qty = 1, addedModifiers = [], removedIngredients = [], comment = '') {
        if (_locked) return;
        const baseGrosze = Math.max(itemA.priceGrosze, itemB.priceGrosze) + _halfSurchargeGrosze;
        const modTotal = addedModifiers.reduce((s, m) => s + (m.priceGrosze || 0), 0);
        const unitPriceGrosze = baseGrosze + modTotal;

        _lines.push({
            lineId: _genLineId(),
            isHalf: true,
            halfASku: itemA.ascii_key,
            halfBSku: itemB.ascii_key,
            itemSku: `${itemA.ascii_key}+${itemB.ascii_key}`,
            snapshotName: `½ ${itemA.name} + ½ ${itemB.name}`,
            imageUrl: itemA.image_url || itemB.image_url || '',
            basePriceGrosze: baseGrosze,
            addedModifiers,
            removedIngredients,
            unitPriceGrosze,
            quantity: qty,
            lineTotalGrosze: unitPriceGrosze * qty,
            vatRate: _orderType === 'dine_in' ? itemA.vatDine : itemA.vatTake,
            comment,
        });
        _emit();
    }

    function updateLine(lineId, changes) {
        if (_locked) return;
        const line = _lines.find(l => l.lineId === lineId);
        if (!line) return;

        if (changes.quantity !== undefined) line.quantity = Math.max(1, changes.quantity);
        if (changes.comment !== undefined) line.comment = changes.comment;
        if (changes.addedModifiers !== undefined) line.addedModifiers = changes.addedModifiers;
        if (changes.removedIngredients !== undefined) line.removedIngredients = changes.removedIngredients;

        const modTotal = line.addedModifiers.reduce((s, m) => s + (m.priceGrosze || 0), 0);
        line.unitPriceGrosze = line.basePriceGrosze + modTotal;
        line.lineTotalGrosze = line.unitPriceGrosze * line.quantity;
        _emit();
    }

    function removeLine(lineId) {
        if (_locked) return;
        _lines = _lines.filter(l => l.lineId !== lineId);
        _emit();
    }

    function clear() {
        _lines = [];
        _locked = false;
        _editOrderId = null;
        _emit();
    }

    /**
     * Load cart state from a saved cart_json array (order editing).
     * Each entry: { ascii_key, name, price, qty, added, removed, comment, is_half, half_a, half_b }
     */
    function loadFromCartJson(cartData, orderId) {
        _lines = [];
        _editOrderId = orderId || null;

        if (!Array.isArray(cartData)) { _emit(); return; }

        cartData.forEach(item => {
            const mods = (item.added || []).map(a => {
                if (typeof a === 'object' && a !== null) return { ascii_key: a.ascii_key || a.sku || '', name: a.name || '', priceGrosze: Math.round(parseFloat(a.price || 0) * 100) };
                return { ascii_key: String(a), name: String(a), priceGrosze: 0 };
            });
            const removed = (item.removed || []).map(r => {
                if (typeof r === 'object' && r !== null) return { sku: r.sku || '', name: r.name || '' };
                return { sku: String(r), name: String(r) };
            });
            // item.price already includes modifiers — it's the final unit price
            const unitPriceGrosze = Math.round(parseFloat(item.price || 0) * 100);
            const modTotal = mods.reduce((s, m) => s + (m.priceGrosze || 0), 0);
            const baseGrosze = unitPriceGrosze - modTotal;
            const qty = item.qty || item.quantity || 1;

            if (item.is_half) {
                const nameA = (item.name || '').split('+')[0]?.replace('½', '').trim() || '';
                const nameB = (item.name || '').split('+')[1]?.replace('½', '').trim() || '';
                _lines.push({
                    lineId: _genLineId(), isHalf: true,
                    halfASku: item.half_a || '', halfBSku: item.half_b || '',
                    itemSku: `${item.half_a || ''}+${item.half_b || ''}`,
                    snapshotName: item.name || `½ ${nameA} + ½ ${nameB}`,
                    imageUrl: '', basePriceGrosze: baseGrosze,
                    addedModifiers: mods, removedIngredients: removed,
                    unitPriceGrosze, quantity: qty, lineTotalGrosze: unitPriceGrosze * qty,
                    vatRate: _orderType === 'dine_in' ? 8 : 5, comment: item.comment || '',
                });
            } else {
                _lines.push({
                    lineId: _genLineId(), isHalf: false,
                    itemSku: item.ascii_key || item.id || '',
                    snapshotName: item.name || '',
                    imageUrl: '', basePriceGrosze: baseGrosze,
                    addedModifiers: mods, removedIngredients: removed,
                    unitPriceGrosze, quantity: qty, lineTotalGrosze: unitPriceGrosze * qty,
                    vatRate: _orderType === 'dine_in' ? 8 : 5, comment: item.comment || '',
                });
            }
        });
        _emit();
    }

    /**
     * Load cart state from sh_order_lines (when cart_json is not available).
     */
    function loadFromOrderLines(lines, orderId) {
        _lines = [];
        _editOrderId = orderId || null;

        if (!Array.isArray(lines)) { _emit(); return; }

        lines.forEach(line => {
            const modsRaw = line.modifiers_json ? (typeof line.modifiers_json === 'string' ? JSON.parse(line.modifiers_json) : line.modifiers_json) : [];
            const removedRaw = line.removed_ingredients_json ? (typeof line.removed_ingredients_json === 'string' ? JSON.parse(line.removed_ingredients_json) : line.removed_ingredients_json) : [];

            const mods = (Array.isArray(modsRaw) ? modsRaw : []).map(m =>
                typeof m === 'object' ? { ascii_key: m.sku || m.ascii_key || '', name: m.name || '', priceGrosze: Math.round(parseFloat(m.price || 0) * 100) }
                    : { ascii_key: String(m), name: String(m), priceGrosze: 0 }
            );
            const removed = (Array.isArray(removedRaw) ? removedRaw : []).map(r =>
                typeof r === 'object' ? { sku: r.sku || '', name: r.name || '' }
                    : { sku: String(r), name: String(r) }
            );

            // unit_price in sh_order_lines already includes modifier prices
            const unitPriceGrosze = parseInt(line.unit_price || 0);
            const modTotal = mods.reduce((s, m) => s + (m.priceGrosze || 0), 0);
            const baseGrosze = unitPriceGrosze - modTotal;
            const qty = parseInt(line.quantity || 1);

            _lines.push({
                lineId: _genLineId(), isHalf: false,
                itemSku: line.item_sku || '',
                snapshotName: line.snapshot_name || '',
                imageUrl: '', basePriceGrosze: baseGrosze,
                addedModifiers: mods, removedIngredients: removed,
                unitPriceGrosze, quantity: qty, lineTotalGrosze: unitPriceGrosze * qty,
                vatRate: _orderType === 'dine_in' ? 8 : 5, comment: line.comment || '',
            });
        });
        _emit();
    }

    function getLines() {
        return [..._lines];
    }

    function getSnapshot() {
        const subtotalGrosze = _lines.reduce((s, l) => s + l.lineTotalGrosze, 0);
        return {
            lines: [..._lines],
            lineCount: _lines.length,
            itemCount: _lines.reduce((s, l) => s + l.quantity, 0),
            subtotalGrosze,
            subtotalFormatted: (subtotalGrosze / 100).toFixed(2),
            orderType: _orderType,
            channel: _channel,
            locked: _locked,
            editOrderId: _editOrderId,
            tableId: _tableId,
            tableNumber: _tableNumber,
            guestCount: _guestCount,
        };
    }

    function toApiPayload() {
        return {
            channel: _channel,
            order_type: _orderType,
            lines: _lines.map(l => {
                const apiLine = {
                    line_id: l.lineId,
                    item_sku: l.isHalf ? '' : l.itemSku,
                    quantity: l.quantity,
                    added_modifier_skus: l.addedModifiers.map(m => m.ascii_key),
                    removed_ingredient_skus: l.removedIngredients.map(r => r.sku),
                    comment: l.comment,
                    is_half: l.isHalf,
                };
                if (l.isHalf) {
                    apiLine.half_a_sku = l.halfASku;
                    apiLine.half_b_sku = l.halfBSku;
                }
                return apiLine;
            }),
        };
    }

    return Object.freeze({
        subscribe, setOrderType, getOrderType, getChannel,
        setHalfSurcharge,
        isLocked, setLocked, getEditOrderId, setEditOrderId,
        setTableContext, getTableContext, clearTableContext,
        addItem, addHalf, updateLine, removeLine, clear,
        loadFromCartJson, loadFromOrderLines,
        getLines, getSnapshot, toApiPayload,
    });
})();

export default PosCart;
