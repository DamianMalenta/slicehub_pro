# BRIEF DLA NOWEGO OKNA CURSOR — SliceHub Online / Immersive Doorway
> **Wygenerowano:** 2026-04-18  
> **Czytaj w tej kolejności:** ten plik → `_docs/checkboxy online.md` → `_docs/00_PAMIEC_SYSTEMU.md`  
> **Jeśli masz ruszać kod:** przeczytaj CAŁOŚĆ zanim napiszesz pierwszą linię.

---

## 0. Kto ty, kim jestem, co robimy

**Projekt:** SliceHub Enterprise — gastronomiczny system klasy enterprise.  
**Stos (ŻELAZNA ZASADA, nigdy nie łam):** Vanilla JS (ES6+) + PHP 8+ + MariaDB. Zero Node, zero npm, zero React/Vue/Angular/jQuery.  
**Rola nowego okna:** kontynuacja zadania "Immersive Doorway Rebuild" — przebudowa pierwszego ekranu storefrontu klienta.

---

## 1. Aktywny cel sesji — co właśnie robimy

### Zadanie: Immersive Doorway Rebuild

Plik planu: `c:\Users\Damian\.cursor\plans\immersive_doorway_rebuild_bb65a0c7.plan.md`

Streszczenie: obecna Scena Drzwi to dwukolumnowy layout z inlinowym SVG. Robimy **kompletny rewrite** na prawdziwy CSS 3D interaktywny mechanizm drzwi:
- Full-screen ilustracja (tło = zdjęcie z Gemini, dostarczy użytkownik)
- Liść drzwi = CSS `perspective: 1400px` + `transform-style: preserve-3d` + `rotateY(-110deg)` po kliknięciu klamki
- Animacja sekwencyjna: handle jiggle → leaf swing → interior glow → white flash → transition do menu
- Hotspot system: niewidoczne strefy na ilustracji, klik → popup z info (godziny, adres, telefon)
- Logo managera w szybie drzwi (`mix-blend-mode: screen`)
- Karteczka "Zaraz wróce" gdy restauracja zamknięta
- **Jednocześnie:** przebudowa nawigacji menu na akordeion kategorii (collapse/expand)

### 6 zadań do wykonania (TODO):

| id | Opis | Status |
|----|------|--------|
| `door_rebuild_html` | Przebuduj `#doorway` w `index.html`: full-screen layout, `.dw-stage`, `.dw-leaf` (front/back), `.dw-hotspots`, `.dw-popup`, usuń `#hero-welcome` | PENDING |
| `door_css` | Napisz nowy `doorway.css`: CSS 3D preserve-3d, animacja handle rotate, leaf swing rotateY, interior glow, noteSwing @keyframes, hotspot zones, popup, logo zone z mix-blend-mode, ambient particles, responsive | PENDING |
| `door_js` | Przepisz `online_doorway.js`: usuń `renderDoorSvg`, nowa logika 3D animacji, hotspot popup manager, logo inject, closed note + preorder flow, skip=doors, channel picker | PENDING |
| `doorway_logo_backend` | Dodaj `logoUrl` do responsa `get_doorway` w `api/online/engine.php` | PENDING |
| `menu_accordion` | Przebuduj `online_table.js` na accordion: single-open model, collapse/expand max-height transition, header z arrow, scrollIntoView po ekspansji | PENDING |
| `menu_css_cleanup` | Zaktualizuj `style.css`: usuń hero-welcome styles, dodaj table-section accordion CSS, dodaj atmos-strip pod topbar | PENDING |

---

## 2. KRYTYCZNE UWAGI — BŁĘDY KTÓRYCH NOWE OKNO NIE MOŻE POPEŁNIĆ

### ❌ UWAGA 1 — AssetResolver::resolveHero NIE zadziała dla tenant logo

