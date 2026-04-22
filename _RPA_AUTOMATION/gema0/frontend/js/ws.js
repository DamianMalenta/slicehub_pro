export function connectWs(onEvent) {
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  const url = `${proto}://${location.host}/ws`;
  let ws;
  let tries = 0;

  function open() {
    ws = new WebSocket(url);
    ws.addEventListener('open', () => {
      tries = 0;
      onEvent({ type: 'ws_open' });
    });
    ws.addEventListener('message', (ev) => {
      try {
        const data = JSON.parse(ev.data);
        onEvent(data);
      } catch {}
    });
    ws.addEventListener('close', () => {
      onEvent({ type: 'ws_close' });
      tries += 1;
      setTimeout(open, Math.min(8000, 500 * tries));
    });
    ws.addEventListener('error', () => {
      try { ws.close(); } catch {}
    });
  }

  open();
}
