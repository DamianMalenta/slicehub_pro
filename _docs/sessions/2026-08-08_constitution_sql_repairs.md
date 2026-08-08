# Sesja: Naprawa naruszeń Konstytucji — SQL + encoding

**Data:** 2026-08-08
**Powiązane:** `2026-07-30_audit_sql_konstytucja_naruszenia.md`, `01_KONSTYTUCJA.md` (Prawo II, IV, IX), `2026-08-04_drift_rectification_N1_N5.md`
**Cel:** Naprawić naruszenia Konstytucji zidentyfikowane w audycie SQL + przywrócić kodowanie `modules/pos/index.html`.

---

## Kontekst

Audyt z 2026-07-30 zidentyfikował 7 naruszeń Konstytucji w zapytaniach SQL. Dodatkowo commit `789dd9a` (2026-08-03) uszkodził kodowanie `modules/pos/index.html` (UTF-8 → cp1250, zepsute polskie znaki, zmieniona logika biznesowa `½ + ½` → `1 + 1`).

Decyzja użytkownika: **nie cofać** do backupu z 21 maja (`backup-local-main-2026-05-21`), lecz naprawić naruszenia in-place. Backup zachowuje 3 miesiące pracy (KSeF, ChoiceQR, Elzab, BI, Food Cost UI, Order Edit, Settlement, Variant Scales).

---

## Wykonane naprawy

### S0 — Encoding `modules/pos/index.html`

| Problem | Fix |
|---------|-----|
| Commit `789dd9a` uszkodził kodowanie: UTF-8 z BOM → cp1250, polskie znaki zepsute (`ó` = `F3` zamiast `C3 B3`), logika biznesowa zmieniona (`½ + ½` → `1 + 1`) | Przywrócony z `backup-local-main-2026-05-21` (13553 bajty, UTF-8 z BOM, `ó` = `C3 B3`) |

### S1 — Brak `tenant_id` w `sh_order_lines` (Prawo II: Multi-Tenancy)

`sh_order_lines` nie ma kolumny `tenant_id` — tenant isolation realizowane przez JOIN/subquery z `sh_orders WHERE tenant_id = ?`.

| # | Plik | Linia | Naprawa |
|---|------|-------|---------|
| S1.1 | `api/pos/engine.php` | 540 (SELECT lines dla order list) | Dodany `AND order_id IN (SELECT id FROM sh_orders WHERE tenant_id = ?)` + `$tenant_id` do execute |
| S1.2 | `api/pos/engine.php` | 829 (UPDATE lines → cancelled) | Dodany subquery `order_id IN (SELECT id FROM sh_orders WHERE tenant_id = ?)` |
| S1.3 | `api/pos/engine.php` | 836 (DELETE oim JOIN lines, fired_at IS NULL) | Dodany subquery na `ol.order_id` |
| S1.4 | `api/pos/engine.php` | 840 (DELETE lines, fired_at IS NULL) | Dodany subquery |
| S1.5 | `api/pos/engine.php` | 850 (DELETE oim JOIN lines, fallback) | Dodany subquery |
| S1.6 | `api/pos/engine.php` | 851 (DELETE lines, fallback) | Dodany subquery |
| S1.7 | `api/courses/engine.php` | 1102 (SELECT lines dla driver course) | Dodany `AND order_id IN (SELECT id FROM sh_orders WHERE tenant_id = :tid)` + `:tid` do params |
| S1.8 | `api/integrations/choiceqr/table_orders.php` | 236 (SELECT lines dla table orders) | Dodany subquery + `array_merge($orderIds, [$tenantId])` do execute |

### S2 — Brak `tenant_id` w `sh_users` (Prawo II: Multi-Tenancy)

| # | Plik | Linia | Naprawa |
|---|------|-------|---------|
| S2.1 | `core/Elzab/ElzabFiscalEngine.php` | 391 (`fetchCashierName`) | Dodany parametr `int $tenantId` do sygnatury + `AND tenant_id = :tid` w SQL. Call-site (linia 75) zaktualizowany: `fetchCashierName($pdo, $userId, $tenantId)` |

### S3 — Interpolacja SQL (Prawo IV: Zero Trust)

| # | Plik | Linia | Naprawa |
|---|------|-------|---------|
| S3.1 | `api/procurement/inbox.php` | 1727-1729 | `$pdo->query("...tenant_id={$tenant_id} AND warehouse_id='" . addslashes(...) . "'")` → prepared statement z `?` positional params |

### S4 — Cross-silo JOIN (Prawo IX: Silosy Prefiksowe) — FALSE POSITIVE

| # | Plik | Linia | Diagnoza |
|---|------|-------|----------|
| S4.1 | `core/BiEngine.php` | 174 | **Nie naruszenie.** Audyt flagged JOIN `sh_orders o ON o.id = wd.order_id` jako "cross-silo JOIN by numeric id". Faktycznie: `sh_orders.id` = **CHAR(36)** (UUID), `wh_documents.order_id` = **CHAR(36)**. JOIN jest VARCHAR=VARCHAR + ma `tenant_id` po obu stronach (`:tid_wd`, `:tid_ord`). Zgodne z Prawo IX. |

---

## Weryfikacja

### Lint (PHP)

```
php -l api/pos/engine.php                              → No syntax errors detected
php -l api/courses/engine.php                          → No syntax errors detected
php -l api/integrations/choiceqr/table_orders.php      → No syntax errors detected
php -l core/Elzab/ElzabFiscalEngine.php                → No syntax errors detected
php -l api/procurement/inbox.php                       → No syntax errors detected
php -l core/BiEngine.php                               → No syntax errors detected
```

### E2E (test_runner.html, headless Chrome)

```
pass: 61, fail: 0, warn: 1, total: 62
```

1 warning = pre-existing (nie związany z niniejszymi zmianami).

### Encoding `index.html`

```
Size: 13553 bytes, BOM: True (EF BB BF)
Zamów → ó = C3 B3 (UTF-8) ✓
```

---

## Pliki zmienione

| Plik | Typ zmiany |
|------|-----------|
| `modules/pos/index.html` | Przywrócony z backup (encoding) |
| `api/pos/engine.php` | 5× dodany tenant_id subquery |
| `api/courses/engine.php` | 1× dodany tenant_id subquery |
| `api/integrations/choiceqr/table_orders.php` | 1× dodany tenant_id subquery |
| `core/Elzab/ElzabFiscalEngine.php` | Sygnatura + SQL (fetchCashierName) |
| `api/procurement/inbox.php` | Interpolacja → prepared statement |

---

## Pozostałe otwarte

- **Dual pricing path** w `api/backoffice/api_menu_studio.php` (Prawo I: Macierz Cenowa) — zidentyfikowane w audycie 2026-07-30, **nie naprawione** w tej sesji. Wymaga decyzji architektonicznej (która ścieżka cenowa jest kanoniczna).
- **HR-6 midnight-crossing allocation** (`fn_allocate_hours` edge case) — pre-existing, niezwiązane z SQL.