Plan w pliku `.plan.md` mówi: *"dodaj logoUrl przez `AssetResolver::resolveHero` dla `entity_type='tenant'`"*.  
**To jest BŁĘDNE.** `AssetResolver::batchHeroUrls()` ma hardcoded:
```php
AND al.entity_type = 'menu_item'   // linia 106 core/AssetResolver.php
```
Nie obsługuje `entity_type='tenant'`.

**Rozwiązanie dla `doorway_logo_backend`:**  
Użyj klucza KV w `sh_tenant_settings` — najprostsze, działające MVP:
```php
$logoUrl = $getTenantSetting('storefront_logo_url', null);
// + dodaj do response onlineResponse(true, [..., 'tenant' => [..., 'logoUrl' => $logoUrl]])
```
Manager wkleja URL loga ręcznie w Panelu Ustawień. Nie trzeba dotykać `AssetResolver`. Nie dodawaj żadnej nowej logiki do `AssetResolver.php`.

### ❌ UWAGA 2 — `surfaceBg` ≠ tło drzwi

`get_doorway` już zwraca `surfaceBg` z ustawienia `storefront_surface_bg` — to jest tło **stołu/menu**, nie elewacji restauracji.  
Dla tła sceny drzwi (zdjęcie z Gemini) potrzebny jest **oddzielny klucz**: `storefront_doorway_bg`.  
Dodaj go obok `logoUrl` w tym samym `get_doorway`.

### ❌ UWAGA 3 — Nie mylić publicznego checkoutu z api/orders/checkout.php

Publiczny checkout klienta online idzie przez:
- `api/online/engine.php` → `init_checkout` + `guest_checkout`

`api/orders/checkout.php` jest za `auth_guard.php` — nie jest publiczny, nie ruszaj go.

### ❌ UWAGA 4 — is-door-entered klasa MUSI zostać zachowana

W nowym `online_doorway.js` po animacji otwarcia MUSISZ dodać:
```javascript
document.body.classList.add('is-door-entered');
```
Ten mechanizm jest w `online_app.js` i w `doorway.css`. Brak tej klasy = menu nigdy się nie pokaże.

### ❌ UWAGA 5 — eksport mountDoorway MUSI być named export

`online_app.js` importuje: `import { mountDoorway } from './online_doorway.js';`  
i wywołuje: `mountDoorway({ api: OnlineAPI, initialChannel, onEnter: (payload) => enterAfterDoorway(payload) })`  
Sygnatura nie może się zmienić.

**Krytyczne:** nowy plik MUSI zaczynać funkcję od:
```javascript
export async function mountDoorway({ api, onEnter, initialChannel = 'Delivery' } = {}) {
```
Sama linia `export default { mountDoorway };` na końcu pliku NIE wystarczy — `online_app.js` używa named import `{ mountDoorway }`, nie default.

### ❌ UWAGA 6 — finishEnter: wzorzec animacji wyjścia (450ms timing)

Obecny `online_doorway.js` (linia 402–408) ma ten wzorzec — nowy kod MUSI go replikować dokładnie:
```javascript
const finishEnter = ({ preOrder }) => {
    doorway.classList.add('is-leaving');   // CSS fade-out (doorway.css transition)
    setTimeout(() => {
        body.classList.add('is-door-entered');
        const channel = getActiveChannel(doorway);  // lub odpowiednik
        onEnter?.({ channel, preOrder: !!preOrder });
    }, 450);  // 450ms — zgodne z CSS transition w doorway.css
};
```
Czas 450ms jest zsynchronizowany z CSS. Nie zmieniaj go bez zmiany CSS.

### ❌ UWAGA 7 — channels_json: format w bazie ≠ format w PHP/JS

W `sh_tenant_settings` wartość `storefront_channels_json` to JSON **array**: `'["delivery","takeaway","dine_in"]'`  
PHP w `get_doorway` konwertuje to na keyed **object**: `{ delivery: true, takeaway: true, dine_in: true }`  
JS w `online_doorway.js` dostaje obiekt i obsługuje go jako `data.channels.delivery`, `data.channels.takeaway` itd.  
Nie zmieniaj tej konwersji w PHP ani w JS.

