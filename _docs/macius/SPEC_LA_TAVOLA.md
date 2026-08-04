# La Tavola — Pełna specyfikacja UX online ordering z Maciusiem

> Wersja: 1.0 (2026-08-03)  
> Status: PLAN DO ZATWIERDZENIA  
> Cel: Dokument referencyjny dla agentów implementujących moduł

---

## 1. Filozofia

**Nie budujesz sklepu internetowego. Budujesz wirtualną restaurację.**

Klient nie "robi zakupy" — klient "siada przy stoliku". Ta zmiana perspektywy kształtuje każdą decyzję UX:

| Tradycyjne e-commerce | La Tavola |
|---|---|
| Koszyk (shopping cart) | Twój stolik (la tavola) |
| Lista produktów | Karty menu na stole |
| "Dodaj do koszyka" | "Postaw na stole" |
| Checkout form | Rachunek (il conto) |
| AI chatbot | Kelner Maciuś (niewidoczne AI) |
| Upsell popup | Karteczka kelnera na stole |
| Promocja banner | Stojak reklamowy na stoliku |

---

## 2. Ekrany i stany aplikacji

### 2.1 Mapa stanów (state machine)

```
[DRZWI] → [STOLIK] ⇄ [KARTA MENU] ⇄ [KOMPOZYTOR] → [RACHUNEK] → [POTWIERDZENIE] → [ŚLEDZENIE]
             ↑              ↑
             └──────────────┘
              powrót zawsze możliwy
```

### 2.2 STAN 0: Drzwi (Doorway) — BEZ ZMIAN

Istniejący moduł `online_doorway.js`. Klient widzi drzwi restauracji, wybiera:
- Kanał: Dowóz / Odbiór
- Status: Otwarte / Zamknięte / Pre-order

**Zmiana względem obecnego:** Po wejściu → przechodzi do STOLIKA (nie do menu listy).

---

### 2.3 STAN 1: Stolik (La Tavola) — GŁÓWNY WIDOK

To jest CENTRUM aplikacji. Klient wraca tu po każdej akcji.

#### Layout mobile (portrait, ~390px):

```
┌───────────────────────────────────────┐
│  ┌─ Pasek górny ───────────────────┐  │
│  │ 🍕 Pizza Forno    [Dowóz ▾] 🔍  │  │
│  └──────────────────────────────────┘  │
│                                        │
│  ┌─ Strefa stolika (40% ekranu) ────┐  │
│  │                                   │  │
│  │   [Tu renderowane dania klienta]  │  │
│  │   drewniany blat, ciepłe światło  │  │
│  │   companions, para, atmosfera     │  │
│  │                                   │  │
│  │   Stan pusty: serwetka + sztućce  │  │
│  │   + mała podpowiedź "Wybierz     │  │
│  │     coś z karty poniżej"         │  │
│  │                                   │  │
│  └───────────────────────────────────┘  │
│                                        │
│  ┌─ Strefa nawigacji (60% ekranu) ──┐  │
│  │                                   │  │
│  │  [Karty kontekstowe Maciusia]     │  │
│  │  np. "Zamów ponownie 36 zł"      │  │
│  │  np. "Zestaw dnia 29 zł"         │  │
│  │                                   │  │
│  │  ─── Karta Menu ───              │  │
│  │  ╭──╮ ╭──╮ ╭──╮ ╭──╮            │  │
│  │  │🍕│ │🥗│ │🥤│ │🍰│            │  │
│  │  ╰──╯ ╰──╯ ╰──╯ ╰──╯            │  │
│  │                                   │  │
│  │  [Items wybranej kategorii]       │  │
│  │                                   │  │
│  └───────────────────────────────────┘  │
│                                        │
│  ┌─ Pasek dolny (Bill Bar) ─────────┐  │
│  │  0,00 zł  ·  0 pozycji    [🔔]   │  │
│  └───────────────────────────────────┘  │
└───────────────────────────────────────┘
```

#### Layout desktop (>1024px):

```
┌────────────────────────────────────────────────────────────────────┐
│  Pasek górny: 🍕 Pizza Forno  |  [Dowóz ▾ Odbiór]  |  🔍  |  🔔  │
├────────────────────────────────────────────────────────────────────┤
│                          │                                         │
│   STREFA STOLIKA         │   STREFA NAWIGACJI                      │
│   (lewa, 45%)            │   (prawa, 55%)                         │
│                          │                                         │
│   Wizualizacja stolika   │   Karty Maciusia (kontekstowe)         │
│   z zamówionymi daniam   │   Kategorie menu                       │
│   companions wokół       │   Items w wybranej kategorii           │
│   para, światło, blat    │   Szukaj / filtruj                     │
│                          │                                         │
│   ─────────────────      │                                         │
│   Bill bar (suma, CTA)   │                                         │
│                          │                                         │
└──────────────────────────┴─────────────────────────────────────────┘
```

#### Elementy stolika (renderowane przez SharedSceneRenderer):

