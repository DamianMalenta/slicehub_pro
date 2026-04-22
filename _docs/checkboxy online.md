# CHECKBOXY ONLINE — stan, mapa i instrukcje dla kolejnych okien Cursor

> **Cel pliku:** po przeczytaniu tego pliku nowe okno Cursor ma bez pomylek wiedziec:
> 1. na jakim etapie jest modul Online/Studio,
> 2. co jest zrobione, co czesciowo domkniete, a co jeszcze czeka,
> 3. gdzie dokladnie szukac prawdy w kodzie i docs.
>
> **Status snapshot:** 2026-04-19/20

---

## 0. Co przeczytac najpierw

- [ ] Najpierw przeczytaj ten plik.
- [ ] Potem przeczytaj `_docs/15_KIERUNEK_ONLINE.md` — to jest aktywny kierunek.
- [ ] Potem przeczytaj `_docs/00_PAMIEC_SYSTEMU.md` — North Star i stan faktyczny.
- [ ] Jesli ruszasz API / checkout / tracker, przeczytaj `_docs/07_INTERACTION_CONTRACT.md`.
- [ ] Jesli ruszasz strukture moduow i plikow, przeczytaj `_docs/02_ARCHITEKTURA.md`.

**Nie opieraj sie na** `_docs/ARCHIWUM/06_WIZJA_MODULU_ONLINE.md` jako zrodle decyzji. To tlo historyczne, nie aktualna decyzja.

---

## 1. Aktualny kierunek

- [x] Aktywny kierunek to **Droga B: Realistyczny Counter + Drzwi**.
- [x] Storefront ma byc **teatrem fotograficznym**, nie zwyklym product gridem.
- [x] Kolejnosc scen dla klienta w aktywnej wizji:
  - Drzwi
  - Counter / Living Table
  - Checkout
  - Track
- [x] Drzwi maja zostac jako pierwszy ekran niezaleznie od dalszej drogi.
- [x] Warstwy + Director + Living Table sa technologiczna baza calej wizji.

**Zrodla prawdy:**
- `_docs/15_KIERUNEK_ONLINE.md`
- `_docs/00_PAMIEC_SYSTEMU.md`

---

## 2. Co jest juz zrobione

### 2.1 Fundament Studio / warstwy / jakosc

- [x] **Menu Studio Polish** zrobione.
  - connect-dots modyfikator -> warstwa,
  - auto-generator sceny,
  - hero picker,
  - miniatura w drzewie,
  - lepszy UX recept.
- [x] **Unifikacja rendererow** zrobiona.
- [x] **7-stopniowy pipeline realizmu** domkniety.
- [x] **Harmony Score** zrobiony.
- [x] **Magic Conform** zrobiony.
- [x] **Magic Harmonize** zrobiony.
- [x] **Style Conductor** zrobiony.
- [x] **Living Scene effects** w storefroncie zrobione.
- [x] **Optimistic save + konflikt 409** w Directorze zrobione.

**Gdzie szukac:**
- `modules/online_studio/js/director/DirectorApp.js`
- `modules/online_studio/js/director/harmony/HarmonyScore.js`
- `modules/online_studio/js/director/magic/MagicConform.js`
- `modules/online_studio/js/director/magic/MagicHarmonize.js`
- `modules/online_studio/js/tabs/conductor.js`
- `modules/online_studio/js/studio_api.js`
- `api/online_studio/engine.php`

### 2.2 Renderer i warstwa sceny

- [x] **SSOT matematyki warstwy** istnieje.
- [x] Storefront i Director korzystaja ze wspolnych podstaw renderu.
- [x] Storefront rozumie juz wiecej niz tylko scale/rotate: honoruje tez offsety, blendy, alpha, filtry, feather itd.
- [x] `SharedSceneRenderer` istnieje i jest wpiety do storefrontu.

