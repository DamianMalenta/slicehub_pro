# PROMPT do nowego okna Cursor Cloud Agent — materiały do wniosku SPARK 3.0 (od zera)

> Skopiuj cały blok poniżej linii `---` i wklej w nowe okno Cloud Agent.
> Agent dostanie pełen kontekst, dostęp do live i listę dokładnie tego co ma wyprodukować.

---

# Zadanie: zrób od zera materiały do wniosku PFR SPARK 3.0 Accelerator

**WAŻNE:** w repo mogą być stare pliki z poprzednich agentów — **zignoruj je całkowicie**. Pracuj od czystej kartki na podstawie tego promptu i prawdziwego stanu produktu na `slicehub.net`. Nie cytuj, nie kopiuj, nie rób refactoru starych dokumentów. Wyprodukuj świeże materiały zgodne z aktualnym wyglądem i działaniem produkcji.

## Kim jestem i co aplikuję

- **Solo founder**: Damian Malenta. Sam projektuję, programuję, wdrażam, sprzedaję. **Brak zespołu, brak współzałożycieli.** Każdy materiał ma to oddawać uczciwie — nie pisz „zespół SliceHub", „zespół założycielski", „my robimy" w pluralis maiestatis. Pisz w pierwszej osobie liczby pojedynczej lub bezosobowo.
- **Nie mam firmy zarejestrowanej.** Aplikuję jako osoba fizyczna z gotowym, działającym produktem.
- **Aplikacja**: PFR SPARK 3.0 Accelerator (https://pfr.pl/), ścieżka **branżowa „go global"** (budżet do **400 000 PLN**).
- **Cel akceleratora**: domknięcie warstwy go-to-market — pilotaże w sieciach gastronomicznych, materiały sprzedażowe, walidacja w CEE, mentor sieciowy.

## Produkt — co realnie jest na slicehub.net

**SliceHub Pro** to wielomodułowa platforma operacyjna dla gastronomii. Hostowana produkcyjnie na shared hostingu, działa w przeglądarce, bez instalacji.

### Stack techniczny

- Frontend: **Vanilla JavaScript (ES6+)** + **Tailwind CSS** + HTML5. Bez Reacta, bez Vue, bez Angulara, **bez Node.js w produkcji** (build-free runtime).
- Backend: **PHP 8** + **PDO** + REST API JSON.
- Baza: **MariaDB / MySQL**.
- PWA z Service Workerami dla POS i Online (offline-first).
- Multi-tenant architecturalnie (jeden bazodanowy schemat, izolacja przez `tenant_id` w każdym zapytaniu).

### Działające moduły (wszystkie sprawdzalne na live)

1. **Hub** (`/modules/hub/`) — punkt startowy z kafelkami modułów, role-based access.
2. **Online** (`/modules/online/`) — storefront B2C dla klienta końcowego (zamiast „płaskiego katalogu") — wejście do marki, sceny wizualne dań, koszyk.
3. **POS** (`/modules/pos/`) — kasa z trzema kanałami (sala / wynos / dostawa), PIN login, koszyk serwerowy, modyfikatory, warianty, combo (Meal Packages), half-and-half pizzy.
4. **Studio Menu** (`/modules/studio/`) — edytor menu z drzewem kategorii, recepturami, modyfikatorami, wariantami iiko-style (`sh_variant_scales` z multiplierami receptur per rozmiar), subrecepturami (półprodukty), wizardem „Nowa Pizza" (5 kroków), drag-and-drop reorderem składników, klonowaniem receptur, temporalną publikacją (`publication_status`, `valid_from/to`).
5. **Courses** (`/modules/courses/`) — dyspozytornia (mapa Leaflet, listy kursów K{n}, przystanków L{n}) + Driver PWA (56px touch targets, safe-area-inset, emergency recall, payment lock — kierowca nie zamknie dostawy bez rozliczenia gotówki/karty).
6. **Tables / Waiter** (`/modules/tables/`, `/modules/waiter/`) — plan sali, stoliki, QR sessions, multi-course pacing.
7. **KDS** (`/modules/kds/`) — wyświetlacz kuchenny.
8. **Magazyn** (`/modules/warehouse/`) — PZ/RW/MM/Inwentaryzacja, AVCO valuation, dokumenty, ruchy, stany realne (`wh_stock`).
9. **KSeF Inbox** (`/modules/procurement/`) — natywne wsparcie polskiego Krajowego Systemu e-Faktur (FA(2) XML parser, AutoScan = self-learning fuzzy matching pozycji faktury dostawcy ze słownikiem surowców, smart-create dokumentów PZ, reverse-PZ).
10. **Kadry / HR** (`/modules/backoffice/hr/`) — pracownicy, kiosk clock z PIN, payroll ledger, advances.
11. **Settings** (`/modules/settings/`) — integracje, webhooks (outbox + retry + DLQ), API keys, audit log, health, GDPR.

### Wyróżniki konkurencyjne (sprawdzalne w kodzie i UI)

1. **Omnichannel pricing jako first-class model** — `sh_price_tiers(target_type, target_sku, channel)` z osobnymi cenami sala/wynos/dostawa. Brak pojęcia „jednej płaskiej ceny" w modelu danych.
2. **Variant scales z multiplierami receptur** — jedna receptura per parent, mała pizza = 1.0×, średnia = 1.5×, duża = 2.0× — zużycie surowca automatycznie skaluje się w `WzEngine`. Brak duplikowania receptur per rozmiar.
3. **Topping size pricing** — modyfikatory (np. dodatki na pizzę) mogą mieć różne ceny zależne od rozmiaru bazy, plus 4 strategie half-and-half pricing (`percentage`, `average`, `higher`, `full`).
4. **Multi-stage recipes (półprodukty)** — receptura może zawierać inną recepturę jako składnik (z `subrecipe_yield`), rekurencyjnie do 3 poziomów. Sos chimichurri jest podrecepturą używaną w 4 daniach.
5. **Combo / Meal Packages** — bundle z fixed lub choice components, ze zniżką, expand do virtual lines przy zamówieniu (każdy komponent osobno deduktowany ze stocku).
6. **KSeF AutoScan** — fuzzy matching pozycji z FA(2) XML faktury ze słownikiem surowców (`sys_items`) — self-learning per tenant. Skraca przyjmowanie dostawy z 15 minut do 30 sekund.
7. **Server-authoritative cart** — `CartEngine::calculate()` przelicza ceny po stronie serwera; klient wysyła wyłącznie SKU + ilości + modifier IDs. Niemożliwe jest „podstawienie" ceny przez frontend.
8. **Zero-reload runtime na shared hostingu** — żaden klient nie musi mieć VPS-a, dockera, Node-a. Działa na hostingu za 30 zł/mies. **Najtańsza warstwa operacyjna w segmencie.**
9. **Multi-tenant SaaS w jednej instalacji** — każda restauracja izolowana w jednym schemacie przez `tenant_id`, oszczędność operacyjna dla franczyz sieciowych.
10. **Reverse stock on cancel** — anulowanie zamówienia automatycznie tworzy KOR (korekta) w magazynie, surowce wracają na stan.

### Dane do live demo (do nagrania wideo i screenshotów)

- **URL**: https://slicehub.net
- **Login backoffice**: `Damian` / `Dammalq123123`
- **PIN POS**: `1111`
- **Tenant_id**: `2`

Po zalogowaniu trafiasz do Huba. Z kafelków otwierasz każdy moduł. Dane demo są wstępnie zaseedowane (kategorie, kilka dań, magazyn).

## Co MASZ wyprodukować — wyłącznie te materiały

### 1. Krótki opis projektu (max 1500 znaków ze spacjami)

Pole formularza SPARK 3.0 „Krótki opis projektu". Polski, pierwsza osoba liczby pojedynczej, konkrety techniczne i biznesowe. Zacznij od problemu (segment + ból), pokaż rozwiązanie (3 wyróżniki najmocniejsze), zakończ celem akceleracji.

Plik: `wniosek/01_krotki_opis_1500.md` (czysty markdown z liczbą znaków u dołu).

### 2. Rozszerzony opis projektu + innowacyjność (3000–5000 znaków)

Pole „Rozszerzony opis projektu oraz czynniki innowacyjności". Polski. Struktura:

- **Problem** (kogo dotyczy, skala, co ludzie robią dziś)
- **Rozwiązanie** (co konkretnie SliceHub robi inaczej)
- **Czynniki innowacyjności** (5–7 punktów technicznych unikatowych dla SliceHub — patrz „Wyróżniki konkurencyjne" wyżej, ale opisz je językiem zrozumiałym dla recenzenta merytorycznego, nie czysto technicznym; pokaż **biznesowy efekt** każdej innowacji — np. „skrócenie czasu przyjęcia dostawy z 15 min do 30 s = 200 PLN miesięcznie oszczędności na pracowniku")
- **Stan obecny** (działający produkt na slicehub.net, X modułów, Y migracji bazy, Z feature'ów)

Plik: `wniosek/02_rozszerzony_opis_innowacje.md`.

### 3. Potencjalni klienci — krajowi i zagraniczni (1500–2500 znaków)

Pole „Potencjalni klienci, krajowi i zagraniczni". Polski. Konkretni adresaci, nie ogólniki:

- **Polska**:
  - **Sieci pizzerii** (Da Grasso, Telepizza PL, Biesiadowo, Pizza Hut Express franczyzy, lokalne 3–10 lokalowe sieci)
  - **Burger / QSR**: Bobby Burger, Pasibus, North Fish (zwłaszcza dynamicznie skalujące się)
  - **Pierogarnie i bistro sieciowe**: Zapiecek, Pyszne.pl-partnerzy
  - **Single-location premium**: bistra z 2–4 kanałami sprzedaży
  - **Cloud kitchens / dark kitchens**
- **Zagranica (CEE)**:
  - **Czechy**: gastronomia korzysta z Dotykačka/STORYOUS — chęć alternatywy
  - **Słowacja**: podobnie do CZ
  - **Rumunia**: rozwijający się rynek z luką po dostawcach POS
  - **Ukraina** (post-war reconstruction): rosnący sektor szybkich franczyz
- **Persony decyzyjne**: operator sieci (5+ lokali), franczyzobiorca pizzerii (1–3 lokale), właściciel cloud kitchen.

Plik: `wniosek/03_klienci_krajowi_zagraniczni.md`.

### 4. Planowane wydatki akceleracji + uzasadnienie (do 400 000 PLN, ścieżka go-global / branżowa)

Pole „Planowane wydatki w ramach akceleracji". Polski. Tabela kosztów + krótkie uzasadnienie każdej pozycji.

Typowe pozycje (dopracuj kwoty pod produkt, sumarycznie ≤ 400 000 PLN):

- Doszlifowanie produktu pod pilotaż sieci (UX, branding, lokalizacja językowa CEE)
- Pilotaże w 3–5 sieciach (subsidies dla pilotów, instalacja, szkolenia)
- Materiały sprzedażowe, demo, pitchdeck, video
- Wyjazdy / udział w eventach (HORECA, FoodTech CEE, FoodService Summit)
- Doradztwo prawne — wejście na rynki czeski/słowacki/rumuński (compliance KSeF-odpowiedniki, fiskalizacja)
- Doradztwo / mentor sieciowy z branży gastronomicznej
- Koszty hostingu / infrastruktury skalowanej dla pilotów

Format: markdown tabela `| Pozycja | Kwota PLN | Uzasadnienie |` + suma + krótkie podsumowanie.

Plik: `wniosek/04_budzet_akceleracji.md`.

### 5. „Why now?" + competitive advantage (1000–1500 znaków, PL)

Pole opcjonalne / „Tell us more". Polski. Dlaczego teraz jest moment na SliceHub:

- Presja kosztów surowca i pracy w gastronomii (food cost real-time)
- KSeF obowiązkowy od 2026 (dla wszystkich), wszystkie istniejące POS-y muszą się dostosować — okazja do podmiany
- Konsolidacja sieci CEE — większe sieci szukają jednolitej warstwy operacyjnej dla franczyzobiorców
- Brak technicznej alternatywy z natywnym KSeF + omnichannel pricing w jednym (Papu ma omnichannel ale brak magazynu/KSeF; LSI ma magazyn ale brak storefrontu)

Plik: `wniosek/05_why_now.md`.

### 6. Wideo demo produktu (60 sekund MP4/WebM)

**Wykonaj przez `computerUse` subagent** na live https://slicehub.net.

Storyboard (60s, 1920×1080):

| Sekundy | Co | Voiceover (sam napisz, polski) |
|---------|-----|-------------------------------|
| 0:00–0:06 | Hub po zalogowaniu — kafelki modułów, scroll | „SliceHub Pro — system operacyjny gastronomii. Wszystko w jednym miejscu." |
| 0:06–0:16 | Online storefront, scroll po menu, klik na pozycję | „Twój klient widzi storefront, nie płaski katalog. Każde danie to scena wizualna." |
| 0:16–0:26 | POS — PIN 1111 (szybko, bez zbliżenia na klawiaturę!) → dodaj pizzę → koszyk | „Kasa liczy koszyk po stronie serwera. Ceny zależne od kanału — sala, wynos, dostawa." |
| 0:26–0:38 | Studio — otwórz pizzę, pokaż recepturę (drag-and-drop składników), pokaż warianty | „Studio menu z recepturami, wariantami z multiplikatorami, półproduktami." |
| 0:38–0:48 | Courses — mapa dyspozytorni, klik na kierowcę | „Logistyka — dyspozytor i aplikacja kierowcy w jednym systemie. Payment lock, emergency recall." |
| 0:48–0:56 | KSeF Inbox — pokaż listę faktur (jeśli pusto, zacytuj UI), albo Magazyn | „Natywny KSeF z AutoScan. Faktura dostawcy → magazyn w 30 sekund." |
| 0:56–0:60 | Slajd końcowy z logo + `slicehub.pro` | „SliceHub. Mniej chaosu, więcej marży." |

**Procedura nagrania**:
1. `computerUse` subagent — otwórz Chrome, zaloguj się jako `Damian`.
2. Przejdź do Huba (rozgrzewka).
3. `RecordScreen mode=START_RECORDING`.
4. Wykonaj storyboard krok po kroku przez `computerUse` (nie ręcznie — agent prowadzi).
5. `RecordScreen mode=SAVE_RECORDING save_as_filename=spark_demo_60s`.
6. Plik wyląduje w `/opt/cursor/artifacts/spark_demo_60s.webm`.
7. **Po nagraniu** użyj `videoReview` subagent żeby sprawdzić czy nagranie jest OK (kompletne, ostre, bez wycieku PIN).
8. Jeśli wideo nie wyszło — debugnij i powtórz, max 3 próby.

**Reguły**:
- PIN `1111` wpisz JEDNYM ciągiem, **bez zbliżenia na klawiaturę**.
- Nie pokazuj DevTools, powiadomień systemowych, innych kart.
- Polski voiceover — wpisz go w pliku `wniosek/06_voiceover_demo.md` (tekst do nagrania osobno, bo wideo będzie nieme — user nagra voiceover sam później).

### 7. 6 screenshotów hero każdego modułu (1920×1080 PNG)

Po zalogowaniu jako Damian, otwórz każdy moduł i zrób hero screen. Każdy ma wyglądać **profesjonalnie** (pełne UI, nie pusta strona, nie loader). Trafiają do `/opt/cursor/artifacts/`:

1. `hero_01_hub.png` — Hub z kafelkami modułów
2. `hero_02_online.png` — Online storefront z menu (scroll do widoku z 3–4 pozycjami)
3. `hero_03_pos.png` — POS z otwartym koszykiem (2–3 pozycje, modyfikatory widoczne)
4. `hero_04_studio.png` — Studio z otwartym editorem pizzy + receptura rozwinięta
5. `hero_05_courses.png` — Courses z mapą lub listą kursów
6. `hero_06_ksef.png` — KSeF Inbox lub Magazyn (jeśli KSeF pusty — pokaż Magazyn z dokumentami)

Wszystkie screeny przez `computerUse` subagent (po pełnym zalogowaniu).

## Format pracy

1. Stwórz folder `wniosek/` w root repo (NIE `_docs/`, czysty start).
2. W folderze 6 plików markdown (pkt 1–5 + pkt 6 voiceover). Każdy plik **gotowy do skopiowania do formularza** — bez metadanych, bez markdown linków do innych plików.
3. Wideo + screeny w `/opt/cursor/artifacts/`.
4. Commit i push na nową branchę `projektx/spark-wniosek-od-zera-c3d7`.
5. Otwórz draft PR z tytułem „SPARK 3.0 wniosek — wszystkie materiały od zera".
6. W ciele PR i w finalnej odpowiedzi do user'a:
   - Wklej każdy z 5 tekstów wniosku w bloku cytatu (żeby od razu mógł skopiować).
   - Wstaw wideo: `<video src="/opt/cursor/artifacts/spark_demo_60s.webm" controls></video>`.
   - Wstaw 6 screenów: `<img src="/opt/cursor/artifacts/hero_XX.png" alt="..." />`.

## Reguły jakości

- **NIE używaj „my", „zespół", „nasi inżynierowie"** — wszędzie pierwsza osoba liczby pojedynczej lub bezosobowo („zostało zaprojektowane", „produkt obejmuje").
- **NIE wymyślaj liczb** (X tys. użytkowników, Y% redukcji kosztów) — jeśli nie ma twardych danych, używaj sformułowań typu „pierwsi pilotażowi klienci wskazują na…", „benchmark techniczny: 30 sekund vs 15 minut manualnie".
- **NIE obiecuj** integracji z konkretnymi sieciami (Pyszne, Glovo, Bolt) jako fait accompli — pisz „roadmapa integracji obejmuje…" lub „planowana integracja".
- **Język polski naturalny** — bez korpomowy, bez „synergii", bez „proaktywnie". Konkretne czasowniki, konkretne efekty.
- **Liczba znaków** — pilnuj limitów wymienionych przy każdym pliku (zwłaszcza 1500 dla krótkiego opisu).
- **Każdy plik podpisany u dołu** liczbą znaków `[Znaków ze spacjami: NNNN]`.

## Workflow rekomendowany

1. **Najpierw** otwórz https://slicehub.net w `computerUse`, zaloguj się, przeklikaj 3–4 moduły żeby zobaczyć co realnie jest w produkcji.
2. **Potem** zrób 6 hero screenshotów (Priority 7) — od razu masz materiał wizualny do innych zadań.
3. **Potem** nagrywaj wideo (Priority 6) — najtrudniejszy element technicznie, najlepiej z świeżą głową.
4. **Na końcu** pisz teksty (1–5) — masz już pełen kontekst wizualny, łatwiej dobrać konkretne sformułowania.
5. Commit, push, PR, odpowiedź.

**Czas pracy:** zaplanuj ~2 godziny intensywnej pracy, w tym 30 minut na nagrywanie + screeny, 60 minut na teksty, 30 minut na polish + PR.

**Powodzenia.**
