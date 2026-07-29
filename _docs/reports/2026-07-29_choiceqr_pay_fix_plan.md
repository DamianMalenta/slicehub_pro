# Raport z audytu ChoiceQR Open API + plan naprawy `pay.php`

## Data
2026-07-29

## Wykonane kroki
- Pobrano i przeanalizowano oficjalne dokumenty ChoiceQR:
  - `/content/authorization` — autoryzacja OAuth2, token JWT ważny 5 lat.
  - `/content/api` — ogólne zasady API, rate limit 60 req/sec, nagłówek `x-idempotence-key`.
  - `/content/webhooks` — schemat eventów `{id, type, langCode, data, varSymbol, timestramp}`.
  - `/content/pos/index` — pełna specyfikacja endpointów POS (Create order, Get menu, Get areas, Get table orders, Pay table order).
  - `/content/order/schema` — schemat zamówienia i `paymentCustomerDetails`.
  - `/content/order/statuses` — statusy zamówień i dostawy.

## Najważniejszy wniosek

**`api/integrations/choiceqr/pay.php` jest niezgodny z dokumentacją ChoiceQR.**

Obecnie `pay.php` oczekuje payloadu w formacie:

```json
{
  "_id": "order_id",
  "payment": { "method": "...", "amount": ..., "id": "..." },
  "varSymbol": "..."
}
```

Tymczasem dokumentacja ChoiceQR (`/content/pos/index`) mówi, że `Pay table order URL` otrzymuje:

```json
{
  "order": {
    "_id": "orderID1",
    "items": [...],
    "total": 180000,
    "tips": 30000,
    "paymentCustomerDetails": {
      "country": "Czech Republic",
      "card": { "type": "MC", "last4": "5000*00" },
      "paymentOrderNum": "TEST_REST-O-1",
      "rro": {
        "terminalId": "TERMINAL_ID",
        "transactionId": "2350740175",
        "authcode": "238710",
        "merchantId": "MERCHANT_ID",
        "rrnDebit": "004331840000"
      }
    }
  },
  "varSymbol": "10102"
}
```

To oznacza, że płatność QR przy stoliku może nie być poprawnie rozpoznawana przez obecny kod.

## Inne ustalenia

1. **Create order URL** i **Pay table order URL** oczekują `200 OK` z pustym body.
2. Retry policy dla obu: **3 próby co 3 sekundy, 15 sekund timeout**, potem anulowanie.
3. Autoryzacja webhooków w dokumentacji ChoiceQR nie jest opisana — my używamy tokena w URL (`?t=SECRET_TOKEN`).
4. `events.php` i `table_orders.php` są zgodne z ogólnym schematem webhooków.
5. Statusy zamówień ChoiceQR: `new → waiting_for_approve → approved → pre_closed → closed` (sukces).
6. Statusy dostawy ChoiceQR: `idle → created → processing → waitingForPickUp → arrivedForPickUp → progress → done`.

---

## Plan naprawy `pay.php`

### Krok 1: Przygotowanie i dokumentacja
- Zapoznać się z bieżącym `api/integrations/choiceqr/pay.php`.
- Zapoznać się ze schematem `tablePayOrderSchema` w `open-api.choiceqr.com/docs/content/pos/index.md`.
- Zapoznać się z `_docs/integrations/choiceqr_integration.md`.

### Krok 2: Zmiana payloadu
- Odczytywać `order` zamiast `_id` i `payment`.
- Z payloadu wyciągać:
  - `order._id` — internal POS order ID (do matchowania z `sh_orders.gateway_external_id` lub `id`)
  - `order.total` — kwota do opłacenia
  - `order.tips` — napiwek
  - `order.paymentCustomerDetails` — dane płatności (opcjonalne)
  - `varSymbol` — mapowanie na tenant
- Zmienić walidację: wymagane `_id` zamówienia i `varSymbol`.

### Krok 3: Matchowanie zamówienia
- Znaleźć zamówienie w `sh_orders` po:
  - `tenant_id = :tid AND id = :oid`, a jeśli nie ma —
  - `tenant_id = :tid AND gateway_source = 'choiceqr' AND gateway_external_id = :eid`.
- Użyć `SELECT ... FOR UPDATE` w transakcji (już obecne — zachować).

### Krok 4: Idempotencja
- Użyć jako klucza `paymentCustomerDetails.rro.transactionId` albo `paymentCustomerDetails.paymentOrderNum`.
- Jeśli brak powyższych — fallback na `order._id` + `order.total` (bo jedno zamówienie przy stoliku może mieć wiele płatności częściowych, więc tylko kombinacja zamówienia + kwota daje sensowną unikalność).
- Sprawdzić `sh_external_order_refs` (lub `sh_inbound_callbacks` gdy dostępne) przed transakcją.

### Krok 5: Aktualizacja zamówienia
- Ustawić:
  - `payment_status = 'online_paid'` (QR płatność jest zawsze online)
  - `tip_amount = order.tips`
  - `payment_method = 'online'`
  - opcjonalnie zapisać `transaction_id` / `payment_order_num` w dodatkowej kolumnie lub JSON (jeśli istnieją kolumny)
