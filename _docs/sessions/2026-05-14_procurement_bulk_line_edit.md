# Sesja 2026-05-14 — Edycja grupowa linii KSeF (inbox)

## Cel

Umożliwić w module Procurement zaznaczanie wielu pozycji faktury jednym kliknięciem, filtrowanie zaznaczenia po tagu (kategorii OPEX z `sh_expense_categories`) oraz masowe zapisanie typu OPEX + kategorii albo towar + SKU — bez wielokrotnego klikania każdej linii.

## Pliki dotknięte

- `api/procurement/inbox.php` — nowa akcja `bulk_update_lines` (tenant + faktura + walidacja `line_ids`, ta sama logika co `update_line`, tryb legacy: samo `sku`).
- `modules/procurement/js/procurement_app.js` — pasek edycji grupowej, kolumna checkboxów, powiązanie z API.
- `modules/procurement/css/procurement.css` — style paska i kolumny zaznaczeń.

## Decyzje architektoniczne

- Jedna akcja API zamiast wielu wywołań `update_line` z przeglądarki — mniej obciążenia i spójna walidacja po stronie serwera.
- Zaznaczanie „po tagu” opiera się na bieżącym UI (aktywny segment OPEX + wartość `<select>` kategorii), zgodnie z oczekiwaniem użytkownika przy pracy przed „Zapisz zmiany”.
- Faktury `accepted` / `rejected` — bez paska i bez kolumny zaznaczeń (tylko odczyt).

## Otwarte pytania

- Czy potrzebna jest masowa zmiana samego segmentu Towar/OPEX **bez** zapisu do API (tylko lokalnie przed „Zapisz zmiany”) — obecnie masowy zapis OPEX/SKU od razu persystuje przez `bulk_update_lines`.

## Test (E2E)

1. Migracja 057 naładowana, faktura w statusie „Nowe”, tryb linii OPEX.
2. Otworzyć fakturę — widoczny pasek „Edycja grupowa”, checkboxy w tabeli.
3. „Zaznacz wszystkie” / „Odznacz wszystkie” — stan nagłówka i wierszy spójny.
4. Ustawić kilka linii jako OPEX z kategorią „Facility” (lub własną „Prąd”), „Zaznacz z tagiem” z wybraną kategorią — zaznaczone tylko pasujące linie OPEX.
5. Zaznaczyć podzbiór, „Zapisz na zaznaczone” przy wybranej kategorii OPEX — po odświeżeniu modalu wszystkie zaznaczone mają ten sam tag; akceptacja faktury przechodzi walidację OPEX.
6. Tryb legacy (bez 057): checkboxy + jedno SKU na zaznaczone — `bulk_update_lines` z `sku` i `line_ids`.
