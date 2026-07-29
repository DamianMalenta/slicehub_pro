# Sesja: Faza D — Wpięcie food_cost.php w backoffice (Food Cost Report)

**Data:** 2026-07-30
**Powiązane:** `_docs/sessions/2026-07-29_promised_time_sla_implementation_prompt.md` (Faza D)
**Konstytucja:** Prawo VI (Snajper), Prawo VIII (Domknięcie Kontraktu), Prawo X (Audyt Sesji)

---

## 1. Cel

Wpięcie `api/reports/food_cost.php` (orphan od audytu 2026-04-19) w panel backoffice.
Manager wybiera danie + magazyn → raport pokazuje koszt teoretyczny (AVCO),
rozbicie składników, modyfikatory i marżę per kanał sprzedaży.

Dotychczas: `FoodCostEngine::calculateForSku` kompletny, `food_cost.php` wrapper
kompletny, zero konsumentów frontend (Prawo VIII: 🟡 PLANNED).

---

## 2. Pliki dotknięte (Snajper — nowy moduł + docblock + docs)

### Nowy moduł `modules/backoffice/food_cost/`

| Plik | Zmiana |
|---|---|
| `modules/backoffice/food_cost/index.html` | Nowa strona: topbar, auth banner, selektor magazyn+danie, przycisk "Oblicz Food Cost", kontener raportu. Wzorzec z `modules/backoffice/profile/`. |
| `modules/backoffice/food_cost/css/food_cost.css` | Style (karty, tabele, stat-boxy, badge statusu marży: excellent/healthy/at_risk/critical). Tailwind CDN nieużywany tu — własny CSS jak w profile. |
| `modules/backoffice/food_cost/js/food_cost_app.js` | Vanilla JS IIFE. JWT z `localStorage['sh_token']`. `SliceHub.apiUrl()` SSOT. |

### Backend — bez zmian logiki (tylko docblock STATUS)

| Plik | Zmiana |
|---|---|
| `api/reports/food_cost.php` | Nagłówek `STATUS: PLANNED` → `STATUS: DOMKNIĘTE 2026-07-29 (Faza D, Prawo VIII)` + opis konsumenta. Zero zmian logiki. |

### Dokumentacja (Prawo VIII — Domknięcie Kontraktu)

| Plik | Zmiana |
|---|---|
| `_docs/01_KONSTYTUCJA.md` | `api/reports/food_cost.php` 🟡 PLANNED → ✅ **DOMKNIĘTE 2026-07-29 (Faza D)** |
| `_docs/02_ARCHITEKTURA.md` | j.w. — tabela Reports |

---

## 3. Decyzje architektoniczne

1. **Bez nowych backendów (Snajper)** — pickera zasilają istniejące endpointy:
   - magazyny: `GET api/warehouse/warehouse_list.php` (zwraca `warehouse_id`, `item_count`, `total_value`)
   - menu: `POST api/backoffice/api_menu_studio.php` akcja `get_menu_tree` (zwraca `data.items[{asciiKey, name, categoryId}]` + `data.categories`)
   - raport: `GET api/reports/food_cost.php?item_sku=&warehouse_id=`
   Zero nowych plików backend — tylko frontend + docblock + docs.
2. **Read-only (Prawo IV)** — raport nie wysyła żadnych cen/kosztów; wszystko liczy
   `FoodCostEngine::calculateForSku` po stronie serwera (AVCO z `wh_stock.current_avco_price`).
3. **Grupowanie po kategorii** — item picker to `<select>` z `<optgroup>` per kategoria
   (z `get_menu_tree`). Filtr `isActive` nie jest wymuszany — manager może audytować
   też dania nieaktywne (widoczne z dopiskiem "(nieaktywne)").
4. **Status marży** — 4-tier z `FoodCostEngine::resolveStatus` (excellent ≤25%,
   healthy ≤33%, at_risk ≤40%, critical >40%). Color-coded badge w tabeli kanałów.
5. **Ostrzeżenie missing AVCO** — gdy `missing_avco_warning=true`, żółty banner:
   koszt teoretyczny zaniżony (składnik bez ceny AVCO = 0,00 zł zamiast rzeczywistej).
6. **XSS-safe** — `_esc()` (textContent) dla wszystkich wartości z serwera (SKU, nazwy,
   kanały). `code` tagi dla SKU dla czytelności.
7. **Snajper** — dotknięte tylko: 3 nowe pliki modułu, 1 docblock w `food_cost.php`,
   2 linie w docs. Inne funkcje w `api_menu_studio.php` / `warehouse_list.php` nietknięte.

---

## 4. Weryfikacja (Test E2E)

- **Lint:** `php -l api/reports/food_cost.php` → "No syntax errors detected".
  `node -c modules/backoffice/food_cost/js/food_cost_app.js` → OK (bez błędów składni).
- **Smoke test E2E** (`scripts/_tmp_test_phase_d.php` — tymczasowy, usunięty przed commit):
  - Login waiter (tenant 1, PIN 1111) → OK
  - Znaleziono item z recepturą: `PIZZA_CALZONE`, warehouse: `MAIN`
  - `GET food_cost.php?item_sku=PIZZA_CALZONE&warehouse_id=MAIN` → `success:true`
  - `recipe_cost=12.69`, `waste_cost=0.02`, `total_food_cost=12.71`
  - 7 składników, 4 modyfikatory, 3 kanały (Delivery/POS/Takeaway)
  - `missing_avco=false`
  - Marże: Delivery 58% (critical, fc 42%), POS 54.6% (critical), Takeaway 54.6% (critical)
- **Suite 62 testów:** 61 passed, 0 failed, 1 warning (headless puppeteer-core,
  Chrome `C:\Program Files\Google\Chrome\Application\chrome.exe`, `CHROME_PATH`).
  Błędy 400/401/404/405 w logach = oczekiwane negatywne testy walidacji. **Brak regresji.**

---

## 5. Otwarte pytania (dla kolejnej sesji)

1. **Faza E (edit.php + DeltaEngine)** — ostatnia wg planu A→C→B→D→E. Edycja zamówienia
   z kitchen delta w KDS. Niski priorytet (~4h).
2. **Kalibracja progów statusu marży** — `FoodCostEngine::resolveStatus` ma hardcoded
   25/33/40%. Czy progi powinny być per-tenant w `sh_tenant_settings`? Niski priorytet.
3. **Bulk food cost** — obecnie raport per-item. Czy dodać widok agregacji (całe menu
   z rankingiem marży)? Niski priorytet — obecny widok single-item spełnia kontrakt Fazy D.
4. **Brak receptury** — `FoodCostEngine` rzuca `RuntimeException` gdy danie nie ma
   receptury w `sh_recipes` (HTTP 404). UI pokazuje banner błędu. Czy zamiast tego
   pokazywać "brak receptury — dodaj w Menu Studio"? Niski priorytet (UX polish).
