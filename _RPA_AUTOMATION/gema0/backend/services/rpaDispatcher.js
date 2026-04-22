import path from 'node:path';
import { execa } from 'execa';
import { emit } from './logger.js';

/**
 * Uruchamia worker Pythona jako proces potomny.
 * Wejscie: JSON -> stdin.
 * Stderr: JSONL -> rebroadcast na WebSocket.
 * Stdout: finalny JSON -> zwracamy jako wynik.
 */
export async function runWorker({
  pythonExe,
  workersDir,
  scriptName,
  payload,
  timeoutMs = 30_000,
}) {
  const scriptPath = path.resolve(workersDir, scriptName);

  emit('info', 'rpa_worker_start', { script: scriptName, payload_keys: Object.keys(payload || {}) });

  const subprocess = execa(pythonExe, ['-u', scriptPath], {
    input: JSON.stringify(payload || {}),
    encoding: 'utf8',
    reject: false,
    timeout: timeoutMs,
    windowsHide: true,
    maxBuffer: 50 * 1024 * 1024,
    env: {
      ...process.env,
      PYTHONIOENCODING: 'utf-8',
      PYTHONUTF8: '1',
      PYTHONPATH: workersDir,
    },
  });

  subprocess.stderr?.on('data', (chunk) => {
    const text = chunk.toString('utf8');
    for (const line of text.split(/\r?\n/)) {
      if (!line.trim()) continue;
      try {
        const obj = JSON.parse(line);
        emit(obj.lvl ? obj.lvl.toLowerCase() : 'info', obj.msg || 'worker_event', {
          worker: scriptName,
          ...obj.ctx,
        });
      } catch {
        emit('debug', 'worker_raw', { worker: scriptName, line });
      }
    }
  });

  const result = await subprocess;

  let parsed = null;
  try {
    parsed = result.stdout ? JSON.parse(result.stdout.trim()) : null;
  } catch {
    emit('warn', 'worker_bad_stdout', { worker: scriptName, stdout: result.stdout });
  }

  emit('info', 'rpa_worker_done', {
    script: scriptName,
    exit: result.exitCode,
    ok: parsed?.ok ?? false,
  });

  if (result.failed && !parsed) {
    return {
      ok: false,
      error: 'worker_failed',
      message: result.shortMessage || 'Worker nie uruchomil sie.',
      exit: result.exitCode,
    };
  }

  return parsed ?? { ok: false, error: 'no_output', exit: result.exitCode };
}
