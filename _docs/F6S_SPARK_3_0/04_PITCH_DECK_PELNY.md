# Pitch Deck — Spark 3.0 / SliceHub Enterprise (pełna treść slajdów)

**Instrukcja:** skopiuj każdy slajd do Google Slides / PowerPoint. Tło: ciemne + akcent (np. `#0f172a` + `#f97316`). Font: Inter / DM Sans.  
**Eksport do F6S:** PDF, **< 30 MB** (kompresja obrazów).  
**Źródła merytoryczne:** `_docs/00_PAMIEC_SYSTEMU.md`, `01_KONSTYTUCJA.md`, `02_ARCHITEKTURA.md`, `12_INTEGRATION_ADAPTERS.md`, `15_KIERUNEK_ONLINE.md`, `19_LOGISTYKA_I_BEZPIECZENSTWO.md`.

---

### Slajd 1 — Tytuł

**SliceHub Enterprise**  
*System operacyjny gastronomii*

Multi-tenant · Omnichannel · Magazyn · Dostawy · Integracje

`[LOGO]` · `[slicehub.pro]`

*Notatka:* „Nie start-up od zera — architektura opisana i utrzymywana jak konstytucja produktu.”

---

### Slajd 2 — Problem

**Gastronomia utyła w narzędziach**

- Kasa, sklep online, magazyn i dostawy to często **osobne „wyspy”** — dane się rozjeżdżają.  
- **Cena** ma być inna na sali, na wynos i w dostawie — a systemy nadal myślą jedną kolumną `price`.  
- **Food cost** żyje w Excelu, menu w panelu — **brak bliźniaka magazynu** w czasie rzeczywistym.  
- Sieci w CEE mają **mieszany POS** — integracje są kosztowne i kruche.

---

### Slajd 3 — Nasza teza

**Jedna platforma = jedna prawda operacyjna**

SliceHub to **OS restauracji**: od pierwszego ekranu klienta, przez realizację w lokalu i kuchni, po magazyn i flotę — **jeden model danych**, twarde reguły bezpieczeństwa finansowego i tenantowego.

---

### Slajd 4 — Dla kogo (ICP)

- Sieci **pizza / QSR / dark kitchen** z wieloma kanałami sprzedaży.  
- Operatorzy, którzy **płacą marżą** za chaos narzędzi, a nie za brak „kolejnego ładnego UI”.  
- Start: **Polska / CEE** — blisko heterogenicznego krajobrazu POS.

---

### Slajd 5 — Produkt: mapa modułów

| Warstwa | Moduły (skrót) |
|--------|----------------|
| Klient | Online (storefront), śledzenie zamówienia |
| Backoffice | Studio menu, warstwy wizualne / Director, ustawienia sklepu |
| Sala | POS, Tables, Waiter |
| Kuchnia | KDS |
| Logistyka | Dispatcher, Driver PWA |
| Zaplecze | Warehouse, Settings (integracje, webhooks) |
| Start | Hub |

*Źródło szczegółów:* `_docs/02_ARCHITEKTURA.md`

---

### Slajd 6 — Perła: omnichannel + koszyk

- **Brak płaskiej ceny** — macierz `sh_price_tiers` z kanałami (m.in. sala / wynos / dostawa).  
- **Front nie wysyła cen ani totali** — tylko SKU i ilości.  
- **`CartEngine::calculate()`** — jedyna autorytatywna kalkulacja koszyka.

*Konstytucja: Prawo I + IV*

---

### Slajd 7 — Perła: bliźniak magazynowy

- Modyfikatory z wpływem na surowce → **`linked_warehouse_sku`** + **`linked_quantity`**.  
- Waste, **half-and-half** × 0.5, receptury BOM menu → magazyn.  
- W Studio: **podgląd kosztu surowca** i stanów w kontekście recept (wdrożenia opisane przy Menu Studio).

*Konstytucja: Prawo II*

---

### Slajd 8 — Perła: czwarty wymiar menu

- Statusy: Draft / Live / Archived.  
- **`valid_from` / `valid_to`** — publikacja w czasie bez kasowania historii.  
- Soft delete zamiast utraty śladu audytowego.

*Konstytucja: Prawo III*

---

### Slajd 9 — Logistyka i bezpieczeństwo operacyjne

- Jeden silnik: **`api/courses/engine.php`**.  
- **Trzy filary:** status zamówienia × status płatności × status dostawy.  
- **Payment Lock** — brak „dostarczono” bez rozliczenia, gdy wymagane.  
- **Emergency Recall** — sygnał dla kierowcy z pełnym UI awaryjnym (overlay, haptyka).

*Dokumentacja:* `_docs/19_LOGISTYKA_I_BEZPIECZENSTWO.md`

---

### Slajd 10 — Integracje pod rzeczywisty rynek

```
sh_event_outbox
   ├─► worker_webhooks  → dowolny HTTP
   └─► worker_integrations → Papu · Dotykačka · GastroSoft …
```

- Ten sam strumień zdarzeń, **osobne ścieżki dostawy** i retry/DLQ.  
- Adapter per provider — **kształt payloadu, auth, semantyka odpowiedzi**.

*Dokumentacja:* `_docs/12_INTEGRATION_ADAPTERS.md`

---

### Slajd 11 — Storefront: droga produktowa

- Wizja: **„okno do restauracji”**, nie sztywny grid SKU.  
- Decyzja **Droga B:** **Counter + Drzwi** (MVP z leveragem Directora).  
- **Wspólny `scene_renderer.js`** — SSOT między edytorem a sklepem klienta.

*Dokumentacja:* `_docs/15_KIERUNEK_ONLINE.md`

---

### Slajd 12 — Stack i ekonomia wdrożenia

- **Vanilla JS + PHP 8 + MariaDB** — produkcja bez Node w runtime.  
- Niższy koszt hostingu vs stack „npm na serwerze”.  
- **JSON API**, moduły jako **klocki** pod włączaniem per tenant.

---

### Slajd 13 — Moat organizacyjno-techniczny

- **Konstytucja v5** — 10 praw (m.in. drift guard docs↔kod, datowane freeze, audyt sesji).  
- **Multi-tenant** jako kultura, nie dopisek.  
- **Silosy + SKU** — świadome granice domen.

---

### Slajd 14 — Roadmapa (uczciwie)

- Domknięcie **Counter + Living Table** (front klienta).  
- Pilotaże z **playbookiem wdrożenia** i twardym QA ścieżek krytycznych.  
- Rozwój integracji pod kolejnych operatorów CEE.  
- *Offline-first POS:* zamrożony fragment — **nie sprzedawamy jako gotowy**; komunikujemy jako plan z datowanym review.

---

### Slajd 15 — Team

`[Zdjęcie]`  
**[Imię Nazwisko]** — CEO / Product — [1 linia doświadczenia gastronomicznego lub technologicznego]  
**[Imię Nazwisko]** — CTO / Engineering — [1 linia]  
**[Advisorzy]** — jeśli są

`[email]` · `[telefon]` · `[slicehub.pro]`

---

### Slajd 16 — Ask

**Spark 3.0:** [mentoring + sieć + kwota jeśli dotyczy]

**Użycie środków (propozycja):** produkt (storefront), QA, 2–3 pilotaże, GTM.

**Dziękujemy.**

---

## Załącznik slajdowy (opcjonalny Slajd 0 — „Confidential”)

```
SliceHub Enterprise — materiał poufny. Rozpowszechnianie wyłącznie w ramach programu Spark 3.0. © [ROK] [NAZWA FIRMY]
```
