# Automatyzacja (push bez ręcznego klikania)

## Intencja

Użytkownik wskazuje **reguły**, kiedy wybrany plik `.md` ma być **automatycznie** wysłany do Cursor przez istniejącą kolejkę RPA — bez ponownego wpisywania komendy `PUSH TO ...`.

## Parametry do rozstrzygnięcia (wizja)

| Parametr | Znaczenie |
|----------|-----------|
| Zdarzenie wyzwalające | Zapis / zamknięcie zapisu pliku, prefix nazwy, podkatalog |
| Cel | `CURSOR_1` … lub `CURSOR_ALL` |
| Szablon instrukcji | Stały tekst + ewentualnie nazwa pliku |
| Tryb mention | `@plik.md` vs sam tekst z pliku |
| `preflight_esc` | Czy domyślnie dla tego profilu |
| Dedupe | Nie wysyłać ponownie tej samej wersji (hash / mtime) |
| Cooldown | Minimalny odstęp czasu między pushami |

## Zależności techniczne

- [`chokidar`](https://www.npmjs.com/package/chokidar) + dopasowanie glob (`picomatch`) są w [`gema0/package.json`](../gema0/package.json); watcher jest podłączony w backendzie (`autoPush.js`), profil reguł: [`../gema0/storage/auto_push.json`](../gema0/storage/auto_push.json).

## Do uzupełnienia

- [ ] Czy automatyzacja obejmuje tylko pliki z `storage/notes`, czy także zewnętrzny folder ( symlink / ścieżka poza gema0 — ryzyko)?

Powiązane: [02_przeplyw_danych.md](02_przeplyw_danych.md), [05_mapowanie_na_gema0.md](05_mapowanie_na_gema0.md).
