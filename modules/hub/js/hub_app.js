/**
 * SliceHub Hub — login `mode: system`, dostęp: owner | manager | admin.
 * Synchronizuje ciasteczko sesji PHP (login.php) + JWT w localStorage.
 */
(function () {
    'use strict';

    const TOKEN_KEY = 'sh_token';
    const USER_KEY = 'sh_user';
    const ALLOWED_ROLES = ['owner', 'manager', 'admin'];

    function apiBase() {
        if (globalThis.SliceHub && globalThis.SliceHub.getApiBase) return globalThis.SliceHub.getApiBase();
        if (globalThis.SliceHub && globalThis.SliceHub.getApiFallback) return globalThis.SliceHub.getApiFallback();
        return '/api';
    }

    const $ = (id) => document.getElementById(id);

    function getStoredUser() {
        try {
            const raw = localStorage.getItem(USER_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    function roleAllowed(role) {
        const r = String(role || '').toLowerCase();
        return ALLOWED_ROLES.includes(r);
    }

    function showLogin() {
        $('hub-login')?.classList.remove('hub-hidden');
        $('hub-dash')?.classList.add('hub-hidden');
    }

    function showDash(user) {
        $('hub-login')?.classList.add('hub-hidden');
        $('hub-dash')?.classList.remove('hub-hidden');
        const meta = $('hub-user-meta');
        if (meta && user) {
            meta.textContent = (user.name || user.username || '') + ' · ' + (user.role || '');
        }
        applyRoleVisibility(user);
    }

    /**
     * Pokazuje/ukrywa kafelki z atrybutem data-roles="rola1,rola2,...".
     * Kafelki bez data-roles widoczne dla wszystkich. Z data-roles widoczne
     * tylko gdy user.role jest na liście.
     */
    function applyRoleVisibility(user) {
        const role = String((user && user.role) || '').toLowerCase();
        document.querySelectorAll('[data-roles]').forEach((el) => {
            const allowed = String(el.getAttribute('data-roles') || '')
                .split(',')
                .map((r) => r.trim().toLowerCase())
                .filter(Boolean);
            if (allowed.length === 0) return;
            el.style.display = allowed.includes(role) ? '' : 'none';
        });
    }

    async function login(username, password) {
        const err = $('hub-login-err');
        if (err) err.textContent = '';
        const btn = $('hub-btn-login');
        if (btn) btn.disabled = true;
        const base = apiBase();
        try {
            const res = await fetch(`${base}/auth/login.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    mode: 'system',
                    username: String(username || '').trim(),
                    password: String(password || ''),
                }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || json.success !== true || !json.data) {
                throw new Error(json.message || 'Błąd logowania');
            }
            const user = json.data.user;
            const role = (user && user.role) || '';
            if (!roleAllowed(role)) {
                await fetch(`${base}/auth/logout.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({}),
                }).catch(() => {});
                throw new Error('Brak dostępu do Hubu (wymagana rola: właściciel, manager lub admin).');
            }
            localStorage.setItem(TOKEN_KEY, json.data.token);
            localStorage.setItem(USER_KEY, JSON.stringify(user));
            showDash(user);
        } catch (e) {
            if (err) err.textContent = e.message || 'Błąd logowania';
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    async function logout() {
        const base = apiBase();
        await fetch(`${base}/auth/logout.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({}),
        }).catch(() => {});
        localStorage.removeItem(TOKEN_KEY);
        localStorage.removeItem(USER_KEY);
        showLogin();
        const pw = $('hub-password');
        if (pw) pw.value = '';
        const u = $('hub-username');
        if (u) u.value = '';
    }

    function boot() {
        const token = localStorage.getItem(TOKEN_KEY);
        const user = getStoredUser();
        if (token && user && roleAllowed(user.role)) {
            showDash(user);
            return;
        }
        if (token && user && !roleAllowed(user.role)) {
            localStorage.removeItem(TOKEN_KEY);
            localStorage.removeItem(USER_KEY);
        }
        showLogin();
    }

    document.addEventListener('DOMContentLoaded', () => {
        boot();
        $('hub-form-login')?.addEventListener('submit', (ev) => {
            ev.preventDefault();
            const u = $('hub-username');
            const p = $('hub-password');
            login(u?.value, p?.value);
        });
        $('hub-btn-logout')?.addEventListener('click', () => { void logout(); });
    });
})();
