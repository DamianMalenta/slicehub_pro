import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import chokidar from 'chokidar';
import picomatch from 'picomatch';
import { enqueuePushToCursor } from './pushTask.js';
import { paths, pythonCfg } from './config.js';
import { emit } from './logger.js';
import { sanitizeLabel } from '../utils/sanitize.js';

function normalizeTarget(raw) {
  if (!raw || typeof raw !== 'string') throw new Error('Brak celu CURSOR_*');
  const up = raw.trim().toUpperCase();
  if (up === 'CURSOR_ALL') return 'CURSOR_ALL';
  return sanitizeLabel(raw);
}

function defaultConfig() {
  return {
    enabled: false,
    debounce_ms: 500,
    rules: [],
    push_after_save_note: {
      enabled: false,
      require_main_auto_push_enabled: true,
      target: 'CURSOR_1',
      extra_instruction: 'Przetwórz notatkę @.',
      mention_syntax: true,
      preflight_esc: false,
    },
  };
}

function readConfigFile() {
  const p = paths.autoPushConfig;
  const base = defaultConfig();
  if (!fs.existsSync(p)) {
    fs.mkdirSync(path.dirname(p), { recursive: true });
    fs.writeFileSync(p, JSON.stringify(base, null, 2), 'utf8');
    return base;
  }
  const raw = JSON.parse(fs.readFileSync(p, 'utf8'));
  return {
    ...base,
    ...raw,
    rules: Array.isArray(raw.rules) ? raw.rules : [],
    push_after_save_note: {
      ...base.push_after_save_note,
      ...(raw.push_after_save_note || {}),
    },
  };
}

function writeEnabledFlag(enabled) {
  const cur = readConfigFile();
  cur.enabled = !!enabled;
  fs.mkdirSync(path.dirname(paths.autoPushConfig), { recursive: true });
  fs.writeFileSync(paths.autoPushConfig, JSON.stringify(cur, null, 2), 'utf8');
  return cur;
}

function expandTemplate(template, relPosix, noteBaseName) {
  if (!template || typeof template !== 'string') return '';
  return template
    .replace(/\{\{relpath\}\}/g, relPosix)
    .replace(/\{\{file\}\}/g, noteBaseName)
    .replace(/\{\{name\}\}/g, noteBaseName);
}

function sha256File(absPath) {
  const buf = fs.readFileSync(absPath);
  return crypto.createHash('sha256').update(buf).digest('hex');
}

function relPosixFromNotes(absPath) {
  let rel = path.relative(paths.notes, absPath);
  rel = rel.split(path.sep).join('/');
  return rel;
}

/**
 * @param {import('./queue.js').TaskQueue} queue
 */
