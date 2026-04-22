# Przepływ danych

## Kierunek: panel / Gemini → notatka → Cursor

1. **Wejście tekstu** — komenda `ZAPISZ`, odpowiedź Gemini lub treść z pola „body”.
2. **Zapis** — pliki `.md` w [`gema0/storage/notes/`](../gema0/) przez `writeNoteVersioned` (wersjonowanie `_v2`, `_v3` zamiast nadpisywania).
3. **Kolejka** — zadanie PUSH nie może się ścigać z innym RPA na tej samej maszynie; [`TaskQueue`](../gema0/backend/services/queue.js) realizuje to sekwencyjnie.
4. **Worker Python** — [`push_to_cursor.py`](../gema0/rpa_workers/push_to_cursor.py): aktywacja okna, `Ctrl+L`, schowek, opcjonalnie sekwencja `@nazwa.md`, `Ctrl+Enter`.
5. **Cursor** — interpretacja `@` zależy od workspace; plik musi być osiągalny w kontekście projektu otwartego w Cursorze.

## Kierunek zwrotny: Cursor → panel (wizja docelowa)

**Stan obecny:** brak niezawodnego odczytu odpowiedzi z UI Cursor przez API.

**Opcje do rozstrzygnięcia w tej wizji:**

| Metoda | Zalety | Wady |
|--------|--------|------|
| Użytkownik kopiuje odpowiedź → schowek → [`clipboard_watcher.py`](../gema0/rpa_workers/clipboard_watcher.py) | Worker + [`clipboardBridge.js`](../gema0/backend/services/clipboardBridge.js) (start przy starcie panelu, **opcjonalnie** w `config.json`) | Wymaga `clipboard_watcher.enabled: true`; domyślnie wyłączone (świadomy opt-in). |
| Zapis zmian w repo (git) jako „odpowiedź” | Obiektywne | To nie jest „tekst czatu” |
| Przyszły parser ekranu / OCR | Automat | Kruchy, kosztowny |

## Do uzupełnienia

- [ ] Docelowy format „jednej sesji” (session_id, powiązanie Gemini ↔ Cursor).
- [ ] Czy notatki wyjściowe z Cursor mają mieć szablon nazwy (np. `cursor_reply_<ts>.md`).

Powiązane: [03_panel_ui_historia.md](03_panel_ui_historia.md), [05_mapowanie_na_gema0.md](05_mapowanie_na_gema0.md).
