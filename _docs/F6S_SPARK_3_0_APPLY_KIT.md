# F6S · Spark 3.0 Accelerator — zestaw pod wniosek (Apply Kit)

> **Cel:** Maksymalna liczba konkretnych „haków” do formularza + gotowe skrypty nagrań (≤60 s) + checklista decka.  
> **Źródła merytoryczne:** `_docs/00_PAMIEC_SYSTEMU.md`, `_docs/01_KONSTYTUCJA.md`, `_docs/02_ARCHITEKTURA.md`, `_docs/12_INTEGRATION_ADAPTERS.md`, `_docs/15_KIERUNEK_ONLINE.md`, `_docs/19_LOGISTYKA_I_BEZPIECZENSTWO.md`, `_docs/DEPLOYMENT_HOSTING.md`.

---

## 1. Stan repo — wideo

| Szukane | Wynik |
|--------|--------|
| Pliki `.mp4`, `.webm`, `.mov` w workspace | **Brak** — nie ma wygenerowanych nagrań w repozytorium. |
| Pitch PDF/PPTX w repo | **Brak** — deck trzeba wyeksportować zewnętrznie (np. Google Slides → PDF, **≤ 30 MB**). |

**Wniosek:** Product Video i Team Video = **nagranie ekranu + upload na YouTube lub Vimeo** (publiczne lub **unlisted**, bez hasła), potem wklejasz URL w F6S.

---

## 2. Mapowanie formularza F6S → co wpisać (SliceHub)

### Social / TikTok / App Store

- **TikTok / social:** Jeśli nie prowadzisz — zostaw puste albo jeden profil (LinkedIn firmy / X). Akceleratory częściej patrzą na **produkt** niż na TikToka SaaS B2B.  
- **Apple App Store / Google Play:** Driver i Kelner to **PWA w przeglądarce** (`modules/driver_app/`, `modules/waiter/`), nie natywne store’y — uczciwie: *„Mobile: PWA (install from browser); native store listing: N/A / planned”* albo pole puste, żeby nie wyglądało na obietnicę bez linku.

### Product Video (≤ 1 min, YouTube/Vimeo)

Pokaż **działający produkt** na **jednym continuous demo** (najlepiej po `seed_demo_all.php` — `_docs/DEPLOYMENT_HOSTING.md`).  
Skrypt dokładny: **§4** poniżej.

### Team Video (≤ 1 min)

Kto jest kim, dlaczego gastronomia, co budujecie, jedna linia traction (np. „działający stack modułowy w produkcyjnej architekturze”). Szablon: **§5**.

### Pitch deck (PDF / PPT / PPTX, max 30 MB)

Zawartość slajdowa: użyj struktury z wcześniejszej sesji (problem → OS gastronomii → moduły → perły: omnichannel + CartEngine + magazyn + logistyka + integracje + online vision + konstytucja v5).  
**Checklista:** §6.

### Finanse (Funding stage, amount, valuation, raised, investors)

Wypełniasz **tylko prawdziwymi danymi** — nie ma ich w repo. Sugestia copy:

- **Raised to date:** jeśli 0 → `0` + w polu inwestorów: *„Bootstrap; grants: [lista jeśli były].”*  
- **Investors & equity holders:** uczciwa lista (wspólnicy, SAFE, pożyczki, dotacje).

---

## 3. „Czym się chwalić” — bullet list do opisu firmy / decka (skrót)

Skopiuj wybrane punkty do pola „Company / Product description” lub decka:

1. **Enterprise OS gastronomii**, nie „kolejny POS” — wielomodułowy, multi-tenant (`00_PAMIEC_SYSTEMU.md`).  
2. **Macierz cen omnichannel** — brak płaskiej ceny; `sh_price_tiers` + kanały (POS / takeaway / delivery) (`01_KONSTYTUCJA.md` Prawo I).  
3. **Serwerowa prawda o koszyku** — front wysyła SKU + qty; **`CartEngine::calculate()`** zawsze na serwerze (`01_KONSTYTUCJA.md` Prawo IV).  
4. **Cyfrowy bliźniak magazynu** — modyfikatory z `linked_warehouse_sku`, waste, half-and-half; food cost w Studio (`01_KONSTYTUCJA.md` Prawo II; `15_KIERUNEK_ONLINE.md` M1).  
5. **Temporalne menu** — Draft / Live / Archived, `valid_from` / `valid_to` (`01_KONSTYTUCJA.md` Prawo III).  
6. **Logistyka:** jeden silnik `api/courses/engine.php`, model **3 filarów** stanu; **Emergency Recall** (wartownik `heading = -999`); **Payment Lock** (`19_LOGISTYKA_I_BEZPIECZENSTWO.md`).  
7. **Integracje:** `sh_event_outbox` → webhooki **+** adaptery (Papu, Dotykačka, GastroSoft) z retry/DLQ (`12_INTEGRATION_ADAPTERS.md`).  
8. **Storefront / Director:** decyzja produktowa **Droga B (Counter + Drzwi)**; wspólny renderer `core/js/scene_renderer.js` (SSOT WYSIWYG ↔ klient) (`15_KIERUNEK_ONLINE.md`).  
9. **Dojrzałość inżynierska:** Konstytucja **v5** (10 praw), drift guard (Prawo VIII), freeze discipline (Prawo IX), audyt sesji AI (Prawo X) (`01_KONSTYTUCJA.md`, `00_PAMIEC_SYSTEMU.md`).  
10. **Zero-Reload Runtime** na produkcji — Vanilla JS + PHP 8 + MariaDB; hosting bez Node w runtime (`00_PAMIEC_SYSTEMU.md`).

