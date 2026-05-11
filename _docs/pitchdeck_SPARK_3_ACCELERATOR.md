# SliceHub Enterprise — Pitch Deck (wniosek Spark 3.0 Accelerator)

> **Źródła wewnętrzne:** `_docs/00_PAMIEC_SYSTEMU.md`, `_docs/15_KIERUNEK_ONLINE.md`, `_docs/canvasy/SliceHub Three Directions.md`, `_docs/02_ARCHITEKTURA.md`, `_docs/01_KONSTYTUCJA.md` (v5).  
> **Cel:** jeden plik = komplet slajdów (tytuł + treść + notatki prelegenta). Przenieś do Google Slides / PowerPoint / Canva — **1 slajd ≈ 1 sekcja H2** poniżej.

---

## Jak użyć w formularzu wniosku

- W polach „opis projektu / innowacyjność / rynek” kopiuj **akapity ze slajdów 3–6 i 9–11**.  
- W „osiągnięcia / TRL” — **slajd 8 (Dowód techniczny)**.  
- W „zespół” — **slajd 14** (uzupełnij imionami i linkami LinkedIn).  
- **Nie kopiuj nazw tabel SQL** do pitchu dla jury laika — zostaw je w załączniku technicznym.

---

# SLIDE 1 — Okładka

**Tytuł:** SliceHub Enterprise  
**Podtytuł:** System operacyjny gastronomii — od magazynu po kinowy storefront  
**Tagline (jedna linia):** *„Okno do restauracji, nie siatka produktów.”* (`00_PAMIEC_SYSTEMU` — Prawo VII)

**Notatki:** Start od emocji: pizza jako scena, nie wiersz w Excelu. My budujemy warstwę, której brakuje globalnym graczom delivery i lokalnym POS-om jednocześnie.

---

# SLIDE 2 — Agenda (opcjonalnie, 30 s)

1. Problem rynku  
2. Nasza odpowiedź (produkt)  
3. Innowacja vs. konkurencja  
4. Architektura = przewaga utrzymywalna  
5. Status / dowód techniczny  
6. Rynek i model  
7. Roadmapa (fazy B → Viewfinder)  
8. Zespół i prosba o wsparcie

---

# SLIDE 3 — Problem

**Nagłówek:** Gastronomia utknęła między dwoma światami

| Świat A | Świat B |
|--------|--------|
| Aplikacje zamówień „jak sklep internetowy” — siatka SKU, zero teatru marki | Systemy POS / backoffice — ciężkie, moduły niespójne, food cost rozłączony od tego, co klient widzi online |
| Agregatory (Glovo, Uber Eats) — marka lokalu ginie | „AI w menu” często = kolejny filtr — nie przewaga produktowa |

**Teza:** Lokale tracą marżę (magazyn ≠ menu), tożsamość wizualną (grid ≠ restauracja) i czas managera na przeklikiwanie między narzędziami.

**Źródło koncepcji:** `_docs/00_PAMIEC_SYSTEMU.md` (wizja: manager = studio kinowe, klient = teatr fotograficzny).

---

# SLIDE 4 — Insight produktowy

**Nagłówek:** Ten sam produkt musi wyglądać jak **jedno zdjęcie** — nie jak kolaż warstw

- Klient ocenia pizzę oczami z Instagrama; restauracja musi **warstwować składniki** z fizyczną spójnością światła, LUT, perspektywy.  
- Manager nie może zaczynać od pustego płótna — potrzebuje **„THE button”** (auto-kompozycja) i metryk jakości, nie kolejnych suwaków.

**Cytat zasad (wewnętrzny North Star):** *„Harmony Score nigdy nie kłamie”* — kompletność, dopracowanie, spójność jako liczby + akcje (`00_PAMIEC_SYSTEMU`).

---

# SLIDE 5 — Rozwiązanie: SliceHub Enterprise OS

**Nagłówek:** Jedna platforma wielonajemcza — **operacje + magazyn + doświadczenie online**

**Dla managera (Studio):**

