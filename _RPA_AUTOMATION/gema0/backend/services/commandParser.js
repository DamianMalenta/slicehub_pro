/**
 * Parser komend czatu panelu.
 * Obsluguje:
 *   ZAPISZ <nazwa>.md
 *   PUSH TO CURSOR_X @<nazwa>.md <instrukcja>
 *   BROADCAST @<nazwa>.md <instrukcja>
 *   DRY PUSH CURSOR_X ...
 *   HEALTHCHECK
 *   ASK GEMINI <tresc>
 *
 * Zwraca obiekt: { type, ...fields }
 */
export function parseCommand(raw, { pendingBody } = {}) {
  if (typeof raw !== 'string') throw new Error('Komenda musi byc stringiem.');
  const input = raw.trim();
  if (!input) throw new Error('Pusta komenda.');

  if (/^HEALTHCHECK\b/i.test(input)) {
    return { type: 'healthcheck' };
  }

  const saveMatch = input.match(/^ZAPISZ\s+(.+?)(?:\.md)?\s*$/i);
  if (saveMatch) {
    return {
      type: 'save_note',
      name: `${saveMatch[1].trim()}.md`,
      body: typeof pendingBody === 'string' ? pendingBody : '',
    };
  }

  const dryMatch = input.match(/^DRY\s+PUSH\s+(CURSOR_[A-Z0-9_]+)\s+(.+)$/i);
  if (dryMatch) {
    return {
      type: 'push',
      dry_run: true,
      target: dryMatch[1].toUpperCase(),
      ...extractMentionAndInstruction(dryMatch[2]),
    };
  }

  const pushMatch = input.match(/^PUSH\s+TO\s+(CURSOR_[A-Z0-9_]+)\s+(.+)$/i);
  if (pushMatch) {
    return {
      type: 'push',
      dry_run: false,
      target: pushMatch[1].toUpperCase(),
      ...extractMentionAndInstruction(pushMatch[2]),
    };
  }

  const broadcastMatch = input.match(/^BROADCAST\s+(.+)$/i);
  if (broadcastMatch) {
    return {
      type: 'push',
      dry_run: false,
      target: 'CURSOR_ALL',
      ...extractMentionAndInstruction(broadcastMatch[1]),
    };
  }

  const askMatch = input.match(/^ASK\s+GEMINI\s+(.+)$/i);
  if (askMatch) {
    return { type: 'gemini_ask', prompt: askMatch[1].trim() };
  }

  return { type: 'chat_text', text: input };
}

function extractMentionAndInstruction(tail) {
  const trimmed = tail.trim();
  const m = trimmed.match(/^@([A-Za-z0-9._\-]+?\.md)\s*(.*)$/);
  if (m) {
    return {
      note_name: m[1],
      extra_instruction: m[2].trim(),
      mention_syntax: true,
    };
  }
  return {
    note_name: '',
    extra_instruction: trimmed,
    mention_syntax: false,
  };
}
