import fs from 'node:fs';
import path from 'node:path';
import { sanitizeNoteName } from '../utils/sanitize.js';

/**
 * Zapisuje notatke .md z wersjonowaniem.
 * Jesli plik 'raport.md' istnieje, tworzy 'raport_v2.md', 'raport_v3.md', ...
 * Nigdy nie nadpisuje istniejacego pliku.
 */
export function writeNoteVersioned(notesDir, rawName, body) {
  const safeName = sanitizeNoteName(rawName);
  fs.mkdirSync(notesDir, { recursive: true });

  const parsed = path.parse(safeName);
  const base = parsed.name;
  const ext = parsed.ext || '.md';

  let candidate = path.join(notesDir, safeName);
  let version = 1;
  while (fs.existsSync(candidate)) {
    version += 1;
    candidate = path.join(notesDir, `${base}_v${version}${ext}`);
  }

  const finalName = path.basename(candidate);
  const finalBody = (body ?? '').toString();
  fs.writeFileSync(candidate, finalBody, 'utf8');

  return {
    name: finalName,
    path: candidate,
    version,
    bytes: Buffer.byteLength(finalBody, 'utf8'),
  };
}

export function listNotes(notesDir) {
  if (!fs.existsSync(notesDir)) return [];
  return fs
    .readdirSync(notesDir, { withFileTypes: true })
    .filter((e) => e.isFile() && e.name.toLowerCase().endsWith('.md'))
    .map((e) => {
      const p = path.join(notesDir, e.name);
      const st = fs.statSync(p);
      return { name: e.name, bytes: st.size, modified: st.mtimeMs };
    })
    .sort((a, b) => b.modified - a.modified);
}

export function readNote(notesDir, rawName) {
  const safeName = sanitizeNoteName(rawName);
  const p = path.join(notesDir, safeName);
  if (!fs.existsSync(p)) throw new Error(`Notatka nie istnieje: ${safeName}`);
  return { name: safeName, body: fs.readFileSync(p, 'utf8') };
}
