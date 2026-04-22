import { Router } from 'express';
import { getAutoPushController } from '../services/autoPush.js';

export function autoPushRouter() {
  const r = Router();

  r.get('/auto_push', (_req, res) => {
    const c = getAutoPushController();
    if (!c) return res.status(500).json({ ok: false, error: 'auto_push_unavailable' });
    res.json({ ok: true, ...c.getPublicSnapshot() });
  });

  r.patch('/auto_push', (req, res) => {
    const c = getAutoPushController();
    if (!c) return res.status(500).json({ ok: false, error: 'auto_push_unavailable' });
    const enabled = req.body?.enabled;
    if (typeof enabled !== 'boolean') {
      return res.status(400).json({ ok: false, error: 'bad_request', message: 'Wymagane body: { enabled: boolean }' });
    }
    const snap = c.setEnabled(enabled);
    res.json({ ok: true, ...snap });
  });

  return r;
}
