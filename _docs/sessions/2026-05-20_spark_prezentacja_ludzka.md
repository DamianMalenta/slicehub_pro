# Sesja AI — prezentacja SPARK od zera (język ludzki + nowe screeny)

## Cel

Nadpisać słabe materiały wniosku (pusty POS na starym wideo) kompletną prezentacją po ludzku: screeny z slicehub.net, HTML, PDF, teksty F6S.

## Pliki dotknięte

- `_docs/SPARK_materialy/` — hero_*.png (10), onepager.html, pitchdeck.html (14 slajdów), landing.html, OPISY_DLA_WNIOSKU.md, README.md
- `_docs/F6S_SPARK_3_0/03_TEKSTY_FORMULARZ_F6S.md`, `01_VOICEOVER_PRODUKT_60s.md`
- `scripts/capture_spark_screenshots.py` — Playwright + JWT
- `/opt/cursor/artifacts/spark_onepager_v2.pdf`, `spark_pitchdeck_v2.pdf`

## Decyzje

- Narracja: solo founder Damian Malenta, odbiorca = komisja/mentorzy, nie programiści.
- Slajd dedykowany POS z koszykiem + notatka o odświeżeniu względem pierwszego nagrania.
- KSeF: surowiec vs koszt operacyjny (ludzkie sformułowanie INVENTORY/EXPENSE).

## Otwarte

- `hero_09_driver.png`, `hero_10_kds.png` — małe pliki; ewentualnie dłuższe czekanie w Playwright.
- Nowe wideo 60s wg zaktualizowanego storyboardu — po stronie usera.

Test (E2E): Playwright capture 10/10 OK; PDF onepager ~1.2MB, pitchdeck ~2MB wygenerowane.
