(() => {
  'use strict';
  const el = (id) => document.getElementById(id);
  let generation = 0;
  let busy = false;
  const errors = { DISABLED: 'Katalog jest wyłączony lub nieskonfigurowany.', UNAUTHORIZED: 'Odmowa. Potrzebny osobny klucz administratora katalogu B-002.', UNSUPPORTED_VERSION: 'Niezgodny kontrakt katalogu.', EMPTY_CATALOG: 'Host nie opublikował żadnej kompetencji.', UNSAFE_TRANSPORT: 'Nie wysyłam klucza przez obcy lub niezabezpieczony adres.' };
  const clear = () => {
    el('capability-title').textContent = 'Brak potwierdzonej listy';
    el('capability-meta').replaceChildren();
    el('capability-list').replaceChildren();
  };
  el('capability-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (busy) return;
    const current = ++generation;
    const token = el('capability-key').value;
    el('capability-key').value = '';
    clear();
    busy = true;
    el('capability-check').disabled = true;
    el('capability-message').textContent = 'Pobieram rzeczywisty katalog PHP. Nie wykonuję kompetencji.';
    try {
      const location = globalThis.location;
      const endpoint = new URL(globalThis.SliceHub.apiUrl('/symbiont/capabilities-admin.php'), location.href);
      if (endpoint.origin !== location.origin || (location.protocol !== 'https:' && !['127.0.0.1', 'localhost', '[::1]'].includes(location.hostname))) throw new Error('UNSAFE_TRANSPORT');
      const request_id = globalThis.crypto.randomUUID();
      const response = await fetch(endpoint.href, { method: 'POST', redirect: 'error', signal: AbortSignal.timeout(8000), headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ protocol: 'symbiont.capability-catalog', version: '1.0.0', request_id, operation: 'catalog.list' }) });
      const body = await response.json();
      if (!response.ok || body.ok !== true) throw new Error(body.error?.code || 'UNAVAILABLE');
      if (body.protocol !== 'symbiont.capability-catalog' || body.version !== '1.0.0' || body.request_id !== request_id || body.data?.execution !== 'not_permitted' || !Array.isArray(body.data?.capabilities)) throw new Error('INVALID_RESPONSE');
      if (current !== generation) return;
      el('capability-title').textContent = `Potwierdzony katalog: ${body.data.catalog_id}`;
      for (const [label, value] of [['Host', body.data.identity.host_id], ['Środowisko', body.data.identity.environment], ['Data obserwacji', body.data.observed_at], ['Wykonywanie', 'Niedozwolone']]) {
        const dt = document.createElement('dt');
        const dd = document.createElement('dd');
        dt.textContent = label;
        dd.textContent = value;
        el('capability-meta').append(dt, dd);
      }
      for (const capability of body.data.capabilities) {
        const article = document.createElement('article');
        const title = document.createElement('h3');
        const summary = document.createElement('p');
        const detail = document.createElement('p');
        title.textContent = `${capability.id} · ${capability.version}`;
        summary.textContent = capability.summary;
        detail.textContent = `Rodzaj: ${capability.kind}; status: ${capability.status}; rola: ${capability.required_actor_role}; skutki biznesowe: ${capability.business_effects}.`;
        article.append(title, summary, detail);
        el('capability-list').append(article);
      }
      el('capability-message').textContent = 'Katalog potwierdzony. Żadna odkryta kompetencja nie została wykonana.';
    } catch (error) {
      if (current === generation) el('capability-message').textContent = errors[error.message] || 'Nie potwierdzono katalogu. Brak fallbacku i brak wykonania.';
    } finally { busy = false; el('capability-check').disabled = false; }
  });
  el('capability-lock').addEventListener('click', () => {
    generation++;
    el('capability-key').value = '';
    clear();
    el('capability-message').textContent = 'Zablokowano panel. Nie zmieniono konfiguracji PHP.';
  });
})();
