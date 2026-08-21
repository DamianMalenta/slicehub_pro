# 2026-08-22 — POS Delta Engine Unification

## Kontekst

POS (`api/pos/engine.php` action=`process_order`, ścieżka EDIT) używał własnego, legacy
mechanizmu detekcji zmian kuchennych:

- Ręczny diff `cart_json` (old vs new) budujący tekstowy łańcuch `kitchen_changes`
  (format: `"DODANO: 2x Pizza | USUNIĘTO: 1x Salad | ZMIENIONO ILOŚĆ: Cola (1 -> 3)"`).
- Dekstrukcyjne `DELETE` wszystkich linii + `INSERT` nowych — line_id były tracone,
  KDS tracił ciągłość biletów, referencje `sh_kds_tickets` / `fired_at` były rwane.
- Zapis do martwej kolumny `sh_orders.kitchen_changes` (TEXT) oraz jednoczesne
  `kitchen_delta=NULL` — delta z Huba była nadpisywana nullem przy każdej edycji POS.

Równolegle `api/orders/edit.php` (ścieżka Hub/backoffice) implementował kanoniczny
przepływ z `DeltaEngine::computeDelta()` + atomowym sync linii (UPDATE/INSERT/DELETE
z zachowaniem `line_id`) + zapisem strukturalnego `kitchen_delta` JSON.

To rozdwojenie SSOT powodowało: utratę ciągłości `line_id` w POS, brak highlightu
zmian na KDS po edycji z POS, oraz konflikt dwóch kolumn (`kitchen_changes` vs
`kitchen_delta`) opisujących to samo zjawisko.

## Zmiany

### 1. `api/pos/engine.php` — `process_order` (EDIT branch)

- **Wycofano** ręczny diff `cart_json`, zmienną `$kitchenChanges` oraz jawne
  `kitchen_delta=NULL`.
- **Wprowadzono** `DeltaEngine::computeDelta($oldLines, $newLinesRaw)` — ten sam
  silnik co `api/orders/edit.php`. SSOT dla detekcji zmian kuchennych.
- **Atomowy sync linii** zastąpił destrukcyjne `DELETE+INSERT`:
  - `DELETE removed` — soft-delete (`line_status='cancelled'`, `quantity=0`) dla
    linii już wysłanych do KDS (`fired_at IS NOT NULL`); hard-delete dla reszty.
  - `UPDATE modified` — aktualizacja qty/price/vat/modifiers/comment w miejscu
    (zachowanie `line_id` + replace `sh_order_item_modifiers`).
  - `INSERT added` — nowe linie z pełnym handlingiem (driver_action_type,
    combo_meta_json, OIM).
- **Greedy-matching DB id**: nowe linie bez `line_id` pasującego do DB są
  dopasowywane po `item_sku` do istniejących DB line_id — zachowanie ciągłości
  identyfikatorów dla KDS.
- **`order_type` change**: jeśli typ zamówienia się zmienił, dopisywane jest
  `$delta['order_type'] = ['old' => $oldType, 'new' => $newType]` (zgodnie z
  rozszerzeniem edit.php z 2026-08-20).
- **SQL UPDATE `sh_orders`**: zapis `kitchen_delta` jako JSON
  (`JSON_UNESCAPED_UNICODE`), `edited_since_print = 1` gdy są zmiany (rygor
  `tenant_id`). Kolumna `kitchen_changes` usunięta z zapytania.
- **Outbox** (`order.edited`): payload zawiera sparsowaną strukturę
  `kitchen_delta` (array) zamiast tekstu `kitchen_changes`.

### 2. Akcje czyszczące — reset `kitchen_delta` + `edited_since_print`

- `print_kitchen` (POS): `kitchen_delta=NULL, edited_since_print=0` — usunięto
  martwe `kitchen_changes=NULL`.
- `bump_order` → `ready` (KDS): już resetował `kitchen_delta=NULL,
  edited_since_print=0` (R2, 2026-07-30) — bez zmian.
- `ack_changes` (KDS): już czyścił `kitchen_delta=NULL, edited_since_print=0`
  — bez zmian.

### 3. Usunięcie `kitchen_changes` ze schematu

- `database/migrations/007_pos_engine_columns.sql`: usunięto `ALTER TABLE ...
  ADD COLUMN kitchen_changes TEXT NULL`.
- `scripts/setup_database.php`: usunięto tę samą DDL z listy migracji 007.
- `api/pos/engine.php` auto-migration: usunięto `kitchen_changes` z listy DDL.

> **Uwaga:** kolumna `kitchen_changes` pozostaje w istniejących bazach (nie
> robimy `DROP COLUMN` — to destrukcyjna operacja na danych klientów). Po prostu
> przestajemy do niej pisać i czytać. Kolumna `kitchen_delta` (JSON, migracja
> 001) jest jedynym SSOT.

### 4. Naprawa kodowania i wydruków (`modules/pos/`)

- `index.html`: usunięto BOM (Byte Order Mark, `EF BB BF`) — plik zapisany jako
  czysty UTF-8. `<meta charset="UTF-8">` już obecny w `<head>`.
- `js/pos_ui.js` (`printTemplate`):
  - Dodano `<meta charset="UTF-8">` wewnątrz `<head>` szablonu wydruku —
    naprawia krzaki w drukowanych bonach/paragonach (polskie znaki).
  - Locale `'pl'` → `'pl-PL'` w `toLocaleTimeString` i `toLocaleString` —
    zgodność ze standardem BCP 47.
  - `addedList` i `removedList` owinięte funkcją `_e()` — ochrona XSS przy
    renderowaniu nazw modyfikatorów z koszyka.
- `sw.js`: `CACHE_VERSION` podbity `slicehub-pos-v8` → `slicehub-pos-v9`
  (Prawo IX — jawny unfreeze właściciela; unieważnia stary cache przeglądarek
  po zmianach `pos_ui.js` i `index.html`).

### 5. Dokumentacja

- `_docs/00_PAMIEC_SYSTEMU.md`: skorygowano ścieżkę `core/DeltaEngine.php` →
  `api/orders/DeltaEngine.php` (Prawo VIII & X — SSOT dokumentacji).
- Ten plik sesyjny.

## Pliki zmienione

| Plik | Zmiana |
|------|--------|
| `api/pos/engine.php` | Refaktor EDIT branch + DeltaEngine + atomowy sync + outbox + print_kitchen + auto-migration + helper `_posInsertOim` |
| `database/migrations/007_pos_engine_columns.sql` | Usunięto `kitchen_changes` DDL |
| `scripts/setup_database.php` | Usunięto `kitchen_changes` z migracji 007 |
| `modules/pos/index.html` | Usunięto BOM |
| `modules/pos/js/pos_ui.js` | `printTemplate`: charset meta, `pl-PL`, `_e()` XSS |
| `modules/pos/sw.js` | `CACHE_VERSION` → `v9` |
| `_docs/00_PAMIEC_SYSTEMU.md` | Ścieżka DeltaEngine + notatka o unifikacji |
| `_docs/sessions/2026-08-22_pos_delta_engine_unification.md` | Ten wpis |

## Weryfikacja

- `php -l` na zmienionych plikach PHP — brak błędów składni.
- Headless test runner: `node scripts/run_test_runner_headless.cjs` — 62/62 PASS.
