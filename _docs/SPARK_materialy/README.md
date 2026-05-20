# Materiały SPARK 3.0 — prezentacja (wersja 2026-05-20)

## Dla człowieka (wniosek, mentorzy)

| Plik | Opis |
|------|------|
| [OPISY_DLA_WNIOSKU.md](OPISY_DLA_WNIOSKU.md) | **Główne teksty** — kim jesteś, dla kogo, wyróżniki (bez kodu) |
| [onepager.html](onepager.html) | One-pager A4 → PDF `spark_onepager_v2.pdf` |
| [pitchdeck.html](pitchdeck.html) | 14 slajdów → PDF `spark_pitchdeck_v2.pdf` |
| [landing.html](landing.html) | Strona demo do hostowania |

## Screenshoty (produkcja slicehub.net)

`hero_01_hub.png` … `hero_10_kds.png` — odświeżone Playwright + JWT (2026-05-20).

**Ważne:** `hero_03_pos.png` pokazuje kasę z koszykiem (wcześniejsze materiały miały pusty POS).

## Formularz F6S

`_docs/F6S_SPARK_3_0/03_TEKSTY_FORMULARZ_F6S.md` — wersja ludzka.

## PDF (artifacts, nie w repo)

- `/opt/cursor/artifacts/spark_onepager_v2.pdf`
- `/opt/cursor/artifacts/spark_pitchdeck_v2.pdf`

## Wideo (pełna ścieżka procesów)

**Użyj V2** (żywe nagranie, nie sklejka screenshotów): [`../PROMPT_SPARK_NAGRANIE_PROCESY_FORNO_V2.md`](../PROMPT_SPARK_NAGRANIE_PROCESY_FORNO_V2.md)  
Merytoryka + seed: [`../PROMPT_SPARK_NAGRANIE_PROCESY_FORNO.md`](../PROMPT_SPARK_NAGRANIE_PROCESY_FORNO.md) · prep: `scripts/prep_spark_demo_orders.py`

## Regeneracja

```bash
python3 scripts/capture_spark_screenshots.py
# PDF: Playwright lub Chrome print-to-pdf na plikach HTML
```
