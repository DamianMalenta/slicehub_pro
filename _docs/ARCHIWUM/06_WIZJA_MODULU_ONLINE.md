# 06. WIZJA MODUŁU ONLINE — SliceHub Surface Storefront + Manager Studio

> ⚠️ **AKTUALIZACJA KIERUNKU (2026-04-18):** Kierunek Fazy 2 został zawężony do **drogi B — Realistyczny Counter + Drzwi**.
> **Autorytatywny dokument decyzyjny:** `_docs/15_KIERUNEK_ONLINE.md`. W razie konfliktu — wygrywa `15_`.
> Ten plik pozostaje szerszą wizją długoterminową (Fazy 3+ i 5+).

> **Cel dokumentu:** Pełna, zatwierdzona wizja systemu zamówień online SliceHub.
> Każda decyzja architektoniczna, każdy endpoint, każda zakładka edytora — opisane przed kodem.
> **Backend zaczynamy dopiero po Twojej akceptacji tej wizji.**

**Data:** 2026-04-16 (rev 2026-04-18)
**Źródła wizji:** rozmowy z Damianem (transkrypt), `00_PAMIEC_SYSTEMU.md`, `ustalenia.md`, audit konkurencji 2026.

---

## 0. JEDNO ZDANIE STRESZCZENIA

> **Online to fotograficzna powierzchnia gdzie produkty „materializują się" na oczach klienta — zarządzana z poziomu Studio jak mały film, nie jak menu PDF.**

Klient widzi półpizzę po lewej, interaktywne dodatki po prawej, modyfikatory pod spodem. Manager wgrywa dwa zdjęcia per składnik (warstwa + hero), kalibruje na żywo na realnym daniu, układa kolejność warstw, zarządza tłem powierzchni. Wszystko mobile-first, zero frameworków, zero przeładowań.

---

## 1. AUDIT KONKURENCJI — CO BIJEMY, CO ROBIMY INACZEJ

### 1.1 Benchmarki rynkowe (2026)

| Konkurent | Co robi dobrze | Co robi źle | Nasza odpowiedź |
|-----------|----------------|-------------|-----------------|
| **Domino's** | Pizza Tracker, Easy Orders (1-click reorder), 34M+ kombinacji, „tech company sells pizza" | Płaskie ceny, brak omnichannel, generic „topping list" UI | Mamy macierz cenową; tracker i Easy Orders **dorobimy** |
| **Papa John's** | „Better Ingredients" branding, Garlic Sauce ritual | Słaby builder, app „catch-up" do Domino's | Builder mamy lepszy (Surface) |
| **Pizza Hut** | +30% conversion po mobile-first redesign | Powolny, korporacyjny | Już startujemy mobile-first |
| **EZ Pizza** (SaaS) | Drag&drop, layer visualization, dynamic pricing | Generic UI bez tożsamości | Surface + dual-photo to nasz moat |
| **NUV POS** | Mixed&Matched (half/quarter/slice), 150+ modyfikatorów | POS-only, brak storefront SPA | Mamy POS + Online + Tables zintegrowane |
| **Apprication** | Topping na ćwiartki przez circular selector | Klikalny okrąg jest mylący | Mamy Half&Half horyzontalny — czytelniejsze |
| **WooCommerce Pizza** | Tania integracja | Gomułki HTML form, brak wizji | Nie konkurujemy — inny segment |

### 1.2 Twarde dane konwersji 2026

- **Średnia konwersja mobile food:** 1.8–2.8% (desktop 3.2–3.9%)
- **Cart abandonment:** 70% średnio
- **1s opóźnienia ładowania:** −7% do −20% konwersji
- **Apple/Google Pay:** +16% do +35% konwersji
- **Guest checkout:** must-have (rejestracja blokuje)
- **High-quality photo:** +24% add-to-cart

### 1.3 Co JUŻ mamy lepszego (moat technologiczny)

| Cecha | Konkurencja | SliceHub |
|-------|-------------|----------|
| **Bliźniak cyfrowy magazynu** | Brak — POS-y mają „flat inventory" | `WzEngine` z waste% × multiplier × half-half 0.5 |
| **Macierz cenowa omnichannel** | Brak — flat prices | `sh_price_tiers` per (channel, target_sku, tenant) |
| **Half & Half horyzontalne** | Domino's ma half-half wertykalnie | Mamy clip-path top/bottom — czytelniejsze na mobile |
| **Server-authoritative cart** | Czasem ufają klientowi | `CartEngine::calculate` zero-trust |
| **Modyfikatory z `linked_warehouse_sku`** | Generic toppings | Każdy mod podpięty do recepty + waste |
| **Atomic order numbering** | Race conditions | `SequenceEngine` z `ON DUPLICATE KEY` |
| **Temporal publishing** | Brak | `valid_from`/`valid_to` per item, soft-delete |

### 1.4 Czego NAM brakuje (do dorobienia w roadmapie)

| Brak | Priorytet | Gdzie dorobimy |
|------|-----------|----------------|
| Live order tracker (jak Pizza Tracker) | P0 | `online_app.js` + polling `engine.php?action=track_order` |
| Easy Orders / 1-click reorder | P1 | `localStorage.lastOrders` + endpoint `repeat_order` |
| Apple Pay / Google Pay | P1 | Endpoint `payments/init_session` + Stripe/PayPal |
| Guest checkout (bez rejestracji) | P0 | Phone-keyed orders (mamy `customer_phone` w `sh_orders`) |
| Address autocomplete | P2 | Google Places API lub Nominatim/OSM |
| Delivery zones validation | P0 | Mamy `sh_delivery_zones` (mig. 008) — wystarczy frontend |
| Loyalty/Rewards | P2 | Osobny moduł (jak ustalono — phone-keyed) |
| SMS notifications | P2 | Twilio/Plivo + `core/Integrations/SmsClient.php` |
| Bundles/Combos | P3 | Studio: typ `bundle` w `sh_menu_items` |

---

## 2. ARCHITEKTURA MODUŁU — DWA SZTUKI W JEDNYM EKOSYSTEMIE

Moduł online to **dwa równoległe światy**, dzielące tę samą bazę i tę samą bibliotekę warstw:

