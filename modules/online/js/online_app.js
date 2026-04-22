/**
 * SliceHub Online — bootstrap (Surface v1: menu + dish + koszyk).
 */
import OnlineAPI from './online_api.js';
import {
    renderLoading,
    renderErrorBanner,
    buildMenuAccordion,
    renderPopularCarousel,
    fillDishSheet,
    repaintSurface,
    updateCartFab,
    renderCartLines,
    renderCartSummary,
} from './online_ui.js';
import {
    buildTableSections,
    adaptSceneDishToLegacy,
    applyDishSheetTheme,
} from './online_table.js';
import { openCheckoutOverlay, readLastOrder } from './online_checkout.js';
import { mountDoorway } from './online_doorway.js';

// Feature flag — dodaj ?legacy=1 do URL, żeby wrócić do akordeonu + get_menu/get_dish.
const USE_LEGACY_MENU = new URLSearchParams(window.location.search).get('legacy') === '1';

const state = {
    channel: 'Delivery',
    orderType: 'delivery',
    categories: [],
    cart: [],
    lastCalc: null,
};

function syncChannelFromUi() {
    const takeaway = document.getElementById('ch-takeaway');
    const isTakeaway = takeaway?.classList.contains('is-active') || takeaway?.classList.contains('channel-pill--active');
    if (isTakeaway) {
        state.channel = 'Takeaway';
        state.orderType = 'takeaway';
    } else {
        state.channel = 'Delivery';
        state.orderType = 'delivery';
    }
}

function applySurfaceBackground(filename) {
    const root = document.documentElement;
    if (!filename) {
        root.style.removeProperty('--storefront-bg-image');
        return;
    }
    const url = `/slicehub/uploads/global_assets/${encodeURIComponent(filename)}`;
    root.style.setProperty('--storefront-bg-image', `url("${url}")`);
}

function flattenMenuItems(categories) {
    const out = [];
    (categories || []).forEach((cat) => {
        (cat.items || []).forEach((it) => out.push({ sku: it.sku, name: it.name }));
    });
    return out;
}

function findCategoryNameForSku(categories, sku) {
    for (const cat of categories || []) {
        if ((cat.items || []).some((i) => i.sku === sku)) return cat.name || '';
    }
    return '';
}

function collectDishSheetModsFromCounts(modCounts) {
    if (!(modCounts instanceof Map)) return [];
    const out = [];
    modCounts.forEach((count, sku) => {
        for (let i = 0; i < count; i++) out.push(sku);
    });
    return out;
}

function cartLinesForApi() {
    return state.cart.map((line) => {
        if (line.is_half && line.half_a_sku && line.half_b_sku) {
            return {
                item_sku: line.half_a_sku,
                quantity: line.qty,
                is_half: true,
                half_a_sku: line.half_a_sku,
                half_b_sku: line.half_b_sku,
                added_modifier_skus: line.added_modifier_skus || [],
            };
        }
        return {
            item_sku: line.itemSku,
            quantity: line.qty,
            added_modifier_skus: line.added_modifier_skus || [],
        };
    });
}

function cartCount() {
    return state.cart.reduce((s, l) => s + l.qty, 0);
}

function openDishSheet() {
    document.getElementById('dish-sheet')?.classList.remove('hidden');
}
function closeDishSheet() {
    document.getElementById('dish-sheet')?.classList.add('hidden');
}

function openCartDrawer() {
    const dr = document.getElementById('cart-drawer');
    if (dr) {
        dr.classList.remove('hidden');
        dr.setAttribute('aria-hidden', 'false');
    }
    recalcCart();
}
function closeCartDrawer() {
    const dr = document.getElementById('cart-drawer');
    if (!dr) return;
    dr.classList.add('hidden');
    dr.setAttribute('aria-hidden', 'true');
    const ae = document.activeElement;
    if (ae && dr.contains(ae)) {
        ae.blur();
    }
}

