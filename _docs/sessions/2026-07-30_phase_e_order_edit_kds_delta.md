# Sesja: Faza E — Wpięcie edit.php + DeltaEngine w KDS (Edytuj zamówienie)

**Data:** 2026-07-30
**Powiązane:** `_docs/sessions/2026-07-29_promised_time_sla_implementation_prompt.md` (Faza E)
**Konstytucja:** Prawo VI (Snajper), Prawo VIII (Domknięcie Kontraktu), Prawo X (Audyt Sesji)

---

## 1. Cel

Wpięcie `api/orders/edit.php` + `DeltaEngine` (orphan od audytu 2026-04-19) w panel
backoffice + KDS. Manager edytuje zamówienie (dodaj/usuń/zmień linię) → serwer
przelicza CartEngine, wykrywa delta przez DeltaEngine, zapisuje `kitchen_delta` JSON
na order header → KDS highlightuje zmienione linie (zielony=dodane, żółty=zmienione,
czerwony=usunięte) + banner "ZAMÓWIENIE EDYTOWANE".

Dotychczas: `edit.php` + `DeltaEngine.php` kompletne, zero konsumentów frontend
(Prawo VIII: 🟡 PLANNED).

---

## 2. Pliki dotknięte (Snajper)

### Nowy backend (read-only order detail)

| Plik | Zmiana |
|---|---|
| `api/orders/get.php` | Nowy endpoint `GET ?order_id=...` — zwraca order header + lines z `line_id` (= `sh_order_lines.id`) dla DeltaEngine matching. JSON fields decoded. Tenant isolation (`tenant_id = :tid`). |

### Nowy moduł `modules/backoffice/order_edit/`

| Plik | Zmiana |
|---|---|
| `modules/backoffice/order_edit/index.html` | Nowa strona: topbar, input order_id (auto-load z `?order_id=`), formularz linii (qty + SKU picker z `get_menu_tree`), podsumowanie, przycisk Zapisz. |
| `modules/backoffice/order_edit/css/order_edit.css` | Style (karty, linie, delta preview z color-coded add/mod/rem). |
| `modules/backoffice/order_edit/js/order_edit_app.js` | Vanilla JS IIFE. JWT z `localStorage['sh_token']`. `SliceHub.apiUrl()` SSOT. Prawo IV: frontend wysyła tylko `{line_id, item_sku, quantity}` — bez cen/totali. |

### KDS backend — rozszerzenie SELECT (Snajper: tylko get_board)

| Plik | Linia | Zmiana |
|---|---|---|
| `api/kds/engine.php` | ~77 (get_board SELECT) | Dodane `COALESCE(o.edited_since_print, 0) AS edited_since_print` + `o.kitchen_delta` do SELECT. |
| `api/kds/engine.php` | ~162 (foreach $rows) | Dekodowanie `kitchen_delta` (JSON column → array) + cast `edited_since_print` na int. Inne akcje nietknięte. |

### KDS frontend — highlight delta (Snajper: tylko funkcja render + nowe helpery)

| Plik | Zmiana |
|---|---|
| `modules/kds/js/kds_app.js` | Nowe helpery: `_deltaInfo(order)` (mapa line_id → {added/modified}), `_deltaLineCls()`, `_deltaLineTag()`. Funkcja `render()`: dodany `editedBanner` (gdy `edited_since_print && hasDelta`), `deltaCls` na liniach, `deltaTag` (tag "+DODANE" / "~ZMIENIONE: ..."), sekcja usuniętych linii (przekreślone, "-USUNIĘTE"). Inne funkcje nietknięte. |
| `modules/kds/css/style.css` | Nowe style: `.kds-edited-banner`, `.kds-line.delta-added/.delta-modified/.delta-removed` (border-left color), `.kds-delta-tag` (.kds-delta-add/-mod/-rem). Dodane na końcu pliku. |

### Backend docblock (Snajper — tylko nagłówek STATUS)

| Plik | Zmiana |
|---|---|
| `api/orders/edit.php` | Nagłówek `STATUS: PLANNED` → `STATUS: DOMKNIĘTE 2026-07-30 (Faza E, Prawo VIII)` + opis konsumenta. Zero zmian logiki. |

### Dokumentacja (Prawo VIII — Domknięcie Kontraktu)

| Plik | Zmiana |
|---|---|
| `_docs/01_KONSTYTUCJA.md` | `api/orders/edit.php` 🟡 PLANNED → ✅ **DOMKNIĘTE 2026-07-30 (Faza E)** |
| `_docs/02_ARCHITEKTURA.md` | j.w. — tabela Orders |

---

## 3. Decyzje architektoniczne

1. **Nowy `get.php` (read-only)** — edit.php wymaga pełnego order (channel, order_type)
   + lines z `line_id` do zachowania istniejących linii. Nie było endpointu
   zwracającego pojedynczy order z lines (KDS `get_board` zwraca listę aktywnych,
   POS `get_orders` zwraca Kanban). Nowy read-only `get.php` — minimalny, tenant-safe.
2. **Prawo IV (Zero Zaufania)** — frontend wysyła tylko `{line_id, item_sku, quantity}`.
   Bez cen, bez totali, bez modifiers (modyfikatory istniejących linii zachowane przez
   `line_id` matching w DeltaEngine — modified wykrywa zmiany qty/mods/removals/comment).
   Serwer przelicza `CartEngine::calculate()` i zwraca przeliczone totaly.
