import 'dotenv/config';
import express from 'express';
import helmet from 'helmet';
import cors from 'cors';
import path from 'node:path';
import url from 'node:url';
import http from 'node:http';
import { WebSocketServer } from 'ws';
import fs from 'node:fs';

import { panelCfg, paths, clipboardWatcherCfg } from './services/config.js';
import { startClipboardBridge } from './services/clipboardBridge.js';
import { chatRouter } from './routes/chat.js';
import { sessionsRouter } from './routes/sessions.js';
import { autoPushRouter } from './routes/autoPush.js';
import { TaskQueue } from './services/queue.js';
import { attachAutoPush } from './services/autoPush.js';
import { emit, onLog, appendFileLog } from './services/logger.js';

const here = path.dirname(url.fileURLToPath(import.meta.url));
const frontendDir = path.resolve(here, '..', 'frontend');

for (const p of [paths.notes, paths.logs, paths.screenshots, paths.sessions, paths.profiles]) {
  fs.mkdirSync(p, { recursive: true });
}
fs.mkdirSync(path.dirname(paths.autoPushConfig), { recursive: true });

const app = express();
app.use(helmet({ contentSecurityPolicy: false }));
app.use(cors({ origin: true }));
app.use(express.json({ limit: '2mb' }));

const queue = new TaskQueue();
const autoPushCtl = attachAutoPush(queue);
app.set('queueSnapshot', () => queue.snapshot());
app.set('queuePause', () => queue.pause());
app.set('queueResume', () => queue.resume());

app.use('/api', chatRouter(queue));
app.use('/api', sessionsRouter());
app.use('/api', autoPushRouter());

app.get('/api/health', (_req, res) => {
  res.json({
    ok: true,
    uptime_s: Math.round(process.uptime()),
    queue: queue.snapshot(),
  });
});

app.use(express.static(frontendDir));

const server = http.createServer(app);
const wss = new WebSocketServer({ server, path: '/ws' });

const sockets = new Set();
wss.on('connection', (ws) => {
  sockets.add(ws);
  ws.send(JSON.stringify({ type: 'hello', ts: Date.now() }));
  ws.on('close', () => sockets.delete(ws));
});

function sendToAll(event) {
  const payload = JSON.stringify({ type: 'log', event });
  for (const ws of sockets) {
    if (ws.readyState === 1) ws.send(payload);
  }
}

onLog((event) => {
  appendFileLog(paths.logs, event);
  sendToAll(event);
});

queue.on((event) => {
  emit('info', `queue_${event.type}`, { queue_event: event });
});

const clipboardBridge = startClipboardBridge(clipboardWatcherCfg);

function shutdownClipboardBridge() {
  try {
    clipboardBridge.stop();
  } catch {}
}

function exitProcess() {
  shutdownClipboardBridge();
  try {
    autoPushCtl.stop();
  } catch {}
  process.exit(0);
}

process.once('SIGINT', exitProcess);
process.once('SIGTERM', exitProcess);

const host = panelCfg.host || '127.0.0.1';
const port = Number(process.env.PORT || panelCfg.port || 7878);

server.listen(port, host, () => {
  emit('info', 'gema0_started', { url: `http://${host}:${port}`, frontend: frontendDir });
  process.stdout.write(`\nGEMA-0 Command Center -> http://${host}:${port}\n`);
});
