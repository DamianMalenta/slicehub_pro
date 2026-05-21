# SliceHub Enterprise — Pitch Deck  
## Wniosek: **Spark 3.0 Accelerator** (wersja robocza do importu do slajdów)

**Data materiału:** 2026-05-11  
**Źródła wewnętrzne (spójność z produktem):** `_docs/01_KONSTYTUCJA.md`, `_docs/02_ARCHITEKTURA.md`, `_docs/00_PAMIEC_SYSTEMU.md`, `_docs/15_KIERUNEK_ONLINE.md`, `_docs/canvasy/SliceHub Three Directions.md`

---

> **Jak używać:** każda sekcja poniżej = jeden slajd (nagłówek + punktory + notatki prelegenta).  
> Eksport do Google Slides / Keynote / Gamma: kopiuj nagłówki jako tytuły, bullet jako treść, notatki do „Speaker notes”.  
> **Wersja 3 min (10 slajdów, max 5 linii/slajd):** [`PITCH_DECK_SPARK_3.0_ACCELERATOR_10SLAJDOW.md`](./PITCH_DECK_SPARK_3.0_ACCELERATOR_10SLAJDOW.md)

---

## SLIDE 1 — Tytuł

**SliceHub Enterprise**  
*System operacyjny gastronomii: od magazynu i kuchni po „teatr zamówienia” online*

- Produkt: wielomodułowy stack SaaS dla restauracji i sieci (multi-tenant).
- Innowacja: jeden silnik danych i operacji — bez „klejenia” POS + delivery + magazyn z trzech narzędzi.

**Notatki:** Nie pozycjonujemy się jako „kolejny POS”. SliceHub to warstwa enterprise: ceny omnichannel, bliźniak magazynowy, logistyka kursów, storefront jako doświadczenie, nie katalog SKU (por. Prawo VII w Konstytucji).

---

## SLIDE 2 — Problem

**Gastronomia utknęła między „działającą kasą” a „prawdziwym kosztem i marką”**

- **Rozproszenie:** osobno menu, osobno magazyn, osobno delivery, osobno strona — dane się rozjeżdżają, food cost jest „po fakcie”.
- **Ceny:** jedna „płaska” cena w katalogu nie oddaje sali / wynosu / dostawy — marża jest zarządzana na czuja.
- **Online:** agregatory i szablony Woo skracają drogę, ale **nie** dają restauracji własnej sceny marki ani głębokiego powiązania składników z magazynem.
- **Skala:** sieci i multi-lokal wymagają izolacji danych i powtarzalnych procesów — wiele narzędzi to łamie.

**Notatki:** W docs mamy jawnie: brak pojęcia płaskiej ceny (macierz `sh_price_tiers`), frontend nie wysyła cen (serwer `CartEngine`).

---

## SLIDE 3 — Rozwiązanie (w jednym zdaniu)

**SliceHub = jeden system operacyjny dla lokalu: operacje + finanse operacyjne + klient końcowy — na wspólnej prawdzie danych**

- Jeden tenant, jedna bariera `tenant_id` — bezpieczeństwo multi-najemcy na poziomie architektury.
- **Omnichannel pricing:** kanały (sala, wynos, dostawa) jako pierwszej klasy obywatele danych.
- **Bliźniak cyfrowy magazynu:** modyfikatory i receptury wiążą się ze SKU magazynowym, nie z „tekstem na paragonie”.
- **Storefront:** „teatr fotograficzny” (Drzwi → Counter / Living Table → checkout → track), zgodnie z roadmapą Fazy 2 (dokument `15_KIERUNEK_ONLINE.md`).

**Notatki:** North Star w `00_PAMIEC_SYSTEMU.md`: manager widzi Studio (Director + Harmony), klient — scenę, nie grid produktów.

---

## SLIDE 4 — Produkt: moduły (co już istnieje w systemie)

**Ponad „kasa” — gotowe moduły enterprise**

