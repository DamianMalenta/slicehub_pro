(() => {
  'use strict';
  const el = (id) => document.getElementById(id);
  const message = (value) => { el('session-message').textContent = value; };
  const viewMessage = (value) => { el('view-message').textContent = value; };
  let token = '';
  async function request(body) {
    const response = await fetch('/api/symbiont/session-operator.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, redirect: 'error', body: JSON.stringify(body) });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error?.code || 'INTERNAL_ERROR');
    return data;
  }
  function showSession(data) {
    token = data.data.session_token;
    el('session-login').hidden = true;
    el('session-view').hidden = false;
    el('view-tenant').textContent = data.data.tenant_id;
    el('view-actor').textContent = data.data.actor_id;
    el('view-role').textContent = data.data.role;
    el('view-expires').textContent = data.data.expires_at;
    viewMessage('Sesja potwierdzona. Brak wykonanych kompetencji.');
    el('session-form').reset();
  }
  function clearSession() {
    token = '';
    el('session-login').hidden = false;
    el('session-view').hidden = true;
    el('view-tenant').textContent = '';
    el('view-actor').textContent = '';
    el('view-role').textContent = '';
    el('view-expires').textContent = '';
    message('Wylogowano. Sesja unieważniona.');
  }
  async function submitLogin(event) {
    event.preventDefault();
    message('Logowanie...');
    try {
      const data = await request({ protocol: 'symbiont.operator-session', version: '1.0.0', request_id: crypto.randomUUID(), operation: 'session.create', tenant_id: Number(el('tenant-id').value), actor_id: el('actor-id').value.trim(), pin_code: el('pin-code').value, role: el('role').value });
      showSession(data);
    } catch (error) { message(error.message === 'INVALID_CREDENTIALS' ? 'Niepoprawne dane operatora.' : error.message); }
  }
  async function doLogout() {
    viewMessage('Wylogowywanie...');
    try {
      await request({ protocol: 'symbiont.operator-session', version: '1.0.0', request_id: crypto.randomUUID(), operation: 'session.end', session_token: token });
      clearSession();
    } catch (error) { viewMessage(error.message); }
  }
  async function doRefresh() {
    viewMessage('Sprawdzanie...');
    try {
      const data = await request({ protocol: 'symbiont.operator-session', version: '1.0.0', request_id: crypto.randomUUID(), operation: 'session.verify', session_token: token });
      el('view-expires').textContent = data.data.expires_at;
      viewMessage('Sesja nadal ważna.');
    } catch (error) { viewMessage(error.message); }
  }
  el('session-form').addEventListener('submit', submitLogin);
  el('session-logout').addEventListener('click', doLogout);
  el('session-refresh').addEventListener('click', doRefresh);
})();