- Menu Studio (CRUD, macierz cen omnichannel POS / Takeaway / Delivery), receptury BOM → magazyn.  
- **Online Studio / Director** — scena dania, Magic (Bake, Companions, Enhance, ColorGrade, Relight, Dust), **Style Conductor** (całe menu w jednym stylu kinowym), Harmony Score.  
- Asset Studio jako **SSOT** biblioteki zdjęć (`api/assets/engine.php` — zgodnie z dokumentacją).

**Dla klienta (Storefront):**

- **Scena Drzwi** → **Counter / Living Table** (swipe dań, bottom sheet „Komponuj / Do stołu”, companions zostają przy zmianie pizzy) → checkout gościnny → **Track** z żywą linią czasu (ETA, kuchnia, kierowca).

**Źródło:** `_docs/15_KIERUNEK_ONLINE.md` (decyzja fazy: droga **B — Counter + Drzwi**; droga C Viewfinder jako naturalna faza 3).

---

# SLIDE 6 — Co już działa (de-risk)

**Nagłówek:** Nie slid „w zamyśle” — **kod i migracje**

- **Silosy danych + multi-tenant:** izolacja `tenant_id`, prefiksy `sh_` / `sys_` / `wh_`, mosty przez **SKU** (zgodnie z konstytucją — brak „łączenia po ID” między silosami).  
- **Silnik zamówień, event outbox, integracje** (webhooks, inbound callbacks, szyfrowanie sekretów).  
- **Logistyka:** unified `api/courses/engine.php` — dispatcher, driver PWA, payment lock, emergency recall.  
- **POS, KDS, Warehouse** (dokumenty PZ/RW/KOR/MM, wiele UI magazynowych), **Tables**, **Waiter**, **Settings**.  
- **Online / Studio:** wspólny renderer warstw `core/js/scene_renderer.js` (WYSIWYG Director ↔ Storefront 1:1 dla pizzy), pipeline realizmu domknięty w dokumencie fazy, fazy A–E roadmapy zamknięte; **Faza F** (Counter + Living Table) jako następny kamień milowy.

**Źródło:** `_docs/02_ARCHITEKTURA.md`, `_docs/15_KIERUNEK_ONLINE.md`, `_docs/canvasy/SliceHub Three Directions.md`.

---

# SLIDE 7 — Innowacja vs. „kolejny sklep”

**Nagłówek:** Prawo produktowe: **o krok przed referencją rynkową** (Domino's, NUV, EZ Pizza, Papa John's, WooFood, Apprication, agregatory)

| Innowacja | Dlaczego to nie „paint” |
|-----------|-------------------------|
| **Style Conductor** — jednym działaniem zmienia tożsamość wizualną całej kategorii (LUT, światło, companions, typografia), nie pojedynczy filtr | Bulk op zmienia **zawartość sceny**, nie parametr |
| **Harmony Score** — transparentny model + actionable outliers | Metryka steruje jakością, nie ozdobą |
| **Living Table + companions persist** | Zachowanie kontekstu „stół” przy swipe między daniami — inny mental model niż accordion SKU |
| **Zero-Reload Runtime** — Vanilla JS + PHP na produkcji | Deploy na typowym hostingu bez farmy Node; **niższy TCO** dla SME |

**Źródło:** `00_PAMIEC_SYSTEMU` — Prawo VII (zakres zawężony do online/studio w v5).

---

# SLIDE 8 — Architektura = moat

**Nagłówek:** Bliźniak cyfrowy magazynu spotyka teatr online

- **CartEngine na serwerze** — frontend nigdy nie wysyła cen ani totali; tylko SKU i ilości (`Prawo IV`).  
- **Macierz cenowa** per kanał (`sh_price_tiers`) — brak „jednej ceny na wszystko”.  
- **Temporal menu** — Draft / Live / Archive z `valid_from` / `valid_to`.  
- **Receptury** łączą menu z magazynem po SKU — food cost i alerty są częścią tej samej prawdy co wizualizacja składnika.