| Warstwa | Moduły (skrót z `02_ARCHITEKTURA.md`) |
|--------|----------------------------------------|
| **Front operacyjny** | POS (Battlefield), Tables / Waiter, KDS |
| **Logistyka** | Dispatcher (kursy K{n}, przystanki L{n}), Driver PWA (payment lock, emergency recall, portfel) |
| **Backoffice** | Menu Studio (menu, modyfikatory, receptury, macierz cen), Warehouse (PZ/RW/INW/KOR/MM), Settings (integracje, webhooks) |
| **Online** | Storefront klienta, Online Studio (Director, asset library), śledzenie zamówienia |
| **Hub / Kiosk** | Centralny launcher, ścieżki personelu |

**Notatki:** To jest realna mapa katalogów — nie wishlist. Komisja widzi „głębokość produktu”, nie slajd z jednym featurem.

---

## SLIDE 5 — Innowacja technologiczna i architektoniczna

**Dlaczego to jest trudne do skopiowania „w weekend”**

- **Silosy prefiksowe bazy (`sh_` / `sys_` / `wh_`)** — połączenia między domenami **tylko** przez klucze znakowe (`sku`, `ascii_key`), nie przez przypadkowe JOIN-y po ID. Mniej „magicznych” bugów przy skali i audycie.
- **Serwer jako źródło prawdy:** koszyk i ceny liczy backend (`CartEngine`), nie przeglądarka — mniej fraudu i rozjazdów.
- **Temporal menu:** publikacja w czasie (`valid_from` / `valid_to`) — realna gastro, nie statyczny PDF.
- **Manifest „Zero-Reload Runtime”:** produkcja jako Vanilla JS + PHP + MariaDB — **bez** Node/React w runtime na hostingu; deploy jako pliki (niższy TCO hostingu, przewidywalny edge case na słabym hostingu shared).

**Notatki:** To jest świadoma decyzja kosztowa i jakościowa — ważna dla funduszy poszukujących „defensibility”.

---

## SLIDE 6 — Kierunek produktowy: Online (decyzja zatwierdzona)

**Faza 2 (MVP): „Realistyczny Counter + Drzwi” — 6–8 tygodni prac po stronie produktu**

- **Scena Drzwi:** wejście marki (godziny, kanały, mapa, tryb zamknięcia / pre-order).
- **Counter / Living Table:** poziomy swipe dań, warstwy składników, companions (dodatki „na stole”), cena live z serwera.
- **Następny krok (Faza 3):** „Restaurant Viewfinder” jako nadbudowa — jedna scena, nawigacja gestem (opis w canvasie audytowym).
- **Film pięcio-scenowy:** świadomie **odłożony** do fazy 5+ — kontrola ryzyka i kosztu.

**Notatki:** Spójne z `15_KIERUNEK_ONLINE.md` (decyzja Damiana, 2026-04-18). Pokazuje dojrzałość roadmapy, nie feature creep na slajdzie.

---

## SLIDE 7 — Rynek i timing

**Rynek:** gastro w Polsce i UE — cyfryzacja POS i delivery już „jest”; następny poziom to **margines**, **marka własna** i **operacyjna spójność** (magazyn ↔ menu ↔ kanał).

- Wzrost kosztów surowców → presja na **food cost w czasie rzeczywistym** (receptury, stany, AVCO w warstwie magazynu).
- Presja na **kanał własny** obok agregatorów → potrzeba storefrontu, który nie wyglada jak klon szablonu.
- Regulacje i compliance (np. KSeF w ekosystemie — prace opisane w sesjach 2026-05-11) → wartość „poważnego” backoffice.

**Notatki:** Nie podawaj tutaj liczb bez źródeł zewnętrznych — do uzupełnienia po researchu GUS / Euromonitor. Slajd ma strukturę logiczną.

---

## SLIDE 8 — Model biznesowy (szkielet do uzupełnienia liczbami)