| Element | Kiedy widoczny | Co reprezentuje |
|---------|---------------|----------------|
| Blat (stage.boardUrl) | Zawsze | Powierzchnia stołu — drewno/marmur/slate |
| Serwetka + sztućce | Gdy stolik pusty | Wizualny placeholder, "czekamy na Ciebie" |
| Danie główne (sr-pizza) | Po dodaniu | Renderowane z warstwami jak w Spectacle |
| Companions (sr-companion) | Po dodaniu napoju/sosu | Butelki/kubki obok dania |
| Para (sr-steam) | Gorące danie na stole | Atmosfera, apetyt |
| Okruszki (sr-crumb) | Po dodaniu pizzy | Subtelny realizm |
| Karteczka kelnera | Gdy Maciuś sugeruje | Fizyczny obiekt = sugestia |
| Stojak promo | Gdy aktywna promocja | Nieprzegapialna, ale nieinwazyjna |
| Rachunek (receipt) | Po tap "Zamów" | Przejście do checkout |

#### KOSZYK = STOLIK

**Nie ma osobnego koszyka.** Stolik JEST koszykiem. Klient widzi:
- Wizualnie: dania na stole (renderowane)
- Numerycznie: Bill Bar na dole (kwota + ilość pozycji)

---

### 2.4 Bill Bar (Pasek rachunku) — stały element na dole

```
┌─────────────────────────────────────────────────────┐
│  🧾 37,00 zł · 2 pozycje     [Zamów →]     [🔔]   │
└─────────────────────────────────────────────────────┘
```

| Element | Funkcja |
|---------|---------|
| Kwota | Suma z CartEngine (server-authoritative) |
| Liczba pozycji | Visual counter |
| [Zamów →] | CTA → przechodzi do Rachunku (checkout). Disabled gdy 0 pozycji |
| [🔔] | Dzwonek na Maciusia (otwiera notepad) |

**Bill Bar jest ZAWSZE widoczny** (fixed bottom). Nawet w Kompozytorze (tam w zminiaturyzowanej formie).

#### Bill Bar rozwinięty (tap na kwotę / swipe up):

```
┌─────────────────────────────────────────────────────┐
│  Twoje zamówienie                           [✕]     │
│  ─────────────────────────────────────────────      │
│  🍕 Diavola duża ×1              34,00 zł   [✎][✕] │
│     + sos czosnkowy               +3,00 zł          │
│  🥤 Coca-Cola 500ml ×1            8,00 zł       [✕] │
│  ─────────────────────────────────────────────      │
│  Suma produktów                   45,00 zł          │
│  Dostawa                           8,00 zł          │
│  ─────────────────────────────────────────────      │
│  RAZEM                            53,00 zł          │
│                                                     │
│  [🗑 Wyczyść stolik]        [Zamów — 53,00 zł →]    │
└─────────────────────────────────────────────────────┘
```

**Akcje na pozycji:**
- [✎] = edytuj (otwiera Kompozytor z tym daniem, preloadowane opcje)
- [✕] = usuń (confirm: "Na pewno zabrać ze stolika?" → animacja zniknięcia z blatu)
- Tap na "×1" → zmiana ilości (+/-) inline

**Zmiana ilości:**
- Tap "×1" → pojawia się [-] 1 [+] inline
- Przy 0 → item znika (z potwierdzeniem)

---

### 2.5 Przełączanie kanału (Dowóz ↔ Odbiór)

#### Gdzie jest przełącznik:

**Pasek górny** — dropdown/toggle obok nazwy restauracji:

```
┌────────────────────────────────────────┐
│ 🍕 Pizza Forno   [🏍️ Dowóz ▾]    🔍   │
└────────────────────────────────────────┘
```

Tap → dropdown:
```
╭────────────────────────╮
│  🏍️ Dowóz              │  ← aktywny (check)
│     ~30 min · min. 30zł│
│  ─────────────────────  │
│  🏪 Odbiór osobisty    │
│     ~20 min · bez min. │
╰────────────────────────╯
```

#### Co się dzieje przy zmianie kanału:

1. **Ceny się przeliczają** (delivery_fee dodany/usunięty z CartEngine)
2. **Minimalana kwota** się zmienia (delivery może mieć min. 30 zł)
3. **Dostępność** — niektóre itemy mogą być niedostępne na dowóz (np. lody)
4. **ETA** się zmienia (dowóz +10-15 min vs odbiór)
5. **Checkout form** zmienia sekcję (adres vs godzina odbioru)

#### Ważne zasady:

- Zmiana kanału **NIE czyści stolika** — pozycje zostają (chyba że niedostępne na nowy kanał)
- Jeśli item jest niedostępny na nowy kanał → toast: "Pizza XL niedostępna na dowóz — usunięta ze stolika"
- Bill Bar natychmiast przelicza cenę po zmianie
- Domyślny kanał = z Doorway (klient wybrał na wejściu)
- Klient MOŻE zmienić kanał w dowolnym momencie (nawet z pełnym stolikiem)

#### Wizualny indicator kanału na stoliku:

- **Dowóz:** W rogu stolika leży torebka delivery (subtelny element sceny)
- **Odbiór:** Na stoliku stoi numerek (tabliczka z numerem zamówienia, jak w restauracji)

To daje klientowi wizualne potwierdzenie wybranego trybu bez potrzeby czytania tekstu.

---

### 2.6 STAN 2: Karta Menu (nawigacja po daniach)

Strefa nawigacji pod stolikiem. Nie jest osobnym ekranem — jest CZĘŚCIĄ widoku stolika (dolne 60% na mobile).

