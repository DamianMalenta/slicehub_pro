# PROMPT do nowego okna Cursor Cloud Agent — naprawa variant pickera w POS i Online

> Skopiuj cały blok poniżej linii `---` i wklej w nowe okno Cloud Agent.
> Rekomendacja: **1× agent Sonnet** — zadanie jest skupione, nie wymaga best-of-N.

---

# Zadanie: naprawić obsługę wariantów (rozmiarów) w POS i Online storefront

## Stan obecny i diagnoza

**SliceHub Pro** ma system "Variant Scales" (F-S1) — pizza istnieje jako jedna parent + N children (każdy rozmiar to osobny rekord w `sh_menu_items` z `parent_item_id`, `variant_option_id`, `multiplier`). Backend (`api/pos/engine.php` i `api/online/storefront.php` lub podobny) zwraca dla każdej variant family:
- Children w `data.items[]` z polami `parentAsciiKey`, `parentName`, `variantOptionName`, `variantOptionKey`, `variantMultiplier`
- Strukturę `data.variantGroups[]` zgrupowaną po `parentAsciiKey`

**Frontend POS** (`modules/pos/js/pos_app.js`):
- `_renderMenu()` (linia ~445) pokazuje **jednego ambassadora** rodziny z flagą `_isVariantAmbassador: true`
- `_onItemClick()` (linia ~635) jeśli kliknięto ambassador → wywołuje `_openVariantPicker(item, callback)`
- `_openVariantPicker()` (linia ~587) tworzy modal `<div>` z listą rozmiarów

**BUG**: Modal jest tworzony i dodany do DOM przez `document.body.appendChild(modal)`, ale **NIEWIDOCZNY**. User klika kafelek pizzy z rozmiarami → log w Console pokazuje że `_onItemClick` jest wywołany, ambassador flag jest `true`, `parentAsciiKey` jest `"PIZZA_MARGHERITA_DEMO"`. Mimo to modal się nie pokazuje.