function formatMoneyPl(val) {
    if (val == null || val === '') return '—';
    const n = typeof val === 'string' ? parseFloat(val.replace(',', '.')) : Number(val);
    if (Number.isNaN(n)) return String(val);
    return n.toFixed(2).replace('.', ',') + ' zł';
}

async function recalcCart() {
    const elTotal = document.getElementById('cart-total');
    const sumWrap = document.getElementById('cart-summary');
    if (!state.cart.length) {
        state.lastCalc = null;
        if (elTotal) elTotal.textContent = '—';
        if (sumWrap) renderCartSummary(sumWrap, null);
        return;
    }
    const res = await OnlineAPI.cartCalculate({
        channel: state.channel,
        order_type: state.orderType,
        lines: cartLinesForApi(),
        promo_code: '',
    });
    if (res.success && res.data) {
        state.lastCalc = res.data;
        if (elTotal) elTotal.textContent = formatMoneyPl(res.data.grand_total);
        if (sumWrap) renderCartSummary(sumWrap, res.data);
    } else if (elTotal) {
        elTotal.textContent = res.message || 'Błąd';
        if (sumWrap) renderCartSummary(sumWrap, null);
    }
}

function refreshCartUi() {
    const wrap = document.getElementById('cart-lines');
    renderCartLines(wrap, state.cart, (idx) => {
        state.cart.splice(idx, 1);
        persistCart();
        refreshCartUi();
        recalcCart();
    });
    const sumWrap = document.getElementById('cart-summary');
    if (sumWrap) renderCartSummary(sumWrap, state.lastCalc);
    const totalLabel = state.lastCalc?.grand_total
        ? formatMoneyPl(state.lastCalc.grand_total)
        : '0,00 zł';
    updateCartFab(cartCount(), totalLabel);

    // Checkout CTA — disabled gdy koszyk pusty albo brak lastCalc
    const btn = document.getElementById('cart-checkout-btn');
    if (btn) {
        const enabled = state.cart.length > 0;
        btn.disabled = !enabled;
        const labelSpan = btn.querySelector('span');
        if (labelSpan) {
            labelSpan.textContent = enabled
                ? `Zamów za ${totalLabel}`
                : 'Przejdź do zamówienia';
        }
    }

    // Last order quick-link
    const lastLink = document.getElementById('cart-last-order-link');
    if (lastLink) {
        const last = readLastOrder(OnlineAPI.getTenantId());
        if (last?.trackingToken && last?.phone) {
            lastLink.classList.remove('hidden');
            lastLink.href = `/slicehub/modules/online/track.html?tenant=${OnlineAPI.getTenantId()}&token=${encodeURIComponent(last.trackingToken)}&phone=${encodeURIComponent(last.phone)}`;
            lastLink.title = `Ostatnie: ${last.orderNumber}`;
        } else {
            lastLink.classList.add('hidden');
        }
    }
}

function persistCart() {
    const tid = OnlineAPI.getTenantId();
    try {
        localStorage.setItem(`online_cart_${tid}`, JSON.stringify(state.cart));
    } catch (_) {}
}

function loadCart() {
    const tid = OnlineAPI.getTenantId();
    try {
        const raw = localStorage.getItem(`online_cart_${tid}`);
        if (raw) state.cart = JSON.parse(raw);
    } catch (_) {
        state.cart = [];
    }
}