---

## 3. Architektura DOM nowej sceny drzwi

```
#doorway (full-screen, position: fixed)
  .dw-bg          ← CSS background-image: url(storefront_doorway_bg) + ciemny gradient fallback
  .dw-ambient     ← particle efekty: para nocą, refleksy słońca dniem (animacje CSS)
  .dw-stage       ← perspective: 1400px; transform-style: preserve-3d
    .dw-frame     ← ościeżnica statyczna, shadow wewnętrzny
      .dw-glass-zone  ← zona logo (mix-blend-mode: screen)
    .dw-leaf      ← LIŚĆ DRZWI (transform-style: preserve-3d)
      .dw-leaf__front
        .dw-handle     ← główny CTA (klamka)
        .dw-glass      ← szyba (blur + refleks)
        .dw-note       ← karteczka "Zaraz wróce" (ukryta gdy open)
      .dw-leaf__back   ← tył drzwi (warm glow)
  .dw-interior-glow  ← radial gradient rosnący zza drzwi podczas obrotu
  .dw-hotspots
    .dw-hs[data-hs="hours"]
    .dw-hs[data-hs="address"]
    .dw-hs[data-hs="phone"]
  .dw-popup          ← jeden floating popup (teleportuje się do hotspota)
  .dw-channel-hint   ← Dowóz/Odbiór picker (bottom center)
  .dw-status-dot     ← status dot (top-right, 14px, zawsze widoczny)
```

---

## 4. Animacja — sekwencja CSS + JS

```
idle → hover klamki → klik klamki
  → handle rotate -25deg (0.15s ease-in-out)
  → leaf: rotateY(0) → rotateY(-110deg) z cubic-bezier(0.25, 0, 0.1, 1) 0.85s
  → interior-glow: scale(0) opacity(0) → scale(1) opacity(1) [równolegle z leaf swing]
  → white/warm flash 0.1s przy ~-90deg (setTimeout ~580ms po starcie)
  → #doorway: opacity(0) scale(1.04) 0.4s
  → body.classList.add('is-door-entered')
  → onEnter({ channel, preOrder: false })
```

Stan zamknięty (`.dw-leaf--closed`):
```css
@keyframes noteSwing { 0%,100% { rotate: -4deg } 50% { rotate: 4deg } }
```
Handle jest klikalny → otwiera preorder bottom-sheet zamiast menu (dispatch `slicehub:preorder-requested`).

---

## 5. Accordion kategorii — nowy DOM

```html
<section class="table-section is-collapsed" data-cat-id="42">
  <header class="table-section__head">  ← klikalny, role="button"
    <div class="table-section__titles">
      <h2>Pizze</h2>
      <span class="table-section__count">8 dań</span>
    </div>
    <i class="fa-solid fa-chevron-down table-section__arrow"></i>
  </header>
  <div class="table-section__body">    ← max-height transition (collapse wrapper)
    <div class="table-section__strip" role="list">...kafelki...</div>
    <div class="table-section__hint">...</div>
  </div>
</section>
```

Logika: single-open (klik na otwartą = zamknij, klik na inną = zamknij poprzednią + otwórz nową). Po animacji (220ms) `scrollIntoView({ behavior: 'smooth', block: 'start' })`.

**WAŻNE:** istniejące klasy `.table-section__strip`, `.table-section__head`, `.table-section__count` itp. w `style.css` pozostają. Tylko dodajemy `.table-section__body` jako nowy wrapper i obsługę `.is-collapsed` / `.is-open`.

---

## 6. Backend — `get_doorway` — dokładny kod zmiany

### Gdzie edytować
Plik: `api/online/engine.php`, akcja `get_doorway`, linie **366–507**.  
`onlineResponse(true, [...], 'OK')` zamyka się na linii **507**.

