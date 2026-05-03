# Audyt Settings Panel — dokumentacja ↔ backend ↔ UI

**Data:** 2026-05-03  
**Źródła:** `_docs/13_SETTINGS_PANEL.md`, `api/settings/engine.php`, `modules/settings/*`

---

## 1. Executive summary

Panel Settings jest **wdrożony funkcjonalnie szerzej niż opisuje pojedynczy dokument 13**: backend obejmuje **Inbound** oraz **Notification Director** (kanały, routing, szablony), UI ma **8 zakładek** zamiast 5 z tabeli w §1. Rdzeń z §1 (Integrations, Webhooks, API Keys, DLQ, Health) jest **spójny** z kodem. **CSRF, rate limit Test Ping i audit** są w kodzie; dokument 13 §8 opisuje je jako wdrożone. Roadmapa §12 „otwarte 7.7+” pozostaje aktualna jako lista usprawnień produktowych, nie bloków startu.

---

## 2. Macierz zgodności

| Obszar | Dokument 13 (§1 / §4) | `engine.php` | UI (`index.html` + JS) |
|--------|------------------------|--------------|-------------------------|
| **Integrations** — list/save/toggle/delete/test_ping | Tak | Tak (`integrations_*`) | Tak (`settings_app.js`) |
| **Webhooks** — list/save/toggle/delete/test_ping | Tak | Tak (`webhooks_*`) | Tak |
| **API Keys** — list/generate/revoke | Tak | Tak (`api_keys_*`) | Tak |
| **DLQ** — list/replay | `sh_event_outbox` + `sh_integration_deliveries` dead | Tak: webhook DLQ = **outbox** `status=dead`; integracje = **`sh_integration_deliveries`** | Tak (kanały webhooks / integrations) |
| **Health** — summary | Podstawowy snapshot | Rozszerzone: **inbound** (24h), **plaintext** counter, vault, outbox 7d | Tak |
| **Inbound callbacks** | Opisany w 14, nie w tabeli §1 | **`inbound_list`** (read-only) | **Zakładka Inbound** |
| **Powiadomienia** | Nie w §1 (osobny kierunek m033) | **`notifications_*`** (channels, routes, templates, test) | **`notifications.js`** + zakładka Powiadomienia |
| **CSRF** | §8 ✅ (zsynchronizowano z kodem) | **`settings_csrfCheck`** + `csrf_token` | **`settings_app.js`** + **`notifications.js`** |
| **Rate limit Test Ping** | §8 ✅ max 5/min | **Max 5/min** per tenant (`sh_settings_audit`) | Pośrednio (backend odrzuca 429) |
| **Audit mutacji** | §12 DONE | **`settings_audit`** → `sh_settings_audit`; **`audit_log_list`** | **Zakładka Dziennik** — tabela + JSON (`audit_log_list`) |

---

## 3. Backend — akcje poza skrótem z dokumentu

Pełna lista rozszerzeń względem tabeli z §4 dokumentu 13:

- `csrf_token` — bootstrap sesji (opisane w §13 dokumentu 13).
- `inbound_list` — lista `sh_inbound_callbacks` + `counts_24h` (Health już agreguje inbound).
- `audit_log_list` — ostatnie N wpisów z `sh_settings_audit` (read-only, bez CSRF).
- `notifications_channels_list` | `notifications_channels_upsert` | `notifications_channels_delete` | `notifications_channels_test`
- `notifications_routes_get` | `notifications_routes_set`
- `notifications_templates_get` | `notifications_templates_set`

**Uwaga:** Handlery `notifications_*` są **poniżej** głównego `switch`; **`settings_csrfCheck`** wykonuje się **przed** switch dla każdej akcji — mutacje powiadomień wymagają poprawnego nagłówka `X-CSRF-Token` (frontend to realizuje).

---

## 4. UI — zakładki

| Zakładka | Plik | Status |
|----------|------|--------|
| Integrations | `settings_app.js` | Gotowe |
| Webhooks | `settings_app.js` | Gotowe |
| API Keys | `settings_app.js` | Gotowe |
| Dead Letters | `settings_app.js` | Gotowe |
| **Inbound** | `settings_app.js` (`renderInbound`) | Gotowe — **poza opisem §1 dokumentu 13** |
| Health | `settings_app.js` | Gotowe |
| **Powiadomienia** | `notifications.js` | Gotowe — **poza opisem §1 dokumentu 13** |
| **Dziennik** | `settings_app.js` (`renderAuditLog`) | Gotowe — read-only `audit_log_list` |