#### Hierarchia:

```
Karty kontekstowe (Maciuś)     ← dynamiczne, max 1-3 karty
  ↓
Kategorie (tabs/chips)          ← Pizza, Sałatki, Napoje, Desery
  ↓
Items w kategorii               ← Karty dań (mini sceny)
```

#### Karty kontekstowe Maciusia:

Pojawiają się NAD kategoriami. Wyglądają jak fizyczne karty/bilety leżące na stole (nie jak UI elementy):

```
╭─────────────────────────────────────────────╮
│  ↻ Twoje ostatnie                           │
│  Margherita + Cola · 36 zł                  │
│                         [Zamów ponownie]     │
╰─────────────────────────────────────────────╯

╭─────────────────────────────────────────────╮
│  ☀️ Zestaw lunchu (do 15:00)                │
│  Dowolna pizza + napój                      │
│  29 zł (oszcz. 11 zł)   [Zobacz zestaw]    │
╰─────────────────────────────────────────────╯
```

Zasady:
- Max 2 karty kontekstowe jednocześnie (nie zalewamy)
- Można je dismissować (swipe prawo → znika, nie wraca w tej sesji)
- Są generowane server-side (PHP + reguły czasowe/kontekstowe)
- NIE wymagają LLM — to logika reguł (if/then), nie generatywna AI

#### Items w kategorii:

Wyświetlane jako **karty z mini-sceną** (thumbnail renderowany przez silnik warstw):

```
╭──────────╮  ╭──────────╮  ╭──────────╮
│ [scena]  │  │ [scena]  │  │ [scena]  │
│ Diavola  │  │ Pepperoni│  │ Margherit│
│ 34 zł    │  │ 32 zł    │  │ 28 zł    │
│   [+]    │  │   [+]    │  │   [+]    │
╰──────────╯  ╰──────────╯  ╰──────────╯
```

**Tap [+] na prostym itemie (napój, sos):** → Od razu dodaje na stolik (animacja fly-to-table). Nie otwiera Kompozytora.

**Tap na karcie dania (pizza, sałatka, danie z opcjami):** → Otwiera KOMPOZYTOR (pełny ekran).

Heurystyka:
- `has_modifiers || has_variants || has_scene` → otwórz Kompozytor
- Proste itemy (napoje, sosy, desery bez opcji) → instant add z animacją

---

### 2.7 STAN 3: Kompozytor (Il Spettacolo) — pełny ekran

Otwierany gdy klient tapuje danie z opcjami. Pełnoekranowy overlay.

```
╔══════════════════════════════════════════════════════╗
║  [← Powrót]                              [Zamknij]  ║
║                                                      ║
║  ┌─── Scena (SharedSceneRenderer fullscreen) ─────┐  ║
║  │                                                 │  ║
║  │     Pizza renderowana z warstwami               │  ║
║  │     Para, oświetlenie, atmosfera                │  ║
║  │     ŻYWA — reaguje na zmiany modyfikatorów     │  ║
║  │                                                 │  ║
║  │     Companions wizualne wokół (dodane mody)     │  ║
║  │                                                 │  ║
║  └─────────────────────────────────────────────────┘  ║
║                                                      ║
║  ── DIAVOLA ──                                       ║
║  Salami pikante · jalapeño · mozzarella fior di latt ║
║                                                      ║
║  ┌─ Opcje ─────────────────────────────────────────┐  ║
║  │  Rozmiar:    (Mała 28zł) (●Duża 34zł)          │  ║
║  │  Ciasto:     (●Tradycyjne) (Cienkie)           │  ║
║  │  Połówka:    (●Cała) (Pół na pół +5zł)         │  ║
║  └──────────────────────────────────────────────────┘  ║
║                                                      ║
║  ┌─ Dodatki ───────────────────────────────────────┐  ║
║  │  💬 "Większość klientów dodaje sos czosnkowy"   │  ║
║  │                                                  │  ║
║  │  ╭──────╮ ╭──────╮ ╭──────╮ ╭──────╮           │  ║
║  │  │🧄 +3 │ │🧀 +5 │ │🫒 +3 │ │🌶️ +2│           │  ║
║  │  │sos cz│ │ex.moz│ │oliwki│ │jalapñ│           │  ║
║  │  ╰──────╯ ╰──────╯ ╰──────╯ ╰──────╯           │  ║
║  └──────────────────────────────────────────────────┘  ║
║                                                      ║
║  ┌──────────────────────────────────────────────────┐  ║
║  │  [   Postaw na stoliku — 37,00 zł   ]           │  ║
║  └──────────────────────────────────────────────────┘  ║
╚══════════════════════════════════════════════════════╝
```

#### Interakcja Maciusia w Kompozytorze:

- **Sugestia modyfikatora** (social proof): "85% klientów dodaje sos czosnkowy"
- **Sugestia companion:** "Do Diavoli pasuje Peroni. Dodać? +12 zł" (mini karta pod dodatkami)
- **Voice (opcjonalny):** Ikonka 🔊 odtwarza opis dania przez TTS

Sugestie Maciusia w Kompozytorze NIE są generowane przez LLM w runtime. Są predefiniowane przez admina (reguły companion + popular_modifiers z bazy).

