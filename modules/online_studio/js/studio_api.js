/**
 * SliceHub · Online Studio — API client
 * Thin wrapper over fetch(). Session cookies auto-included; Bearer token optional
 * (if present in localStorage['sh_token'], also sent for JWT flows).
 */

const ENGINE_URL        = '/slicehub/api/online_studio/engine.php';
const UPLOAD_URL        = '/slicehub/api/online_studio/library_upload.php';
const ASSETS_ENGINE_URL = '/slicehub/api/assets/engine.php';
const MENU_ENGINE_URL   = '/slicehub/api/backoffice/api_menu_studio.php';
const LOGIN_PATH        = '/slicehub/login.html';

function authHeaders() {
    const h = {};
    const tok = localStorage.getItem('sh_token');
    if (tok) h['Authorization'] = 'Bearer ' + tok;
    return h;
}

async function call(action, payload = {}) {
    try {
        const res = await fetch(ENGINE_URL, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', ...authHeaders() },
            body: JSON.stringify({ action, ...payload }),
        });

        if (res.status === 401) {
            return { success: false, message: 'UNAUTH', data: null, _unauth: true };
        }

        const json = await res.json().catch(() => null);
        if (!json) {
            return { success: false, message: 'Niepoprawna odpowiedz serwera.', data: null };
        }
        return {
            success: json.success === true,
            message: json.message ?? '',
            data: json.data ?? null,
            status: res.status,
        };
    } catch (e) {
        return { success: false, message: e?.message || 'Blad sieci.', data: null, status: 0 };
    }
}

async function upload(formData) {
    try {
        const res = await fetch(UPLOAD_URL, {
            method: 'POST',
            credentials: 'include',
            headers: { ...authHeaders() }, // no content-type; let browser set multipart boundary
            body: formData,
        });
        if (res.status === 401) return { success: false, message: 'UNAUTH', data: null, _unauth: true };
        const json = await res.json().catch(() => null);
        if (!json) return { success: false, message: 'Niepoprawna odpowiedz serwera.', data: null };
        return { success: json.success === true, message: json.message ?? '', data: json.data ?? null };
    } catch (e) {
        return { success: false, message: e?.message || 'Blad sieci.', data: null };
    }
}

// ---------------------------------------------------------------------------
// ASSET STUDIO (m021 — Unified Asset Library)
// ---------------------------------------------------------------------------

async function callAssets(action, payload = {}) {
    try {
        const res = await fetch(ASSETS_ENGINE_URL, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', ...authHeaders() },
            body: JSON.stringify({ action, ...payload }),
        });
        if (res.status === 401) return { success: false, message: 'UNAUTH', data: null, _unauth: true };
        const json = await res.json().catch(() => null);
        if (!json) return { success: false, message: 'Niepoprawna odpowiedz serwera.', data: null };
        return { success: json.success === true, message: json.message ?? '', data: json.data ?? null };
    } catch (e) {
        return { success: false, message: e?.message || 'Blad sieci.', data: null };
    }
}

async function uploadAsset(formData) {
    try {
        // action musi byc w FormData zeby PHP rozpoznal router
        if (!formData.has('action')) formData.append('action', 'upload');
        const res = await fetch(ASSETS_ENGINE_URL, {
            method: 'POST',
            credentials: 'include',
            headers: { ...authHeaders() }, // no content-type!
            body: formData,
        });
        if (res.status === 401) return { success: false, message: 'UNAUTH', data: null, _unauth: true };
        const json = await res.json().catch(() => null);
        if (!json) return { success: false, message: 'Niepoprawna odpowiedz serwera.', data: null };
        return { success: json.success === true, message: json.message ?? '', data: json.data ?? null };
    } catch (e) {
        return { success: false, message: e?.message || 'Blad sieci.', data: null };
    }
}

// ---------------------------------------------------------------------------
// Menu Studio engine (cross-module: czytamy scene_templates + scene_kit z api_menu_studio)
// ---------------------------------------------------------------------------
async function callMenuApi(action, payload = {}) {
    try {
        const res = await fetch(MENU_ENGINE_URL, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', ...authHeaders() },
            body: JSON.stringify({ action, ...payload }),
        });
        if (res.status === 401) return { success: false, message: 'UNAUTH', data: null, _unauth: true };
        const json = await res.json().catch(() => null);
        if (!json) return { success: false, message: 'Niepoprawna odpowiedz serwera.', data: null };
        return { success: json.success === true, message: json.message ?? '', data: json.data ?? null };
    } catch (e) {
        return { success: false, message: e?.message || 'Blad sieci.', data: null };
    }
}

function redirectToLogin() {
    window.location.href = LOGIN_PATH;
}

