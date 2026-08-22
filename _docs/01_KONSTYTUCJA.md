# KONSTYTUCJA SYSTEMU: SLICEHUB ENTERPRISE

**Wersja:** v5 · **Ostatnia rewizja:** 2026-05-11 · **Poprzednia:** v4 (2026-04-19)

> Ten dokument określa **nienaruszalne prawa architektury** systemu SliceHub. Każdy programista oraz Agent AI pracujący przy tym kodzie ma bezwzględny obowiązek stosowania się do poniższych zasad. Złamanie tych reguł grozi krytycznym uszkodzeniem bazy danych, regresją integralności biznesowej oraz utratą zaufania użytkownika.
>
> **Zmiany w v5 (2026-05-11)** są opisane w sekcji „Historia rewizji" na końcu pliku. Krótko: dotychczasowe Prawa I–VII pozostają bez zmian merytorycznych (poza naprawą literówki w II), dorzucone zostały **Prawa VIII / IX / X** + **formalne zawężenie zakresu Prawa VII** + **złagodzenie zakazu Node.js do warstwy runtime**.

---

## 1. PRAWO MACIERZY CENOWEJ (Omnichannel)
W systemie **nie istnieje pojęcie „płaskiej ceny"** (jednej ceny dla dania/modyfikatora).
- Wyjaśnienie definicji: **POS (Battlefield)** to główny panel operacyjny i terminal w restauracji. Nie jest on „kanałem cenowym" samym w sobie.
- Ceny zawsze funkcjonują w wielowymiarowej macierzy zależnej od **Kanału Sprzedaży**: np. **POS/Sala (Dine-in)**, **Wynos (Takeaway)**, **Dostawa (Delivery)**.
- Ceny nigdy nie są zapisywane jako płaska kolumna `price` w głównej tabeli dania. Zawsze używamy relacyjnej tabeli `sh_price_tiers` (lub struktury JSON w payloadzie) z flagami przypisanymi do konkretnych kanałów.
- Edycja masowa (Bulk) na jednym kanale nie może nadpisywać cen na pozostałych kanałach bez wyraźnego polecenia.