#### Po "Postaw na stoliku":

1. Animacja: danie "leci" z Kompozytora na stolik (shrink + translate)
2. Powrót do widoku Stolika
3. Danie pojawia się na stole (renderowane)
4. Karteczka kelnera pojawia się obok: "Coś do picia?" z 2-3 opcjami
5. Bill Bar aktualizuje kwotę

---

### 2.8 STAN 4: Rachunek (Il Conto) — checkout

Otwierany z Bill Bar → [Zamów →].

#### Wizualnie:

Nie jest to "checkout form" — to jest **rachunek restauracyjny** który odwracasz i wypełniasz dane do dostawy.

```
╔══════════════════════════════════════════════════════╗
║  ┌─── RACHUNEK ────────────────────────────────────┐ ║
║  │                                                  │ ║
║  │  Pizza Forno                                     │ ║
║  │  ──────────────────────────────────              │ ║
║  │  Diavola duża              34,00 zł              │ ║
║  │    + sos czosnkowy          +3,00 zł             │ ║
║  │  Coca-Cola 500ml             8,00 zł             │ ║
║  │  ──────────────────────────────────              │ ║
║  │  Suma                       45,00 zł             │ ║
║  │  Dostawa                     8,00 zł             │ ║
║  │  ══════════════════════════════════              │ ║
║  │  DO ZAPŁATY                 53,00 zł             │ ║
║  │                                                  │ ║
║  └──────────────────────────────────────────────────┘ ║
║                                                      ║
║  ┌─── DANE DOSTAWY ───────────────────────────────┐  ║
║  │                                                  │  ║
║  │  Imię *          [________________]              │  ║
║  │  Telefon *       [________________]              │  ║
║  │  Email           [________________]              │  ║
║  │                                                  │  ║
║  │  Adres *         [________________]              │  ║  ← tylko Dowóz
║  │  Uwagi kurier    [________________]              │  ║
║  │                                                  │  ║
║  │  Godzina odbioru [________________]              │  ║  ← tylko Odbiór
║  │                                                  │  ║
║  └──────────────────────────────────────────────────┘  ║
║                                                      ║
║  ┌─── PŁATNOŚĆ ───────────────────────────────────┐   ║
║  │  (●) 💵 Gotówka przy dostawie                  │   ║
║  │  ( ) 💳 Karta przy dostawie                    │   ║
║  │  ( ) 🌐 Online (BLIK/przelew) — wkrótce       │   ║
║  └─────────────────────────────────────────────────┘   ║
║                                                      ║
║  ┌─── KOD RABATOWY ──────────────────────────────┐    ║
║  │  [________________] [Zastosuj]                 │    ║
║  └────────────────────────────────────────────────┘    ║
║                                                      ║
║  □ Akceptuję regulamin *                             ║
║                                                      ║
║  ┌──────────────────────────────────────────────────┐  ║
║  │  [     ZAMAWIAM I PŁACĘ — 53,00 zł     ]        │  ║
║  └──────────────────────────────────────────────────┘  ║
╚══════════════════════════════════════════════════════╝
```

#### Kluczowe cechy:

1. **Autofill z localStorage:** Imię, telefon, email, adres — zapamiętane z ostatniego zamówienia
2. **Walidacja inline:** Błędy pod polem, nie modal
3. **Promo code:** Pole na kod + "Zastosuj" → CartEngine przelicza → kwota się zmienia
4. **ETA visible:** "Szacowany czas: ~35 min" pod przyciskiem
5. **Edycja zamówienia:** Link "Wróć do stolika" nad rachunkiem — nie traci danych formularza

#### Flow techniczny checkout:

```
1. Klient tap "Zamów" → POST init_checkout (lock_token, TTL 5min)
2. Klient wypełnia form → tap "Zamawiam"
3. Frontend: waliduje → POST guest_checkout (lock_token + dane + cart lines)
4. Backend: CartEngine.calculate() (final) → INSERT sh_orders → return orderNumber + trackingToken
5. Frontend: localStorage.save(trackingToken, phone) → przechodzi do POTWIERDZENIA
```

---

### 2.9 STAN 5: Potwierdzenie (Grazie!)

```
╔══════════════════════════════════════════════════════╗
║                                                      ║
║         ╭────────────────────────╮                   ║
║         │         ✓              │                   ║
║         │   Zamówienie złożone!  │                   ║
║         │                        │                   ║
║         │   Nr: #2847            │                   ║
║         │   ETA: ~35 min         │                   ║
║         ╰────────────────────────╯                   ║
║                                                      ║
║  💬 Maciuś: "Dziękuję! Zaraz zabieramy się          ║
║     do pracy. Powiadomimy SMS-em."                   ║
║                                                      ║
║  ┌──────────────────────────────────────────────────┐ ║
║  │  [  Śledź zamówienie  ]                          │ ║
║  │  [  Zamów więcej  ]                              │ ║
║  └──────────────────────────────────────────────────┘ ║
║                                                      ║
╚══════════════════════════════════════════════════════╝
```

Po potwierdzeniu:
- Stolik "czyści się" (animacja — kelner zabiera)
- SMS do klienta z linkiem do śledzenia
- "Zamów więcej" → nowy pusty stolik
- "Śledź zamówienie" → istniejący track.html

---

