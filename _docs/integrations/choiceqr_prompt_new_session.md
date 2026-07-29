# PROMPT — kontynuacja integracji ChoiceQR w nowej sesji

## Skopiuj i wklej do nowej konwersacji:

---

Kontynuujemy integrację ChoiceQR POS z SliceHub. Pełna analiza API i plan są w `_docs/integrations/choiceqr_integration.md` — przeczytaj ten plik najpierw.

Dokumentacja API ChoiceQR: https://open-api.choiceqr.com/docs#/content/pos/index

**Co zostało zrobione:**
- Przeanalizowano pełną dokumentację API ChoiceQR (POS integration, Order schema, Order methods, Webhooks, Authorization)
- Zidentyfikowano model integracji: ChoiceQR pushuje do nas (odwrócony model)
- Zmapowano wszystkie pola ChoiceQR → SliceHub
- Utworzono plan wdrożenia w `_docs/integrations/choiceqr_integration.md`

**Co trzeba zrobić (P0 — MVP):**
1. Stworzyć `api/integrations/choiceqr/webhook.php` — odbiór zamówień z ChoiceQR (POST, mapowanie OrderSchema → sh_orders + sh_order_lines, idempotency przez order._id, auth tokenem w URL, tenant mapping przez varSymbol)
2. Stworzyć `api/integrations/choiceqr/menu.php` — export menu do ChoiceQR (GET, kategorie + dania z posID=ascii_key, ceny w groszach)
3. Stworzyć `api/integrations/choiceqr/areas.php` — export stref/stolików (GET)
4. Testy curl

**Kluczowe decyzje:**
- Skip CartEngine dla choiceqr — ufamy totalom ChoiceQR
- posID w ChoiceQR = ascii_key w SliceHub
- Auth webhooka: token w URL path (ChoiceQR nie ma HMAC)
- Tenant mapping: varSymbol → tenant_id z sh_tenant_integrations

**Infrastruktura gotowa w SliceHub:**
- `api/gateway/intake.php` — wzorzec order intake
- `core/GatewayAuth.php` — storeExternalRef/lookupExternalRef
- `core/Integrations/BaseAdapter.php` — adapter framework
- `core/OrderEventPublisher.php` — transactional outbox

Zacznij od P0 — webhook.php (odbiór zamówień). Użyj wzorców z `api/gateway/intake.php` ale bez CartEngine — ufamy cenom z ChoiceQR. Zwracamy 200 OK empty body (wymóg ChoiceQR).

---
