# Sesja 2026-08-20 — order_type w modalu edycji + ACK delty na KDS

## Cel
Rozszerzenie modala "Edytuj zamówienie" (Hub) o zmianę typu zamówienia
(dine_in / takeaway / delivery, delivery wymaga adresu) oraz przycisk
"OK, widziałem" na bilecie KDS czyszczący `kitchen_delta` + `edited_since_print`.

## Pliki dotknięte
- **`api/orders/edit.php`** — walidacja `order_type` + wymóg `delivery_address`
  dla delivery (z payloadu lub istniejącego nagłówka); nagłówek zamówienia
  aktualizuje teraz `order_type` i `delivery_address` (COALESCE — brak pola
  w payloadzie nie kasuje adresu); zmiana typu zapisywana w
  `kitchen_delta.order_type = {old, new}`; edycja "tylko typ" (bez zmian linii)
  również przechodzi (wcześniej early-exit "No changes detected").
- **`api/orders/get_for_edit.php`** — `get_order` zwraca dodatkowo
  `delivery_address` (prefill pola adresu w modalu).
- **`api/kds/engine.php`** — nowa akcja `ack_changes`: SELECT + UPDATE
  tenant-scoped (`kitchen_delta = NULL`, `edited_since_print = 0`).
- **`modules/hub/index.html`** — select typu zamówienia + input adresu
  (widoczny tylko dla delivery) w widoku edycji modala.
- **`modules/hub/js/hub_order_edit.js`** — prefill typu/adresu, toggle
  widoczności adresu, walidacja frontowa (delivery bez adresu = błąd inline),
  `order_type` + `delivery_address` w payloadzie save, komunikat sukcesu
  pokazuje zmianę typu.
- **`modules/hub/css/hub.css`** — style `.hoe-type-row` / `.hoe-address`
  (Dark Glass, spójne z resztą modala).
- **`modules/kds/js/kds_app.js`** — wiersz `~ TYP: Sala → Dostawa` w bloku
  delty + przycisk "OK, widziałem" (`KdsApp.ackChanges`).
- **`modules/kds/css/style.css`** — styl `.kds-delta-ack` (bursztyn, spójny
  z ramką delty).
- **Docs:** `02_ARCHITEKTURA.md` — kontrakt `orders/edit.php` (order_type +
  delivery_address) i `kds/engine.php` (`ack_changes`).

## Decyzje architektoniczne
1. **`channel` pozostaje bez zmian** przy zmianie `order_type` — kanał cenowy
   (Prawo I, price tiers) to osobny temat; edycja typu nie przelicza cen na
   inny kanał.
2. **Zmiana typu jako klucz `order_type` w `kitchen_delta`** (obok
   added/removed/modified) — kuchnia widzi "TYP: Sala → Dostawa" w tym samym
   bloku delty; kontrakt DeltaEngine dla linii nietknięty.
3. **`delivery_address` przez COALESCE** — payload bez pola adresu nie
   nadpisuje istniejącego adresu; frontend wysyła adres tylko dla delivery.
4. **`ack_changes` czyści tylko `kitchen_delta` + `edited_since_print`** —
   nie dotyka `kitchen_changes` (mechanizm POS/print pozostaje niezależny,
   zgodnie z decyzją z sesji 2026-08-20_review_fixes_delta_xss).

## Otwarte pytania
- Czy zmiana `order_type` powinna docelowo przełączać też `channel`
  (i przeliczać ceny wg macierzy)? Obecnie ceny zostają na kanale zamówienia.
- Czy ACK na KDS powinien być logowany w `sh_order_audit` (kto potwierdził)?
