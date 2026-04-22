# Ryzyka i założenia techniczne

## Założenia

- **Windows** jako platforma docelowa dla RPA (workers Python w [`../gema0/rpa_workers/`](../gema0/rpa_workers/)).
- **Cursor** nie udostępnia publicznego API czatu dla zewnętrznego procesu — wizja zakłada **inject klawiatury / schowka** jako kanał główny.
- **Node** orkiestruje kolejkę pojedynczych zadań — równoległe RPA na jednej sesji są celowo wykluczone ([`queue.js`](../gema0/backend/services/queue.js)).

## Ryzyka

| Ryzyko | Mitygacja (istniejąca lub planowana) |
|--------|--------------------------------------|
| Utrata focusu okna | Weryfikacja HWND; retry; opcjonalnie `preflight_esc` ([`chat.js`](../gema0/backend/routes/chat.js)) |
| Zmiana skrótów w Cursorze | Dokumentacja w config; healthcheck |
| Konflikt schowka | `clipboard_safe.py` + backup |
| Duże prompty | Limity czatu Cursor; preferencja `@plik.md` zamiast wklejania całości |
| „Odpowiedź” Cursor do pliku | Brak API — wymaga osobnej strategii ([wizja/02](../wizja/02_przeplyw_danych.md)) |

## Do uzupełnienia

- [ ] Akceptowalny poziom awarii RPA (metryki / log).
- [ ] Czy planować fallback AutoHotkey dla krytycznych wdrożeń ([`../cursor_chat_sender.ahk`](../cursor_chat_sender.ahk)).

Powiązane: [fazy/01_faza_fundament.md](fazy/01_faza_fundament.md).
