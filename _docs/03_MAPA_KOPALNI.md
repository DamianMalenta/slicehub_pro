# 03 MAPA KOPALNI (Legacy)

Poniżej znajduje się zaktualizowana mapa folderu `_KOPALNIA_WIEDZY_LEGACY/` po ponownym skanie.  
Priorytety zostały nadane z naciskiem na: zarządzanie, magazyn, 86'ing (braki towarowe), zamówienia, grywalizację oraz architekturę POS.

| Nazwa pliku | Prawdopodobna główna funkcja / Logika Biznesowa | Priorytet przydatności (Wysoki / Średni / Niski) | Uwagi |
|---|---|---|---|
| `app (1).html` | Makieta/panel główny aplikacji POS | Średni | Wersja robocza/snapshot |
| `config_factory_view.html` | Konfigurator ROOT modułów (logistyka/POS/loyalty/order) | Wysoki | Punkt centralny architektury modułowej |
| `in_max.php` | Widok/obsługa inwentaryzacji IN (spis z natury) | Wysoki | Bezpośrednio magazyn i różnice stanów |
| `loyalty_view (1).html` | Panel lojalności, punkty, kody rabatowe (gamifikacja) | Wysoki | Klucz do CRM/grywalizacji |
| `magazyn (3).html` | UI magazynu/logistyki | Wysoki | Dotyczy stanów i operacji magazynowych |
| `marketing_view (1).html` | Widok marketingu/promocji | Średni | Integracje kampanii |
| `order_handler_view (1).html` | KDS/kanban zamówień online i kiosk | Wysoki | Krytyczne dla przepływu zamówień |
| `prod_max (1).html` | Widok produkcji/operacji kuchni | Średni | Powiązany z wykonaniem zamówień |
| `pos (1).html` | Alternatywny widok POS | Wysoki | Ważny dla architektury POS |
| `kiosk (1).html` | Alternatywny widok kiosku | Średni | Kanał sprzedaży samoobsługowej |
| `waiter.html` | Interfejs kelnerski | Wysoki | Kanał zamówień salowych |
| `studio_recipe.js` | Edycja receptur w module Studio | Wysoki | Receptury wpływają na 86'ing/stany |
| `studio_modifiers.js` | Modyfikatory pozycji menu | Średni | Wpływa na warianty zamówień |
| `studio_item.js` | Edycja pozycji menu | Średni | Konfiguracja oferty POS |
| `studio_core.js` | Core frontu Studio + API `api_menu_studio.php` | Wysoki | Spina architekturę menu |
| `studio_bulk.js` | Masowe operacje na menu | Średni | Ułatwia administrację |
| `settings_mapping.html` | Ustawienia mapowania produktów/SKU | Wysoki | Krytyczne dla zgodności danych magazynowych |
| `settings_magazyn.html` | Ustawienia i matryca stanów magazynowych | Wysoki | Bezpośrednio magazyn/86'ing |
| `pos_fleet.js` | Logika floty/delivery dla POS | Wysoki | Kanał dostaw i dispatch |
| `pos_active_routes.js` | Aktywne trasy kierowców | Wysoki | Operacyjna logistyka dostaw |
| `pos.html` | Główny interfejs POS | Wysoki | Fundament sprzedaży i zamówień |
| `online_store.html` | Front zamówień online | Wysoki | Kanał e-commerce i order flow |
| `menu_builder.html` | Budowanie/zarządzanie menu | Średni | Konfiguracja oferty |
| `manifest_boss.json` | Manifest PWA dla roli boss/owner | Niski | Konfiguracja klienta PWA |
| `manifest_admin.json` | Manifest PWA dla admina | Niski | Konfiguracja klienta PWA |
| `manifest.json` | Główny manifest PWA | Niski | Rola pomocnicza runtime |
| `manager_recipes.html` | Panel managera receptur | Wysoki | Kontrola kosztów i składników |
| `manager_pz.html` | Panel managera przyjęć PZ | Wysoki | Krytyczne dla dostaw i stanów |
| `manager_floor.html` | Zarządzanie salą/stolikami | Średni | Operacje FOH |
| `login.html` | Logowanie użytkowników | Średni | Security entrypoint |
| `kiosk_attendance.html` | Kiosk obecności personelu | Średni | HR/ewidencja czasu |
| `kiosk.html` | Główny kiosk sprzedażowy | Średni | Kanał sprzedaży |
| `driver.html` | Panel kierowcy | Wysoki | Dostawy i statusy tras |
| `delivery.html` | Panel dostaw | Wysoki | Proces realizacji zamówień |
| `db_connect.php` | Połączenie DB, sesja, `require_role` | Wysoki | Krytyczny punkt bezpieczeństwa i multi-tenant |
| `auth_pos.py` | Pomocnicza walidacja logowania POS (Python) | Niski | Najpewniej eksperymentalne/offline |
| `auth_kiosk.py` | Pomocnicza walidacja logowania kiosk | Niski | Najpewniej eksperymentalne/offline |
| `auth_admin.py` | Pomocnicza walidacja logowania admin | Niski | Minimalny stub |
| `app.html` | Główna aplikacja/panel startowy | Średni | Agreguje wejście do modułów |
| `api_session_check.php` | Walidacja sesji | Wysoki | Klucz do bezpieczeństwa sesji |
| `api_recipes.php` | API receptur/składników | Wysoki | Koszty, stany, 86'ing |
| `api_pos.php` | Główny silnik POS (zamówienia, kitchen flow) | Wysoki | Najważniejszy backend operacyjny |
| `api_online.php` | API zamówień online | Wysoki | Kanał internetowy |
| `api_menu_studio.php` | API Studio menu | Średni | Zaplecze konfiguracji menu |
| `api_mapping.php` | API mapowania produktów | Wysoki | Integracje i spójność SKU |
| `api_manager.php` | API kadry zarządzającej (zespół/finanse) | Wysoki | Zarządzanie operacyjne |
| `api_kiosk_emp.php` | API kiosku pracowniczego | Średni | HR/obsługa personelu |
| `api_inventory.php` | API magazynu (PZ, stock matrix, logi) | Wysoki | Rdzeń magazynu i ruchów stanów |
| `api_floor.php` | API sali/stolików | Średni | FOH orchestration |
| `api_ekipa.php` | API zespołu/obsady | Średni | Kadry/operacje |
| `api_driver.php` | API kierowców | Wysoki | Dostawy i statusy kierowców |
| `api_delivery.php` | API procesu dostawy | Wysoki | Integracja realizacji zamówień |
| `api_dashboard.php` | API dashboardu tenant/user | Średni | Telemetria i kontekst użytkownika |
| `api_auth_pos.php` | Auth API dla POS | Wysoki | Krytyczne bezpieczeństwo dostępu |
| `api_auth_kiosk.php` | Auth API dla kiosku | Średni | Kanał kiosk |
| `api_auth_admin.php` | Auth API dla admina | Wysoki | Uprawnienia administracyjne |
| `administration.html` | Panel administracyjny | Wysoki | Sterowanie systemem |
| `admin_login.html` | Logowanie administracyjne | Wysoki | Security gateway |
| `admin_app.html` | Aplikacja panelu admina | Wysoki | Główne centrum zarządzania |
| `stare pliki/api_magazyn_pro.php` | Starsze API magazynu/pro | Wysoki | Cenne historycznie dla logiki magazynu |
| `stare pliki/pos_dosprawdzenia.html` | POS do weryfikacji/testów | Średni | Materiał QA/prototyp |
| `stare pliki/baza_slicehub (1).sql` | Dump bazy | Wysoki | Klucz do rekonstrukcji modelu danych |
| `stare pliki/baza_slicehub.sql` | Dump bazy (główny wariant) | Wysoki | Najważniejsze źródło schematu |
| `stare pliki/waiter.html` | Legacy UI kelnera | Średni | Porównanie regresji UX |
| `stare pliki/settings_mapping.html` | Legacy mapping settings | Wysoki | Historia mapowania SKU |
| `stare pliki/settings_magazyn.html` | Legacy ustawień magazynu | Wysoki | Przydatne dla różnic logiki stanów |
| `stare pliki/reset.php` | Reset/utility serwera | Średni | Ryzyko operacyjne (ostrożnie) |
| `stare pliki/pos_mobile.html` | Mobile POS | Średni | Kanał mobilny |
| `stare pliki/pos_fleet.js` | Legacy logika floty | Wysoki | Wgląd w dostawy |
| `stare pliki/pos_active_routes.js` | Legacy trasy aktywne | Wysoki | Routing operacyjny |
| `stare pliki/pos.html` | Legacy POS | Wysoki | Architektura historyczna POS |
| `stare pliki/online_store.html` | Legacy sklep online | Wysoki | Ewolucja order flow |
| `stare pliki/menu_builder.html` | Legacy menu builder | Średni | Wsparcie migracji |
| `stare pliki/manifest_boss.json` | Legacy PWA boss | Niski | Konfig pomocnicza |
| `stare pliki/manifest_admin.json` | Legacy PWA admin | Niski | Konfig pomocnicza |
| `stare pliki/manifest.json` | Legacy manifest | Niski | Konfig pomocnicza |
| `stare pliki/manager_recipes.html` | Legacy panel receptur | Wysoki | Receptury/koszty |
| `stare pliki/manager_pz.html` | Legacy panel PZ | Wysoki | Magazyn/przyjęcia |
| `stare pliki/manager_floor.html` | Legacy floor manager | Średni | Sala/obsługa |
| `stare pliki/login.html` | Legacy login | Średni | Punkt wejścia |
| `stare pliki/kiosk_attendance.html` | Legacy kiosk attendance | Średni | Kadry |
| `stare pliki/kiosk.html` | Legacy kiosk | Średni | Kanał sprzedaży |
| `stare pliki/driver.html` | Legacy driver UI | Wysoki | Dostawy |
| `stare pliki/delivery.html` | Legacy delivery UI | Wysoki | Realizacja zamówień |
| `stare pliki/db_connect.php` | Legacy DB/session/auth helper | Wysoki | Ryzyko sekretów i driftu |
| `stare pliki/dashboard.html` | Legacy dashboard | Średni | Monitoring |
| `stare pliki/app.html` | Legacy app shell | Średni | Nawigacja modułów |
| `stare pliki/api_recipes.php` | Legacy API receptur | Wysoki | Porównanie zmian biznesowych |
| `stare pliki/api_pos.php` | Legacy API POS | Wysoki | Krytyczna historia logiki zamówień |
| `stare pliki/api_online.php` | Legacy API online | Wysoki | E-commerce flow |
| `stare pliki/api_menu_studio.php` | Legacy API studio | Średni | Konfiguracja menu |
| `stare pliki/api_mapping.php` | Legacy API mapping | Wysoki | Integracje produktów |
| `stare pliki/api_manager.php` | Legacy API managera | Wysoki | Zarządzanie personelem/finansami |
| `stare pliki/api_kiosk_emp.php` | Legacy API kiosku prac. | Średni | HR |
| `stare pliki/api_inventory.php` | Legacy API magazynu | Wysoki | Kluczowy dla stanów/PZ |
| `stare pliki/api_floor.php` | Legacy API floor | Średni | Operacje sali |
| `stare pliki/api_ekipa (2).php` | Legacy API zespołu (wariant) | Średni | Wersjonowanie konfliktowe |
| `stare pliki/api_driver.php` | Legacy API kierowców | Wysoki | Dostawy |
| `stare pliki/api_delivery.php` | Legacy API delivery | Wysoki | Realizacja |
| `stare pliki/api_dashboard.php` | Legacy API dashboard | Średni | Telemetria |
| `stare pliki/api_auth.php` | Legacy autoryzacja | Wysoki | Bezpieczeństwo |
| `stare pliki/administration.html` | Legacy admin panel | Wysoki | Zarządzanie |
| `stare pliki/admin_login.html` | Legacy admin login | Wysoki | Security |
| `stare pliki/admin_app.html` | Legacy admin app | Wysoki | Centrum operacyjne |
| `stare pliki/htaccess` | Reguły serwera (PWA/cache/sw) | Średni | Wpływ na runtime i cache |
| `stare pliki/api_ekipa (1).php` | Legacy API zespołu (wariant 1) | Średni | Duplikat wersji |
| `stare pliki/api_ekipa.php` | Legacy API zespołu (bazowe) | Średni | Duplikat wersji |
| `stare pliki/pozostale/waiter.html` | UI kelnerski (kopia) | Średni | Duplikat względem innych snapshotów |
| `stare pliki/pozostale/settings_mapping.html` | Mapping settings (kopia) | Wysoki | Ważne dla spójności SKU |
| `stare pliki/pozostale/settings_magazyn.html` | Magazyn settings (kopia) | Wysoki | 86'ing/stany |
| `stare pliki/pozostale/pos_mobile.html` | Mobile POS (kopia) | Średni | Kanał mobilny |
| `stare pliki/pozostale/pos_fleet.js` | Fleet logic (kopia) | Wysoki | Dostawy |
| `stare pliki/pozostale/pos_active_routes.js` | Trasy aktywne (kopia) | Wysoki | Routing |
| `stare pliki/pozostale/pos.html` | POS (kopia) | Wysoki | Architektura POS |
| `stare pliki/pozostale/online_store.html` | Online store (kopia) | Wysoki | Zamówienia online |
| `stare pliki/pozostale/menu_builder.html` | Menu builder (kopia) | Średni | Konfiguracja |
| `stare pliki/pozostale/manifest_boss.json` | PWA boss (kopia) | Niski | Pomocnicze |
| `stare pliki/pozostale/manifest_admin.json` | PWA admin (kopia) | Niski | Pomocnicze |
| `stare pliki/pozostale/manifest.json` | PWA manifest (kopia) | Niski | Pomocnicze |
| `stare pliki/pozostale/manager_recipes.html` | Panel receptur (kopia) | Wysoki | Koszty/stany |
| `stare pliki/pozostale/manager_pz.html` | Panel PZ (kopia) | Wysoki | Przyjęcia i stany |
| `stare pliki/pozostale/manager_floor.html` | Floor manager (kopia) | Średni | Sala |
| `stare pliki/pozostale/login.html` | Login (kopia) | Średni | Auth entrypoint |
| `stare pliki/pozostale/kiosk_attendance.html` | Attendance (kopia) | Średni | HR |
| `stare pliki/pozostale/kiosk.html` | Kiosk (kopia) | Średni | Kanał sprzedaży |
| `stare pliki/pozostale/driver.html` | Driver UI (kopia) | Wysoki | Dostawy |
| `stare pliki/pozostale/delivery.html` | Delivery UI (kopia) | Wysoki | Realizacja |
| `stare pliki/pozostale/db_connect.php` | DB connect (kopia) | Wysoki | Security/sekrety |
| `stare pliki/pozostale/dashboard.html` | Dashboard (kopia) | Średni | Monitoring |
| `stare pliki/pozostale/app.html` | App shell (kopia) | Średni | Nawigacja |
| `stare pliki/pozostale/api_recipes.php` | API receptur (kopia) | Wysoki | Receptury |
| `stare pliki/pozostale/api_pos.php` | API POS (kopia) | Wysoki | Zamówienia |
| `stare pliki/pozostale/api_online.php` | API online (kopia) | Wysoki | E-commerce |
| `stare pliki/pozostale/api_menu_studio.php` | API studio (kopia) | Średni | Konfiguracja |
| `stare pliki/pozostale/api_mapping.php` | API mapping (kopia) | Wysoki | Integracje |
| `stare pliki/pozostale/api_manager.php` | API managera (kopia) | Wysoki | Zarządzanie |
| `stare pliki/pozostale/api_kiosk_emp.php` | API kiosk emp (kopia) | Średni | HR |
| `stare pliki/pozostale/api_inventory.php` | API magazynu (kopia) | Wysoki | Stany/PZ |
| `stare pliki/pozostale/api_floor.php` | API floor (kopia) | Średni | Sala |
| `stare pliki/pozostale/api_ekipa (2).php` | API zespołu (wariant) | Średni | Duplikat wersji |
| `stare pliki/pozostale/api_driver.php` | API kierowcy (kopia) | Wysoki | Dostawy |
| `stare pliki/pozostale/api_delivery.php` | API delivery (kopia) | Wysoki | Realizacja |
| `stare pliki/pozostale/api_dashboard.php` | API dashboard (kopia) | Średni | Telemetria |
| `stare pliki/pozostale/api_auth (1).php` | API auth wariant | Wysoki | Bezpieczeństwo |
| `stare pliki/pozostale/administration (2).html` | Admin panel wariant | Wysoki | Zarządzanie |
| `stare pliki/pozostale/admin_login.html` | Admin login (kopia) | Wysoki | Security |
| `stare pliki/pozostale/admin_app.html` | Admin app (kopia) | Wysoki | Operacje |
| `stare pliki/pozostale/administration (1).html` | Admin panel wariant | Wysoki | Wersja alternatywna |
| `stare pliki/pozostale/administration.html` | Admin panel (kopia) | Wysoki | Zarządzanie |
| `stare pliki/pozostale/api_auth.php` | API auth (kopia) | Wysoki | Bezpieczeństwo |
| `stare pliki/pozostale/api_pos11.php` | Wariant API POS | Wysoki | Potencjalnie ważna gałąź logiki |
| `stare pliki/pozostale/api_pz.php` | API PZ (przyjęcia) | Wysoki | Bezpośrednio magazyn |
| `stare pliki/pozostale/db_connect najstarszy chyba.php` | Najstarszy DB connector | Średni | Wysokie ryzyko nieaktualnych sekretów |

## Priorytet strategiczny (TOP 10)

1. `api_pos.php` - centralny silnik POS (zamówienia i statusy).
2. `api_inventory.php` - rdzeń magazynu (PZ, stany, logi ruchów).
3. `db_connect.php` - fundament sesji, ról i bezpieczeństwa.
4. `api_online.php` - kanał zamówień internetowych.
5. `api_delivery.php` - realizacja dostaw i post-order flow.
6. `api_manager.php` - logika zarządzania personelem i operacjami.
7. `settings_magazyn.html` - kontrola matrycy stanów i 86'ing.
8. `manager_pz.html` - operacyjna obsługa przyjęć magazynowych.
9. `order_handler_view (1).html` - pipeline/KDS zamówień.
10. `loyalty_view (1).html` - grywalizacja i lojalność klienta.