**Gdzie szukac:**
- `core/js/scene_renderer.js` — SSOT matematyki warstwy
- `core/js/surface/SharedSceneRenderer.js` — wspolny renderer sceny
- `modules/online/js/online_renderer.js`
- `modules/online_studio/js/director/renderer/SceneRenderer.js`
- `modules/online/css/style.css`

### 2.3 Scena Drzwi

- [x] **Scena Drzwi** zrobiona.
- [x] Jest ilustracja SVG.
- [x] Jest status open / closing_soon / closed.
- [x] Jest mapa lazy-loaded.
- [x] Jest tryb statyczny.
- [x] Jest pre-order CTA dla zamknietej restauracji.

**Gdzie szukac:**
- `modules/online/js/online_doorway.js`
- `modules/online/css/doorway.css`
- `modules/online/index.html`
- `modules/online/js/online_app.js`
- `api/online/engine.php` -> akcja `get_doorway`

### 2.4 Track / PWA / mikro-interakcje

- [x] **Track** dziala przez publiczne API storefrontu.
- [x] Polling trackera jest ustawiony na `10s`.
- [x] Jest PWA: manifest, service worker, offline fallback.
- [x] Jest `ModifierOrchestrator`.

**Gdzie szukac:**
- `modules/online/track.html`
- `modules/online/js/online_track.js`
- `modules/online/js/online_api.js`
- `api/online/engine.php` -> akcja `track_order`
- `modules/online/manifest.webmanifest`
- `modules/online/sw.js`
- `modules/online/offline.html`
- `modules/online/js/surface/ModifierOrchestrator.js`

---

## 3. Co jest czesciowo domkniete / wymaga ostroznosci

### 3.1 Checkout

- [~] **Publiczny checkout storefrontu istnieje**, ale prawda jest taka:
  - publiczna sciezka klienta idzie przez `api/online/engine.php`,
  - a nie przez `api/orders/checkout.php`.
- [~] `api/orders/checkout.php` ma juz czesc logiki online (`lock_token`, `tracking_token`, stock preflight),
  ale nadal jest za `auth_guard.php`.
- [~] Czyli: **nie traktowac `api/orders/checkout.php` jako publicznego checkoutu klienta online**.

**Gdzie szukac:**
- `api/online/engine.php` -> `init_checkout`
- `api/online/engine.php` -> `guest_checkout`
- `api/orders/checkout.php`
- `_docs/07_INTERACTION_CONTRACT.md`
- `_docs/00_PAMIEC_SYSTEMU.md`

### 3.2 Delivery zones

- [~] `delivery_zones` dziala, ale pelna walidacja strefy wymaga `lat/lng`.
- [~] Sam `address` daje miekki fallback `in_zone = null`.
- [~] Czyli bez geokodowania nie ma twardego green/red dla strefy.

**Gdzie szukac:**
- `api/online/engine.php` -> `delivery_zones`
- `modules/online/js/online_api.js`
- `_docs/07_INTERACTION_CONTRACT.md`
- `_docs/00_PAMIEC_SYSTEMU.md`

### 3.3 Tracker recovery

- [~] Tracker dziala po parze:
  - `tracking_token`
  - `customer_phone`
- [~] Nie ma jeszcze fallbacku po samym `order_number`.
- [~] W UI `track.html` input jest opisany jako kod zamowienia, ale technicznie chodzi o token z potwierdzenia.

**Gdzie szukac:**
- `modules/online/track.html`
- `modules/online/js/online_api.js`
- `modules/online/js/online_track.js`
- `api/online/engine.php` -> `track_order`
- `_docs/07_INTERACTION_CONTRACT.md`

---

## 4. Co jeszcze NIE jest zrobione

### 4.1 Glowny brak produktu

- [ ] **M5 — Counter + Living Table** nie jest jeszcze domkniety jako glowny ekran produktu.
- [ ] To jest nastepny glowny etap po Drzwiach.
- [ ] Chodzi o:
  - horyzontalny swipe miedzy daniami,
  - Bottom Sheet "Komponuj / Do stolu",
  - companions persist przy swipe,
  - live price,
  - finalny kinowy flow sceny dania.