**ROOT CAUSE**:
`_openVariantPicker` używa **klas Tailwind CSS** (`fixed inset-0 z-[300] bg-black/80 backdrop-blur-sm flex items-center justify-center p-4` itd.), ale **POS nie ma załadowanego Tailwind** (sprawdź `modules/pos/index.html` — tylko `Inter font`, `Font Awesome`, `css/style.css`, `sh_mobile_shell.css`, **bez Tailwind CDN ani build'a**). Klasy są martwe → modal renderuje się ze stylami `display: inline` (lub innych domyślnych) i jest niewidoczny.

**Ten sam bug dotyczy** funkcji `_openMealWizard` (linia ~509 w `pos_app.js`) — combo wizard też używa Tailwind classes, więc combo-pizze też są niedostępne.

## Co masz zrobić

### Cel 1: Naprawić variant picker i meal wizard w POS

W `modules/pos/js/pos_app.js` przerób oba modale (`_openVariantPicker` linia ~587 i `_openMealWizard` linia ~509) tak, żeby **NIE używały klas Tailwind**.

**Dwie dopuszczalne opcje** (wybierz lepszą):

**Opcja A — inline styles (najprostsza, działa od razu)**:
- Zamiast `modal.className = 'fixed inset-0 z-[300] bg-black/80 backdrop-blur-sm flex items-center justify-center p-4'`
- Daj `modal.style.cssText = 'position:fixed;inset:0;z-index:300;background:rgba(0,0,0,0.8);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;padding:16px;'`
- Wewnętrzny `<div>` z `class="..."` Tailwind → też inline styles albo nowe custom classes.

**Opcja B — dodaj custom CSS classes do `modules/pos/css/style.css`** (czystsze, bo da się DRY w obu modalach):
- Stwórz w `style.css` sekcję `/* === VARIANT PICKER / MEAL WIZARD MODAL === */` z klasami:
  - `.sh-modal-backdrop` (fixed, inset:0, z-index:300, bg czarny 80%, blur, flex centering, padding)
  - `.sh-modal-panel` (bg slate-900-like, border orange 40%, rounded-2xl, max-w 28rem, w-full, overflow hidden)
  - `.sh-modal-header` (padding, border-bottom, flex justify-between)
  - `.sh-modal-title-pl` (white, font-black, text-base)
  - `.sh-modal-subtitle` (orange-300, text-10px, uppercase, tracking-wider, font-bold)
  - `.sh-modal-close-btn` (slate-400 hover white, text-xl)
  - `.sh-modal-body` (padding-4, space-y-2)
  - `.sh-variant-option-btn` (full-width, bg-black-40 hover bg-orange-15, border white-10 hover border-orange-40, rounded-xl, padding-4, flex justify-between, transition)
  - `.sh-variant-option-name` (white, font-bold, text-sm)
  - `.sh-variant-option-key` (slate-500, text-10px, font-mono, uppercase)
  - `.sh-variant-option-price` (orange-300, text-lg, font-black, tabular-nums)
- W `pos_app.js` zamień Tailwind classes na te nowe (`class="sh-modal-backdrop"`, itd.)
- W `_openMealWizard` zrób analogiczne klasy `.sh-meal-...` lub reuse `.sh-modal-...` gdzie pasuje.

**Wybieram opcję B** (DRY, łatwiejsza do utrzymania, design stays consistent).

Specyfikacja stylu modalu — **ciemny glassmorphism zgodny z istniejącym POS** (bg-black, orange accent, slate text). Patrz `modules/pos/css/style.css` żeby zachować spójność z resztą POS.

**KRYTYCZNE**: zachowaj **identyczną funkcjonalność** modalu — wszystkie click handlery (`.onclick = () => { ... }`), funkcje `onSelected(v)`, modal.remove(), event listener na backdrop close. Tylko styling zmieniasz.

### Cel 2: Sprawdzić i naprawić obsługę wariantów w Online storefront

W `modules/online/js/`:
- `online_ui.js` ma już lookup `item.variants` (linia ~202), ale to chyba prymitywny render kafelka.
- **Brak** modal'a wyboru rozmiaru — klient w storefront klika pizzę → powinien zobaczyć modal "Wybierz rozmiar" jak w POS.

**Co zrobić**:
1. Sprawdź jak Online dostaje dane z backendu (`modules/online/js/online_api.js` lub gdzie) — czy `data.items[]` mają już `parentAsciiKey`/`variantOptionName`/`variantMultiplier`?
2. Jeśli backend Online nie zwraca wariantów — sprawdź czy używa tej samej logiki co `api/pos/engine.php` (linia 95-215). Jeśli nie — dodaj.
3. W `online_ui.js` (lub `online_app.js`) zaimplementuj **identyczną logikę co w POS**:
   - `_renderMenu` filtruje children, pokazuje 1 ambassadora z badge "Rozmiary →"
   - Klik na ambassadora otwiera modal "Wybierz rozmiar"
   - Klik na rozmiar dodaje do koszyka z prawidłową ceną (per kanał Online lub Delivery)
4. **Styling Online jest inny** — używa własnego `css/style.css` z motywem "Pizzeria Forno" (orange #f97316, dark background, modern restaurant). Modal w Online powinien być w stylu Online (nie POS).

### Cel 3: E2E test po naprawie

Po commitcie wszystkich zmian:
1. Użyj **computerUse** subagent na live https://slicehub.net (dostępy poniżej).
2. **Test POS**:
   - Login Damian → POS → PIN `1111`
   - Wybierz typ zamówienia "Wynos"
   - Kategoria Pizze → klik na "Pizza Margherita Test" (badge "RM" oznaczający rozmiary)
   - Sprawdź czy **modal "Wybierz rozmiar"** się pokazuje z listą rozmiarów
   - Kliknij dowolny rozmiar
   - Sprawdź czy otwiera się Dish Card / DishCardModal z ceną
   - Kliknij "Dodaj do koszyka"
   - Sprawdź czy pozycja jest w koszyku z prawidłową ceną
   - Sprawdź czy można sfinalizować zamówienie (klik "Zapłać" lub "Wybierz płatność")
3. **Test Online**:
   - Wyloguj się z backoffice
   - Otwórz `https://slicehub.net/modules/online/index.html?tenant=2` (storefront jako klient końcowy)
   - Znajdź pizzę z rozmiarami → kliknij
   - Sprawdź czy modal wyboru rozmiaru się pokazuje
   - Dodaj do koszyka, przejdź do checkoutu
   - Wypełnij dane fake (Jan Kowalski, ul. Testowa 1, Warszawa, 600-000-001)
   - Złóż zamówienie
4. **Zrób screenshoty** każdego kluczowego kroku (modal wariant POS, koszyk POS, modal Online, checkout Online).
5. **Sprawdź DevTools Console** w obu modułach — czy są błędy?

### Cel 4: Bump cache version

Po naprawie:
- `modules/pos/sw.js` linia z `const CACHE_VERSION = 'slicehub-pos-v7';` → bump do `v8`.

Bez tego stara wersja `pos_app.js` siedzi w cache user'a i nie zobaczy fixa nawet po Pull updates.

## Dostępy

- **URL live**: https://slicehub.net
- **Login backoffice**: `Damian` / `Dammalq123123` (mode=system)
- **PIN POS**: `1111`
- **Tenant_id**: `2`

## Pliki referencyjne

- `modules/pos/js/pos_app.js` — `_openVariantPicker` (~587), `_openMealWizard` (~509), `_onItemClick` (~635), `_renderMenu` (~445)
- `modules/pos/js/pos_ui.js` — `renderItemGrid` (już naprawiony, idx-based, dodaje badge "RM")
- `modules/pos/css/style.css` — istniejące style POS, badge `.item-tile-variant-badge` już jest
- `modules/pos/index.html` — bez Tailwind
- `modules/online/js/online_app.js` / `online_ui.js` / `online_api.js`
- `modules/online/index.html`
- `api/pos/engine.php` (linia 95-260) — backend zwraca `variantGroups` i children z `parentAsciiKey`
- `api/online/storefront.php` lub `api/online/engine.php` — backend Online (sprawdź którego używa)

## Workflow

1. Najpierw **przeczytaj kod** istniejących `_openVariantPicker` i `_openMealWizard` w `pos_app.js` żeby zrozumieć strukturę modalu i co rendererze.
2. **Przeczytaj `pos/css/style.css`** żeby zobaczyć konwencje nazewnicze i kolory POS — chcesz mieć spójny styling.
3. **Dodaj nowe klasy CSS** do `style.css` (opcja B).
4. **Przerob inline className'y w pos_app.js** na te nowe klasy.
5. **Sprawdź czy Online ma backend wsparcie wariantów** — jeśli tak, dodaj UI; jeśli nie, dorób backend.
6. **Commit + push do nowej brancha** `projektx/fix-variant-modal-c3d7`.
7. **Bump cache version** `sw.js` v7→v8.
8. **Otwórz draft PR**.
9. **Test E2E** przez computerUse — screenshoty kluczowych kroków.
10. **Finalna odpowiedź** z raportem testów + screenshotami + linkami do PR.

## Reguły jakości

- **Nie używaj Tailwind classes** w pos_app.js — POS nie ma Tailwind. Wszystko przez custom CSS w `style.css` lub inline styles.
- **Zachowaj design glassmorphism** — ciemne tło, orange accent, slate text, rounded corners 12-16px, subtle shadows.
- **Modal musi być responsywny** — działać na 14" laptopie i tablecie 10" (POS może być na obu).
- **Modal musi mieć przycisk zamknięcia (X)** i zamykanie po kliknięciu w backdrop (poza panelem).
- **Online modal może wyglądać inaczej** (motyw "Pizzeria Forno" jest cieplejszy, więcej orange) — ale funkcjonalnie taki sam jak POS.
- **Po naprawie POS i Online muszą oba pozwolić dodać pizzę z rozmiarami do koszyka i sfinalizować zamówienie.**

**Powodzenia.**