### Jak działa `getTenantSetting`
Jest to closure zdefiniowana w linii 174 pliku `engine.php`:
```php
$getTenantSetting = function (string $key, $default = null) use ($pdo, $tenantId, $hasTenantSettings) {
    // SELECT setting_value WHERE setting_key = :k
    // Jeśli wiersz nie istnieje → zwraca $default (null)
};
```
**Nie ma potrzeby migracji DB ani INSERT nowych wierszy.** Jeśli klucz nie istnieje w tabeli → gracefully zwraca `null`. Wartości pojawią się w bazie dopiero gdy manager wpisze je przez Panel Ustawień.

### Dokładne 4 linie do dodania w `get_doorway`

Dodaj **przed** wywołaniem `onlineResponse` (przed linią 477):
```php
$logoUrl   = $getTenantSetting('storefront_logo_url', null);
$doorwayBg = $getTenantSetting('storefront_doorway_bg', null);
```

W bloku `'tenant'` (linie 478–483) dodaj dwa pola:
```php
'tenant' => [
    'id'         => (int)$tenantRow['id'],
    'name'       => $brandName,
    'tagline'    => $brandTagline,
    'brandColor' => $brandColor,
    'logoUrl'    => $logoUrl,    // ← DODAJ
    'doorwayBg'  => $doorwayBg, // ← DODAJ
],
```

### Aktualne dane testowe w bazie (tenant_id = 1)

| setting_key | wartość w DB |
|---|---|
| `storefront_tagline` | `PIZZA FORNO` |
| `storefront_surface_bg` | `board___0010_13_wynik_be6f85.webp` |
| `storefront_address` | `ul. Dąbrowskiego 58` |
| `storefront_city` | `Trzcianka` |
| `storefront_phone` | `519405251` |
| `storefront_lat` | `53.039682` |
| `storefront_lng` | `16.460392` |
| `storefront_channels_json` | `["delivery","takeaway","dine_in"]` |
| `storefront_brand_color` | *brak wiersza* → default `#E8B04B` |
| `storefront_logo_url` | *brak wiersza* → `null` |
| `storefront_doorway_bg` | *brak wiersza* → `null` |

Tenant name w tabeli `sh_tenant`: `SliceHub Pizzeria Poznań` (id=1).

---

## 7. Pliki które zostaną zmienione

| Plik | Zmiana |
|------|--------|
| `modules/online/index.html` | Rebuild sekcji `#doorway`, usuń `#hero-welcome`, dodaj `.dw-popup`, `.dw-channel-hint`, `.dw-status-dot` |
| `modules/online/css/doorway.css` | Pełen rewrite — 3D leaf, hotspot, popup, noteSwing, logo zone, ambient, mobile breakpoints |
| `modules/online/js/online_doorway.js` | Rewrite — usuń `renderDoorSvg()`, nowy mechanizm 3D, hotspot manager, logo inject |
| `modules/online/js/online_table.js` | Dodanie accordion (single-open model) |
| `modules/online/css/style.css` | Usuń `.hero-welcome` bloki, dodaj `.table-section__body` + `.is-collapsed` + `atmos-strip` |
| `api/online/engine.php` | Dodaj `logoUrl` + `doorwayBg` do responsa `get_doorway` przez `getTenantSetting()` |

---

## 8. Pliki których NIE ruszamy w tej iteracji

- `core/AssetResolver.php` — nie modyfikować
- `api/orders/checkout.php` — nie modyfikować
- `core/js/surface/SharedSceneRenderer.js` — nie modyfikować
- `modules/online_studio/` — nie modyfikować (Studio to oddzielna sesja)
- **Żadne migracje DB** — tabela `sh_tenant_settings` już istnieje z kolumnami `(tenant_id, setting_key, setting_value)`. `getTenantSetting()` zwraca `null` gdy wiersz nie istnieje — to jest poprawne zachowanie, nie błąd. Nowe klucze (`storefront_logo_url`, `storefront_doorway_bg`) pojawią się w bazie automatycznie gdy manager je wpisze przez UI.