async function loadDish(sku) {
    const sheet = document.getElementById('dish-sheet-inner');
    if (!sheet) return;
    sheet.innerHTML = '<p class="online-muted sc-loading">Ładowanie…</p>';
    openDishSheet();

    // Faza 3.0 Interaction Contract — getSceneDish zwraca pełny scene_spec + promo slots.
    // Stary getDish pozostaje fallbackiem (legacy flow lub gdy scene contract niedostępny).
    let res;
    if (USE_LEGACY_MENU) {
        res = await OnlineAPI.getDish(sku, state.channel);
    } else {
        const sceneRes = await OnlineAPI.getSceneDish(sku, state.channel);
        if (sceneRes.success && sceneRes.data?.sceneContract) {
            const adapted = adaptSceneDishToLegacy(sceneRes.data);
            res = { ok: true, success: true, message: 'OK', data: adapted };
        } else {
            // fallback, żeby klik w item zawsze zadziałał
            res = await OnlineAPI.getDish(sku, state.channel);
        }
    }
    if (!res.success || !res.data) {
        sheet.innerHTML = `<p class="online-banner online-banner--error">${escapeHtmlInline(res.message || 'Błąd')}</p>`;
        return;
    }

    // Theming panelu z activeStyle (paleta + font) — Scene Studio → klient.
    const panel = document.querySelector('#dish-sheet .dish-sheet__panel');
    applyDishSheetTheme(panel, res.data.activeStyle || null);

    // Build flat modifier index for live pricing (sku → {price, name})
    const modIdx = {};
    (res.data.modifierGroups || []).forEach((g) => {
        (g.options || []).forEach((o) => {
            modIdx[o.sku] = o;
        });
    });

    const dishData = {
        ...res.data,
        _categoryName: findCategoryNameForSku(state.categories, sku),
        _modIndex: modIdx,
    };
    const menuItems = flattenMenuItems(state.categories);

    const dishCtx = {
        surfaceFull: false,
        halfHalf: false,
        halfBSku: '',
        layersB: [],
        editMode: false,
        modCounts: new Map(),
    };

    const fetchHalfBLayers = async () => {
        if (!dishCtx.halfHalf || !dishCtx.halfBSku) {
            dishCtx.layersB = [];
            return;
        }
        const bRes = await OnlineAPI.getDish(dishCtx.halfBSku, state.channel);
        dishCtx.layersB = bRes.success && bRes.data?.visualLayers ? bRes.data.visualLayers : [];
    };

    const handlers = {
        menuItems,
        onClose: () => closeDishSheet(),
        onCtxChange: async (partial) => {
            Object.assign(dishCtx, partial);
            if (partial.halfHalf === false) {
                dishCtx.halfBSku = '';
                dishCtx.layersB = [];
            }
            if (partial.halfBSku !== undefined || partial.halfHalf !== undefined) {
                await fetchHalfBLayers();
            }
            // Re-render entire card (mode/toolbar labels change) — keep counts
            paintSheet();
            if (partial.editMode === true) {
                requestAnimationFrame(() => {
                    document.getElementById('sc-mod-groups')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                });
            }
        },
        onModifierChange: (_sku, _next, _prev) => {
            repaintSurface(sheet, dishData, dishCtx);
        },
        onAddToCart: () => {
            if (dishCtx.halfHalf && !dishCtx.halfBSku) return;
            const mods = collectDishSheetModsFromCounts(dishCtx.modCounts);
            const primary = dishData.item;
            const primarySku = primary?.sku;

            if (dishCtx.halfHalf && dishCtx.halfBSku) {
                const nameA = primary?.name || primarySku;
                let nameB = dishCtx.halfBSku;
                const bHit = menuItems.find((m) => m.sku === dishCtx.halfBSku);
                if (bHit) nameB = bHit.name;
                state.cart.push({
                    is_half: true,
                    half_a_sku: primarySku,
                    half_b_sku: dishCtx.halfBSku,
                    itemSku: primarySku,
                    name: `½ ${nameA} + ½ ${nameB}`,
                    qty: 1,
                    added_modifier_skus: mods,
                });
            } else {
                state.cart.push({
                    itemSku: primarySku,
                    name: primary?.name || primarySku,
                    qty: 1,
                    added_modifier_skus: mods,
                });
            }
            persistCart();
            refreshCartUi();
            recalcCart();
            closeDishSheet();
        },
        onAddCompanion: (comp) => {
            state.cart.push({
                itemSku: comp.sku,
                name: comp.name || comp.sku,
                qty: 1,
                added_modifier_skus: [],
            });
            persistCart();
            refreshCartUi();
            recalcCart();
        },
    };

    const paintSheet = () => {
        fillDishSheet(sheet, dishData, dishCtx, handlers);
    };

    paintSheet();
}

