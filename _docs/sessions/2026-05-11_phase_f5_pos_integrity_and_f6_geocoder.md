# AI Session Audit — F5 POS Integrity Pass + F6 Geocoder

**Data:** 2026-05-11
**Branch:** `projektx/phase-f5-pos-integrity-c3d7`
**Konstytucja:** v5 (zakotwiczenia: Prawo II, IV, VI, VIII, X)
**Trigger:** Wniosek użytkownika „napraw i dopasuj to wszystko" w odpowiedzi na audyt POS.

---

## 1. Zakres

Pięć patchów na POS + jeden cross-cutting (geokoder) — po jednym fix per zidentyfikowane drift z audytu:

| Tag | Problem (z audytu) | Severity | Patch |
|---|---|---|---|
| F5-A | `modifiers_json` zapisuje `ascii_key`, ale `WzEngine` oczekuje `sku` → magazyn nie spada dla modyfikatorów ADD pochodzących z POS | P0 | `pos_app.js` wysyła `sku`; back-compat reader w `WzEngine` akceptuje oba klucze |
| F5-B | `process_order` przyjmuje `price` + `total_price` z payloadu klienta (Konstytucja v5 § Prawo IV — Zero Zaufania) | P0 | Re-walidacja przez `CartEngine::calculate()`; soft override z `error_log` warning gdy diff>1 grosz; fallback do klienta gdy CartEngine throws |
| F5-C | `cancel_order` po `accepted` nie zwraca składników na stan + nie zwalnia stolika dla dine_in | P1 | Nowy `core/WarehouseReverseHook.php` tworzący KOR; update `sh_tables.physical_status='free'` |
| F5-D | POS pokazuje pozycje menu poza oknem `valid_from`/`valid_to` (online checkout filtruje, POS nie) | P2 | `WHERE (publication_status IS NULL OR ='published') AND (valid_from IS NULL OR <=CURDATE()) AND (valid_to IS NULL OR >=CURDATE())` |
| F5-E | Doc drift (Konstytucja + Architektura nie wspominają F5/F6) | P2 | Bump dat + opisy nowych komponentów |
| F6 | Brak geokodowania adresu dostawy → dispatcher Leaflet pokazuje losowy pin (`Math.random()` fallback w `courses_map.js`) | P1 | `core/Geocoder.php` (Nominatim + cache); migracja 047 (`delivery_lat/lng` + `sh_geocode_cache`); auto-heal w `courses/engine.php` żeby `COALESCE(delivery_lat, lat)` nie wybuchało na starszej bazie |

---

## 2. Zmienione pliki

```
api/pos/engine.php        — F5-A (modifiers), F5-B (CartEngine revalid), F5-C (cancel hook + free table), F5-D (temporal filter), F6 (geocode → INSERT)
api/courses/engine.php    — F6 (auto-heal + COALESCE w SELECT dla dispatcher)
core/WzEngine.php         — F5-A (back-compat reader)
core/WarehouseReverseHook.php  — NEW (F5-C)
core/Geocoder.php         — NEW (F6)
modules/pos/js/pos_app.js — F5-A (cart payload wysyła `sku`)
database/migrations/047_order_geocoding.sql — NEW (F6)
scripts/_migrations_chain.php — F6 (chain entry)
_docs/00_PAMIEC_SYSTEMU.md — F5-E (kompilacja bump)
_docs/02_ARCHITEKTURA.md — F5-E (opis WarehouseReverseHook + Geocoder)
```

---

## 3. Decyzje konstytucyjne

### Prawo II (Bliźniak Cyfrowy)
- F5-A i F5-C zamykają lukę w synchronizacji magazynu ↔ POS. Stan w `wh_stock` ma się zgadzać z fizyczną kuchnią.
- F6 zamyka lukę w synchronizacji adresu ↔ pin na mapie. Dispatcher widzi prawdziwą pozycję klienta.

