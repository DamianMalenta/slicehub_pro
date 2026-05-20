# Sesja: prompt nagrania SPARK · Pizza Forno

## Cel

Kanoniczny brief do nagrania pełnej ścieżki procesów na produkcji z seedem `seed_pizzaforno.sql` (menu 1:1, warianty 30/37 cm) + montaż timelapse.

## Pliki dotknięte

- `_docs/PROMPT_SPARK_NAGRANIE_PROCESY_FORNO.md` (nowy)
- `_docs/PROMPT_SPARK_PREZENTACJA_IDEALNA.md` (link)
- `_docs/SPARK_materialy/README.md` (link)

## Decyzje architektoniczne

- Seed Forno nie ustawia `tracking_token` — prompt zawiera SQL prep dla FORNO-006 przed sceną track+mapa.
- Kierowca nagrania: Kasia (produkcja); opcjonalnie `demo_seed_dynamic_data.sql` dla Jan/Tomasz przy dispatchu.
- Oddzielenie od `PROMPT_SPARK_PREZENTACJA_IDEALNA` (screeny bez RecordScreen).

## Otwarte pytania

- Czy na produkcji seed jest już wgrany (verify PASS)?
- Czy wykonać nagranie w tej samej sesji agenta, czy tylko prompt dla użytkownika?
