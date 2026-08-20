# Zestawienie modułów — stan faktyczny (audyt 2026-08-19)

Weryfikacja krążącego opisu systemu względem realnego kodu (main @ eb4e1bf).
Pełne opisy architektury: `00_PAMIEC_SYSTEMU.md`, `01_KONSTYTUCJA.md`, `02_ARCHITEKTURA.md`.

## Zgodne z kodem (potwierdzone)

- Silosy i prefiksy `sh_` / `sys_` / `wh_`, komunikacja cross-silo wyłącznie po `sku` / `ascii_key` + `tenant_id`.
- SSOT ścieżek API: `core/js/sh_api_base.js` (`SliceHub.apiUrl()`), fallback `/slicehub/api` ↔ `/api`.
- POS: login PIN (`api/auth/login.php`, mode=kiosk), PanicEngine z 2-min debounce, PWA (`modules/pos/sw.js` + manifesty), variant scales.
- Logistyka: K{n}/L{n} (`sh_course_sequences`), dyspozytor na Leaflet (`modules/courses`), SSE kierowcy (`api/courses/sse_driver.php`), payment lock (`collect_payment`), emergency recall (`heading = -999`), reconcile.
- Magazyn/KDS: `KdsTicketEngine`, `WarehouseConsumeHook`/`WarehouseReverseHook`, AVCO (`PzEngine`), receptury, subrecipes, meal packages (`MealEngine`), modyfikatory.
- Menu Studio + Studio Online, matryca cenowa `sh_price_tiers` (per kanał, odseparowana od `sh_menu_items`).
- KSeF: Inbox (`api/procurement/inbox.php`), AutoScan (`core/AutoScanEngine.php`), klasy `core/Ksef/` (5 plików), `ksef_config.php`.
- Elzab: `core/Elzab/` (3 pliki, `ElzabFiscalEngine`).
- Marketing: SMS (kampanie `api/marketing/engine.php` + notyfikacje statusów `core/Notifications/`), deck A5 (`api/marketing/deck_engine.php`).
- Backoffice: BI (`core/BiEngine.php`, `api/bi/dashboard_data.php`), HR/payroll (`api/backoffice/hr/engine.php`), `SettlementEngine` (split-tender), profil firmy, kategorie wydatków.

## Korekty względem krążącego opisu

| Opis mówi | Stan faktyczny |
|---|---|
| SliceHub pobiera (pull) menu z ChoiceQR | Odwrotnie: ChoiceQR pobiera menu/strefy ZE SliceHub (GET `menu.php`, `areas.php`) i pushuje zamówienia (POST `webhook.php`). Zob. `_docs/integrations/choiceqr_integration.md` |
| ChoiceQR: 7 endpointów, w tym `table_orders.php` | 4 pliki: `webhook.php`, `menu.php`, `areas.php`, `_check_config.php`. Zamówienia stolikowe idą przez `webhook.php` (dine_in) + natywne `modules/tables` / `api/tables/engine.php` |
| Podgląd zamówień: `api/orders/get.php` | Plik nie istnieje. Podgląd zamówień przez akcje `api/pos/engine.php`. W `api/orders/` są: `edit.php`, `estimate.php`, `sla_monitor.php` (wszystkie @planned — czekają na frontend) |
| Food Cost UI: `modules/backoffice/food_cost/` | Celowo w Studio jako Margin Guardian (`modules/studio/js/studio_margin.js` + `core/FoodCostEngine.php` + `api/reports/food_cost.php`). Zob. `19_STUDIO_MENU_PRO_DESIGN.md` |
| Edycja zamówień: `modules/backoffice/order_edit/` | Frontendu brak — backend `api/orders/edit.php` (DeltaEngine, kitchen_delta) gotowy, @planned na modal „Edytuj zamówienie" w admin_hub (Faza 3) |
