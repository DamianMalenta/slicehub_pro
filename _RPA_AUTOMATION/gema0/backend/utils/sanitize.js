const SAFE_NAME = /^[a-zA-Z0-9._\- ()\[\]]+$/;

export function sanitizeNoteName(rawName) {
  if (typeof rawName !== 'string') {
    throw new Error('Nazwa notatki musi byc stringiem.');
  }
  const trimmed = rawName.trim();
  if (!trimmed) throw new Error('Nazwa notatki jest pusta.');
  if (trimmed.includes('..') || trimmed.includes('/') || trimmed.includes('\\')) {
    throw new Error('Nazwa notatki nie moze zawierac sciezek.');
  }
  const base = trimmed.toLowerCase().endsWith('.md') ? trimmed : `${trimmed}.md`;
  if (!SAFE_NAME.test(base)) {
    throw new Error('Dozwolone sa tylko litery, cyfry, spacje, oraz . _ - ( ) [ ]');
  }
  return base;
}

export function sanitizeLabel(raw) {
  if (typeof raw !== 'string') throw new Error('Etykieta musi byc stringiem.');
  const up = raw.trim().toUpperCase();
  if (!/^CURSOR_[A-Z0-9_]+$/.test(up)) {
    throw new Error(`Nieprawidlowa etykieta: ${raw}`);
  }
  return up;
}
