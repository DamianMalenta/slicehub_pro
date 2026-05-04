/**
 * SliceHub — Kiosk obecność (zmiana HR)
 * 1) JWT: konto techniczne terminala (lokal)
 * 2) PIN pracownika (profil HR)
 * 3) Start / licznik czasu pracy / Koniec — ten sam silnik co POS (HrClockEngine)
 */
(() => {
    'use strict';

    const TOKEN_KEY = 'sh_token';
    const USER_KEY = 'sh_user';
    const PIN_LEN = 4;

    function apiBase() {
        const meta = document.querySelector('meta[name="sh-api-base"]');
        if (meta && meta.content) {
            const b = String(meta.content).trim().replace(/\/+$/, '');
            if (b) return b;
        }
        const p = window.location.pathname || '';
        const m = p.indexOf('/modules/');
        if (m > 0) return p.slice(0, m) + '/api';
        return '/slicehub/api';
    }

    const BASE = apiBase();
    const HR_URL = `${BASE}/backoffice/hr/engine.php`;
    const LOGIN_URL = `${BASE}/auth/login.php`;

    let _token = localStorage.getItem(TOKEN_KEY) || '';
    let _pinBuf = '';

    let _sessionStartMs = null;
    let _timerId = null;
    /** PIN z ostatniego poprawnego odczytu — do clock_in / clock_out */
    let _activePin = '';

    const $ = (id) => document.getElementById(id);

    function toast(msg, ok = true) {
        const el = $('ka-toast');
        if (!el) return;
        el.textContent = msg;
        el.className = 'ka-toast visible ' + (ok ? 'ok' : 'err');
        clearTimeout(toast._t);
        toast._t = setTimeout(() => el.classList.remove('visible'), 3200);
    }

    async function hrPost(payload) {
        const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
        if (_token) headers.Authorization = 'Bearer ' + _token;
        const res = await fetch(HR_URL, {
            method: 'POST',
            headers,
            body: JSON.stringify(payload),
        });
        const json = await res.json().catch(() => ({}));
        return {
            ok: res.ok,
            success: json.success === true,
            message: json.message || json.code || '',
            code: json.code,
            data: json.data ?? null,
        };
    }

    function showView(name) {
        ['view-terminal', 'view-pin', 'view-work'].forEach((v) => {
            const el = $(v);
            if (el) el.classList.toggle('hidden', v !== name);
        });
    }

    function formatHMS(totalSec) {
        const s = Math.max(0, Math.floor(totalSec));
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        return [h, m, sec].map((n) => String(n).padStart(2, '0')).join(':');
    }

    function stopTimer() {
        if (_timerId) {
            clearInterval(_timerId);
            _timerId = null;
        }
        _sessionStartMs = null;
    }

    function startTimerFromIso(startIso) {
        stopTimer();
        const t = Date.parse(startIso);
        if (Number.isNaN(t)) return;
        _sessionStartMs = t;
        const tick = () => {
            const el = $('ka-work-elapsed');
            if (!el || !_sessionStartMs) return;
            el.textContent = formatHMS((Date.now() - _sessionStartMs) / 1000);
        };
        tick();
        _timerId = setInterval(tick, 1000);
    }

    function updatePinDots() {
        const wrap = $('ka-pin-dots');
        if (!wrap) return;
        wrap.innerHTML = Array.from({ length: PIN_LEN }, (_, i) =>
            `<span class="ka-dot ${i < _pinBuf.length ? 'filled' : ''}"></span>`
        ).join('');
    }

    function applyWorkView(data, pinStr) {
        const snap = data.employee_snapshot;
        const open = data.open_sessions || [];

        $('ka-work-name').textContent = snap?.display_name || 'Pracownik';
        $('ka-work-role').textContent = snap?.primary_role ? String(snap.primary_role) : '';

        if (open.length > 0) {
            startTimerFromIso(open[0].start_time);
            $('ka-btn-start').classList.add('hidden');
            $('ka-btn-stop').classList.remove('hidden');
        } else {
            stopTimer();
            $('ka-work-elapsed').textContent = '00:00:00';
            $('ka-btn-start').classList.remove('hidden');
            $('ka-btn-stop').classList.add('hidden');
        }

        _activePin = pinStr;
        showView('view-work');
    }

    async function onPinComplete() {
        const err = $('ka-pin-err');
        if (err) err.textContent = '';
        const pinStr = _pinBuf;
        const r = await hrPost({
            action: 'clock_status',
            source: 'kiosk',
            auth: { pin: pinStr },
        });
        _pinBuf = '';
        updatePinDots();

        if (!r.success || !r.data) {
            if (err) err.textContent = r.message || 'PIN nieprawidłowy';
            return;
        }
        applyWorkView(r.data, pinStr);
    }

    window._kaPin = function (v) {
        if (v === 'clear') {
            _pinBuf = '';
        } else if (v === 'back') {
            _pinBuf = _pinBuf.slice(0, -1);
        } else if (_pinBuf.length < PIN_LEN && /^\d$/.test(v)) {
            _pinBuf += v;
            if (_pinBuf.length === PIN_LEN) void onPinComplete();
        }
        updatePinDots();
    };

    async function clockStart() {
        if (!_activePin || _activePin.length !== PIN_LEN) {
            toast('Użyj najpierw PIN', false);
            showView('view-pin');
            return;
        }
        const r = await hrPost({
            action: 'clock_in',
            source: 'kiosk',
            auth: { pin: _activePin },
        });
        if (!r.success) {
            toast(r.message || 'Nie można rozpocząć zmiany', false);
            return;
        }
        if (r.data?.start_time) startTimerFromIso(r.data.start_time);
        $('ka-btn-start').classList.add('hidden');
        $('ka-btn-stop').classList.remove('hidden');
        toast('Zmiana rozpoczęta', true);
    }

    async function clockStop() {
        if (!_activePin || _activePin.length !== PIN_LEN) {
            toast('Sesja wygasła — wpisz PIN ponownie', false);
            showView('view-pin');
            return;
        }
        const r = await hrPost({
            action: 'clock_out',
            source: 'kiosk',
            auth: { pin: _activePin },
        });
        if (!r.success) {
            toast(r.message || 'Nie można zakończyć zmiany', false);
            return;
        }
        stopTimer();
        $('ka-work-elapsed').textContent = '00:00:00';
        $('ka-btn-start').classList.remove('hidden');
        $('ka-btn-stop').classList.add('hidden');
        toast('Zmiana zakończona', true);
    }

    async function terminalLogin(ev) {
        ev.preventDefault();
        const u = $('ka-term-user')?.value?.trim() || '';
        const p = $('ka-term-pass')?.value || '';
        const err = $('ka-term-err');
        if (err) err.textContent = '';
        const res = await fetch(LOGIN_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode: 'system', username: u, password: p }),
        });
        const json = await res.json().catch(() => ({}));
        if (!json.success || !json.data?.token) {
            if (err) err.textContent = json.message || 'Błąd logowania';
            return;
        }
        _token = json.data.token;
        localStorage.setItem(TOKEN_KEY, _token);
        if (json.data.user) localStorage.setItem(USER_KEY, JSON.stringify(json.data.user));
        toast('Terminal zalogowany', true);
        showView('view-pin');
    }

    function logoutTerminal() {
        stopTimer();
        const tok = localStorage.getItem(TOKEN_KEY);
        _token = '';
        _activePin = '';
        localStorage.removeItem(TOKEN_KEY);
        localStorage.removeItem(USER_KEY);
        fetch(`${BASE}/auth/logout.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', ...(tok ? { Authorization: 'Bearer ' + tok } : {}) },
            body: JSON.stringify({}),
        }).catch(() => {});
        showView('view-terminal');
    }

    function bind() {
        $('ka-form-terminal')?.addEventListener('submit', terminalLogin);
        $('ka-btn-logout-terminal')?.addEventListener('click', logoutTerminal);
        $('ka-btn-start')?.addEventListener('click', () => void clockStart());
        $('ka-btn-stop')?.addEventListener('click', () => void clockStop());
        $('ka-btn-other')?.addEventListener('click', () => {
            stopTimer();
            _activePin = '';
            _pinBuf = '';
            updatePinDots();
            showView('view-pin');
        });
    }

    function boot() {
        bind();
        updatePinDots();
        if (_token) showView('view-pin');
        else showView('view-terminal');
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
