# USTALENIA: MODUŁ ZAMÓWIEŃ ONLINE — SLICEHUB ENTERPRISE

**Data:** 2026-04-16
**Status:** Zatwierdzone przez Product Ownera, oczekuje na start implementacji

---

## 1. ARCHITEKTURA DWUPOZIOMOWA

### Poziom 1: Strona Główna Menu (Przeglądanie)

- Tło: ciemna fotograficzna powierzchnia (uploadowana przez managera w Studio)
- Fallback: gradient ciemny (#1a1a1a) gdy brak zdjęcia
- Akordeon kategorii:
  - Wąskie paski (~48px) z nazwą kategorii, strzałką rozwinięcia
  - Elementy tekstowe na półprzezroczystym tle (glass-morphism) dla czytelności
  - Tylko jedna kategoria otwarta naraz
  - Lazy loading miniaturek — ładowane dopiero przy rozwinięciu kategorii
- Każde danie w rozwiniętej liście:
  - Miniaturka (~56px kwadrat) po lewej
  - Nazwa + krótki opis (1 linia, obcięty) w środku
  - Cena po prawej — jeśli warianty rozmiaru: "30cm 29,99 / 37cm 39,99" widoczne od razu inline
- Klik na danie = otwiera Poziom 2
- Opcjonalnie: sekcja "Popularne" nad akordeonem (3-4 wyróżnione dania)
- Opcjonalnie: pasek wyszukiwania na górze

### Poziom 2: Karta Dania ("Surface Card")

- **Mobile**: bottom sheet (wysuwa się od dołu, snap: pół ekranu → pełny ekran)
- **Desktop**: modal lub side panel z backdrop blur
- Tło karty: ta sama ciemna powierzchnia co strona główna (spójność)
- Układ wewnątrz:
  - Połowa pizzy po lewej (prawy półkrąg, krawędź cięcia przy lewej ścianie)
  - Prawa strona: companions (sos, napój) jako zdjęcia produktów + "DODAJ"
  - Pod tym: modyfikatory w zwiniętych kategoriach (np. WARZYWA — 3 widoczne, reszta po "Pokaż więcej")
- Przełącznik **Podgląd / Edycja** u góry nad pizzą
- Tryb Podgląd: pizza statyczna, dodatki quick-add (badge "x1", "x2"), bez zmian warstw
- Tryb Edycja: warstwy ożywają, klik na składnik:
  - x1: warstwa (scatter) nakłada się na pizzę + zdjęcie hero produktu pojawia się na powierzchni obok
  - x2: brak nowej warstwy, drugie zdjęcie hero materiałuje się obok pierwszego
  - x0 (usunięcie): warstwa i zdjęcia hero znikają
- Pizza może opcjonalnie rozszerzyć się na pełny ekran w trybie Edycja
- Pół na pół: widoczna połowa dzieli się horyzontalnie (góra = Pizza A, dół = Pizza B)

---

## 2. KOSZYK — CAŁKOWICIE ODDZIELONY

- Koszyk NIE jest częścią karty dania ani strony menu
- **Mobile**: floating button (prawy dolny róg) z badge'm ilości → klik otwiera drawer od prawej
- **Desktop**: ikona koszyka w nagłówku → dropdown/panel
- Komunikacja: `CustomEvent('pizza-add-to-cart')` — karta dania dispatchuje event, koszyk nasłuchuje
- Walidacja server-side: przed dodaniem → `CartEngine.php` potwierdza cenę (Prawo Zera Zaufania)

---

## 3. SYSTEM DWÓCH ZDJĘĆ NA SKŁADNIK

Każdy modyfikator/składnik wymaga DWÓCH zdjęć:

| Rola | Nazwa kolumny | Opis |
|------|---------------|------|
| **Warstwa (layer/scatter)** | `asset_filename` | Składnik rozsypany jak na pizzy, widok z góry, przezroczyste tło, ~1500x1500px |
| **Produkt (hero)** | `product_filename` (NOWA) | Pojedynczy piękny produkt (pomidorek, listki bazylii), przezroczyste tło, ~600x600px |

Migracja DB: dodanie `product_filename` do `sh_visual_layers` lub `sh_ingredient_assets`.

---

## 4. ZDJĘCIE TŁA POWIERZCHNI (SURFACE)

- Uploadowane przez managera w Studio (`/modules/studio/`)
- Zapisywane w `sh_tenant_settings` pod kluczem `storefront_surface_bg`
- CSS: `--surface-bg: url(...)` na głównym kontenerze
- Wymagania: ciemna powierzchnia (kamień, marmur, drewno), top-down, min. 2560x1600px
- Serwowane jako WebP z srcset dla różnych rozdzielczości

---

## 5. WZORCE Z POS-a (INSPIRACJA, NIE KOPIA)

Bierzemy z POS-a:
- Zwartość elementów (małe paddingi, czytelna hierarchia fontów)
- Akordeonowy układ (kanban click-to-expand → nasze kategorie click-to-expand)
- Modal konfiguracji dania (`dc-card` → nasz bottom sheet)
- Koszyk jako osobny panel (`pos-cart` → nasz floating drawer)
- Color-coded feedback (badge'e, stany aktywne)

NIE bierzemy z POS-a:
- Ciemny motyw systemowy (nasza ciemność pochodzi ze zdjęcia powierzchni, nie z UI)
- Rozmiary fontów POS-owe (7-12px) — online musi być większy dla klientów końcowych
- Tryb operatora (POS to narzędzie pracy, online to doświadczenie klienta)

---

## 6. CO ZACHOWUJEMY Z OBECNEGO KODU

### Backend (bez zmian / minimalne rozszerzenia):
- `api/online/engine.php` — unified storefront engine (action-based) ✅ (zastąpił `product.php` 2026-04-16)
- `api/cart/CartEngine.php` + `calculate.php` — walidacja server-side ✅
- `core/WzEngine.php` — silnik magazynowy, half&half 0.5 multiplier ✅

### Logika biznesowa do odtworzenia w nowym frontend (specyfikacja, nie kod):
- Generowanie payload CartEngine-compatible (`channel`, `order_type`, `lines[]`, `promo_code`)
- Optymistyczny podgląd ceny (half&half: max(A,B) + surcharge z tenant_settings)
- Flatowanie modyfikatorów (group → options → SKU)
- Domyślna selekcja modyfikatorów + rozmiaru
- Half & Half: state machine A/B + ładowanie danych pizzy B
- Komunikacja z backendem przez `online_api.js` (wrapper na `engine.php`)

### Baza danych (zachowane tabele):
- `sh_price_tiers` — macierz cenowa
- `sh_global_assets` — warstwy bazowe (deska, ciasto, sos)
- `sh_visual_layers` — mapowanie modifier → warstwa wizualna
- `sh_board_companions` — cross-sell companions
- `sh_ingredient_assets` — biblioteka składników
- `sh_tenant_settings` — ustawienia tenanta (surcharge, surface_bg)

---

## 7. CO WYMIENIAMY BRUTALNIE

| Plik | Akcja | Powód |
|------|-------|-------|
| `modules/online/index.html` | **TWORZYMY OD ZERA** (stary v4 usunięty 2026-04-16) | Czysty shell zgodny z konwencją POS/Tables |
| `modules/online/css/style.css` | **TWORZYMY OD ZERA** | Surface theme — dark, glass-morphism, mobile-first |
| `modules/online/js/online_api.js` | **TWORZYMY OD ZERA** | Wrapper na `api/online/engine.php` (wzorzec `pos_api.js`) |
| `modules/online/js/online_app.js` | **TWORZYMY OD ZERA** | State machine + glue (init, lifecycle) |
| `modules/online/js/online_ui.js` | **TWORZYMY OD ZERA** | Akordeon kategorii + Surface Card + cart drawer |

---

## 8. ZIDENTYFIKOWANE ZAGROŻENIA

| # | Zagrożenie | Ryzyko | Mitygacja |
|---|-----------|--------|-----------|
| 1 | Wydajność przy 100+ daniach | Wolne ładowanie, lagujący scroll | Akordeon + lazy loading miniaturek (ładowane przy rozwinięciu kategorii) |
| 2 | Bottom sheet + pizza na małym telefonie (375px) | Brak miejsca na dodatki | Pizza max 40% szerokości; na małych ekranach: pizza u góry, dodatki pod spodem |
| 3 | Opóźnienie otwarcia karty dania (API call) | User czeka 1-2s = drop-off | Smart prefetch: ładujemy dane 3 pierwszych dań w tle przy rozwinięciu kategorii |
| 4 | Niespójne zdjęcia od fotografa | Surface effect się rozsypie | Precyzyjny brief fotograficzny (sekcja 9) z przykładami |
| 5 | Czytelność tekstu na ciemnym tle | Accessibility fail (WCAG) | Glass-morphism: elementy UI na półprzezroczystym tle, kontrast min. 4.5:1 |
| 6 | Brak zdjęć na starcie (nowa restauracja) | Pusta strona, zły UX | Graceful fallback: inicjały zamiast miniaturek, brak pizzy wizualnej, czysta lista |
| 7 | Duże zdjęcie tła = wolny LCP | Słabe Core Web Vitals / SEO | WebP + srcset + progressive loading (blur placeholder → full image) |
| 8 | Animacje na starych telefonach | Lagowanie, jank | `prefers-reduced-motion` media query, fallback do prostych przejść |

---

## 9. BRIEF FOTOGRAFICZNY

### Zasady ogólne:
- Kąt kamery: 100% zdjęć z **bezpośrednio nad** (bird's-eye / flat lay)
- Wszystkie składniki i produkty: **.webp/.png z przezroczystym tłem** (alpha)
- Oświetlenie: miękkie, ciepłe, z jednej strony (konsekwentnie ~godz. 10:00), identyczne we wszystkich sesjach
- Kalibracja kolorów: ciepłe tony, karta referencyjna kolorów w każdej sesji
- Rozdzielczość: dostarczać w 2x (retina)

### Na składnik — DWA zdjęcia:

**Zdjęcie A — "Scatter Layer" (warstwa na pizzę):**
- Składnik rozsypany jak na pizzy, widok z góry
- Wypełnia ~60-70% kwadratowej ramki, margines na brzegach
- Naturalny układ (garść oliwek rzuconych na blat, startki mozzarelli organicznie rozłożone)
- Przezroczyste tło, min. 1500x1500px

**Zdjęcie B — "Hero Product" (produkt na powierzchnię + siatka menu):**
- Piękny, kompaktowy pojedynczy obiekt
- Przykłady: JEDEN pomidorek koktajlowy na łodyżce, 3 listki bazylii, mała kupka plastrów salami
- Przezroczyste tło, min. 600x600px
- Ma wyglądać jak "postawiony na ciemnej powierzchni przez food stylistę"

### Zdjęcie tła (Surface):
- Prawdziwa fizyczna powierzchnia z góry (ciemny kamień, stary marmur, ciemne drewno, łupek)
- Subtelna tekstura, bez silnych powtarzających się wzorów
- Min. 2560x1600px, idealnie 3840x2400px
- Jedno zdjęcie per motyw marki

### Companions (sosy, napoje, dodatki):
- Widok z góry (widzimy czubek butelki, wieczko sosu)
- Naturalny cień pod spodem dozwolony
- Przezroczyste tło, .webp

### Miniaturki dań (dla listy menu):
- Kwadrat, danie z góry, pięknie skomponowane
- Min. 400x400px, przezroczyste tło lub na tej samej powierzchni

---

## 10. STUDIO — ROZBUDOWA KOMPOZYTORA WIZUALNEGO (NIE NOWY MODUŁ)

### Stan obecny (`studio_visual.js` / `VisualLayerEditor`):
- Canvas z renderowaniem warstw `.webp` (z-order stacking) ✅
- 3 zakładki: Warstwy, Biblioteka, Cross-sell ✅
- Auto-mapping składników do assetów (`_SUB_TYPE_KEYWORDS`) ✅
- Dodawanie/usuwanie warstw, zmiana ilości (x1/x2/x3) ✅
- Animacje pop-in/pop-out ✅
- Companion CRUD (dodaj/usuń cross-sell) ✅

### Braki do uzupełnienia:
- ❌ Nie zapisuje warstw do bazy (`sh_visual_layers`) — tylko pamięć
- ❌ Brak kalibracji scale/rotate na warstwach
- ❌ Brak uploadu nowych assetów (tylko wybór z biblioteki)
- ❌ Brak systemu dual-photo (hero product)
- ❌ Brak podglądu widoku klienta (Surface preview)

### Plan rozbudowy — okrojony kompozytor z pełnymi możliwościami:

**1. Zapis do bazy (`sh_visual_layers`)**
- Przycisk "ZAPISZ WARSTWY" pod canvasem
- Każda warstwa z canvasa = wiersz w `sh_visual_layers` (item_sku + layer_sku + asset + z_index + kalibracja)
- Endpoint: `api_menu_studio.php` → akcja `save_visual_layers`
- Przy ładowaniu dania (`VisualLayerEditor.load()`) → dane z DB zamiast auto-mapu

**2. Kalibracja na żywo (scale + rotate) — LIVE PREVIEW**
- Przy każdej warstwie w zakładce "Warstwy" → dwa suwaki:
  - Scale: `0.80` → `1.20` (krok 0.01)
  - Rotate: `-180°` → `180°` (krok 1°)
- Zmiana suwaka = natychmiastowa aktualizacja `transform` na canvasie (bez przeładowania)
- Manager WIDZI NA ŻYWO jak warstwa pasuje do reszty kompozycji
- Wartości zapisywane w `sh_visual_layers.cal_scale` i `sh_visual_layers.cal_rotate`
- CSS canvasa: `transform: scale(var(--cal-scale, 1)) rotate(var(--cal-rotate, 0deg))` (identyczny jak online)

**3. Upload zdjęć (drag & drop)**
- W zakładce "Biblioteka" → sekcja "Wgraj nowy asset" u góry
- Drag & drop zone lub file picker (HTML5 `<input type="file" accept=".webp,.png">`)
- DWA SLOTY na upload:
  - Slot A: "Warstwa (scatter)" → `asset_filename`
  - Slot B: "Hero (produkt)" → `product_filename`
- Walidacja po stronie JS:
  - Format: tylko `.webp` lub `.png`
  - Wymiary: min 600x600px (hero) / 1000x1000px (layer)
  - Rozmiar: max 5MB
- Auto-generowanie `ascii_key` z nazwy pliku (np. `veg_tomato_cherry_a1b2c3`)
- Upload via `api_menu_studio.php` → akcja `upload_visual_asset`
- Pliki zapisywane do `/uploads/global_assets/` (shared) lub `/uploads/visual/{tenant_id}/` (per-tenant)
- Wytyczne fotograficzne jako tooltip/info-box przy uploaderze

**4. Podgląd widoku klienta ("Podgląd Online")**
- CZWARTA zakładka obok Warstwy/Biblioteka/Cross-sell
- Symuluje dokładny widok klienta na stronie online:
  - Ciemne tło surface (z `sh_tenant_settings.storefront_surface_bg`)
  - Połowa pizzy (prawy półkrąg, lewa krawędź cięcia)
  - Hero products materializujące się obok
- Toggle: "Pełna pizza / Połowa / Pół na pół" do testowania różnych widoków
- Używa **dokładnie tego samego CSS** co moduł online (shared stylesheet lub skopiowane reguły)
- Manager widzi 1:1 to co zobaczy klient

**5. Inline wytyczne fotograficzne**
- Małe info-boxy przy uploaderze:
  - "📸 Warstwa: widok z góry, przezroczyste tło, min 1500×1500px"
  - "📸 Hero: pojedynczy produkt, przezroczyste tło, min 600×600px"
- Link "Pokaż pełny brief" → rozwija szczegółowe wytyczne (z sekcji 9)

### Schemat interakcji managera (flow):

```
1. Otwórz danie w Studio → przewiń do "Konfigurator Wizualny"
2. Canvas pokazuje aktualne warstwy (z sh_visual_layers LUB auto-map jako fallback)
3. Zakładka "Biblioteka" → klik na asset → dodaje do canvasa
4. Zakładka "Warstwy" → suwaki scale/rotate → natychmiastowy efekt na canvasie
5. Zakładka "Podgląd Online" → widzi to samo co klient (połowa pizzy + surface)
6. Potrzeba nowego assetu → "Wgraj nowy" → drag & drop → upload do serwera
7. Klik "ZAPISZ WARSTWY" → persystencja do sh_visual_layers
```

### Zagrożenia rozbudowy Studio:

| # | Zagrożenie | Mitygacja |
|---|-----------|-----------|
| 1 | Upload dużych plików blokuje UI | Async upload z progress barem, limit 5MB per plik |
| 2 | Manager wgra złe zdjęcie (z tłem, źle wykadrowane) | Walidacja: format, wymiary min., ostrzeżenie jeśli brak alpha |
| 3 | Kalibracja transform psuje clip-path | Architektura 3-warstwowa: clip-path na kontenerze, transform na img (już zaprojektowana) |
| 4 | Podgląd rozsynchronizowany z online | Studio preview używa identycznego CSS co frontend online |
| 5 | Manager nie rozumie systemu warstw | Tooltip z instrukcją + automatyczny auto-map jako fallback startowy |

---

## 11. ZASADY ARCHITEKTURY SYSTEMOWEJ

- Moduł online (`/modules/online/`) jest **WYŁĄCZNIE do odczytu** — klient końcowy
- Zero narzędzi edycji wizualnej (drag & drop, uploadery, kalibratory) w module online
- Wszystkie ustawienia wizualne (tło, kalibracja warstw, zdjęcia) zarządzane w **Studio** (`/modules/studio/`)
- Online czyta i aplikuje → Studio pisze i zarządza
- API `product.php` zwraca gotowe URL-e i parametry kalibracji → frontend je tylko renderuje
- `VisualLayerEditor` w Studio to jedyne miejsce w systemie do zarządzania warstwami wizualnymi

---

## 12. MIGRACJA BAZY DANYCH — WYKONANA ✅

**Plik:** `database/migrations/016_visual_compositor_upgrade.sql`
**Status:** UTWORZONY, gotowy do uruchomienia

Migracja jest **idempotentna** (bezpieczna do wielokrotnego uruchomienia) — sprawdza istnienie kolumn przed dodaniem.

### Co dodaje:

| Tabela | Kolumna | Typ | Opis |
|--------|---------|-----|------|
| `sh_visual_layers` | `product_filename` | `VARCHAR(255) NULL` | Hero product photo (standalone, surface/grid) |
| `sh_visual_layers` | `cal_scale` | `DECIMAL(4,2) DEFAULT 1.00` | Kalibracja: współczynnik skali 0.50-2.00 |
| `sh_visual_layers` | `cal_rotate` | `SMALLINT DEFAULT 0` | Kalibracja: rotacja w stopniach -180 do 180 |
| `sh_board_companions` | `product_filename` | `VARCHAR(255) NULL` | Hero product photo dla surface materialization |
| `sh_tenant_settings` | (wiersz) `storefront_surface_bg` | KV | Tło surface uploadowane przez managera |

### SQL idempotentny (fragment):

```sql
-- Sprawdzenie istnienia kolumny przed ALTER TABLE
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sh_visual_layers'
  AND COLUMN_NAME = 'product_filename');
-- IF NOT EXISTS → ALTER TABLE ADD COLUMN
```

---

## 13. KOLEJNOŚĆ IMPLEMENTACJI (ZAKTUALIZOWANA)

### Faza 1: Fundament (DB + API)
1. **Migracja DB** — `product_filename`, `cal_scale`, `cal_rotate`, `storefront_surface_bg`
2. **API rozszerzenie** — `product.php` + nowe endpointy: `save_visual_layers`, `upload_visual_asset`, `get_menu_categories` (dla online)

### Faza 2: Studio — Rozbudowa kompozytora
3. **Studio: zapis warstw** — `VisualLayerEditor` → persystencja do `sh_visual_layers`
4. **Studio: kalibracja** — suwaki scale/rotate z live preview na canvasie
5. **Studio: upload** — drag & drop z walidacją, dual-photo slots
6. **Studio: podgląd online** — 4. zakładka symulująca widok klienta

### Faza 3: Online Frontend
7. **HTML/CSS** — nowa struktura DOM (akordeon + bottom sheet + floating cart)
8. **JS Engine** — przepisanie renderingu, zachowanie logiki biznesowej
9. **Integracja koszyka** — floating cart drawer + CustomEvent bridge
10. **Half & Half** — masking + Pizza B selector w bottom sheet

### Faza 4: Polski i optymalizacja
11. **Animacje** — materializacja na surface, layer-land, przejścia
12. **Fallbacki** — graceful degradation bez zdjęć, bez surface bg
13. **Testy mobile** — responsywność, performance, touch events
14. **Cross-sell step** — krok po dodaniu dania z poleceniami

---

## 14. CO JUŻ MAMY I MOŻEMY WYKORZYSTAĆ

### Pliki gotowe do rozbudowy (nie usuwamy, rozbudowujemy):
| Plik | Stan | Co z nim robimy |
|------|------|-----------------|
| `modules/studio/js/studio_visual.js` | Działa (preview only) | Dodajemy: save, kalibrację, upload, podgląd online |
| `modules/studio/js/studio_item.js` | Działa (formularz dania) | Sekcja `sec-visual` już istnieje — integracja gotowa |
| `modules/studio/index.html` | Działa | Bez zmian — `VisualLayerEditor` renderuje się dynamicznie |
| `api/online/engine.php` | **GOTOWY** (zastąpił `product.php` 2026-04-16) | Akcje: get_storefront_settings, get_menu, get_dish, cart_calculate |
| `api/backoffice/api_menu_studio.php` | Działa | Dodajemy akcje: `save_visual_layers`, `upload_visual_asset` |
| `api/cart/CartEngine.php` | Działa | Bez zmian |
| `core/WzEngine.php` | Działa | Bez zmian |

### Pliki do utworzenia (stary moduł online usunięty 2026-04-16):
| Plik | Powód |
|------|-------|
| `modules/online/index.html` | Nowy shell zgodny z konwencją POS/Tables (czysty HTML) |
| `modules/online/css/style.css` | Surface theme od zera (dark, glass, mobile-first) |
| `modules/online/js/online_api.js` | Wrapper na `engine.php` (wzorzec `pos_api.js`) |
| `modules/online/js/online_app.js` | State machine + glue (lifecycle + init) |
| `modules/online/js/online_ui.js` | Rendering: akordeon + Surface Card + cart drawer |

### Baza danych (istniejące tabele, rozbudowywane):
| Tabela | Stan | Zmiana |
|--------|------|--------|
| `sh_visual_layers` | Istnieje | +`product_filename`, +`cal_scale`, +`cal_rotate` |
| `sh_board_companions` | Istnieje | +`product_filename` |
| `sh_global_assets` | Istnieje | Bez zmian (shared asset library) |
| `sh_price_tiers` | Istnieje | Bez zmian |
| `sh_tenant_settings` | Istnieje | +wiersz `storefront_surface_bg` |

---

## 15. LIMITY UPLOADU I INSTRUKCJA FOTOGRAFICZNA — WYKONANA ✅

**Plik:** `_docs/05_INSTRUKCJA_FOTO_UPLOAD.md`
**Status:** UTWORZONY, gotowy do użycia

### Kluczowe limity (wymuszone na serwerze i kliencie):

| Typ assetu | Max rozmiar | Min wymiary | Max wymiary | Format |
|-----------|-------------|-------------|-------------|--------|
| Warstwa (scatter) | **3 MB** | 1000×1000 | 3000×3000 | `.webp` / `.png` |
| Hero (produkt) | **1.5 MB** | 400×400 | 1200×1200 | `.webp` / `.png` |
| Miniaturka dania | **800 KB** | 300×300 | 800×800 | `.webp` / `.png` |
| Tło surface | **5 MB** | 1920×1080 | 3840×2400 | `.webp` / `.jpg` |
| Companion | **1.5 MB** | 400×400 | 1200×1200 | `.webp` / `.png` |

### Walidacja dwupoziomowa:
1. **JS (pre-upload):** format, rozmiar `File.size`, wymiary `Image.naturalWidth/Height`, preview
2. **PHP (serwer):** MIME type `getimagesize()`, rozszerzenie, rozmiar, wymiary, alpha channel

### ZAKAZANE formaty: `.jpg` (brak alpha), `.gif`, `.bmp`, `.svg`, `.tiff`, `.heic`
Wyjątek: tło surface akceptuje `.jpg` (nie wymaga przezroczystości).

### Komunikaty błędów:
Zdefiniowane po polsku w dokumencie, gotowe do implementacji w JS i PHP.

### Instrukcja zawiera też:
- Kompletny brief fotograficzny (warstwy, hero, surface, companions, miniaturki)
- Checklist managera przed uploadem
- Konwencję nazw plików (auto-generowane `{category}_{sub_type}_{6hex}.{ext}`)

---

## 16. BEZPIECZEŃSTWO UPLOADÓW — WYKONANE ✅

### .htaccess w katalogach uploadów:

| Ścieżka | Stan | Co robi |
|---------|------|---------|
| `uploads/visual/.htaccess` | Istniał ✅ | Blokuje PHP, pozwala tylko .webp/.png |
| `uploads/global_assets/.htaccess` | **DODANY** ✅ | Identyczna ochrona — blokuje PHP, pozwala tylko .webp/.png |

### Reguły bezpieczeństwa uploadów:
- `Options -Indexes -ExecCGI` — brak listowania, brak wykonywania skryptów
- `RemoveHandler .php` — PHP nie wykona się nawet jeśli ktoś wgra `.php`
- `FilesMatch` — tylko `.webp` i `.png` mogą być serwowane, reszta = `403 Forbidden`
- Walidacja server-side (PHP): MIME type, rozszerzenie, rozmiar, wymiary, alpha channel
- Walidacja client-side (JS): format, `File.size`, `Image.naturalWidth/Height`, preview

---

## 17. SETUP_DATABASE.PHP — ZAKTUALIZOWANY ✅

`scripts/setup_database.php` obsługuje teraz:
- Migracje 006, 007, 008 (oryginalne)
- Migracja 012 — `sh_visual_layers` (CREATE TABLE IF NOT EXISTS)
- Migracja 013 — `sh_board_companions` (CREATE TABLE IF NOT EXISTS)
- Migracja 014 — `sh_global_assets` + `sh_ingredient_assets` (CREATE TABLE IF NOT EXISTS)
- Migracja 016 — `product_filename`, `cal_scale`, `cal_rotate`, `storefront_surface_bg` (idempotentne ALTER)
- Weryfikacja: sprawdza istnienie WSZYSTKICH nowych tabel i kolumn

`scripts/setup_enterprise_tables.php` — oddzielny skrypt dla Dine-In (sh_zones, sh_tables, sh_order_logs).

---

## 18. ISTNIEJĄCE ASSETY W UPLOADS ✅

`uploads/global_assets/` zawiera 16 gotowych plików `.webp`:

| Plik | Kategoria |
|------|-----------|
| `base_dough_ac0cae.webp` | base (ciasto) |
| `sauce_tomato_4a8e30.webp` | sauce (sos pomidorowy) |
| `cheese_mozzarella_b4d22d.webp` | cheese (mozzarella) |
| `meat_salami_cc276c.webp` | meat (salami) |
| `meat_bacon_145ef8.webp` | meat (boczek) |
| `veg_tomato_af2636.webp` | veg (pomidor) |
| `veg_corn_282edf.webp` | veg (kukurydza) |
| `veg_olive_b6b164.webp` | veg (oliwki) |
| `veg_mushroom_29cd69.webp` | veg (pieczarki) |
| `veg_onion_45a36f.webp` | veg (cebula) |
| `veg_pepper_f3c612.webp` | veg (papryka) |
| `veg_cucumber_a8c342.webp` | veg (ogórek) |
| `veg_pea_7fc038.webp` | veg (groszek) |
| `herb_basil_acb969.webp` | herb (bazylia) |
| `herb_spiece_4abc7a.webp` | herb (przyprawy) |
| `board_plate_133aed.webp` | board (deska/talerz) |

Te assety używają już konwencji nazw `{category}_{sub_type}_{6hex}.{ext}` — spójne z naszym systemem.
Brakuje: **hero product photos** (drugie zdjęcie per składnik) — do wykonania przez fotografa.

---

## 19. DOKUMENTACJA — ZAKTUALIZOWANA ✅

| Plik | Co zmienione |
|------|-------------|
| `_docs/02_ARCHITEKTURA.md` | Dodano nowy plik 05_INSTRUKCJA, ustalenia.md; zaktualizowana tabela migracji |
| `_docs/04_BAZA_DANYCH.md` | Dodane migracje 009-016 do tabeli |
| `_docs/05_INSTRUKCJA_FOTO_UPLOAD.md` | **NOWY** — kompletna instrukcja limitów, walidacji i brief fotograficzny |
| `database/README.md` | Pełna lista migracji, setup_enterprise_tables.php, zaktualizowane instrukcje |

---

## 20. SPRZĄTANIE MODUŁU ONLINE — WYKONANE ✅

### Znaleziony bałagan:
W `modules/online/` istniały **3 kompletne wersje** modułu jednocześnie:

| # | Pliki | Wersja | Co to było |
|---|-------|--------|-----------|
| **v2** | `index.html` + `css/builder.css` + `js/pizza_builder.js` | Cinematic Pizza Builder | Ciemny 3D tilt, cząsteczki mąki, step wizard |
| **v3** | `css/composer.css` + `js/board_composer.js` | Immersive Pizza Composer | OSIEROCONY — żaden HTML go nie ładował! |
| **v4** | `ordering.html` + `css/ordering.css` + `js/ordering_engine.js` | Subtle Italian SaaS | Parchment jasny, dual-state, half-pizza |

Razem: **~125 KB śmieci** (v2: 63 KB + v3: 62 KB).

### Co zrobione:
1. v2 → skopiowany do `_archive/online_v2/` i **usunięty** z `modules/online/`
2. v3 → skopiowany do `_archive/online_v3/` i **usunięty** z `modules/online/`
3. Zostało TYLKO v4 (ordering.*) — baza do nowej wersji "Surface"

### Po sprzątaniu `modules/online/` zawiera:
```
modules/online/
├── ordering.html              (91 linii — do przepisania na Surface)
├── css/ordering.css           (1112 linii — do przepisania na Surface)
└── js/ordering_engine.js      (978 linii — rendering przepisany, logika zachowana)
```

### Dodatkowe pliki w repo:
- `core/js/neon_pizza_engine.js` — proceduralny SVG neon generator. Fallback kiedy brak zdjęć .webp. ZACHOWANY (nie jest śmieciem, to narzędzie utility).

---

## 21. API UPLOAD — NAPRAWIONY ✅

`api/backoffice/api_visual_studio.php` — kompletnie przepisany:

### Nowe parametry POST:
| Pole | Opis | Przykład |
|------|------|---------|
| `asset` | Plik (multipart) | (file binary) |
| `asset_type` | Typ assetu | `layer` / `hero` / `thumbnail` / `surface` / `companion` |
| `category` | Kategoria składnika | `meat` / `veg` / `herb` / `sauce` / `cheese` / `base` |
| `sub_type` | Pod-typ | `salami` / `tomato` / `basil` |

### Limity per typ (zgodne z 05_INSTRUKCJA_FOTO_UPLOAD.md):
| Typ | Max rozmiar | Min wymiary | Max wymiary |
|-----|-------------|-------------|-------------|
| `layer` | 3 MB | 1000×1000 | 3000×3000 |
| `hero` | 1.5 MB | 400×400 | 1200×1200 |
| `thumbnail` | 800 KB | 300×300 | 800×800 |
| `surface` | 5 MB | 1920×1080 | 3840×2400 |
| `companion` | 1.5 MB | 400×400 | 1200×1200 |

### Wyjątek format dla surface:
`surface` akceptuje `.jpg` / `.jpeg` (nie wymaga alpha channel). Reszta: tylko `.webp` / `.png`.

### Konwencja nazw (automatyczna):
`{category}_{sub_type}_{sha256_6hex}.{ext}` — np. `meat_salami_cc276c.webp`
Hash z zawartości pliku (SHA-256 → 6 znaków). Unikalne bez kolizji.

### Komunikaty błędów po polsku.
### Walidacja: MIME, rozszerzenie, getimagesize, wymiary min/max, rozmiar per typ.

---

## 22. ONLINE — RESET DO ZERA (2026-04-16) ✅

Stary moduł online (v4 "Surface attempt") został **w całości usunięty** decyzją usera: *"po starym module online nie potrzebujemy nic robimy wszystko od nowa ale trzymajac sie koncepcji"*.

### Usunięte pliki (sprzątanie 2026-04-16):
| Ścieżka | Powód |
|---------|-------|
| `modules/online/ordering.html` | Stary shell v4 — nie pasuje do koncepcji nowego modułu |
| `modules/online/css/ordering.css` | Stary Surface theme z błędną logiką masek |
| `modules/online/js/ordering_engine.js` | Stary engine z mieszaną logiką (rendering + biznes) |
| `api/online/product.php` | Zastąpiony przez `api/online/engine.php` (action-based) |
| `_archive/online_v2/*` (3 pliki) | Martwe archiwum — referencje do usuniętego `product.php` |
| `_archive/online_v3/*` (3 pliki) | Martwe archiwum — komozytor v3 |

**Katalogi `modules/online/` i `_archive/online_v2/`, `_archive/online_v3/` nie istnieją fizycznie po sprzątaniu.**

### Co zostaje (gotowe do nowego frontendu):
- ✅ `api/online/engine.php` — unified backend (akcje: get_storefront_settings, get_menu, get_dish, cart_calculate)
- ✅ `api/cart/CartEngine.php` — silnik koszyka (statyczna `::calculate`)
- ✅ `core/WzEngine.php` — dedukcja magazynu (half-half × 0.5)
- ✅ `core/SequenceEngine.php` — atomic numeracja zamówień
- ✅ `database/migrations/016_visual_compositor_upgrade.sql` — `surfaceBg`, `cal_scale/cal_rotate`, `product_filename`
- ✅ `uploads/global_assets/*.webp` — 16 testowych assetów (base, sauce, cheese, mięsa, warzywa, zioła, board)
- ✅ Wytyczne fotograficzne: `_docs/05_INSTRUKCJA_FOTO_UPLOAD.md`

### Następny krok — budowa nowego `modules/online/` od zera
Zgodnie ze wzorcem POS/Tables/Courses (action-based API + modularny JS):
```
modules/online/
├── index.html              # Czysty shell (jak modules/pos/index.html)
├── css/style.css           # Surface theme — od zera, mobile-first
└── js/
    ├── online_api.js       # Wrapper na engine.php (jak pos_api.js)
    ├── online_app.js       # State machine + glue
    └── online_ui.js        # Akordeon kategorii + Surface Card + cart drawer
```

**Zasada:** żadnych narzędzi edycji wizualnej w online — tylko storefront dla klienta. Edycja warstw → Studio (zakładka Visual Compositor).

---

## 23. PRZYSZŁE MODUŁY (ŚWIADOMOŚĆ)

Po stabilizacji online ordering + rozbudowie Studio:
- **Moduł Marketingowy** — sezonowe surface'y, promocje, wyróżnione dania
- **Moduł Lojalnościowy** — badge'e, nagrody, streaki na surface
- **Moduł Statystyk** — heatmapa kliknięć, A/B testy zdjęć, konwersja per składnik
- **E-Menu (QR) i Podgląd** — już widoczny w nawigacji Studio jako "WKRÓTCE"

---

## 24. AKCEPT WIZJI MODUŁU ONLINE + MANAGER EDITOR (2026-04-16)

**Dokument wizji:** `_docs/06_WIZJA_MODULU_ONLINE.md` — kompletny, 13 sekcji.

### Zatwierdzone decyzje (user approved):

1. **Dwa moduły zamiast jednego:**
   - `modules/online/` — STOREFRONT (klient, public)
   - `modules/online_studio/` — MANAGER EDITOR (JWT auth, 5 zakładek)
   - Link do composera dodamy *później* z głównego Studio

2. **Migracja 017** — wszystkie 5 zmian:
   - `sh_visual_layers.version` (optimistic locking)
   - `sh_visual_layers.library_category` + `library_sub_type`
   - `sh_checkout_locks` (idempotency tokens, TTL 5min)
   - `sh_orders.tracking_token` (guest tracker)
   - 5× default `sh_tenant_settings` dla online module

3. **Tenant resolution (storefront):** fallback chain
   - 1) subdomain (`mojapizza.slicehub.pl`)
   - 2) URL param (`?tenant=1`)
   - 3) `<meta name="sh-tenant-id">` w HTML shell
   - Najbardziej elastyczne + production-ready

