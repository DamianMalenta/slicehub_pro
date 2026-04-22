/**
 * Online Studio — SURFACE tab
 *
 * Choose the wooden-plank / marble / stone background that will sit behind the
 * hero dish on the storefront (stored in sh_tenant_settings / storefront_surface_bg).
 *
 * Surfaces are drawn from the unified asset library (api/assets/engine.php)
 * where bucket='surface' / category='board' / sub_type='surface', or assets
 * whose ascii_key implies a surface (wood, plank, marble, stone, plate, desk).
 *
 * Repipe 2026-04-19 · Faza B (SSOT biblioteki): Studio.library → Studio.assets.
 */

/** Wyciągnij basename pliku z storageUrl/publicUrl (server oczekuje samego basename). */
function basenameFromUrl(urlLike) {
    if (!urlLike) return '';
    const clean = String(urlLike).split('?')[0].split('#')[0];
    const parts = clean.split('/');
    return parts[parts.length - 1] || '';
}

export function mountSurface(root, Studio, Api) {
    const state = { activeFilename: null, candidates: [] };

    root.innerHTML = `
        <div class="row row--between mb-16">
            <div>
                <div class="card__title">Aktywna powierzchnia</div>
                <div id="surf-current" class="muted">—</div>
            </div>
            <div class="row">
                <button id="surf-upload" class="btn btn--accent"><i class="fa-solid fa-upload"></i> Wgraj powierzchnię</button>
                <button id="surf-refresh" class="btn btn--ghost" title="Odśwież"><i class="fa-solid fa-rotate"></i></button>
            </div>
        </div>
        <div id="surf-active-preview" class="card" style="margin-bottom:20px"></div>
        <div class="card__title">Dostępne powierzchnie</div>
        <div id="surf-grid" class="surface-grid"></div>
    `;

    const $current = root.querySelector('#surf-current');
    const $grid    = root.querySelector('#surf-grid');
    const $preview = root.querySelector('#surf-active-preview');

    function collectCandidates() {
        const items = (Studio.assets?.items || []);
        // Primary: bucket=='surface' lub sub_type=='surface' ; fallback: category=='board' ;
        // or names containing 'surface'/'plank'/'plate'/'marble'/'stone'/'wood'/'desk'
        const hits = items.filter(it =>
            it.bucket === 'surface'
            || it.subType === 'surface'
            || it.category === 'board'
            || /surface|plank|plate|marble|stone|wood|desk/i.test(it.asciiKey || '')
        );
        // De-dupe po id
        const seen = new Set();
        return hits
            .filter(it => !seen.has(it.id) && (seen.add(it.id), true))
            // Augmentacja polami których oczekuje stary UI (surface_apply bierze basename)
            .map(it => ({
                ...it,
                url:      it.publicUrl || it.storageUrl || '',
                filename: basenameFromUrl(it.storageUrl || it.publicUrl || ''),
            }));
    }

    function renderActive() {
        const fn = state.activeFilename;
        if (!fn) {
            $current.textContent = 'Brak ustawionej powierzchni — zostanie użyte domyślne tło.';
            $preview.innerHTML = `<div class="muted" style="padding:40px;text-align:center">
                <i class="fa-solid fa-image" style="font-size:40px;color:var(--text-mute);display:block;margin-bottom:10px"></i>
                Wybierz kafelek poniżej, aby ustawić domyślną powierzchnię.
            </div>`;
            return;
        }
        $current.innerHTML = `<code>${fn}</code>`;
        $preview.innerHTML = `
            <div style="position:relative;width:100%;aspect-ratio:16/7;background-image:url('/slicehub/uploads/global_assets/${fn}');background-size:cover;background-position:center;border-radius:10px;overflow:hidden;display:grid;place-items:center">
                <div style="color:rgba(255,255,255,0.6);font-weight:700;font-size:13px;letter-spacing:0.12em;text-transform:uppercase;background:rgba(0,0,0,0.4);padding:6px 14px;border-radius:999px;backdrop-filter:blur(4px)">
                    <i class="fa-solid fa-check-circle" style="color:var(--green)"></i> Aktywna
                </div>
            </div>
        `;
    }

    function renderGrid() {
        const items = state.candidates;
        if (items.length === 0) {
            $grid.innerHTML = `<div class="muted" style="grid-column:1/-1;padding:40px;text-align:center">
                Brak powierzchni w bibliotece. Wgraj własną (JPG/PNG/WebP) powyżej.
            </div>`;
            return;
        }
        $grid.innerHTML = items.map(it => `
            <div class="surface-card ${state.activeFilename === it.filename ? 'active' : ''}" data-fn="${it.filename}">
                <img class="surface-card__image" src="${it.url}" alt="${it.asciiKey}" loading="lazy">
                <div class="surface-card__name">
                    ${it.subType || '—'} <span class="muted">· ${it.category}</span>
                </div>
            </div>
        `).join('');

        $grid.querySelectorAll('.surface-card').forEach(el => {
            el.onclick = () => apply(el.dataset.fn);
        });
    }

    async function apply(filename) {
        const r = await Api.surfaceApply(filename);
        if (r.success) {
            state.activeFilename = filename;
            Studio.toast('Ustawiono powierzchnię: ' + filename, 'ok');
            renderActive();
            renderGrid();
        } else {
            Studio.toast(r.message || 'Błąd.', 'err');
        }
    }

    async function refresh() {
        await Studio.refreshAssets(true);
        state.candidates = collectCandidates();
        // Ask server for current setting by loading any dish (composer_load_dish returns surfaceBg).
        // Use a minimal stub: pick first dish if menu cached.
        const first = Studio.menu?.items?.[0];
        if (first) {
            const r = await Api.composerLoadDish(first.sku);
            if (r.success) state.activeFilename = r.data.surfaceBg || null;
        }
        renderActive();
        renderGrid();
    }

    function openUploader() {
        const body = document.createElement('div');
        body.innerHTML = `
            <p class="muted mb-16">Tło powierzchni (deska, marmur, kamień, drewno).<br>
            JPG / PNG / WebP · min 800×450 zalecane · max 5 MB.</p>
            <input id="su-file" type="file" class="input" accept="image/webp,image/png,image/jpeg" style="margin-bottom:12px">
            <div id="su-prev" style="display:none;margin-bottom:12px">
                <img id="su-img" style="width:100%;max-height:240px;object-fit:cover;border-radius:8px">
                <small id="su-meta" class="muted" style="display:block;margin-top:6px"></small>
            </div>
            <div class="form-row">
                <div><label class="label">sub_type</label><input id="su-sub" class="input" value="surface"></div>
                <div><label class="label">ascii_key</label><input id="su-ak" class="input" placeholder="np. surface_oak_plank"></div>
            </div>
            <p class="muted" style="font-size:11px">Powierzchnia zostanie zapisana w bibliotece jako category=<code>misc</code>, sub_type=<code>surface</code>.</p>
        `;
        const footer = document.createElement('div');
        footer.innerHTML = `
            <div style="flex:1"></div>
            <button class="btn" id="su-cancel">Anuluj</button>
            <button class="btn btn--accent" id="su-go"><i class="fa-solid fa-cloud-arrow-up"></i> Wgraj i ustaw</button>
        `;
        const m = Studio.modal({ title: 'Wgraj powierzchnię', body, footer });

        const $file = body.querySelector('#su-file');
        $file.onchange = () => {
            const f = $file.files?.[0];
            if (!f) return;
            body.querySelector('#su-img').src = URL.createObjectURL(f);
            body.querySelector('#su-meta').textContent = `${f.name} · ${Math.round(f.size/1024)} KB`;
            body.querySelector('#su-prev').style.display = 'block';
        };
        m.footer.querySelector('#su-cancel').onclick = m.close;
        m.footer.querySelector('#su-go').onclick = async () => {
            const f = $file.files?.[0];
            if (!f) { Studio.toast('Wybierz plik.', 'warn'); return; }
            // Surface files we upload as category=misc, sub_type=surface (relaxed validator accepts)
            // But our library_upload only accepts webp/png. For JPEG surfaces, use existing
            // visual_composer/asset_upload.php endpoint (asset_type=surface).
            const ext = (f.name.split('.').pop() || '').toLowerCase();
            const isJpg = ext === 'jpg' || ext === 'jpeg';

            m.footer.querySelector('#su-go').disabled = true;

            if (isJpg) {
                const fd = new FormData();
                fd.append('asset', f);
                fd.append('asset_type', 'surface');
                fd.append('category', 'misc');
                fd.append('sub_type', body.querySelector('#su-sub').value || 'surface');
                const res = await fetch('/slicehub/api/visual_composer/asset_upload.php', {
                    method: 'POST',
                    credentials: 'include',
                    body: fd,
                });
                const j = await res.json().catch(() => null);
                if (!j || !j.success) {
                    Studio.toast((j && j.message) || 'Błąd uploadu JPG.', 'err', 5000);
                    m.footer.querySelector('#su-go').disabled = false;
                    return;
                }
                const filename = j.data?.layerFilename;
                const r2 = await Api.surfaceApply(filename);
                if (r2.success) { Studio.toast('Wgrano i ustawiono.', 'ok'); m.close(); refresh(); }
                else Studio.toast(r2.message || 'Błąd przypięcia.', 'err');
                return;
            }

            // WebP/PNG: send via library_upload
            const fd = new FormData();
            fd.append('file', f);
            fd.append('category', 'misc');
            fd.append('sub_type', body.querySelector('#su-sub').value || 'surface');
            const ak = body.querySelector('#su-ak').value.trim();
            if (ak) fd.append('ascii_key', ak);
            const r = await Api.libraryUpload(fd);
            if (!r.success) {
                Studio.toast(r.message || 'Błąd uploadu.', 'err', 5000);
                m.footer.querySelector('#su-go').disabled = false;
                return;
            }
            const r2 = await Api.surfaceApply(r.data.filename);
            if (r2.success) {
                Studio.toast('Wgrano i ustawiono: ' + r.data.filename, 'ok');
                m.close();
                refresh();
            } else {
                Studio.toast(r2.message || 'Wgrano, ale nie udało się ustawić jako domyślna.', 'err');
            }
        };
    }

    root.querySelector('#surf-upload').onclick  = openUploader;
    root.querySelector('#surf-refresh').onclick = refresh;

    return { onEnter: refresh };
}
