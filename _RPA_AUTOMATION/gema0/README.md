# GEMA-0 Command Center

Lokalne centrum dowodzenia dla automatyzacji Cursor + Gemini.
Wszystko zyje w sandboxie `/_RPA_AUTOMATION/gema0/`.

**Proces pracy (wizja / plan / kod):** najpierw [`../START_TUTAJ_CURSOR.md`](../START_TUTAJ_CURSOR.md), przy zmianach w kodzie także [`../PROCES_ZMIANY_I_RAPORT.md`](../PROCES_ZMIANY_I_RAPORT.md) oraz aktualizacja [`../wizja/05_mapowanie_na_gema0.md`](../wizja/05_mapowanie_na_gema0.md) gdy zmienia się zachowanie widoczne na zewnątrz.

## Co to jest

Panel webowy uruchamiany lokalnie, ktory:

- rozmawia z Google Gemini przez oficjalne SDK,
- steruje oknami Cursor za pomoca skryptow Python (pywinauto + pyperclip + rapidfuzz),
- obsluguje komendy chatowe `ZAPISZ ...`, `PUSH TO CURSOR_X ...`, `BROADCAST ...`, `DRY PUSH ...`, `HEALTHCHECK`, `ASK GEMINI ...`,
- loguje wszystko na zywo po WebSocket,
- robi screenshoty pre/post kazdej akcji,
- uczy sie opoznien adaptacyjnie.

## Architektura

```
frontend  (Tailwind, vanilla JS)   <-- WebSocket + REST -->   backend (Node/Express)
                                                                |
                                                                |-- Gemini API (HTTPS)
                                                                '-- Python workers (child_process)
```

Python workery:

- `push_to_cursor.py` - glowna sekwencja PUSH + mention picker
- `focus_window.py`   - fokus i smoke test
- `clipboard_watcher.py` - Cursor -> panel (most wsteczny; polling schowka). Podpinany przez backend gdy `clipboard_watcher.enabled` w `backend/config/config.json` jest `true`.
- `healthcheck.py`    - pre-flight

Wspolny rdzen:

- `common/keyboard_safe.py` - hotkeys + BlockInput
- `common/clipboard_safe.py` - atomowy clipboard
- `common/window_resolver.py` - CURSOR_X -> konkretne okno (fuzzy match)
- `common/delays.py` - adaptacyjne opoznienia
- `common/panic.py` - globalny panic-stop (Ctrl+Alt+F12)
- `common/screenshot.py` - audit trail
- `common/logger.py` - JSONL -> Node -> WebSocket

## Wymagania

- Windows 10 / 11
- Node.js 18+
- Python 3.11+
- Cursor zainstalowany i uruchomiony
- Klucz Google Gemini (opcjonalny, ale mocno zalecany)

## Instalacja

```powershell
cd C:\xampp\htdocs\slicehub\_RPA_AUTOMATION\gema0
powershell -File tools\install.ps1
```

Uzupelnij klucz w `.env`:

```
GEMINI_API_KEY=twoj_klucz
```

## Uruchomienie

```powershell
cd C:\xampp\htdocs\slicehub\_RPA_AUTOMATION\gema0
powershell -File tools\start.ps1 -Open
```

Panel: `http://127.0.0.1:7878`

## Komendy panelu

Pole komend na dole obsluguje jezyk domenowy:

| Komenda | Dzialanie |
|---|---|
| `ZAPISZ raport.md` | Zapisuje tresc z pola body jako notatke z wersjonowaniem (`raport.md`, `raport_v2.md`, ...). |
| `PUSH TO CURSOR_1 @raport.md zrob kod` | Wysyla prompt do Cursora nr 1 z natywnym mention. |
| `BROADCAST @raport.md cos tam` | Wysyla do wszystkich zmapowanych Cursorow. |
| `DRY PUSH CURSOR_2 @raport.md ...` | Symulacja, bez klawiatury. |
| `HEALTHCHECK` | Sprawdzenie srodowiska + mapowania okien. |
| `ASK GEMINI <pytanie>` | Wysyla do Gemini, odpowiedz ladnie w kolumnie Gemini. |

Przycisk "Gemini -> Cursor" automatycznie:

1. zapisuje ostatnia odpowiedz Gemini jako notatke,
2. pushuje ja do wybranego Cursora przez mention `@`.

## Auto-push notatek (.md)