## 3. Maciuś — logika decyzyjna (BEZ LLM w 90% przypadków)

### 3.1 Zasada: Reguły first, LLM only for freetext

| Sytuacja | Mechanizm | LLM potrzebny? |
|----------|-----------|----------------|
| Klient wraca (ma historię) | Reguła: if lastOrder exists → karta "Powtórz" | ❌ |
| Pora lunchu + aktywny zestaw | Reguła: if hour ∈ [11,15] && lunch_set active → karta | ❌ |
| Po dodaniu pizzy → sugestia napoju | Reguła: companion_rules (pizza→drinks) | ❌ |
| Aktywna promocja | Reguła: if promo active for channel → karta promo | ❌ |
| Klient bezczynny >20s | Reguła: show "Potrzebujesz pomocy?" → bell hint | ❌ |
| Popular modifier sugestia | Reguła: if modifier_uptake >60% → "Większość dodaje X" | ❌ |
| Klient pisze/mówi freetext | LLM: parsuj intencję → mapuj na SKU/akcję | ✅ |
| Klient pyta "co bez glutenu?" | LLM: filtruj menu po tagach + generuj odpowiedź | ✅ |
| Klient pyta "co polecacie do piwa?" | LLM: kontekst menu + pairing rules → odpowiedź | ✅ |

**90% interakcji Maciusia = logika reguł w PHP (zero kosztu, zero latency).**  
**10% = LLM (Groq free) — tylko gdy klient AKTYWNIE wzywa Maciusia (dzwonek + freetext).**

### 3.2 Karty kontekstowe — priorytet wyświetlania

```
PRIORYTET 1 (max 1):
  - Powracający klient + <72h od ostatniego zamówienia → "Powtórz ostatnie"
  
PRIORYTET 2 (max 1):
  - Aktywna promocja dla bieżącego kanału + pory dnia → Karta promo

PRIORYTET 3 (max 1, tylko jeśli P1 i P2 nie zajęły obu slotów):
  - Zestaw dnia (aktywny w danym przedziale godzin)
  - LUB Nowość w menu (< 7 dni, oznaczona "is_new")
  - LUB "Popularne teraz" (top 1 danie z ostatnich 24h zamówień)

LIMIT: Max 2 karty kontekstowe wyświetlane jednocześnie.
```

### 3.3 Upsell — karteczki kelnera po dodaniu dania

```
TRIGGER: Klient dodał danie na stolik

REGUŁY (sprawdzane w kolejności, PIERWSZA pasująca → wyświetl karteczkę):

1. IF na stoliku jest pizza AND nie ma napoju:
   → Karteczka "Coś do picia?" + top 2 companions type=drink dla tego SKU

2. IF na stoliku jest danie AND nie ma sosu:
   → Karteczka "Sos do tego?" + top 2 companions type=sauce

3. IF suma > 40 zł AND nie ma deseru AND hour > 17:
   → Karteczka "Na deser?" + top 1 dessert

4. IF aktywna promo "2za1" AND na stole 1 pizza AND suma kwalifikuje:
   → Karteczka "Druga pizza za 1 zł! Która?"

LIMIT: Max 1 karteczka po każdym dodaniu. Nie spamuj. 
COOLDOWN: Jeśli klient dismiss karteczkę, nie pokazuj następnej przez 60s.
DISMISS: Swipe prawo lub tap "×" — karteczka znika, nie wraca dla tego dania.
```

### 3.4 Dzwonek (🔔) — panel Maciusia

Otwierany TYLKO na żądanie klienta. Wygląd: notepad kelnera.

```
╭─────────────────────────────────────╮
│  ✏️ Maciuś — Twój kelner           │
│  ───────────────────────────        │
│                                     │
│  Jak mogę pomóc?                    │
│                                     │
│  ○ Co jest dzisiaj dobre?           │
│  ○ Mam alergię / dietę             │
│  ○ Coś na imprezę (4+ osoby)       │
│  ○ Jaki napój do [danie na stole]? │
│                                     │
│  ──── lub ────                      │
│  [🎤]  [_____wpisz pytanie_____]   │
│                                     │
╰─────────────────────────────────────╯
```

**Opcje predefiniowane** (reguły, bez LLM):
- "Co jest dzisiaj dobre?" → losuje 3 dania (weighted random: popularne + z promo + nowości)
- "Mam alergię / dietę" → sub-menu z filtrami (bezglutenowe, wege, bez laktozy)
- "Na imprezę" → shows party combos / multi-pizza deals
- "Napój do [X]?" → companion pairing rules dla dania na stole

**Freetext / głos** (LLM):
- Klient pisze lub mówi → backend parsuje → odpowiedź w stylu notepad
- Odpowiedź ZAWSZE zawiera actionable items (karty dań do tapnięcia)
- LLM NIE mówi "Niestety nie mogę..." — zawsze proponuje alternatywę

---

## 4. Edge cases

### 4.1 Restauracja zamknięta

- Doorway blokuje wejście (jak teraz) — "Zamknięte. Otwieramy o X."
- Jeśli pre-order enabled: "Zamów na jutro?" → wchodzi na stolik z badge "Pre-order"
- Stolik w trybie pre-order ma zegar: "Zamówienie na: [jutro 12:00 ▾]" — klient wybiera godzinę

