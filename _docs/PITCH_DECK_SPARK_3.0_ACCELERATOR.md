# SliceHub Enterprise — Pitch Deck (wniosek: Startup Spark 3.0 Accelerator)

> **Gotowa prezentacja do wniosku (HTML → PDF):** [`presentations/spark-30-slicehub/index.html`](../presentations/spark-30-slicehub/index.html) — 22 slajdy merytoryczne + slajd instrukcji eksportu (ukryty przy druku). Druk: **Ctrl+P** → Zapisz jako PDF, format **A4 pionowo** (ustawienia w przeglądarce). Przed złożeniem: uzupełnij pola „uzupełnić", usuń lub pomiń ostatni slajd instrukcji.

> **Źródła merytoryczne w repo:** `_docs/00_PAMIEC_SYSTEMU.md`, `_docs/01_KONSTYTUCJA.md`, `_docs/02_ARCHITEKTURA.md`, `_docs/15_KIERUNEK_ONLINE.md`, `_docs/canvasy/SliceHub Three Directions.md`, `_docs/19_LOGISTYKA_I_BEZPIECZENSTWO.md` (logistyka).  
> **Uwaga prawna:** kryteria naboru Spark 3.0 weryfikuj na stronie PARP / operatora programu w aktualnej edycji — ten dokument to **szkielet narracji**, nie gotowy formularz urzędowy.

**Wersja:** 2026-05-11 · **Język prezentacji:** PL (slajdy można zduplikować EN dla tracku Go Global).

---

## Slajd 1 — Tytuł

**SliceHub Enterprise**  
*System operacyjny dla gastronomii: od magazynu i kuchni po „teatr zamówienia" online i flotę dostaw.*

**Wniosek:** Startup Spark 3.0 Accelerator  
**Kwota (rama programowa):** do ok. 400 000 zł (de minimis — potwierdź limity w regulaminie aktualnej edycji)  
**Lokalizacja / spółka:** [DO UZUPEŁNIENIA: nazwa, NIP, forma prawna]

---

## Slajd 2 — Problem (dlaczego rynek jest zły dziś)

1. **Rozjechane narzędzia:** POS, magazyn, zamówienia online, agregatory i logistyka często żyją w osobnych „silosach" — dane się rozjeżdżają, a food cost i compliance stają się sztuką obliczeń w arkuszu.  
2. **„Katalog SKU" zamiast marki:** witryna zamówień online u większości konkurentów to siatka produktów, a nie doświadczenie budujące rozpoznawalność lokalu.  
3. **Brak twardego mostu menu ↔ magazyn:** bez cyfrowego bliźniaka receptur i modyfikatorów menedżer nie widzi realnego wpływu promocji i zmian menu na stany i koszt surowca.  
4. **Logistyka i bezpieczeństwo rozliczeń:** dostawy wymagają spójnego silnika statusów, blokad płatności u kierowcy i narzędzi dla dyspozytora — inaczej rośnie ryzyko operacyjne i utargówka.

*(Narracja oparta o Prawa I–IV Konstytucji systemu: macierz cen omnichannel, bliźniak magazynowy, temporalna publikacja, walidacja serwerowa koszyka — patrz `_docs/01_KONSTYTUCJA.md`.)*

---

## Slajd 3 — Insight (co wiemy z budowy produktu)

**Gastronomia premium to opera na trzech scenach jednocześnie:**  
**A)** zaplecze (magazyn, receptury, koszt), **B)** front domowy (POS, sala, KDS), **C)** kanał zewnętrzny (online + dostawa + śledzenie).

Jeśli **A** i **B** nie rozmawiają z **C** w tym samym języku danych (SKU, klucze menu, tenant), skaluje się chaos — nie feature.

---

## Slajd 4 — Rozwiązanie (jednym zdaniem)

**SliceHub** to wielomodułowy, **wielonajemcowy** system klasy enterprise dla gastronomii: jedna baza prawdy dla menu, cen (per kanał), magazynu, zamówień, kuchni, stolików, **logistyki kursów** oraz **immersyjnego storefrontu** — z zasadą **„serwer prawda, klient propozycja"** (koszyk i ceny liczy backend).

*(Wizja produktu: `_docs/00_PAMIEC_SYSTEMU.md` — sekcja „Wizja celu" i „Czym jest SliceHub".)*

---

## Slajd 5 — Produkt: warstwa operacyjna (już zmapowana w architekturze)

| Moduł | Wartość dla lokalu | Odniesienie w repo |
|--------|-------------------|---------------------|
| **Menu Studio** | Macierz cen omnichannel (POS / Takeaway / Delivery), receptury BOM, modyfikatory z wpływem na magazyn | `modules/studio/`, `api/backoffice/api_menu_studio.php` |
| **POS / Tables / Waiter** | Obsługa sali i koszyka z walidacją serwerową | `modules/pos/`, `modules/tables/`, `modules/waiter/` |
| **KDS** | Przepływ kuchenny | `modules/kds/` |
| **Warehouse** | Dokumenty magazynowe (PZ, RW, INW, …), most **SKU** do warstwy `sh_` | `modules/warehouse/`, `api/warehouse/` |
| **Courses + Driver PWA + Dispatcher** | Kursy K{n}, przystanki L{n}, portfel kierowcy, **payment lock** przed „dostarczono", emergency recall | `api/courses/engine.php`, `_docs/02_ARCHITEKTURA.md` § Dispatcher/Driver |
| **Hub** | Centralny launcher dla personelu | `modules/hub/` |