---

## 8a. Baza danych — jak korzystać podczas pracy

W korzeniu projektu jest live dump bazy: `slicehub_pro_v2 BAZA.sql` (uwaga na spację w nazwie).  
Zamiast zgadywać schemat — użyj Grep na tym pliku:
```
Grep: CREATE TABLE `sh_nazwa_tabeli` → slicehub_pro_v2 BAZA.sql
```
Przykład: żeby sprawdzić kolumny `sh_tenant_settings` → szukaj `CREATE TABLE \`sh_tenant_settings\`` w tym pliku.

---

## 9. Kontekst systemowy — co jest gotowe (nie rób ponownie)

- `mountDoorway` eksportuje się z `online_doorway.js` i jest już wpięte w `online_app.js`
- `api.getDoorway(channel)` istnieje w `online_api.js` (linia 56)
- `enterAfterDoorway({ channel, preOrder })` w `online_app.js` — nie ruszaj
- `?skip=doors` — deep-link bypass dla SMS/email/tracking (MUSI zostać w nowym JS)
- `body.classList.add('is-door-entered')` — zarówno w skip-doors jak i po animacji
- Istniejący CSS `.hero-welcome.is-collapsed` i `.hero-welcome` w `style.css` (linie 161–218) — do usunięcia w `menu_css_cleanup`
- `table-surface--hybrid` klasa na wrapperze z `buildTableSections()` — zostaje, bo accordion jest rozszerzeniem, nie zastąpieniem

---

## 10. Kolejność wykonania (optymalna)

```
1. doorway_logo_backend  ← najpierw backend, testujemy odpowiedź API
2. door_rebuild_html     ← dopiero potem HTML który korzysta z tych danych
3. door_css              ← CSS bez JS, możemy testować wygląd statycznie
4. door_js               ← JS animacje, gdy CSS gotowy
5. menu_accordion        ← JS dla accordion
6. menu_css_cleanup      ← sprzątanie CSS, na końcu, nie blokuje nic
```

---

## 11. Skrócona mapa projektu (tylko co potrzebne teraz)

```
modules/online/
  index.html            ← główny HTML storefrontu
  js/
    online_app.js       ← bootstrap SPA, montuje doorway, wywołuje enterAfterDoorway
    online_api.js       ← wrapper fetch (getDoorway, getMenu, initCheckout...)
    online_doorway.js   ← GŁÓWNY CEL REWRITEU
    online_table.js     ← GŁÓWNY CEL ACCORDION
    online_ui.js        ← renderowanie dań, dish sheet, koszyk
    online_renderer.js  ← mountPizzaScene (SharedSceneRenderer)
    online_checkout.js  ← flow checkout (nie ruszamy)
    online_track.js     ← tracking zamówienia (nie ruszamy)
    surface/
      ModifierOrchestrator.js  ← kliknięcia modyfikatorów (nie ruszamy)
  css/
    style.css           ← globalny CSS storefrontu (MODYFIKUJEMY: usuń hero-welcome, dodaj accordion)
    doorway.css         ← PEŁEN REWRITE
    track.css           ← tracker (nie ruszamy)
    living-scene.css    ← efekty żywej sceny (nie ruszamy)

api/online/
  engine.php            ← publiczne API storefrontu (MODYFIKUJEMY: get_doorway + logoUrl/doorwayBg)

core/
  AssetResolver.php     ← NIE RUSZAMY (entity_type='menu_item' hardcoded w batchHeroUrls)
  js/
    scene_renderer.js           ← SSOT matematyki warstwy (nie ruszamy)
    surface/SharedSceneRenderer.js  ← wspólny renderer (nie ruszamy)
```

---

## 12. Dane których użytkownik jeszcze nie dostarczył

