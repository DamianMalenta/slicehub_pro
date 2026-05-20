# Sesja AI — odświeżenie materiałów wniosku SPARK 3.0

## Cel

Zsynchronizować pliki `wniosek*.md`, teksty F6S i `SPARK_materialy` z aktualnym stanem `main` po zmianach od 2026-05-13 (KSeF API v2, procurement OPEX, BiEngine P&L, pełny seed demo, 55 migracji).

## Pliki dotknięte

- `wniosek.md` — 17 modułów, API bi/procurement, BiEngine/KSeF, worker KSeF, migracje 004–057, 62 testy
- `wniosek_innowacje.md` — sekcja 14 BI P&L Engine
- `wniosek_modul_magazyn.md` — KSeF ✅, matryca stanu, faza 1 zamknięta
- `wniosek_modul_studio_online.md` — warianty storefront, data rewizji
- `wniosek_porownanie_papu.md` — KSeF, BI, liczniki kodu
- `_docs/F6S_SPARK_3_0/03_TEKSTY_FORMULARZ_F6S.md` — elevator + opisy średni/długi
- `_docs/SPARK_materialy/onepager.html`, `pitchdeck.html`, `landing.html` — liczby modułów/status

## Decyzje architektoniczne

- **Źródło prawdy:** sesje `2026-05-14_*` (BI, KSeF v2, OPEX) + git log od `c62ee5e`.
- **Nie przerabiano wideo/screenów** w tej sesji — tylko treść merytoryczna; rework wizualny pozostaje wg `PROMPT_SPARK_REWORK_BEZ_VIDEO.md`.
- **Liczba modułów:** 17 operacyjnych (bez `ui_shell`); w materiałach marketingowych zaokrąglone spójnie.

## Otwarte pytania

- Czy wygenerować ponownie PDF z `onepager.html` / `pitchdeck.html` po odświeżeniu screenów (v2)?
- Czy formularz F6S w portalu wymaga osobnej synchronizacji pól liczbowych (budżet, trakcja) poza repo?