export function createAutoPush(queue) {
  let watcher = null;
  /** @type {Map<string, NodeJS.Timeout>} */
  const debouncers = new Map();
  /** @type {Map<string, string>} — klucz `${ruleId}:${rel}` → hash po udanym pushu */
  const lastSuccessHash = new Map();
  /** @type {Map<string, number>} — cooldown per rule+target */
  const lastCooldownAt = new Map();
  /** @type {Map<string, { hash: string, ts: number }>} — dedup ostatniego enqueue (hook vs watcher) */
  const recentEnqueue = new Map();
  /** meta zadań auto-push: taskId → { dedupeKey, hash } */
  const pendingTaskMeta = new Map();
  let queueUnsub = null;

  function cfg() {
    return readConfigFile();
  }

  function matchRule(relPosix, rule, ruleId) {
    if (!rule || rule.enabled === false) return false;
    const glob = rule.glob || '**/*.md';
    try {
      const pm = picomatch(glob, { dot: false, nocase: true });
      return pm(relPosix);
    } catch (exc) {
      emit('warn', 'auto_push_bad_glob', { ruleId, glob, message: exc.message });
      return false;
    }
  }

  function findRuleFor(relPosix, rules) {
    const list = rules || [];
    for (let i = 0; i < list.length; i++) {
      const rule = list[i] || {};
      const rid = rule.id || `rule_${i}`;
      if (matchRule(relPosix, rule, rid)) return { rule, ruleId: rid };
    }
    return null;
  }

  function shouldSkipRecentDuplicate(relPosix, hash) {
    const prev = recentEnqueue.get(relPosix);
    const windowMs = 4000;
    if (prev && prev.hash === hash && Date.now() - prev.ts < windowMs) return true;
    return false;
  }

  function markRecentEnqueue(relPosix, hash) {
    recentEnqueue.set(relPosix, { hash, ts: Date.now() });
  }

  function tryEnqueueFromRule(relPosix, absPath, source) {
    const config = cfg();
    if (!config.enabled) return;

    const picked = findRuleFor(relPosix, config.rules);
    if (!picked) return;

    const { rule, ruleId } = picked;
    let hash;
    try {
      hash = sha256File(absPath);
    } catch (exc) {
      emit('warn', 'auto_push_hash_failed', { rel: relPosix, message: exc.message });
      return;
    }

    const dedupeKey = `${ruleId}:${relPosix}`;
    if (rule.dedupe_by_hash !== false && lastSuccessHash.get(dedupeKey) === hash) {
      emit('debug', 'auto_push_skip_same_hash', { rel: relPosix, ruleId, source });
      return;
    }

    if (shouldSkipRecentDuplicate(relPosix, hash)) {
      emit('debug', 'auto_push_skip_recent_enqueue', { rel: relPosix, source });
      return;
    }

    const cooldownMs = Number(rule.cooldown_ms) || 0;
    const cdKey = `${ruleId}:${rule.target}`;
    if (cooldownMs > 0) {
      const last = lastCooldownAt.get(cdKey) || 0;
      if (Date.now() - last < cooldownMs) {
        emit('debug', 'auto_push_skip_cooldown', { rel: relPosix, ruleId, source });
        return;
      }
    }

    const noteBase = path.basename(absPath);
    const target = normalizeTarget(rule.target || 'CURSOR_1');
    const extra = expandTemplate(rule.extra_instruction || '', relPosix, noteBase);

    const label = `auto|${ruleId}|${relPosix}|${hash}`;

    try {
      const { id } = enqueuePushToCursor(queue, {
        pythonExe: pythonCfg.exe,
        workersDir: paths.workersDir,
        target_label: target,
        note_name: noteBase,
        extra_instruction: extra,
        mention_syntax: rule.mention_syntax !== false,
        session_id: 'auto_push',
        preflight_esc: !!rule.preflight_esc,
        dry_run: false,
        priority: 7,
        label,
      });
      pendingTaskMeta.set(id, { dedupeKey, hash });
      markRecentEnqueue(relPosix, hash);
      lastCooldownAt.set(cdKey, Date.now());
      emit('info', 'auto_push_enqueued', { rel: relPosix, ruleId, target, source, task_id: id });
    } catch (exc) {
      emit('error', 'auto_push_enqueue_failed', { message: exc.message, rel: relPosix });
    }
  }

  function processFileDebounced(absPath) {
    const rel = relPosixFromNotes(absPath);
    if (rel.startsWith('..') || rel.includes('..')) return;
    tryEnqueueFromRule(rel, absPath, 'watcher');
  }

  function scheduleDebounced(absPath) {
    const config = cfg();
    const ms = Math.max(50, Number(config.debounce_ms) || 500);
    const prev = debouncers.get(absPath);
    if (prev) clearTimeout(prev);
    const t = setTimeout(() => {
      debouncers.delete(absPath);
      processFileDebounced(absPath);
    }, ms);
    debouncers.set(absPath, t);
  }

  function stopWatcher() {
    for (const t of debouncers.values()) clearTimeout(t);
    debouncers.clear();
    if (watcher) {
      try {
        watcher.close();
      } catch {}
      watcher = null;
    }
    emit('info', 'auto_push_watcher_stopped', {});
  }

  function startWatcher() {
    stopWatcher();
    const config = cfg();
    if (!config.enabled) return;

    const base = paths.notes;
    watcher = chokidar.watch(base, {
      ignored: (p) => {
        const lower = p.toLowerCase();
        return !lower.endsWith('.md');
      },
      ignoreInitial: true,
      depth: 30,
      awaitWriteFinish: { stabilityThreshold: 280, pollInterval: 120 },
    });

    watcher.on('change', (p) => scheduleDebounced(p));
    watcher.on('add', (p) => scheduleDebounced(p));

    watcher.on('ready', () => {
      emit('info', 'auto_push_watcher_ready', { dir: base });
    });

    watcher.on('error', (err) => {
      emit('error', 'auto_push_watcher_error', { message: err.message });
    });
  }

  function wireQueueCompletion() {
    if (queueUnsub) return;
    queueUnsub = queue.on((ev) => {
      if (ev.type !== 'completed' && ev.type !== 'failed') return;
      const id = ev.item?.id;
      if (!id || !pendingTaskMeta.has(id)) return;
      const meta = pendingTaskMeta.get(id);
      pendingTaskMeta.delete(id);

      if (ev.type === 'failed') {
        emit('warn', 'auto_push_task_failed', { dedupeKey: meta?.dedupeKey, error: ev.error });
        return;
      }

      const res = ev.result;
      const ok = !!(res && typeof res === 'object' && res.ok === true);

      if (ok && meta) {
        lastSuccessHash.set(meta.dedupeKey, meta.hash);
        emit('info', 'auto_push_completed_ok', { dedupeKey: meta.dedupeKey });
      } else if (meta) {
        emit('warn', 'auto_push_completed_fail', { dedupeKey: meta.dedupeKey });
      }
    });
  }

  function onNoteSaved(noteName) {
    const config = cfg();
    const pas = config.push_after_save_note || {};
    if (!pas.enabled) return;
    if (pas.require_main_auto_push_enabled !== false && !config.enabled) return;

    const safeName = noteName;
    const absPath = path.join(paths.notes, safeName);
    if (!fs.existsSync(absPath)) return;

    const rel = relPosixFromNotes(absPath);
    let hash;
    try {
      hash = sha256File(absPath);
    } catch {
      return;
    }

    if (shouldSkipRecentDuplicate(rel, hash)) return;

    try {
      const target = normalizeTarget(pas.target || 'CURSOR_1');
      const extra = expandTemplate(pas.extra_instruction || '', rel, path.basename(absPath));
      const label = `auto|save_hook|${rel}|${hash}`;
      const { id } = enqueuePushToCursor(queue, {
        pythonExe: pythonCfg.exe,
        workersDir: paths.workersDir,
        target_label: target,
        note_name: path.basename(absPath),
        extra_instruction: extra,
        mention_syntax: pas.mention_syntax !== false,
        session_id: 'auto_push_save',
        preflight_esc: !!pas.preflight_esc,
        dry_run: false,
        priority: 7,
        label,
      });
      const dedupeKey = `save_hook:${rel}`;
      pendingTaskMeta.set(id, { dedupeKey, hash });
      markRecentEnqueue(rel, hash);
      emit('info', 'auto_push_save_hook_enqueued', { rel, task_id: id });
    } catch (exc) {
      emit('error', 'auto_push_save_hook_failed', { message: exc.message });
    }
  }

  function start() {
    wireQueueCompletion();
    startWatcher();
  }

  function stop() {
    if (queueUnsub) queueUnsub();
    queueUnsub = null;
    pendingTaskMeta.clear();
    stopWatcher();
  }

  function reload() {
    startWatcher();
  }

  function getPublicSnapshot() {
    const c = cfg();
    return {
      enabled: !!c.enabled,
      debounce_ms: c.debounce_ms,
      rules_count: (c.rules || []).filter((r) => r.enabled !== false).length,
      push_after_save_note: !!(c.push_after_save_note && c.push_after_save_note.enabled),
      config_path: paths.autoPushConfig,
    };
  }

  function setEnabled(flag) {
    writeEnabledFlag(flag);
    reload();
    return getPublicSnapshot();
  }

  return {
    start,
    stop,
    reload,
    onNoteSaved,
    getPublicSnapshot,
    setEnabled,
  };
}

let controllerSingleton = null;

export function attachAutoPush(queue) {
  controllerSingleton = createAutoPush(queue);
  controllerSingleton.start();
  return controllerSingleton;
}

export function notifyNoteSaved(noteName) {
  try {
    controllerSingleton?.onNoteSaved(noteName);
  } catch (exc) {
    emit('warn', 'notify_note_saved_failed', { message: exc.message });
  }
}

export function getAutoPushController() {
  return controllerSingleton;
}
