/**
 * SLICEHUB POS V2 — Main Application Orchestrator
 * Full Battlefield + Order Creator with all legacy business logic.
 */
// P4: PosApiOutbox wrapper (domyślny export = proxy z identycznym API jak PosAPI).
// Mutacje (processOrder, accept, settle, cancel, ...) lecą przez outbox gdy offline
// lub sieć padnie mid-live — UI dostaje response z { queued: true } i może zachować
// spójność. Reads (getOrders, getInitData, ...) idą bez zmian przez oryginalny PosAPI.
import PosAPI from './PosApiOutbox.js';
import PosCart from './pos_cart.js';
import PosUI from './pos_ui.js';
import { initPosHrClock } from './pos_hr_clock.js';

const PosApp = (() => {
    const TENANT_ID = parseInt(document.querySelector('meta[name="sh-tenant-id"]')?.content, 10) || 1;
    const POLL_INTERVAL = 8000;

    let _user = null;
    let _menuData = { categories: [], items: [], ingredients: [], drivers: [], waiters: [], modifierGroups: [] };
    let _orders = [];
    let _activeCategoryId = null;
    let _halfMode = false;
    let _halfA = null;
    let _editOrderId = null;
    let _isCartLocked = false;
    let _filterType = 'all';
    let _expandedOrderId = null;
    let _expandedOnlineId = null;
    let _lastPlayedId = null;
    let _settleOrderId = null;
    let _settleMethod = null;
    let _pollTimer = null;
    let _tableLocked = false;

    // Route builder state
    let _routeDriverId = null;
    let _routeOrders = [];
    let _assignCourseId = null;

    // =========================================================================
    // BOOT
    // =========================================================================
    async function init() {
        const stored = localStorage.getItem('sh_user');
        const token = PosAPI.getToken();
        if (stored && token) {
            try { _user = JSON.parse(stored); await _bootApp(); return; } catch {}
        }
        _showPinLogin();
    }

    function _showPinLogin() {
        PosUI.renderPinScreen(async (pin) => {
            const res = await PosAPI.loginPin(TENANT_ID, pin);
            if (res.success && res.data) {
                PosAPI.setToken(res.data.token);
                _user = res.data.user;
                localStorage.setItem('sh_user', JSON.stringify(_user));
                PosUI.toast(`Witaj, ${_user.name}!`, 'success');
                await _bootApp();
            } else {
                PosUI.toast(res.message || 'Nieprawidłowy PIN', 'error');
            }
        });
    }

    // =========================================================================
    // APP BOOT
    // =========================================================================
    async function _bootApp() {
        PosUI.hidePinScreen();
        PosUI.renderUserBadge(_user);
        const navBadge = document.getElementById('nav-user-badge');
        if (navBadge && _user) navBadge.textContent = _user.name || _user.role || 'POS';

        // Capture URL intent BEFORE any DOM wiring (params are one-shot)
        const intent = _parseUrlIntent();

        // Wire cart subscription
        PosCart.subscribe((snapshot) => {
            PosUI.renderCart(snapshot, {
                onQtyChange: (lid, qty) => PosCart.updateLine(lid, { quantity: qty }),
                onRemove:    (lid) => PosCart.removeLine(lid),
                onLineClick: (lid) => _editCartLine(lid),
            });
        });

        // Wire buttons
        _on('#btn-new-order', 'click', _openOrderTypeSelector);
        _on('#btn-show-battlefield', 'click', _exitTableContext);
        _on('#btn-back-to-bf', 'click', _exitTableContext);
        _on('#btn-panic', 'click', _triggerPanic);
        _on('#btn-checkout', 'click', _openCheckout);
        _on('#btn-clear-cart', 'click', () => { PosCart.clear(); PosUI.toast('Koszyk wyczyszczony', 'info'); });
        _on('#btn-half', 'click', _toggleHalf);
        _on('#btn-logout', 'click', () => { PosAPI.clearToken(); _user = null; location.reload(); });
        _on('#btn-send-route', 'click', _sendRoute);
        _on('#btn-create-course', 'click', _createCourse);

        // Universal nav bar — hard URL redirects (micro-frontend routing)
        document.querySelectorAll('#nav-tabs .nav-tab[data-href]').forEach(tab => {
            tab.addEventListener('click', () => { window.location.href = tab.dataset.href; });
        });

        // Order type buttons
        document.querySelectorAll('.order-type-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (_tableLocked) return;
                _setOrderType(btn.dataset.type);
            });
        });

        // Routes button in topbar
        _on('#btn-view-routes', 'click', () => _setFilter(_filterType === 'routes' ? 'all' : 'routes'));

        PosCart.clear();
        await _loadInitData();

        // Apply URL intent after data is loaded
        if (intent) {
            _applyUrlIntent(intent);
        } else {
            _switchView('battlefield');
        }

        _startPolling();

        initPosHrClock(PosAPI, PosUI, TENANT_ID);

        // Global keyboard shortcut: Escape closes any open modal
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach((m) => m.classList.remove('active'));
            }
        });
    }

    // =========================================================================
    // URL INTENT PARSER (Cart-First bridge from Table Map)
    // =========================================================================
    function _parseUrlIntent() {
        const params = new URLSearchParams(window.location.search);
        if (!params.toString()) return null;

        const intent = {
            tableId:      params.get('table_id'),
            tableNumber:  params.get('table_number'),
            guestCount:   params.get('guest_count'),
            orderType:    params.get('order_type'),
            editOrderId:  params.get('edit_order_id'),
        };

        // Clean URL to prevent re-processing on manual refresh
        history.replaceState(null, '', window.location.pathname);

        if (intent.editOrderId || intent.tableId || intent.orderType) return intent;
        return null;
    }

    function _applyUrlIntent(intent) {
        if (intent.editOrderId) {
            const oid = intent.editOrderId;
            const found = _orders.find(x => String(x.id) === String(oid));
            if (found) {
                if (intent.tableId) {
                    PosCart.setTableContext(intent.tableId, intent.tableNumber || '', found.guest_count || 0);
                    _tableLocked = true;
                    _lockOrderTypeButtons(true);
                    _updateTableBanner();
                }
                _openEditInCart(oid);
            } else {
                PosUI.toast('Zamówienie nie znalezione — otwarto pulpit', 'error');
                _switchView('battlefield');
            }
        } else if (intent.orderType === 'dine_in') {
            _editOrderId = null;
            _isCartLocked = false;
            PosCart.clear();

            if (intent.tableId) {
                PosCart.setTableContext(intent.tableId, intent.tableNumber || '', intent.guestCount || 1);
                _tableLocked = true;
                _lockOrderTypeButtons(true);
                _updateTableBanner();
                const addr = document.getElementById('cust-addr');
                if (addr) addr.value = intent.tableNumber || intent.tableId;
            } else {
                _tableLocked = false;
                PosCart.clearTableContext();
                _updateTableBanner();
            }

            _setOrderType('dine_in');
            document.getElementById('edit-mode-badge')?.classList.add('hidden');
            _switchView('creator');
            _renderMenu();
        } else {
            _switchView('battlefield');
        }
    }

    // =========================================================================
    // TABLE CONTEXT UI HELPERS
    // =========================================================================
    function _lockOrderTypeButtons(lock) {
        document.querySelectorAll('.order-type-btn').forEach(btn => {
            if (btn.dataset.type !== 'dine_in') {
                btn.disabled = lock;
                btn.style.opacity = lock ? '0.3' : '';
                btn.style.pointerEvents = lock ? 'none' : '';
            }
        });
    }

    function _updateTableBanner() {
        const banner = document.getElementById('cart-table-banner');
        if (!banner) return;
        const ctx = PosCart.getTableContext();
        if (ctx.tableId) {
            const guestStr = ctx.guestCount ? ` · Gości: ${ctx.guestCount}` : '';
            banner.innerHTML = `<span style="font-size:14px">🍽️</span>`
                + `<span>Stolik ${ctx.tableNumber || ctx.tableId}${guestStr}</span>`;
            banner.classList.remove('hidden');
            banner.style.display = 'flex';
        } else {
            banner.classList.add('hidden');
            banner.style.display = 'none';
        }
    }

    function _exitTableContext() {
        if (_tableLocked) {
            _tableLocked = false;
            PosCart.clearTableContext();
            _lockOrderTypeButtons(false);
            _updateTableBanner();
        }
        _switchView('battlefield');
    }

    function _on(sel, evt, fn) {
        const el = document.querySelector(sel);
        if (el) el.addEventListener(evt, fn);
    }

    // =========================================================================
    // DATA LOADING
    // =========================================================================
    async function _loadInitData() {
        const res = await PosAPI.getInitData();
        if (!res.success || !res.data) { PosUI.toast('Błąd ładowania danych', 'error'); return; }
        const d = res.data;
        _menuData.categories = d.categories || [];
        _menuData.ingredients = d.ingredients || [];
        _menuData.drivers = d.drivers || [];
        _menuData.waiters = d.waiters || [];
        _menuData.modifierGroups = d.modifierGroups || [];
        // F-S1 (2026-05-11): variant groups — kazda grupa to parent + lista wariantow.
        _menuData.variantGroups = d.variantGroups || [];
        // F-S3.1 (2026-05-11): meal packages — kafelki combo w POS.
        _menuData.mealPackages = d.mealPackages || [];

        const channel = PosCart.getChannel();
        _menuData.items = (d.items || []).map(item => {
            const tiers = item.priceTiers || [];
            let priceRow = tiers.find(p => p.channel === channel) || tiers.find(p => p.channel === 'POS');
            const priceGrosze = priceRow ? Math.round(parseFloat(priceRow.price) * 100) : 0;
            return {
                id: parseInt(item.id), category_id: parseInt(item.categoryId),
                name: item.name, ascii_key: item.asciiKey || '',
                image_url: item.imageUrl || '', description: item.description || '',
                priceGrosze, price: (priceGrosze / 100).toFixed(2),
                vatDine: parseFloat(item.vatDineIn || 8), vatTake: parseFloat(item.vatTakeaway || 5),
                priceTiers: tiers,
                // F-S1 — variant meta (kazdy wariant wie ze nalezy do rodziny)
                parentAsciiKey: item.parentAsciiKey || null,
                parentName: item.parentName || null,
                variantOptionName: item.variantOptionName || null,
                variantOptionKey: item.variantOptionKey || null,
                variantMultiplier: item.variantMultiplier ?? 1.0,
            };
        });

        PosUI.renderDrivers(_menuData.drivers, _routeDriverId, _onDriverClick);
        PosUI.renderWaiters(_menuData.waiters);
        await _fetchOrders();
    }

    async function _fetchOrders() {
        const res = await PosAPI.getOrders();
        if (res.success && res.data) {
            _orders = res.data.orders || [];
            if (res.data.drivers) {
                _menuData.drivers = res.data.drivers;
            }
            _renderBattlefield();
        }
    }

    function _startPolling() {
        if (_pollTimer) clearInterval(_pollTimer);
        _pollTimer = setInterval(_fetchOrders, POLL_INTERVAL);

        // P4: po udanym replayu outboxu (offline → online) UI refetchuje listę,
        // żeby pokazać realne server-side IDs i statusy zamiast optymistycznych.
        window.addEventListener('slicehub-pos:outbox-replayed', () => {
            _fetchOrders();
        });

        // P3.5: gdy serwer wypchnie nowe zdarzenie przez pull_since (np. storefront
        // utworzył zamówienie, KDS zmienił status), odświeżamy listę.
        window.addEventListener('slicehub-pos:server-event', (e) => {
            const ev = e.detail || {};
            if (!ev.event_type) return;
            // Order-related events — reaguj tylko na ten typ, żeby nie robić
            // niepotrzebnych fetchów przy menu.updated czy system.test.
            if (ev.event_type === 'order.created' || ev.event_type === 'order.status') {
                _fetchOrders();
            }
        });
    }

    // =========================================================================
    // VIEW SWITCHING
    // =========================================================================
    function _switchView(view) {
        const bf = document.getElementById('view-battlefield');
        const cr = document.getElementById('view-creator');
        if (view === 'battlefield') {
            bf.classList.remove('hidden');
            cr.classList.add('hidden');
            _fetchOrders();
        } else {
            bf.classList.add('hidden');
            cr.classList.remove('hidden');
        }
    }

    // =========================================================================
    // ORDER TYPE SELECTOR MODAL
    // =========================================================================
    function _openOrderTypeSelector() {
        PosUI.showOrderTypeModal((type) => {
            if (type === 'dine_in') {
                _openTableSelector();
                return;
            }
            _finishNewOrder(type);
        });
    }

    function _finishNewOrder(type, tableCtx) {
        _editOrderId = null;
        _isCartLocked = false;
        PosCart.clear();
        if (tableCtx) {
            PosCart.setTableContext(tableCtx.id, tableCtx.table_number, 1);
            _tableLocked = true;
            _lockOrderTypeButtons(true);
            _updateTableBanner();
            const addr = document.getElementById('cust-addr');
            if (addr) addr.value = tableCtx.table_number || '';
        }
        _setOrderType(type);
        document.getElementById('edit-mode-badge').classList.add('hidden');
        if (!tableCtx) {
            document.getElementById('cust-addr').value = '';
        }
        document.getElementById('cust-phone').value = '';
        document.getElementById('cust-name') && (document.getElementById('cust-name').value = '');
        document.getElementById('cust-nip').value = '';
        _switchView('creator');
        _renderMenu();
    }

    async function _openTableSelector() {
        const res = await PosAPI.getAvailableTables();
        const tables = (res.success && res.data?.tables) ? res.data.tables : [];
        PosUI.showTableSelectorModal(tables, (table) => {
            _finishNewOrder('dine_in', table);
            if (table) {
                PosUI.toast(`Stolik ${table.table_number} wybrany`, 'success');
            } else {
                PosUI.toast('Zamówienie na miejscu bez stolika', 'info');
            }
        });
    }

    // =========================================================================
    // ORDER TYPE & DELIVERY FIELDS
    // =========================================================================
    function _setOrderType(type) {
        PosCart.setOrderType(type);
        document.querySelectorAll('.order-type-btn').forEach(b =>
            b.classList.toggle('active', b.dataset.type === type)
        );
        const df = document.getElementById('delivery-fields');
        const addrInput = document.getElementById('cust-addr');
        const phoneInput = document.getElementById('cust-phone');
        const nameInput = document.getElementById('cust-name');

        if (type === 'delivery') {
            if (df) df.classList.remove('hidden');
            if (addrInput) addrInput.placeholder = 'Adres dostawy';
            if (phoneInput) { phoneInput.classList.remove('hidden'); phoneInput.placeholder = 'Telefon'; }
            if (nameInput) nameInput.classList.remove('hidden');
        } else if (type === 'dine_in') {
            if (df) df.classList.remove('hidden');
            if (addrInput) addrInput.placeholder = 'Nr stolika';
            if (phoneInput) phoneInput.classList.add('hidden');
            if (nameInput) nameInput.classList.add('hidden');
        } else {
            if (df) df.classList.remove('hidden');
            if (addrInput) addrInput.placeholder = 'Notatka (opcjonalnie)';
            if (phoneInput) phoneInput.classList.add('hidden');
            if (nameInput) nameInput.classList.remove('hidden');
        }
    }

    // =========================================================================
    // FILTER
    // =========================================================================
    function _setFilter(type) {
        _filterType = type;
        const routeBtn = document.getElementById('btn-view-routes');
        if (routeBtn) routeBtn.classList.toggle('active', type === 'routes');
        _renderBattlefield();
    }

    // =========================================================================
    // HALF-HALF
    // =========================================================================
    function _toggleHalf() {
        _halfMode = !_halfMode;
        _halfA = null;
        const btn = document.querySelector('#btn-half');
        if (btn) btn.classList.toggle('active', _halfMode);
        PosUI.toast(_halfMode ? 'Tryb ½+½: wybierz pierwszą połowę' : 'Tryb ½+½ wyłączony', 'info');
    }

    // =========================================================================
    // MENU RENDERING
    // =========================================================================
    function _renderMenu() {
        if (_menuData.categories.length && !_activeCategoryId) {
            _activeCategoryId = _menuData.categories[0].id;
        }
        PosUI.renderCategories(_menuData.categories, _activeCategoryId, (catId) => {
            _activeCategoryId = catId;
            _renderMenu();
        });
        // F-S1 (2026-05-11): dedup wariantow w kafelkach.
        // Z kazdej variant family pokazujemy jeden „ambassador" (pierwszy wariant)
        // z badge'm „RM" (rozmiary). Klikniecie wymusza wybor rozmiaru.
        const all = _menuData.items.filter(i => i.category_id === _activeCategoryId);
        const seenParents = new Set();
        const displayItems = [];
        for (const it of all) {
            if (it.parentAsciiKey) {
                if (seenParents.has(it.parentAsciiKey)) continue;
                seenParents.add(it.parentAsciiKey);
                displayItems.push({
                    ...it,
                    name: it.parentName || it.name,
                    _isVariantAmbassador: true,
                });
            } else {
                displayItems.push(it);
            }
        }
        // F-S3.1 (2026-05-11): meal packages — wirtualne kafelki combo w aktualnej kategorii.
        // Combo bez kategorii (`category_id IS NULL`) trafia do wszystkich.
        const meals = (_menuData.mealPackages || []).filter(m =>
            !m.category_id || parseInt(m.category_id, 10) === parseInt(_activeCategoryId, 10)
        );
        for (const m of meals) {
            const priceTxt = m.final_price_grosze
                ? (parseInt(m.final_price_grosze, 10) / 100).toFixed(2)
                : '—';
            displayItems.push({
                id: 'meal_' + m.id,
                _isMealPackage: true,
                _mealId: parseInt(m.id, 10),
                category_id: parseInt(_activeCategoryId, 10),
                name: '🍔 ' + m.name,
                ascii_key: m.ascii_key,
                image_url: m.image_url || '',
                description: m.description || '',
                priceGrosze: m.final_price_grosze ? parseInt(m.final_price_grosze, 10) : 0,
                price: priceTxt,
                vatDine: 8, vatTake: 5,
                priceTiers: [],
                _components: m.components || [],
            });
        }
        PosUI.renderItemGrid(displayItems, _onItemClick);
    }

    // F-S3.1 — Combo wizard (Petpooja-style).
    // Dla `fixed` combo: wszystkie składniki są ustalone, idą do koszyka jako paczka.
    // Dla `choice` combo: pokaż wizard z dropdown'ami per `category_choice` component.
    function _openMealWizard(meal) {
        const fixedItems = (meal._components || []).filter(c => c.component_type === 'fixed_item');
        const choices    = (meal._components || []).filter(c => c.component_type === 'category_choice');

        const modal = document.createElement('div');
        modal.id = 'fs31-meal-wizard';
        modal.className = 'sh-modal-backdrop';

        const panel = document.createElement('div');
        panel.className = 'sh-meal-panel';

        // Header
        const header = document.createElement('div');
        header.className = 'sh-modal-header';
        header.innerHTML = `
            <div>
                <h3 class="sh-modal-title">${meal.name}</h3>
                <p class="sh-meal-subtitle">F-S3.1 · Combo</p>
            </div>`;
        const closeBtn = document.createElement('button');
        closeBtn.className = 'sh-modal-close-btn';
        closeBtn.innerHTML = '×';
        closeBtn.onclick = () => modal.remove();
        header.appendChild(closeBtn);
        panel.appendChild(header);

        // Body
        const body = document.createElement('div');
        body.className = 'sh-modal-body';

        if (fixedItems.length) {
            const fixedDiv = document.createElement('div');
            fixedDiv.className = 'sh-meal-fixed-section';
            fixedDiv.innerHTML = `<div class="sh-meal-fixed-label">W zestawie:</div>`;
            const ul = document.createElement('ul');
            ul.className = 'sh-meal-fixed-list';
            fixedItems.forEach(c => {
                const li = document.createElement('li');
                li.className = 'sh-meal-fixed-item';
                li.innerHTML = `<i class="fa-solid fa-check sh-meal-fixed-check"></i> ${c.qty}× <span class="sh-meal-fixed-sku">${c.item_sku}</span>`;
                ul.appendChild(li);
            });
            fixedDiv.appendChild(ul);
            body.appendChild(fixedDiv);
        }

        choices.forEach((c, idx) => {
            const catItems = _menuData.items.filter(i =>
                i.category_id === parseInt(c.category_id, 10) && !i.parentAsciiKey
            );
            const choiceDiv = document.createElement('div');
            choiceDiv.className = 'sh-meal-choice-block';
            choiceDiv.innerHTML = `<div class="sh-meal-choice-label">Wybór #${idx+1} (${c.qty}× z kategorii)</div>`;
            const select = document.createElement('select');
            select.className = 'sh-meal-select fs31-choice-pick';
            select.dataset.choiceIdx = String(idx);
            select.dataset.componentId = String(c.id);
            const defaultOpt = document.createElement('option');
            defaultOpt.value = '';
            defaultOpt.textContent = '— wybierz —';
            select.appendChild(defaultOpt);
            catItems.forEach(ci => {
                const opt = document.createElement('option');
                opt.value = ci.ascii_key;
                opt.dataset.price = String(ci.priceGrosze);
                opt.textContent = `${ci.name} — ${ci.price} zł`;
                select.appendChild(opt);
            });
            choiceDiv.appendChild(select);
            body.appendChild(choiceDiv);
        });

        const priceBlock = document.createElement('div');
        priceBlock.className = 'sh-meal-price-block';
        priceBlock.innerHTML = `<div class="sh-meal-price-label">Cena combo</div><div class="sh-meal-price-val">${meal.price} zł</div>`;
        body.appendChild(priceBlock);
        panel.appendChild(body);

        // Footer
        const footer = document.createElement('div');
        footer.className = 'sh-meal-footer';
        const addBtn = document.createElement('button');
        addBtn.id = 'fs31-add-meal-btn';
        addBtn.className = 'sh-meal-add-btn';
        addBtn.innerHTML = '<i class="fa-solid fa-cart-plus"></i> Dodaj zestaw do koszyka';
        addBtn.onclick = () => window.PosMealCart._confirm(meal._mealId);
        footer.appendChild(addBtn);
        panel.appendChild(footer);

        modal.appendChild(panel);
        modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);

        // Eksponuj confirm jako method do button onclick
        window.PosMealCart = window.PosMealCart || {};
        window.PosMealCart._confirm = (mealId) => {
            const picks = [];
            modal.querySelectorAll('.fs31-choice-pick').forEach(sel => {
                if (sel.value) picks.push({ component_id: parseInt(sel.dataset.componentId, 10), sku: sel.value });
            });
            // Walidacja: wszystkie choices muszą być wybrane.
            if (picks.length < choices.length) {
                alert('Wybierz wszystkie pozycje z wymaganych kategorii.');
                return;
            }
            // Dodaj jako 1 linię koszyka z meta combo.
            const comboItem = {
                id: 'meal_' + mealId,
                ascii_key: meal.ascii_key,
                name: meal.name.replace('🍔 ', ''),
                image_url: meal.image_url,
                priceGrosze: meal.priceGrosze,
                price: meal.price,
                vatDine: 8, vatTake: 5,
                _isMealLine: true,
                _mealId: mealId,
                _mealPicks: picks,
                _mealFixedItems: fixedItems.map(c => ({ sku: c.item_sku, qty: c.qty })),
            };
            PosCart.addItem(comboItem, 1, [], [], `Combo #${mealId} | picks: ${picks.map(p=>p.sku).join(', ')}`);
            PosUI.toast(`${meal.name} dodano`, 'success');
            modal.remove();
        };
    }

    // F-S1 — Modal wyboru rozmiaru (przed dish card).
    // Pokazywany gdy kliknieto ambassador wariantu lub gdy item jest czescia rodziny.
    function _openVariantPicker(ambassadorItem, onSelected) {
        const parentKey = ambassadorItem.parentAsciiKey;
        const siblings = _menuData.items
            .filter(i => i.parentAsciiKey === parentKey)
            .sort((a, b) => (a.variantOptionKey || '').localeCompare(b.variantOptionKey || ''));
        if (siblings.length === 0) { onSelected(ambassadorItem); return; }

        const modal = document.createElement('div');
        modal.id = 'fs1-variant-picker';
        modal.className = 'sh-modal-backdrop';

        const panel = document.createElement('div');
        panel.className = 'sh-modal-panel';

        // Header
        const header = document.createElement('div');
        header.className = 'sh-modal-header';
        header.innerHTML = `
            <div>
                <h3 class="sh-modal-title">${ambassadorItem.parentName || ambassadorItem.name}</h3>
                <p class="sh-modal-subtitle">Wybierz rozmiar</p>
            </div>`;
        const closeBtn = document.createElement('button');
        closeBtn.className = 'sh-modal-close-btn';
        closeBtn.innerHTML = '×';
        closeBtn.onclick = () => modal.remove();
        header.appendChild(closeBtn);
        panel.appendChild(header);

        // Variant list
        const listEl = document.createElement('div');
        listEl.className = 'sh-modal-body';
        listEl.id = 'fs1-variant-list';

        siblings.forEach(v => {
            const priceTxt = (v.priceGrosze / 100).toFixed(2);
            const btn = document.createElement('button');
            btn.className = 'sh-variant-option-btn';
            btn.innerHTML = `
                <div class="sh-variant-option-left">
                    <span class="sh-variant-option-name">${v.variantOptionName || v.name}</span>
                    <span class="sh-variant-option-key">${v.ascii_key}</span>
                </div>
                <div class="sh-variant-option-right">
                    <span class="sh-variant-option-price">${priceTxt} zł</span>
                    <i class="fa-solid fa-chevron-right sh-variant-option-arrow"></i>
                </div>`;
            btn.onclick = () => {
                modal.remove();
                onSelected(v);
            };
            listEl.appendChild(btn);
        });

        panel.appendChild(listEl);
        modal.appendChild(panel);
        modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    }

    // =========================================================================
    // ITEM CLICK → DISH CARD
    // =========================================================================
    function _onItemClick(item) {
        // Defensive log — pozwala debugowac przypadki gdy kafelek nie reaguje na klik.
        // Pojawia sie tylko gdy _onItemClick faktycznie zostal wywolany.
        console.log('[POS] _onItemClick:', {
            id: item?.id,
            name: item?.name,
            asciiKey: item?.ascii_key,
            isVariantAmbassador: item?._isVariantAmbassador,
            parentAsciiKey: item?.parentAsciiKey,
            isMealPackage: item?._isMealPackage,
        });

        if (_isCartLocked) { PosUI.toast('Koszyk zablokowany — wydrukowano paragon', 'error'); return; }

        // F-S3.1 (2026-05-11): kafelek combo otwiera meal wizard.
        if (item._isMealPackage) {
            _openMealWizard(item);
            return;
        }

        // F-S1 (2026-05-11): jesli kafelek to ambassador rodziny — otworz picker rozmiaru.
        if (item._isVariantAmbassador && item.parentAsciiKey) {
            _openVariantPicker(item, (chosenVariant) => {
                if (_halfMode) {
                    if (!_halfA) {
                        _halfA = chosenVariant;
                        PosUI.toast(`½ ${chosenVariant.name} — teraz drugą połowę`, 'info');
                    } else {
                        _openHalfDishCard(_halfA, chosenVariant);
                        _halfA = null; _halfMode = false;
                        const btn = document.querySelector('#btn-half');
                        if (btn) btn.classList.remove('active');
                    }
                } else {
                    _openDishCard(chosenVariant);
                }
            });
            return;
        }

        if (_halfMode) {
            if (!_halfA) {
                _halfA = item;
                PosUI.toast(`½ ${item.name} — teraz drugą połowę`, 'info');
            } else {
                _openHalfDishCard(_halfA, item);
                _halfA = null; _halfMode = false;
                const btn = document.querySelector('#btn-half');
                if (btn) btn.classList.remove('active');
            }
            return;
        }
        _openDishCard(item);
    }

    async function _openDishCard(item) {
        const groups = _menuData.modifierGroups.filter(g => g.itemIds.includes(item.id));

        let ingredients = [];
        const res = await PosAPI.getItemDetails(item.id);
        if (res.success && res.data) {
            ingredients = (res.data.ingredients || []).map(ing => ({
                sku: ing.sku, name: ing.name || ing.sku, unit: ing.unit || '',
            }));
        }

        const modGroups = groups.map(g => ({
            name: g.name,
            min_selection: g.minSelection || 0,
            modifiers: (g.modifiers || []).map(m => {
                const channel = PosCart.getChannel();
                const pr = m.prices?.[channel] || m.prices?.['POS'] || 0;
                return {
                    ascii_key: m.asciiKey, name: m.name,
                    priceGrosze: Math.round(parseFloat(pr) * 100),
                };
            }),
        }));

        PosUI.showDishCard(item, ingredients, modGroups, (result) => {
            PosCart.addItem(item, result.quantity, result.addedModifiers, result.removedIngredients, result.comment);
            PosUI.toast(`${item.name} dodano`, 'success');
        });
    }

    async function _openHalfDishCard(itemA, itemB) {
        const compositeItem = {
            name: `½ ${itemA.name} + ½ ${itemB.name}`,
            ascii_key: `${itemA.ascii_key}+${itemB.ascii_key}`,
            image_url: itemA.image_url || itemB.image_url,
            priceGrosze: Math.max(itemA.priceGrosze, itemB.priceGrosze) + 200,
            vatDine: itemA.vatDine, vatTake: itemA.vatTake,
        };

        let ingredients = [];
        const res = await PosAPI.getItemDetails(itemA.id, itemB.id);
        if (res.success && res.data) {
            ingredients = (res.data.ingredients || []).map(ing => ({
                sku: ing.sku, name: `[${ing.half === 'A' ? '½ ' + itemA.name : '½ ' + itemB.name}] ${ing.name || ing.sku}`,
            }));
        }

        PosUI.showDishCard(compositeItem, ingredients, [], (result) => {
            PosCart.addHalf(itemA, itemB, result.quantity, result.addedModifiers, result.removedIngredients, result.comment);
            PosUI.toast('½+½ dodano', 'success');
        });
    }

    function _editCartLine(lineId) {
        if (_isCartLocked) return;
        const line = PosCart.getLines().find(l => l.lineId === lineId);
        if (!line) return;

        if (line.isHalf) return;

        const item = _menuData.items.find(i => i.ascii_key === line.itemSku);
        if (!item) return;

        const groups = _menuData.modifierGroups.filter(g => g.itemIds.includes(item.id));
        const modGroups = groups.map(g => ({
            name: g.name,
            min_selection: g.minSelection || 0,
            modifiers: (g.modifiers || []).map(m => {
                const channel = PosCart.getChannel();
                const pr = m.prices?.[channel] || m.prices?.['POS'] || 0;
                return { ascii_key: m.asciiKey, name: m.name, priceGrosze: Math.round(parseFloat(pr) * 100) };
            }),
        }));

        PosAPI.getItemDetails(item.id).then(res => {
            let ingredients = [];
            if (res.success && res.data) {
                ingredients = (res.data.ingredients || []).map(ing => ({
                    sku: ing.sku, name: ing.name || ing.sku, unit: ing.unit || '',
                }));
            }

            PosUI.showDishCardEdit(item, ingredients, modGroups, line, (result) => {
                PosCart.updateLine(lineId, {
                    quantity: result.quantity,
                    addedModifiers: result.addedModifiers,
                    removedIngredients: result.removedIngredients,
                    comment: result.comment,
                });
                PosUI.toast(`${item.name} zaktualizowano`, 'success');
            });
        });
    }

    // =========================================================================
    // CHECKOUT MODAL (Full legacy logic)
    // =========================================================================
    function _openCheckout() {
        const snapshot = PosCart.getSnapshot();
        if (snapshot.lineCount === 0) { PosUI.toast('Koszyk jest pusty!', 'error'); return; }

        const orderType = snapshot.orderType;
        if (orderType === 'delivery' && !document.getElementById('cust-addr')?.value?.trim()) {
            PosUI.toast('Podaj adres dostawy!', 'error'); return;
        }
        if (orderType === 'delivery' && !document.getElementById('cust-phone')?.value?.trim()) {
            PosUI.toast('Podaj numer telefonu!', 'error'); return;
        }

        PosUI.showCheckoutModal(snapshot, {
            isEdit: !!_editOrderId,
            orderType,
            onSubmit: async (opts) => {
                // Mandatory receipt for card/online
                if (['card','online_paid'].includes(opts.payStatus) && !opts.printReceipt) {
                    PosUI.toast('Dla karty/online paragon jest obowiązkowy!', 'error'); return;
                }

                const cartForApi = PosCart.getLines().map(l => ({
                    cart_id: l.lineId, line_id: l.lineId,
                    id: l.itemSku || null, ascii_key: l.itemSku,
                    name: l.snapshotName, price: (l.unitPriceGrosze / 100).toFixed(2),
                    qty: l.quantity, quantity: l.quantity,
                    vat_rate: l.vatRate ?? 8,
                    // F5-A (2026-05-11): ujednolicenie kontraktu z online checkout (CartEngine).
                    // POS wysyła `sku` (nie `ascii_key`) żeby WzEngine::consumeForOrder konsumował
                    // magazyn dla modyfikatorów ADD. Konstytucja v5 § Prawo II (Bliźniak Cyfrowy).
                    removed: l.removedIngredients.map(r => ({ sku: r.sku, name: r.name })),
                    added: l.addedModifiers.map(m => ({ sku: m.ascii_key, ascii_key: m.ascii_key, name: m.name, price: (m.priceGrosze / 100).toFixed(2) })),
                    comment: l.comment,
                    is_half: l.isHalf, half_a: l.halfASku || null, half_b: l.halfBSku || null,
                    // F-S3.2 (2026-05-11): combo meta (meal_id + picks + fixed_items) — backend zapisze do combo_meta_json
                    combo_meta: l.comboMeta || null,
                }));

                const total = parseFloat(snapshot.subtotalFormatted);

                const tableCtx = PosCart.getTableContext();
                const payload = {
                    edit_order_id: _editOrderId || 0,
                    cart: cartForApi, source: 'local', status: 'new',
                    order_type: PosCart.getOrderType(),
                    payment_method: opts.payMethod, payment_status: opts.payStatus,
                    total_price: total,
                    address: document.getElementById('cust-addr')?.value || '',
                    customer_phone: document.getElementById('cust-phone')?.value || '',
                    customer_name: document.getElementById('cust-name')?.value || '',
                    nip: document.getElementById('cust-nip')?.value || '',
                    custom_datetime: opts.promisedTime,
                    print_kitchen: opts.printKitchen ? 1 : 0,
                    print_receipt: opts.printReceipt ? 1 : 0,
                    table_id: tableCtx.tableId || null,
                    guest_count: tableCtx.guestCount || null,
                };

                const res = await PosAPI.processOrder(payload);
                if (res.success) {
                    const orderId = res.data?.order_id || 'NEW';
                    const waiterName = _user?.name || _user?.username || 'POS';
                    if (opts.printKitchen) {
                        const orderForPrint = { order_number: orderId, order_type: PosCart.getOrderType(), cart: cartForApi, total, address: payload.address, customer_phone: payload.customer_phone, customer_name: payload.customer_name, created_at: new Date().toISOString(), waiter_name: waiterName, is_edit: !!_editOrderId };
                        PosUI.printTemplate(orderForPrint, true);
                    }
                    if (opts.printReceipt) {
                        const orderForPrint = { order_number: orderId, order_type: PosCart.getOrderType(), cart: cartForApi, total, address: payload.address, customer_phone: payload.customer_phone, customer_name: payload.customer_name, created_at: new Date().toISOString(), waiter_name: waiterName };
                        PosUI.printTemplate(orderForPrint, false);
                    }
                    PosUI.toast(_editOrderId ? 'Zamówienie zaktualizowane!' : 'Zamówienie zapisane!', 'success');
                    _editOrderId = null;
                    PosCart.clear();
                    _exitTableContext();
                } else {
                    PosUI.toast(res.message || 'Błąd', 'error');
                }
            },
        });
    }

    // =========================================================================
    // PANIC MODE
    // =========================================================================
    async function _triggerPanic() {
        if (!confirm('Dodać +20 minut do wszystkich zamówień w toku?')) return;
        const r = await PosAPI.panicMode();
        if (r.success) { PosUI.toast('Wydłużono czasy o 20 minut!', 'success'); _fetchOrders(); }
    }

    // =========================================================================
    // BATTLEFIELD RENDERING (delegates to PosUI)
    // =========================================================================
    function _renderBattlefield() {
        const now = new Date();
        let counts = { all: 0, delivery: 0, dine_in: 0, takeaway: 0, routes: 0 };
        let pulseCount = 0;

        _orders.forEach(o => {
            const isOnlineNew = o.source !== 'local' && o.status === 'new';
            const isInRoute = o.delivery_status === 'in_delivery';
            const isQueued = o.delivery_status === 'queued';
            if (isOnlineNew) { pulseCount++; return; }
            if (isInRoute) { counts.routes++; return; }
            if (isQueued) { counts.routes++; }
            counts.all++;
            if (o.order_type === 'delivery') counts.delivery++;
            if (o.order_type === 'dine_in') counts.dine_in++;
            if (o.order_type === 'takeaway') counts.takeaway++;
        });

        // Update route counter badge
        const el = (id) => document.getElementById(id);
        if (el('fcnt-routes'))   el('fcnt-routes').textContent = counts.routes;
        if (el('pulse-count'))   { el('pulse-count').textContent = pulseCount; el('pulse-count').classList.toggle('hidden', pulseCount === 0); }

        // Render Pulse (online orders)
        const onlineOrders = _orders.filter(o => o.source !== 'local' && o.status === 'new');
        // Sound alert
        onlineOrders.forEach(o => {
            if (_lastPlayedId !== o.id) { document.getElementById('alert-sound')?.play().catch(() => {}); _lastPlayedId = o.id; }
        });

        PosUI.renderPulse(onlineOrders, _expandedOnlineId, {
            onToggle: (id) => { _expandedOnlineId = _expandedOnlineId === id ? null : id; _renderBattlefield(); },
            onAccept: async (id, mins) => {
                const t = new Date(); t.setMinutes(t.getMinutes() + mins);
                const iso = t.toISOString().slice(0, 16);
                const r = await PosAPI.acceptOrder(id, iso);
                if (!r.success) {
                    PosUI.toast(r.message || 'Nie udało się przyjąć zamówienia', 'error');
                    return;
                }
                PosUI.toast('Zamówienie przyjęte', 'success');
                _expandedOnlineId = null; _fetchOrders();
            },
            onAcceptDate: async (id, dateStr) => {
                if (!dateStr) return;
                const r = await PosAPI.acceptOrder(id, dateStr);
                if (!r.success) {
                    PosUI.toast(r.message || 'Nie udało się przyjąć zamówienia', 'error');
                    return;
                }
                PosUI.toast('Zamówienie przyjęte', 'success');
                _expandedOnlineId = null; _fetchOrders();
            },
        });

        // Render Kanban (main battlefield)
        const activeOrders = _orders.filter(o => {
            if (o.source !== 'local' && o.status === 'new') return false;
            if (o.delivery_status === 'in_delivery') return _filterType === 'routes';
            if (o.delivery_status === 'queued') return true;
            if (_filterType === 'routes') return false;
            if (_filterType !== 'all' && o.order_type !== _filterType) return false;
            return true;
        });

        PosUI.renderKanban(activeOrders, _filterType, _expandedOrderId, _routeDriverId, _routeOrders, {
            onCardClick: (id) => {
                const order = _orders.find(o => o.id === id);
                if (order?.delivery_status === 'queued') {
                    _expandedOrderId = _expandedOrderId === id ? null : id;
                    _renderBattlefield();
                    return;
                }
                if ((_routeDriverId || _routeOrders.length > 0) && order?.order_type === 'delivery') {
                    _toggleOrderToRoute(id);
                } else {
                    _expandedOrderId = _expandedOrderId === id ? null : id;
                    _renderBattlefield();
                }
            },
            getDriverName: (driverId) => {
                const d = _menuData.drivers.find(dr => String(dr.id) === String(driverId));
                return d ? (d.display_name || d.first_name || 'Kierowca') : 'Kierowca';
            },
            onAssignCourse: (courseId) => {
                _assignCourseId = courseId;
                PosUI.toast(`Wybierz kierowcę z floty dla kursu ${courseId}`, 'info');
            },
            onPrintKitchen: async (id) => {
                const o = _orders.find(x => x.id === id);
                if (o) PosUI.printOrderTemplate(o, true, { waiterName: _user?.name || 'POS' });
                await PosAPI.printKitchen(id);
                PosUI.toast('Bon na kuchnię wysłany', 'success');
                _fetchOrders();
            },
            onPrintReceipt: (id) => _openPaymentModal(id, 'print'),
            onEdit:         (id) => _openEditInCart(id),
            onSettle:       (id) => _openPaymentModal(id, 'settle'),
            onCancel:       (id) => _openCancelModal(id),
            onStatusChange: async (id, status) => {
                await PosAPI.updateStatus(id, status);
                _fetchOrders();
            },
            onColumnToggle: (colIdx) => {
                // State kept via grid dataset, no extra action needed
            },
        });

        // Drivers
        PosUI.renderDrivers(_menuData.drivers, _routeDriverId, _onDriverClick);
        PosUI.renderWaiters(_menuData.waiters);
    }

    // =========================================================================
    // FLEET / ROUTE BUILDER
    // =========================================================================
    function _onDriverClick(driverId) {
        if (_assignCourseId) {
            _assignDriverToCourse(_assignCourseId, driverId);
            return;
        }
        if (_routeDriverId === driverId) {
            _routeDriverId = null;
        } else {
            _routeDriverId = driverId;
        }
        _updateFleetButtons();
        _renderBattlefield();
    }

    function _updateFleetButtons() {
        const sendBtn = document.getElementById('btn-send-route');
        const createBtn = document.getElementById('btn-create-course');
        if (!sendBtn || !createBtn) return;
        const hasOrders = _routeOrders.length > 0;
        if (_routeDriverId && hasOrders) {
            sendBtn.classList.remove('hidden');
            createBtn.classList.add('hidden');
        } else if (!_routeDriverId && hasOrders) {
            sendBtn.classList.add('hidden');
            createBtn.classList.remove('hidden');
        } else if (_routeDriverId && !hasOrders) {
            sendBtn.classList.remove('hidden');
            createBtn.classList.add('hidden');
        } else {
            sendBtn.classList.add('hidden');
            createBtn.classList.add('hidden');
        }
    }

    function _toggleOrderToRoute(id) {
        const idx = _routeOrders.indexOf(id);
        if (idx >= 0) _routeOrders.splice(idx, 1);
        else _routeOrders.push(id);
        _updateFleetButtons();
        _renderBattlefield();
    }

    async function _sendRoute() {
        if (!_routeDriverId || _routeOrders.length === 0) {
            PosUI.toast('Wybierz kierowcę i zamówienia', 'error'); return;
        }
        const r = await PosAPI.assignRoute(_routeDriverId, _routeOrders);
        if (r.success) {
            PosUI.toast(`Kurs ${r.data?.course_id} wysłany!`, 'success');
            _routeDriverId = null; _routeOrders = [];
            _updateFleetButtons();
            _setFilter('routes');
            await _fetchOrders();
        } else if (r.data?.reason === 'driver_busy') {
            PosUI.toast(`${r.data.driver_name || 'Kierowca'} jest w trasie (${r.data.active_course_id || '?'}). Użyj Dispatchera, aby dołączyć zamówienia.`, 'warn');
        } else { PosUI.toast(r.message || 'Błąd', 'error'); }
    }

    async function _createCourse() {
        if (_routeOrders.length === 0) {
            PosUI.toast('Wybierz zamówienia do kursu', 'error'); return;
        }
        const r = await PosAPI.createCourse(_routeOrders);
        if (r.success) {
            PosUI.toast(`Kurs ${r.data?.course_id || ''} utworzony (bez kierowcy)`, 'success');
            _routeDriverId = null; _routeOrders = [];
            _updateFleetButtons();
            await _fetchOrders();
        } else { PosUI.toast(r.message || 'Błąd tworzenia kursu', 'error'); }
    }

    async function _assignDriverToCourse(courseId, driverId) {
        const r = await PosAPI.assignDriverToCourse(courseId, driverId);
        if (r.success) {
            PosUI.toast(`Kierowca przypisany do ${courseId}!`, 'success');
            _assignCourseId = null;
            _routeDriverId = null;
            _updateFleetButtons();
            await _fetchOrders();
        } else { PosUI.toast(r.message || 'Błąd przypisania', 'error'); _assignCourseId = null; }
    }

    // =========================================================================
    // PAYMENT / SETTLE MODAL
    // =========================================================================
    function _openPaymentModal(orderId, mode) {
        const o = _orders.find(x => x.id === orderId);
        if (!o) return;
        _settleOrderId = orderId;

        PosUI.showPaymentModal(o, mode, {
            onSettle: async (method, printReceipt) => {
                if (printReceipt) PosUI.printOrderTemplate(o, false, { waiterName: _user?.name || 'POS' });
                const r = await PosAPI.settleAndClose(orderId, method, printReceipt);
                if (r.success) { PosUI.toast('Zamknięto pomyślnie!', 'success'); _fetchOrders(); }
                else PosUI.toast(r.message || 'Błąd', 'error');
            },
            onPrintOnly: async (method) => {
                PosUI.printOrderTemplate(o, false, { waiterName: _user?.name || 'POS' });
                const r = await PosAPI.printReceipt(orderId, method);
                if (r.success) { PosUI.toast('Wydrukowano paragon', 'success'); _fetchOrders(); }
                else PosUI.toast(r.message || 'Błąd', 'error');
            },
        });
    }

    // =========================================================================
    // CANCEL MODAL
    // =========================================================================
    function _openCancelModal(orderId) {
        PosUI.showCancelModal(orderId, async (returnStock) => {
            const r = await PosAPI.cancelOrder(orderId, returnStock);
            if (r.success) { PosUI.toast('Anulowano', 'success'); _fetchOrders(); }
            else PosUI.toast(r.message || 'Błąd', 'error');
        });
    }

    // =========================================================================
    // EDIT ORDER IN CART
    // =========================================================================
    function _openEditInCart(orderId) {
        const o = _orders.find(x => String(x.id) === String(orderId));
        if (!o) return;

        _editOrderId = orderId;
        _isCartLocked = o.receipt_printed == 1;

        PosCart.clear();
        PosCart.setEditOrderId(orderId);
        PosCart.setLocked(_isCartLocked);

        let cartData = [];
        try {
            cartData = o.cart_json ? (typeof o.cart_json === 'string' ? JSON.parse(o.cart_json) : o.cart_json) : [];
        } catch { cartData = []; }

        if (Array.isArray(cartData) && cartData.length > 0) {
            PosCart.setLocked(false);
            PosCart.loadFromCartJson(cartData, orderId);
            PosCart.setLocked(_isCartLocked);
        } else if (o.lines && o.lines.length > 0) {
            PosCart.setLocked(false);
            PosCart.loadFromOrderLines(o.lines, orderId);
            PosCart.setLocked(_isCartLocked);
        }

        _setOrderType(o.order_type || 'dine_in');
        if (document.getElementById('cust-addr'))  document.getElementById('cust-addr').value = o.delivery_address || '';
        if (document.getElementById('cust-phone')) document.getElementById('cust-phone').value = o.customer_phone || '';
        if (document.getElementById('cust-name'))  document.getElementById('cust-name').value = o.customer_name || '';
        if (document.getElementById('cust-nip'))   document.getElementById('cust-nip').value = o.nip || '';

        const badge = document.getElementById('edit-mode-badge');
        if (badge) badge.classList.remove('hidden');

        _switchView('creator');
        if (_isCartLocked) {
            document.getElementById('cat-tabs').innerHTML = '';
            document.getElementById('item-grid').innerHTML = '<div class="empty-state" style="color:var(--accent-red)">🔒 Edycja zablokowana — paragon wydrukowany. Zmień tylko dane dostawy.</div>';
        } else {
            _renderMenu();
        }
    }

    return Object.freeze({ init });
})();

document.addEventListener('DOMContentLoaded', () => PosApp.init());
