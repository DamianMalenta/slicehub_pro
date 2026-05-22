# Sesja: odświeżenie seedów demo

## Cel
Zsynchronizować `seed_demo_all.php` ze schematem i funkcjami po migracjach 046–059 (KSeF, mapowania NIP, aliasy AutoScan, `publication_status`).

## Pliki dotknięte
- `scripts/seed_demo_all.php`
- `scripts/lib/seed_search_aliases.php`
- `_docs/SEED_GUIDE.md`
- `_docs/demo_seed_dynamic_data.sql`
- `AGENTS.md`

## Decyzje architektoniczne
- Usunięto zduplikowane ALTER 006/007 z seeda — jedyny właściwy path: `apply_migrations_chain.php` przed seedem.
- Aliasy: reuse UPDATE z `004_expand_search_aliases.sql` po wstawieniu `sys_items`.
- KSeF demo: `FA/DEMO/*` z statusami `draft` / `accepted` / `error`, mapowania z `supplier_nip` zgodne z m059.

## Aktualizacja (Studio visuals)
- `scripts/lib/seed_dish_visuals.php` — hero SVG, sh_assets/links, opisy, composition_profile, sceny pizza, Scene Kit, mod layers, meal images.

## Otwarte pytania
- `seed_pizzaforno.sql` — osobny audyt pod tenant 2 (duży SQL, generator Python).
- Prawdziwe zdjęcia .webp — opcjonalnie `restore_assets_from_disk.php` jeśli masz `uploads/global_assets/` lokalnie.

## Test (E2E)
`php scripts/seed_demo_all.php` → 18/18 OK; `php scripts/audit_ksef_matching.php` → FA/DEMO widoczne w Inbox.