**Przewaga architektoniczna:** silosy prefiksowe (`sh_` / `sys_` / `wh_`) i **mosty wyłącznie po kluczach znakowych** (`sku`, `ascii_key`) + **bariera `tenant_id`** w SQL — mniej ryzyka wycieku danych między pizzeriami przy skalowaniu SaaS.

---

## Slajd 6 — Produkt: warstwa „okna do restauracji" (differentiator)

**Storefront + Online Studio** — klient widzi **„teatr fotograficzny"**, nie sztywny grid; menedżer ma **Director's Suite** (warstwy sceny, LUT, kamery, Harmony Score, operacje masowe na stylu kategorii).

- **Harmony Score:** metryki liczbowe i actionable (nie dekoracja UI).  
- **Living Scene:** reakcja na kontekst (pora dnia, obciążenie, triggery — kierunek rozwoju).  
- **Magic Enhance:** punkt startu pracy, nie „puste płótno".

*(Prawo VII „Innowacja albo nic" — zakres modułów online: `_docs/01_KONSTYTUCJA.md`; plan Fazy 2 „Counter + Drzwi": `_docs/15_KIERUNEK_ONLINE.md`.)*

---

## Slajd 7 — Technologia i skalowalność (Manifest „Zero-Reload Runtime")

- **Runtime produkcji:** Vanilla JS (ES6+), PHP 8+, MariaDB — **bez** Node/React/Vue w runtime na hostingu.  
- **Deploy:** zwykłe pliki statyczne + PHP — przewidywalny hosting, krótszy TTFB operacji krytycznych niż w ciężkim SPA-frameworku.  
- **Dev tooling (opcjonalnie):** Vite/esbuild/TS → output commitowany do repo (zgodnie z Konstytucją v5).

**Dlaczego to ważne dla Spark / due diligence:** mniejsza powierzchnia ataku dependency-chains, łatwiejszy audyt, niższy próg wejścia dla integratorów i partnerów przemysłu.

---

## Slajd 8 — Trakcja i dowód wykonalności [DO UZUPEŁNIENIA]

**Wariant A — masz klientów:** liczba lokali, MRR/ARR, churn, NPS, case study (anonimizowane jeśli potrzeba).  
**Wariant B — pre-revenue:** pilot LOI, listy oczekujących, wyniki user testing, link do demo (hasłowane).

**Wewnętrzny dowód techniczny (repo):** domknięte pętle E2E dokumentowane w `_docs/sessions/` (np. zużycie magazynowe po akceptacji zamówienia — F1; KSeF/procurement — kolejne fazy). To buduje zaufanie komitetu merytorycznego.

---

## Slajd 9 — Model biznesowy [DO UZUPEŁNIENIA]

Propozycje do uzupełnienia przez zespół:

- **SaaS per lokal / per stanowisko** + moduły premium (Online Studio, fleet).  
- **Onboarding + migracja menu** (jednorazowo).  
- **Pilot przemysłowy** (slajd 11) jako etap przed pełnym MRR.

---

## Slajd 10 — Rynek i konkurencja

**Rynek:** SMB gastronomia w Polsce i CEE + łańcuchy regionalne potrzebujące **jednego OS** zamiast pięciu subskrypcji.

**Konkurencja (nazwane w docs jako punkt odniesienia jakości):** Domino's-class digital, NUV, EZ Pizza, Papa John's, ekosystemy agregatorów — SliceHub pozycjonuje się jako **„krok dalej"** w warstwie immersive online + ścisłej spójności operacyjnej, nie jako kolejny „prosty sklepik".

---

## Slajd 11 — Dopasowanie do ścieżek Spark 3.0 (do wyboru w formularzu)

| Ścieżka | Jak uzasadnić SliceHub |
|--------|-------------------------|
| **Industry / pilot z partnerem** | Wspólny pilot z siecią / HORECA / dystrybutorem: **magazyn + zamówienia + dostawy + storefront** na wybranym regionie — mierzalne KPI: czas obsługi, food cost, błędy zamówień, SLA dostaw. |
| **Sector agnostic / skalowanie** | Produkt modułowy — sprzedaż „klocków" (warehouse-only, courses-only) z roadmapą do pełnego OS. |
| **Go Global** | Architektura multi-tenant i runtime „lekkie stacki" ułatwają **lokalizację** i hosting międzynarodowy; storefront jako przewaga UX na rynkach nasyconych agregatorami. |

---

## Slajd 12 — Roadmapa (18–24 miesiące) — zsynchronizowana z dokumentacją