Użytkownik zaoferował wygenerowanie w Gemini Ultra:
1. **Tło sceny drzwi** — zewnętrze restauracji / klimatyczna uliczka (dzień + wieczór wariant)
2. **Tekstura liścia drzwi** — opcjonalna (PNG z transparentem), drewno lub lakier

Dopóki ich nie dostarczy → fallback = ciemny CSS gradient + CSS drewniana faktura.  
Nie czekaj na te obrazy — kod musi działać z fallbackiem.

---

## 13. Zasady architektoniczne (ŻELAZNE — nie dyskutujemy)

1. **Zero frameworków** — Vanilla JS, żaden import z npm
2. **SceneResolver + AssetResolver = serwer** — frontend nie wymyśla URL-i do obrazków
3. **CartEngine = serwer** — frontend nie liczy totala
4. **Nie twórz nowego renderera** — SharedSceneRenderer już istnieje
5. **Nie rób nowego endpointu jeśli akcja w istniejącym wystarczy** — get_doorway rozszerzamy, nie tworzymy nowego pliku
6. **`api/orders/checkout.php` to NIE jest publiczny checkout** — publiczny to `api/online/engine.php` → `guest_checkout`

---

---

## 14. PRZED KAŻDĄ NAPRAWĄ / IMPLEMENTACJĄ — lista kontrolna zgodności

> **Obowiązuje przed napisaniem pierwszej linii kodu w każdym zadaniu.**

Zanim zaczniesz implementować cokolwiek z listy TODO, wykonaj ten checklist:

### 14.1 Sprawdź interfejsy JS → PHP
- Jakie pole wysyła frontend (`online_api.js`) do backendu?
- Jakie pole zwraca backend (`api/online/engine.php`) do frontendu?
- Czy nazwy kluczy JSON są identyczne po obu stronach?

### 14.2 Sprawdź eksporty/importy JS
- Czy funkcja jest importowana jako named (`{ mountDoorway }`) czy default (`import X from`)?
- Czy plik eksportuje ją w odpowiedni sposób (named export vs default)?
- Czy zmiana sygnatury funkcji nie złamie innych plików które ją importują?

### 14.3 Sprawdź CSS klasy i ich użycie
- Czy klasa CSS która zostanie usunięta / zmieniona nie jest używana w JS (`classList.add/remove/contains`)?
- Czy klasa CSS która zostanie dodana nie koliduje z istniejącymi stylami w `style.css` lub `doorway.css`?

### 14.4 Sprawdź schemat DB przed pisaniem SQL
- Czy tabela którą czytasz / piszesz istnieje? (Grep w `slicehub_pro_v2 BAZA.sql`)
- Czy kolumna której używasz faktycznie istnieje w tej tabeli?
- Czy zapytanie zawiera `tenant_id = :tid`? (brak = błąd krytyczny wg Prawa VI)

### 14.5 Sprawdź plan vs rzeczywistość
- Czy plik planu (`immersive_doorway_rebuild_bb65a0c7.plan.md`) opisuje coś co koliduje z aktualnym kodem?
- Konkretnie: plan mówi `AssetResolver::resolveHero` dla logo → **to nie zadziała** (patrz UWAGA 1). Użyj `getTenantSetting`.
- Jeśli widzisz inną niespójność między planem a kodem — zatrzymaj się, opisz ją użytkownikowi, nie implementuj na ślepo.

### 14.6 Szybka weryfikacja przed startem (5 minut)
```
1. git diff / przeczytaj aktualny stan pliku który masz zmienić
2. Grep: czy zmieniana funkcja/klasa jest używana gdzie indziej?
3. Sprawdź czy zmiana nie psuje mechanizmu skip=doors / is-door-entered / onEnter
4. Dopiero potem pisz kod
```

> **Jeśli cokolwiek w tym briefie jest niejasne lub widzisz sprzeczność z kodem — najpierw sprawdź kod, potem zapytaj użytkownika. Nie wymyślaj.**