```
                        ┌────────────────────────────────────┐
                        │      sh_menu_items + sh_modifiers  │
                        │      sh_visual_layers + assets     │
                        │      sh_board_companions           │
                        │      sh_tenant_settings (surface)  │
                        └─────────┬──────────────┬───────────┘
                                  │              │
                  reads only      │              │  reads + writes
                                  │              │
        ┌─────────────────────────▼──┐      ┌────▼──────────────────────────┐
        │  modules/online/           │      │  modules/online_studio/       │
        │  (STOREFRONT — KLIENT)     │      │  (MANAGER EDITOR)             │
        │                            │      │                               │
        │  • Surface dark canvas     │      │  • Library Manager (warstwy)  │
        │  • Akordeon kategorii      │      │  • Surface Composer (live)    │
        │  • Surface Card (dish)     │      │  • Dual-photo upload          │
        │  • Half-pizza + companions │      │  • Auto-fit do receptor       │
        │  • Floating cart drawer    │      │  • Z-order per pizza          │
        │  • Live tracker            │      │  • Companions board           │
        │  • Guest checkout          │      │  • Surface background mgmt    │
        │                            │      │  • Live preview klienta       │
        │  AUTH: PUBLIC              │      │  AUTH: owner/manager (JWT)    │
        └────────────────────────────┘      └───────────────────────────────┘
                       │                                    │
                       │ POST                               │ POST
                       ▼                                    ▼
        ┌──────────────────────────────┐    ┌──────────────────────────────┐
        │  api/online/engine.php       │    │  api/online_studio/engine.php│
        │  (PUBLIC, tenant z body)     │    │  (auth_guard, JWT)           │
        │                              │    │                              │
        │  get_storefront_settings     │    │  library_list                │
        │  get_menu                    │    │  library_upload (multipart)  │
        │  get_dish                    │    │  library_update              │
        │  cart_calculate              │    │  library_delete              │
        │  track_order                 │    │  composer_load_dish          │
        │  delivery_zones              │    │  composer_save_layers        │
        │  init_checkout               │    │  composer_calibrate          │
        │  checkout (POST → orders/)   │    │  surface_upload              │
        │                              │    │  companions_save             │
        │                              │    │  preview_render (sandbox)    │
        └──────────────────────────────┘    └──────────────────────────────┘
```

> **Decyzja architektoniczna:** Manager Editor to **osobny moduł `modules/online_studio/`** (nie podzakładka istniejącego Studio menu). Powód: izolacja, bezpieczeństwo (multipart uploady), specyficzny stack (drag&drop, image manipulation), własny CSS/state. **Później** dodamy pojedynczy link z `modules/studio/` → „🎨 Online Composer" jako bramę. Zgodnie z Twoim hasłem: *„OBOWIAZKOWO ROZDZIELAMY I ROBIMY OSOBNO. POZNIEJ DODAMY POPROSTU ZAKLADKE W STUDIO!"*

---

## 3. STOREFRONT (`modules/online/`) — WIZJA PER EKRAN

### 3.1 Hero Surface — Strona Powitalna (root route)

```
┌────────────────────────────────────────────────┐
│  [LOGO Pizzeria]    📍 Poznań  •  ☰ Menu  🛒3 │ ← Glass nav (sticky)
├────────────────────────────────────────────────┤
│                                                │
│        ╔════════════════════════════╗          │
│        ║  🍕 Witaj w SliceHub Pizza ║          │
│        ║                            ║          │
│        ║   ⏱ Dziś: 30 min dostawa   ║          │
│        ║   📍 Twój adres: [auto]    ║          │
│        ║                            ║          │
│        ║   [🛵 DOSTAWA] [🥡 ODBIÓR] ║          │
│        ╚════════════════════════════╝          │
│                                                │
│  ━━━━━ POPULARNE TERAZ ━━━━━                   │
│  [Pepperoni  ][Margherita ][Hawajska ][Cap...] │ ← horyzontalna karuzela
│                                                │
│  ━━━━━ WYBIERZ KATEGORIĘ ━━━━━                 │
│  ▶ 🍕 PIZZE 18cm                       [12 →] │ ← akordeon (zwinięty)
│  ▶ 🍕 PIZZE 30cm                       [12 →] │
│  ▶ 🥗 SAŁATKI                           [4 →] │
│  ▶ 🌭 PRZYSTAWKI                        [8 →] │
│  ▶ 🥤 NAPOJE                           [10 →] │
│  ▶ 🍰 DESERY                            [3 →] │
│                                                │
└────────────────────────────────────────────────┘
                                      🛒 [3 • 89,90 zł]  ← floating FAB
```

**Mechanika:**
- **Kanał wybierany na starcie** (Dostawa/Odbiór) — bez tego widzimy „Wybierz kanał" toast.
- **Geo-fence:** wpisanie adresu → zapytanie do `delivery_zones` → zielone/czerwone (poza strefą = pickup-only).
- **„Popularne teraz"** — endpoint `get_popular` (top 8 z `sh_orders` ostatnie 30 dni).
- **Akordeon:** wąskie paski ~52px (jak Twoje POS okienko, ale subtelniejsze + glass-morphism). Klik → płynnie się rozwija, miniatury 64×64px + nazwa + opis 1 linia + cena/warianty inline.
- **Surface tło:** `body { background: var(--surface-bg) }` z `sh_tenant_settings.storefront_surface_bg`. Fallback: dark linear gradient.
- **Floating cart FAB:** `position: fixed; bottom: 24px; right: 24px` z badge.

### 3.2 Surface Card — Otwarcie Dania (modal/bottom-sheet)

```
┌────────────────────────────────────────────────┐
│  ✕                                  PIZZE 30cm │
├────────────────────────────────────────────────┤
│                                                │
│   ╔═══════════╗                                │
│   ║ ◐         ║   ┌──────────────────────────┐ │
│   ║   PIZZA   ║   │ 🍕 PEPPERONI SUPREME     │ │
│   ║  HEMISFERA║   │ 30cm • Klasyk z chili    │ │
│   ║  (right)  ║   │                          │ │
│   ║           ║   │  💰 39,90 zł             │ │
│   ║ półpizza  ║   │                          │ │
│   ║           ║   │  [PODGLĄD] [✏ EDYCJA]   │ │
│   ║           ║   │  [⬌ PÓŁ NA PÓŁ]          │ │
│   ╚═══════════╝   └──────────────────────────┘ │
│   ↑ flush-left                                 │
│                                                │
│   ━━━ DO TEJ PIZZY POLECAMY ━━━                │
│   ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐              │
│   │🥤   │ │🍞   │ │🥣   │ │🥗   │              │
│   │Cola │ │Czos │ │Sos  │ │Sał. │              │
│   │6,90 │ │4,50 │ │3,00 │ │14,00│              │
│   │[+]  │ │[+]  │ │[+]  │ │[+]  │              │
│   └─────┘ └─────┘ └─────┘ └─────┘              │
│   ↑ companions z hero photos (right of pizza)  │
│                                                │
│   ━━━ DODATKI ━━━                              │
│   🌶 OSTRE                                     │
│   [Pepperoncini ][Jalapeño ][Tabasco ]         │
│   🥬 WARZYWA                                   │
│   [Pomidor +x1 ][Pieczar.][Cebula ] [▼ więcej] │
│   🧀 SERY                                      │
│   [Mozzarella✓][Parmezan ][Gorgonzola ]        │
│   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━              │
│                                                │
│   [    DODAJ DO KOSZYKA — 39,90 zł    ] ←CTA   │
└────────────────────────────────────────────────┘
```

