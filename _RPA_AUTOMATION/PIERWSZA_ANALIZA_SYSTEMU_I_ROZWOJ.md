# Pierwsza analiza systemu RPA i możliwości rozwoju

Dokument zbiera ustalenia z rozmowy (analiza kodu w `_RPA_AUTOMATION/`, kierunki rozwoju, odczyt plików w czatach, koszt utrzymania). Zakres: **wyłącznie** sandbox automatyzacji, bez oceny reszty repozytorium.

---

## 1. Kontekst

Ustalono tryb pracy agenta skupionego na folderze `_RPA_AUTOMATION/` (automatyzacja UI i skrypty systemowe). Poniższa analiza opiera się na przeglądzie plików w tym katalogu.

---

## 2. Co jest w sandboxie — dwa poziomy

### 2.1 PoC: `run_cursor_prompt.ps1` + `cursor_chat_sender.ahk`

- **Cel:** wysłanie promptu do okna Cursor na Windows bez natywnego API Cursor.
- **Przepływ:** PowerShell znajduje AutoHotkey v2 → uruchamia skrypt z argumentem (ścieżka do pliku lub tekst inline), opcjonalnie `--no-send`.
- **AHK:** `WinActivate` na `Cursor.exe` → `Ctrl+L` → schowek + wklejenie → domyślnie `Enter` → przywrócenie schowka.
- **Warunki działania:** AHK v2, Cursor otwarty, odblokowany pulpit, brak przejęcia focusu; skróty zgodne z konfiguracją Cursor.
- **Ryzyka:** opisane w głównym `README.md` (zmiana skrótów, UIPI, opóźnienia renderu, `@plik` jako literał, konflikt schowka itd.).

### 2.2 GEMA-0 (`gema0/`) — panel + Node + Python

- **Cel:** lokalne „centrum dowodzenia”: panel web (`127.0.0.1:7878`), WebSocket z logami, kolejka RPA, integracja z Gemini, workery Python sterujące oknami Cursor.
- **Przepływ:** `tools/install.ps1` (venv + pip + npm + `.env`) → `tools/start.ps1` → `node backend/server.js` → REST `/api/chat` → parser komend → dla push **kolejka** → `execa` → worker z JSON na stdin → **stdout: jeden JSON wyniku**, **stderr: JSONL** na WS.
- **Komendy (m.in.):** `ZAPISZ`, `PUSH TO CURSOR_X`, `BROADCAST`, `DRY PUSH`, `HEALTHCHECK`, `ASK GEMINI`.
- **Push do Cursor:** `push_to_cursor.py` — fokus okna wg `windows_map.json` (fuzzy), screenshoty pre/post, `Ctrl+L`, wklejenie instrukcji, opcjonalnie sekwencja `@` + nazwa pliku + Enter (picker), wysłanie **`Ctrl+Enter`**.
- **Notatki:** zapis wersjonowany w `storage/notes/` (`noteWriter.js`); odczyt po stronie serwera: `readNote`, endpoint `GET /api/notes/:name`.

---

## 3. Werdykt „czy zadziała”

| Element | Ocena |
|--------|--------|
| PoC AHK + PS1 | Spójny z dokumentacją; działa przy spełnionej checklistie środowiska. |
| GEMA-0 architektura Node ↔ Python | Spójna; po poprawnym `install` i mapowaniu okien — **powinna działać**. |
| Push z `@notatka.md` | Backend nie wysyła `note_text`; odniesienie do pliku zależy od **widoczności pliku w workspace Cursora**. Notatki tylko w `gema0/storage/notes` mogą nie być widoczne dla pickera `@`, jeśli Cursor ma otwarty inny katalog. |
| Gemini | Wymaga `GEMINI_API_KEY` w `.env`; rate limit w `config.json`. |

### 3.1 Zauważone luki techniczne (z analizy kodu)