function escapeHtmlInline(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

function normalizeSceneMenuCategories(cats) {
    // Adapter: `getSceneMenu` items używają `heroUrl` + nie mają `visualLayers`.
    // Mapujemy na kształt zgodny z `buildMenuAccordion` (imageUrl, visualLayers[]).
    return (cats || []).map((cat) => ({
        id: cat.id,
        name: cat.name,
        isMenu: cat.isMenu,
        layoutMode: cat.layoutMode,
        defaultCompositionProfile: cat.defaultCompositionProfile,
        hasCategoryScene: cat.hasCategoryScene,
        items: (cat.items || []).map((it) => ({
            sku: it.sku,
            name: it.name,
            description: it.description,
            imageUrl: it.heroUrl || null,
            heroUrl: it.heroUrl || null,
            visualLayers: [],
            price: it.price,
            priceFallback: it.priceFallback,
            compositionProfile: it.compositionProfile,
            hasScene: it.hasScene,
            activeStyle: it.activeStyle || null,
            // G6 · propagujemy atmospheric_effects na tile/popular (na wypadek gdy visualLayers trafią tu w przyszłości)
            atmosphericEffects: Array.isArray(it.atmosphericEffects) ? it.atmosphericEffects : [],
            // M3 #4 · propagujemy active_camera_preset (dla tile/popular/surface)
            activeCamera: it.activeCamera || null,
        })),
    }));
}

async function loadMenuAndPopular() {
    const err = document.getElementById('online-error');
    renderLoading(document.getElementById('online-loading'), true, 'Ładowanie menu…');
    renderErrorBanner(err, '');
    syncChannelFromUi();

    const menuFetcher = USE_LEGACY_MENU
        ? OnlineAPI.getMenu(state.channel)
        : OnlineAPI.getSceneMenu(state.channel);

    const [setRes, menuRes, popRes] = await Promise.all([
        OnlineAPI.getStorefrontSettings(state.channel),
        menuFetcher,
        OnlineAPI.getPopular(8, state.channel),
    ]);

    renderLoading(document.getElementById('online-loading'), false);

    if (!setRes.success) {
        renderErrorBanner(err, setRes.message || 'Błąd ustawień sklepu.');
    } else if (setRes.data?.surfaceBg) {
        applySurfaceBackground(setRes.data.surfaceBg);
    }

    if (setRes.data?.tenant?.name) {
        const name = setRes.data.tenant.name;
        const lbl = document.getElementById('tenant-label');
        if (lbl) lbl.textContent = name;
        const heroN = document.getElementById('hero-tenant');
        if (heroN) heroN.textContent = name;
    }
    if (setRes.data?.tenant?.city) {
        const city = document.getElementById('tenant-city');
        if (city) city.textContent = setRes.data.tenant.city;
    }

    if (!menuRes.success || !menuRes.data?.categories) {
        renderErrorBanner(err, menuRes.message || 'Nie udało się wczytać menu.');
        return;
    }

    state.categories = USE_LEGACY_MENU
        ? menuRes.data.categories
        : normalizeSceneMenuCategories(menuRes.data.categories);

    const menuRoot = document.getElementById('menu-root');
    if (menuRoot) {
        menuRoot.innerHTML = '';
        if (USE_LEGACY_MENU) {
            menuRoot.appendChild(
                buildMenuAccordion(state.categories, (itemSku) => loadDish(itemSku))
            );
        } else {
            menuRoot.appendChild(
                buildTableSections(state.categories, {
                    onPickItem: (itemSku) => loadDish(itemSku),
                })
            );
        }
    }

    const popWrap = document.getElementById('popular-root');
    if (popRes.success && popRes.data?.items) {
        renderPopularCarousel(popWrap, popRes.data.items, (itemSku) => loadDish(itemSku));
    } else if (popWrap) {
        popWrap.classList.add('hidden');
    }
}

function bindUiOnce() {
    const setActive = (activeId) => {
        ['ch-delivery', 'ch-takeaway'].forEach((id) => {
            const btn = document.getElementById(id);
            if (!btn) return;
            const active = id === activeId;
            btn.classList.toggle('is-active', active);
            btn.classList.toggle('channel-pill--active', active); // legacy compat
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    };

    document.getElementById('ch-delivery')?.addEventListener('click', () => {
        setActive('ch-delivery');
        loadMenuAndPopular();
    });
    document.getElementById('ch-takeaway')?.addEventListener('click', () => {
        setActive('ch-takeaway');
        loadMenuAndPopular();
    });

    const openCart = () => {
        refreshCartUi();
        openCartDrawer();
    };
    document.getElementById('cart-fab')?.addEventListener('click', openCart);
    document.getElementById('topbar-cart')?.addEventListener('click', openCart);
    document.getElementById('cart-close')?.addEventListener('click', closeCartDrawer);
    document.getElementById('dish-sheet-close')?.addEventListener('click', closeDishSheet);

    document.getElementById('cart-checkout-btn')?.addEventListener('click', () => {
        if (!state.cart.length) return;
        closeCartDrawer();
        openCheckoutOverlay({
            state,
            api: OnlineAPI,
            cartLinesForApi,
            persistCart,
            refreshCartUi,
            onSuccess: () => {
                // noop — successScreen renders inside overlay
            },
        });
    });

    // Hero welcome znika po pierwszym przewinięciu — płynna transition.
    const hero = document.getElementById('hero-welcome');
    if (hero) {
        const onScroll = () => {
            if (window.scrollY > 60) {
                hero.classList.add('is-collapsed');
            } else {
                hero.classList.remove('is-collapsed');
            }
        };
        window.addEventListener('scroll', onScroll, { passive: true });
    }
}

function enterAfterDoorway({ channel, preOrder }) {
    // Topbar channel sync — Doorway może wybrać kanał, który honorujemy w dalszej sesji.
    const wanted = channel === 'Takeaway' ? 'ch-takeaway' : 'ch-delivery';
    ['ch-delivery', 'ch-takeaway'].forEach((id) => {
        const btn = document.getElementById(id);
        if (!btn) return;
        const active = id === wanted;
        btn.classList.toggle('is-active', active);
        btn.classList.toggle('channel-pill--active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    syncChannelFromUi();

    // Loading overlay + menu fetch.
    renderLoading(document.getElementById('online-loading'), true, 'Ładowanie menu…');
    loadMenuAndPopular().then(() => {
        refreshCartUi();
        recalcCart();
        if (preOrder) {
            // Drobny sygnał dla UX — w przyszłości otworzymy pre-order sheet.
            try { window.dispatchEvent(new CustomEvent('slicehub:preorder-requested')); } catch (_) {}
        }
    });
}

function init() {
    const err = document.getElementById('online-error');
    if (OnlineAPI.getTenantId() <= 0) {
        renderErrorBanner(err, 'Ustaw tenant: dopisz do adresu ?tenant=1 albo meta sh-tenant-id w index.html.');
        return;
    }

    loadCart();
    bindUiOnce();

    // M4 · Scena Drzwi — pierwszy ekran. Po wejściu ładujemy menu z właściwym kanałem.
    mountDoorway({
        api: OnlineAPI,
        initialChannel: state.channel || 'Delivery',
        onEnter: (payload) => enterAfterDoorway(payload || {}),
    });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/slicehub/modules/online/sw.js').catch(() => {});
        }, { once: true });
    }
}

document.addEventListener('DOMContentLoaded', init);
