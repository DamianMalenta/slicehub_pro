/**
 * SliceHub — Profil Firmy (frontend)
 * Vanilla JS, brak frameworków per Konstytucja §3.
 *
 * Auth: token JWT z localStorage['sh_token'] (zapisywany przy loginie w Hub).
 * API:  /api/backoffice/profile/engine.php (action=legal_profile_get|save).
 *
 * Walidacja inline (na blur) dla NIP / REGON / IBAN — natychmiastowy feedback,
 * twarda walidacja po stronie serwera (engine.php) jest zawsze.
 */
(function () {
    'use strict';

    const ENDPOINT = '/api/backoffice/profile/engine.php';
    const $ = (sel) => document.querySelector(sel);

    let state = {
        loaded: false,
        editable: false,
        original: null,
        dirty: false,
    };

    // -------------------------------------------------------------------------
    // API
    // -------------------------------------------------------------------------
    function getToken() {
        return localStorage.getItem('sh_token') || '';
    }

    async function api(action, body = {}) {
        const tok = getToken();
        if (!tok) {
            return { success: false, message: 'Brak tokenu — zaloguj się w Hub i wróć.', code: 'NO_TOKEN' };
        }
        let res;
        try {
            res = await fetch(ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + tok,
                },
                body: JSON.stringify({ action, ...body }),
            });
        } catch (netErr) {
            return { success: false, message: 'Błąd sieci: ' + (netErr.message || netErr), code: 'NETWORK' };
        }
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch (e) {
            return {
                success: false,
                message: 'Serwer zwrócił nie-JSON (HTTP ' + res.status + ').',
                code: 'NON_JSON',
                raw: text.slice(0, 300),
            };
        }
        return json;
    }

    // -------------------------------------------------------------------------
    // Walidacja klient-side (mirror serwerowej)
    // -------------------------------------------------------------------------
    function normalizeNip(s) { return (s || '').replace(/\D+/g, ''); }

    function validateNip(s) {
        s = normalizeNip(s);
        if (!/^\d{10}$/.test(s)) return false;
        const w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        let sum = 0;
        for (let i = 0; i < 9; i++) sum += parseInt(s[i], 10) * w[i];
        const c = sum % 11;
        return c !== 10 && c === parseInt(s[9], 10);
    }

    function validateRegon(s) {
        s = (s || '').replace(/\D+/g, '');
        if (s.length === 9) {
            const w = [8, 9, 2, 3, 4, 5, 6, 7];
            let sum = 0;
            for (let i = 0; i < 8; i++) sum += parseInt(s[i], 10) * w[i];
            let c = sum % 11; if (c === 10) c = 0;
            return c === parseInt(s[8], 10);
        }
        if (s.length === 14) {
            if (!validateRegon(s.slice(0, 9))) return false;
            const w = [2, 4, 8, 5, 0, 9, 7, 3, 6, 1, 2, 4, 8];
            let sum = 0;
            for (let i = 0; i < 13; i++) sum += parseInt(s[i], 10) * w[i];
            let c = sum % 11; if (c === 10) c = 0;
            return c === parseInt(s[13], 10);
        }
        return false;
    }

    function validateIbanPL(s) {
        s = (s || '').toUpperCase().replace(/\s+/g, '');
        if (!/^PL\d{26}$/.test(s)) return false;
        const r = s.slice(4) + s.slice(0, 4);
        let num = '';
        for (const c of r) {
            num += /\d/.test(c) ? c : (c.charCodeAt(0) - 55).toString();
        }
        let rem = 0;
        for (const d of num) rem = (rem * 10 + parseInt(d, 10)) % 97;
        return rem === 1;
    }

    function setStatus(elId, ok, msg) {
        const el = $('#' + elId);
        if (!el) return;
        if (msg === '' || msg === null || msg === undefined) {
            el.textContent = '';
            el.className = 'pf-status';
        } else {
            el.textContent = msg;
            el.className = 'pf-status ' + (ok ? 'pf-status--ok' : 'pf-status--err');
        }
    }

    // -------------------------------------------------------------------------
    // Field bindings — id-DOM ↔ klucz w response
    // -------------------------------------------------------------------------
    const FIELDS = [
        // tenant kolumny
        { id: 'pf-name',           path: ['tenant', 'name'] },
        { id: 'pf-slug',           path: ['tenant', 'slug'] },
        { id: 'pf-nip',            path: ['tenant', 'nip'] },
        // statutory KV
        { id: 'pf-company-name',   path: ['statutory', 'company_name'] },
        { id: 'pf-legal-form',     path: ['statutory', 'legal_form'] },
        { id: 'pf-regon',          path: ['statutory', 'regon'] },
        { id: 'pf-krs',            path: ['statutory', 'krs'] },
        { id: 'pf-addr-street',    path: ['statutory', 'address_street'] },
        { id: 'pf-addr-postal',    path: ['statutory', 'address_postal'] },
        { id: 'pf-addr-city',      path: ['statutory', 'address_city'] },
        { id: 'pf-addr-country',   path: ['statutory', 'address_country'] },
        { id: 'pf-vat-payer',      path: ['statutory', 'vat_payer'], type: 'checkbox' },
        // financial KV
        { id: 'pf-invoice-email',  path: ['financial', 'invoice_email'] },
        { id: 'pf-bank-name',      path: ['financial', 'bank_name'] },
        { id: 'pf-bank-iban',      path: ['financial', 'bank_iban'] },
        { id: 'pf-bank-swift',     path: ['financial', 'bank_swift'] },
        { id: 'pf-fiscal-no',      path: ['financial', 'fiscal_no'] },
    ];

    function readField(field) {
        const el = document.getElementById(field.id);
        if (!el) return '';
        if (field.type === 'checkbox') return !!el.checked;
        return el.value;
    }

    function writeField(field, value) {
        const el = document.getElementById(field.id);
        if (!el) return;
        if (field.type === 'checkbox') {
            el.checked = !!value;
        } else {
            el.value = value == null ? '' : String(value);
        }
    }

    function getValueByPath(obj, path) {
        let cur = obj;
        for (const p of path) {
            if (cur == null) return undefined;
            cur = cur[p];
        }
        return cur;
    }

    function setEditable(editable) {
        state.editable = editable;
        for (const f of FIELDS) {
            const el = document.getElementById(f.id);
            if (el) el.disabled = !editable;
        }
        $('#pf-btn-save').disabled = !editable || !state.dirty;
    }

    function markDirty() {
        state.dirty = true;
        $('#pf-dirty-indicator').classList.remove('hidden');
        $('#pf-btn-save').disabled = !state.editable;
    }

    function clearDirty() {
        state.dirty = false;
        $('#pf-dirty-indicator').classList.add('hidden');
        $('#pf-btn-save').disabled = true;
    }

    function showError(msg) {
        const el = $('#pf-error-banner');
        el.textContent = msg;
        el.classList.remove('hidden');
        setTimeout(() => el.classList.add('hidden'), 6000);
    }

    function showSaveStatus(ok, msg) {
        const el = $('#pf-save-status');
        el.textContent = msg;
        el.className = 'pf-save-status ' + (ok ? 'pf-save-status--ok' : 'pf-save-status--err');
        if (ok) setTimeout(() => { el.textContent = ''; el.className = 'pf-save-status'; }, 4000);
    }

    // -------------------------------------------------------------------------
    // Load / Save
    // -------------------------------------------------------------------------
    async function load() {
        if (!getToken()) {
            $('#pf-auth-banner').classList.remove('hidden');
            return;
        }
        $('#pf-auth-banner').classList.add('hidden');

        const r = await api('legal_profile_get');
        if (!r.success) {
            if (r.code === 'NO_TOKEN' || (r.message || '').toLowerCase().includes('unauth')) {
                $('#pf-auth-banner').classList.remove('hidden');
            } else {
                showError(r.message || 'Błąd ładowania.');
            }
            return;
        }

        state.original = r.data;
        for (const f of FIELDS) {
            const v = getValueByPath(r.data, f.path);
            writeField(f, v);
        }

        const editable = !!(r.data.meta && r.data.meta.editable);
        setEditable(editable);
        if (!editable) {
            $('#pf-readonly-banner').classList.remove('hidden');
        } else {
            $('#pf-readonly-banner').classList.add('hidden');
        }
        clearDirty();
        state.loaded = true;
    }

    function buildSavePayload() {
        if (!state.original) return null;
        const payload = { brand: {}, statutory: {}, financial: {} };
        for (const f of FIELDS) {
            const orig = getValueByPath(state.original, f.path);
            const cur  = readField(f);
            const origNorm = (f.type === 'checkbox') ? !!orig : (orig == null ? '' : String(orig));
            const curNorm  = (f.type === 'checkbox') ? !!cur  : String(cur);
            if (origNorm === curNorm) continue;

            const section = f.path[0];
            const key     = f.path[1];

            // Mapping path → payload structure
            if (section === 'tenant') {
                payload.brand[key] = cur; // 'name', 'slug', 'nip'
            } else if (section === 'statutory' || section === 'financial') {
                payload[section][key] = cur;
            }
        }
        // Usuń puste sekcje (czystszy payload)
        for (const k of Object.keys(payload)) {
            if (Object.keys(payload[k]).length === 0) delete payload[k];
        }
        return payload;
    }

    async function save() {
        if (!state.editable) return;
        const payload = buildSavePayload();
        if (!payload || Object.keys(payload).length === 0) {
            showSaveStatus(true, 'Nic do zapisania.');
            return;
        }

        $('#pf-btn-save').disabled = true;
        showSaveStatus(true, 'Zapisuję…');

        const r = await api('legal_profile_save', payload);
        if (!r.success) {
            showError(r.message || 'Błąd zapisu.');
            showSaveStatus(false, 'Błąd');
            $('#pf-btn-save').disabled = false;
            return;
        }
        showSaveStatus(true, '✓ ' + (r.message || 'Zapisano.'));
        // Reload — żeby state.original miał najnowsze wartości
        await load();
    }

    // -------------------------------------------------------------------------
    // Inline validators (on blur)
    // -------------------------------------------------------------------------
    function bindInlineValidators() {
        const nip = $('#pf-nip');
        if (nip) nip.addEventListener('blur', () => {
            const v = nip.value.trim();
            if (v === '') { setStatus('pf-nip-status', true, ''); return; }
            const norm = normalizeNip(v);
            if (validateNip(norm)) {
                setStatus('pf-nip-status', true, '✓ Poprawny NIP');
                nip.value = norm; markDirty();
            } else {
                setStatus('pf-nip-status', false, '✗ Nieprawidłowa checksuma NIP');
            }
        });

        const regon = $('#pf-regon');
        if (regon) regon.addEventListener('blur', () => {
            const v = regon.value.trim();
            if (v === '') { setStatus('pf-regon-status', true, ''); return; }
            if (validateRegon(v)) {
                setStatus('pf-regon-status', true, '✓ Poprawny REGON');
                regon.value = v.replace(/\D+/g, ''); markDirty();
            } else {
                setStatus('pf-regon-status', false, '✗ REGON: 9 lub 14 cyfr + checksum');
            }
        });

        const iban = $('#pf-bank-iban');
        if (iban) iban.addEventListener('blur', () => {
            const v = iban.value.trim();
            if (v === '') { setStatus('pf-iban-status', true, ''); return; }
            const norm = v.toUpperCase().replace(/\s+/g, '');
            if (validateIbanPL(norm)) {
                setStatus('pf-iban-status', true, '✓ Poprawny IBAN PL');
                iban.value = norm; markDirty();
            } else {
                setStatus('pf-iban-status', false, '✗ IBAN: PL + 26 cyfr, MOD 97 != 1');
            }
        });
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------
    function bindEvents() {
        $('#pf-btn-refresh').addEventListener('click', load);
        $('#pf-btn-save').addEventListener('click', save);

        for (const f of FIELDS) {
            const el = document.getElementById(f.id);
            if (!el) continue;
            const evt = (f.type === 'checkbox' || el.tagName === 'SELECT') ? 'change' : 'input';
            el.addEventListener(evt, () => {
                if (state.loaded) markDirty();
            });
        }

        bindInlineValidators();
    }

    document.addEventListener('DOMContentLoaded', () => {
        console.log('[profile_app] booted', new Date().toISOString());
        bindEvents();
        load();
    });
})();