4. **Apple Pay / Google Pay:** P1 (po MVP — gotówka + przelew wystarczy na start)

### Status implementacji (2026-04-17):

| Komponent | Status |
|-----------|--------|
| `_docs/06_WIZJA_MODULU_ONLINE.md` | ✅ GOTOWE |
| `database/migrations/017_online_module_extensions.sql` | ✅ GOTOWE (idempotentne) |
| `scripts/setup_database.php` — sekcja 017 | ✅ GOTOWE |
| `api/online/engine.php` — akcje podstawowe (4) | ✅ GOTOWE |
| `api/online/engine.php` — akcje rozszerzone (4 nowe) | ✅ GOTOWE |
| `api/online_studio/engine.php` — 12 akcji (JWT/session + role owner/admin/manager) | ✅ GOTOWE — Etap 2 |
| `api/online_studio/*_upload.php` (multipart) | ⏳ Etap 2b (opcjonalnie — jest `api/visual_composer/asset_upload.php`) |
| `modules/online/` — frontend storefront (v1: menu + karta + koszyk) | ✅ szkielet działa |
| `modules/online_studio/` — frontend manager | ⏳ Etap 5-7 |

### Akcje w `api/online/engine.php` (ostateczna lista 8):

1. `get_storefront_settings` — tenant, surfaceBg, halfHalfSurcharge, online flags
2. `get_menu` — kategorie + dania z cenami (akordeon)
3. `get_dish` — item, mods, companions, visual layers, global assets
4. `cart_calculate` — server-authoritative (CartEngine::calculate static)
5. `get_popular` — top SKU ostatnie 30 dni (fallback: display_order)
6. `delivery_zones` — ST_Contains geofencing + ETA + min_order
7. `init_checkout` — lock_token UUID TTL 5min, idempotent po cart_hash
8. `track_order` — status stages + driver GPS (token + phone match security)