**Najpierw czytaj:**
- `_docs/15_KIERUNEK_ONLINE.md` sekcja M5
- `_docs/00_PAMIEC_SYSTEMU.md` sekcja Counter + Living Table

**Potem sprawdzaj kod:**
- `modules/online/js/online_ui.js`
- `modules/online/js/online_table.js`
- `modules/online/js/online_renderer.js`
- `modules/online/css/style.css`

### 4.2 Domkniecie checkout UX

- [ ] **M6 — Checkout path** nie jest jeszcze domkniety jako caly UX flow.
- [ ] Backend publiczny istnieje, ale trzeba ostroznie weryfikowac frontendowy przeplyw od koszyka do potwierdzenia.
- [ ] Apple Pay / Google Pay nadal sa P1, nie MVP done.

**Sprawdzaj:**
- `modules/online/js/online_checkout.js`
- `modules/online/js/online_app.js`
- `api/online/engine.php`
- `_docs/07_INTERACTION_CONTRACT.md`

### 4.3 QA / performance / fallbacki techniczne

- [ ] **M7 — QA + polish** nie jest domkniete.
- [ ] Brakuje pelnego performance auditu dla mobile i slabszych urzadzen.
- [ ] Brakuje finalnego testu real-device pod Counter / Living Table.

**Do sprawdzenia / zrobienia:**
- Lighthouse mobile
- FPS przy duzej liczbie warstw
- static mode
- dostepnosc

### 4.4 Ogony techniczne z planu

- [ ] **E8 — performance audit**: Lighthouse / FPS / urzadzenia realne.
- [ ] **E9 — canvas backend** dla `SharedSceneRenderer` nie jest domkniety jako gotowy feature produkcyjny.
- [ ] **E10 — Live Kitchen Scene WebGL** w trackerze nie jest zrobione.

**Wskazowki:**
- `core/js/surface/SharedSceneRenderer.js` ma juz logike backend selector i slady pod `canvas`,
  ale nie traktowac tego jako skonczonego backendu produkcyjnego.

### 4.5 Rzeczy odlozone swiadomie

- [ ] **G3 — AI Jobs Runner** odlozone do fazy AI.
- [ ] **G7 — Magic Workshop** odlozone do fazy AI.
- [ ] **Restaurant Viewfinder** odlozony do Fazy 3.
- [ ] **Scena Sali / gamifikacja / Time-of-Day full automatyka** odlozone.
- [ ] **Pelny 5-scenowy Film** odlozony do Fazy 5+.

**Zrodlo prawdy:** `_docs/15_KIERUNEK_ONLINE.md`

---

## 5. Najwazniejsze zasady, zeby nowe okno Cursor nie popelnilo bledu

- [ ] **Nie mylic** publicznego checkoutu storefrontu z `api/orders/checkout.php`.
- [ ] **Nie mylic** `tracking_token` z `order_number`.
- [ ] **Nie zakladac**, ze `delivery_zones` daje twarda odpowiedz bez `lat/lng`.
- [ ] **Nie rozwijac** starej archiwalnej wizji zamiast aktywnej Drogi B.
- [ ] **Nie robic** nowego renderera obok istniejacego shared renderera.
- [ ] **Nie omijac** `SceneResolver`, `CartEngine`, `WzEngine::checkAvailability`.

---

## 6. Gdzie szukac czego — szybka mapa

### Decyzje i prawda projektowa

- `_docs/checkboxy online.md` — ten plik startowy
- `_docs/15_KIERUNEK_ONLINE.md` — aktywny kierunek i roadmapa
- `_docs/00_PAMIEC_SYSTEMU.md` — North Star + stan faktyczny
- `_docs/07_INTERACTION_CONTRACT.md` — kontrakty publicznego API
- `_docs/02_ARCHITEKTURA.md` — mapa plikow