**B2B SaaS — abonament per lokal / tier funkcji + opcjonalnie usługi wdrożeniowe**

- **Core:** subskrypcja modułów (POS, online, magazyn, logistyka).
- **Upsell:** onboarding menu i scen, integracje, multi-lokal (Admin Hub — faza późniejsza w `00_PAMIEC_SYSTEMU.md`).
- **Kanał:** bezpośrednio do sieci pizzy / casual dining oraz partnerzy wdrożeniowi.

**Notatki:** Wstaw MRR cel, średni kontrakt (ACV), CAC jeśli macie — Spark oczekuje konkretów w excelu, slajd tylko agreguje.

---

## SLIDE 9 — Status projektu (uczciwie, z dokumentacji)

**Produkt w zaawansowanym stadium budowy — nie „slajd na idei”**

- **Zbudowane:** szeroki zestaw modułów operacyjnych i backoffice (por. mapa w `02_ARCHITEKTURA.md`).
- **Online Studio / Director:** audyt wewnętrzny wskazuje ~**70%** gotowości silników sceny i narzędzi „Magic”; storefront ~**40%** — świadomy **krok unifikacji rendererów** już opisany i częściowo domknięty (wspólny `core/js/scene_renderer.js` w `15_KIERUNEK_ONLINE.md`).
- **Faza F (Counter + Living Table):** wymaga dedykowanej iteracji — jasno zapisane w `00_PAMIEC_SYSTEMU.md` jako następny duży kamień.
- **Proces jakości:** Konstytucja v5 — m.in. domknięcie kontraktu kod ↔ docs, audyt sesji AI (`_docs/sessions/`) — obniża ryzyko „dokumentacja kłamie”.

**Notatki:** Fundusze widzą ryzyko „forever beta” — ten slajd pokazuje **metryki gotowości** i **nazwane luki**, co buduje zaufanie.

---

## SLIDE 10 — Przewaga konkurencyjna (pitch w 30 s)

1. **Głębokość operacyjna + warstwa klienta w jednym repozytorium prawdy.**  
2. **Architektura pod skalę i audyt** (multi-tenant, silosy, brak „cen z frontu”).  
3. **Unikalny kierunek UX online** — „okno do restauracji”, nie kolejny grid (świadome ograniczenie „paint” w Prawie VII).  
4. **Stack wdrożeniowy przyjazny hostingom klasycznym** — realny argument dla SME.

---

## SLIDE 11 — Zespół (uzupełnij zdjęciami i 1 linią / osoba)

- **[Founder / CEO]** — wizja produktu, relacje z pierwszymi lokami.  
- **[CTO / Lead]** — architektura, bezpieczeństwo danych, jakość release.  
- **[Product / Gastro domain]** — procesy lokalu, szkolenia, acceptance.  

**Notatki:** Spark często patrzy na **komplementarność** zespołu (biz + tech + domena). Jeśli zespół jest smukły — podkreśl doradców / partnerów technologicznych.

---

## SLIDE 12 — Prośba o finansowanie / udział w programie (dostosuj do regulaminu Spark 3.0)

**Na co przeznaczymy wsparcie akceleratora**

- Dokończenie **Fazy F**: Counter + Living Table + spójność Director ↔ Storefront (najwyższy ROI dla pierwszych płatnych tenantów online).
- **Menu Studio Polish** — redukcja tarcia dla managera (łączenie modyfikatora z warstwą wizualną, auto-kompozycja scen — już częściowo wdrożone w M1/M2 według `15_KIERUNEK_ONLINE.md`).
- **Pierwsze wdrożenia pilotażowe** — success manager, migracja menu, szkolenie kuchni i kierowców.
- **Compliance i integracje** — kontynuacja ścieżek opisanych w dokumentacji (np. KSeF / rozliczenia — zgodnie z roadmapą produktu).

**Kwota / equity:** *do uzupełnienia wg wymogów programu i cap table.*

