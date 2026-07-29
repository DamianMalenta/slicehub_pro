# Sesja: ChoiceQR P2.3 — Compliance fix

**Data:** 2026-07-29
**Commity:** `b6d8772`, `248403d`, `aa915a6`
**Prawo X:** audyt sesji AI zmieniającej `core/` i `api/`

## Cel

Naprawa 5 niezgodności z wymogami ChoiceQR wykrytych w audycie kodowym + zestawieniu z oficjalną
dokumentacją ChoiceQR (pos/index.md, order/schema.md, webhooks.md). 3 fixy krytyczne (K5, NC6, K3)
+ 2 weryfikacje (NC1, NC12) naprawione na podstawie dokumentacji.

## Pliki dotknięte

### Zmienione (istniejące)
- `api/integrations/choiceqr/webhook.php` — K5: `cqr_fail_silent()` dla post-auth (200 OK + log)
- `core/Integrations/ChoiceQRAdapter.php` — NC1: `delivery.status` zamiast `deliveryStatus`; NC12: `paymentCustomerDetails` zamiast `payment`
- `_docs/integrations/choiceqr_integration.md` — dokumentacja P2.3 + stan gotowości do go-live

### Nowe (izolowane w warstwie integracji)
- `api/integrations/choiceqr/table_orders.php` — NC6: schema tableOrderSchema; K3: JOIN sh_tables.id

### NIE dotknięte (rdzeń SliceHub nienaruszony)
- POS, KDS, Magazyn, Kadry, Storefront, CartEngine, OrderStateMachine, WarehouseConsumeHook

## Decyzje architektoniczne

1. **K3 — Opcja A (JOIN w table_orders.php)** wybrana przez użytkownika. areas.php bez zmian
   (nie zmienia posID dla istniejących konfiguracji ChoiceQR). Odkryto że `sh_orders` ma `table_id`
   (FK → sh_tables.id), nie `table_number` — implementacja dostosowana (4 ścieżki query).

2. **NC1/NC12 — naprawione z docs, bez live payloadu.** webhooks.md potwierdza że `data` = pełny
   Order schema dla order.* events. Nie czekaliśmy na staging. Fallback na stare płaskie pola
   dodany dla zgodności wstecznej.

3. **Prawo IV (Zero zaufania) — świadomy wyjątek.** ChoiceQR przysyła totaly (klient zapłacił
   u nich). Nie przeliczamy przez CartEngine — to celowe, udokumentowane w webhook.php linia 17-18.
   ChoiceQR to zewnętrzna platforma, nie frontend JS. Przeliczenie + odrzucenie = klient zapłacił,
   restauracja nie widzi zamówienia.

4. **webhook.php — 200 OK dla błędów post-auth (K5).** ChoiceQR retryuje 3x przy braku 200, potem
   anuluje zamówienie klienta. Realne kody HTTP (401/403) zachowane tylko dla pre-auth (brak tokenu,
   zły token, brak tenant mapping) — bo te i tak retryuje, ale w logach Apache widać 401.

## Otwarte pytania (do kolejnej sesji)

1. **NC3 (Priorytet 1)** — `customerComments` vs `delivery.comment`. webhook.php czyta
   `delivery.comment`, ignoruje `customerComments` (array). Komentarze klienta mogą nie dotrzeć
   do restauracji. Mały fix, 1 plik.

2. **K4 (Priorytet 1)** — pay.php callback log bez `tenant_id`/`integration_id`. Płatność działa,
   ale w Settings → Inbound nie widać który tenant. Mały fix, 1 plik.

3. **S7 (Priorytet 1)** — brak testów inbound (parseInboundCallback). CLI test P1 pokrywa tylko
   push (42 asercje). Rozszerzyć `scripts/test_choiceqr_adapter.php` o 5 typów eventów.

4. **Prawo IV — czy świadomy wyjątek (totaly ChoiceQR bez CartEngine) jest akceptowalny
   długoterminowo?** Decyzja biznesowa: odrzucanie zamówień gdy cena ChoiceQR ≠ cena SliceHub vs
   zaufanie totalom. Obecnie: zaufanie. Do przegadania gdy pojawi się pierwszy klient live.

5. **Live test z ChoiceQR/papu.io** — gdy panel ChoiceQR będzie skonfigurowany z URL-ami SliceHub,
   zweryfikować end-to-end: zamówienie z ChoiceQR → webhook.php → sh_orders → POS/KDS.