| Horyzont | Cel | Uzasadnienie |
|----------|-----|--------------|
| **0–6 mc** | Domknięcie **Counter + Drzwi** (Faza B), poler Menu Studio, dalsza unifikacja rendererów storefront ↔ director | `_docs/15_KIERUNEK_ONLINE.md` |
| **6–12 mc** | Rozszerzenie **Living Table** (persist companions), Apple/Google Pay w checkout (P1 w dok.), integracje powiadomień | Ten sam dokument + `_docs/00_PAMIEC_SYSTEMU.md` |
| **12–18 mc** | **Restaurant Viewfinder** (Faza 3 w strategii „three directions") jako nadbudowa po walidacji B | `_docs/canvasy/SliceHub Three Directions.md` |
| **Równolegle** | Odmrożenie / dokończenie **offline-first POS** po Anti-Corruption Layer (`worker_pos_fanout`) — zgodnie z FREEZE NOTICE | `_docs/00_PAMIEC_SYSTEMU.md` |

---

## Slajd 13 — Grant Spark: alokacja środków (propozycja szkieletowa)

**Całość:** do limitu de minimis w programie (np. ~400k zł — **zweryfikuj w regulaminie**).

| Kategoria | % / kwota | Co finansujemy |
|-----------|-----------|----------------|
| **R&D produktu** | ~45–55% | Faza Counter+Drzwi, testy E2E, performance SQL, bezpieczeństwo |
| **Zespół** | ~25–35% | Backend/frontend/product (etaty / B2B) |
| **Pilot industry** | ~10–15% | Integracja z partnerem, szkolenia, monitoring KPI |
| **Internacjonalizacja / compliance** | ~5–10% | EN storefront, dokumentacja RODO, przygotowanie pod certyfikacje |

---

## Slajd 14 — Ryzyka i mitygacja (transparentność dla komitetu)

| Ryzyko | Mitygacja |
|--------|-----------|
| Złożoność produktu (wiele modułów) | Sprzedaż modułowa + jasny **MVP per segment**; Prawo VIII — brak „papierowych" funkcji bez call-site |
| Zależność od key people | Dokumentacja `_docs/*`, sesje audytowe, konstytucja architektury dla onboardingu |
| Koszt utrzymania jakości UX online | Prawo VII — twardy filtr „paint vs innowacja"; Harmony Score jako kontrola jakości |
| Skalowanie bazy | Tenant barrier + silosy prefiksowe; zapytania parametryzowane |

---

## Slajd 15 — ESG / społeczny efekt (opcjonalny, krótki)

- **Mniejsze marnotrawstwo surowca** dzięki powiązaniu receptur z magazynem i realnym zużyciem (waste% w modelu).  
- **Bezpieczniejsza dostawa:** payment lock i narzędzia dyspozytora ograniczają rozjazd rozliczeń i konflikty z klientem.

---

## Slajd 16 — Zespół [DO UZUPEŁNIENIA]

| Osoba | Rola | Doświadczenie |
|-------|------|----------------|
| [Imię Nazwisko] | CEO / Produkt | [1–2 zdania] |
| […] | CTO / Lead backend | […] |
| […] | Design / Front | […] |

**Advisory / partnerzy przemysłu:** [jeśli są — silnie podbija Industry track]

---

## Slajd 17 — Call to action

**Szukamy:** grantu Spark 3.0 + **partnera pilotażowego** z branży gastronomicznej lub dystrybucji.  
**Proponujemy:** 6-miesięczny pilot z jasno zdefiniowanymi KPI i raportem końcowym.  
**Kontakt:** [email · telefon · strona WWW]

---

## Załącznik A — „Elevator pitch" (30 s)

SliceHub to enterprise’owy OS dla restauracji: jeden silnik dla menu, magazynu, kuchni, sali, dostaw i immersyjnego zamówienia online. Łączymy **cyfrowego bliźniaka kosztu** z **doświadczeniem klienta jak w kinie**, a technicznie trzymamy **prosty, audytowalny stack** (PHP, Vanilla JS, MariaDB) z twardą izolacją danych per tenant. Dzięki grantowi Spark domykamy fazę **Counter + Drzwi** i pilotaż przemysłowy z mierzalnym ROI.

---

## Załącznik B — Słowniczek na pytania komisji

- **Omnichannel price matrix:** brak pojedynczej ceny w wierszu dania — ceny w `sh_price_tiers` per kanał.  
- **CartEngine:** serwerowe przeliczenie koszyka; frontend nie wysyła totali.  
- **Payment lock:** kierowca nie zamyka dostawy bez rozliczenia płatności (gotówka/karta), o ile zamówienie nie jest prepaid.  
- **Harmony Score:** jakościowa metryka spójności sceny wizualnej — liczby, nie ozdoba.

---

*Koniec dokumentu Markdown — **wersja do złożenia z wnioskiem:** `presentations/spark-30-slicehub/index.html` (eksport PDF). Opcjonalnie: treść slajdów można przenieść do Google Slides i uzupełnić o zrzuty ekranu z produktu.*
