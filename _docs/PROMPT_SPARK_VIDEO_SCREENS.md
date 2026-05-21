# PROMPT do nowego okna Cursor Cloud Agent — TYLKO wideo demo + screenshoty

> Skopiuj cały blok poniżej linii `---` i wklej w nowe okno Cloud Agent.

---

# Zadanie: nagraj wideo demo (60s) + 6 hero screenshotów produktu SliceHub Pro

Aplikuję do PFR SPARK 3.0 Accelerator. **Wniosek mam już wypełniony tekstowo**, brakuje mi wyłącznie materiałów wizualnych. Twoje zadanie jest jedno: wejść na produkcyjną instancję, zalogować się i wyprodukować **wideo demo (60s)** + **6 screenshotów hero** każdego modułu.

**NIE rób** pitch decków, tekstów wniosku, landing page, one-pagerów, GIF-ów, voiceoverów, slajdów. **Tylko wideo i screeny.**

## Dostępy

- **URL**: https://slicehub.net
- **Login backoffice**: `Damian` / `Dammalq123123`
- **PIN POS (kiosk)**: `1111`
- **Tenant_id**: `2`

Po zalogowaniu trafiasz do Huba (`/modules/hub/index.html`) — punkt startowy z kafelkami modułów.

## Czym jest SliceHub Pro (krótki kontekst, byś wiedział co klikać)

System operacyjny dla gastronomii — multi-modułowa platforma w przeglądarce. Moduły do pokazania:

- **Hub** — kafelki nawigacyjne wszystkich modułów
- **Online** — storefront B2C dla klienta końcowego (sklep z menu)
- **POS** — kasa (sala / wynos / dostawa), login PIN-em, koszyk, modyfikatory
- **Studio Menu** — edytor menu z drzewem kategorii, recepturami, wariantami pizzy
- **Courses** — dyspozytornia z mapą + driver app
- **Magazyn** / **KSeF Inbox** — przyjęcia faktur, stany surowca

Wszystko działa na slicehub.net produkcyjnie, dane demo są zaseedowane.

## Zadanie A — wideo demo 60 sekund (MP4/WebM)

### Storyboard (1920×1080, dokładnie 60s)

| Sekundy | Co pokazać | Akcja |
|---------|-----------|-------|
| 0:00–0:06 | **Hub** po zalogowaniu (`/modules/hub/`) | Pokaż kafelki, lekki scroll |
| 0:06–0:16 | **Online storefront** (`/modules/online/index.html?tenant=2`) | Scroll po menu, najedź na pozycję |
| 0:16–0:26 | **POS** (`/modules/pos/`) — login PIN `1111` → dodaj pozycję do koszyka | **NIE rób zbliżenia na klawiaturę**, wpisz PIN szybko jednym ciągiem |
| 0:26–0:38 | **Studio Menu** (`/modules/studio/`) — drzewo kategorii → klik na pizzę → pokaż receptury | Otwórz edytor pozycji, pokaż listę składników (z multiplikatorami jeśli widoczne) |
| 0:38–0:48 | **Courses** (`/modules/courses/`) — mapa lub lista kursów | Pokaż widok dyspozytora |
| 0:48–0:56 | **Magazyn lub KSeF Inbox** (`/modules/warehouse/` lub `/modules/procurement/`) | Pokaż listę dokumentów / faktur |
| 0:56–0:60 | Wróć do **Huba** — końcowe ujęcie z logo / kafelkami | Krótkie zamknięcie |

### Procedura nagrania

1. Użyj **`computerUse` subagent**, otwórz Chrome, przejdź na https://slicehub.net.
2. Zaloguj się jako `Damian` / `Dammalq123123`.
3. Przejdź do Huba — to punkt startowy (sprawdź że wszystko ładuje się czysto).
4. `RecordScreen mode=START_RECORDING`.
5. Wykonaj storyboard krok po kroku przez `computerUse` (każdą sekcję 6–12 sekund zgodnie z tabelą).
6. `RecordScreen mode=SAVE_RECORDING save_as_filename=slicehub_spark_demo_60s`.
7. Plik wyląduje automatycznie w `/opt/cursor/artifacts/slicehub_spark_demo_60s.webm`.
8. Po zapisaniu użyj **`videoReview` subagent**, żeby zweryfikować:
   - Czy wideo trwa ~60 sekund (akceptowalne 55–70s).
   - Czy widać wszystkie 6 sekcji storyboardu.
   - Czy PIN `1111` nie jest widoczny klatka-po-klatce.
   - Czy nie ma DevTools, powiadomień, prywatnych kart.
9. Jeśli wideo nie spełnia kryteriów — **discard recording i powtórz** (max 3 próby). Zapisz tylko najlepszą wersję.

## Zadanie B — 6 hero screenshotów (PNG 1920×1080)

Po zalogowaniu zrób po jednym wysokiej jakości screenshocie każdego modułu. **Każdy ma być wzorowym ujęciem promocyjnym** — pełne UI, dane widoczne (nie pusta strona, nie loader).

Pliki w `/opt/cursor/artifacts/`:

1. `hero_01_hub.png` — Hub z kafelkami modułów
2. `hero_02_online.png` — Online storefront z menu w widoku (3–4 dania widoczne)
3. `hero_03_pos.png` — POS z otwartym koszykiem (2–3 pozycje, jeśli możesz pokaż modyfikatory)
4. `hero_04_studio.png` — Studio z otwartym editorem pozycji + receptura w widoku
5. `hero_05_courses.png` — Courses z mapą lub listą kursów (najlepiej z pinami kierowców na mapie)
6. `hero_06_warehouse.png` — Magazyn (lista dokumentów) lub KSeF Inbox (lista faktur, jeśli są jakieś)

**Reguły wizualne**:
- Pełna szerokość okna 1920×1080.
- Bez otwartych DevTools.
- Bez systemowych powiadomień / tooltipów w kadrze.
- Bez kursora w środku contentu (przesuń kursor poza obszar).
- Bez prywatnych danych (e-maili, numerów telefonów) w widoku — jeśli widzisz, scrolluj poza nie.

Każdy screenshot wykonaj przez `computerUse` subagent (after navigate to module + delay).

## Format dostarczenia

Po zakończeniu w finalnej odpowiedzi:

1. **Wideo** w bloku HTML:
   ```html
   <video src="/opt/cursor/artifacts/slicehub_spark_demo_60s.webm" controls></video>
   ```

2. **Wszystkie 6 screenów** w blokach HTML:
   ```html
   <img src="/opt/cursor/artifacts/hero_01_hub.png" alt="Hub" />
   <img src="/opt/cursor/artifacts/hero_02_online.png" alt="Online" />
   ... etc.
   ```

3. **Krótkie podsumowanie**: ile prób nagrania, czy są jakieś zastrzeżenia, czy wszystko wyszło OK.

## Reguły krytyczne

- **NIE commit'uj** wideo / PNG do repo (zostają tylko w `/opt/cursor/artifacts/` — user pobierze z Cursor Web).
- **NIE pisz** żadnych nowych dokumentów `.md`, prezentacji, voiceoverów — wszystko mam.
- **NIE modyfikuj** kodu produktu na slicehub.net — to live, używasz read-only.
- Jeśli któryś moduł nie ładuje się / ma błąd → zrób screenshot stanu błędu i przejdź dalej, w finalnej odpowiedzi opisz problem.
- Jeśli `computerUse` nie wystartuje albo nagrywanie zawodzi po 3 próbach → zwróć tylko screeny, opisz powód niepowodzenia wideo.

**Powodzenia.**