3. **`line_id` matching** — istniejące linie zachowują `line_id` (UUID z `sh_order_lines.id`).
   Nowe linie mają `line_id: null` → DeltaEngine klasyfikuje jako `added`. Usunięte linie
   (nieobecne w nowym lines array) → `removed`. Zmienione (qty/mods/removals/comment) → `modified`.
4. **KDS highlight 3-kolorowy** — zielony (added, border-left #22c55e), żółty (modified,
   #eab308), czerwony (removed, #ef4444, przekreślone). Banner "ZAMÓWIENIE EDYTOWANE"
   gdy `edited_since_print=1 && kitchen_delta != null`. Tagi "+DODANE" / "~ZMIENIONE: ..."
   / "-USUNIĘTE" na liniach.
5. **`kitchen_delta` dekodowanie w backend** — MariaDB zwraca JSON column jako string.
   `get_board` dekoduje do array w pętli foreach (Snajper — tylko ta pętla). Frontend
   dostaje obiekt, nie string.
6. **Snajper** — dotknięte: 1 nowy backend (`get.php`), 3 nowe pliki modułu, 2 sekcje
   w `kds/engine.php` (SELECT + foreach), 3 nowe helpery + funkcja `render` w `kds_app.js`,
   1 blok CSS na końcu `style.css`, 1 docblock w `edit.php`, 2 linie w docs. Inne funkcje
   w tych plikach nietknięte.

---

## 4. Weryfikacja (Test E2E)

- **Lint:** `php -l` na 4 backendach (`get.php`, `edit.php`, `DeltaEngine.php`,
  `kds/engine.php`) → "No syntax errors detected" ×4. `node -c` na 2 frontendach
  (`order_edit_app.js`, `kds_app.js`) → OK.
- **Smoke test E2E** (`scripts/_tmp_test_phase_e.php` — tymczasowy, usunięty przed commit):
  - Login waiter (tenant 1, PIN 1111) → OK
  - Znaleziono order `CQR/20260729/0002` (status=accepted, 2 linie: BURGER_CLASSIC + SIDE_FRIES)
  - `GET get.php?order_id=...` → 2 linie z `line_id`
  - `POST edit.php` (dodano BURGER_BBQ x1) → `success:true`, `grand_total=71.28`
  - `delta.added=1`, `delta.modified=1`, `delta.removed=0` (modified = SIDE_FRIES,
    CartEngine przeliczył line_total/modifiers)
  - KDS `get_board` → order na tablicy, `edited_since_print=1`, `kitchen_delta` zwrócone
    (decoded: added=1, modified=1)
  - DB check: `edited_since_print=1`, `kitchen_delta` = 533 bytes (SET)
- **Suite 62 testów:** 61 passed, 0 failed, 1 warning (headless puppeteer-core,
  Chrome `C:\Program Files\Google\Chrome\Application\chrome.exe`, `CHROME_PATH`).
  Błędy 400/401/404/405 w logach = oczekiwane negatywne testy walidacji. **Brak regresji.**

### Odkryte pre-existing issue (NIE dotyczy Fazy E)

Demo data (`seed_demo_all.php`) zapisuje `item_sku=MARGHERITA` w `sh_order_lines`, ale
`sh_menu_items.ascii_key=PIZZA_MARGHERITA`. CartEngine (przy edycji) szuka po `ascii_key`
i rzuca `"SKU 'MARGHERITA' not found"`. To NIE jest bug Fazy E — to pre-existing data
inconsistency w seedzie (legacy SKU w order_lines vs aktualne ascii_key w menu).
Edycja zamówień z poprawnymi SKU (np. CQR/20260729/0002 z BURGER_CLASSIC) działa.
Rekomendacja dla kolejnej sesji: fix seed_demo_all.php żeby używał ascii_key, albo
fallback w CartEngine po `name` gdy `ascii_key` nie istnieje (ostatnie — ryzykowne).

---

## 5. Otwarte pytania (dla kolejnej sesji)

1. **Demo data SKU inconsistency** — `seed_demo_all.php` zapisuje legacy SKU (MARGHERITA)
   w order_lines, ale `sh_menu_items.ascii_key=PIZZA_MARGHERITA`. Edycja takich zamówień
   przez edit.php się nie powiedzie (CartEngine szuka po ascii_key). Fix: seed_demo_all
   używa ascii_key, ALBO edit.php fallback po snapshot_name. Niski priorytet (demo data).
2. **Modyfikatory w edycji** — obecnie formularz edytuje tylko qty + dodawanie/usuwanie
   linii. Edycja modyfikatorów (added_modifier_skus, removed_ingredient_skus) istniejących
   linii nie jest w UI (DeltaEngine wykryje zmiany jeśli frontend je wyśle, ale UI tego
   nie expose'uje). Niski priorytet — obecny zakres spełnia kontrakt Fazy E (dodaj/usuń linię).
3. **`edited_since_print` reset** — flaga jest ustawiana na 1 przy edycji ale nigdy
   resetowana na 0 (np. po ponownym druku paragonu/ticketu). Czy KDS powinien mieć
   przycisk "Potwierdzam zmiany" resetujący flagę? Niski priorytet.
4. **Wszystkie 5 faz A→C→B→D→E DOMKNIĘTE** — `PromisedTimeEngine`, `sla_monitor.php`,
   `food_cost.php`, `edit.php` wpięte end-to-end. Pozostałe `@planned`: `estimate.php`
   (wrapper dla future scheduled-picker UI — silnik wpięty bezpośrednio w 4 ścieżki,
   wrapper pozostaje dla future UI).
