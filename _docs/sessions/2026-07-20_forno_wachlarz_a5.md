# Sesja: Wachlarz A5 Forno + panel Marketing

**Data:** 2026-07-20

## Cel

Wdrożyć edytowalny system kart A5 (wachlarz na deskę ze śrubą) na bazie oferty Pizza Forno: silnik druku, seed treści, API multi-tenant oraz panel użytkownika w Marketingu.

## Pliki dotknięte

- `_docs/ulotki/forno-wachlarz/BRIEF.md`, `content.json`
- `database/migrations/060_print_decks.sql`
- `scripts/_migrations_chain.php` (+ wpis 060)
- `api/marketing/deck_engine.php`
- `modules/marketing/deck/` (`index.html`, `print.html`, `css/deck.css`, `js/deck-renderer.js`, `js/deck-panel.js`)
- `modules/marketing/index.html` (link do Wachlarza)
- `modules/hub/index.html` (karta Hub)
- `_docs/02_ARCHITEKTURA.md`, `_docs/04_BAZA_DANYCH.md`

## Decyzje architektoniczne

1. **Treść ≠ layout** — karty w `sh_print_deck_cards.payload_json`; wspólny CSS/renderer dla panelu i druku.
2. **Typy kart:** `cover` | `hero_duo` | `hero_sizes` | `hero_list` | `cta`.
3. **Most do menu** tylko przez `ascii_key` w payload (bez cross-silo ID).
4. Panel w `modules/marketing/deck/` (obok SMS), nie osobny hub-moduł.
5. Seed startowy z `_docs/ulotki/forno-wachlarz/content.json` (`deck_seed_forno`).

## Otwarte pytania

- Upload zdjęć hero do biblioteki assetów (dziś URL w polu).
- Sync cen z `sh_price_tiers` jednym kliknięciem.
- Fizyczny proof montażu (śruba 7 mm, baza 160×220) u drukarni.

## Test (E2E)

1. `php scripts/apply_migrations_chain.php --audit` → OK
2. Migracja 060 zastosowana lokalnie (`sh_print_decks`, `sh_print_deck_cards`)
3. `php scripts/seed_print_deck_forno.php 1` → 7 kart
4. Panel: `/modules/marketing/deck/` (JWT) → podgląd / edycja / `print.html?deck_id=`
5. Offline preview bez API: `_docs/ulotki/forno-wachlarz/preview.html`
