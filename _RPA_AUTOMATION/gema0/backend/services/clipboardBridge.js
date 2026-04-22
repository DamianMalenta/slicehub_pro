import { spawn } from 'node:child_process';
import readline from 'node:readline';
import path from 'node:path';
import { emit } from './logger.js';
import { writeNoteVersioned } from './noteWriter.js';
import { paths, pythonCfg } from './config.js';

/**
 * Długożyjący worker: clipboard_watcher.py pisze zdarzenia JSON na stdout (linie).
 * Stderr: JSONL jak w pozostałych workerach RPA.
 */
export function startClipboardBridge(cfg) {
  if (!cfg?.enabled) {
    return { stop: () => {} };
  }

  const scriptPath = path.resolve(paths.workersDir, 'clipboard_watcher.py');
  const child = spawn(pythonCfg.exe, ['-u', scriptPath], {
    cwd: paths.workersDir,
    windowsHide: true,
    env: {
      ...process.env,
      PYTHONPATH: paths.workersDir,
      PYTHONIOENCODING: 'utf-8',
      PYTHONUTF8: '1',
    },
  });

  child.stderr?.on('data', (chunk) => {
    const text = chunk.toString('utf8');
    for (const line of text.split(/\r?\n/)) {
      if (!line.trim()) continue;
      try {
        const obj = JSON.parse(line);
        emit(obj.lvl ? obj.lvl.toLowerCase() : 'info', obj.msg || 'clipboard_worker', {
          worker: 'clipboard_watcher.py',
          ...obj.ctx,
        });
      } catch {
        emit('debug', 'clipboard_worker_raw', { line: line.slice(0, 120) });
      }
    }
  });

  const rl = readline.createInterface({ input: child.stdout });
  rl.on('line', (line) => {
    if (!line.trim()) return;
    try {
      const ev = JSON.parse(line);
      if (ev.type !== 'clipboard_change') return;

      const content = typeof ev.content === 'string' ? ev.content : '';
      let saved_note = null;
      if (cfg.save_to_notes && content.length > 0) {
        const info = writeNoteVersioned(paths.notes, 'cursor_clipboard.md', content);
        saved_note = info.name;
      }

      const preview = (ev.preview || content.slice(0, 160)).toString().slice(0, 280);
      const n = content.length || ev.chars || 0;
      emit('info', `Schowek → panel (${n} zn.)`, {
        kind: 'clipboard_inbound',
        chars: n,
        preview,
        saved_note,
      });
    } catch {
      emit('warn', 'clipboard_stdout_parse_error', { line: line.slice(0, 120) });
    }
  });

  child.on('error', (err) => {
    emit('error', 'clipboard_watcher_spawn_failed', { message: err.message });
  });

  child.on('exit', (code, signal) => {
    emit('warn', 'clipboard_watcher_exit', { code, signal });
  });

  emit('info', 'clipboard_watcher_started', { script: path.basename(scriptPath) });

  return {
    stop: () => {
      try {
        child.kill();
      } catch {}
    },
  };
}
