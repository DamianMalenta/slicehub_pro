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

## Wideo — 7 procesów (localhost, tenant Pizza Forno SPARK)

| Plik | Proces |
|------|--------|
| [wideo/spark_forno_procesy_final.mp4](wideo/spark_forno_procesy_final.mp4) | Montaż finałowy (~139 s, crossfade 0,4 s) |
| [wideo/spark_P1_online.webm](wideo/spark_P1_online.webm) | P1 — Online, wariant 30/37 cm |
| [wideo/spark_P2_kds.webm](wideo/spark_P2_kds.webm) | P2 — KDS |
| [wideo/spark_P3_track.webm](wideo/spark_P3_track.webm) | P3 — Tracking klienta |
| [wideo/spark_P4_courses.webm](wideo/spark_P4_courses.webm) | P4 — Courses + POS |
| [wideo/spark_P5_driver.webm](wideo/spark_P5_driver.webm) | P5 — Driver App |
| [wideo/spark_P6_ksef.webm](wideo/spark_P6_ksef.webm) | P6 — KSeF Inbox |
| [wideo/spark_P7_bi.webm](wideo/spark_P7_bi.webm) | P7 — BI + Studio Menu |

Bootstrap: `scripts/bootstrap_spark_recording_env.sh` · env: [spark_recording_env.example.json](spark_recording_env.example.json)

Brief: [`../PROMPT_SPARK_NAGRANIE_PROCESY_FORNO_V2.md`](../PROMPT_SPARK_NAGRANIE_PROCESY_FORNO_V2.md)

## Regeneracja

```bash
python3 scripts/capture_spark_screenshots.py
# PDF: Playwright lub Chrome print-to-pdf na plikach HTML
```