Flow sekretów (`showRevealSecret` / modal „Copy once”) — zgodny z intencją §5 dokumentu 13 dla webhooków i kluczy API.

---

## 5. Nieścisłości dokumentacji (`13_SETTINGS_PANEL.md`)

Stan po synchronizacji (**2026-05-03**): dokument **13** zaktualizowany — tabela 8 zakładek (w tym Dziennik), architektura dwóch plików JS, sekcje Inbound/Powiadomienia/Dziennik, §8 CSRF+rate limit jako ✅ (bez sprzecznych TODO).

Historycznie:
1. ~~§1 — tylko 5 zakładek~~ → naprawione (8 z Dziennikiem).
2. ~~§5 — jeden plik ~600 LoC~~ → naprawione (`settings_app.js` + `notifications.js`).
3. ~~§8 TODO CSRF/rate limit~~ → naprawione (odsyłacz do §12–§13).
4. **§6 Test Ping** — literówka **`X-Slicehup-Timestamp`** w `engine.php` **poprawiona** na `X-Slicehub-Timestamp` (2026-05-03).

---

## 6. Roadmapa z dokumentu 13 §12 — status „otwarte”

Nadal adekwatne jako **nice-to-have**, nie jako brak MVP Settings:

| Punkt | Opis |
|-------|------|
| Webhook delivery inspector | Pełna historia HTTP w UI z paginacją |
| Provider test suite | Cron/sandbox ping aktywnych integracji |
| Scope picker dla API Keys | Checkboxy zamiast tekstu |
| Multi-tenant admin view | Superadmin przeglądający wszystkich tenantów |
| Auto-register subscription u providera | Papu/Uber API |

---

## 7. Wnioski i rekomendacje

1. **Produktowo:** Settings jest **używalny** dla integracji, webhooków, kluczy API, DLQ, health, inbound read-only oraz powiadomień — **powyżej** minimalnego zakresu sesji 7.5 z dokumentu 13.
2. **Dokumentacja:** **`13_SETTINGS_PANEL.md`** zsynchronizowany z kodem (2026-05-03).
3. **Operacyjnie:** Zakładka **Dziennik** + `audit_log_list` pokazuje ostatnie wpisy z `sh_settings_audit` w UI (2026-05-03).
4. **Technicznie:** Literówka nagłówka timestamp przy webhook Test Ping — **naprawiona** w `api/settings/engine.php`.

---

## 8. Dziennik kroków (inkrementalny)

| Data       | Krok | Zmiana |
|------------|------|--------|
| 2026-05-03 | 1    | Utworzono ten audyt (`AUDIT_SETTINGS_PANEL.md`). |
| 2026-05-03 | 2    | `api/settings/engine.php`: poprawka nagłówka `X-Slicehub-Timestamp` (Test Ping webhook). |
| 2026-05-03 | 3    | `_docs/13_SETTINGS_PANEL.md`: 7 zakładek, Inbound/Powiadomienia, architektura 2× JS, §8 CSRF/rate limit, rozszerzone `health_summary` i tabele akcji; link do audytu. |
| 2026-05-03 | 4    | **Dziennik (opcja A):** `api/settings/engine.php` — `audit_log_list`; UI — zakładka Dziennik, `renderAuditLog`, style w `style.css`; dokumentacja 13 i ten audyt zsynchronizowane (8 zakładek). |
| 2026-05-03 | 5    | **North Star — eventy rozliczenia:** `api/payments/settle.php` — przed `COMMIT` wywołanie `OrderEventPublisher::publishOrderLifecycle`: `order.completed` gdy auto-complete (`ready`→`completed`), w przeciwnym razie `payment.settled` (split tender w `_context`). Zaktualizowano `_docs/02_ARCHITEKTURA.md`, `_docs/09_EVENT_SYSTEM.md`. |

*Następne priorytety North Star (poza tym commitem): backlog modułowy z `OPTIMIZED_CORE_LOGIC_V2.md` / `_docs/17_OFFLINE_POS_BACKLOG.md` — walidacja we własnym lokalu.*

---

**Powiązane:** `_docs/13_SETTINGS_PANEL.md`, `_docs/14_INBOUND_CALLBACKS.md`, `_docs/09_EVENT_SYSTEM.md`
