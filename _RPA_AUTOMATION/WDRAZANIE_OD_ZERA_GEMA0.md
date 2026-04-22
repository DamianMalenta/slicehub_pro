# Wdrożenie GEMA-0 od zera — instrukcja krok po kroku (Twoja strona)

Ten dokument opisuje **wyłącznie to, co musisz zrobić Ty na swoim komputerze**, żeby **GEMA-0 Command Center** (`_RPA_AUTOMATION/gema0/`) działał po raz pierwszy. Zakładamy: **Windows**, **repo już jest na dysku** (np. sklonowane lub skopiowane).

Powiązane źródła (nie są wymagane do pierwszego uruchomienia, ale warto):

- Kontekst procesu pracy AI/cursor: [`START_TUTAJ_CURSOR.md`](START_TUTAJ_CURSOR.md)
- Szczegóły panelu i komend: [`gema0/README.md`](gema0/README.md)

---

## Krok 0 — sprawdź wymagania

| Element | Minimum | Uwagi |
|--------|---------|--------|
| System | Windows 10 / 11 | RPA opiera się o okna Win32 |
| Node.js | wersja **18+** | Sprawdź: `node -v` w PowerShell |
| npm | razem z Node | Sprawdź: `npm -v` |
| Python | **3.11+** | Sprawdź: `python --version` |
| Cursor | zainstalowany | Do pushy musisz mieć **otwarte okno Cursora** |
| Gemini (opcjonalnie) | klucz API | Bez klucza panel działa, ale **ASK GEMINI** nie |

**Jeśli `node` lub `npm` nie działają:** zainstaluj Node.js LTS z oficjalnej strony Microsoft/OpenJS i **otwórz nowy** PowerShell.

---

## Krok 1 — przejdź do folderu programu

W PowerShell ustaw ścieżkę do projektu (dostosuj, jeśli Twój katalog jest inny):

```powershell
cd C:\xampp\htdocs\slicehub\_RPA_AUTOMATION\gema0
```

Od tego momentu pracujesz **tylko wewnątrz `gema0/`**, chyba że instrukcja mówi inaczej.

---

## Krok 2 — zależności Node (`npm install`)

```powershell
npm install
```

To pobiera Express, WebSocket, `chokidar`, `picomatch` itd. **Musisz to zrobić raz** (i ponownie po zmianach w `package.json`).

---

## Krok 3 — środowisko Python dla workerów RPA

Uruchom skrypt instalacyjny z katalogu `gema0`:

```powershell
powershell -File tools\install.ps1
```

Co robi (w skrócie): tworzy `rpa_workers/venv`, instaluje pakiety z `rpa_workers/requirements.txt` (pywinauto, pyperclip, rapidfuzz itd.).

Jeśli coś padnie, spróbuj ponownie z przebudową venv (jeśli skrypt to umożliwa — patrz komunikat; w README jest też wzmianka o `-Recreate` w rodzinie tego installera).

---

## Krok 4 — plik `.env` (Gemini i opcjonalnie Python)

W folderze `gema0` utwórz plik **`.env`** na bazie przykładu:

```powershell
copy .env.example .env
```

Otwórz `.env` w edytorze i ustaw przynajmniej:

```env
GEMINI_API_KEY=twoj_klucz_z_google_ai_studio
```

Opcjonalnie, jeśli `python` nie jest w PATH:

```env
PYTHON_EXE=C:\sciezka\do\python.exe
```

**Nie commituj** `.env` — jest w `.gitignore`.

---

## Krok 5 — mapowanie okien Cursor (`windows_map.json`)

Otwórz plik:

`gema0/backend/config/windows_map.json`

Wpisz **fragment tytułu okna** Cursora dla danego workspace (np. nazwa folderu projektu). Przykład:

```json
{
  "CURSOR_1": "slicehub",
  "CURSOR_2": "_RPA_AUTOMATION"
}
```

**Musisz mieć uruchomiony Cursor** z odpowiednim projektem, żeby tytuł okna dało się dopasować.

---

## Krok 6 — uruchom panel GEMA-0

Upewnij się, że Cursor (docelowy) jest otwarty.

