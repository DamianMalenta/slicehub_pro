# Panel UI i historia konwersacji

## Wizja docelowa

- **Dwa tory** — osobna przestrzeń „Gemini” i „Cursor / RPA” z jasnym rozróżnieniem (nie tylko dwie kolumny bez semantyki).
- **Historia trwała** — po odświeżeniu przeglądarki widać ostatnią sesję lub wybraną sesję z listy (wymaga backendu: magazyn transcriptów; **obecnie UI trzyma część stanu tylko w pamięci przeglądarki**).
- **Zapis odpowiedzi do plików** — jednym przyciskiem lub regułą: zrzut ostatniej odpowiedzi Gemini do `storage/notes/` (częściowo jest przez flow „Gemini → Cursor”); analog dla Cursor — do zdefiniowania technicznie (patrz [02_przeplyw_danych.md](02_przeplyw_danych.md)).

## Ustawienia sterowane z UI (wizja)

- Cel `CURSOR_X`, tryb dry-run, `preflight_esc`, ewentualnie wyłączenie finalnego submit (`Ctrl+Enter`) — bez edycji JSON ręcznie.
- Edycja mapy okien lub link do API [`/api/windows_map`](../gema0/backend/routes/sessions.js).

## Do uzupełnienia

- [ ] Priorytet: najpierw historia Gemini, najpierw Cursor, czy jedna oś czasu?
- [ ] Czy potrzebny eksport całej sesji do jednego `.md` archiwum.

Powiązane: [04_automatyzacja.md](04_automatyzacja.md).
