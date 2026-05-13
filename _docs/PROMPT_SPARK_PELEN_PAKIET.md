# PROMPT do nowego okna Cursor Cloud Agent — pełen pakiet materiałów do wniosku SPARK 3.0

> Skopiuj cały blok poniżej linii `---` i wklej w nowe okno Cloud Agent.
> Rekomendacja: uruchom **2–3 agenty równolegle (best-of-N)** dla pewności że nagranie się uda.

---

# Zadanie: wyprodukuj pełen zestaw materiałów wizualnych do wniosku PFR SPARK 3.0

Aplikuję do PFR SPARK 3.0 Accelerator (ścieżka „go global"). **Wniosek tekstowo mam już wypełniony**. Brakuje mi materiałów wizualnych do uzupełnienia formularza i wysyłki do mentorów. Twoje zadanie: wejść na produkcję, zalogować się i wyprodukować pełen pakiet.

**Ważne**: w repo mogą być stare szczątki materiałów z poprzednich prób (foldery `_docs/F6S_SPARK_3_0/`, `_docs/pitchdeck_*`, `presentations/`, `wniosek*.md`) — **całkowicie zignoruj je**, nie kopiuj, nie cytuj. Wyprodukuj wszystko od zera na podstawie aktualnego wyglądu i działania produkcyjnej instancji.

## Dostępy do live

- **URL**: https://slicehub.net
- **Login backoffice**: `Damian` / `Dammalq123123`
- **PIN POS (kiosk)**: `1111`
- **Tenant_id**: `2`

## Czym jest SliceHub Pro (krótko, żebyś wiedział co klikać)

**Multi-modułowy system operacyjny dla gastronomii** — POS, magazyn, dostawy, Studio menu, KSeF, Online storefront. Stack: Vanilla JS + PHP 8 + MariaDB (bez Node w produkcji). Hostowane produkcyjnie na shared hostingu, działa multi-tenant.

**Solo founder**: Damian Malenta. Nie ma zespołu, nie ma firmy. Każdy materiał w pierwszej osobie lub bezosobowo (nie „my", nie „zespół").

### Moduły do pokazania

1. **Hub** (`/modules/hub/`) — kafelki nawigacyjne
2. **Online** (`/modules/online/`) — storefront B2C
3. **POS** (`/modules/pos/`) — kasa z trzema kanałami
4. **Studio Menu** (`/modules/studio/`) — edytor menu, receptury, warianty, „Nowa Pizza" wizard
5. **Courses** (`/modules/courses/`) — dyspozytornia + driver PWA
6. **Magazyn** (`/modules/warehouse/`) — PZ/RW, AVCO, stany
7. **KSeF Inbox** (`/modules/procurement/`) — natywny KSeF z AutoScan fuzzy matching

### Wyróżniki konkurencyjne (mówią o czym jest produkt)

1. **Omnichannel pricing first-class** — różne ceny sala/wynos/dostawa w modelu danych
2. **Variant scales z multiplierami receptur** — jedna receptura, mała = 1.0×, średnia = 1.5×, duża = 2.0×
3. **KSeF AutoScan** — faktura dostawcy → magazyn w 30s (vs 15 min manualnie)
4. **Multi-stage recipes** — receptury z podrecepturami (półproduktami)
5. **Server-authoritative cart** — klient wysyła tylko SKU, ceny liczy serwer
6. **Zero-reload runtime** — działa na shared hostingu za 30 zł/mies

## Co masz wyprodukować — 5 deliverables

### Deliverable 1: Wideo demo 60s (MP4/WebM, 1920×1080)

**Storyboard** (60s ± 5s):

| Sekundy | Moduł | Akcja |
|---------|-------|-------|
| 0:00–0:06 | Hub | Pokaż kafelki modułów, lekki scroll |
| 0:06–0:16 | Online | Scroll po menu storefrontu, hover na pozycji |
| 0:16–0:26 | POS | Login PIN `1111` (szybko, bez zbliżenia na klawiaturę!) → dodaj pizzę → otwórz koszyk |
| 0:26–0:38 | Studio Menu | Drzewo kategorii → klik na pizzę → pokaż edytor + receptura |
| 0:38–0:48 | Courses | Mapa dyspozytora lub lista kursów |
| 0:48–0:56 | Magazyn lub KSeF Inbox | Pokaż listę dokumentów / faktur |
| 0:56–0:60 | Hub (powrót) | Końcowe ujęcie |

**Procedura**:
1. `computerUse` → Chrome → https://slicehub.net → login `Damian` / `Dammalq123123`.
2. Przejdź do Huba, sprawdź że wszystko ładuje czysto.
3. `RecordScreen mode=START_RECORDING`.
4. Wykonaj storyboard przez `computerUse`.
5. `RecordScreen mode=SAVE_RECORDING save_as_filename=slicehub_demo_60s`.
6. `videoReview` subagent — sprawdź: czas ~60s, 6 sekcji widocznych, PIN niewidoczny klatka-po-klatce, brak DevTools.
7. Jeśli źle → discard, powtórz (max 3 próby).

**Wynik**: `/opt/cursor/artifacts/slicehub_demo_60s.webm`.

### Deliverable 2: 7 hero screenshotów (PNG 1920×1080)

Po zalogowaniu zrób profesjonalne screeny każdego modułu — pełne UI, dane widoczne, bez DevTools, bez powiadomień.

Pliki w `/opt/cursor/artifacts/`:

1. `hero_01_hub.png` — Hub z kafelkami
2. `hero_02_online.png` — Online storefront z menu (3–4 dania widoczne)
3. `hero_03_pos.png` — POS z otwartym koszykiem (2–3 pozycje, modyfikatory jeśli są)
4. `hero_04_studio.png` — Studio z otwartym edytorem pizzy + receptura
5. `hero_05_courses.png` — Courses z mapą lub listą kursów
6. `hero_06_warehouse.png` — Magazyn z listą dokumentów (PZ/RW)
7. `hero_07_ksef.png` — KSeF Inbox z listą faktur (jeśli pusta, pokaż UI z legendą)

Każdy screen przez `computerUse` po nawigacji na moduł + 2s delay (żeby UI się ustawiło).

### Deliverable 3: One-pager A4 PDF (do wysyłki mentorom mailem)

Jedna strona A4 portrait w PDF zawierająca:

- Hero z 1 dużym screenem (najlepszy z Deliverable 2 — proponuję POS lub Studio)
- Tagline: „SliceHub Pro — system operacyjny gastronomii: POS + Magazyn + Dostawy + KSeF w jednej platformie"
- 3 sekcje:
  - **Problem** (3 punkty: chaos narzędzi, brak omnichannel pricing, ręczne wpisywanie faktur)
  - **Rozwiązanie** (4 wyróżniki: variant scales z multiplierami, KSeF AutoScan, omnichannel native, zero-reload na shared hostingu)
  - **Status** (działa produkcyjnie na slicehub.net, 11 modułów, multi-tenant)
- 3 mini-thumbnaile innych screenów (z Deliverable 2)
- Footer: `slicehub.pro` + CTA „Aplikacja: SPARK 3.0 Accelerator"

**Format**: HTML + CSS print stylesheet → Chrome headless → PDF.
- HTML źródłowy: `_docs/SPARK_materialy/onepager.html`
- PDF wynik: `/opt/cursor/artifacts/spark_onepager.pdf`

Styl: ciemny motyw glassmorphism (`bg-white/5`, `backdrop-blur`) zgodny z brandem SliceHub. Czcionka system (Inter / SF) dla czystości.

### Deliverable 4: Pitch deck PDF (8–10 slajdów, do investor relations)

Slajdy 1920×1080 (PDF landscape) — do załącznika we wniosku jako „Investor Deck" lub do wysyłki mentorom.

**Struktura 10 slajdów**:

1. **Cover** — logo SliceHub Pro + tagline + URL
2. **Problem** — chaos narzędzi w gastronomii (POS osobno, magazyn osobno, dostawy osobno, KSeF ręcznie)
3. **Rozwiązanie** — jedna platforma, 11 modułów, multi-tenant
4. **Demo** — duży screen POS lub Studio (z Deliverable 2) + 3 kluczowe wyróżniki
5. **Innowacje techniczne** — variant scales, KSeF AutoScan, server-authoritative cart, multi-stage recipes (krótkie one-linery)
6. **Rynek** — gastronomia PL (~70k restauracji) + CEE expansion (CZ, SK, RO, UA)
7. **Why now?** — KSeF obowiązkowy 2026, presja kosztów surowca, konsolidacja sieci CEE
8. **Konkurencja** — porównanie: Papu (omnichannel ale brak magazynu/KSeF), Dotykačka (CZ), LSI (PL), SliceHub (wszystko w jednym)
9. **Roadmapa SPARK** — co zostanie zrobione w akceleracji (pilotaże, lokalizacja CEE, materiały sprzedażowe)
10. **Cover end** — slicehub.pro + kontakt

Każdy slajd: dark theme, screeny z Deliverable 2 jako wizualizacje.

**Wykonanie**: HTML + CSS slajdy → puppeteer / Chrome headless PDF print.
- HTML źródłowy: `_docs/SPARK_materialy/pitchdeck.html`
- PDF wynik: `/opt/cursor/artifacts/spark_pitchdeck.pdf`

### Deliverable 5: Landing page (link demo do wniosku)

Strona HTML standalone do wstawienia we wniosku jako „Demo URL" lub do wysłania jako quick link.

**Struktura**:

- **Hero**: duży screen POS + tagline + CTA „Zobacz demo (60s)" → link do YouTube wideo (placeholder URL, user wstawi)
- **Sekcja modułów**: 7 kafelków, każdy z miniaturą hero (Deliverable 2) + krótkim opisem
- **Sekcja „Liczby"**: 11 modułów, X feature tagów, Y migracji bazy
- **Sekcja „Innowacje"**: 4 wyróżniki z ikonami
- **Sekcja „Demo wideo"**: embed wideo (placeholder dla YouTube)
- **Footer**: linki, slicehub.pro, GitHub

**Wykonanie**: HTML + Tailwind CSS (CDN) + ciemny motyw glassmorphism.
- Plik: `_docs/SPARK_materialy/landing.html`
- Self-contained — wszystkie style w `<style>` lub linki do CDN.

## Format dostarczenia (KRYTYCZNE)

Po zakończeniu pracy w **finalnej odpowiedzi** zwróć user'owi:

### Sekcja 1: Wideo + screeny (artifacts)

```
Wideo demo:
<video src="/opt/cursor/artifacts/slicehub_demo_60s.webm" controls></video>

Screeny:
<img src="/opt/cursor/artifacts/hero_01_hub.png" alt="Hub" />
<img src="/opt/cursor/artifacts/hero_02_online.png" alt="Online" />
... (wszystkie 7)
```

### Sekcja 2: Tabela „Co z czym zrobić"

```markdown
| Materiał | Co z tym zrobić |
|----------|-----------------|
| `slicehub_demo_60s.webm` | Upload na YouTube/Vimeo jako **Unlisted**. Link wkleić do formularza SPARK w polu „Product Video" / „Demo URL". |
| `hero_01_hub.png` ... `hero_07_ksef.png` | Załączyć do wniosku w sekcji „Screenshots" lub „Załączniki wizualne". Można też wkleić do Deliverable 3 i 4 (one-pager / pitch deck używają tych obrazów). |
| `spark_onepager.pdf` | Wysłać mentorom / akceleratorowi mailem jako szybkie info o produkcie. Można też załączyć do wniosku jako „Executive Summary". |
| `spark_pitchdeck.pdf` | Załączyć do wniosku w sekcji „Pitch Deck" / „Investor Deck". |
| `landing.html` | Hostować na własnej domenie (np. https://slicehub.pro/demo) i wkleić link do formularza w polu „Demo URL" / „Product Website". |
```

### Sekcja 3: PR i branch

- Commit i push HTML/CSS dla onepager i pitchdeck do nowej brancha `projektx/spark-pelen-pakiet-c3d7`.
- Otwórz draft PR z tytułem „SPARK 3.0 — pełen pakiet materiałów wizualnych".

### Sekcja 4: Podsumowanie raportu

W finalnej odpowiedzi zwięźle:
- Ile prób nagrania wideo (1/3, 2/3, czy 3/3)
- Czy któryś deliverable miał problemy (np. moduł nie ładuje się)
- Co user musi zrobić ręcznie (np. wgrać wideo na YouTube, zhostować landing.html)

## Reguły jakości

- **PIN `1111`** nie pokazuj klatka-po-klatce. Wpisz szybko jednym ciągiem, kursor poza klawiaturą.
- **Solo founder** — w tekstach pitch decka / onepager pierwsza osoba liczby pojedynczej („zostało zaprojektowane", „produkt obejmuje"), nie „my".
- **Bez wymyślania liczb** — jeśli nie znasz liczby aktywnych klientów / przychodu, używaj sformułowań typu „benchmark techniczny: 30s vs 15 min".
- **Bez DevTools, powiadomień, prywatnych danych** w screenach i wideo.
- **PDF-y** muszą być rzeczywiście PDF (nie HTML z `.pdf` w nazwie). Użyj `chromium --headless --print-to-pdf`.
- **Wszystkie teksty w PL** (pitchdeck PL, onepager PL — wniosek jest do PFR).

## Rekomendowany workflow

1. **Setup**: otwórz Chrome przez `computerUse`, zaloguj się, przeklikaj 3–4 moduły żeby zobaczyć aktualny stan.
2. **Deliverable 2 najpierw** (screeny) — masz wtedy materiał wizualny do deliverables 3 i 4.
3. **Deliverable 1** (wideo) — masz świeży kontekst po przeklikaniu, najlepszy moment na nagranie.
4. **Deliverable 3** (one-pager) — szybki, HTML + CSS, używa screenów z 2.
5. **Deliverable 4** (pitch deck) — najdłuższy element pisany, używa screenów z 2.
6. **Deliverable 5** (landing) — używa screenów z 2 i (placeholder) wideo z 1.
7. Commit, push, draft PR, finalna odpowiedź z tabelą „co z czym zrobić".

**Plan czasowy**: ~2 godziny pracy. Powodzenia.
