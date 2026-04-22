# Cursor Chat Automation PoC

## Dokumentacja strategiczna (wizja i plan wdrożenia)

- **Zacznij tutaj (Cursor / AI):** **[START_TUTAJ_CURSOR.md](START_TUTAJ_CURSOR.md)** — pierwszy plik: role, kolejność lektury, żelazne zasady.
- **[PROCES_ZMIANY_I_RAPORT.md](PROCES_ZMIANY_I_RAPORT.md)** — *które foldery edytować przy danej zmianie* + szablon krótkiego raportu końca sesji (żeby nie rozjeżdżać wizji, planu i kodu).
- **[wizja/](wizja/README.md)** — docelowe zachowanie systemu GEMA-0 / RPA (granice, przepływy, UI, automatyzacja); wiele powiązanych plików markdown.
- **[plan/](plan/README.md)** — plan implementacji wyprowadzony z katalogu `wizja/` (fazy, ryzyka, roadmapa).

Szczegóły instalacji i uruchomienia Command Center leżą w **[gema0/README.md](gema0/README.md)**.

---

Ten sandbox zawiera Proof of Concept dla bezobslugowego wysylania promptu do okna czatu AI w aplikacji Cursor na Windows, bez natywnego API Cursor.

## Problem techniczny

Cursor nie udostepnia oficjalnego API do sterowania wewnetrznym czatem z poziomu zewnetrznego procesu. Jedyna praktyczna sciezka automatyzacji to warstwa systemowa:

- aktywacja okna aplikacji,
- wywolanie skrotu otwierajacego nowy czat,
- wstrzykniecie promptu,
- wyslanie wiadomosci.

To oznacza, ze automatyzacja jest zalezna od focusu, czasu renderowania UI i stabilnosci skrotow klawiszowych.

## Dwa najbardziej stabilne podejscia

### 1. Rekomendowane: AutoHotkey v2 + PowerShell wrapper

Dlaczego to jest najlepsza opcja na Windows:

- bezposrednia integracja z aktywacja okien (`WinActivate`, `WinWaitActive`),
- bardzo dobra obsluga symulacji klawiatury i schowka,
- mala liczba zaleznosci i szybkie wdrozenie,
- dobra wspolpraca z Task Scheduler i skryptami `.ps1`,
- niski koszt utrzymania PoC.

Model dzialania:

1. Znajdz okno `Cursor.exe`.
2. Ustaw je na pierwszym planie.
3. Wyslij `Ctrl+L`.
4. Wklej caly prompt przez schowek.
5. Odczekaj krotki bufor czasowy.
6. Wyslij `Enter`.

### 2. Alternatywa: Python + `pywinauto` + schowek + fallback klawiaturowy

To podejscie jest dobre, gdy potrzebujesz:

- lepszego logowania zdarzen,
- integracji z wiekszym pipeline RPA,
- przyszlego rozszerzenia o retry, screenshoty, watchdogi i telemetrie.

Jednoczesnie jest zwykle mniej odporne dla aplikacji Electron niz AHK, bo:

- drzewo UIA w Electronie bywa niespojne,
- pole inputu czatu nie zawsze daje sie stabilnie wskazac po kontrolkach,
- i tak czesto konczy sie na fallbacku typu clipboard + hotkeys.

## Najwazniejsze ryzyka

1. Utrata focusu okna.
   Jesli w chwili wysylki aktywne bedzie inne okno, prompt trafi w zle miejsce.

2. Zmieniony skrot w Cursor.
   Po aktualizacji aplikacji `Ctrl+L` moze otwierac inny widok lub byc nadpisany.

3. Opoznienia renderowania UI.
   Cursor moze potrzebowac wiecej czasu na otwarcie nowego czatu niz ustawiony delay.

4. Interakcja uzytkownika z myszka lub klawiatura.
   Ruch uzytkownika w trakcie automatyzacji moze zaklocic sekwencje.

5. Problem z rozpoznaniem odwolan `@plik`.
   Cursor moze traktowac `@nazwa_pliku` jako zwykly tekst albo oczekiwac recznego potwierdzenia z listy podpowiedzi. Ten PoC wysyla tekst literalnie. Jesli w danym workspace potrzebne jest twarde potwierdzenie podpowiedzi, trzeba dodac dodatkowy krok wyboru z autocomplete.

6. Uprawnienia i UIPI.
   Jesli Cursor zostanie uruchomiony z wyzszymi uprawnieniami niz skrypt, symulacja wejscia moze nie zadzialac.

