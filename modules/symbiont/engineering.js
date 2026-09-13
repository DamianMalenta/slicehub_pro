(() => {
  'use strict';
  const el = (id) => document.getElementById(id);
  let generation = 0;
  let busy = false;
  const errors = { DISABLED: 'Funkcja jest wyłączona lub nieskonfigurowana. Uruchom izolowany pilot B-001.', UNAUTHORIZED: 'Odmowa. Potrzebny osobny klucz administratora pakietu B-001.', PACKAGE_UNAVAILABLE: 'Pakiet niedostępny. Sprawdź przygotowanie izolowanego pilota.', INTEGRITY_ERROR: 'Pakiet nie odpowiada zatwierdzonym źródłom. Nie udostępniono go.', UNSAFE_TRANSPORT: 'Nie wysyłam klucza przez obcy lub niezabezpieczony adres.', UNSUPPORTED_VERSION: 'Niezgodny kontrakt. Nie wykonano odczytu.' };
  el('engineering-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (busy) return;
    const current = ++generation;
    const token = el('engineering-key').value;
    el('engineering-key').value = '';
    el('engineering-details').textContent = '';
    el('engineering-message').textContent = 'Sprawdzam rzeczywisty endpoint PHP.';
    busy = true;
    el('engineering-check').disabled = true;
    try {
      const location = globalThis.location;
      const endpoint = new URL(globalThis.SliceHub.apiUrl('/symbiont/engineering-admin.php'), location.href);
      if (endpoint.origin !== location.origin || (location.protocol !== 'https:' && !['127.0.0.1', 'localhost', '[::1]'].includes(location.hostname))) throw new Error('UNSAFE_TRANSPORT');
      const request_id = globalThis.crypto.randomUUID();
      const response = await fetch(endpoint.href, { method: 'POST', redirect: 'error', signal: AbortSignal.timeout(8000), headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ protocol: 'symbiont.engineering-read', version: '1.0.0', request_id, operation: 'snapshot.describe', params: { package_id: 'slicehub-graft-a-baseline' } }) });
      const body = await response.json();
      if (!response.ok || body.ok !== true) throw new Error(body.error?.code || 'UNAVAILABLE');
      if (body.protocol !== 'symbiont.engineering-read' || body.version !== '1.0.0' || body.request_id !== request_id || !body.data?.manifest || !body.data?.identity || !body.data?.observed_at) throw new Error('INVALID_RESPONSE');
      if (current !== generation) return;
      el('engineering-details').textContent = JSON.stringify(body.data, null, 2);
      el('engineering-message').textContent = 'Potwierdzono udostępniony zakres w tej obserwacji. Nie pobrano treści źródeł ani danych biznesowych.';
    } catch (error) {
      if (current === generation) el('engineering-message').textContent = errors[error.message] || 'Nie potwierdzono wyniku. Sprawdź połączenie i ponów sprawdzenie; brak fallbacku.';
    } finally { busy = false; el('engineering-check').disabled = false; }
  });
  el('engineering-lock').addEventListener('click', () => {
    generation++;
    el('engineering-key').value = '';
    el('engineering-details').textContent = '';
    el('engineering-message').textContent = 'Zablokowano panel. Nie zmieniono konfiguracji PHP.';
  });
})();
