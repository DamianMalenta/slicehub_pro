# Sesja 2026-08-20 — UI edycji zamówień (admin_hub) + kitchen_delta na KDS

## Cel
Podpiąć gotowy backend edycji zamówień (`api/orders/edit.php` + `DeltaEngine` +
`sh_orders.kitchen_delta`, dotąd `@planned`) do frontendu: modal "Edytuj
zamówienie" w `modules/hub/` oraz widoczne różnice od ostatniego wydruku na
bilecie KDS.

## Pliki dotknięte
- **`api/orders/get_for_edit.php`** *(nowy)* — dane dla modala: `list_orders`
  (aktywne zamówienia tenanta), `get_order` (nagłówek + linie z `id` +
  katalog dań `sh_menu_items` i modyfikatorów `sh_modifiers`). Wszystkie
  zapytania tenant-scoped (auth_guard → `$tenant_id`).
- **`api/orders/edit.php`** — tylko nagłówek STATUS: PLANNED → WIRED
  (logika bez zmian, zgodnie z wytyczną "nie zmieniaj bez potrzeby").
- **`api/kds/engine.php`** — `get_board` zwraca dodatkowo `o.kitchen_delta`
  i `o.edited_since_print` (te same tenant-scoped SELECT-y).
- **`modules/hub/index.html`** — karta "Edycja zamówień" + markup modala.
- **`modules/hub/js/hub_order_edit.js`** *(nowy)* — logika modala: lista
  zamówień → edycja linii (ilość ±, usunięcie, dodanie dania, modyfikatory
  jako chipy, komentarz) → `POST api/orders/edit.php` przez
  `SliceHub.apiUrl()`; czytelne komunikaty błędów (`hoe-error`).
- **`modules/hub/css/hub.css`** — style modala w konwencji Dark Glass
  (rgba surface + backdrop-filter blur).
- **`modules/kds/js/kds_app.js`** — blok "ZMIANY OD OSTATNIEGO WYDRUKU" na
  bilecie (dodane/usunięte/zmienione linie z `kitchen_delta`), renderowany
  gdy `edited_since_print = 1`.
- **`modules/kds/css/style.css`** — style bloku delty (bursztynowa ramka).
- **Docs:** `00_PAMIEC_SYSTEMU.md`, `START_TUTAJ.md`, `02_ARCHITEKTURA.md` —
  `api/orders/edit.php` przeniesiony z `@planned` do domkniętych.

## Decyzje architektoniczne
1. **Nowy endpoint `get_for_edit.php` zamiast rozszerzania `edit.php`** —
   `edit.php` pozostał nietknięty logicznie (wytyczna). `pos/engine.php#get_orders`
   nie zwraca `sh_order_lines.id`, które jest wymagane przez kontrakt
   `CartEngine`/`DeltaEngine` (`line_id`), stąd dedykowany, wąski endpoint read-only.
2. **Payload linii = kontrakt CartEngine:** istniejące linie wysyłają `line_id`,
   nowe bez; `added_modifier_skus` odtwarzane z `modifiers_json` (pole `sku`),
   `removed_ingredient_skus` z `removed_ingredients_json`. Linie half-half
   (`item_sku` = `A+B`) mapowane na `is_half` + `half_a_sku`/`half_b_sku`.
3. **KDS bez zmian w `core/KdsTicketEngine.php`** — delta jest atrybutem
   nagłówka zamówienia; wystarczyło dodać 2 kolumny do SELECT w `get_board`.
   Silnik biletów (state machine) nie uczestniczy w prezentacji delty.
4. **Czyszczenie delty** pozostaje po stronie istniejącego flow wydruku
   (`edited_since_print`) — nie dodano nowych akcji mutujących.

## Test (E2E)
- Lint: `find . -name "*.php" | xargs -P4 -I{} php -l {}` — 0 błędów.
- Golden path: MariaDB + `001_init` + `apply_migrations_chain.php`
  (znane wyjątki 015/030/037) + `seed_demo_all.php`.
- Headless runner: `node scripts/run_test_runner_headless.cjs` — oczekiwane
  62 testy bez fail.
- Ręczny flow: login system (manager, tenant 1) → Hub → "Edycja zamówień" →
  zmiana ilości / dodanie / usunięcie linii + modyfikator → zapis →
  KDS pokazuje blok "ZMIANY OD OSTATNIEGO WYDRUKU".

## Otwarte pytania
- Czy KDS powinien mieć akcję "potwierdź zmiany" czyszczącą `kitchen_delta`
  (obecnie delta znika dopiero przy kolejnym wydruku biletu)?
- Modal nie edytuje kanału/typu zamówienia — `edit.php` używa wartości
  z nagłówka; ewentualna zmiana kanału to osobny temat cenowy (price tiers).