### Publiczne API storefrontu

- `api/online/engine.php`
  - `get_doorway`
  - `delivery_zones`
  - `init_checkout`
  - `guest_checkout`
  - `track_order`

### Checkout chroniony / kanoniczny

- `api/orders/checkout.php`

### Storefront UI

- `modules/online/index.html`
- `modules/online/track.html`
- `modules/online/js/online_app.js`
- `modules/online/js/online_api.js`
- `modules/online/js/online_ui.js`
- `modules/online/js/online_renderer.js`
- `modules/online/js/online_table.js`
- `modules/online/js/online_checkout.js`
- `modules/online/js/online_track.js`
- `modules/online/js/online_doorway.js`
- `modules/online/js/surface/ModifierOrchestrator.js`
- `modules/online/css/style.css`
- `modules/online/css/doorway.css`
- `modules/online/css/track.css`
- `modules/online/css/living-scene.css`

### Shared render / warstwy

- `core/js/scene_renderer.js`
- `core/js/surface/SharedSceneRenderer.js`
- `core/SceneResolver.php`
- `core/AssetResolver.php`

### Director / Studio

- `modules/online_studio/js/director/DirectorApp.js`
- `modules/online_studio/js/director/harmony/HarmonyScore.js`
- `modules/online_studio/js/director/magic/MagicConform.js`
- `modules/online_studio/js/director/magic/MagicHarmonize.js`
- `modules/online_studio/js/tabs/conductor.js`
- `modules/online_studio/js/studio_api.js`
- `api/online_studio/engine.php`

### PWA

- `modules/online/manifest.webmanifest`
- `modules/online/sw.js`
- `modules/online/offline.html`

---

## 7. Najkrotsze podsumowanie dla nowego okna

- [x] Kierunek: **Droga B — Drzwi + Counter / Living Table**
- [x] Zrobione: Studio Polish, Shared Renderer, pipeline realizmu, Harmony/Magic/Conductor, Living Scene, Drzwi, Track, PWA
- [~] Czesciowo: publiczny checkout, delivery zones, tracker recovery semantics
- [ ] Najwiekszy otwarty temat: **M5 Counter + Living Table**
- [ ] Potem: **M6 checkout UX** i **M7 QA/performance**
- [ ] Odlozone: G3/G7 AI, Viewfinder, pelny Film

---

## 8. Instrukcja pracy dla kolejnego okna Cursor

- [ ] Zacznij od przeczytania sekcji 0 i 7 tego pliku.
- [ ] Jesli masz ruszac UX klienta: otworz `_docs/15_KIERUNEK_ONLINE.md` + `modules/online/js/online_app.js`.
- [ ] Jesli masz ruszac checkout: otworz `_docs/07_INTERACTION_CONTRACT.md` + `api/online/engine.php` + `modules/online/js/online_checkout.js`.
- [ ] Jesli masz ruszac tracker: otworz `modules/online/track.html` + `modules/online/js/online_track.js` + `api/online/engine.php`.
- [ ] Jesli masz ruszac render scen: otworz `core/js/scene_renderer.js` + `core/js/surface/SharedSceneRenderer.js` + `modules/online/js/online_renderer.js`.
- [ ] Jesli masz ruszac Studio / jakosc: otworz `DirectorApp.js`, `HarmonyScore.js`, `MagicConform.js`, `MagicHarmonize.js`, `conductor.js`.
- [ ] Przed kazda zmiana sprawdz, czy nie wchodzisz przypadkiem w cos juz odlozonego do Fazy 3/Fazy AI/Fazy 5+.

> Jesli cokolwiek w tym pliku zacznie rozjezdzac sie z kodem, najpierw aktualizuj ten plik i `00_PAMIEC_SYSTEMU.md`, a dopiero potem koduj dalej.