1. **`package.json`:** skrypt `npm run healthcheck` wskazuje na `backend/tools/healthcheck_cli.js` — plik **nie występuje** w drzewie; sam panel nadal może wołać HEALTHCHECK przez API → `healthcheck.py`.
2. **`tools/start.ps1`:** użycie operatora `??` (PowerShell 7+) przy `-Open` — na **Windows PowerShell 5.1** może **wyłożyć błąd składni**; samo `node backend/server.js` bez `-Open` lub uruchomienie z **pwsh** omija problem.
3. **Frontend (`app.js`):** status Gemini oparty o nieistniejące pole — **kosmetyka UX**, nie blokuje API.

---

## 4. Możliwości rozwoju (roadmapa logiczna)

### Już zasygnalizowane w README GEMA-0

- OCR / odczyt odpowiedzi z czatu Cursor.
- Pętla Gemini ↔ Cursor z limitami.
- Szablony promptów z placeholderami + profile w `storage/profiles/`.
- Eksport transkrypcji do `.md`.

### Stabilność RPA (wysoki zwrot)

- Retry z backoffiem przy utracie focusu / błędzie workera.
- Watchdog: weryfikacja aktywnego okna przed krytycznymi krokami.
- Tryby: tylko wklej / bez wysyłki / audit.
- Harmonogram (Task Scheduler) + istniejący tor AHK do prostych promptów.

### Integracja panelu

- Trigger zewnętrzny: `POST /api/chat` bez ręcznego panelu.
- Opcjonalne **wstrzyknięcie treści notatki** do payloadu push (inline), gdy `@` nie jest niezawodne.

### Skala

- Agenci na wielu stacjach + centralny panel (obecnie: wiele okien na jednym hoście przez `windows_map` + broadcast).

### Modele

- Router modeli (Flash / Pro / inny dostawca) przy wspólnym interfejsie wywołania.

---

## 5. Odczyt zapisanych plików „przez czaty” — rekomendacja

### Dwa odbiorcy

- **Gemini (panel):** najlepiej **serwer** czyta wyłącznie z `paths.notes` + sanityzacja + **limit rozmiaru**, potem jeden prompt do API (np. rozszerzenie komendy typu `ASK GEMINI @plik.md …`).
- **Cursor (RPA):** albo **inline** (serwer `readNote` → `note_text` / wklejenie pełnej treści przez worker), albo **`@` + pliki w workspace** — hybryda dla długich dokumentów.

### Zasady utrzymaniowe

- Jedna kanoniczna ścieżka odczytu plików (katalog notatek + `sanitize`).
- Jasny szablon promptu (nagłówki: źródło / treść / pytanie).

---

## 6. Koszt utrzymania — synteza

- **Niski:** jedna bramka backendowa (odczyt notatek + limity + Gemini), prosty kontrakt tekstowy.
- **Średni:** RPA wklejające długie treści (limity, focus, aktualizacje Cursor).
- **Wyższy:** wiele równoległych mechanizmów (AHK + kilka trybów push + OCR bez prostych reguł) oraz pełna automatyzacja odczytu z UI bez telemetrii i limitów.

**Wniosek:** sam wzorzec „plik z dysku → backend → model” jest **relatywnie tani w utrzymaniu**; koszt rośnie przy rozproszeniu ścieżek i głębokiej zależności od niestabilnego UI bez logów i retry.

---

## 7. Następne kroki (opcjonalnie)

1. Naprawa zgodności `start.ps1` z PS 5.1 i/lub dopisanie/usunięcie `healthcheck_cli.js` z `package.json`.
2. Decyzja produktowa: **priorytet Gemini vs Cursor** dla scenariusza „czytaj mój zapisany plik”.
3. Implementacja jednej komendy `ASK GEMINI` z `@nazwa.md` po stronie serwera (minimalny przyrost kodu, przewidywalne zachowanie).

---

*Wygenerowano jako podsumowanie rozmowy; wersja robocza „pierwszej analizy” do dalszej iteracji.*
