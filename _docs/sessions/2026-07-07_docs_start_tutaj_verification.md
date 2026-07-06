# Sesja 2026-07-07 — START_TUTAJ + weryfikacja golden path

## Cel

Domknąć drift dokumentacji (brakujący `START_TUTAJ.md`), zweryfikować rzetelnie stan repo po aktualizacji Prawo X (commity 6–7 lip), naprawić łamany CI Deno, potwierdzić 62/62 test_runner na żywej bazie.

## Pliki dotknięte

| Plik | Opis |
|------|------|
| `_docs/START_TUTAJ.md` | **NEW** — punkt wejścia: tory zadań, golden path, @planned, konflikt priorytetów |
| `_docs/sessions/README.md` | Usunięto martwy link do `.cursor/plans/api_base_paths_handoff.md` |
| `_docs/sessions/2026-05-21_api_base_paths.md` | Handoff → ten plik sesji (kanon) |
| `.github/workflows/deno.yml` | `workflow_dispatch` only — brak `deno.json` w repo |
| `scripts/run_test_runner_headless.cjs` | Headless 62/62 bez przeglądarki (puppeteer-core poza repo) |
| `AGENTS.md` | Instrukcja headless test runner |

## Decyzje architektoniczne

1. **`START_TUTAJ.md` w `_docs/`** — zgodnie z `02_ARCHITEKTURA.md` i handoff Magazyn+Studio; jeden plik zamiast rozproszonych odniesień.
2. **Deno CI** — nie usuwać workflow (decyzja usera 17 cze), ale wyłączyć auto-run na push dopóki nie ma `deno.json` + testów JS (Zero-Reload).
3. **Headless test runner** — `puppeteer-core` poza repo (brak `package.json`); skrypt opcjonalny dla agentów/CI.
4. **Settle / Magazyn / Online** — bez zmian kodu w tej sesji; stan opisany w `START_TUTAJ.md` §5–7.

## Otwarte pytania

1. Decyzja właściciela: tor **Magazyn+Studio** vs **Online M5** vs **settle canonical**.
2. `units_contract.json` (KSeF) — nadal nie wdrożony.
3. Czy dodać `deno.json` + lint JS modułów, czy usunąć workflow całkowicie.

## Test (E2E)

```bash
sudo mysqld_safe &   # jeśli socket stale
apachectl start
php scripts/seed_demo_all.php
php scripts/test_invoice_qty_normalizer.php    # All tests passed
php scripts/test_ksef_parser_quality.php       # 8 OK, 0 FAIL
node scripts/run_test_runner_headless.cjs      # 62 pass, 0 fail
```

Migracje `apply_migrations_chain`: 015 pominięte, 037 FAIL (MariaDB 10.11 — znane); kolumny 048+ już istnieją na istniejącej bazie.