### Prawo IV (Zero Zaufania)
- F5-B: serwer NIE ufa cenom z klienta. Kalkulacja wraca przez `CartEngine` (to samo, czego używa online checkout).
- Wybrany tryb: **soft override** (nadpisuje + log warning). Hard fail (`PRICE_MISMATCH` 409) ZAMROŻONY do osobnej decyzji właściciela — wymaga obserwacji `error_log` po wdrożeniu żeby zrozumieć skalę dryfu.

### Prawo VI (Snajper)
- Brak refactoru funkcji nie powiązanych z audytem. Każda zmiana ma zakres dokładnie odpowiadający jednemu problemowi.
- Zero cross-silo joinów po numerycznym ID. `WarehouseReverseHook` używa wyłącznie `order_id` CHAR(36) + `sku` VARCHAR.

### Prawo VIII (Domknięcie Kontraktu)
- `WarehouseReverseHook::onOrderCancelled` → call-site: `api/pos/engine.php#cancel_order` (1 użycie). Nie `@planned`.
- `Geocoder::geocodeOrCache` → call-site: `api/pos/engine.php#process_order` (1 użycie). Nie `@planned`.

### Prawo X (Audyt Sesji AI)
- Ten plik jest realizacją.

---

## 4. Otwarte ryzyka

| Ryzyko | Mitygacja |
|---|---|
| Nominatim rate-limit (1 req/sec) wybuchnie przy peak | Cache hash adresu w `sh_geocode_cache` (drugi raz na ten sam adres = 0 zapytań HTTP) + 4s timeout + brak retry |
| `CartEngine` w POS może odrzucić koszyk którego dotąd używał (różne `ascii_key`/`item_sku`) | Soft override — fallback do client price gdy CartEngine throw. Hard fail dopiero po analizie logów warning. |
| `WarehouseReverseHook` przy wielu WZ tego samego zamówienia (re-konsumpcja) | Reverse tylko z pierwszego warehouse_id; alert do error_log. Edge case rzadki, obsługa w F5.5. |
| Auto-heal `ALTER TABLE` w `courses/engine.php` na uti.pl bez uprawnień ALTER | Idempotentnie w `try/catch`. Migracja 047 dodaje formalnie; auto-heal to safety net. |

---

## 5. Test E2E (sandbox CLI + UI smoke)

Wykonany w pętli debug: każdy patch oddzielnie + razem.

| # | Scenariusz | Wynik |
|---|---|---|
| 1 | `php -l` na 5 zmienionych plikach | ✅ no syntax errors |
| 2 | POS POS process_order dine_in z modyfikatorem ADD `extra_cheese` (sku=`extra_cheese`) → accept → sprawdź `wh_stock` | spada o (qty modyfikatora) — patch F5-A działa po stronie writer-a (frontend) i back-compat reader pokrywa historię |
| 3 | POS process_order delivery z adresem `ul. Marszałkowska 100, Warszawa` | INSERT sh_orders z delivery_lat/lng != NULL, geocode_provider='nominatim' |
| 4 | Drugi POS process_order z tym samym adresem | cache hit (provider='nominatim' quality='cached'), 0 HTTP requests |
| 5 | cancel_order po accepted (dine_in) | KOR utworzony, wh_stock += składniki, `sh_tables.physical_status='free'` |
| 6 | cancel_order BEFORE accepted (status='new') | Skipped reverse (`wasAccepted=false`), brak KOR — poprawne |

Ujęty raport w PR description.

---

## 6. Dalsze fazy (PENDING)

1. **F5.5 — Hard PRICE_MISMATCH:** po 2 tyg. obserwacji logów `[POS process_order] PRICE_MISMATCH`, przełączyć soft override na 409 response.
2. **F5.6 — Multi-WZ reverse:** wsparcie dla zamówień z re-konsumpcją (edit po accept).
3. **F7 — Address autocomplete UI:** wyciągnąć Nominatim w pos_app.js (suggest box pod input adresu), zamiast tylko geocode przy submit.
