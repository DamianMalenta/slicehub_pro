import fs from 'node:fs';
import path from 'node:path';
import pino from 'pino';

const LEVEL = process.env.LOG_LEVEL || 'info';

const subscribers = new Set();

export const logger = pino({
  level: LEVEL,
  transport: {
    target: 'pino-pretty',
    options: { colorize: true, translateTime: 'HH:MM:ss' },
  },
});

export function onLog(fn) {
  subscribers.add(fn);
  return () => subscribers.delete(fn);
}

export function broadcast(event) {
  for (const fn of subscribers) {
    try {
      fn(event);
    } catch {}
  }
}

export function emit(level, message, ctx = {}) {
  const event = { ts: Date.now(), level, message, ...ctx };
  logger[level] ? logger[level](event) : logger.info(event);
  broadcast(event);
  return event;
}

export function appendFileLog(logsDir, event) {
  try {
    fs.mkdirSync(logsDir, { recursive: true });
    const day = new Date().toISOString().slice(0, 10);
    const file = path.join(logsDir, `${day}.log`);
    fs.appendFileSync(file, JSON.stringify(event) + '\n', 'utf8');
  } catch {}
}
