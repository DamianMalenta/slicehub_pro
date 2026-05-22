# Seed — ścieżka „golden path” (SliceHub demo)

> Ostatnia aktualizacja: 2026-05-22. Główny seed: `scripts/seed_demo_all.php` (tenant_id = 1).

## Pełna baza od zera

```bash
mysql -u root slicehub_pro_v2 < database/migrations/001_init_slicehub_pro_v2.sql
php scripts/apply_migrations_chain.php
php scripts/seed_demo_all.php
```

Opcjonalnie (drugi tenant / duże menu + KSeF FORNO):

```bash
mysql -u root slicehub_pro_v2 < scripts/seed_pizzaforno.sql
```

## Tylko odświeżenie danych demo (baza już po chain)

```bash
php scripts/seed_demo_all.php
```

Bezpieczny wielokrotny run (`ON DUPLICATE KEY UPDATE`). KSeF demo (`FA/DEMO/%`) jest kasowany i wstawiany na nowo.

## Co robi `seed_demo_all.php`

| Krok | Zawartość |
|------|-----------|
| Preflight | Sprawdza tabele KSeF / meal packages / OPEX (wymaga chain) |
| Tenant 1 | Ustawienia, 8 użytkowników, kategorie, 33 pozycje menu (`publication_status=Live`) |
| Ceny / modyfikatory | `sh_price_tiers`, grupy modów, linki magazynowe |
| Magazyn | 43× `sys_items` + `wh_stock` |
| Aliasy | `004_expand_search_aliases.sql` (UPDATE po SKU — AutoScan) |
| Receptury | Menu → warehouse (FK `ascii_key`) |
| Mapowania | `sh_product_mapping` z `supplier_nip` + `pack_qty_base` (P_7A bazylia) |
| KSeF | 3 faktury: `draft`, `accepted` (PZ #1), `error` (pusta korekta) |
| OPEX | Kategorie kosztów (m057) |
| Operacje | PZ/RW, kierowcy, 12 zamówień, 2 zestawy POS, sesje pracy |

## Czego seed **nie** robi

- Nie zastępuje `apply_migrations_chain.php` (usunięto zduplikowane ALTER 006/007 z seeda).
- `nuclear_reset.php` — tylko częściowy reset zamówień/użytkowników, nie pełny reseed.
- `_docs/demo_seed_dynamic_data.sql` — osobny fixture pod **tenant_id = 2** (wideo SPARK); wklej po sprawdzeniu `@tid`.

## Weryfikacja

```bash
php scripts/seed_demo_all.php
php scripts/audit_ksef_matching.php
```

W przeglądarce: `http://localhost/slicehub/modules/procurement/` (login: admin / password).

## Znane problemy chain na starych instancjach

Migracje 010, 037, 047–048, 053–055 mogą zgłosić FAIL przy duplikatach kolumn — nie blokuje seeda, jeśli tabele już istnieją.