## 2. PRAWO BLIŹNIAKA CYFROWEGO (Zarządzanie Magazynem)
System kasy/menu to tylko front. Prawdziwa gastronomia dzieje się w magazynie (Food Cost).
- Opcje i modyfikatory to nie jest tylko „tekst na paragonie".
- Każdy modyfikator mający wpływ na surowce MUSI zachować logikę powiązania z bazą magazynową przez **`linked_warehouse_sku`** (poprawione w v5 — wcześniej nieprecyzyjnie napisano „(KSeF)").
- Akcje takie jak `ADD` lub `REMOVE` muszą zawsze wskazywać na kod surowca (`linked_warehouse_sku`) oraz jego dokładne zużycie ułamkowe (`linked_quantity`, np. 0.05).
- **Half & Half:** każda połowa konsumuje surowce × **0.5** multiplier.
- **Formuła zużycia:** `needed = recipe_qty × (1 + waste%/100) × multiplier` — zawsze z marnotrawstwem.
- Usuwanie składnika („BEZ …") = darmowe dla klienta, ale omija dedukcję z magazynu (matching przez `warehouse_sku`, nigdy po nazwie).
- Nigdy nie usuwaj mechanizmów odczytu i zapisu tych wartości w interfejsie.

## 3. PRAWO CZWARTEGO WYMIARU (Temporal Tables)
Widoczność dań i kategorii jest kontrolowana przez zmienne czasowe.
- Używamy statusów publikacji: `Draft` (Szkic), `Live` (Opublikowane), `Archived` (Zarchiwizowane).
- Publikacja może być zaplanowana w czasie za pomocą zmiennych `valid_from` oraz `valid_to`.
- Zamiast usuwać rekordy z bazy (Hard Delete), zawsze preferujemy zmianę statusu na `Archived` (Soft Delete) lub flagę `is_deleted = 1`.

## 4. PRAWO ZERA ZAUFANIA (Walidacja i API)
Silnik API i Baza Danych to nasza twierdza.
- Żadne żądanie z Frontendu (JS) nie jest traktowane jako w 100% bezpieczne.
- Frontend **nigdy nie wysyła cen ani totali** — tylko SKU i ilości. Serwer ZAWSZE przelicza koszyk przez `CartEngine::calculate()` zanim zaakceptuje zamówienie.
- Wszystkie query parametryzowane (PDO prepared statements) — zero interpolacji.
- Interfejs masowy wysyłający dane do API musi restrykcyjnie trzymać się struktury Payloadu (np. `omnichannelPricePatch`).

## 5. PROTOKÓŁ „KOPALNIA WIEDZY" (KOD LEGACY)
W systemie znajdują się foldery ze starym kodem (np. poprzednie wersje POS, Magazynu, Grywalizacji).
- **ZASADA BEZWZGLĘDNA:** Pliki te służą WYŁĄCZNIE jako materiał referencyjny i źródło logiki biznesowej.
- Nigdy nie kopiuj starego kodu 1:1 do nowego systemu. Należy wyciągnąć z niego zasady, a sam kod napisać od nowa zgodnie z obecną architekturą i najnowszymi standardami API.
- `_KOPALNIA_WIEDZY_LEGACY/` i `_archive/` są fizycznie przeniesione **poza repo** (`.gitignore`) — backup na dysku właściciela. Inwentarz historyczny: [`_docs/03_MAPA_KOPALNI.md`](03_MAPA_KOPALNI.md).
- Żelazna zasada: nigdy nie linkuj do legacy z nowego UI.

## 6. ZASADY ZMIANY KODU DLA AI (Snajper)
- AI ma zakaz przeprowadzania „globalnych optymalizacji" i usuwania nieznanych sobie funkcji z plików (tzw. halucynacje).
- Jeśli zmieniasz funkcję `A`, nie masz prawa dotykać funkcji `B` w tym samym pliku bez pytania.
- Przed każdą zmianą w UI sprawdź, jak dane mapują się na Backend API.
- **Każde zapytanie SQL MUSI zawierać `tenant_id = :tid`.** Brak bariery = błąd krytyczny (Multi-Tenancy).
- **Każde JOIN cross-silo** (`sh_` ↔ `sys_` ↔ `wh_`) wyłącznie po **kluczach znakowych** (`sku`, `ascii_key`, `warehouse_sku`), nigdy po numerycznym `id`.

## 7. PRAWO INNOWACJI ALBO NIC (Online Studio + Storefront)

> **OBOWIĄZUJE OD 2026-04-19. ZAKRES ZAWĘŻONY w v5 (2026-05-11).**
>
> **Twardo dotyczy WYŁĄCZNIE następujących obszarów:**
> - `modules/online/` (Storefront — strona klienta)
> - `modules/online_studio/` (Director's Suite + Asset Studio)
> - `api/online/engine.php`
> - `api/online_studio/engine.php`
> - `api/assets/engine.php`
> - `core/SceneResolver.php`, `core/SceneRenderer.php`, `core/js/scene_renderer.js`
>
> **NIE dotyczy** modułów: Magazyn, POS, Hub, Kadry, Kiosk, Settings, Tables, KDS, Driver, Courses, Backoffice/Profile, Procurement (KSeF Inbox), Dispatcher. Tam liczy się żelazna funkcjonalność, UX i niezawodność. „Slider" w Settings jest dozwolony jeśli to słuszna kontrolka. „Slider" w Online Studio do regulacji harmony jest **paint** — zatrzymujemy się słowem `paint`.

- **SliceHub nie jest kolejnym systemem POS / online do gastronomii.** Buduje rozwiązania, których jeszcze nie ma na rynku (Domino's, NUV POS, EZ Pizza, Papa John's, WooFood, Apprication, Glovo/Uber Eats web ordering).
- **Każda funkcja w obrębie zawężonego zakresu MUSI być o krok przed najlepszym konkurentem.** Jeśli opisuje się ją jednym słowem — „filtr", „slider", „picker", „color wheel", „thumbnail grid" — **nie piszemy tego kodu**. To paint, nie innowacja.
- **Każdy „bulk op" zmienia rzeczywistą zawartość:** ambient + companions + typografia + ruch + LUT + kompozycja — nie pojedynczy parametr.
- **Harmony Score / metryki jakości = numeryczne + actionable.** Nie ozdoba UI. Manager widzi liczbę i wie co nacisnąć.
- **Living Scene reaguje na świat** (pora dnia, pogoda, obciążenie kuchni, triggery z `sh_scene_triggers`), nie jest pętlą CSS animation.
- **Klient widzi OKNO do restauracji, nie product grid.** Storefront to teatr fotograficzny, nie katalog SKU.
- **Magic Enhance („THE button") to norma startu**, nie funkcja dodatkowa. Edytor zaczyna się od auto-compose, nie od pustego płótna.
- **Kontrakt z użytkownikiem:** jeśli zaczniesz się osuwać w „jeszcze jeden slider" — user zatrzymuje słowem `paint` → wracasz do tego filtra.

**Plan operacyjny (G1-G7) dla tego prawa:** [`_docs/15_KIERUNEK_ONLINE.md`](15_KIERUNEK_ONLINE.md) § 2.4.

## 8. PRAWO DOMKNIĘCIA KONTRAKTU (Code ↔ Docs Drift Guard) · NEW v5

> **OBOWIĄZUJE OD 2026-05-11.** Reaguje na sytuacje, gdy dokumentacja opisuje funkcjonalność jako „działającą", a kod albo nie istnieje, albo nie ma żadnego call-site.

- **Jeśli docs (`_docs/*.md`) opisują funkcję jako produkcyjną** (np. „WzEngine = Zużycie surowców po acceptance"), MUSI istnieć **przynajmniej jeden call-site** w `api/` lub `core/` który ją uruchamia w produkcyjnym przepływie + **przynajmniej jeden test** (manualny w `_docs/manual_tests.md` albo automatyczny w `tests/`).
- **Funkcja kompletna ale niewpięta** = MUSI mieć w docblocku adnotację `@planned (Prawo VIII)` z konkretnym powodem („zaplanowana do sesji F1") i datą planowanego włączenia. AI/programista bez tego nie ma prawa pushnąć.
- **Dokumentacja kłamiąca = błąd równy SQL injection.** Każda sesja AI zaczynająca od „przeczytaj docs" wpada w pułapkę gdy docs opisują nieistniejący kontrakt. Drift kod-docs jest jednym z najbardziej kosztownych długów technicznych w SaaS.
- **Detekcja drift-u:** przy każdym audycie modułu (sesja typu „przejrzyj X") AI ma OBOWIĄZEK zgłosić w raporcie końcowym listę funkcji `@planned` lub funkcji opisanych w docs ale bez call-sitów. Nie wolno tego ukryć.
- **Zwalnianie z `@planned`:** dopiero w tej samej sesji, w której funkcja zostaje wpięta + przetestowana E2E + udokumentowana w commit message.

**Aktualna lista znanych `@planned` funkcji** (do zlikwidowania w kolejnych sesjach):
- ~~`core/WzEngine.php::consumeForOrder`~~ — **DOMKNIĘTE w sesji F1 · 2026-05-11.** Wpięte w `core/WarehouseConsumeHook` → `api/pos/engine.php#accept_order` + `api/orders/accept.php`. Test E2E w `_docs/sessions/2026-05-11_phase_f1_consume_loop.md`.
- ~~`api/staff/payroll.php`~~ — **USUNIĘTY 2026-07-28.** Martwy wrapper GET bez konsumenta. Logika w `hr/engine.php#payroll_report` → `PayrollEngine::calculate()`. Katalog `api/staff/` usunięty.
- ~~`api/dashboard/team_payroll.php`~~ — **USUNIĘTY 2026-07-28.** Martwy wrapper GET bez konsumenta. Logika w `hr/engine.php#payroll_report` → `TeamPayrollEngine::getAggregate()`. Katalog `api/dashboard/` usunięty.
- ~~`api/payments/settle.php`~~ — **USUNIĘTY 2026-07-28.** Martwy wrapper HTTP, zero call-site'ów. Logika w `core/SettlementEngine.php`.
- ~~`api/orders/panic.php`~~ — **USUNIĘTY 2026-07-28.** Duplikat `pos/engine.php#panic_mode`. Logika wchłonięta do `core/PanicEngine.php` (debounce + configurable delay).
- ~~`api/orders/accept.php`~~ — **USUNIĘTY 2026-07-28.** Duplikat `pos/engine.php#accept_order`. `canTransition()` pre-check wchłonięty.
- ~~`api/orders/checkout.php`~~ — **USUNIĘTY 2026-07-28.** Duplikat `online/engine.php#guest_checkout` + `pos/engine.php#process_order`. `WzEngine::checkAvailability()` wchłonięte do obu.
- ~~`api/delivery/dispatch.php`~~ — **USUNIĘTY 2026-07-28.** Duplikat `courses/engine.php#dispatch`. `out_for_delivery_at` + event publishing wchłonięte. Katalog `api/delivery/` usunięty.
- ~~`api/delivery/reconcile.php`~~ — **USUNIĘTY 2026-07-28.** Duplikat `courses/engine.php#reconcile`. Driver release + delivery stats wchłonięte.
- ~~`api/kds/update_ticket.php`~~ — **USUNIĘTY 2026-07-28.** Per-ticket state machine wchłonięta do `core/KdsTicketEngine.php` + `kds/engine.php#bump_ticket`.
- ~~`api/orders/edit.php`~~ — ✅ **DOMKNIĘTE 2026-07-30 (Faza E).** Edycja zamówienia + `DeltaEngine` (diff linii → `kitchen_delta` JSON dla KDS). Wpięte end-to-end: `modules/backoffice/order_edit/` (nowy moduł: `index.html` + `js/order_edit_app.js` + `css/order_edit.css`) → `GET api/orders/get.php` (read-only order+lines) → `POST api/orders/edit.php` (CartEngine + DeltaEngine). KDS consumer: `api/kds/engine.php#get_board` zwraca `kitchen_delta` + `edited_since_print`; `modules/kds/js/kds_app.js` highlightuje linie (zielony=dodane, żółty=zmienione, czerwony=usunięte) + banner "ZAMÓWIENIE EDYTOWANE". Test E2E w `_docs/sessions/2026-07-30_phase_e_order_edit_kds_delta.md`.
- `api/orders/estimate.php` — ✅ **DOMKNIĘTE.** Wrapper HTTP na `PromisedTimeEngine::calculate()` (tryb `slots`). Aktywnie używany przez `modules/pos/js/pos_api.js:99` (`estimateSlots` → `GET api/orders/estimate.php?mode=slots&channel=…`). Silnik wpięty również bezpośrednio w 4 ścieżki ASAP (online/gateway/choiceqr/pos).
- `core/PromisedTimeEngine.php` — ✅ **DOMKNIĘTE 2026-07-29 (Faza B)** ⚠️ **AUDYT 2026-08-03: częściowe domknięcie.** Kompletny silnik estymacji `promised_time` (load factor, channel buffers, business hours). Wpięty w 4 produkcyjne ścieżki ASAP: `online/engine.php#guest_checkout`, `gateway/intake.php`, `integrations/choiceqr/webhook.php`, `pos/engine.php#accept_order` (default gdy kasjer nie poda `custom_time`). Ręczny input kasjera (POS) i scheduled orders (online surowy, gateway walidowany) pozostają bez zmian. **Luki znalezione w audycie 2026-08-03** (szczegóły: `_docs/sessions/2026-08-03_promised_time_wiring_audit.md`): (L1) POS "ZAAKCEPTUJ" wysyła `now` jako `custom_time` → silnik ASAP nie odpala w tej ścieżce; (L2) online checkout scheduled zapisuje surowy `requested_time` bez walidacji silnika; (L3) tryb `scheduled` silnika = martwy kod (0 produkcyjnych call-site'ów, jedyny konsument to orphan `estimate.php`).
- `api/orders/sla_monitor.php` — ✅ **DOMKNIĘTE 2026-07-29 (Faza C).** SLA breach monitoring API (klasyfikacja 4-tier, zapis do `sh_sla_breaches`). Wpięte end-to-end: `scripts/worker_sla_monitor.php` (cron CLI, mirror logiki, iteruje tenanty) + `courses/engine.php#get_sla_breaches` (backend akcja) + `modules/courses/js/courses_api.js#getSlaBreaches` (frontend) + `courses_app.js` (polling co 30s) + `courses_ui.js#renderSlaBreachesPanel` (panel w sidebar Dispatcher). Cron instrukcja: `_docs/19_LOGISTYKA_I_BEZPIECZENSTWO.md §6.7`.
- ~~`api/reports/food_cost.php`~~ — ✅ **DOMKNIĘTE 2026-07-29 (Faza D).** Food cost + margin breakdown per item (wrapper `FoodCostEngine::calculateForSku` — AVCO, składniki, modyfikatory, marża per kanał). Wpięte end-to-end: `modules/backoffice/food_cost/` (nowy moduł: `index.html` + `js/food_cost_app.js` + `css/food_cost.css`) → `GET api/reports/food_cost.php?item_sku=&warehouse_id=`. Pickera zasilają istniejące endpointy (Snajper — bez nowych backendów): `api/warehouse/warehouse_list.php` (magazyny) + `api/backoffice/api_menu_studio.php#get_menu_tree` (menu). Test E2E w `_docs/sessions/2026-07-30_phase_d_food_cost_report.md`.

## 9. PRAWO DATOWANYCH ZAMROŻEŃ (Freeze Discipline) · NEW v5

> **OBOWIĄZUJE OD 2026-05-11.** Reaguje na ryzyko że freeze staje się dziedziczonym długiem technicznym bez możliwości odmrożenia.

Każda sekcja `FREEZE NOTICE` w jakimkolwiek dokumencie systemu MUSI zawierać:

1. **`FROZEN_AT`** — data wprowadzenia (YYYY-MM-DD).
2. **`UNTIL`** — albo konkretna data automatycznego review (max +6 miesięcy), albo trigger biznesowy (np. „P4 ≥ 60 dni produkcji bez P0/P1 incidentu", „>1000 użytkowników na produkcji", „decyzja właściciela w chacie").
3. **`REASON`** — konkretny powód, nie generyczne „work in progress". Co dokładnie chronimy?
4. **`UNFREEZE_BY`** — kto może odmrozić (imię/rola). Domyślnie: właściciel produktu jawną decyzją.
5. **`SCOPE`** — pełna lista plików / migracji / tabel pod ochroną.

**Po dacie `UNTIL`** (jeśli jest data, nie trigger) freeze automatycznie wraca do statusu „do przeglądu" — AI może w kolejnej sesji **zaproponować** odmrożenie z konkretnym planem migracji. Świadome przedłużenie freeze wymaga jawnej decyzji właściciela + aktualizacji `UNTIL`.

**Cel:** żaden zamrożony plik nie zostaje w stanie freeze dłużej niż jest to merytorycznie uzasadnione. Lista freeze nie rośnie w nieskończoność.

## 10. PRAWO AUDYTU SESJI AI · NEW v5

> **OBOWIĄZUJE OD 2026-05-11.** Standaryzuje przekazanie kontekstu między sesjami AI / programistą / właścicielem.

- Każda sesja AI kończąca się **commitem zmieniającym `core/`, `api/`, `database/migrations/` lub `_docs/01_KONSTYTUCJA.md`** MUSI:
  - W commit message zawrzeć sekcję `Test (E2E):` opisującą jak weryfikowano zmianę. Bez tego — Prawo VIII.
  - W `_docs/sessions/YYYY-MM-DD_<topic>.md` (NEW folder) pozostawić plik z 4 sekcjami: **Cel**, **Pliki dotknięte**, **Decyzje architektoniczne**, **Otwarte pytania** (co kolejna sesja musi rozstrzygnąć).
- **Cel:** kolejny AI / programista w 30 sekund rozumie *co*, *dlaczego* i *co dalej*. Zero archeologii w git history.
- **Drobne sesje** (literówka, fix typo, lint config) — z tego obowiązku **zwolnione**. Zasada: jeśli zmiana zmienia zachowanie systemu, wymaga audytu.

---

## STOS TECHNOLOGICZNY (Manifest „Zero-Reload Runtime")

**Złagodzone w v5 (2026-05-11).** Wcześniej formułowane jako absolutny zakaz Node.js / npm — w praktyce niemożliwy do egzekwowania bez wykluczania współczesnych dev-toolingów.

### Frontend (runtime na hostingu)
- Vanilla JS (ES6+), czysty HTML5, Tailwind CSS (CDN albo build artifact) lub czysty CSS.
- **Runtime na produkcji:** żadnego Node.js, żadnego React/Vue/Angular/Svelte/jQuery jako biblioteki.
- **To co ląduje w `public_html/`** — tylko czyste pliki `.html` / `.css` / `.js` bez zależności runtime.

### Dev tooling (lokalnie, opcjonalnie)
- **DOZWOLONE od v5:** Vite, esbuild, prettier, eslint, TypeScript (jeśli kompilowany do vanilla JS), preprocessory CSS (PostCSS, Sass).
- **Warunek:** narzędzia działają wyłącznie w workflow developerskim. Output (pliki dystrybucyjne) deployujemy do `public_html/` jako zwykłe pliki — bez `node_modules/`, bez `package.json` na hostingu, bez build-step po stronie serwera.
- **Cel:** bardziej produktywny dev (autocomplete TypeScript, hot reload, lint), ale bez kompromisu w runtime.

### Backend
- PHP 8+ (PDO, REST API JSON).

### Baza
- MariaDB 10.4+ / MySQL 8.0+, utf8mb4_unicode_ci, baza `slicehub_pro_v2`.

### Bezwzględny ZAKAZ na produkcji
- Node.js w runtime (jako serwer / cron / worker).
- npm / yarn / pnpm jako runtime dependency manager.
- Bundlery jako runtime (Webpack, Rollup, Parcel itd. działające na żądanie).
- React, Vue, Angular, Svelte jako framework runtime.
- jQuery jako biblioteka.

> **Reguła kompromisu (od v5):** jeśli używasz dev-toolingu, output MUSI być commit-owany do repo. Hosting nigdy nie odpala build-step. Każdy programista po `git clone` ma działający kod bez `npm install`.

---

## HISTORIA REWIZJI

### v5 — 2026-05-11

**Zmiany:**
- ✅ **Prawo II — naprawa literówki:** „(KSeF)" → „przez `linked_warehouse_sku`" (KSeF to e-faktury, nie magazyn — błąd terminologiczny).
- ✅ **Prawo II — uzupełnienie:** dodane Half & Half × 0.5, formuła zużycia, regulacja „BEZ X" (przepisane z `_docs/00_PAMIEC_SYSTEMU.md` § 1 Prawo II — synchronizacja).
- ✅ **Prawo VI — uzupełnienie:** wyraźnie wpisana bariera `tenant_id = :tid` + zakaz JOIN cross-silo po numerycznym `id` (przepisane z `.cursorrules` § 9 — synchronizacja).
- ✅ **Prawo VII — formalne zawężenie:** twarda lista plików/modułów objętych. Magazyn / POS / Hub / Kadry / Settings / Procurement / itd. JAWNIE wyłączone. Wcześniej granica była tylko w nagłówku Prawa, łatwa do nadinterpretacji.
- ➕ **Prawo VIII — Domknięcie Kontraktu (NEW):** dyscyplina kontraktu kod ↔ docs. `@planned` jako obowiązkowa adnotacja dla funkcji bez call-site.
- ➕ **Prawo IX — Datowane Zamrożenia (NEW):** każdy `FREEZE NOTICE` musi mieć `UNTIL` (datę lub trigger).
- ➕ **Prawo X — Audyt Sesji AI (NEW):** standaryzacja `_docs/sessions/YYYY-MM-DD_<topic>.md` po każdej znaczącej sesji.
- ✅ **Stos technologiczny — złagodzenie:** zakaz Node.js doprecyzowany do **runtime**. Dev-tooling (Vite, esbuild, TypeScript→vanilla, prettier, eslint) dozwolony na zasadzie „output do repo, build-step nigdy na hostingu".

**Co NIE zostało zmienione:**
- Prawa I, III, IV, V — bez zmian merytorycznych.
- Prawo VI — uzupełnienie istniejących reguł, nic nie usunięto.
- Prawo VII — zakres zawężony, ale wszystkie wewnętrzne reguły bez zmian.

### v4 — 2026-04-19
- Wprowadzenie Prawa VII (Innowacja albo Nic) dla Online Studio + Storefront.

### v3 — wcześniejsze
- Prawa I-VI w wersji bazowej (rdzeń systemu od początku projektu).

---

> **Dla AI:** czytasz Konstytucję ZANIM tknąć kod. Jeśli zmiana zaczyna się rozjeżdżać z którymkolwiek z 10 praw — STOP. Pytaj. Nie improwizuj.