7. Sesja zablokowana lub zminimalizowana.
   To nie jest prawdziwa automatyzacja headless. Ekran musi byc odblokowany, a sesja desktopowa aktywna.

8. Konflikty ze schowkiem.
   Inny proces moze zmienic clipboard miedzy kopiowaniem i wklejeniem.

## Rekomendowana architektura wdrozenia

Na Windows najbardziej przewidywalny jest nastepujacy lancuch:

1. Task Scheduler lub reczny trigger.
2. `PowerShell` uruchamia wrapper `run_cursor_prompt.ps1`.
3. Wrapper wywoluje `AutoHotkey v2`.
4. `AutoHotkey` aktywuje Cursor i wysyla prompt.

To daje prosty podzial odpowiedzialnosci:

- `PowerShell` do orkiestracji,
- `AutoHotkey` do sterowania GUI.

## Zawartosc sandboxa

- `cursor_chat_sender.ahk` - glowny skrypt automatyzacji.
- `run_cursor_prompt.ps1` - wrapper do wygodnego uruchomienia.
- `prompt_example.txt` - przykladowy prompt.

## Jak uruchomic PoC krok po kroku

### Wariant A: najszybszy test reczny

1. Zainstaluj AutoHotkey v2.
2. Uruchom aplikacje Cursor i pozostaw ja zalogowana.
3. Otworz docelowy workspace w Cursor.
4. Upewnij sie, ze ekran jest odblokowany i nic nie przejmie focusu.
5. W PowerShell przejdz do:

```powershell
cd C:\xampp\htdocs\slicehub\_RPA_AUTOMATION
```

6. Uruchom:

```powershell
.\run_cursor_prompt.ps1
```

7. Skrypt:
   - aktywuje okno Cursor,
   - otworzy nowy czat przez `Ctrl+L`,
   - wklei zawartosc `prompt_example.txt`,
   - wysle wiadomosc przez `Enter`.

### Wariant B: wlasny prompt z pliku

1. Przygotuj plik tekstowy UTF-8, np. `C:\temp\cursor_prompt.txt`.
2. Uruchom:

```powershell
cd C:\xampp\htdocs\slicehub\_RPA_AUTOMATION
.\run_cursor_prompt.ps1 -PromptFile C:\temp\cursor_prompt.txt
```

### Wariant C: prompt inline

```powershell
cd C:\xampp\htdocs\slicehub\_RPA_AUTOMATION
.\run_cursor_prompt.ps1 -InlinePrompt "Przeczytaj @01_KONSTYTUCJA.md i przygotuj raport."
```

### Wariant D: test bez wysylki

Ten wariant otwiera chat i wkleja prompt, ale nie wciska `Enter`.

```powershell
cd C:\xampp\htdocs\slicehub\_RPA_AUTOMATION
.\run_cursor_prompt.ps1 -NoSend
```

## Jak zrobic to stabilniej w praktyce

Jesli PoC ma przejsc do trybu produkcyjnego, dodaj:

1. blokade wejscia uzytkownika na czas sekwencji,
2. retry z rosnacymi opoznieniami,
3. log do pliku z timestampem,
4. screenshot po wyslaniu,
5. osobny tryb testowy bez `Enter`,
6. watchdog sprawdzajacy, czy okno Cursor jest faktycznie aktywne.

## Znane ograniczenia tego PoC

- Nie czyta odpowiedzi z czatu.
- Nie potwierdza programowo, czy wiadomosc zostala dostarczona.
- Nie gwarantuje zamiany `@plik` na natywny chip/mention Cursor.
- Zaklada, ze `Ctrl+L` otwiera w Twojej konfiguracji nowy chat input.

## Minimalna checklista przed uruchomieniem

- Cursor jest otwarty.
- Workspace jest zaladowany.
- Sesja Windows jest odblokowana.
- AutoHotkey v2 jest zainstalowane.
- Zaden inny proces nie przejmuje focusu.

## Co zmienic, jesli skrot jest inny

W pliku `cursor_chat_sender.ahk` zmien:

```ahk
openChatHotkey := "^l"
```

Przyklady:

- `^l` = `Ctrl+L`
- `!l` = `Alt+L`
- `^+l` = `Ctrl+Shift+L`

## Kiedy wybrac Python zamiast AHK

Wybierz Pythona, jesli potrzebujesz:

- integracji z wieksza orkiestracja,
- raportowania JSON,
- screenshotow i analizy obrazu,
- wieloetapowych workflow z retry i alertami.

Do samego stabilnego wyslania promptu do Cursor na Windows, AHK jest zwykle prostszy i mniej zawodny.
