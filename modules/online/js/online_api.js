/**
 * SliceHub Online Storefront — API wrapper (public engine, no JWT).
 */

const OnlineAPI = (() => {
    const BASE = '/slicehub/api';

    function _tenantScope() {
        const meta = document.querySelector('meta[name="sh-tenant-id"]');
        const fromMeta = meta ? parseInt(meta.getAttribute('content') || '0', 10) : 0;
        const params = new URLSearchParams(window.location.search);
        const fromUrl = parseInt(params.get('tenant') || params.get('tenantId') || '0', 10);
        return fromUrl > 0 ? fromUrl : fromMeta;
    }

    function _channelFromUrl() {
        const params = new URLSearchParams(window.location.search);
        const c = (params.get('channel') || 'Delivery').trim();
        if (c === 'Takeaway' || c === 'Delivery' || c === 'POS') return c;
        return 'Delivery';
    }

    async function _post(payload) {
        const tid = _tenantScope();
        if (tid <= 0) {
            return { ok: false, success: false, message: 'Brak tenantId (dodaj ?tenant=1 lub meta sh-tenant-id).', data: null };
        }
        const body = { tenantId: tid, ...payload };
        try {
            const res = await fetch(`${BASE}/online/engine.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
                body: JSON.stringify(body),
            });
            const json = await res.json();
            return {
                ok: res.ok,
                status: res.status,
                success: json.success === true,
                message: json.message || '',
                data: json.data ?? null,
            };
        } catch (e) {
            return { ok: false, success: false, message: e.message || 'Network error', data: null };
        }
    }

    return Object.freeze({
        getTenantId: _tenantScope,
        getChannelFromUrl: _channelFromUrl,

        getStorefrontSettings: (channel) =>
            _post({ action: 'get_storefront_settings', channel: channel || _channelFromUrl() }),

        // M4 · Scena Drzwi — hero entry screen (godziny, status, kontakt, mapa, kanały).
        getDoorway: (channel) =>
            _post({ action: 'get_doorway', channel: channel || _channelFromUrl() }),

        getMenu: (channel) => _post({ action: 'get_menu', channel: channel || _channelFromUrl() }),

        getPopular: (limit, channel) =>
            _post({ action: 'get_popular', channel: channel || _channelFromUrl(), limit: limit || 8 }),

        getDish: (itemSku, channel) =>
            _post({ action: 'get_dish', channel: channel || _channelFromUrl(), itemSku }),

        // ── Interaction Contract v1 (Faza 3.0 — The Table) ──────────────────
        // Wzbogacone akcje: mini/pełny Scene Contract przez SceneResolver.
        getSceneMenu: (channel) =>
            _post({ action: 'get_scene_menu', channel: channel || _channelFromUrl() }),

        getSceneDish: (itemSku, channel) =>
            _post({ action: 'get_scene_dish', channel: channel || _channelFromUrl(), itemSku }),

        getSceneCategory: (categoryId, channel) =>
            _post({ action: 'get_scene_category', channel: channel || _channelFromUrl(), categoryId }),

        cartCalculate: (payload) => _post({ action: 'cart_calculate', ...payload }),

        // ── Checkout (Faza 5.1 — guest order lifecycle) ─────────────────────
        initCheckout: (payload) => _post({ action: 'init_checkout', ...payload }),

        guestCheckout: (payload) => _post({ action: 'guest_checkout', ...payload }),

        trackOrder: (trackingToken, customerPhone) =>
            _post({ action: 'track_order', tracking_token: trackingToken, customer_phone: customerPhone }),

        deliveryZones: (payload = {}) => _post({ action: 'delivery_zones', ...payload }),
    });
})();

export default OnlineAPI;
