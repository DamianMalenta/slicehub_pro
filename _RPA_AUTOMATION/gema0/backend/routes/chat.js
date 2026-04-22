import { Router } from 'express';
import { z } from 'zod';
import { parseCommand } from '../services/commandParser.js';
import { writeNoteVersioned, listNotes, readNote } from '../services/noteWriter.js';
import { runWorker } from '../services/rpaDispatcher.js';
import { askGemini } from '../services/geminiClient.js';
import { sanitizeLabel } from '../utils/sanitize.js';
import { paths, pythonCfg, geminiCfg } from '../services/config.js';
import { emit } from '../services/logger.js';
import { enqueuePushToCursor } from '../services/pushTask.js';
import { notifyNoteSaved } from '../services/autoPush.js';

const chatSchema = z.object({
  command: z.string().min(1).max(10_000),
  body: z.string().optional(),
  session_id: z.string().optional(),
  preflight_esc: z.boolean().optional(),
});

export function chatRouter(queue) {
  const r = Router();

  r.post('/chat', async (req, res) => {
    const parsed = chatSchema.safeParse(req.body);
    if (!parsed.success) {
      return res.status(400).json({ ok: false, error: 'bad_request', details: parsed.error.flatten() });
    }
    const { command, body, session_id = 'default', preflight_esc = false } = parsed.data;

    let cmd;
    try {
      cmd = parseCommand(command, { pendingBody: body || '' });
    } catch (exc) {
      return res.status(400).json({ ok: false, error: 'parse_error', message: exc.message });
    }

    emit('info', 'command_received', { type: cmd.type, session_id });

    try {
      switch (cmd.type) {
        case 'save_note': {
          const info = writeNoteVersioned(paths.notes, cmd.name, cmd.body);
          emit('info', 'note_saved', info);
          notifyNoteSaved(info.name);
          return res.json({ ok: true, type: 'save_note', note: info });
        }

        case 'push': {
          const target = sanitizeLabelSafely(cmd.target);
          const { id, payload } = enqueuePushToCursor(queue, {
            pythonExe: pythonCfg.exe,
            workersDir: paths.workersDir,
            target_label: target,
            note_name: cmd.note_name || '',
            extra_instruction: cmd.extra_instruction || '',
            mention_syntax: !!cmd.mention_syntax,
            submit: true,
            dry_run: !!cmd.dry_run,
            session_id,
            preflight_esc: !!preflight_esc,
            priority: 3,
            label: `push ${target}`,
          });
          return res.json({ ok: true, type: 'push', queued: id, payload });
        }

        case 'healthcheck': {
          const result = await runWorker({
            pythonExe: pythonCfg.exe,
            workersDir: paths.workersDir,
            scriptName: 'healthcheck.py',
            payload: {},
            timeoutMs: 20_000,
          });
          return res.json({ ok: true, type: 'healthcheck', result });
        }

        case 'gemini_ask': {
          const resp = await askGemini({
            apiKey: process.env.GEMINI_API_KEY,
            model: geminiCfg.model,
            prompt: cmd.prompt,
            rateLimit: geminiCfg.rate_limit_per_minute,
          });
          emit('info', 'gemini_answer', { chars: resp.text.length });
          return res.json({ ok: true, type: 'gemini_answer', text: resp.text });
        }

        case 'chat_text':
        default:
          return res.json({ ok: true, type: 'chat_text', echo: cmd.text });
      }
    } catch (exc) {
      emit('error', 'chat_handler_error', { message: exc.message });
      return res.status(500).json({ ok: false, error: 'handler', message: exc.message });
    }
  });

  r.get('/notes', (_req, res) => {
    res.json({ ok: true, notes: listNotes(paths.notes) });
  });

  r.get('/notes/:name', (req, res) => {
    try {
      const note = readNote(paths.notes, req.params.name);
      res.json({ ok: true, note });
    } catch (exc) {
      res.status(404).json({ ok: false, message: exc.message });
    }
  });

  return r;
}

function sanitizeLabelSafely(raw) {
  if (raw === 'CURSOR_ALL') return raw;
  return sanitizeLabel(raw);
}