---

## 4. Product Video — storyboard 55–60 s (nagranie ekranu)

**Przygotowanie (przed REC):**

1. Środowisko z seedem: `seed_demo_all.php` (tenant demo, menu, zamówienia) — `DEPLOYMENT_HOSTING.md` Krok 6.  
2. Rozdzielczość: **1920×1080**, zoom przeglądarki ~100%, wyłącz powiadomienia.  
3. **Nie pokazuj** haseł/PINów w zbliżeniu dłużej niż 1 s (PINy są w dokumentacji seeda — na produkcji i tak do rotacji).

| Sek | Widok | Akcja / voiceover (PL, krótko) |
|-----|--------|--------------------------------|
| 0–5 | Hub lub logo + moduły | „SliceHub Enterprise — jeden system na całą restaurację.” |
| 5–15 | `modules/online/index.html?tenant=1` — drzwi / wejście / menu | „Klient: sklep online jako doświadczenie, nie sztywny katalog.” |
| 15–28 | POS — `waiter1` / PIN z seeda — dodaj pozycję do koszyka | „Kasa i obsługa: koszyk zawsze liczony na serwerze.” |
| 28–38 | Studio lub fragment z cenami kanałów / macierzą | „Ceny omnichannel: sala, wynos, dostawa — osobno.” |
| 38–48 | Dispatcher **lub** mapa kursów / lista kierowców (jeśli masz dane dostaw) | „Logistyka: dispatch, śledzenie, bezpieczeństwo operacji.” |
| 48–55 | Settings (integracje) **lub** slajd z jednym zdaniem o outbox + adapterach | „Integracje z sieciami i POS zewnętrznymi — ten sam bus zdarzeń.” |
| 55–60 | Ekran z napisem końcowym | „SliceHub — system operacyjny gastronomii.” |

**Eksport:** OBS / QuickTime / Windows Clipchamp → wrzuć na **YouTube Unlisted** → wklej URL.

---

## 5. Team Video — szablon 55–60 s

| Sek | Kto | Treść (dostosuj imiona) |
|-----|-----|-------------------------|
| 0–8 | Founder | „Nazywam się [X], budujemy SliceHub w Polsce.” |
| 8–20 | Founder | „Problem: restauracje tracą marżę przez rozłączone kasy, magazyn i kanały sprzedaży.” |
| 20–35 | CTO / lead dev (jeśli jest) | „Nasza odpowiedź: jedna platforma multi-tenant, serwerowa kalkulacja, magazyn spięty z menu.” |
| 35–50 | Founder | „Mamy działające moduły: POS, online, kuchnia, dostawy, magazyn, integracje — opisane w architekturze produktu.” |
| 50–60 | Oba / zbiórka | „Spark 3.0 przyspieszy [Counter+Drzwi / pilotaże / GTM — jedna linia]. Dziękujemy.” |

Nagraj **webcam + ekran** (PiP) albo tylko ekran z głosem — oba są OK dla F6S.

---

## 6. Checklista Pitch Deck (PDF ≤ 30 MB)

- [ ] Slajd tytułowy: **SliceHub Enterprise** + tagline OS gastronomii.  
- [ ] Problem: fragmentacja narzędzi + omnichannel.  
- [ ] Rozwiązanie: jedna platforma + lista modułów z `02_ARCHITEKTURA.md`.  
- [ ] **3 perły techniczne:** CartEngine + macierz cen + multi-tenant barrier.  
- [ ] **3 perły operacyjne:** Recall + Payment Lock + 3-filarowy stan (`19_...`).  
- [ ] Integracje: diagram outbox + adaptery (`12_...`).  
- [ ] Online: Droga B + scene_renderer SSOT (`15_...`).  
- [ ] Moat: Konstytucja v5 (krótko, bez zanudzania).  
- [ ] Roadmapa: Faza F / pilotaże (realnie z `00_PAMIEC`).  
- [ ] Team + kontakt.  
- [ ] Ask: kwota / użytek (Twoje dane).  
- [ ] Eksport: **PDF**, kompresja obrazków jeśli >30 MB.

---

## 7. Kolejne kroki (checklist Ty)

1. [ ] Nagraj Product Video wg §4 → YouTube/Vimeo Unlisted.  
2. [ ] Nagraj Team Video wg §5.  
3. [ ] Złóż deck → PDF < 30 MB.  
4. [ ] Uzupełnij finanse i inwestorów prawdą.  
5. [ ] Opcjonalnie: strona www / landing z jednym zdaniem + zrzut ekranu Hub — wzmacnia wiarygodność.

---

*Dokument roboczy pod wniosek F6S; nie zastępuje regulaminu programu Spark 3.0.*
