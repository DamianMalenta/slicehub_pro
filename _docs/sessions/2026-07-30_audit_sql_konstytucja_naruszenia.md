# Audyt SQL vs Konstytucja — naruszenia w żywym kodzie

**Data:** 2026-07-30
**Sesja:** audyt (bez zmian w kodzie)
**Następna sesja:** naprawa P0/P1 (patrz sekcja „Otwarte pytania")

---

## Cel

Weryfikacja, czy zapytania SQL w żywym kodzie PHP naruszają Konstytucję (`_docs/01_KONSTYTUCJA.md` v5). Audyt oparty **wyłącznie na kodzie** — bez powoływania się na dokumentację. Sprawdzono: cross-silo JOINs, intra-sh_ JOINs, barierę `tenant_id`, interpolację SQL, ścieżki cen.

## Pliki dotknięte (audytowane, nie zmienione)

- `core/BiEngine.php` (linie 142-189) — cross-silo JOIN po `id`
- `core/Elzab/ElzabFiscalEngine.php` (linie 389-394) — brak `tenant_id`
- `api/procurement/inbox.php` (linie 1727-1729) — interpolacja + addslashes w API
- `api/pos/engine.php` (linie 535-542, 820-842, 1092) — brak `tenant_id` w `sh_order_lines`
- `api/courses/engine.php` (linie 93-114, 1090-1094) — interpolacja w migracji + brak `tenant_id`
- `api/backoffice/api_menu_studio.php` (linie 1190-1204, 1545-1562, 1286-1288) — dualna ścieżka cen
- `scripts/nuclear_reset.php` (linie 87-142) — interpolacja w CLI
- `scripts/seed_demo_all.php` (linie 103-716) — interpolacja w CLI

## Decyzje architektoniczne

### 1. API vs CLI — rozróżnienie ma znaczenie dla oceny naruszeń

- **API** = `api/**/*.php` — odbiera HTTP, JWT, nieufne wejście. Prawo IV obowiązuje w pełni.
- **CLI** = `scripts/**/*.php` — uruchamiane ręcznie/cron, `$argv`, kontrolowane wejście. Luźniejsze.
- **Konstytucja nie wymaga** żeby wszystko było w API. CLI jest legalne (migracje, seed, cron, vault).
- **Kryterium oceny:** naruszenie w API = krytyczne (realny wektor ataku); naruszenie w CLI = dług techniczny (ryzyko bliskie zero, ale łamie literę Prawa IV).

### 2. `sh_menu_items` ma dualną tożsamość — dług schematyczny

- JOIN z `sh_order_lines` idzie po `ascii_key` (poprawnie, 9 wystąpień)
- JOIN z `sh_item_modifiers`/`sh_recipes` idzie po `id` (7 wystąpień)
- `sh_item_modifiers.item_id` powinien być `item_sku` (VARCHAR) — spójność z `sh_order_lines.item_sku`
- To nie narusza Konstytucji (Prawo VI mówi tylko o cross-silo), ale jest niespójnością DDD

### 3. Płaska kolumna `price` w `sh_menu_items` wciąż żyje jako fallback

- `api_menu_studio.php:1190-1204` — jeśli `!$hasPriceTiers && !$schemaV2`, czyta płaską `price`
- `api_menu_studio.php:1554-1562` — INSERT/UPDATE z `price` w legacy schema
- To narusza Prawo I dosłownie: „Ceny nigdy nie są zapisywane jako płaska kolumna `price`"
- Kod omija to warunkiem feature-detection — dualna ścieżka

## Hierarchia naruszeń (do naprawy w kolejnej sesji)

| Priorytet | Plik:linia | Typ | Naruszenie | Prawo |
|-----------|-----------|-----|-----------|-------|
| **P0** | `api/procurement/inbox.php:1727-1729` | API | interpolacja + `addslashes` zamiast prepared statement | IV |
| **P0** | `core/Elzab/ElzabFiscalEngine.php:391` | core (z API) | `SELECT name FROM sh_users WHERE id = :uid` — brak `tenant_id` | VI |
| **P0** | `api/pos/engine.php:539` | API | `sh_order_lines WHERE order_id = ?` — brak `tenant_id` | VI |
| **P0** | `api/pos/engine.php:832` | API | `DELETE FROM sh_order_lines WHERE order_id = ?` — brak `tenant_id` | VI |
| **P0** | `api/pos/engine.php:842` | API | `DELETE FROM sh_order_lines WHERE order_id = ?` — brak `tenant_id` | VI |
| **P1** | `api/courses/engine.php:1092` | API | `sh_order_lines WHERE order_id IN (...)` — brak `tenant_id` | VI |
| **P1** | `api/courses/engine.php:93-105` | API | interpolacja `{$migTid}` w bloku migracji w locie | IV |
| **P2** | `core/BiEngine.php:174` | core | `INNER JOIN sh_orders o ON o.id = wd.order_id` — cross-silo wh_↔sh_ po `id` | VI |
| **P2** | `api/backoffice/api_menu_studio.php:1190-1204, 1554-1562` | API | dualna ścieżka cen (fallback na płaską `price`) | I |
| **P3** | `scripts/nuclear_reset.php:87-142` | CLI | 11× interpolacja `$T` (ryzyko zero, łamie literę IV) | IV |
| **P3** | `scripts/seed_demo_all.php:103-716` | CLI | 6× interpolacja `{$T}` (ryzyko zero) | IV |

## Statystyki audytu

- 133 pliki PHP z SQL
- 184 JOIN-y łącznie
- 1373 `->prepare()` (poprawnie)
- 154 `->exec()` (często interpolacja)
- 243 `->query()` (często feature-detection)
- 12 cross-silo JOINs: 11 po `sku`/`ascii_key` (OK), 1 po `id` (BiEngine)
- ~40 intra-sh_ JOINs po `id` (nie zabronione, ale niespójne z DDD)
- 923 wystąpień `tenant_id = :tid` — większość zapytań ma barierę

## Otwarte pytania (do rozstrzygnięcia w kolejnej sesji)

1. **P0 — czy naprawiać wszystkie 4 punkty w jednej sesji, czy rozbić na osobne commity?**
   - `inbox.php:1727` — najgroźniejszy (API + addslashes + dane z KSeF)
   - `ElzabFiscalEngine.php:391` — wymaga dodania `tenant_id` do sygnatury `fetchCashierName()`
   - `pos/engine.php` 3× — proste dodanie `AND tenant_id = ?` + param

2. **P2 — `BiEngine.php:174`: czy `wh_documents` ma kolumnę `order_number` (klucz domenowy)?**
   - Jeśli tak → zmiana JOIN na `o.order_number = wd.order_number`
   - Jeśli nie → decyzja: dodać kolumnę (migracja) czy zostawić `id` z `tenant_id` w WHERE (kompromis)
   - Sprawdzić schemat `wh_documents` przed naprawą

3. **P2 — `api_menu_studio.php` dualna ścieżka cen: czy usunąć fallback na płaską `price`?**
   - Jeśli tak → wymaga migracji usuwającej kolumnę `price` z `sh_menu_items` (breaking)
   - Jeśli nie → zostaje dług, ale Konstytucja naruszona
   - Pytanie do właściciela: czy którykolwiek tenant w produkcji używa legacy schema (bez `sh_price_tiers`)?

4. **P3 — CLI: czy refaktoryzować `nuclear_reset.php` i `seed_demo_all.php` na prepared statements?**
   - Ryzyko = zero, ale łamie literę Prawa IV
   - Decyzja: kosmetyk czy obowiązkowe?

5. **DDD — `sh_item_modifiers.item_id` → `item_sku`: czy to w zakresie tej sesji?**
   - Spójność tożsamości `sh_menu_items` (ascii_key vs id)
   - Wymaga migracji + refaktoryzacji JOIN-ów
   - Nie narusza Konstytucji, ale jest długiem DDD

## Kontekst dla kolejnej sesji

- Konstytucja: `_docs/01_KONSTYTUCJA.md` v5 (Prawa I-X + Manifest runtime)
- AGENTS.md: `C:\xampp\htdocs\slicehub\AGENTS.md` (stack, demo accounts, gotchas)
- Reguła: każda zmiana `core/`/`api/`/`database/migrations/` wymaga commit message z `Test (E2E):` + pliku sesji (Prawo X)
- Testy: `http://localhost/slicehub/tests/test_runner.html` (62 testy) lub `node /workspace/scripts/run_test_runner_headless.cjs`
- Lint: `find /workspace -name "*.php" -not -path "*/vendor/*" | xargs -P4 -I{} php -l {}`
