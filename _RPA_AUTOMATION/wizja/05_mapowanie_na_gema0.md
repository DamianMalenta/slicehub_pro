# Mapowanie wizji na stan kodu GEMA-0

Skrót do kodu w [`../gema0/`](../gema0/README.md). Ten dokument ma być aktualizowany przy każdej większej zmianie implementacji.

## Już zaimplementowane (punkt odniesienia)

| Obszar | Plik / moduł |
|--------|----------------|
| API komend, push, notatki, Gemini | [`gema0/backend/routes/chat.js`](../gema0/backend/routes/chat.js) |
| Payload push: `preflight_esc`, kolejka | Ten sam + [`queue.js`](../gema0/backend/services/queue.js) |
| Parser komend tekstowych | [`commandParser.js`](../gema0/backend/services/commandParser.js) |
| Uruchamianie workerów Python | [`rpaDispatcher.js`](../gema0/backend/services/rpaDispatcher.js) |
| RPA: focus HWND, schowek, Ctrl+L, Ctrl+Enter | [`push_to_cursor.py`](../gema0/rpa_workers/push_to_cursor.py) |
| Mapowanie okien | [`windows_map.json`](../gema0/backend/config/windows_map.json) + [`window_resolver.py`](../gema0/rpa_workers/common/window_resolver.py) |
| Panel statyczny + WebSocket logów | [`gema0/frontend/`](../gema0/frontend/), [`server.js`](../gema0/backend/server.js) |
| Cursor → panel (schowek), worker długożyjący | [`clipboard_watcher.py`](../gema0/rpa_workers/clipboard_watcher.py) + [`clipboardBridge.js`](../gema0/backend/services/clipboardBridge.js), włączane w [`config.json`](../gema0/backend/config/config.json) (`clipboard_watcher`) |
| Auto-push `.md` (glob, dedupe hash, cooldown) | [`autoPush.js`](../gema0/backend/services/autoPush.js) + [`storage/auto_push.json`](../gema0/storage/auto_push.json), ten sam enqueue co [`pushTask.js`](../gema0/backend/services/pushTask.js) |

## Luki względem wizji (do wpisania po uzgodnieniu)

- [ ] Trwała historia konwersacji w backendzie (nie tylko stan w przeglądarce).
- [x] Automatyczny push po zmianie `.md` (`chokidar`) + hook po `ZAPISZ` (opcja `push_after_save_note`); panel: przełącznik `PATCH /api/auto_push`.
- [ ] Pełny odczyt „odpowiedzi czatu” Cursor bez udziału użytkownika (OCR / parser UI) — nadal poza zakresem.
- [x] Podgląd tekstu ze schowka po **Ctrl+C** w Cursor → event WebSocket + opcjonalny zapis do `storage/notes` (`clipboard_watcher` w konfiguracji).
- [ ] Pełna konfiguracja z panelu (wszystkie flagi push bez JSON).

## Do uzupełnienia przez właściciela

Po potwierdzeniu któregoś punktu „mamy to w kodzie na stałe” można oznaczyć go w [99_analiza_podsumowanie.md](99_analiza_podsumowanie.md) linijką kończącą się na **KONIEC**.
