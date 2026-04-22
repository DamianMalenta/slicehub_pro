# Faza 2 — panel, historia, zapis odpowiedzi

## Cel

Zbliżyć UI do [wizji panelu i historii](../../wizja/03_panel_ui_historia.md): dwa tory (Gemini / Cursor), trwała historia po stronie serwera, możliwość zapisu „odpowiedzi” do plików tam, gdzie jest to technicznie możliwe.

## Stan wyjściowy

- Frontend: [`../../gema0/frontend/`](../../gema0/frontend/) — stan w przeglądarce częściowo ulotny.
- Backend: brak dedykowanego magazynu sesji czatu (poza notatkami i logami).

## Zadania (szkielet)

- [ ] Model sesji / transcriptu (np. JSONL per `session_id` w `storage/sessions`).
- [ ] Endpointy: zapis wiadomości, lista sesji, pobranie historii.
- [ ] UI: podpięcie pod nowe endpointy; opcjonalne zakładki Gemini / Cursor.
- [x] Strategia minimalna „Cursor → panel” przez schowek (`clipboard_watcher` + WebSocket); pełna historia sesji nadal TODO.

## Zależności wizji

- [../../wizja/03_panel_ui_historia.md](../../wizja/03_panel_ui_historia.md)
- [../../wizja/02_przeplyw_danych.md](../../wizja/02_przeplyw_danych.md)

Powrót: [../README.md](../README.md)