### 4.2 Danie niedostępne (86'd / out of stock)

- Karta dania w menu: szara, przekreślona cena, "Niedostępne dzisiaj"
- Nie można tapnąć (disabled)
- Jeśli klient MA to danie na stole (dodał i potem 86'd się zmieniło — rzadkie):
  - Toast: "Diavola właśnie się skończyła — usunęliśmy ją ze stolika. Przepraszamy!"
  - Automatyczne usunięcie + karteczka Maciusia: "Zamiast Diavoli polecam Pepperoni"

### 4.3 Minimalna kwota zamówienia (delivery)

- Bill Bar pokazuje: "37,00 zł · min. 30 zł ✓" (zielone) lub "22,00 zł · min. 30 zł (brakuje 8 zł)" (pomarańczowe)
- CTA "Zamów" jest disabled dopóki nie osiągnięto minimum
- Karteczka Maciusia (when close to min): "Brakuje 8 zł do minimum. Cola 8 zł zamknie sprawę!"

### 4.4 Promo code

- Pole w checkout ("Kod rabatowy")
- LUB klient może powiedzieć Maciusiowi (🔔): "Mam kod PIZZA20" → Maciuś aplikuje
- Wizualnie na rachunku: "Rabat -20%: -9,00 zł" (przekreślona stara kwota)

### 4.5 Klient odświeża stronę / wraca po czasie

- Stolik (cart) zapisany w `localStorage` (jak teraz)
- Karty kontekstowe odświeżane z serwera (mogły się zmienić)
- Jeśli klient wraca po >2h → toast: "Twój stolik czekał! Sprawdź czy ceny aktualne."
  - CartEngine recalculate → jeśli ceny się zmieniły → highlight + info

### 4.6 Wyszukiwanie (🔍)

- Ikona w pasku górnym → rozwija pole search
- Wpisanie tekstu → instant filter items (client-side, po załadowanym menu)
- Wyniki: karty dań w strefie nawigacji (filtred view)
- "Bez wyników?" → Maciuś sugeruje: "Nie znalazłem, ale może interesuje Cię..."
- Search NIE wymaga LLM — prosty text match na nazwie + description + tagach

### 4.7 Alergeny / diety

- Dzwonek → "Mam alergię" → checkboxy: [gluten] [laktoza] [orzechy] [jaja]
- Po wybraniu: menu filtrowane na stałe (do reset) + badge w pasku: "🚫 gluten"
- Filtr persistence: sesja (nie localStorage — bo dieta może się zmienić)
- Items niezgodne z filtrem: ukryte LUB szare z ostrzeżeniem

### 4.8 Danie z wariantami (rozmiary)

- W karcie menu: "od 28 zł" + badge "Rozmiary ›"
- Tap → Kompozytor z selector rozmiaru (nie osobna strona)
- Zmiana rozmiaru → cena się zmienia, wizualna scena reaguje (większa pizza)

### 4.9 Pół na pół (half-half)

- W Kompozytorze: toggle "Pół na pół (+5 zł)"
- Po włączeniu: scena dzieli się wizualnie, wybór drugiej połówki
- Druga połówka z dropdownu lub sugestii Maciusia

---

## 5. Panel admina — co restaurator konfiguruje

### 5.1 Sekcja "Maciuś Online" w module Settings

| Ustawienie | Typ | Efekt |
|-----------|-----|-------|
| **Tekstura stolika** | Select: drewno jasne / ciemne / marmur / slate | Zmienia boardUrl w SharedSceneRenderer |
| **Danie dnia** | Select danie + przedział godzin | Karta kontekstowa "Danie dnia" |
| **Zestaw lunchu** | Builder: dania + cena + godziny | Karta "Zestaw lunchu" |
| **Aktywne promocje** | Lista promo (istniejący moduł sh_promo_codes) | Karta promo na stoliku |
| **Upsell rules** | Drag & drop: "Po pizza → sugeruj drinks/sauces" | Karteczki kelnera |
| **Companion pairings** | Istniejący moduł online_studio/companions | Sugestie w Kompozytorze |
| **Popular threshold** | Slider: % (domyślnie 60%) | "Większość klientów dodaje X" jeśli > threshold |
| **Maciuś aktywny** | Toggle on/off | Wyłącza karty kontekstowe + dzwonek (pure menu mode) |
| **Styl odpowiedzi** | Select: ciepły / profesjonalny / żartobliwy | Ton tekstów Maciusia |
| **Pre-order** | Toggle + max dni do przodu | Zamawianie gdy zamknięte |
| **Min. zamówienie delivery** | Kwota | Blokada CTA poniżej kwoty |

### 5.2 Automatyczne (zero konfiguracji admina):

- Popularne dania (z sh_order_lines, last 30 days)
- "Powtórz ostatnie" (z localStorage klienta)
- Pora dnia routing (lunch/obiad/kolacja detection)
- ETA estimate (z existing sh_tenant_settings.online_default_eta_min)
- Modifier popularity stats (% uptake from sh_order_lines)

---

## 6. Stany Bill Bar (reguły)

```
STAN A: Stolik pusty
  └─ Bill Bar: "Wybierz coś z karty"  [🔔]  (CTA disabled)

STAN B: 1+ pozycja, poniżej minimum (only delivery)
  └─ Bill Bar: "22,00 zł · brakuje 8 zł do min."  [🔔]  (CTA disabled, pomarańczowy)

STAN C: 1+ pozycja, powyżej minimum (lub takeaway)
  └─ Bill Bar: "53,00 zł · 3 pozycje"  [Zamów →]  [🔔]  (CTA active, złoty)

STAN D: W trakcie checkout
  └─ Bill Bar ukryty (checkout overlay jest pełnoekranowy)

STAN E: Po złożeniu zamówienia
  └─ Bill Bar: "Zamówienie złożone! 🎉"  [Śledź]  (zmienia kolor na zielony)
```

---

## 7. Animacje i przejścia

| Przejście | Animacja | Czas |
|-----------|----------|------|
| Dodanie dania na stolik | Shrink card → fly to table area → materialize | 0.4s |
| Usunięcie ze stolika | Fade out + slide down z table | 0.3s |
| Otwarcie Kompozytora | Slide up from bottom (fullscreen overlay) | 0.35s |
| Zamknięcie Kompozytora | Slide down + danie flies to table | 0.4s |
| Karteczka kelnera pojawia się | Slide in from right + subtle bounce | 0.3s |
| Dismiss karteczki | Slide out to right | 0.2s |
| Bill Bar expand | Slide up (bottom sheet) | 0.3s |
| Otwarcie checkoutu | Crossfade / slide up | 0.35s |
| Channel switch | Table surface subtle crossfade | 0.5s |
| Item 86'd removal | Shake + fade + toast | 0.4s |

---

## 8. Responsywność — breakpoints

| Breakpoint | Layout | Table position |
|-----------|--------|----------------|
| < 500px (mobile portrait) | Single column: table top 35%, nav bottom 65% | Sticky top, scrolls with page |
| 500-768px (tablet/landscape) | Single column: table top 40%, nav bottom 60% | Sticky top |
| 768-1024px (tablet landscape) | Two column: table left 40%, nav right 60% | Fixed left panel |
| > 1024px (desktop) | Two column: table left 45%, nav right 55% | Fixed left panel |

### Mobile-specific:

- Bill Bar: fixed bottom, always visible
- Table: compact (max 3 items visible, horizontal scroll for more)
- Kompozytor: fullscreen bottom sheet (100vh)
- Dzwonek notepad: bottom sheet (80vh)
- Channel switch: dropdown from topbar

### Desktop-specific:

- Bill Bar: embedded in table panel (bottom of left column)
- Table: always visible, larger scene
- Kompozytor: overlay (max 560px width, centered)
- Dzwonek notepad: floating panel (right side)
- Channel switch: inline buttons in topbar

---

## 9. Dane techniczne — co backend musi dostarczać

### 9.1 Nowy endpoint: `POST api/online/engine.php` → `action: get_table_context`

```json
// Request
{
  "tenantId": 1,
  "action": "get_table_context",
  "channel": "Delivery",
  "clientToken": "abc123...",  // localStorage token for history
  "hour": 18,
  "dayOfWeek": "friday"
}

// Response
{
  "success": true,
  "data": {
    "tableStyle": {
      "boardUrl": "/uploads/scene_kit/dark_wood.jpg",
      "ambient": { "steam": { "count": 0 }, "crumbs": { "count": 0 } }
    },
    "contextCards": [
      {
        "type": "repeat_last",
        "title": "Twoje ostatnie",
        "subtitle": "Margherita + Cola · 36,00 zł",
        "cta": "Zamów ponownie",
        "items": [
          { "sku": "MARGHERITA", "variant": "large", "qty": 1 },
          { "sku": "COLA-500", "qty": 1 }
        ]
      },
      {
        "type": "promo",
        "title": "🎉 Piątkowa promocja",
        "subtitle": "Druga pizza za 1 zł!",
        "cta": "Zobacz szczegóły",
        "promoCode": "PIATEK2ZA1"
      }
    ],
    "upsellRules": [
      { "trigger": "category:pizza", "suggest": "category:drinks", "text": "Coś do picia?" },
      { "trigger": "category:pizza", "suggest": "type:sauce", "text": "Sos do pizzy?" },
      { "trigger": "total>40", "suggest": "category:desserts", "text": "Deser na koniec?" }
    ],
    "popularModifiers": {
      "PIZZA-DIAVOLA": [
        { "sku": "MOD-GARLIC-SAUCE", "name": "Sos czosnkowy", "price": 3.00, "uptakePercent": 87 }
      ]
    },
    "channelInfo": {
      "current": "Delivery",
      "deliveryFee": 8.00,
      "minOrder": 30.00,
      "etaMin": 35,
      "takeawayEtaMin": 20
    },
    "maciusEnabled": true,
    "maciusTone": "warm"
  }
}
```

### 9.2 Istniejące endpointy (bez zmian):

- `get_menu` / `get_scene_menu` → lista kategorii + items
- `get_dish` / `get_scene_dish` → szczegóły dania + scena + mody
- `cart_calculate` → walidacja cen server-side
- `init_checkout` → lock token
- `guest_checkout` → finalizacja
- `delivery_zones` → sprawdzenie adresu

### 9.3 Nowy endpoint (opcjonalny, Phase 2): `POST api/macius/converse.php`

Używany TYLKO gdy klient pisze/mówi freetext (dzwonek → input). Nie jest wymagany do Phase 1.

```json
// Request
{
  "tenantId": 1,
  "message": "co jest bez glutenu?",
  "context": {
    "channel": "Delivery",
    "cartSkus": ["PIZZA-DIAVOLA"],
    "hour": 19,
    "filters": []
  }
}

// Response
{
  "success": true,
  "data": {
    "text": "Mamy 3 pozycje bezglutenowe: Sałatka Cesare, Bowl z kurczakiem i Sałatka grecka. Każda pizza może być na cieście GF (+5 zł).",
    "items": [
      { "sku": "CESARE", "name": "Sałatka Cesare", "price": 24.00 },
      { "sku": "BOWL-CHICKEN", "name": "Bowl z kurczakiem", "price": 28.00 }
    ],
    "filters_applied": ["gluten_free"]
  }
}
```

---

## 10. Fazy implementacji

### FAZA 1: Stolik + Menu (bez LLM, bez głosu)
- Nowy layout: stolik (góra) + nawigacja (dół)
- Bill Bar zamiast FAB + cart drawer
- Karty kontekstowe (reguły PHP)
- Karteczki kelnera po dodaniu dania (upsell reguły)
- Kompozytor pełnoekranowy (istniejący dish-sheet przerobiony)
- Channel switch w topbar
- Checkout jako overlay (istniejący, restyled)
- **Scope:** ~15-20 sesji dev

### FAZA 2: Maciuś (LLM + notepad)
- Dzwonek → notepad kelnera
- Freetext input → LLM → odpowiedź z items
- Voice input (Web Speech API STT)
- Kontekstowe opcje w notepadzie (bazowane na stanie stolika)
- **Scope:** ~5-8 sesji dev

### FAZA 3: Personalizacja + social proof
- "Powtórz ostatnie" (localStorage history)
- Pora dnia routing (lunch/dinner/late)
- Popular modifier stats ("85% klientów dodaje X")
- Nowości / dania dnia z admin panel
- **Scope:** ~4-6 sesji dev

### FAZA 4: Wizualny stolik (SharedSceneRenderer)
- Dania renderowane na stoliku (nie tylko miniatury)
- Companions wizualnie na stole
- Ambient effects (para, okruszki)
- Animacje fly-to-table
- **Scope:** ~8-10 sesji dev

---

## 11. Metryki sukcesu (KPI)

| Metryka | Obecna (estymacja) | Cel po wdrożeniu |
|---------|-------------------|------------------|
| Avg order value | ~42 zł | 52+ zł (+24%) |
| Conversion rate (wejście→zamówienie) | ~15% | 22%+ |
| Upsell acceptance | ~8% | 25%+ |
| Repeat order rate | ~20% | 35%+ |
| Cart abandonment | ~35% | 20% |
| Time to first item added | ~90s | 30s (repeat) / 60s (new) |
| Items per order | ~2.1 | 2.8+ |

---

## 12. Ryzyka i mitygacja

| Ryzyko | Prawdopodobieństwo | Mitygacja |
|--------|-------------------|-----------|
| Klient nie rozumie nowego UX | Średnie | Progressive disclosure: pierwszy raz = tooltip "Wybierz z karty poniżej, danie pojawi się na stoliku" |
| Wolne ładowanie scen | Niskie (lekki DOM) | Lazy load: scena stolika renderuje się jako placeholder, dania pojawiają się po dodaniu |
| LLM timeout / halucynacja | Niskie (LLM tylko dla freetext) | Fallback: jeśli LLM fail → "Przepraszam, oto nasze menu" + karty kategorii |
| Mobile performance | Średnie | Reduced motion: na słabych urządzeniach → statyczne miniatury zamiast pełnych scen |
| Właściciel nie konfiguruje | Wysokie | Smart defaults: wszystko działa out-of-box bez konfiguracji (popularne z bazy, companions z menu, upsell generyczne) |
| A/B testing trudny | N/A | Feature flag: `macius_table_enabled` w sh_tenant_settings → toggle per tenant |

---

## 13. Słownik pojęć (dla agentów implementujących)

| Termin | Znaczenie |
|--------|-----------|
| **La Tavola** | Cały system online ordering z Maciusiem |
| **Stolik** | Główny widok = wizualny koszyk klienta |
| **Karta Menu** | Nawigacja po daniach (pod stolikiem na mobile) |
| **Kompozytor** | Pełnoekranowy edytor dania (modyfikatory, rozmiary, pół/pół) |
| **Karteczka kelnera** | Upsell sugestia — fizyczny obiekt na stole, nie chat |
| **Karta kontekstowa** | Smart suggestion card (powtórz, promo, zestaw dnia) |
| **Bill Bar** | Stały pasek z sumą + CTA "Zamów" |
| **Dzwonek** | Przycisk wzywania Maciusia (otwiera notepad) |
| **Notepad** | Panel interakcji z Maciusiem (opcje + freetext/głos) |
| **Rachunek** | Checkout — podsumowanie + formularz danych |
| **Spektakl** | Pełnoekranowa scena dania w Kompozytorze |
| **Fly-to-table** | Animacja dodawania dania z menu/kompozytora na stolik |
