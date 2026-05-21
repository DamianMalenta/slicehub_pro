# PROMPT do nowego okna Cursor Cloud Agent — REWORK materiałów SPARK 3.0 (BEZ wideo)

> Skopiuj cały blok poniżej linii `---` i wklej w nowe okno Cloud Agent.
> **Model**: 1× Claude Sonnet (zadanie rozbudowane wizualnie, Sonnet lepszy do design HTML/CSS niż Composer)
> **Liczba agentów**: 1 wystarczy (nie potrzeba best-of-N — żadnego nagrywania, tylko deterministyczne screeny + HTML).

---

# Zadanie: PRZEROBIĆ I WZBOGACIĆ materiały wizualne do wniosku PFR SPARK 3.0 (bez wideo)

Aplikuję do PFR SPARK 3.0 Accelerator (ścieżka „go global"). **Wideo demo już mam nagrane** — NIE rób nowego nagrania, NIE dotykaj `RecordScreen` toola. Skup się wyłącznie na materiałach statycznych: screenshoty + HTML/PDF.

## Stan poprzedniego pakietu

W repo na `main` w `_docs/SPARK_materialy/` jest pakiet wygenerowany 13.05.2026 wieczorem (PR #11 i #10 zmergowane). Zawiera:
- 7 hero screenshotów PNG (3 z nich SŁABE — POS/Courses/KSeF były wtedy puste lub broken)
- `onepager.html`, `pitchdeck.html`, `landing.html` (kod OK ale używa starych słabych screenów)
- PDF wygenerowane z HTML

**Ten pakiet ma być nadpisany czystymi nowymi materiałami.**

## Kluczowe zmiany w produkcie od poprzedniego nagrywania

System na slicehub.net **znacznie więcej teraz pokazuje** niż wtedy:

1. **POS variants modal działa** (commit `ef601b9`) — kliknięcie pizzy z rozmiarami otwiera ładny dark glassmorphism modal "Wybierz rozmiar" z listą wariantów (Mała / Średnia / Duża) z cenami per kanał.
2. **Online storefront** (Pizzeria Forno) ma teraz wsparcie wariantów — badge "Rozmiary →" na pizzy + modal wyboru w stylu storefront.
3. **POS/Courses/KSeF mają demo dane** (po `_docs/demo_seed_dynamic_data.sql`):
   - 3 demo zamówienia w POS: DEMO-001 Delivery Jan Kowalski (paid online, 53.00 zł), DEMO-002 Takeaway Anna Nowak (preparing), DEMO-003 Sala Stolik 5 Para (75.00 zł, 2 pizze)
   - 2 kierowców w Courses: Jan Wiśniewski (available), Tomasz Kowalczyk (busy)
   - 2 faktury KSeF Inbox: FA/DEMO/2026/001 Hurtownia Warmia 307.50 zł (status=new, do akceptacji), FA/DEMO/2026/002 Fruktus 126 zł (accepted historyczna)

Czyli **screenshoty będą nieporównywalnie lepsze** niż wczoraj.

## Dostępy do live

- **URL**: https://slicehub.net
- **Login backoffice**: `Damian` / `Dammalq123123` (mode=system)
- **PIN POS**: `1111`
- **Tenant_id**: `2`

## Co masz wyprodukować (4 deliverables, BEZ wideo)

### Deliverable 1: 7 hero screenshotów (PNG 1920×1080) — NADPISZ stare

Wszystkie do `/opt/cursor/artifacts/`. Jakość: profesjonalne hero shots, pełne UI, dane widoczne, **bez DevTools, bez powiadomień, bez prywatnych elementów w kadrze**.

| Plik | Co pokazać |
|------|-----------|
| `hero_01_hub.png` | Hub po zalogowaniu — kafelki modułów, dark glassmorphism. ZACHOWAJ podobny do poprzedniego (był dobry). |
| `hero_02_online.png` | Pizzeria Forno storefront ze scrollem do widoku z 3-4 daniami. **Bonus**: jeśli widzisz badge "Rozmiary →" na Pizza Margherita — pokaż to ujęcie, bo to nowy feature. |
| `hero_03_pos.png` | **NOWY HERO**: POS w trybie "Tworzenie zamówienia" z **otwartym koszykiem** zawierającym 2-3 pozycje (np. Pizza Margherita Duża 45.00 zł, Pizza Capricciosa 40.00 zł, modyfikator + dodatek). Sidebar z kierowcami i kelnerami widoczny. Kategoria Pizze otwarta z kafelkami. To MA pokazać POS jako **żywy, używany system** — nie pusty sandbox. |
| `hero_04_studio.png` | Studio Menu z otwartym edytorem Pizza Margherita Test (lub innej), receptura rozwinięta, F-S1 variant scales widoczne, Bliźniak Cyfrowy (Food Cost) z prawej strony. **Najlepszy screen pakietu** — zachowaj jakość. |
| `hero_05_courses.png` | **NOWY HERO**: Dispatcher z mapą Leaflet, 2 piny kierowców widoczne (Jan Wiśniewski i Tomasz Kowalczyk), w sidebarze widoczne 1-2 zamówienia w kolejce do dispatchu (DEMO-001 Marszałkowska 12 jest geocoded — pokaże pin). Pokaż profesjonalny dispatcher view. |
| `hero_06_warehouse.png` | Magazyn V2 dashboard z 10 kafelkami (PZ/RW/MM/KOR itd.). Zachowaj poprzedni jeśli był dobry. |
| `hero_07_ksef.png` | **NOWY HERO**: KSeF Inbox z **listą 2 faktur** w trzech zakładkach (Wszystkie 2, Do akceptacji 1, Zaakceptowane 1). Otwórz fakturę FA/DEMO/2026/001 Hurtownia Warmia żeby pokazać AutoScan match levels (EXACT/ALIAS/NAME/FUZZY) jeśli widoczne. Dropzone "Przeciągnij FA(2) XML tutaj" w widoku. |

**Każdy screen przez computerUse** (po pełnym zalogowaniu, navigate, delay 2s, screenshot).

### Deliverable 2: One-pager A4 PDF — PRZEROBIĆ (wzbogacić treść + nowe screeny)

Plik: `_docs/SPARK_materialy/onepager.html` (przerobić, NIE od zera — zachowaj design glassmorphism który już jest dobry, ale **wymień screenshoty na nowe** + **wzbogacić treść**).

**Wzbogać treść**:
- Sekcja PROBLEM: dodać konkrety z polskiego rynku (~70k restauracji, KSeF obowiązkowy 2026, fragmentacja systemów)
- Sekcja ROZWIĄZANIE: każdy z 4 wyróżników z **mierzalnym efektem biznesowym** (np. "KSeF AutoScan: faktura → magazyn w ~30s zamiast 15-20 min manualnie = oszczędność godziny dziennie na pracowniku")
- Sekcja STATUS: zaktualizować liczby (11 modułów produkcyjnych, X migracji bazy, Y feature tagów)
- Hero screenshot: użyć nowego `hero_04_studio.png` (najmocniejszy)
- Dolne thumbnaile: 7 nowych screenshotów (Hub/Online/POS/Studio/Courses/Magazyn/KSeF)
- Footer: `slicehub.net` + email kontaktowy + "Solo founder: Damian Malenta" (uczciwie)

**Format**: HTML + CSS print stylesheet → Chrome headless → PDF.
- Plik: `_docs/SPARK_materialy/onepager.html` (nadpisać)
- PDF: `/opt/cursor/artifacts/spark_onepager_v2.pdf` (suffix `_v2` żeby odróżnić od starego)

### Deliverable 3: Pitch deck PDF — PRZEROBIĆ + dodać 2 nowe slajdy

Plik: `_docs/SPARK_materialy/pitchdeck.html` (przerobić). Aktualne 10 slajdów struktura:
1. Cover
2. Problem
3. Rozwiązanie
4. Demo Studio Menu
5. Wyróżniki techniczne
6. Rynek + CEE
7. Why now
8. Konkurencja (tabela)
9. Roadmapa SPARK
10. Cover end

**Modyfikacje**:
- Slajd 4 (Demo Studio): zostaw, tylko podmień screen na nowy `hero_04_studio.png`
- **Dodać slajd 4b "Demo POS"**: hero `hero_03_pos.png` (POS z koszykiem) + 3 punkty (Server-authoritative cart, omnichannel pricing per kanał, modyfikatory + warianty w jednym koszyku)
- **Dodać slajd 4c "Demo Logistyki"**: hero `hero_05_courses.png` + 3 punkty (Mapa dyspozytora, Driver PWA z payment lock + emergency recall, jeden silnik API dla flotyowych integracji)
- Slajd 5 (Wyróżniki): wzbogać o **mierzalne benchmarki** (KSeF AutoScan: 30s vs 15-20 min, Variant scales: 1 receptura vs N duplikatów per rozmiar)
- Slajd 8 (Konkurencja): zostaw — była mocna
- Pozostałe slajdy: drobne poprawki tylko jeśli treść była słaba

Razem **12 slajdów** w landscape 1920×1080.

**KRYTYCZNE — `@page` rule**:
HTML musi mieć:
```css
@page { size: 1920px 1080px; margin: 0 }
@media print {
    html, body { width: 1920px; height: auto; background: var(--bg); margin: 0; padding: 0; }
    .slide { width: 1920px; height: 1080px; page-break-after: always; }
    .slide:last-child { page-break-after: auto; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
```

Bez tego Chrome `--print-to-pdf` używa A4 portrait i obcina slajdy.

**Format**: HTML + CSS → Chrome headless `--print-to-pdf --paper-width=20 --paper-height=11.25` → PDF.
- Plik: `_docs/SPARK_materialy/pitchdeck.html` (nadpisać)
- PDF: `/opt/cursor/artifacts/spark_pitchdeck_v2.pdf`

### Deliverable 4: Landing page HTML — PRZEROBIĆ

Plik: `_docs/SPARK_materialy/landing.html` (przerobić — zachowaj 4 sekcje structure ale wymień zawartość):

- **Hero**: dodać nowy `hero_03_pos.png` jako tło sekcji + tagline "Restaurant OS — POS, Magazyn, Dostawy, KSeF w jednej platformie" + CTA "Zobacz demo (60s)" → placeholder URL `<YOUTUBE_URL_TO_REPLACE>`
- **Sekcja Moduły**: 7 kafelków z miniaturami (nowe screeny)
- **Sekcja Innowacje**: 4 ikon + tytuły + krótki opis (Variant Scales, KSeF AutoScan, Server-Authoritative Cart, Zero-Reload Runtime)
- **Sekcja "Liczby"**: 11 modułów, X migracji, Y feature tagów, slicehub.net (uppercase grid)
- **Sekcja Demo**: embed wideo (placeholder YouTube ID `dQw4w9WgXcQ` jako tymczasowy — user zamieni)
- **Footer**: linki, slicehub.net, GitHub, email, "Solo founder: Damian Malenta"

**Styling**: Tailwind CDN + ciemny motyw glassmorphism. Self-contained.

Plik: `_docs/SPARK_materialy/landing.html` (nadpisać).

## Format dostarczenia (KRYTYCZNE)

Po skończeniu w finalnej odpowiedzi do user'a:

### Sekcja 1: Inline screenshoty
```
<img src="/opt/cursor/artifacts/hero_01_hub.png" alt="Hub" />
<img src="/opt/cursor/artifacts/hero_02_online.png" alt="Online" />
... (wszystkie 7)
```

### Sekcja 2: PDF do pobrania
```
PDF onepager: /opt/cursor/artifacts/spark_onepager_v2.pdf (NNN KB)
PDF pitchdeck: /opt/cursor/artifacts/spark_pitchdeck_v2.pdf (NNN KB)
```

### Sekcja 3: Tabela "Co z czym zrobić"
| Materiał | Akcja user'a |
|----------|--------------|
| `hero_*.png` (7 sztuk) | Załączyć do formularza SPARK w sekcji "Screenshots" |
| `spark_onepager_v2.pdf` | Wysłać mentorom mailem jako Executive Summary |
| `spark_pitchdeck_v2.pdf` | Załącznik w polu "Pitch Deck" |
| `landing.html` | Hostować na slicehub.net/demo, podmienić `<YOUTUBE_URL_TO_REPLACE>` na rzeczywisty URL filmu po wgraniu |

### Sekcja 4: Branch + PR
- Commit i push do brancha `projektx/spark-rework-bez-video-c3d7`
- Otwórz draft PR z tytułem "SPARK 3.0 REWORK — materiały bez wideo"

## Reguły jakości

- **NIE NAGRYWAJ WIDEO** — user ma swoje. NIE używaj `RecordScreen` ani `videoReview`.
- **Screenshoty robisz przez `computerUse` subagent** na live https://slicehub.net.
- **PDF generujesz przez Chrome headless `--print-to-pdf`** (nie wkładaj PDF do repo, tylko HTML + PDF do artifacts).
- **Solo founder** — w tekstach pierwsza osoba liczby pojedynczej, "zostało zaprojektowane", "produkt obejmuje". NIE "zespół SliceHub", NIE "my".
- **Bez wymyślania liczb** — jeśli nie znasz X klientów / Y MRR, używaj sformułowań typu "benchmark techniczny: 30s vs 15-20 min", "11 modułów produkcyjnych", "działa produkcyjnie na slicehub.net".
- **Bez DevTools, powiadomień, prywatnych danych** w screenach.
- **Polski język** w pitch deck / one-pager / landing — wniosek jest do PFR.
- **Zachowaj design glassmorphism** który już jest dobry — ciemne tło, orange accent, slate text.

## Workflow

1. **Przeczytaj** `_docs/SPARK_materialy/onepager.html` + `pitchdeck.html` + `landing.html` — masz solidny start.
2. **Zaloguj się przez `computerUse`** na slicehub.net jako Damian.
3. **Zrób 7 screenów** (deliverable 1) — to baza dla wszystkiego innego.
4. **Update onepager.html** — wymień screeny na nowe, wzbogać treść, generuj PDF.
5. **Update pitchdeck.html** — wymień screeny, dodaj 2 slajdy (4b, 4c), wzbogać slajd 5, generuj PDF.
6. **Update landing.html** — wymień hero, dodaj sekcje "Liczby", "Innowacje".
7. **Commit + push + draft PR**.
8. **Finalna odpowiedź** ze wszystkimi screenami inline + linki do PDF + tabela co zrobić.

**Czas pracy:** ~1.5h. Powodzenia.
