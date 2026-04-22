import { runWorker } from './rpaDispatcher.js';

/**
 * Wspólna ścieżka kolejki → ten sam worker co przy ręcznym `PUSH TO`.
 * `priority`: niższa liczba = wyższy priorytet (ręczny push = 3, auto = domyślnie 7).
 */
export function enqueuePushToCursor(queue, options) {
  const {
    pythonExe,
    workersDir,
    target_label,
    note_name = '',
    note_text = '',
    extra_instruction = '',
    mention_syntax = true,
    submit = true,
    dry_run = false,
    session_id = 'panel',
    preflight_esc = false,
    timeoutMs = 45_000,
    priority = 7,
    label,
  } = options;

  const payload = {
    target_label,
    note_name: note_name || '',
    note_text: note_text || '',
    extra_instruction: extra_instruction || '',
    mention_syntax: !!mention_syntax,
    submit: !!submit,
    dry_run: !!dry_run,
    session_id,
    preflight_esc: !!preflight_esc,
  };

  const id = queue.enqueue(
    {
      label: label || `push ${target_label}`,
      run: () =>
        runWorker({
          pythonExe,
          workersDir,
          scriptName: 'push_to_cursor.py',
          payload,
          timeoutMs,
        }),
    },
    priority
  );

  return { id, payload };
}
