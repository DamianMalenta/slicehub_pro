(() => {
    'use strict';
    const el = (id) => document.getElementById(id);
    el('hub-back').hidden = new URL(globalThis.location.href).searchParams.get('from') !== 'hub';
    let generation = 0;
    let busy = false;
    const errors = { UNAUTHORIZED: 'Brak uprawnień do panelu Wszczepu. Sprawdź osobny klucz administratora.', DISABLED: 'Wszczep wyłączony lub nieskonfigurowany. Postępuj zgodnie z RUNBOOK_A.', UNSUPPORTED_VERSION: 'Niezgodna wersja kontraktu. Nie wykonano diagnostyki.', INVALID_REQUEST: 'Nieprawidłowe żądanie diagnostyczne.', UNSAFE_TRANSPORT: 'Nie wysyłam klucza przez niezabezpieczony lub obcy adres. Otwórz HTTPS albo lokalny panel.' };

    el('bridge-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        const current = ++generation;
        const token = el('bridge-admin').value;
        el('bridge-admin').value = '';
        el('bridge-check').disabled = true;
        el('bridge-state').textContent = 'Sprawdzam';
        el('bridge-details').replaceChildren();
        el('bridge-message').textContent = 'Weryfikuję diagnostykę PHP. Nie uruchamiam operacji biznesowych.';
        try {
            const location = globalThis.location;
            const endpoint = new URL(globalThis.SliceHub.apiUrl('/symbiont/admin.php'), location.href);
            if (endpoint.origin !== location.origin || (location.protocol !== 'https:' && !['127.0.0.1', 'localhost', '[::1]'].includes(location.hostname))) throw new Error('UNSAFE_TRANSPORT');
            const requestId = globalThis.crypto.randomUUID();
            const response = await fetch(endpoint.href, {
                method: 'POST', signal: AbortSignal.timeout(8000),
                headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
                body: JSON.stringify({ protocol: 'symbiont.bridge', version: '1.0.0', request_id: requestId, operation: 'diagnose' }),
            });
            const body = await response.json();
            if (!response.ok || body.ok !== true) throw new Error(body.error?.code || 'UNAVAILABLE');
            if (body.protocol !== 'symbiont.bridge' || body.version !== '1.0.0' || body.request_id !== requestId || body.data?.checks?.business_access !== 'not_requested' || body.data?.checks?.bridge !== 'ok' || !body.data.identity?.host_id || !body.data.observed_at) throw new Error('UNAVAILABLE');
            if (current !== generation) return;
            el('bridge-state').textContent = 'Most PHP odpowiada';
            el('bridge-message').textContent = 'Potwierdzono tylko read-only diagnostykę Wszczepu. To nie jest test połączenia Mózgu z hostem.';
            for (const [label, value] of [['Host', body.data.identity.host_id], ['Środowisko', body.data.identity.environment], ['Adapter', body.data.identity.adapter_version], ['Data obserwacji', body.data.observed_at], ['Dane biznesowe', 'Nie badano']]) {
                const dt = document.createElement('dt');
                const dd = document.createElement('dd');
                dt.textContent = label;
                dd.textContent = value;
                el('bridge-details').append(dt, dd);
            }
        } catch (error) {
            if (current !== generation) return;
            el('bridge-state').textContent = 'Nie potwierdzono';
            el('bridge-message').textContent = errors[error.message] || 'Brak poprawnej odpowiedzi. Sprawdź łączność i konfigurację. Nie uruchomiono trybu demo.';
        } finally {
            busy = false;
            el('bridge-check').disabled = false;
        }
    });

    el('bridge-lock').addEventListener('click', () => {
        generation++;
        el('bridge-admin').value = '';
        el('bridge-state').textContent = 'Zablokowano';
        el('bridge-details').replaceChildren();
        el('bridge-message').textContent = 'Wynik wyczyszczony. Most PHP nie został wyłączony; tę konfigurację zmienia administrator procesu.';
    });
})();
