import { connectWs } from './ws.js';

const el = (id) => document.getElementById(id);

const logStream = el('log-stream');
const queueList = el('queue-list');
const notesList = el('notes-list');
const cursorTranscript = el('cursor-transcript');
const geminiTranscript = el('gemini-transcript');
const cmdInput = el('command-input');
const form = el('command-form');
const btnHealth = el('btn-health');
const btnPanic = el('btn-panic');
const btnAsGemini = el('btn-as-gemini');
const btnGToC = el('btn-gemini-to-cursor');
const pushTargetSel = el('gemini-push-target');
const statusPython = el('status-python');
const statusCursor = el('status-cursor');
const statusGemini = el('status-gemini');
const chkAutoPush = el('chk-auto-push');

const state = {
  lastGeminiAnswer: '',
};

function addLogLine(event) {
  const div = document.createElement('div');
  div.className = `log-line log-${event.level || 'info'}`;
  const time = new Date(event.ts || Date.now()).toLocaleTimeString();
  div.textContent = `${time} ${event.message || ''}`;
  logStream.appendChild(div);
  while (logStream.childNodes.length > 300) logStream.removeChild(logStream.firstChild);
  logStream.scrollTop = logStream.scrollHeight;
}

function addBubble(container, who, text) {
  const b = document.createElement('div');
  b.className = `bubble ${who === 'user' ? 'user' : 'ai'}`;
  b.textContent = text;
  container.appendChild(b);
  container.scrollTop = container.scrollHeight;
}

function setStatus(node, level, label) {
  node.className = `px-2 py-0.5 rounded-full border status-${level}`;
  node.textContent = label;
}

async function sendCommand(command, body = '') {
  const res = await fetch('/api/chat', {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ command, body, session_id: 'panel' }),
  });
  return res.json();
}

async function refreshNotes() {
  try {
    const res = await fetch('/api/notes').then((r) => r.json());
    notesList.innerHTML = '';
    for (const n of res.notes || []) {
      const li = document.createElement('li');
      li.className = 'flex items-center gap-2 text-slate-300';
      li.innerHTML = `<span class="truncate">${n.name}</span><span class="ml-auto text-slate-500 text-[10px]">${n.bytes}B</span>`;
      notesList.appendChild(li);
    }
  } catch {}
}

async function refreshAutoPush() {
  try {
    const res = await fetch('/api/auto_push').then((r) => r.json());
    if (res.ok && chkAutoPush) chkAutoPush.checked = !!res.enabled;
  } catch {}
}

async function refreshQueue() {
  try {
    const res = await fetch('/api/queue').then((r) => r.json());
    queueList.innerHTML = '';
    const q = res.queue || { pending: [] };
    if (q.current) {
      const li = document.createElement('li');
      li.className = 'text-fuchsia-300';
      li.textContent = `RUN ${q.current.label}`;
      queueList.appendChild(li);
    }
    for (const it of q.pending) {
      const li = document.createElement('li');
      li.className = 'text-slate-300';
      li.textContent = `· ${it.label}`;
      queueList.appendChild(li);
    }
    if (!q.current && !q.pending.length) {
      queueList.innerHTML = '<li class="text-slate-500">-- puste --</li>';
    }
  } catch {}
}

form.addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const command = cmdInput.value.trim();
  if (!command) return;
  addBubble(cursorTranscript, 'user', command);
  cmdInput.value = '';
  const result = await sendCommand(command);
  handleResult(result);
  refreshNotes();
  refreshQueue();
});

cmdInput.addEventListener('keydown', (ev) => {
  if (ev.ctrlKey && ev.key === 'Enter') {
    ev.preventDefault();
    form.requestSubmit();
  }
});

document.querySelectorAll('button.quick').forEach((b) => {
  b.addEventListener('click', () => {
    cmdInput.value = b.dataset.quick || '';
    cmdInput.focus();
  });
});

btnHealth.addEventListener('click', async () => {
  const res = await sendCommand('HEALTHCHECK');
  handleResult(res);
});

chkAutoPush?.addEventListener('change', async () => {
  try {
    await fetch('/api/auto_push', {
      method: 'PATCH',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ enabled: !!chkAutoPush.checked }),
    });
    await refreshAutoPush();
    addLogLine({
      level: 'info',
      message: chkAutoPush.checked ? 'Auto-push wlaczony (reguly z auto_push.json).' : 'Auto-push wylaczony.',
    });
  } catch {
    addLogLine({ level: 'warn', message: 'Nie udalo sie zmienic auto-push.' });
  }
});

