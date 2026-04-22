import { Router } from 'express';
import fs from 'node:fs';
import path from 'node:path';
import { paths } from '../services/config.js';

export function sessionsRouter() {
  const r = Router();

  r.get('/windows_map', (_req, res) => {
    try {
      const body = fs.readFileSync(paths.windowsMap, 'utf8');
      res.type('application/json').send(body);
    } catch (exc) {
      res.status(500).json({ ok: false, message: exc.message });
    }
  });

  r.put('/windows_map', (req, res) => {
    try {
      const data = req.body;
      if (!data || typeof data !== 'object') throw new Error('Zly body.');
      for (const [k, v] of Object.entries(data)) {
        if (!/^CURSOR_[A-Z0-9_]+$/.test(k)) throw new Error(`Zla etykieta: ${k}`);
        if (typeof v !== 'string' || !v.trim()) throw new Error(`Zly hint: ${k}`);
      }
      fs.writeFileSync(paths.windowsMap, JSON.stringify(data, null, 2), 'utf8');
      res.json({ ok: true });
    } catch (exc) {
      res.status(400).json({ ok: false, message: exc.message });
    }
  });

  r.get('/queue', (req, res) => {
    const snap = req.app.get('queueSnapshot')?.() || { pending: [] };
    res.json({ ok: true, queue: snap });
  });

  r.post('/queue/pause', (req, res) => {
    req.app.get('queuePause')?.();
    res.json({ ok: true });
  });

  r.post('/queue/resume', (req, res) => {
    req.app.get('queueResume')?.();
    res.json({ ok: true });
  });

  r.get('/profiles', (_req, res) => {
    try {
      if (!fs.existsSync(paths.profiles)) return res.json({ ok: true, profiles: [] });
      const files = fs
        .readdirSync(paths.profiles)
        .filter((f) => f.toLowerCase().endsWith('.json'))
        .map((f) => {
          const p = path.join(paths.profiles, f);
          return {
            name: f.replace(/\.json$/i, ''),
            body: JSON.parse(fs.readFileSync(p, 'utf8')),
          };
        });
      res.json({ ok: true, profiles: files });
    } catch (exc) {
      res.status(500).json({ ok: false, message: exc.message });
    }
  });

  return r;
}