Profil w [`storage/auto_push.json`](storage/auto_push.json): reguly `glob` (np. `**/*.md`), cel `CURSOR_*` / `CURSOR_ALL`, szablon `extra_instruction` (`{{relpath}}`, `{{file}}`), cooldown, dedupe po hash po udanym pushu. **Globalny** wlacznik w panelu (Audit) lub `enabled` w JSON — bez tego watcher jest zatrzymany. Wymaga `npm install` (zaleznosc `picomatch`). Priorytet kolejki: auto-push (7) vs reczny PUSH (3).

Po instalacji zaleznosci uruchom ponownie panel.

## Cursor → panel (schowek)

Domyslnie **wylaczony** (`clipboard_watcher.enabled: false`). Po wlaczeniu backend uruchamia `clipboard_watcher.py`; zmiana schowka (np. Ctrl+C na odpowiedzi w Cursor) emituje zdarzenie na WebSocket i pokazuje baner w kolumnie Cursor. Opcja `save_to_notes: true` dopisuje pelny tekst jako wersjonowana notatka `cursor_clipboard.md` / `_v2` itd.

## Konfiguracja okien

`backend/config/windows_map.json`:

```json
{
  "CURSOR_1": "slicehub",
  "CURSOR_2": "_RPA_AUTOMATION"
}
```

Wartosc to fragment tytulu okna (workspace). Resolver robi fuzzy matching (`rapidfuzz`).
Etykieta `CURSOR_ACTIVE` zwraca aktualnie aktywne okno Cursor. Etykieta `CURSOR_ALL` broadcastuje.

## Adaptacyjny timing

Worker zapisuje kolejne opoznienia do `storage/timing_cache.json`.
Kazdy sukces zmniejsza delay (floor), kazdy blad zwieksza (ceil).
Po kilku uruchomieniach panel dziala szybciej i stabilniej na danej maszynie.

## Audit trail

- `storage/screenshots/<session>/<ts>_pre.png` i `..._post.png`
- `storage/logs/YYYY-MM-DD.log` (JSONL)
- `storage/notes/` (wszystkie `.md` z panelu)

## Panic stop

Globalny skrot (rejestrowany przez worker): `Ctrl + Alt + F12`.
Dodatkowo: przycisk `Panic` w panelu pauzuje kolejke natychmiast.

## Wazne zasady bezpieczenstwa

1. Sandbox twardy - zadne pliki panelu nie wychodza poza `_RPA_AUTOMATION/`.
2. Nazwy notatek sanityzowane (whitelisting znakow, zakaz `..`, `/`, `\`).
3. Clipboard zawsze przywracany po operacji.
4. BlockInput wlaczany na czas krytycznej sekwencji (jesli uprawnienia).
5. Focus weryfikowany pred kazdym krytycznym krokiem.

## Problemy i rozwiazania

- **Cursor nie reaguje na mention `@`** -> sprawdz hotkey `Ctrl+L` w Cursor, moze byc nadpisany.
- **Python nie wykryty** -> sprawdz `rpa_workers/venv/Scripts/python.exe`, reinstaluj `tools\install.ps1 -Recreate`.
- **Gemini blad 429** -> `rate_limit_per_minute` w `config.json`.
- **Zly Cursor dostaje prompt** -> popraw `windows_map.json`, mozesz testowac `HEALTHCHECK`.

## Co jest w etapach nastepnych

- OCR odpowiedzi z Cursor (pytesseract)
- Tryb Loop (Gemini <-> Cursor z limitami)
- Profile promptow (szablony z `{{zmiennymi}}`)
- Eksport transkrypcji do `.md`

## Struktura katalogu

```
gema0/
  backend/
    server.js
    routes/ (chat.js, sessions.js)
    services/ (commandParser, noteWriter, rpaDispatcher, queue, geminiClient, logger, config)
    utils/sanitize.js
    config/ (config.json, windows_map.json)
  frontend/ (index.html, js/, css/)
  rpa_workers/
    push_to_cursor.py
    focus_window.py
    clipboard_watcher.py
    healthcheck.py
    common/ (logger, errors, panic, clipboard_safe, keyboard_safe, window_resolver, delays, screenshot)
    requirements.txt
    venv/ (tworzony przez install.ps1)
  storage/
    notes/ logs/ screenshots/ sessions/ profiles/ timing_cache.json
  tools/ (install.ps1, start.ps1, stop.ps1)
  package.json
  .env / .env.example
  README.md
```