- Zalogować warning, gdy `order.total` != `sh_orders.grand_total` (walidacja kwoty).

### Krok 6: Publikacja eventu
- Opublikować `order.payment_completed` przez `OrderEventPublisher::publishOrderLifecycle()`.
- Przekazać `paymentCustomerDetails` w kontekście.

### Krok 7: Odpowiedź
- Zawsze zwrócić `200 OK` puste body (krytyczne — ChoiceQR inaczej anuluje płatność).

### Krok 8: Weryfikacja
- `php -l` na `pay.php`.
- `node scripts/run_test_runner_headless.cjs` — oczekiwany wynik: `61 pass / 0 fail`.
- Opcjonalnie ręczny test z mock payloadem ChoiceQR.

---

## Prompt dla kolejnego agenta

```
Celem jest wdrożenie naprawy `api/integrations/choiceqr/pay.php` na podstawie oficjalnej dokumentacji ChoiceQR.

Przed przystąpieniem do pracy przeczytaj:
1. `_docs/reports/2026-07-29_choiceqr_pay_fix_plan.md` (ten plik)
2. `_docs/integrations/choiceqr_integration.md`
3. Oficjalne docs: https://open-api.choiceqr.com/docs/content/pos/index.md (sekcja "Pay (split/close) dishes in POS")

Wdróż krok po kroku plan naprawy z sekcji "Plan naprawy `pay.php`" powyżej. Zachowaj obecne wzorce bezpieczeństwa (tenant_id w WHERE, SELECT FOR UPDATE w transakcji, hash_equals dla tokena). Nie zmieniaj innych endpointów ChoiceQR bez wyraźnej potrzeby.

Po zakończeniu:
- Uruchom `php -l` na `pay.php`.
- Uruchom `node scripts/run_test_runner_headless.cjs` (potrzebny CHROME_PATH).
- Oczekiwane: 61 pass / 0 fail / 1 warn (pre-existing).

Jeśli napotkasz niejasności co do payloadu ChoiceQR, nie zgaduj — zapytaj użytkownika.
```

---

## Wdrożenie (2026-07-29) — ZAKOŃCZONE

### Status kroków planu

| Krok | Status | Uwagi |
|------|--------|-------|
| K1 — Przygotowanie i dokumentacja | ✅ | Przeczytano docs + spec + plan |
| K2 — Zmiana payloadu | ✅ | `{ order, varSymbol }` zamiast `{ _id, payment }`; wyciąg `_id`, `total`, `tips`, `paymentCustomerDetails` |
| K3 — Matchowanie zamówienia | ✅ | `id` → fallback `gateway_external_id` (oba z `tenant_id` w WHERE, `FOR UPDATE`) |
| K4 — Idempotencja | ✅ | `rro.transactionId` > `paymentOrderNum` > `pay:{_id}:{total}` |
| K5 — Aktualizacja zamówienia | ✅ | `payment_status='online_paid'`, `payment_method='online'`, `tip_amount=order.tips`, warning na mismatch kwoty |
| K6 — Publikacja eventu | ✅ | `order.payment_completed` z `paymentCustomerDetails` w kontekście |
| K7 — Odpowiedź | ✅ | 200 OK empty body zawsze (nawet przy błędach) |
| K8 — Weryfikacja | ✅ | `php -l` PASS, test runner 61/0/1 |

### Zachowane wzorce bezpieczeństwa

- `hash_equals()` na tokenie `?t=` vs `webhook_token`
- `tenant_id` w każdym WHERE (SELECT, UPDATE, fallback po `gateway_external_id`)
- `SELECT ... FOR UPDATE` w transakcji
- 200 OK empty body zawsze (wymóg ChoiceQR — inaczej anulowanie po 3 próbie)
- Callback logging do `sh_inbound_callbacks` (feature-detect)
- Inne endpointy ChoiceQR (webhook/menu/areas/events/table_orders) nietknięte

### Weryfikacja

| Sprawdzenie | Wynik |
|-------------|-------|
| `php -l pay.php` | **PASS** (No syntax errors detected) |
| `node scripts/run_test_runner_headless.cjs` (CHROME_PATH) | **61 pass / 0 fail / 1 warn** (warn pre-existing, brak regresji) |
| Zgodność z `tablePayOrderSchema` | ✅ wszystkie pola ze schematu obsługiwane |
| Null-safety (`paymentCustomerDetails` opcjonalne) | ✅ `null['key'] ?? ...` bezpieczne w PHP 8.3 |
| Brak pozostałości starego payloadu | ✅ grep: zero odwołań do `$payment`/`$paymentId`/`$payMethod` |

### Zmienione pliki

- `api/integrations/choiceqr/pay.php` — payload + idempotencja + update + event context
- `_docs/integrations/choiceqr_integration.md` — sekcja 10 + nowa sekcja P2.2 + tabela faz

### Powiązane dokumenty

- Integracja: `_docs/integrations/choiceqr_integration.md` (sekcja P2.2)
- Oficjalna specyfikacja: https://open-api.choiceqr.com/docs/content/pos/index.md (sekcja "Pay (split/close) dishes in POS")