**Komunikat dla inwestora:** Trudno to skopiować „feature flagiem” w istniejącym POS — wymaga **spójnego modelu danych od dnia pierwszego**.

---

# SLIDE 9 — Rynek (ramka strategiczna)

**Segment początkowy:** Sieci i multi-lokale pizza / fast-casual / dark kitchen w Europie Środkowej potrzebujące **własnego kanału online** przy jednoczesnym ciśnieniu marży i kosztów surowca.

**Rozszerzenie:** Moduły uniwersalne (magazyn, kadry, stoliki, logistyka) — architektura nie jest zamknięta na jeden vertical copywritingu „tylko pizza”; pizza jest **pierwszym nośnikiem wizualnym** najtrudniejszym technicznie.

**Konkurencja:** Agregatory (utrata marki), zestawy POS+online (średni UX, brak warstwowego realizmu), custom dev (koszt, brak product discipline).

---

# SLIDE 10 — Model biznesowy (szkielet do doprecyzowania liczbami)

**Warianty do walidacji we Spark:**

- SaaS per lokal / tier funkcji (Studio + liczba seatów POS / kierowców).  
- Setup fee dla onboarding scen (Director + sesja zdjęciowa wg briefu `05_INSTRUKCJA_FOTO_UPLOAD.md`).  
- Opcjonalnie: revenue share na kanał własny online (niższa prowizja niż agregatory = argument sprzedażowy).

**Uwaga:** W formularzu **wstaw własne założenia ARPU / CAC** po danych wewnętrznych — tutaj celowo bez wymyślonych liczb.

---

# SLIDE 11 — Roadmapa (zgodna z dokumentacją)

| Faza | Opis | Status (dokumentacja) |
|------|------|------------------------|
| **A–E** | SSOT assetów, Harmony Score, ustawienia sklepu, Track | DONE (2026-04-19) |
| **F** | Counter + Living Table (teatr daniowy, bottom sheet, companions) | Aktywny następny krok |
| **G3 (AI Jobs)** | Kolejka `sh_ai_jobs` + worker (Replicate/Flux) | Odłożone do fazy AI |
| **Faza 3** | Restaurant Viewfinder (swipe w 4 kierunkach) + **Admin Hub** | Zaplanowane po walidacji B |
| **Faza 5+** | Pełny „Film” pięciu scen | Świadomie odłożone — wysokie ryzyko koszt/performance |

**Źródło:** `_docs/15_KIERUNEK_ONLINE.md`, `SliceHub Three Directions.md` (drogi A/B/C).

---

# SLIDE 12 — Dlaczego Spark 3.0 Accelerator

**Nagłówek:** Czego potrzebujemy od programu

- **Kapitał / grant** na domknięcie **Fazy F** i pierwsze wdrożenia pilotażowe u operatorów z prawdziwym ruchem.  
- **Mentoring produktowy** — utrzymanie dyscypliny „innowacja albo nic” vs. pokusa dodawania sliderów.  
- **Sieć** — intro do sieci franczyzowych i dostawców surowca (synergia z modułem magazynu).  
- **Widoczność** — case study „od bliźniaka magazynu do kinowego storefrontu” jako referencja dla kolejnych rund.

*(Dostosuj nagłówek slajdu do oficjalnej nazwy programu w Twoim wniosku.)*

---

# SLIDE 13 — Ryzyka i mitygacja (credibility)

| Ryzyko | Mitygacja |
|--------|-----------|
| Scope creep „filmowy” | Formalna decyzja fazy: MVP = **B**, nie A (`15_KIERUNEK_ONLINE`) |
| Dryf WYSIWYG vs. klient | **Wspólny scene_renderer** — już wdrożony |
| Złożoność multi-tenant | Konstytucja: każde SQL z `tenant_id`; audyt bezpieczeństwa w dokumentacji |
| Offline POS / konflikty sync | **FREEZE NOTICE** — świadome zamrożenie do czasu ACL (`00_PAMIEC_SYSTEMU`) |

---

# SLIDE 14 — Zespół

**Nagłówek:** Ludzie, którzy noszą architekturę w głowie

