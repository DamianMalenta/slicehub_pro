# Faza 3 — automatyczny push plików `.md`

## Cel

Zrealizować [wizja/04_automatyzacja.md](../../wizja/04_automatyzacja.md): po spełnieniu reguł (zapis pliku, glob, cooldown, dedupe) kolejka automatycznie uruchamia ten sam pipeline co ręczny `PUSH TO`.

## Stan wyjściowy

- Zależności `chokidar`, `picomatch` w [`../../gema0/package.json`](../../gema0/package.json); watcher podłączony z [`server.js`](../../gema0/backend/server.js) przez [`autoPush.js`](../../gema0/backend/services/autoPush.js).
- Payload push wspólny z ręcznym push przez [`pushTask.js`](../../gema0/backend/services/pushTask.js).

## Zadania (szkielet)

- [x] Profil [`../../gema0/storage/auto_push.json`](../../gema0/storage/auto_push.json) — ścieżka w [`config.json`](../../gema0/backend/config/config.json) (`paths.auto_push_config`).
- [x] Serwis Node: debounce, dedupe hash + cooldown, enqueue `push_to_cursor` przez kolejkę (priorytet auto niższy niż ręczny).
- [x] Hook po zapisie notatki (`notifyNoteSaved`) — `push_after_save_note` + krótkie okno dedupe względem watchem.
- [x] Panel: checkbox Auto-push → `PATCH /api/auto_push` (pełna edycja reguł nadal w pliku JSON).

## Zależności wizji

- [../../wizja/04_automatyzacja.md](../../wizja/04_automatyzacja.md)

Powrót: [../README.md](../README.md)
