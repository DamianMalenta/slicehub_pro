import fs from 'node:fs';
import path from 'node:path';
import url from 'node:url';

const here = path.dirname(url.fileURLToPath(import.meta.url));
const backendDir = path.resolve(here, '..');
const configPath = path.resolve(backendDir, 'config', 'config.json');

const raw = JSON.parse(fs.readFileSync(configPath, 'utf8'));

function abs(rel) {
  return path.resolve(backendDir, rel);
}

export const paths = {
  backend: backendDir,
  notes: abs(raw.paths.notes),
  logs: abs(raw.paths.logs),
  screenshots: abs(raw.paths.screenshots),
  sessions: abs(raw.paths.sessions),
  profiles: abs(raw.paths.profiles),
  timingCache: abs(raw.paths.timing_cache),
  workersDir: abs(raw.python.workers_dir),
  windowsMap: path.resolve(backendDir, 'config', 'windows_map.json'),
  autoPushConfig: abs(raw.paths.auto_push_config || '../storage/auto_push.json'),
};

export const panelCfg = raw.panel;
export const geminiCfg = raw.gemini;
export const cursorCfg = raw.cursor;
export const pythonCfg = { exe: process.env.PYTHON_EXE || raw.python.exe };

/** Cursor → panel: polling schowka (clipboard_watcher.py). Wyłączone domyślnie — świadomy opt-in operatora. */
export const clipboardWatcherCfg = raw.clipboard_watcher ?? {
  enabled: false,
  save_to_notes: false,
};
