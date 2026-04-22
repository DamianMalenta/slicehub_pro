# Pierwsza wersja systemu — GEMA-0 Command Center

**Data:** 2026-04-20  
**Zakres:** wyłącznie katalog `/_RPA_AUTOMATION/` (sandbox). Rdzeń SliceHub nie był modyfikowany.

## Cel

Lokalne centrum dowodzenia do automatyzacji pracy z **wieloma oknami Cursor** (brak natywnego API czatu) oraz integracji z **Google Gemini**. Sterowanie na poziomie OS: fokus okna, skróty klawiszowe, schowek, kolejka zadań, logi na żywo.

## Co zostało zbudowane (wersja 1)

### 1. Legacy PoC (poziom główny `_RPA_AUTOMATION/`)

- `cursor_chat_sender.ahk` — AutoHotkey v2: aktywacja Cursor, `Ctrl+L`, wklejka promptu, `Enter`.
- `run_cursor_prompt.ps1` — wrapper PowerShell (`-PromptFile`, `-InlinePrompt`, `-NoSend`).
- `prompt_example.txt`, `README.md` — dokumentacja tego PoC.

### 2. GEMA-0 Command Center (`/_RPA_AUTOMATION/gema0/`)

Pełniejszy stack zgodny z planem: **HTML + Tailwind + Vanilla JS** (ciemny motyw `bg-[#0a0a0f]`, glassmorphism, akcenty fiolet/cyan), **Node.js Express** jako router i WebSocket, **Python** jako RPA workers wywoływane przez `child_process` (`execa`).

**Backend (`gema0/backend/`):**

- `server.js` — Express, statyczny frontend, WebSocket `/ws`, broadcast logów.
- `routes/chat.js` — `POST /api/chat` (parser komend), `GET /api/notes`, odczyt notatki.
- `routes/sessions.js` — `GET/PUT /api/windows_map`, kolejka `pause/resume`, profile.
- Serwisy: `commandParser.js`, `noteWriter.js` (zapis `.md` z wersjonowaniem `_v2`, `_v3`…), `rpaDispatcher.js`, `queue.js`, `geminiClient.js`, `logger.js`, `config.js`, `sanitize.js`.

**Frontend (`gema0/frontend/`):**

- `index.html` — panel trzykolumnowy (Gemini / Cursor / audit).
- `js/app.js`, `js/ws.js` — REST + WebSocket.
- `css/panel.css` — dopiski stylistyczne.

**Python (`gema0/rpa_workers/`):**

- `push_to_cursor.py` — sekwencja PUSH: fokus, `Ctrl+L`, schowek, **dwuetapowy mention** (`@` → nazwa pliku → Enter w pickerze), `Ctrl+Enter`, screenshot pre/post, adaptacyjne opóźnienia, panic-stop.
- `focus_window.py`, `healthcheck.py`, `clipboard_watcher.py`.
- `common/` — `keyboard_safe` (BlockInput), `clipboard_safe`, `window_resolver` (fuzzy + `CURSOR_ALL` / `CURSOR_ACTIVE`), `delays` (cache timingów), `panic`, `screenshot`, `logger` (JSONL na stderr).

**Konfiguracja:**

- `backend/config/config.json`, `windows_map.json`.
- `.env.example` — m.in. `GEMINI_API_KEY`, `PORT`, `PYTHON_EXE`.

**Narzędzia:**

- `tools/install.ps1`, `start.ps1`, `stop.ps1`.
- `tools/_test_parser.mjs` — testy parsera komend (wszystkie przechodzą).

**Storage:**

- `storage/notes/`, `logs/`, `screenshots/`, `sessions/`, `profiles/`, `timing_cache.json` (generowany przez worker).

## Komendy czatu (wersja 1)

| Komenda | Zachowanie |
|--------|------------|
| `ZAPISZ nazwa` / `ZAPISZ nazwa.md` | Zapis treści z `body` jako plik `.md` w `storage/notes/` z wersjonowaniem. |
| `PUSH TO CURSOR_X @plik.md instrukcja` | Kolejka → worker PUSH do wskazanego okna (mapowanie + fuzzy tytułu). |
| `BROADCAST @plik.md …` | PUSH do wszystkich zmapowanych okien. |
| `DRY PUSH CURSOR_X …` | Symulacja (bez wysyłki klawiaturą w trybie dry). |
| `HEALTHCHECK` | Sprawdzenie środowiska i dopasowania okien. |
| `ASK GEMINI …` | Zapytanie do Gemini (wymaga klucza w `.env`). |

Panel: przycisk **Gemini → Cursor** zapisuje ostatnią odpowiedź Gemini jako notatkę i pushuje ją z `@mention`.

## Najlepsze praktyki wbudowane w v1

- Mapowanie `CURSOR_X` → fragment tytułu okna + **rapidfuzz** (wiele okien tego samego exe).
- **Weryfikacja focusu** przed krytycznymi krokami; retry aktywacji okna.
- **Schowek**: backup → ustawienie → operacja → przywrócenie.
- **BlockInput** na czas sekwencji (jeśli Windows pozwoli — wymaga uprawnień).
- **Panic:** `Ctrl+Alt+F12` + pauza kolejki z panelu.
- **Adaptacyjne opóźnienia** w `storage/timing_cache.json`.
- **Sanityzacja nazw plików** — brak `..`, `/`, `\` w nazwie notatki.

## Uruchomienie (skrót)

```powershell
cd C:\xampp\htdocs\slicehub\_RPA_AUTOMATION\gema0
powershell -File tools\install.ps1
# uzupełnij GEMINI_API_KEY w .env
powershell -File tools\start.ps1 -Open
```

Panel: `http://127.0.0.1:7878` (port z `config.json` / `PORT`).

## Ograniczenia pierwszej wersji

- Brak natywnego potwierdzenia z Cursor, że wiadomość została „przyjęta” — jest screenshot + log.
- Mention `@plik` zależy od zachowania UI Cursor (picker); sekwencja jest zaprojektowana pod typowy flow.
- Pełna stabilność wymaga dopasowania `windows_map.json` do rzeczywistych tytułów okien i ewentualnie strojenia opóźnień na danej maszynie.
- Środowisko: Node + Python venv; na maszynie bez Pythona najpierw `install.ps1`.

## Kolejne sensowne kroki (poza v1)

- OCR odpowiedzi z okna czatu (np. pytesseract).
- Tryb pętli Gemini ↔ Cursor z twardymi limitami iteracji/czasu/tokenów.
- Bogatszy panel ustawień (edycja `windows_map` z UI, profile z placeholderami).

---

*Ten dokument jest podsumowaniem pierwszej wersji systemu automatyzacji w sandboxie SliceHub RPA.*
