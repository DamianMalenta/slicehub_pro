/**
 * SLICEHUB POS V2 — UI Rendering Engine
 * All rendering: battlefield, pulse, fleet, dish card, checkout, payment, cancel, print.
 */
const PosUI = (() => {
    const $ = sel => document.querySelector(sel);
    const $$ = sel => document.querySelectorAll(sel);

    const _e = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

    // === TOAST ===
    function toast(msg, type = 'info') {
        const c = $('#toast-container'); if (!c) return;
        const el = document.createElement('div');
        const bg = { success:'#22c55e', error:'#ef4444', info:'#3b82f6', warn:'#eab308' }[type] || '#3b82f6';
        el.className = 'pos-toast';
        Object.assign(el.style, { background: bg, color:'#fff', padding:'10px 20px', borderRadius:'10px', fontWeight:'800', fontSize:'11px', textTransform:'uppercase', letterSpacing:'0.05em', transform:'translateX(120%)', transition:'transform 0.3s', pointerEvents:'auto', boxShadow:`0 4px 20px ${bg}44` });
        el.textContent = msg;
        c.appendChild(el);
        requestAnimationFrame(() => el.style.transform = 'translateX(0)');
        setTimeout(() => { el.style.transform = 'translateX(120%)'; setTimeout(() => el.remove(), 300); }, 3000);
    }

    // === PIN SCREEN ===
    function renderPinScreen(onSubmit) {
        const screen = $('#pin-screen'); if (!screen) return;
        screen.classList.remove('hidden');
        $('#pos-app').classList.add('hidden');
        let pin = '';
        function updateDots() {
            const dots = $('#pin-dots'); if (!dots) return;
            dots.innerHTML = Array.from({length:4}, (_, i) => `<div class="pin-dot ${i < pin.length ? 'filled' : ''}"></div>`).join('');
        }
        updateDots();
        window._pinHandler = (val) => {
            if (val === 'clear') { pin = ''; updateDots(); return; }
            if (val === 'back') { pin = pin.slice(0, -1); updateDots(); return; }
            if (pin.length >= 4) return;
            pin += val; updateDots();
            if (pin.length === 4) { onSubmit(pin); pin = ''; setTimeout(updateDots, 500); }
        };
    }
    function hidePinScreen() { const s = $('#pin-screen'); if (s) s.classList.add('hidden'); $('#pos-app').classList.remove('hidden'); }
    function renderUserBadge(user) { const b = $('#user-badge'); if (b && user) b.textContent = user.name || user.role || 'POS'; }

    // === CATEGORIES ===
    function renderCategories(categories, activeId, onClick) {
        const c = $('#cat-tabs'); if (!c) return;
        c.innerHTML = categories.map(cat => `<button class="cat-tab ${cat.id === activeId ? 'active' : ''}" data-id="${cat.id}">${_e(cat.name)}</button>`).join('');
        c.querySelectorAll('.cat-tab').forEach(btn => btn.addEventListener('click', () => onClick(parseInt(btn.dataset.id))));
    }

    // === ITEM GRID ===
    function renderItemGrid(items, onClick) {
        const grid = $('#item-grid'); if (!grid) return;
        if (!items.length) { grid.innerHTML = '<div class="empty-state">Brak produktów w tej kategorii</div>'; return; }
        grid.innerHTML = items.map(item => {
            const price = (item.priceGrosze / 100).toFixed(2);
            const hasImg = item.image_url && item.image_url.trim();
            const safeUrl = hasImg ? encodeURI(item.image_url).replace(/'/g, '%27') : '';
            const bgStyle = hasImg ? `background-image:url('${safeUrl}');background-size:cover;background-position:center;` : '';
            const initials = item.name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
            return `<div class="item-tile ${hasImg ? '' : 'no-image'}" data-sku="${_e(item.ascii_key)}"><div class="item-tile-bg" style="${bgStyle}">${!hasImg ? `<span class="item-initials">${_e(initials)}</span>` : ''}</div><div class="item-tile-info"><span class="item-tile-name">${_e(item.name)}</span><span class="item-tile-price">${price} zł</span></div></div>`;
        }).join('');
        grid.querySelectorAll('.item-tile').forEach(tile => {
            tile.addEventListener('click', () => { const it = items.find(i => i.ascii_key === tile.dataset.sku); if (it) onClick(it); });
        });
    }

    // === CART ===
    function renderCart(snapshot, callbacks) {
        const list = $('#cart-lines'), total = $('#cart-total'), count = $('#cart-count'), btn = $('#btn-checkout');
        if (!list) return;
        if (count) count.textContent = snapshot.itemCount;
        if (total) total.textContent = `${snapshot.subtotalFormatted} zł`;
        if (btn) btn.disabled = snapshot.lineCount === 0;

        if (snapshot.lineCount === 0) {
            list.innerHTML = '<div class="cart-empty"><p>Koszyk pusty</p></div>'; return;
        }
        list.innerHTML = snapshot.lines.map(line => {
            const lTotal = (line.lineTotalGrosze / 100).toFixed(2);
            const mods = line.addedModifiers.map(m => `<span class="cart-mod">+ ${m.name}</span>`).join('');
            const rem = line.removedIngredients.map(r => `<span class="cart-removal">- ${r.name}</span>`).join('');
            const com = line.comment ? `<span class="cart-comment">${line.comment}</span>` : '';
            return `<div class="cart-line" data-line-id="${line.lineId}"><div class="cart-line-main"><div class="cart-line-info"><span class="cart-line-name">${line.snapshotName}</span><div class="cart-line-details">${mods}${rem}${com}</div></div><div class="cart-line-right"><span class="cart-line-total">${lTotal} zł</span><div class="cart-line-qty"><button class="qty-btn qty-minus" data-line="${line.lineId}">−</button><span class="qty-val">${line.quantity}</span><button class="qty-btn qty-plus" data-line="${line.lineId}">+</button></div></div></div><button class="cart-line-remove" data-line="${line.lineId}" title="Usuń">✕</button></div>`;
        }).join('');

        list.querySelectorAll('.qty-minus').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); const l = snapshot.lines.find(x => x.lineId === b.dataset.line); if (l && l.quantity > 1) callbacks.onQtyChange(b.dataset.line, l.quantity - 1); else if (l) callbacks.onRemove(b.dataset.line); }));
        list.querySelectorAll('.qty-plus').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); const l = snapshot.lines.find(x => x.lineId === b.dataset.line); if (l) callbacks.onQtyChange(b.dataset.line, l.quantity + 1); }));
        list.querySelectorAll('.cart-line-remove').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); callbacks.onRemove(b.dataset.line); }));
        list.querySelectorAll('.cart-line').forEach(el => el.addEventListener('click', () => callbacks.onLineClick(el.dataset.lineId)));
    }

    // === DISH CARD MODAL ===
    function showDishCard(item, ingredients, modifierGroups, onSave, onClose) {
        const modal = $('#dish-card-modal'); if (!modal) return;
        const price = (item.priceGrosze / 100).toFixed(2);
        const hasImg = item.image_url && item.image_url.trim();

        let ingHtml = ingredients.length ? `<div class="dc-section"><h4 class="dc-section-title">Składniki <span class="dc-hint">(kliknij aby usunąć)</span></h4><div class="dc-ingredients">${ingredients.map(i => `<button class="dc-ing" data-sku="${_e(i.sku)}" data-name="${_e(i.name)}">${_e(i.name)}</button>`).join('')}</div></div>` : '';

        let modHtml = (modifierGroups || []).map(grp => `<div class="dc-section"><h4 class="dc-section-title">${_e(grp.name)} ${grp.min_selection > 0 ? '<span class="dc-required">wymagane</span>' : ''}</h4><div class="dc-modifiers">${(grp.modifiers || []).map(m => { const mp = (m.priceGrosze / 100).toFixed(2); return `<label class="dc-mod"><input type="checkbox" value="${_e(m.ascii_key)}" data-name="${_e(m.name)}" data-price="${m.priceGrosze}"><span class="dc-mod-label">${_e(m.name)}</span>${m.priceGrosze > 0 ? `<span class="dc-mod-price">+${mp} zł</span>` : ''}</label>`; }).join('')}</div></div>`).join('');

        const heroUrl = hasImg ? encodeURI(item.image_url).replace(/'/g, '%27') : '';
        modal.innerHTML = `<div class="dc-backdrop" data-close="1"></div><div class="dc-card">${hasImg ? `<div class="dc-hero" style="background-image:url('${heroUrl}')"></div>` : ''}<div class="dc-body"><div class="dc-header"><div><h3 class="dc-name">${_e(item.name)}</h3><span class="dc-price" id="dc-live-price">${price} zł</span></div><button class="dc-close" data-close="1">✕</button></div>${ingHtml}${modHtml}<div class="dc-section"><h4 class="dc-section-title">Komentarz</h4><textarea class="dc-comment" id="dc-comment" placeholder="Np. dobrze wypieczony..." rows="2"></textarea></div><div class="dc-footer"><div class="dc-qty-control"><button class="dc-qty-btn" id="dc-qty-minus">−</button><span class="dc-qty-val" id="dc-qty">1</span><button class="dc-qty-btn" id="dc-qty-plus">+</button></div><button class="dc-add-btn" id="dc-save">Dodaj do koszyka <span id="dc-total-price">${price} zł</span></button></div></div></div>`;
        modal.classList.add('active');

        let qty = 1, removedSet = new Set(), addedMods = [];
        const baseGrosze = item.priceGrosze;

        function updatePrice() {
            const modT = addedMods.reduce((s, m) => s + m.priceGrosze, 0);
            const u = baseGrosze + modT, t = u * qty;
            const lp = modal.querySelector('#dc-live-price'), tp = modal.querySelector('#dc-total-price'), qe = modal.querySelector('#dc-qty');
            if (lp) lp.textContent = (u / 100).toFixed(2) + ' zł';
            if (tp) tp.textContent = (t / 100).toFixed(2) + ' zł';
            if (qe) qe.textContent = qty;
        }

        modal.querySelector('#dc-qty-minus')?.addEventListener('click', () => { qty = Math.max(1, qty - 1); updatePrice(); });
        modal.querySelector('#dc-qty-plus')?.addEventListener('click', () => { qty++; updatePrice(); });
        modal.querySelectorAll('.dc-ing').forEach(b => b.addEventListener('click', () => { const s = b.dataset.sku; if (removedSet.has(s)) { removedSet.delete(s); b.classList.remove('removed'); } else { removedSet.add(s); b.classList.add('removed'); } }));
        modal.querySelectorAll('.dc-mod input').forEach(cb => cb.addEventListener('change', () => { if (cb.checked) addedMods.push({ ascii_key: cb.value, name: cb.dataset.name, priceGrosze: parseInt(cb.dataset.price) || 0 }); else addedMods = addedMods.filter(m => m.ascii_key !== cb.value); updatePrice(); }));
        modal.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', e => { if (e.target === el || e.currentTarget === el) { modal.classList.remove('active'); if (onClose) onClose(); } }));
        modal.querySelector('#dc-save')?.addEventListener('click', () => {
            const comment = modal.querySelector('#dc-comment')?.value?.trim() || '';
            const removed = [...removedSet].map(sku => { const ing = ingredients.find(i => i.sku === sku); return { sku, name: ing ? ing.name : sku }; });
            onSave({ quantity: qty, addedModifiers: addedMods, removedIngredients: removed, comment });
            modal.classList.remove('active');
        });
    }

    // === DISH CARD EDIT (pre-filled for cart line editing) ===
    function showDishCardEdit(item, ingredients, modifierGroups, existingLine, onSave) {
        const modal = $('#dish-card-modal'); if (!modal) return;
        const baseGrosze = item.priceGrosze;
        let qty = existingLine.quantity || 1;
        let removedSet = new Set(existingLine.removedIngredients.map(r => r.sku));
        let addedMods = [...existingLine.addedModifiers];

        function calcPrices() {
            const modT = addedMods.reduce((s, m) => s + m.priceGrosze, 0);
            return { unit: baseGrosze + modT, total: (baseGrosze + modT) * qty };
        }

        const prices = calcPrices();
        const unitStr = (prices.unit / 100).toFixed(2);
        const hasImg = item.image_url && item.image_url.trim();

        let ingHtml = ingredients.length ? `<div class="dc-section"><h4 class="dc-section-title">Składniki <span class="dc-hint">(kliknij aby usunąć)</span></h4><div class="dc-ingredients">${ingredients.map(i => `<button class="dc-ing ${removedSet.has(i.sku) ? 'removed' : ''}" data-sku="${_e(i.sku)}" data-name="${_e(i.name)}">${_e(i.name)}</button>`).join('')}</div></div>` : '';

        let modHtml = (modifierGroups || []).map(grp => `<div class="dc-section"><h4 class="dc-section-title">${_e(grp.name)}</h4><div class="dc-modifiers">${(grp.modifiers || []).map(m => { const mp = (m.priceGrosze / 100).toFixed(2); const checked = addedMods.some(am => am.ascii_key === m.ascii_key) ? 'checked' : ''; return `<label class="dc-mod"><input type="checkbox" value="${_e(m.ascii_key)}" data-name="${_e(m.name)}" data-price="${m.priceGrosze}" ${checked}><span class="dc-mod-label">${_e(m.name)}</span>${m.priceGrosze > 0 ? `<span class="dc-mod-price">+${mp} zł</span>` : ''}</label>`; }).join('')}</div></div>`).join('');

        const heroUrl2 = hasImg ? encodeURI(item.image_url).replace(/'/g, '%27') : '';
        modal.innerHTML = `<div class="dc-backdrop" data-close="1"></div><div class="dc-card">${hasImg ? `<div class="dc-hero" style="background-image:url('${heroUrl2}')"></div>` : ''}<div class="dc-body"><div class="dc-header"><div><h3 class="dc-name">${_e(item.name)}</h3><span class="dc-price" id="dc-live-price">${unitStr} zł</span></div><button class="dc-close" data-close="1">✕</button></div>${ingHtml}${modHtml}<div class="dc-section"><h4 class="dc-section-title">Komentarz</h4><textarea class="dc-comment" id="dc-comment" placeholder="Np. dobrze wypieczony..." rows="2">${_e(existingLine.comment || '')}</textarea></div><div class="dc-footer"><div class="dc-qty-control"><button class="dc-qty-btn" id="dc-qty-minus">−</button><span class="dc-qty-val" id="dc-qty">${qty}</span><button class="dc-qty-btn" id="dc-qty-plus">+</button></div><button class="dc-add-btn" id="dc-save">Zapisz zmiany <span id="dc-total-price">${(prices.total / 100).toFixed(2)} zł</span></button></div></div></div>`;
        modal.classList.add('active');

        function updatePrice() {
            const p = calcPrices();
            const lp = modal.querySelector('#dc-live-price'), tp = modal.querySelector('#dc-total-price'), qe = modal.querySelector('#dc-qty');
            if (lp) lp.textContent = (p.unit / 100).toFixed(2) + ' zł';
            if (tp) tp.textContent = (p.total / 100).toFixed(2) + ' zł';
            if (qe) qe.textContent = qty;
        }

        modal.querySelector('#dc-qty-minus')?.addEventListener('click', () => { qty = Math.max(1, qty - 1); updatePrice(); });
        modal.querySelector('#dc-qty-plus')?.addEventListener('click', () => { qty++; updatePrice(); });
        modal.querySelectorAll('.dc-ing').forEach(b => b.addEventListener('click', () => { const s = b.dataset.sku; if (removedSet.has(s)) { removedSet.delete(s); b.classList.remove('removed'); } else { removedSet.add(s); b.classList.add('removed'); } }));
        modal.querySelectorAll('.dc-mod input').forEach(cb => cb.addEventListener('change', () => { if (cb.checked) addedMods.push({ ascii_key: cb.value, name: cb.dataset.name, priceGrosze: parseInt(cb.dataset.price) || 0 }); else addedMods = addedMods.filter(m => m.ascii_key !== cb.value); updatePrice(); }));
        modal.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', e => { if (e.target === el || e.currentTarget === el) modal.classList.remove('active'); }));
        modal.querySelector('#dc-save')?.addEventListener('click', () => {
            const comment = modal.querySelector('#dc-comment')?.value?.trim() || '';
            const removed = [...removedSet].map(sku => { const ing = ingredients.find(i => i.sku === sku); return { sku, name: ing ? ing.name : sku }; });
            onSave({ quantity: qty, addedModifiers: addedMods, removedIngredients: removed, comment });
            modal.classList.remove('active');
        });
    }

    // === ORDER TYPE MODAL ===
    function showOrderTypeModal(onSelect) {
        const modal = $('#order-type-modal'); if (!modal) return;
        modal.innerHTML = `<div class="dc-backdrop" data-close-ot="1"></div><div class="ot-card"><h3 class="ot-title">Wybierz Typ Zamówienia</h3><div class="ot-grid"><button class="ot-btn" data-otype="dine_in"><span class="ot-icon"><i class="fa-solid fa-chair" style="font-size:24px"></i></span>Na Miejscu</button><button class="ot-btn" data-otype="takeaway"><span class="ot-icon"><i class="fa-solid fa-bag-shopping" style="font-size:24px"></i></span>Na Wynos</button><button class="ot-btn" data-otype="delivery"><span class="ot-icon"><i class="fa-solid fa-motorcycle" style="font-size:24px"></i></span>Dostawa</button></div></div>`;
        modal.classList.add('active');
        modal.querySelectorAll('.ot-btn').forEach(btn => btn.addEventListener('click', () => { modal.classList.remove('active'); onSelect(btn.dataset.otype); }));
        modal.querySelectorAll('[data-close-ot]').forEach(el => el.addEventListener('click', e => { if (e.target === el) modal.classList.remove('active'); }));
    }

    // === TABLE SELECTOR MODAL (Dine-In → Pick Available Table or No Table) ===
    function showTableSelectorModal(tables, onSelect, onClose) {
        const modal = $('#table-selector-modal'); if (!modal) return;
        const sorted = [...(tables || [])].sort((a, b) => {
            const na = parseInt(a.table_number) || 0, nb = parseInt(b.table_number) || 0;
            return na - nb;
        });
        const gridHtml = sorted.length ? sorted.map(t => {
            const isFree = t.physical_status === 'free' || t.physical_status === 'reserved';
            const cls = isFree ? 'ts-free' : 'ts-occupied';
            return `<button class="ts-table-btn ${cls}" data-tid="${t.id}" data-tnum="${_e(t.table_number)}" data-seats="${t.seats || '?'}"><span class="ts-table-num">${_e(t.table_number)}</span><span class="ts-table-seats">${t.seats || '?'} <i class="fa-solid fa-chair" style="font-size:7px"></i></span></button>`;
        }).join('') : '<div class="ts-empty">Brak stolików</div>';

        const noTableBtn = `<button class="ts-no-table" id="ts-no-table"><i class="fa-solid fa-receipt" style="font-size:14px"></i> Bez stolika</button>`;

        modal.innerHTML = `<div class="dc-backdrop" data-close-ts="1"></div><div class="ts-card"><div class="ts-header"><div class="ts-header-icon"><i class="fa-solid fa-chair"></i></div><div class="ts-header-text"><h3>Wybierz Stolik</h3><p>Kliknij wolny stolik lub kontynuuj bez stolika</p></div><button class="dc-close ts-close" data-close-ts="1"><i class="fa-solid fa-xmark"></i></button></div><div class="ts-body">${noTableBtn}<div class="ts-grid">${gridHtml}</div></div></div>`;
        modal.classList.add('active');

        const noTblBtn = modal.querySelector('#ts-no-table');
        if (noTblBtn) noTblBtn.addEventListener('click', () => { modal.classList.remove('active'); onSelect(null); });

        modal.querySelectorAll('.ts-table-btn.ts-free').forEach(btn => {
            btn.addEventListener('click', () => {
                modal.classList.remove('active');
                onSelect({ id: btn.dataset.tid, table_number: btn.dataset.tnum, seats: btn.dataset.seats });
            });
        });
        modal.querySelectorAll('[data-close-ts]').forEach(el => {
            el.addEventListener('click', e => {
                if (e.target === el || e.currentTarget === el) {
                    modal.classList.remove('active');
                    if (onClose) onClose();
                }
            });
        });
    }

    // === CHECKOUT MODAL (Full legacy logic) ===
    function showCheckoutModal(snapshot, callbacks) {
        const modal = $('#checkout-modal'); if (!modal) return;
        const isDelivery = snapshot.orderType === 'delivery';
        const isEdit = !!callbacks.isEdit;
        const defaultMins = isDelivery ? 60 : 30;

        let targetTime = new Date();
        targetTime.setMinutes(targetTime.getMinutes() + defaultMins);
        let payMethod = 'unpaid', payStatus = 'unpaid', printKitchen = true, printReceipt = false;

        const lines = snapshot.lines.map(l => `<div class="ck-line"><span class="ck-line-qty">${l.quantity}x</span><span class="ck-line-name">${l.snapshotName}</span><span class="ck-line-price">${(l.lineTotalGrosze / 100).toFixed(2)} zł</span></div>`).join('');

        const quickBtns = (isDelivery ? [45,60,90,120] : [15,30,45,60]).map(v => `<button class="ck-pill" data-minutes="${v}">+${v}m</button>`).join('');

        const editBanner = isEdit
            ? `<div style="background:rgba(234,179,8,0.15);border:1px solid rgba(234,179,8,0.4);border-radius:8px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:#fbbf24;letter-spacing:0.02em"><span style="font-size:16px">✏️</span><div><div>TRYB EDYCJI ZAMÓWIENIA</div><div style="font-weight:500;color:#d4a017;font-size:11px;margin-top:2px">Zmiany zostaną wysłane na kuchnię — wydrukuj nowy BON</div></div></div>`
            : '';

        const submitLabel = isEdit ? 'Aktualizuj Zamówienie →' : 'Wyślij Zamówienie →';
        const kitchenLabel = isEdit ? 'Drukuj BON ze zmianami (Kuchnia)' : 'Drukuj BON (Kuchnia)';

        modal.innerHTML = `<div class="dc-backdrop" data-close-ck="1"></div><div class="ck-card"><div class="ck-header"><h3>${isEdit ? 'Aktualizacja Zamówienia' : 'Finalizacja'}</h3><button class="dc-close" data-close-ck="1">✕</button></div>
        ${editBanner}
        <div class="ck-time-section"><h4>Czas realizacji</h4><div class="ck-time-pills">${quickBtns}</div><div class="ck-time-display"><span id="ck-time-label">Za ${defaultMins} min</span></div></div>
        <div class="ck-lines">${lines}</div>
        <div class="ck-total-bar"><span>Suma</span><span class="ck-grand-total">${snapshot.subtotalFormatted} zł</span></div>
        <div class="ck-pay-section"><h4>Status płatności</h4>
        <button class="ck-pay-btn active-park" data-pm="unpaid" data-ps="unpaid">🕐 DO ZAPŁATY (ZAPARKUJ)</button>
        <div class="ck-pay-row"><button class="ck-pay-btn" data-pm="cash" data-ps="paid">Gotówka</button><button class="ck-pay-btn" data-pm="card" data-ps="paid">Karta</button></div></div>
        <div class="ck-print-section"><label class="ck-check"><input type="checkbox" id="ck-print-kitchen" checked> ${_e(kitchenLabel)}</label><label class="ck-check"><input type="checkbox" id="ck-print-receipt"> Drukuj PARAGON <span class="ck-req hidden" id="ck-receipt-req">(wymagane)</span></label></div>
        <button class="ck-submit-btn" id="ck-submit">${_e(submitLabel)}</button></div>`;

        modal.classList.add('active');

        // Time pills
        modal.querySelectorAll('.ck-pill').forEach(pill => pill.addEventListener('click', () => {
            const m = parseInt(pill.dataset.minutes);
            targetTime = new Date(); targetTime.setMinutes(targetTime.getMinutes() + m);
            modal.querySelectorAll('.ck-pill').forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            modal.querySelector('#ck-time-label').textContent = `Za ${m} min`;
        }));

        // Payment buttons
        modal.querySelectorAll('.ck-pay-btn').forEach(btn => btn.addEventListener('click', () => {
            modal.querySelectorAll('.ck-pay-btn').forEach(b => b.classList.remove('active', 'active-park'));
            btn.classList.add(btn.dataset.pm === 'unpaid' ? 'active-park' : 'active');
            payMethod = btn.dataset.pm; payStatus = btn.dataset.ps;
            const chk = modal.querySelector('#ck-print-receipt'), req = modal.querySelector('#ck-receipt-req');
            if (payMethod === 'card' || payMethod === 'online') { chk.checked = true; chk.disabled = true; req?.classList.remove('hidden'); }
            else { chk.disabled = false; req?.classList.add('hidden'); }
        }));

        // Close
        modal.querySelectorAll('[data-close-ck]').forEach(el => el.addEventListener('click', e => { if (e.target === el) modal.classList.remove('active'); }));

        // Submit
        modal.querySelector('#ck-submit')?.addEventListener('click', () => {
            printKitchen = modal.querySelector('#ck-print-kitchen')?.checked || false;
            printReceipt = modal.querySelector('#ck-print-receipt')?.checked || false;
            const iso = new Date(targetTime.getTime() - (targetTime.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
            modal.classList.remove('active');
            callbacks.onSubmit({ payMethod, payStatus, printKitchen, printReceipt, promisedTime: iso });
        });
    }

    // === PAYMENT / SETTLE MODAL ===
    function showPaymentModal(order, mode, callbacks) {
        const modal = $('#payment-modal'); if (!modal) return;
        const num = order.order_number?.split('/').pop() || order.order_number;
        let method = null, doPrint = false;

        const isSettleMode = mode === 'settle';
        const title = isSettleMode ? 'ROZLICZ I ZAMKNIJ' : 'DRUKUJ PARAGON';

        modal.innerHTML = `<div class="dc-backdrop" data-close-pm="1"></div><div class="pm-card"><h3 class="pm-title">${title}</h3><p class="pm-sub">#${num}</p>
        <div class="pm-methods"><button class="pm-btn" data-m="card">💳 Karta</button><button class="pm-btn" data-m="cash">💵 Gotówka</button></div>
        <label class="ck-check pm-check"><input type="checkbox" id="pm-print" ${mode === 'print' ? 'checked disabled' : ''}> Drukuj Paragon <span class="ck-req hidden" id="pm-req">(wymagane)</span></label>
        ${isSettleMode ? `<button class="ck-submit-btn" id="pm-settle">Zakończ i zamknij</button>` : `<button class="ck-submit-btn" id="pm-print-only" style="background:var(--accent-blue)">Tylko Drukuj</button>`}
        <button class="pm-cancel" data-close-pm="1">Anuluj</button></div>`;

        modal.classList.add('active');

        modal.querySelectorAll('.pm-btn').forEach(btn => btn.addEventListener('click', () => {
            modal.querySelectorAll('.pm-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            method = btn.dataset.m;
            const chk = modal.querySelector('#pm-print'), req = modal.querySelector('#pm-req');
            if (method === 'card' || method === 'online') { chk.checked = true; chk.disabled = true; req?.classList.remove('hidden'); }
            else { chk.disabled = false; req?.classList.add('hidden'); }
        }));

        modal.querySelectorAll('[data-close-pm]').forEach(el => el.addEventListener('click', e => { if (e.target === el || e.currentTarget === el) modal.classList.remove('active'); }));

        modal.querySelector('#pm-settle')?.addEventListener('click', () => {
            if (!method) { toast('Zaznacz metodę płatności!', 'error'); return; }
            doPrint = modal.querySelector('#pm-print')?.checked || false;
            if ((method === 'card' || method === 'online') && !doPrint) { toast('Paragon obowiązkowy!', 'error'); return; }
            modal.classList.remove('active');
            callbacks.onSettle(method, doPrint);
        });

        modal.querySelector('#pm-print-only')?.addEventListener('click', () => {
            if (!method) { toast('Zaznacz metodę płatności!', 'error'); return; }
            modal.classList.remove('active');
            callbacks.onPrintOnly(method);
        });
    }

    // === CANCEL MODAL ===
    function showCancelModal(orderId, onConfirm) {
        const modal = $('#cancel-modal'); if (!modal) return;
        modal.innerHTML = `<div class="dc-backdrop" data-close-cn="1"></div><div class="cn-card"><h3 class="cn-title">ANULUJ ZAMÓWIENIE</h3>
        <button class="cn-btn stock" id="cn-return">↩ Zwróć na magazyn</button>
        <button class="cn-btn loss" id="cn-loss">🔥 Odpisz jako stratę</button>
        <button class="pm-cancel" data-close-cn="1">Wróć</button></div>`;
        modal.classList.add('active');
        modal.querySelector('#cn-return')?.addEventListener('click', () => { modal.classList.remove('active'); onConfirm(true); });
        modal.querySelector('#cn-loss')?.addEventListener('click', () => { modal.classList.remove('active'); onConfirm(false); });
        modal.querySelectorAll('[data-close-cn]').forEach(el => el.addEventListener('click', e => { if (e.target === el || e.currentTarget === el) modal.classList.remove('active'); }));
    }

    // === PULSE (Online Orders Sidebar) ===
    function renderPulse(orders, expandedId, callbacks) {
        const sections = { delivery: $('#pulse-delivery'), takeaway: $('#pulse-takeaway'), dinein: $('#pulse-dinein') };
        Object.values(sections).forEach(s => { if (s) s.innerHTML = ''; });

        orders.forEach(o => {
            const target = o.order_type === 'delivery' ? sections.delivery : o.order_type === 'takeaway' ? sections.takeaway : sections.dinein;
            if (!target) return;
            const num = o.order_number?.split('/').pop() || '';
            const elapsed = Math.floor((Date.now() - new Date(o.created_at).getTime()) / 60000);
            const borderClass = elapsed > 3 ? 'pulse-card-urgent' : '';
            const total = o.grand_total_formatted || (parseInt(o.grand_total) / 100).toFixed(2);

            let expandHtml = '';
            if (expandedId === o.id) {
                const lines = (o.lines || []).map(l => `<div class="pulse-line">${l.quantity}x ${l.snapshot_name} — ${(parseInt(l.line_total)/100).toFixed(2)}zł</div>`).join('');
                expandHtml = `<div class="pulse-expand"><div class="pulse-items">${lines}</div><div class="pulse-accept-btns"><button data-accept="${o.id}" data-min="15">+15m</button><button data-accept="${o.id}" data-min="30">+30m</button><button data-accept="${o.id}" data-min="45">+45m</button></div><button class="pulse-accept-now" data-accept-now="${o.id}">✓ ZAAKCEPTUJ</button></div>`;
            }

            target.insertAdjacentHTML('beforeend', `<div class="pulse-card ${borderClass}" data-pulse-id="${o.id}"><div class="pulse-card-top"><span class="pulse-num">#${num}</span><span class="pulse-total">${total}zł</span></div><div class="pulse-card-bottom"><span class="pulse-source">${o.source || 'online'}</span><span class="pulse-time">${elapsed}m temu</span></div>${expandHtml}</div>`);
        });

        // Wire events
        document.querySelectorAll('.pulse-card').forEach(card => {
            card.addEventListener('click', e => {
                if (e.target.closest('[data-accept]') || e.target.closest('[data-accept-now]')) return;
                callbacks.onToggle(card.dataset.pulseId);
            });
        });
        document.querySelectorAll('[data-accept]').forEach(btn => btn.addEventListener('click', e => {
            e.stopPropagation(); callbacks.onAccept(btn.dataset.accept, parseInt(btn.dataset.min));
        }));
        document.querySelectorAll('[data-accept-now]').forEach(btn => btn.addEventListener('click', e => {
            e.stopPropagation(); callbacks.onAccept(btn.dataset.acceptNow, 0);
        }));
    }

    // === KANBAN BATTLEFIELD ===
    function renderKanban(orders, filterType, expandedId, routeDriverId, routeOrders, callbacks) {
        const grid = $('#bf-grid'); if (!grid) return;
        const now = new Date();

        function fmtTime(dateStr, type) {
            if (!dateStr) return { text: '<span class="time-indicator">ASAP</span>', cls: 'sla-white' };
            const d = new Date(dateStr), diff = Math.ceil((d - now) / 60000);
            const sign = diff >= 0 ? '+' : '-';
            const text = `<span class="time-indicator">${sign}${Math.abs(diff)}min</span>`;
            let cls = 'sla-green';
            if (type === 'delivery') { if (diff < 15) cls = 'sla-red'; else if (diff <= 59) cls = 'sla-yellow'; }
            else { if (diff < 6) cls = 'sla-red'; else if (diff <= 14) cls = 'sla-yellow'; }
            return { text, cls };
        }

        function fmtNum(orderNum, createdAt) {
            const num = (orderNum || '').split('/').pop() || orderNum;
            const d = new Date(createdAt || now);
            const isToday = d.toDateString() === now.toDateString();
            return isToday ? `#${num}` : `${String(d.getDate()).padStart(2,'0')}.${String(d.getMonth()+1).padStart(2,'0')} #${num}`;
        }

        function buildCard(o) {
            const timeInfo = fmtTime(o.promised_time || o.created_at, o.order_type);
            const num = fmtNum(o.order_number, o.created_at);
            const total = o.grand_total_formatted || (parseInt(o.grand_total || 0) / 100).toFixed(2);
            let payText, payCls;
            if (o.payment_status === 'online_paid') { payText = 'OPŁACONE ONLINE'; payCls = 'pay-prepaid'; }
            else if (o.payment_status === 'cash') { payText = 'GOTÓWKA'; payCls = 'pay-paid'; }
            else if (o.payment_status === 'card') { payText = 'KARTA'; payCls = 'pay-card'; }
            else { payText = 'DO ZAPŁATY'; payCls = 'pay-unpaid'; }
            const cartSnippet = (o.lines || []).map(l => `${l.quantity}x ${l.snapshot_name}`).join(', ');
            const source = o.source === 'waiter' ? 'KELNER' : o.source === 'local' ? 'POS' : (o.source || '').toUpperCase();
            const statusLabel = {
                new: 'NOWE', pending: 'NOWE', accepted: 'ZAACCEPT.', preparing: 'KUCHNIA', ready: 'GOTOWE',
                completed: 'ZAMKN.', cancelled: 'ANUL.',
            }[o.status] || o.status.toUpperCase();
            const statusCls = {
                new: 'st-pending', pending: 'st-pending', accepted: 'st-accepted', preparing: 'st-preparing', ready: 'st-ready',
            }[o.status] || '';
            const isQueued = o.delivery_status === 'queued' && o.course_id;
            const isSelected = routeOrders.includes(o.id);
            const lIdx = routeOrders.indexOf(o.id);
            const lBadge = lIdx >= 0 ? `<div class="l-badge">L${lIdx + 1}</div>` : '';
            const courseBadge = isQueued ? `<div class="course-badge">${_e(o.course_id)} / ${_e(o.stop_number || '?')}</div>` : '';
            const badges = [];
            if (o.receipt_printed == 1) badges.push('<span class="s-badge">PARAGON</span>');
            if (o.kitchen_ticket_printed == 1) badges.push(`<span class="s-badge ${o.edited_since_print == 1 ? 's-badge-alert' : ''}">BON</span>`);

            let expandHtml = '';
            if (expandedId === o.id && !routeDriverId && !isQueued) {
                const fullLines = (o.lines || []).map(l => {
                    const comment = l.comment ? `<span class="bf-comment">(${l.comment})</span>` : '';
                    return `<div class="bf-line"><span>${l.quantity}x ${l.snapshot_name} ${comment}</span><span class="bf-line-price">${(parseInt(l.line_total || 0) / 100).toFixed(2)}zł</span></div>`;
                }).join('');

                const pColor = (o.kitchen_ticket_printed == 0 || o.edited_since_print == 1) ? 'bf-btn-warn' : '';
                const pText = o.edited_since_print == 1 ? 'Drukuj Zmiany' : 'Drukuj Kuchnia';
                let statusBtn = '';
                if (o.status === 'pending' || o.status === 'new') {
                    statusBtn = `<button class="bf-action bf-preparing" data-act="status_preparing" data-oid="${o.id}">🔥 PRZYGOTUJ</button>`;
                } else if (o.status === 'preparing') {
                    statusBtn = `<button class="bf-action bf-ready" data-act="status_ready" data-oid="${o.id}">✅ GOTOWE</button>`;
                }

                expandHtml = `<div class="bf-expand"><div class="bf-lines">${fullLines || '<span class="bf-empty">Pusty</span>'}</div><div class="bf-actions-row"><button class="bf-action ${pColor}" data-act="print_kitchen" data-oid="${o.id}">🖨 ${pText}</button><button class="bf-action" data-act="print_receipt" data-oid="${o.id}">📄 Paragon</button><button class="bf-action" data-act="edit" data-oid="${o.id}">✏️ Edytuj</button></div><div class="bf-actions-row"><button class="bf-action bf-settle" data-act="settle" data-oid="${o.id}">💰 ZAMKNIJ</button><button class="bf-action bf-cancel" data-act="cancel" data-oid="${o.id}">🗑</button>${statusBtn}</div></div>`;
            }

            const clientLine = o.order_type === 'delivery' ? `📍 ${_e(o.delivery_address || 'Brak adresu')}` : `${_e(o.customer_name || 'Gość')}`;

            return `<div class="bf-card ${timeInfo.cls} ${isSelected ? 'bf-selected' : ''} ${statusCls} ${isQueued ? 'bf-queued' : ''}" data-order-id="${o.id}">${lBadge}${courseBadge}<div class="bf-card-top"><div class="bf-card-left"><div class="bf-num-row"><span class="bf-num">${_e(num)}</span><span class="bf-status-badge ${statusCls}">${statusLabel}</span>${badges.join('')}</div><div class="bf-client">${clientLine}</div><div class="bf-snippet">${_e(cartSnippet)}</div></div><div class="bf-card-right"><div class="bf-source">${_e(source)}</div><div class="bf-sla">${timeInfo.text}</div><div class="bf-total">${total} zł</div><div class="bf-pay ${payCls}">${payText}</div></div></div>${expandHtml}</div>`;
        }

        // Column expand/collapse state
        const colExpandHint = '<span class="col-expand-hint"><i class="fa-solid fa-expand"></i> Rozwiń</span>';
        const colCollapseHint = '<span class="col-expand-hint" style="opacity:1"><i class="fa-solid fa-compress"></i> Zwiń</span>';

        if (filterType === 'routes') {
            const groups = {};
            orders.filter(o => (o.delivery_status === 'in_delivery' || o.delivery_status === 'queued') && o.course_id).forEach(o => {
                const key = o.course_id || 'K?';
                if (!groups[key]) groups[key] = { items: [], driver_id: null, cashToCollect: 0, cardToCollect: 0, paidTotal: 0, isQueued: false };
                groups[key].items.push(o);
                if (o.driver_id) groups[key].driver_id = o.driver_id;
                if (o.delivery_status === 'queued') groups[key].isQueued = true;
                const price = parseInt(o.grand_total || 0);
                if (['cash','card','online_paid'].includes(o.payment_status)) groups[key].paidTotal += price;
                else if (o.payment_status === 'online_unpaid') groups[key].cardToCollect += price;
                else groups[key].cashToCollect += price;
            });
            const fmtG = g => (g / 100).toFixed(2);
            let html = '';
            for (const [courseId, g] of Object.entries(groups)) {
                g.items.sort((a, b) => parseInt((a.stop_number || 'L99').replace('L', '')) - parseInt((b.stop_number || 'L99').replace('L', '')));
                const isUnassigned = !g.driver_id;
                const driverName = isUnassigned ? 'NIEPRZYPISANY' : (callbacks.getDriverName ? callbacks.getDriverName(g.driver_id) : 'Kierowca');

                const stopsHtml = g.items.map(o => {
                    let sPay, sPayCls;
                    if (o.payment_status === 'online_paid') { sPay = 'OPŁACONE'; sPayCls = 'rt-pay-prepaid'; }
                    else if (o.payment_status === 'cash') { sPay = 'GOTÓWKA'; sPayCls = 'rt-pay-paid'; }
                    else if (o.payment_status === 'card') { sPay = 'KARTA'; sPayCls = 'rt-pay-card'; }
                    else { sPay = 'DO ZAPŁATY'; sPayCls = 'rt-pay-unpaid'; }
                    return `<div class="rt-stop"><div class="rt-stop-num">${_e(o.stop_number || '?')}</div><div class="rt-stop-body"><div class="rt-stop-main"><span class="rt-stop-order">${_e(fmtNum(o.order_number, o.created_at))}</span><span class="rt-stop-total">${fmtG(parseInt(o.grand_total || 0))} zł</span></div><div class="rt-stop-addr">${_e(o.delivery_address || 'Brak adresu')}</div>${o.customer_phone ? `<div class="rt-stop-phone">${_e(o.customer_phone)}</div>` : ''}</div><div class="rt-stop-pay ${sPayCls}">${sPay}</div></div>`;
                }).join('');

                const walletHtml = !isUnassigned ? `<div class="rt-wallet"><div class="rt-w-cell"><span class="rt-w-label">Gotówka do pobrania</span><span class="rt-w-val">${fmtG(g.cashToCollect)} zł</span></div><div class="rt-w-cell"><span class="rt-w-label">Terminal</span><span class="rt-w-val rt-w-card">${fmtG(g.cardToCollect)} zł</span></div><div class="rt-w-cell"><span class="rt-w-label">Opłacone</span><span class="rt-w-val rt-w-paid">${fmtG(g.paidTotal)} zł</span></div></div>` : '';

                const assignBtn = isUnassigned ? `<div class="rt-assign-row"><button class="rt-assign-btn" data-assign-course="${courseId}">Przypisz Kierowcę</button></div>` : '';

                const headerCls = isUnassigned ? 'rt-header-unassigned' : '';
                const driverBadge = isUnassigned
                    ? `<span class="rt-driver rt-unassigned">NIEPRZYPISANY</span>`
                    : `<span class="rt-driver">${_e(driverName)}</span>`;

                html += `<div class="rt-card ${isUnassigned ? 'rt-card-unassigned' : ''}"><div class="rt-header ${headerCls}"><span class="rt-course">${courseId}</span>${driverBadge}<span class="rt-count">${g.items.length} przyst.</span></div>${walletHtml}<div class="rt-stops">${stopsHtml}</div>${assignBtn}</div>`;
            }
            grid.innerHTML = html || '<div class="empty-state">Brak aktywnych kursów</div>';

            grid.querySelectorAll('[data-assign-course]').forEach(btn => {
                btn.addEventListener('click', e => { e.stopPropagation(); callbacks.onAssignCourse(btn.dataset.assignCourse); });
            });
        } else if (filterType === 'all') {
            const buckets = { delivery: [], takeaway: [], dine_in: [] };
            orders.forEach(o => { if (buckets[o.order_type]) buckets[o.order_type].push(o); });

            const expandedCol = grid.dataset.expandedCol || null;
            const fullCls = expandedCol !== null ? ` fullscreen-col-${expandedCol}` : '';

            grid.innerHTML = `<div class="kanban-3col${fullCls}" id="kanban-grid-3col">
                <div class="kanban-col"><div class="kanban-col-header${expandedCol === '0' ? ' col-active' : ''}" data-col-idx="0"><i class="fa-solid fa-motorcycle col-icon"></i><span class="col-label">Dowóz</span><span class="kanban-cnt">${buckets.delivery.length}</span>${expandedCol === '0' ? colCollapseHint : colExpandHint}</div><div class="kanban-col-body">${buckets.delivery.map(buildCard).join('') || '<div class="kanban-empty">Brak</div>'}</div></div>
                <div class="kanban-col"><div class="kanban-col-header${expandedCol === '1' ? ' col-active' : ''}" data-col-idx="1"><i class="fa-solid fa-bag-shopping col-icon"></i><span class="col-label">Wynos</span><span class="kanban-cnt">${buckets.takeaway.length}</span>${expandedCol === '1' ? colCollapseHint : colExpandHint}</div><div class="kanban-col-body">${buckets.takeaway.map(buildCard).join('') || '<div class="kanban-empty">Brak</div>'}</div></div>
                <div class="kanban-col"><div class="kanban-col-header${expandedCol === '2' ? ' col-active' : ''}" data-col-idx="2"><i class="fa-solid fa-chair col-icon"></i><span class="col-label">Sala</span><span class="kanban-cnt">${buckets.dine_in.length}</span>${expandedCol === '2' ? colCollapseHint : colExpandHint}</div><div class="kanban-col-body">${buckets.dine_in.map(buildCard).join('') || '<div class="kanban-empty">Brak</div>'}</div></div>
            </div>`;

            grid.querySelectorAll('.kanban-col-header[data-col-idx]').forEach(hdr => {
                hdr.addEventListener('click', e => {
                    e.stopPropagation();
                    const idx = hdr.dataset.colIdx;
                    const g3 = grid.querySelector('#kanban-grid-3col');
                    if (!g3) return;
                    if (grid.dataset.expandedCol === idx) {
                        delete grid.dataset.expandedCol;
                        g3.className = 'kanban-3col';
                        hdr.classList.remove('col-active');
                    } else {
                        grid.dataset.expandedCol = idx;
                        g3.className = 'kanban-3col fullscreen-col-' + idx;
                        grid.querySelectorAll('.kanban-col-header').forEach(h => h.classList.remove('col-active'));
                        hdr.classList.add('col-active');
                    }
                    if (callbacks.onColumnToggle) callbacks.onColumnToggle(grid.dataset.expandedCol || null);
                });
            });
        }

        // Wire events
        grid.querySelectorAll('.bf-card').forEach(card => {
            card.addEventListener('click', e => {
                if (e.target.closest('[data-act]')) return;
                callbacks.onCardClick(card.dataset.orderId);
            });
        });
        grid.querySelectorAll('[data-act]').forEach(btn => {
            btn.addEventListener('click', e => {
                e.stopPropagation();
                const oid = btn.dataset.oid, act = btn.dataset.act;
                if (act === 'print_kitchen') callbacks.onPrintKitchen(oid);
                else if (act === 'print_receipt') callbacks.onPrintReceipt(oid);
                else if (act === 'edit') callbacks.onEdit(oid);
                else if (act === 'settle') callbacks.onSettle(oid);
                else if (act === 'cancel') callbacks.onCancel(oid);
                else if (act === 'status_preparing') callbacks.onStatusChange(oid, 'preparing');
                else if (act === 'status_ready') callbacks.onStatusChange(oid, 'ready');
            });
        });
    }

    // === DRIVERS & WAITERS ===
    function renderDrivers(drivers, activeId, onClick) {
        const list = $('#drivers-list'); if (!list) return;
        list.innerHTML = (drivers || []).map(d => {
            const isActive = String(d.id) === String(activeId);
            const statusCls = d.status === 'available' ? 'drv-available' : d.status === 'busy' ? 'drv-busy' : 'drv-offline';
            return `<div class="drv-item ${statusCls} ${isActive ? 'drv-active' : ''}" data-did="${d.id}"><span class="drv-name">${_e(d.display_name || 'Kierowca')}</span><span class="drv-status">${_e(d.status || 'offline')}</span></div>`;
        }).join('') || '<div class="fleet-empty">Brak kierowców</div>';
        list.querySelectorAll('.drv-item').forEach(el => el.addEventListener('click', () => onClick(el.dataset.did)));
    }

    function renderWaiters(waiters) {
        const list = $('#waiters-list'); if (!list) return;
        list.innerHTML = (waiters || []).map(w => `<div class="wtr-item"><span>${_e(w.display_name || 'Kelner')}</span></div>`).join('') || '<div class="fleet-empty">Brak kelnerów</div>';
    }

    // === PRINTING ===
    function printTemplate(orderData, isKitchen) {
        const cart = orderData.cart || [];
        const itemsHtml = cart.map(item => {
            const addedList = (item.added || []).map(a => `+ ${typeof a === 'object' ? a.name : a}`).join(', ');
            const addedHtml = addedList ? `<br><small><b>${addedList}</b></small>` : '';
            const removedList = (item.removed || []).map(r => `BEZ ${typeof r === 'object' ? r.name : r}`).join(', ');
            const removedHtml = removedList ? `<br><small>${removedList}</small>` : '';
            const comment = item.comment ? `<br><small style="border:1px solid #000;padding:2px">UWAGA: ${_e(item.comment)}</small>` : '';
            return `<tr><td style="padding:5px 0;font-weight:bold;width:15%">${item.qty || item.quantity || 1}x</td><td style="padding:5px 0;width:60%">${_e(item.name)}${addedHtml}${removedHtml}${comment}</td>${!isKitchen ? `<td style="text-align:right;padding:5px 0">${((item.price || 0) * (item.qty || item.quantity || 1)).toFixed(2)}</td>` : ''}</tr>`;
        }).join('');

        const typeStr = orderData.order_type === 'delivery' ? 'DOSTAWA' : orderData.order_type === 'takeaway' ? 'WYNOS' : 'SALA';
        const title = isKitchen ? 'BON NA KUCHNIĘ' : 'RACHUNEK / PARAGON NIEFISKALNY';
        const editFlag = orderData.is_edit ? `<div style="text-align:center;font-size:14px;font-weight:bold;margin:4px 0;border:2px solid #000;padding:4px;background:#eee">⚠ ZMIANA ZAMÓWIENIA ⚠</div>` : '';
        const clientHtml = orderData.order_type === 'delivery' ? `<div style="border-top:1px dashed #000;border-bottom:1px dashed #000;margin:10px 0;padding:10px 0"><b>${_e(orderData.address || '')}</b><br>Tel: ${_e(orderData.customer_phone || '')}</div>` : '';
        const waiterName = orderData.waiter_name || '';
        const waiterHtml = waiterName ? `<div style="font-weight:bold;font-size:13px;margin:4px 0">Przyjął: ${_e(waiterName)}</div>` : '';

        const html = `<html><head><style>body{font-family:'Courier New',monospace;width:300px;margin:0;padding:0;font-size:12px}h2,h3{text-align:center;margin:5px 0}table{width:100%;border-collapse:collapse}.divider{border-top:2px dashed #000;margin:10px 0}</style></head><body><h2>SLICEHUB POS</h2><h3>${title}</h3><div class="divider"></div><div style="display:flex;justify-content:space-between;font-weight:bold;font-size:14px"><span>#${orderData.order_number || 'NEW'}</span><span>${new Date(orderData.created_at || Date.now()).toLocaleTimeString('pl',{hour:'2-digit',minute:'2-digit'})}</span></div>${waiterHtml}<div style="text-align:center;font-size:18px;font-weight:bold;margin:5px 0;border:2px solid #000;padding:5px">${typeStr}</div>${editFlag}${clientHtml}<table>${itemsHtml}</table><div class="divider"></div>${!isKitchen ? `<h2 style="text-align:right;font-size:20px">SUMA: ${orderData.total || 0} PLN</h2>` : ''}<div style="text-align:center;margin-top:20px;font-size:10px">Wygenerowano: ${new Date().toLocaleString()}</div></body></html>`;
        _doPrint(html);
    }

    function printOrderTemplate(order, isKitchen, opts = {}) {
        const cart = order.cart_json ? (typeof order.cart_json === 'string' ? JSON.parse(order.cart_json) : order.cart_json) : [];
        const total = order.grand_total_formatted || (parseInt(order.grand_total || 0) / 100).toFixed(2);
        printTemplate({
            order_number: order.order_number, order_type: order.order_type,
            cart, total, address: order.delivery_address,
            customer_phone: order.customer_phone, customer_name: order.customer_name,
            created_at: order.created_at,
            waiter_name: opts.waiterName || order.waiter_name || order.created_by_name || '',
            is_edit: !!opts.isEdit,
        }, isKitchen);
    }

    function _doPrint(htmlContent) {
        const iframe = document.getElementById('print-frame'); if (!iframe) return;
        const doc = iframe.contentWindow.document;
        doc.open(); doc.write(htmlContent); doc.close();
        setTimeout(() => { iframe.contentWindow.focus(); iframe.contentWindow.print(); }, 250);
    }

    return Object.freeze({
        toast, renderPinScreen, hidePinScreen, renderUserBadge,
        renderCategories, renderItemGrid, renderCart,
        showDishCard, showDishCardEdit, showOrderTypeModal, showTableSelectorModal,
        showCheckoutModal, showPaymentModal, showCancelModal,
        renderPulse, renderKanban, renderDrivers, renderWaiters,
        printTemplate, printOrderTemplate,
    });
})();

export default PosUI;