btnPanic.addEventListener('click', async () => {
  await fetch('/api/queue/pause', { method: 'POST' });
  addLogLine({ level: 'warn', message: 'Kolejka zapauzowana (panic).' });
});

btnAsGemini.addEventListener('click', async () => {
  const text = cmdInput.value.trim();
  if (!text) return;
  cmdInput.value = '';
  addBubble(geminiTranscript, 'user', text);
  const res = await sendCommand(`ASK GEMINI ${text}`);
  if (res.ok && res.type === 'gemini_answer') {
    state.lastGeminiAnswer = res.text || '';
    addBubble(geminiTranscript, 'ai', state.lastGeminiAnswer || '(puste)');
  } else {
    addBubble(geminiTranscript, 'ai', `BLAD: ${res.message || JSON.stringify(res)}`);
  }
});

btnGToC.addEventListener('click', async () => {
  if (!state.lastGeminiAnswer) {
    addLogLine({ level: 'warn', message: 'Brak odpowiedzi Gemini do przeslania.' });
    return;
  }
  const target = pushTargetSel.value || 'CURSOR_1';
  const saveRes = await sendCommand('ZAPISZ gemini_answer', state.lastGeminiAnswer);
  if (!saveRes.ok) {
    addLogLine({ level: 'error', message: 'Nie udalo sie zapisac notatki.' });
    return;
  }
  const noteName = saveRes.note.name;
  const pushRes = await sendCommand(`PUSH TO ${target} @${noteName} uzyj tej notatki`);
  handleResult(pushRes);
  refreshNotes();
  refreshQueue();
});

function handleResult(res) {
  if (!res) return;
  switch (res.type) {
    case 'save_note':
      addBubble(cursorTranscript, 'ai', `ZAPISANO ${res.note.name} (${res.note.bytes} B)`);
      break;
    case 'push':
      addBubble(cursorTranscript, 'ai', `PUSH -> ${res.payload.target_label} (queued ${res.queued})`);
      break;
    case 'healthcheck':
      addBubble(cursorTranscript, 'ai', `HEALTH:\n${JSON.stringify(res.result, null, 2)}`);
      updateStatusesFromHealth(res.result);
      break;
    case 'gemini_answer':
      state.lastGeminiAnswer = res.text || '';
      addBubble(geminiTranscript, 'ai', state.lastGeminiAnswer || '(puste)');
      break;
    default:
      addBubble(cursorTranscript, 'ai', JSON.stringify(res, null, 2));
  }
}

function updateStatusesFromHealth(h) {
  if (!h) return;
  const flags = h.flags || {};
  setStatus(
    statusPython,
    flags.pyperclip && flags.pygetwindow && flags.pywinauto ? 'ok' : 'bad',
    flags.pyperclip && flags.pygetwindow && flags.pywinauto ? 'gotowy' : 'brakuje pakietow'
  );
  setStatus(
    statusCursor,
    flags.cursor_running ? 'ok' : 'warn',
    flags.cursor_running ? `${flags.cursor_process_count} okien` : 'nie wykryto'
  );
}

connectWs((msg) => {
  if (msg.type === 'log') {
    const ev = msg.event || {};
    if (ev.kind === 'clipboard_inbound') {
      let bubble = `[schowek → panel] ${ev.chars ?? '?'} znaków`;
      if (ev.preview) bubble += `\n${ev.preview}`;
      if (ev.saved_note) bubble += `\n→ zapisano: ${ev.saved_note}`;
      addBubble(cursorTranscript, 'ai', bubble);
    }
    addLogLine(ev);
  } else if (msg.type === 'ws_open') {
    addLogLine({ level: 'info', message: 'WebSocket polaczony.' });
  } else if (msg.type === 'ws_close') {
    addLogLine({ level: 'warn', message: 'WebSocket rozlaczony, reconnect...' });
  }
});

setStatus(statusGemini, (window?.Gemini || true) ? 'warn' : 'bad', 'brak klucza?');
refreshNotes();
refreshAutoPush();
refreshQueue();
setInterval(refreshQueue, 2500);