**Mechanika kluczowa:**

1. **Pizza po lewej** (półpizza, prawy hemisfer flush-left) — **statyczny anchor**. Nie rusza się między trybami Podgląd ↔ Edycja.
2. **Companions po prawej** (hero photos z `sh_board_companions.product_filename`) — 1-click „+" dodaje do koszyka jako osobną pozycję, nie wpływa na pizzę.
3. **Modyfikatory pod spodem** — kategoryzowane (`sh_modifier_groups`):
   - 3 widoczne, reszta ukryta za „▼ więcej" (klik rozwija pełną listę).
   - Klik raz → flag „dodane x1", warstwa scatter wskakuje na pizzę.
   - Klik dwa razy → „dodane x2", drugi pomidorek pojawia się **OBOK pizzy** na surface jako hero photo (nie nakłada drugiej warstwy scatter).
   - Klik trzy razy → wraca do x0.
4. **Tryb Podgląd:** statyczne thumbnaile na pizzy (te same warstwy co default).
5. **Tryb Edycja:** warstwy „ożywają" przy kliknięciu (fade-in 200ms + lekki scale bounce). Pizza opcjonalnie wjeżdża na cały ekran (mobile only).
6. **Half & Half:** klik [⬌ PÓŁ NA PÓŁ] → otwiera selektor drugiej pizzy (kompaktowa lista jak Battlefield Ticket). Po wyborze: visible half dzieli się **HORYZONTALNIE** (góra=A, dół=B). Cena = `max(priceA, priceB) + half_half_surcharge`.

### 3.3 Cart Drawer — Niezależny element

```
┌──────────────────────────┐
│  🛒 Twój koszyk    [✕]   │
├──────────────────────────┤
│  PIZZA PEPPERONI 30cm    │
│  +Pomidor x1 +Cebula x2  │
│              39,90 zł [✕]│
├──────────────────────────┤
│  COLA 0,5L          6,90 │
│  CZOSNKOWY SOS      4,50 │
├──────────────────────────┤
│  Suma: 51,30 zł          │
│  Dostawa: 5,00 zł        │
│  ─────────────────────   │
│  RAZEM: 56,30 zł         │
│                          │
│  [💳 PRZEJDŹ DO PŁATN.]  │
└──────────────────────────┘
```

- Wysuwa się z prawej (`transform: translateX(100%)` → `0`).
- Mobile: pełnoekranowy bottom-sheet po kliknięciu FAB.
- Każda pozycja ma swój breakdown (warianty, modyfikatory).
- Server-authoritative re-calculation przy każdym otwarciu (kanał, promocje).
- Promo code input + apply.

### 3.4 Checkout — Trzy kroki (nie więcej!)

```
KROK 1/3: DOSTAWA
─────────────────
[📍 Adres] [🏠 Mieszkanie/Klatka]
[📞 Telefon] [📝 Notatki]
[ASAP ⊙] [Na godzinę ⊙ 19:30]

KROK 2/3: PŁATNOŚĆ
──────────────────
[💵 Gotówka u kuriera]
[💳 Apple Pay / Google Pay]  ← P1
[💳 Karta online (Stripe)]   ← P1
[Promo code: ____] [APPLY]

KROK 3/3: POTWIERDZENIE
───────────────────────
[Lista pozycji]
[RAZEM: 56,30 zł]
[ZAMAWIAM ✓]
```