**Notatki:** Powiąż każdą pozycję budżetu z **milestonem mierzalnym** (np. „3 lokale na storefrontie Fazy 2”, „Harmony Score używany przez 80% nowych tenantów”).

---

## SLIDE 13 — Kamienie milowe (12–18 miesięcy)

| Okres | Cel (zgodny z docs) |
|------|---------------------|
| **0–3 mc** | Produkcja ścieżki B: Drzwi + Counter, pierwsze lokale pilotażowe, metryki konwersji zamówienia |
| **3–6 mc** | Faza 3: Viewfinder lub rozszerzenia Counter; rozważany **Admin Hub** (agregacja dla sieci) |
| **6–12 mc** | Skalowanie SaaS, integracje płatności / POS offline (ścieżka opisana w backlogu resilient POS) |
| **12+ mc** | Ewaluacja „pełnego filmu” scen (Faza 5+) przy potwierdzonym PMF online |

---

## SLIDE 14 — Ryzyka i mitygacja

| Ryzyko | Mitygacja (z praktyki projektu) |
|--------|----------------------------------|
| Złożoność produktu | Modułowość „klocki lego”, jeden silnik API per domena (`switch $action`) |
| Dryf docs vs kod | Prawo VIII Konstytucji — domknięcie kontraktu + sesje w `_docs/sessions/` |
| Performance mobile przy bogatej scenie | Świadoma rezygnacja z ciężkiego SPA w produkcji; etapowe dosyłanie assetów (strategie opisane w canvasie) |
| Zależność od kilku dużych tenantów | Multi-tenant od początku; roadmapa Admin Hub pod sieci |

---

## SLIDE 15 — Zamknięcie

**SliceHub Enterprise — od „kasy” do systemu, w którym każda złotówka ma źródło w danych, a klient widzi restaurację, nie tabelkę SKU.**

- **Kontakt:** *[email / telefon / strona]*  
- **Załączniki do wniosku:** one-pager, architektura (1 strona), lista modułów, roadmapa Fazy 2–3.

---

## ANEKS A — „Evidence pack” (linki do plików w repo)

| Twierdzenie w pitchu | Dowód w repo |
|---------------------|--------------|
| Brak płaskiej ceny, macierz kanałów | `_docs/01_KONSTYTUCJA.md` §1 |
| Bliźniak magazynowy, modyfikatory | `_docs/01_KONSTYTUCJA.md` §2 |
| Multi-tenant, `tenant_id` | `_docs/01_KONSTYTUCJA.md` / `.cursorrules` §2 |
| Lista modułów UI i API | `_docs/02_ARCHITEKTURA.md` |
| North Star online + fazy A–G | `_docs/00_PAMIEC_SYSTEMU.md` |
| Decyzja drogi B, MVP 6–8 tyg. | `_docs/15_KIERUNEK_ONLINE.md` §0–1 |
| Audyt % Director / storefront, 3 drogi | `_docs/canvasy/SliceHub Three Directions.md` |
| Silosy DB, zakaz JOIN po ID między silosami | `_docs/04_BAZA_DANYCH.md` (w razie pytań technicznych z komisji) |

---

## ANEKS B — Checklist przed wysłaniem wniosku

- [ ] Uzupełnione dane rynkowe (cytowane źródła).  
- [ ] Cap table i prośba o finansowanie zgodna z regulaminem Spark 3.0.  
- [ ] Slajd „trac­tion”: liczby (lokale, MRR, piloci, LOI).  
- [ ] Slajd „competition” z macierzą funkcji (SliceHub vs 2–3 alternatywy).  
- [ ] One-pager PDF w identyfikacji wizualnej marki.  
- [ ] Wideo 60–90 s (opcjonalnie): Drzwi + Counter z demo tenant.

---

*Dokument roboczy — treść merytoryczna zsynchronizowana z dokumentacją SliceHub Enterprise na dzień 2026-05-11.*
