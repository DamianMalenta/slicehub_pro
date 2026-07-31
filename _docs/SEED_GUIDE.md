# Seed — ścieżka „golden path” (SliceHub demo)

> Ostatnia aktualizacja: 2026-07-07. Główny seed: `scripts/seed_demo_all.php` (tenant_id = 1).

**Ważne:** seedy wymagają wcześniejszego pełnego łańcucha migracji **004–059** (`php scripts/apply_migrations_chain.php`). Same migracje **nie** tworzą produktów — patrz [`database/README.md`](../database/README.md).

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
| **Studio / Online** | Scene Kit (53 SVG tła/props), **33 hero** dań + opisy, `composition_profile`, 10 scen pizza, warstwy modów, storefront channels |
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

W przeglądarce:
- Inbox KSeF: `/slicehub/modules/procurement/`
- Menu Studio (miniatury): `/slicehub/modules/studio/`
- Online Studio (sceny): `/slicehub/modules/online_studio/`
- Sklep: `/slicehub/modules/online/`

Login: admin / password (po `seed_demo_all.php`).

### Multi-tenant bez seeda demo

Typowy flow po `install_panel.php`: tenant 1 pusty (Demo Tenant), dane w tenant 2+. Wtedy:

- **Nie** zakładaj demo PIN-ów z tabeli powyżej — użyj PIN/hasła z install panelu.
- POS/Online: `tenant_config.php` wybiera tenanta z użytkownikami; opcjonalnie `?tenant=2` w URL.
- Test runner: auto-discovery PIN przed suite'ami (`discoverAuthTenant()`).
- Pełny reseed demo: `php scripts/seed_demo_all.php` (tenant 1) lub `scripts/seed_pizzaforno.sql` z `@tid`.

Szczegóły seeda: [`sessions/2026-05-22_seed_refresh.md`](sessions/2026-05-22_seed_refresh.md).  
Multi-tenant / auth: [`sessions/2026-05-22_tenant_discovery_auth.md`](sessions/2026-05-22_tenant_discovery_auth.md).

**Uwaga:** pliki SVG trafiają do `uploads/` (gitignore) — są **generowane przy każdym seedzie**, nie trzeba ich commitować.

## Znane problemy chain na starych instancjach

Migracje 010, 037, 047–048, 053–055 mogą zgłosić FAIL przy duplikatach kolumn — nie blokuje seeda, jeśli tabele już istnieją.

Migracja 062 (`fiscal_receipt_number`) dodaje kolumnę `fiscal_receipt_number` do `sh_orders` — idempotentna (`ADD COLUMN IF NOT EXISTS`), bezpieczna na istniejących bazach.
