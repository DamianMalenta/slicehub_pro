/**
 * Online Studio — PREVIEW tab
 *
 * Live iframe pointing at /modules/online with tenant & dish context,
 * plus a HUD to hot-swap the dish SKU and force-refresh the frame.
 */

export function mountPreview(root, Studio, Api) {
    const state = { sku: '', url: '' };

    root.innerHTML = `
        <div class="preview-hud">
            <span class="muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:0.1em">Podgląd:</span>
            <select id="pv-sku" class="select" style="max-width:260px"></select>
            <button id="pv-reload" class="btn btn--sm"><i class="fa-solid fa-rotate"></i> Odśwież</button>
            <button id="pv-open"   class="btn btn--sm btn--accent"><i class="fa-solid fa-external-link-alt"></i> Otwórz w nowej karcie</button>
            <span style="flex:1"></span>
            <code id="pv-url" class="muted" style="font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:420px"></code>
        </div>
        <iframe id="pv-frame" class="preview-frame" src="about:blank" loading="lazy"></iframe>
    `;

    const $sku    = root.querySelector('#pv-sku');
    const $frame  = root.querySelector('#pv-frame');
    const $url    = root.querySelector('#pv-url');

    function renderSkuSelect() {
        const items = Studio.menu?.items || [];
        $sku.innerHTML = '<option value="">(dowolne — lista menu)</option>' +
            items.map(i => `<option value="${i.sku}" ${i.sku === state.sku ? 'selected' : ''}>${i.name || i.sku}</option>`).join('');
    }

    async function reload() {
        const r = await Api.previewUrl(state.sku);
        if (!r.success) { Studio.toast(r.message || 'Brak URL.', 'err'); return; }
        state.url = r.data.iframeUrl;
        // Cache-bust to force fresh fetch even when SKU unchanged
        const u = new URL(r.data.iframeUrl, window.location.origin);
        u.searchParams.set('_t', Date.now().toString());
        $frame.src = u.toString();
        $url.textContent = u.pathname + u.search;
    }

    root.querySelector('#pv-reload').onclick = reload;
    root.querySelector('#pv-open').onclick   = () => { if (state.url) window.open(state.url, '_blank'); };
    $sku.onchange = () => { state.sku = $sku.value; reload(); };

    return {
        onEnter: async () => {
            if (!Studio.menu) await Studio.refreshMenu();
            renderSkuSelect();
            reload();
        },
    };
}