- **Founder / Product Owner** — (imię, rola, 1 zdanie track record).  
- **Lead engineering** — PHP + frontend performance, znajomość domeny gastronomicznej.  
- **Design / scenografia** — (opcjonalnie — brief foto + Director).  
- **Advisorzy** — (operator lokalu, ekspert logistyki last mile).

**Placeholder:** Uzupełnij biogramy — dokumentacja repo nie zawiera CV zespołu.

---

# SLIDE 15 — Prośba (The Ask)

**Jedno zdanie:** Środki i partnerstwo na **ukończenie immersive storefrontu (Faza F)** + **3 piloty produkcyjne** z mierzalnym wzrostem udziału zamówień własnych kanałem vs. agregatorem.

**KPI na koniec programu (propozycja — skaluj do budżetu):**

- Faza F wdrożona na ≥1 produkcyjnym tenantcie.  
- Harmony Score + Style Conductor użyte w produkcji przez managerów bez szkolenia > 2 h.  
- Udokumentowany wzrost konwersji „doorway → koszyk” vs. baseline statyczny layout.

---

# SLIDE 16 — Zamknięcie

**Tytuł:** SliceHub — **Enterprise OS dla gastronomii, nie kolejny sklep**  
**Kontakt:** (email · strona · kalendarz demo)  
**QR:** link do 2-min nagrania Director + storefront (przygotuj osobno).

---

## Załącznik A — „Elevator pitch” (90 sekund, do nagrania wideo w wniosku)

„SliceHub to system operacyjny dla restauracji, które już mają magazyn i koszty pod kontrolą w teorii — ale w praktyce rozłączają się od tego, co widzi klient online. Łączymy bliźniaka magazynu z warstwowym storefrontem: manager pracuje w Studio jak w kinowym zapleczu — Style Conductor, Harmony Score, wspólny renderer z klientem. Klient nie widzi siatki SKU — widzi drzwi lokalu, stół, żywe danie. Technicznie: PHP, MariaDB, Vanilla JS — zero Node na produkcji, żeby polski SME mógł to hostować bez DevOps z Doliny Krzemowej. Jesteśmy po domknięciu fundamentów; teraz domykamy Counter z Living Table i szukamy partnera na pierwsze skalowalne wdrożenia.”

---

## Załącznik B — Słownik (do FAQ jury)

- **Living Table** — stół z daniami i dodatkami; companions (np. frytki) **zostają** przy zmianie pizzy na swipe.  
- **Director** — edytor sceny w `modules/online_studio`.  
- **Droga B** — MVP: Drzwi + Counter (nie pełny pięcioscenowy „Film”).  
- **SKU / ascii_key** — klucze znakowe łączące silosy danych (menu, magazyn, wizualizacje).

---

## Pliki PPTX / PDF (gotowe w repo)

| Plik | Opis |
|------|------|
| `_docs/pitchdeck_SPARK_3_output/SliceHub_Spark3_Pitch.pptx` | Prezentacja 16:9 (slajdy + mocki PWA z repo) |
| `_docs/pitchdeck_SPARK_3_output/SliceHub_Spark3_Pitch.pdf` | Ta sama treść w PDF (A4, DejaVu, polskie znaki) |
| `_docs/pitchdeck_SPARK_3_assets/png/*.png` | Rastery mocków SVG (`modules/online/screenshots/`, `modules/pos/screenshots/`) — CairoSVG 2× |

**Regeneracja:** `pip install python-pptx pillow cairosvg fpdf2` następnie `python3 scripts/build_spark_pitchdeck.py`.

**Wideo / screen recording:** w repozytorium **nie ma** plików `.mp4` / `.webm`; w dokumentacji plan demo offline: `_docs/17_OFFLINE_POS_BACKLOG.md` (P8). Do wniosku możesz dołożyć nagranie lokalnie po `seed_demo_all.php` (instrukcja: `_docs/DEPLOYMENT_HOSTING.md`).

---

*Dokument roboczy dla wniosku. Ostatnia synchronizacja treści z repo: 2026-05-11.*