### Akcje w `api/online_studio/engine.php` (12 — wymaga `Authorization: Bearer` lub sesji + rola owner/admin/manager):

1. `library_list` — `sh_global_assets` + licznik przypisań (distinct `item_sku` w `sh_visual_layers`)
2. `library_update` — edycja metadanych assetu (nie `tenant_id=0` globalnych)
3. `library_delete` — soft `is_active=0` (opcjonalnie `force` jeśli są przypisania)
4. `composer_load_dish` — item, warstwy, companions, podgląd biblioteki (500), `surfaceBg`, flagi schematu
5. `composer_save_layers` — upsert warstw; `replaceAll` = pełny replace; merge z optimistic lock (`version`)
6. `composer_calibrate` — pojedyncza warstwa `cal_scale` / `cal_rotate`
7. `composer_clone` — kopiuj stos `sourceSku` → `targetSku`
8. `composer_autofit_suggest` — średnia kalibracja dla `sub_type` z biblioteki na danym daniu
9. `companions_list` — lista cross-sell dla `itemSku`
10. `companions_save` — `DELETE` + `INSERT` companions (walidacja SKU w menu)
11. `surface_apply` — `storefront_surface_bg` = bezpieczna nazwa pliku w `global_assets`
12. `preview_url` — URL iframe pod `modules/online/index.html` (gdy shell będzie gotowy)

### Ostatnie kroki:
- Smoke test Studio API: zaloguj się do aplikacji (JWT), `POST` JSON na `/slicehub/api/online_studio/engine.php`
- Następnie: frontend `modules/online_studio/` + ewentualnie dedykowane `*_upload.php` pod ten moduł

---