**Konwersja-driven:**
- 5 → 3 kroki = +200% (z badań).
- Guest checkout (zero rejestracji).
- Phone autofill z `localStorage` przy powrocie.
- Address autocomplete (P2: Google Places).
- Trust badges przy przyciskach (SSL, „Twoje dane są bezpieczne").

### 3.5 Order Tracker — Po zamówieniu (jak Domino's Pizza Tracker)

```
ZAMÓWIENIE #ORD/20260416/0042
═════════════════════════════
✓ Otrzymane (16:42)
✓ Zaakceptowane (16:43)
● Przygotowanie (Twoje danie jest w piecu)
○ Gotowe do dostawy
○ W drodze
○ Dostarczone

⏱ Szacowany czas: 16:42 → 17:12 (30 min)
🛵 Kierowca: Marek (☆4.9)
📍 [Mapa LIVE z lokalizacją kierowcy]

[💬 ZADZWOŃ] [📞 RESTAURACJA]
```

- Endpoint `track_order` (polling co 10s, `If-Modified-Since` cache).
- Stage: `new` → `accepted` → `preparing` → `ready` → `in_delivery` → `completed` (mamy ten state machine!).
- GPS kierowcy z `sh_driver_locations` (mig. 008).
- WebSocket fallback do polling (P3).

---

## 4. MANAGER EDITOR (`modules/online_studio/`) — POTĘŻNY KOMPOZYTOR

> **To jest serce systemu od strony managera.** Twórca ma zarządzać setkami warstw, dziesiątkami dań, dziesiątkami companions — wszystko z poziomu jednego, intuicyjnego, *zaawansowanego ale prostego* edytora.

### 4.1 Pięć zakładek

```
┌────────────────────────────────────────────────────────────────┐
│  SLICEHUB ONLINE STUDIO                          ← Studio menu │
│  📚 BIBLIOTEKA  •  🎨 KOMPOZYTOR  •  🛍 COMPANIONS  •          │
│  🖼 SURFACE  •  👁 PODGLĄD ONLINE                              │
└────────────────────────────────────────────────────────────────┘
```

### 4.2 Zakładka 1: 📚 BIBLIOTEKA WARSTW (Library Manager)

```
┌────────────────────────────────────────────────────────────────┐
│ 📚 Biblioteka                                                  │
│  [+ DODAJ WARSTWĘ]  [🔍 szukaj...]  [Filtr: WSZYSTKIE ▼]      │
├──────────────┬─────────────────────────────────────────────────┤
│ KATEGORIE    │  ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐           │
│ ☐ baza (3)  │  │   │ │   │ │   │ │   │ │   │ │   │           │
│ ☐ sosy (4)  │  │ T │ │ P │ │ S │ │ C │ │ B │ │ M │           │
│ ☐ sery (6)  │  │   │ │   │ │   │ │   │ │   │ │   │           │
│ ☐ mięsa (8) │  └───┘ └───┘ └───┘ └───┘ └───┘ └───┘           │
│ ☐ warzywa   │  Tomato Pepperoni Salami Cebula Bacon Mozz...   │
│ ☐ zioła     │                                                 │
│ ☐ desery    │  Klik karty → otwiera szczegóły:               │
│             │  • Layer (scatter) photo                        │
│ TYPY:       │  • Hero (product) photo                         │
│ ☐ Layer     │  • Kategoria, sub_type, ascii_key               │
│ ☐ Hero      │  • z_order default                              │
│ ☐ Surface   │  • Przypisane do dań: [PIZZA_PEPPERONI x12]     │
│ ☐ Companion │  • [✏ EDYTUJ] [🗑 USUŃ] [🔄 ZAMIEŃ]            │
└──────────────┴─────────────────────────────────────────────────┘
```

**Funkcje:**
- **Upload dual-photo** w jednym kroku (drag&drop dwóch plików: `layer_*.webp` + `product_*.webp`).
- **Auto-fit** — silnik analizuje średnią wielkość istniejących warstw w kategorii i sugeruje `cal_scale`/`cal_rotate`. Manager widzi „Sugerowana skala: 0.95" + przycisk „Zastosuj".
- **Filtry:** kategoria, sub_type, „nieprzypisane do żadnego dania" (sieroty).
- **Bulk operations:** zaznacz 5 warstw → „Przypisz do PIZZA_HAWAJSKA" / „Usuń".
- **Wyszukiwarka:** fuzzy search po `ascii_key`, `category`, `sub_type`.
- **Edytor warstwy** (modal):
  - Podmiana zdjęć (layer + hero osobno).
  - Edycja `ascii_key`, `category`, `sub_type`.
  - Default `cal_scale` (0.5–2.0), `cal_rotate` (-180° do +180°), `z_order` (0–60).
  - Lista przypisań („Używana w 12 daniach: [klikalna lista]").

### 4.3 Zakładka 2: 🎨 KOMPOZYTOR (Surface Composer — TO JEST KILLER)

```
┌────────────────────────────────────────────────────────────────┐
│ 🎨 Kompozytor — wybierz danie:  [PIZZA_PEPPERONI ▼]            │
├────────────────────┬───────────────────┬───────────────────────┤
│ STOS WARSTW        │  PODGLĄD LIVE     │  BIBLIOTEKA           │
│ ──────────────     │ ┌───────────────┐ │  [🔍 szukaj...]      │
│ 6 ↕ Bazylia        │ │               │ │  ┌──┐┌──┐┌──┐        │
│ 5 ↕ Salami    [✕]  │ │   ◐  PIZZA    │ │  │  ││  ││  │        │
│ 4 ↕ Pepperoni [✕]  │ │   POW WIDOK   │ │  └──┘└──┘└──┘        │
│ 3 ↕ Mozzarella     │ │   pełny okrąg │ │  Drag → na stos      │
│ 2 ↕ Sos Tomato     │ │   z warstwami │ │                       │
│ 1 ↕ Ciasto (BASE)  │ │               │ │  ━━ NIEPRZYPISANE ━━  │
│                    │ │  [📐 Surface] │ │  ┌──┐┌──┐┌──┐        │
│ Zaznacz warstwę    │ │  [🍕 Live]    │ │  Drag → dodaj         │
│ → kalibruj poniżej │ └───────────────┘ │                       │
│                    │                   │  ━━ COMPANIONS ━━     │
│ ▶ Salami           │  ┌───────────────┐│  [Cola] [Czosnek]    │
│   Skala: ●─────── 1│  │ Przełącz tryb ││  Drag → karuzela     │
│                  .95│  │ [🍕 Pizza]    ││                       │
│   Rotacja: ─●──── 0°│  │ [📐 Surface]  ││                       │
│                  -8°│  │ [⬌ Pół na Pół]││                       │
│   Z-order: ──────●5 │  │ [👁 Podgląd]  ││                       │
│                    │  └───────────────┘│                       │
│   [💾 ZAPISZ]      │                   │                       │
└────────────────────┴───────────────────┴───────────────────────┘
```

**Mechanika:**

1. **Środkowy panel** = real-time render dokładnie tak jak zobaczy klient (renderer dzielony — ten sam kod CSS/JS co frontend storefront).
2. **Lewy panel** = z-stack warstw przypisanych do tego dania. Drag-handle ↕ zmienia kolejność (z_index). Klik warstwy → kalibracja w sekcji niżej.
3. **Prawy panel** = biblioteka (cała), filtry, drag&drop NA stos lewy. Przeciągnięcie warstwy do środkowego panelu = przypisanie.
4. **Suwaki kalibracji** (live preview, debouncing 50ms):
   - `cal_scale` (0.5 — 2.0)
   - `cal_rotate` (-180° — +180°)
   - `z_index` (0 — 60)
5. **Tryby przełącznika centralnego:**
   - 🍕 **Pizza** — pełny okrąg, edytujesz danie.
   - 📐 **Surface** — tylko tło + companions (to co widzi klient zanim wybierze danie).
   - ⬌ **Pół na Pół** — symuluje połowę pizzy A i B.
   - 👁 **Podgląd Klienta** — przełącza się na embedded iframe `modules/online/?dish=PIZZA_PEPPERONI` (1:1 widok klienta).
6. **„Auto-Fit do receptora"** — przycisk u góry. Po dodaniu nowej warstwy silnik analizuje:
   - Istniejące warstwy w tej samej `sub_type` na tym daniu.
   - Średnia `cal_scale` i `cal_rotate` z tej grupy.
   - Sugeruje wartości + manager akceptuje jednym klikiem.
7. **„Skopiuj kompozycję z…"** — wybierasz inne danie, kopiujesz cały stos warstw (np. PIZZA_HAWAJSKA = PIZZA_MARGHERITA + ananas + szynka).

### 4.4 Zakładka 3: 🛍 COMPANIONS (Cross-sell Board Manager)

```
┌────────────────────────────────────────────────────────────────┐
│ 🛍 Companions — dla dania:  [PIZZA_PEPPERONI ▼]                │
├──────────────────────────────┬─────────────────────────────────┤
│ AKTYWNE COMPANIONS           │  DOSTĘPNE PRODUKTY              │
│ ┌──────────────────────────┐ │  [🔍 szukaj...]                 │
│ │ Slot 1: 🥤 Cola 0.5L     │ │  ☐ Cola 1L                      │
│ │ Hero: cola_05_a8f2.webp  │ │  ☐ Sprite 0.5L                  │
│ │ [✕ usuń] [↕ slot]       │ │  ☐ Fanta 0.5L                   │
│ ├──────────────────────────┤ │  ☐ Pepsi 0.5L                   │
│ │ Slot 2: 🍞 Czosnkowy     │ │  ☐ Sok jabłkowy                 │
│ │ Hero: garlic_b3c1.webp   │ │  [+ DODAJ ZAZNACZONE]           │
│ │ [✕ usuń] [↕ slot]       │ │                                 │
│ ├──────────────────────────┤ │  Drag → przeciągnij na lewo     │
│ │ Slot 3: 🥣 Sos czosnk.   │ │  aby dodać jako companion.       │
│ │ [Brak hero — UPLOAD]    │ │                                 │
│ └──────────────────────────┘ │                                 │
│                              │                                 │
│ [💾 ZAPISZ KOLEJNOŚĆ]        │                                 │
└──────────────────────────────┴─────────────────────────────────┘
```

- Drag&drop slotów (1, 2, 3, 4 — `board_slot`).
- Per-companion hero photo upload (jeśli brak → spada do `mi.image_url`).
- „Apply to all pizzas" (bulk).

### 4.5 Zakładka 4: 🖼 SURFACE (Background Manager)

```
┌────────────────────────────────────────────────────────────────┐
│ 🖼 Surface — tło powierzchni storefront                        │
├────────────────────────────────────────────────────────────────┤
│  AKTUALNE TŁO:                                                 │
│  ┌──────────────────────────────────────────────────┐          │
│  │  [PODGLĄD: ciemny kamień / drewno / marmur]      │          │
│  │  surface_stone_a8c1f2.webp  •  4.2 MB            │          │
│  └──────────────────────────────────────────────────┘          │
│                                                                │
│  [📤 ZMIEŃ TŁO]   [🗑 USUŃ (powrót do default)]                │
│                                                                │
│  ━━ WYTYCZNE FOTOGRAFICZNE ━━                                  │
│  • Ciemna powierzchnia (czerń, ciemne drewno, kamień)          │
│  • Min 1920×1080 px, max 3840×2400 px                          │
│  • Format: .webp (preferowany) lub .jpg                        │
│  • Max rozmiar: 5 MB                                           │
│  • Bez ostrych wzorów — ma być TŁEM nie PRODUKTEM              │
│  • Subtelna tekstura (~70% jednolitości)                       │
│                                                                │
│  ━━ GALERIA SUGEROWANYCH TŁ ━━                                 │
│  ┌───┐ ┌───┐ ┌───┐ ┌───┐ ┌───┐                                │
│  │ 1 │ │ 2 │ │ 3 │ │ 4 │ │ 5 │  Klik → Apply                  │
│  └───┘ └───┘ └───┘ └───┘ └───┘                                │
└────────────────────────────────────────────────────────────────┘
```

- Walidacja JS pre-upload + PHP server-side (`api/online_studio/surface_upload.php`).
- Galeria predefiniowanych teł (10 sztuk, generowane przy pierwszym setupie).
- Live preview z aktualnymi daniami.

### 4.6 Zakładka 5: 👁 PODGLĄD ONLINE (Live Customer View)

```
┌────────────────────────────────────────────────────────────────┐
│ 👁 Podgląd Online — to widzi klient TERAZ                      │
│  [📱 Mobile]  [💻 Tablet]  [🖥 Desktop]   [🔄 Odśwież]         │
├────────────────────────────────────────────────────────────────┤
│  ┌────────────────────────────┐                                │
│  │                            │                                │
│  │  EMBEDDED IFRAME           │                                │
│  │  modules/online/           │                                │
│  │  z aktualnym tenantId      │                                │
│  │                            │                                │
│  │  (Real-time customer view) │                                │
│  │                            │                                │
│  └────────────────────────────┘                                │
│                                                                │
│  Nawiguj do dania: [PIZZA_PEPPERONI ▼] → otwiera w iframe      │
└────────────────────────────────────────────────────────────────┘
```

- Iframe ładuje `modules/online/?tenant=X&dish=PIZZA_PEPPERONI`.
- Responsive viewport switcher (testy mobile/tablet/desktop bez zmiany urządzenia).
- WYSIWYG — manager widzi DOKŁADNIE to co klient.

---

## 5. BACKEND — KOMPLETNY KONTRAKT API

### 5.1 `api/online/engine.php` (PUBLIC — storefront, klient)

> **Status: ✅ MAMY GOTOWY** (4 akcje), trzeba dorobić 4 dodatkowe.

| Akcja | In | Out | Status |
|-------|----|----|--------|
| `get_storefront_settings` | tenantId, channel | tenant, surfaceBg, halfHalfSurcharge | ✅ |
| `get_menu` | tenantId, channel | categories[items[]] z cenami | ✅ |
| `get_dish` | tenantId, channel, itemSku | item, modifierGroups, companions, visualLayers, globalAssets | ✅ |
| `cart_calculate` | tenantId, channel, lines, promo_code | grand_total, vat_summary, response | ✅ |
| **`get_popular`** | tenantId, channel, limit | top 8 SKU z `sh_orders` ostatnie 30d | 🆕 P0 |
| **`delivery_zones`** | tenantId, address | zone, fee, eta, in_zone | 🆕 P0 |
| **`init_checkout`** | tenantId, channel, lines | lock_token (idempotency), grand_total | 🆕 P0 |
| **`track_order`** | tenantId, order_id, phone | status, eta, driver_gps | 🆕 P0 |

### 5.2 `api/online_studio/engine.php` (AUTH — manager editor) 🆕

| Akcja | In | Out | Notes |
|-------|----|----|-------|
| **`library_list`** | filter (cat, sub_type), search | layers[] z assignment count | Listing biblioteki |
| **`library_update`** | layer_id, ascii_key, cat, sub_type, default_scale, default_rotate, z_order | layer | Edycja metadanych |
| **`library_delete`** | layer_id | success | Soft delete (tylko jeśli unassigned) |
| **`composer_load_dish`** | item_sku | layers (assigned), full library, surface | Otwiera danie do edycji |
| **`composer_save_layers`** | item_sku, layers[{layer_sku, z_index, cal_scale, cal_rotate, is_base}] | success, version | Bulk save z optimistic locking |
| **`composer_calibrate`** | item_sku, layer_sku, cal_scale, cal_rotate | success | Single-layer save (debounced live calibration) |
| **`composer_clone`** | source_sku, target_sku | success | „Skopiuj kompozycję z…" |
| **`composer_autofit_suggest`** | item_sku, new_layer_sku | suggested_scale, suggested_rotate | Auto-fit ML-lite |
| **`companions_list`** | item_sku | companions[] | Aktywne companions dania |
| **`companions_save`** | item_sku, companions[{sku, slot}] | success | Bulk save sloty |
| **`surface_apply`** | filename | success | Aktywuj nowe tło z biblioteki |
| **`preview_url`** | item_sku, viewport (mobile/tablet/desktop) | iframe_url, csp_token | URL do iframe podglądu |

### 5.3 Endpointy uploadowe (osobne, multipart) 🆕

| Endpoint | Cel | Validation |
|----------|-----|-----------|
| `api/online_studio/library_upload.php` | Upload dual-photo (layer + hero) | 3MB layer, 1.5MB hero, .webp/.png, dim min/max |
| `api/online_studio/companion_upload.php` | Upload hero companion | 1.5MB, .webp/.png |
| `api/online_studio/surface_upload.php` | Upload tła Surface | 5MB, .webp/.jpg, min 1920×1080 |

> Wszystkie multipart endpointy używają wspólnego `core/UploadValidator.php` (do utworzenia) — DRY.

### 5.4 Reużycie istniejącego API (NIE DUBLUJEMY)

| Cel | Endpoint | Notes |
|-----|----------|-------|
| Finalizacja zamówienia | `POST /api/orders/checkout.php` | Działa, dodajemy `source: 'ONLINE'` |
| Edycja zamówienia (admin) | `POST /api/orders/edit.php` | Już mamy z DeltaEngine |
| Anulowanie | `POST /api/orders/panic.php` lub status | Mamy state machine |
| Wycena (preview) | `POST /api/cart/calculate.php` | Działa — używamy z `online/engine.php?action=cart_calculate` jako proxy |
| Tracking GPS | `sh_driver_locations` (mig. 008) | Frontend polling przez `track_order` |
| Driver assignment | `POST /api/courses/engine.php` | Już istnieje |

### 5.5 Auth & Bezpieczeństwo

| Endpoint | Auth | Rate limit | CSRF |
|----------|------|------------|------|
| `online/engine.php` (PUBLIC) | tenantId w body | 60 req/min/IP | ❌ (read-only + cart preview) |
| `online/engine.php?action=init_checkout` | + idempotency token | 10 req/min/phone | Token jednorazowy |
| `online_studio/*` | `auth_guard.php` (JWT, role: owner/manager) | 120 req/min/user | ✅ |
| Upload endpoints | `auth_guard.php` + multipart | 30 uploadów/h/user | ✅ + magic-bytes check |

---

## 6. BAZA DANYCH — CO ZMIENIAMY (PROPOZYCJA, CZEKA NA TWOJĄ ZGODĘ)

> **Hard rule:** żaden CREATE/ALTER/DROP bez Twojego „TAK".

### 6.1 Co już mamy (ZERO zmian potrzebne):

| Tabela | Pokrycie wizji |
|--------|----------------|
| `sh_categories`, `sh_menu_items`, `sh_modifier_groups`, `sh_modifiers`, `sh_item_modifiers` | 100% |
| `sh_price_tiers`, `sh_recipes` | 100% |
| `sh_visual_layers` (po mig. 016: +cal_scale, +cal_rotate, +product_filename) | 100% |
| `sh_board_companions` (po mig. 016: +product_filename) | 100% |
| `sh_global_assets`, `sh_ingredient_assets` | 100% |
| `sh_tenant_settings` (po mig. 016: +storefront_surface_bg) | 100% |
| `sh_orders`, `sh_order_lines`, `sh_order_audit`, `sh_order_payments` | 100% |
| `sh_order_sequences`, `sh_promo_codes` | 100% |
| `sh_delivery_zones` (mig. 008/009) | 100% |
| `sh_driver_locations` (mig. 008) | 100% |

### 6.2 Co PROPONUJĘ dorobić (migracja 017 — wymaga Twojej zgody):

```sql
-- 017_online_module_extensions.sql

-- (A) Optimistic locking dla composer (zapobiega kolizjom edycji)
ALTER TABLE sh_visual_layers
  ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active;

-- (B) Library metadata (kategorie/sub_type per layer w bibliotece)
ALTER TABLE sh_visual_layers
  ADD COLUMN library_category VARCHAR(64) NULL AFTER layer_sku,
  ADD COLUMN library_sub_type VARCHAR(64) NULL AFTER library_category,
  ADD INDEX idx_lib_cat (tenant_id, library_category);

-- (C) Idempotency keys dla checkout (zapobiega podwójnym zamówieniom)
CREATE TABLE IF NOT EXISTS sh_checkout_locks (
  lock_token CHAR(36) PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  customer_phone VARCHAR(32) NULL,
  expires_at TIMESTAMP NOT NULL,
  consumed_at TIMESTAMP NULL,
  cart_hash CHAR(64) NOT NULL,
  INDEX idx_expires (expires_at),
  FOREIGN KEY (tenant_id) REFERENCES sh_tenant(id) ON DELETE CASCADE
);

-- (D) Order tracking — link telefonu do zamówienia (guest tracking)
ALTER TABLE sh_orders
  ADD COLUMN tracking_token CHAR(16) NULL AFTER customer_phone,
  ADD INDEX idx_tracking (tracking_token);
-- (Klient widzi swoje zamówienie tylko z token + phone match)

-- (E) Tenant settings — wszystkie online flagi
INSERT IGNORE INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
SELECT id, 'online_min_order_value', '0.00' FROM sh_tenant
UNION ALL
SELECT id, 'online_default_eta_min', '30' FROM sh_tenant
UNION ALL
SELECT id, 'online_guest_checkout', '1' FROM sh_tenant
UNION ALL
SELECT id, 'online_apple_pay_enabled', '0' FROM sh_tenant;
```

### 6.3 Co PROPONUJĘ NIE dotykać:
- Wszystko inne — działa.

---

## 7. TECH STACK FINALNY

| Warstwa | Wybór | Powód |
|---------|-------|-------|
| Backend | PHP 8+ (PDO) | Konstytucja, zero zmian |
| Frontend logic | Vanilla JS ES6+ (modules) | Konstytucja |
| Frontend styling | Czysty CSS (custom properties) | Konstytucja, nie Tailwind dla storefront |
| Frontend studio | Vanilla JS + Tailwind CSS | Spójność z innymi modułami backofficu |
| HTTP | Fetch API + JSON | Wszędzie, jak w POS |
| State | localStorage (cart, phone, address) + sessionStorage (lock_token) | Brak frameworków |
| Image format | WebP (z .png fallback) | Performance |
| Upload | multipart/form-data + drag&drop | Native API |
| Live preview | CSS transforms + clip-path | Zero canvas, GPU acceleration |
| Mobile | Touch events + safe-area-inset | iOS/Android nativ |
| PWA | `manifest.json` + service worker (P2) | Add to homescreen |
| Polling | setInterval z `If-Modified-Since` (P0); WebSocket (P3) | Tracker |

---

## 8. STRUKTURA PLIKÓW

```
modules/
├── online/                              # STOREFRONT (klient)
│   ├── index.html                       # Akordeon + nav + cart FAB
│   ├── checkout.html                    # 3-step checkout
│   ├── track.html                       # Order tracker
│   ├── manifest.json                    # PWA manifest
│   ├── css/
│   │   ├── style.css                    # Surface theme + akordeon
│   │   ├── dish_card.css                # Surface Card styling
│   │   ├── cart.css                     # Cart drawer + checkout
│   │   └── tracker.css                  # Pizza tracker
│   ├── js/
│   │   ├── online_api.js                # Fetch wrapper na engine.php
│   │   ├── online_app.js                # State machine + lifecycle
│   │   ├── online_ui_menu.js            # Akordeon + lista dań
│   │   ├── online_ui_dish.js            # Surface Card (półpizza+companions+mods)
│   │   ├── online_ui_cart.js            # Drawer + line-item rendering
│   │   ├── online_ui_checkout.js        # 3-step form
│   │   ├── online_ui_tracker.js         # Pizza tracker + GPS
│   │   └── online_renderer.js           # Layer rendering (clip-path + transforms)
│   └── service-worker.js                # Offline cache (P2)
│
├── online_studio/                       # MANAGER EDITOR (admin)
│   ├── index.html                       # 5-tab shell
│   ├── manifest.json                    # PWA (P3)
│   ├── css/
│   │   ├── studio.css                   # 3-panel layout
│   │   ├── library.css                  # Grid kart bibliotecznych
│   │   ├── composer.css                 # Live preview + suwaki
│   │   └── companions.css               # Slots + drag&drop
│   ├── js/
│   │   ├── studio_api.js                # Wrapper na online_studio/engine.php
│   │   ├── studio_app.js                # Tab routing + auth
│   │   ├── studio_library.js            # Biblioteka warstw
│   │   ├── studio_composer.js           # Live composer (suwaki + drag)
│   │   ├── studio_companions.js         # Cross-sell board
│   │   ├── studio_surface.js            # Background manager
│   │   ├── studio_preview.js            # Live customer iframe
│   │   ├── studio_upload.js             # Dual-photo uploader
│   │   ├── studio_renderer.js           # WSPÓLNE z online/online_renderer.js
│   │   └── studio_autofit.js            # Auto-fit ML-lite
│   └── (shares renderer with online/)

api/
├── online/
│   └── engine.php                       # ✅ READY (4 akcje), dorobić 4
├── online_studio/                       # 🆕 NEW
│   ├── engine.php                       # 12 akcji
│   ├── library_upload.php               # multipart dual-photo
│   ├── companion_upload.php             # multipart hero
│   └── surface_upload.php               # multipart tło

core/
└── UploadValidator.php                  # 🆕 wspólny walidator multipart (DRY)
```

---

## 9. KOLEJNOŚĆ IMPLEMENTACJI (ROADMAPA)

> **Każdy etap = jeden focus session, lint-clean, commit. Twoja zgoda między etapami.**

### Etap 0 (TERAZ) — Wizja zatwierdzona
- ✅ Sprzątanie: zrobione
- ✅ `00_PAMIEC_SYSTEMU.md`: zrobione
- ⏳ `06_WIZJA_MODULU_ONLINE.md` (ten plik): czeka na akceptację
- ⏳ Migracja 017 SQL: czeka na akceptację

### Etap 1 — Backend Storefront (rozszerzenie)
- 🔧 `api/online/engine.php`: dodać `get_popular`, `delivery_zones`, `init_checkout`, `track_order`
- 🔧 Smoke-test wszystkich 8 akcji curl/Postman
- ⏱ ~3h pracy

### Etap 2 — Backend Studio (manager editor)
- 🆕 `api/online_studio/engine.php` z 12 akcjami
- 🆕 `api/online_studio/library_upload.php` (multipart dual-photo)
- 🆕 `api/online_studio/companion_upload.php` (multipart hero)
- 🆕 `api/online_studio/surface_upload.php` (multipart tło)
- 🆕 `core/UploadValidator.php` (wspólny walidator)
- ⏱ ~6h pracy

### Etap 3 — Frontend Storefront — szkielet
- 🆕 `modules/online/index.html` + `css/style.css` (Surface theme)
- 🆕 `online_api.js` + `online_app.js` (init + state)
- 🆕 `online_ui_menu.js` (akordeon + lista)
- 🆕 `online_renderer.js` (warstwy + clip-path)
- ⏱ ~5h pracy

### Etap 4 — Frontend Storefront — Surface Card
- 🆕 `online_ui_dish.js` (półpizza + companions + modyfikatory)
- 🆕 `online_ui_cart.js` (drawer + checkout link)
- ⏱ ~5h pracy

### Etap 5 — Frontend Studio — Library + Upload
- 🆕 `modules/online_studio/index.html` + tabs
- 🆕 `studio_library.js` + `studio_upload.js`
- ⏱ ~5h pracy

### Etap 6 — Frontend Studio — Composer (KILLER FEATURE)
- 🆕 `studio_composer.js` (3-panel + drag&drop + live calibration)
- 🆕 `studio_renderer.js` (shared z online_renderer.js)
- 🆕 `studio_autofit.js`
- ⏱ ~8h pracy

### Etap 7 — Frontend Studio — Companions + Surface + Preview
- 🆕 `studio_companions.js` + `studio_surface.js` + `studio_preview.js`
- ⏱ ~4h pracy

### Etap 8 — Checkout + Tracker
- 🆕 `modules/online/checkout.html` + `online_ui_checkout.js`
- 🆕 `modules/online/track.html` + `online_ui_tracker.js`
- 🔧 `api/orders/checkout.php` rozszerzenie (`source=ONLINE`, `tracking_token`)
- ⏱ ~5h pracy

### Etap 9 — Polish + Smoke Test
- 🧪 E2E: pełen flow zamówienia od menu do tracker
- 🧪 Test multi-tenant
- 🧪 Mobile real device test (iOS/Android)
- 🧪 Performance audit (Lighthouse, target: 90+ Performance, 95+ Accessibility)
- 🧪 Manager workflow: upload warstwy → kalibracja → zapis → klient widzi
- ⏱ ~4h pracy

### Etap 10 (P1) — Apple Pay/Google Pay + PWA
- 🆕 Stripe/PayU integracja (oddzielny task)
- 🆕 Service worker + offline cache
- ⏱ ~6h pracy

**Suma:** ~51h skoncentrowanej pracy = realistycznie **2 tygodnie** dziennej iteracji z pełnym Twoim feedbackiem.

---

## 10. SPÓJNOŚĆ Z KONSTYTUCJĄ

| Prawo | Jak respektujemy |
|-------|------------------|
| **I. Macierz Cenowa** | `engine.php` używa `sh_price_tiers` z fallback do POS; `priceFallback: true` w odpowiedzi |
| **II. Bliźniak Cyfrowy** | `WzEngine` po checkout dedukuje magazyn; half-half × 0.5 |
| **III. Czwarty Wymiar** | `is_active` + `is_deleted` na items; soft delete w composer |
| **IV. Zero Zaufania** | Cart re-calc po stronie serwera; `init_checkout` zwraca lock_token; idempotency |
| **V. Kopalnia Wiedzy** | Stare moduły online już zarchiwizowane/usunięte; nowy kod zgodny z `OPTIMIZED_CORE_LOGIC_V2.md` |
| **VI. Snajper** | Każda zmiana per etap, commit, smoke test; nigdy globalne refaktory |

---

## 11. RYZYKA I MITYGACJE

| Ryzyko | Prawdopodobieństwo | Impact | Mitygacja |
|--------|-------------------|--------|-----------|
| Multipart upload zbyt duże pliki | Średnie | Wysoki | Walidacja JS pre-upload + PHP `getimagesize` + magic bytes; limity per typ (3MB layer, 1.5MB hero, 5MB surface) |
| Kolizje edycji w composer (2 managerów na raz) | Niskie | Średni | Optimistic locking via `version` column; konflikt → modal „Ktoś inny edytował, [PRZEŁADUJ]" |
| Race conditions w checkout (double-tap) | Średnie | Wysoki | `init_checkout` → lock_token (TTL 5min); checkout SPRAWDZA + KONSUMUJE token atomically |
| Wolny render warstw na mobile (8+ layers) | Średnie | Średni | `will-change: transform`; lazy loading warstw; `loading="lazy"` na images; image preloading przed otwarciem Surface Card |
| Cache miss tracker (klient F5 = stracone tracking) | Średnie | Niski | `tracking_token` w URL + localStorage backup + phone match validation |
| Nieaktualne ceny w cart drawer (zmiana w Studio podczas sesji) | Wysokie | Średni | Każde otwarcie drawer = re-call `cart_calculate`; różnica > 5% → toast „Ceny się zmieniły, sprawdź" |
| Brak surowca w trakcie zamówienia (race z innymi kanałami) | Wysokie | Wysoki | Pre-order check przez `WzEngine::checkAvailability` (do dorobienia); fallback: powiadomienie po przyjęciu „Niestety brak X, [zmień / anuluj]" |
| Złe tło Surface zniszczy UX | Niskie | Wysoki | Galeria 10 sugerowanych teł + walidacja kontrastu (algorytm: średnia jasność <40 OK) |
| Klient niezapisany (anonim) traci koszyk | Wysokie | Średni | localStorage cart persistence + sessionStorage fallback |

---

## 12. ROZSZERZENIA POST-MVP

> Wymienione tylko aby pamiętać kierunek. Nie implementujemy teraz.

- **Marketing Module** — sezonowe surface, promo banners, A/B testy
- **Loyalty Module** — phone-keyed rewards (zgodnie z `OPTIMIZED_CORE_LOGIC_V2.md` §5)
- **Statistics Module** — heatmapa kliknięć, conversion funnel, food cost analytics, A/B test winner
- **AI Recommendations** — collaborative filtering („Klienci którzy kupili Pepperoni zamawiali też…")
- **Voice Ordering** — Web Speech API
- **Telegram/WhatsApp Bot** — alternatywne kanały
- **Influencer Bundles** — „Pizza Damiana" custom landing
- **Subscription** — pizza co tydzień (jak Glovo Plus)
- **B2B Catering Portal** — duże zamówienia firmowe z fakturą VAT (już mamy NIP w mig. 007)
- **Multi-language** — `i18n.json` + wybór języka

---

## 13. CO POTRZEBUJĘ OD CIEBIE — CZEKAM NA AKCEPT

Przed zaczęciem backendu odpowiedz:

1. ✅ **Wizja modułu storefront** (rozdział 3) — akceptujesz?
2. ✅ **Wizja Manager Editor jako osobny moduł `online_studio/`** (rozdział 4) — akceptujesz?
3. ✅ **Migracja 017** (rozdział 6.2: 5 zmian DB) — dajesz zielone światło?
4. ✅ **Kolejność etapów** (rozdział 9) — czy zaczynamy od Etapu 1 (rozszerzenie istniejącego `online/engine.php`)?
5. ✅ **Tech stack** (rozdział 7) — Vanilla JS dla storefront, Tailwind dla studio. Zgoda?
6. ❓ **Pytania otwarte:**
   - Tenant resolution dla storefront: subdomain (`mojapizza.slicehub.pl`), URL param (`?t=1`) czy hardcode w shell?
   - Apple Pay / Stripe — w P0 czy P1?
   - PWA z service worker — w MVP czy później?
   - Wielojęzyczność — od razu czy potem?

Po Twoim „GO" zaczynam **Etap 1: Backend Storefront** (rozszerzenie `online/engine.php` o 4 nowe akcje).

---

> **To nie jest kolejna strona z pizzą. To jest fotograficzny teatr który zarabia.**