```powershell
cd C:\xampp\htdocs\slicehub\_RPA_AUTOMATION\gema0
powershell -File tools\start.ps1 -Open
```

Oczekiwany efekt:

- w konsoli komunikat z adresem panelu,
- przeglądarka z **`http://127.0.0.1:7878`** (jeśli użyłeś `-Open`).

Zatrzymanie: zamknij okno konsoli lub użyj skryptu `tools\stop.ps1` jeśli go używasz (patrz README w `gema0`).

---

## Krok 7 — pierwszy test (obowiązkowy)

W panelu (dolne pole poleceń lub przyciski):

1. Kliknij **Healthcheck**  
   — sprawdza Pythona, biblioteki, widoczność procesów Cursor itd.

2. Wykonaj **DRY PUSH** (np. z szybkiego przycisku)  
   — symulacja bez klawiatury; pozwala sprawdzić mapowanie bez ryzyka.

3. Dopiero potem spróbuj prawdziwego pusha, np. po utworzeniu notatki:

   - `ZAPISZ test.md` (treść w polu „body” jeśli używasz API/czatu — w UI panelu zwykle wpisujesz komendę i treść wg README),
   - potem `PUSH TO CURSOR_1 @test.md twoje polecenie`

Jeśli Cursor nie reaguje: przeczytaj sekcję „Problemy” w [`gema0/README.md`](gema0/README.md) (hotkey Ctrl+L, mapowanie okien).

---

## Krok 8 — funkcje opcjonalne (po tym, jak działa Core)

### 8a. Auto-push plików `.md`

1. Upewnij się, że **`npm install`** było wykonane (Krok 2).
2. Edytuj `gema0/storage/auto_push.json`: ustaw **`enabled": true`** na poziomie głównym **oraz** włącz wybraną **regułę** (`"enabled": true` przy regule).
3. Alternatywnie w panelu zaznacz checkbox **Auto-push** (kolumna Audit) — zapisuje ten sam przełącznik do pliku.

Szczegóły: [`gema0/README.md`](gema0/README.md) sekcja „Auto-push”.

### 8b. Schowek Cursor → panel

W `gema0/backend/config/config.json` ustaw:

```json
"clipboard_watcher": { "enabled": true, "save_to_notes": false }
```

Restart panelu. **Świadomie** — każdy większy Ctrl+C może trafić na WebSocket.

### 8c. „Gemini → Cursor”

Wymaga działającego **`GEMINI_API_KEY`** w `.env`. Przycisk w UI zapisuje odpowiedź i pushuje — opis w README `gema0`.

---

## Typowe problemy (skrót)

| Objaw | Co sprawdzić |
|--------|----------------|
| `npm` nie znaleziony | Instalacja Node.js, nowy PowerShell |
| Python worker nie startuje | `tools\install.ps1`, ścieżka `PYTHON_EXE` |
| Zły Cursor dostaje push | `windows_map.json`, uruchomiony właściwy workspace |
| Gemini błąd / brak odpowiedzi | `.env`, limit rate w `backend/config/config.json` |
| Port zajęty | zmień `PORT` w `.env` lub `config.json` |

---

## Co dalej (nie jest wymagane „day one”)

- Wizja produktu: [`wizja/README.md`](wizja/README.md)
- Plan faz: [`plan/README.md`](plan/README.md)
- Pierwsza wiadomość do zespołu w nowym oknie Cursor: [`PIERWSZA_WIADOMOSC_WDRAZANIE.md`](PIERWSZA_WIADOMOSC_WDRAZANIE.md)

---

## Skrót ścieżki (checklista)

- [ ] Node 18+ i `npm` działają w PowerShell  
- [ ] `cd ...\gema0` → `npm install`  
- [ ] `powershell -File tools\install.ps1`  
- [ ] `.env` z `GEMINI_API_KEY` (jeśli chcesz Gemini)  
- [ ] `backend/config/windows_map.json` dopasowany do tytułów okien  
- [ ] Cursor otwarty  
- [ ] `powershell -File tools\start.ps1 -Open`  
- [ ] Panel → **Healthcheck** → **DRY PUSH** → pierwszy realny **PUSH**  

**Koniec checklisty pierwszego wdrożenia.**