export const StudioApi = {
    LOGIN_PATH,
    redirectToLogin,
    // -- identity --
    whoami:                  ()                 => call('whoami'),
    // -- menu --
    menuList:                ()                 => call('menu_list'),
    /** Ustaw image_url produktu na ścieżkę już wgraną (np. z Biblioteki). */
    menuSetProductImage:     (itemSku, imageUrl) =>
                                                     call('menu_set_product_image', { itemSku, imageUrl }),
    // -- library (legacy sh_global_assets; NEW code uses assetsList/assetsListCompact) --
    // libraryList REMOVED 2026-04-19 — SSOT = assetsList (api/assets/engine.php)
    libraryUpdate:           (p)                => call('library_update', p),
    libraryDelete:           (p)                => call('library_delete', p),
    libraryUpload:           (formData)         => upload(formData),
    // -- composer --
    composerLoadDish:        (itemSku)          => call('composer_load_dish', { itemSku }),
    composerSaveLayers:      (itemSku, layers, replaceAll = true) =>
                                                     call('composer_save_layers', { itemSku, layers, replaceAll }),
    composerCalibrate:       (p)                => call('composer_calibrate', p),
    composerClone:           (sourceSku, targetSku) =>
                                                     call('composer_clone', { sourceSku, targetSku }),
    composerAutofitSuggest:  (itemSku, layerSku)=> call('composer_autofit_suggest', { itemSku, layerSku }),
    composerAutoMatchDishes: (p = {})           => call('composer_auto_match_dishes', p),
    // -- companions --
    companionsList:          (itemSku)          => call('companions_list', { itemSku }),
    companionsSave:          (itemSku, companions) =>
                                                     call('companions_save', { itemSku, companions }),
    // -- surface --
    surfaceApply:            (filename)         => call('surface_apply', { filename }),
    // -- storefront settings (Faza D · 2026-04-19) --
    storefrontSettingsGet:   ()                 => call('storefront_settings_get'),
    storefrontSettingsSave:  (payload)          => call('storefront_settings_save', payload),
    // -- preview --
    previewUrl:              (itemSku = '')     => call('preview_url', { itemSku }),
    // -- director (Hollywood Suite) --
    call:                    (action, payload)  => call(action, payload),
    directorLoadScene:       (itemSku)          => call('director_load_scene', { itemSku }),
    directorSaveScene:       (itemSku, specJson, snapshotLabel) =>
                                                     call('director_save_scene', { itemSku, specJson, snapshotLabel }),
    directorListPresets:     ()                 => call('director_list_presets'),

    // -- promotions (M022 — badge na scenie; CartEngine później) --
    promotionsList:          ()                 => call('promotions_list'),
    promotionSave:           (payload)          => call('promotion_save', payload),
    promotionDelete:         (promotionId)      => call('promotion_delete', { promotionId }),
    scenePromotionSlotsGet:  (itemSku)          => call('scene_promotion_slots_get', { itemSku }),
    scenePromotionSlotsSave: (itemSku, slots)   => call('scene_promotion_slots_save', { itemSku, slots }),

    // -- asset studio (m021 Unified Asset Library) --
    assetsList:              (p = {})           => callAssets('list', p),
    assetsUpload:            (formData)         => uploadAsset(formData),
    assetsUpdate:            (p)                => callAssets('update', p),
    assetsSoftDelete:        (p)                => callAssets('soft_delete', p),
    assetsRestore:           (p)                => callAssets('restore', p),
    assetsLink:              (p)                => callAssets('link', p),
    assetsUnlink:            (p)                => callAssets('unlink', p),
    assetsListUsage:         (assetId)          => callAssets('list_usage', { asset_id: assetId }),
    assetsListEntities:      ()                 => callAssets('list_entities'),

    // -- m032 Asset Library Organizer --
    assetsBulkUpdate:        (ids, patch)       => callAssets('bulk_update', { ids, patch }),
    assetsBulkSoftDelete:    (ids)              => callAssets('bulk_soft_delete', { ids }),
    assetsDuplicate:         (p)                => callAssets('duplicate', p),
    assetsMergeDuplicates:   (keeperId, mergeIds) => callAssets('merge_duplicates', { keeper_id: keeperId, merge_ids: mergeIds }),
    assetsScanHealth:        ()                 => callAssets('scan_health'),
    assetsRenameSmart:       (p)                => callAssets('rename_smart', p),

    // -- scene templates & scene kit (m022+m023 — czyt. z api_menu_studio.php) --
    sceneTemplatesList:      (kind = null)      => callMenuApi('list_scene_templates', kind ? { kind } : {}),
    sceneKitGet:             (templateKey)      => callMenuApi('get_scene_kit', { templateKey }),
    sceneKitSave:            (templateKey, kit) => callMenuApi('save_scene_kit', { templateKey, kit }),
    assetsListCompact:       (limit = 500)      => callMenuApi('list_assets_compact', { limit }),

    // -- G1/G2 Style Engine --
    stylePresetsList:        ()                 => call('style_presets_list'),
    categoryStylesList:      ()                 => call('category_styles_list'),
    categoryStyleApply:      (categoryId, stylePresetId, dryRun = false) =>
                                                     call('category_style_apply', { categoryId, stylePresetId, dryRun }),
    menuStyleApply:          (stylePresetId, categoryIds = null, dryRun = false) =>
                                                     call('menu_style_apply', { stylePresetId, categoryIds, dryRun }),

    // -- G4 Harmony Score --
    sceneHarmonyGet:         (scope, itemSku = '') => call('scene_harmony_get', { scope, itemSku }),
    sceneHarmonySave:        (p)                 => call('scene_harmony_save', p),
};
