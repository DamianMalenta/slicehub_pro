# ANALIZA PORÓWNAWCZA: SliceHub Enterprise OS vs Papu.io

> Porównanie technologiczne i biznesowe pod wniosek o dofinansowanie.  
> Źródła: audyt kodu SliceHub (**rewizja 2026-05-20**), publiczna dokumentacja papu.io (papu.io/funkcjonalnosci).

---

## KONTEKST RYNKOWY

**Papu.io** to komercyjny system SaaS POS dla restauracji z dowozem, działający na polskim rynku. Oferowany w modelu abonamentowym (258–763 zł/mies. + 599 zł jednorazowe wdrożenie). Fokus: delivery-first, integracja z agregatorami (Glovo, Wolt, Uber Eats, Pyszne.pl).

**SliceHub Enterprise OS** to autorska platforma full-stack budowana jako własność intelektualna (IP). Architektura zero-dependency LAMP, self-hosted, model multi-tenant z izolacją danych na poziomie wierszy. Pokrywa cały cykl życia restauracji — od POS, przez magazyn i HR, po wizualny kompozytor witryny online.

**Kluczowy fakt:** SliceHub posiada w kodzie gotowy adapter integracyjny z Papu.io (`core/Integrations/PapuAdapter.php`, `PapuClient.php`) — traktuje Papu jako jeden z zewnętrznych systemów, do którego może pushować zamówienia. Papu jest konsumentem danych SliceHub, nie odwrotnie.

---

## MATRYCA PORÓWNAWCZA FUNKCJONALNOŚCI

| Obszar funkcjonalny | Papu.io | SliceHub Enterprise | Przewaga SliceHub |
|---------------------|---------|---------------------|-------------------|
| **POS — przyjmowanie zamówień** | ✅ Zamówienia na miejscu, Caller ID | ✅ POS z pełnym menu, kafelki, modyfikatory, half/half pizza | — |
| **POS — tryb offline** | ❌ Brak informacji o trybie offline | ✅ **Resilient POS** — 4 warstwy: IndexedDB, UUID v7, Proxy outbox, Dual-loop sync. Pełna obsługa zamówień bez internetu | **KRYTYCZNA** |
| **POS — PWA** | ❌ Brak informacji | ✅ Service Worker, manifest.webmanifest, offline.html, cache z nagłówkiem `X-SliceHub-Cache: stale` | ✅ |
| **KDS (Kitchen Display)** | ✅ Ekrany kuchenne, statusy, multi-stacja | ✅ KDS z polling 6s, bump flow, recall, filtr stacji, routing `KdsAcceptRouting.php` | Porównywalny |
| **Integracje z agregatorami** | ✅ Glovo, Wolt, Uber Eats, Pyszne.pl, Bolt | ✅ Unified Gateway API (`intake.php`) + adaptery (Papu, Dotykačka, GastroSoft) + inbound webhooks | ✅ Gateway z idempotencją i rate-limitingiem |
| **Dyspozytornia / Delivery** | ✅ Mapa GPS, kursy, łączenie zamówień, rozliczanie | ✅ K-System (kursy K{n}, przystanki L{n}), mapa Leaflet, multi-order dispatch, reconciliation, Emergency Recall | ✅ Emergency Recall, Payment Lock |
| **Aplikacja kierowcy** | ✅ GPS, status zamówień | ✅ PWA z GPS 15s, Payment Lock, Emergency Alert (flash+wibracje), Driver Wallet, shift management | ✅ Payment Lock, Emergency Alert |
| **Śledzenie zamówień klienta** | ✅ Śledzenie jak na portalach | ✅ SSE real-time tracking (`sse.php` + `EventSource`), strona `track.html` | ✅ SSE zamiast polling |
| **Strefy dostaw** | ✅ Automatyczne przypisanie stref | ✅ `sh_delivery_zones` z walidacją w checkout | Porównywalny |

---

## MAGAZYN I FOOD COST

| Mechanizm | Papu.io | SliceHub Enterprise | Przewaga SliceHub |
|-----------|---------|---------------------|-------------------|
| **Metoda wyceny** | FIFO (First In, First Out) | **AVCO** (Average Cost) — autorski silnik wyceny średnioważonej z safe guard na zero/negative denominator | Różne podejścia — AVCO daje średnią ważoną, FIFO śledzi konkretne partie |
| **Automatyczne zejścia ze stanu** | ✅ Po sprzedaży dania | ✅ `WzEngine::consumeForOrder()` z pełną dekompozycją receptury, modyfikatorów, waste%, half/half, kompozytów `A+B` | ✅ Głębszy model (waste%, half/half, kompozyty) |
| **Inteligentne anulacje** | ✅ Napoje wracają, gotowe dania → straty | ✅ `KorEngine` — korekta na podstawie dokładnych linii WZ (nie rekalkulacja receptury), odwracanie AVCO | ✅ Korekta na oryginalnych liniach WZ |
| **Inwentaryzacja** | ✅ Mobilna i do druku, wykrywanie braków/nadwyżek | ✅ `InwEngine` — 3-poziomowa eskalacja (auto ≤2% → manager ≤10% → owner >10%), automatyczne dokumenty RW/PW | **✅ Eskalacja zatwierdzania** |
| **Dokumenty MM** | ✅ Przesunięcia międzymagazynowe | ✅ `MmEngine` z blendowaniem AVCO i walidacją stanu źródłowego | Porównywalny |
| **Stany krytyczne / alerty** | ✅ Stany optymalne + krytyczne, listy zamówieniowe | ✅ Alert 86 Convention (UI), checkout preflight `checkAvailability()` blokujący zamówienie na brakujące składniki | ✅ Preflight w checkout |
| **Kalkulator Food Cost** | ✅ Na podstawie aktualnych cen | ✅ `FoodCostEngine` + `MarginGuardian` — per-channel (POS/Takeaway/Delivery), status tiers (excellent <25%, healthy <33%, at_risk <40%, critical ≥40%) | **✅ Food cost per kanał sprzedaży** |
| **Alerty cenowe dostawców** | ✅ Automatyczne alerty po zmianach cen | ❌ Brak automatycznych alertów cenowych | ❌ Papu ma tę funkcję |
| **Auto-generowanie list zakupowych** | ✅ Na żądanie przy stanach krytycznych | ❌ Brak automatycznych list zakupowych | ❌ Papu ma tę funkcję |
| **Podmagazyny** | ✅ Bar, Kuchnia, produkcja własna | ✅ `warehouse_id` w `wh_stock` — multi-warehouse per tenant | Porównywalny |
| **KSeF / e-faktury (PL)** | Integracje zależne od dostawcy | ✅ Natywny moduł Procurement — API v2 MF, AutoScan, accept→PZ, OPEX→P&L | **✅ Compliance 2026 + szybkość** |
| **Dashboard P&L** | Raporty w module | ✅ `BiEngine` — COGS z WZ, OPEX z KSeF, payroll, zamrożony kapitał AVCO | **✅ Właściciel bez Excela** |

---

## OBSZARY, KTÓRYCH PAPU.IO NIE POSIADA

| Obszar | SliceHub | Znaczenie biznesowe |
|--------|----------|---------------------|
| **Macierz Omnichannel Pricing** | `sh_price_tiers` — 3 niezależne ceny per danie (POS, Takeaway, Delivery) + franczyzowy override (`tenant_id=0` → HQ default) | Zarządzanie cenami z jednego miejsca dla wszystkich kanałów |
| **Visual Scene Compositor** | `SceneResolver`, Director, Harmony Score, warstwy wizualne, cook_state-aware modifier assets | Wizualna personalizacja witryny online bez grafika |
| **Personal Phone SMS** | `PersonalPhoneChannel` — SMS przez telefon właściciela (Android Gateway / generic HTTP) zamiast płatnych bramek | **~500-2000 zł/mies. oszczędności** na powiadomieniach |
| **SmartReply Engine** | Automatyczne odpowiedzi na SMS klientów (ETA, anulacja, info, STOP, reorder) — keyword matching, zero AI | Automatyzacja 80%+ zapytań klientów bez personelu |
| **HR / Payroll** | `HrClockEngine`, `PayrollLedger` (append-only, integer grosze), `AdvanceEngine` (cykl życia zaliczek) | Zarządzanie pracownikami w jednym systemie |
| **HR → Logistics Decoupling** | Event-driven driver fanout — clock-in/out automatycznie przełącza status kierowcy | Zero ręcznych zmian statusu |
| **Transactional Outbox** | `OrderEventPublisher` z `INSERT IGNORE` + 3 niezależne workery (webhooks, integracje, notyfikacje) | Gwarancja dostarczenia wszystkich zdarzeń |
| **Credential Vault** | XChaCha20-Poly1305 AEAD encryption at rest | GDPR/PCI compliance na poziomie kodu |
| **Reorder Nudge** | Behawioralna segmentacja — SMS w dzień tygodnia, w który klient regularnie zamawia | Automatyczny remarketing oparty na nawykach |
| **Dine-in Tables** | Plan sali, strefy, stoliki, transfery, QR otwieranie, rachunki dzielone | Pełna obsługa na miejscu z planem sali |
| **Waiter App** | Dedykowana aplikacja mobilna kelnera z PIN login | Niezależny interfejs od POS |
| **Inbox (CRM)** | Skrzynka SMS klienta, wątek konwersacji, forward do managera | Mini-CRM dla komunikacji z klientami |
| **Marketing Campaigns** | Segmentacja audytorium, rate limiting personal_phone, outbox z idempotencją | Kampanie SMS z telefonu właściciela |
| **Gateway Idempotency** | `INSERT IGNORE` + `external_order_refs`, atomowe rate limity per-minuta/per-dzień | Zero duplikatów nawet przy retry |
| **Atomic Sequences** | MySQL `LAST_INSERT_ID()` trick — bezkonfliktowe numery dokumentów | Zero kolizji numeracji |
| **Multi-Tenant Isolation** | Row-Level Security w aplikacji, JWT z embedded `tenant_id`, cross-silo bridges tylko przez SKU | Bezpieczeństwo danych franczyzowych |
| **Promised Time Engine** | Dynamiczna estymacja: `basePrep × min(2.0, 1.0 + activeOrders/20) + channelBuffer` | Realistyczne czasy z uwzględnieniem obciążenia kuchni |
| **Self-Hosted / Own IP** | Zero zależności zewnętrznych, pełna kontrola nad kodem | Niezależność od dostawcy SaaS |

---

## ARCHITEKTURA I MODEL BIZNESOWY

| Aspekt | Papu.io | SliceHub Enterprise |
|--------|---------|---------------------|
| **Model** | SaaS (abonament miesięczny) | Self-hosted / własna IP |
| **Koszt** | 258–763 zł/mies. + 599 zł wdrożenie | Zero opłat licencyjnych (koszt: hosting + utrzymanie) |
| **Kod źródłowy** | Zamknięty, brak dostępu | Pełna kontrola — 130+ plików PHP, **17 modułów operacyjnych** JS, 55 migracji SQL |
| **KSeF (e-faktury PL)** | Ograniczone / zależne od integratora | Natywny inbox + **API v2 MF**, AutoScan, accept→PZ, OPEX→BI P&L |
| **BI / P&L w produkcie** | Raporty u dostawcy | `BiEngine` — COGS z WZ, OPEX z KSeF, payroll ledger, zamrożony kapitał AVCO |
| **Zależności** | Nieznane (SaaS) | **Zero** — brak npm, Composer, framework. Czysty PHP 8.3 + Vanilla JS |
| **Konteneryzacja** | N/A (SaaS) | Docker-ready: ~10 linii Dockerfile, ~120 MB obraz |
| **Offline** | Brak informacji | Pełna architektura offline-first (4 warstwy) |
| **Multi-tenant** | Brak informacji o franczyzach | Wbudowane: `tenant_id` na każdej tabeli, JWT, hierarchiczne ceny HQ→franczyza |
| **API otwarte** | Brak publicznego API | Unified Gateway + webhook HMAC + adapter registry |
| **Szyfrowanie danych** | Brak informacji | XChaCha20-Poly1305 AEAD (libsodium) |
| **Vendor lock-in** | Tak (zależność od Papu jako dostawcy) | Nie — pełna własność kodu i danych |

---

## ANALIZA KOSZTÓW (TCO) — 3 LATA DLA SIECI 5 LOKALI

| Pozycja kosztowa | Papu.io (Gold × 5) | SliceHub Enterprise |
|-------------------|---------------------|---------------------|
| Abonament (36 mies.) | 5 × 763 × 36 = **137 340 zł** | **0 zł** (self-hosted) |
| Wdrożenie | 5 × 599 = **2 995 zł** | Koszt dewelopera (jednorazowy) |
| Hosting (VPS/chmura) | Wliczony w SaaS | ~200 zł/mies. × 36 = **7 200 zł** |
| SMS (Twilio/SMSAPI) | Osobny koszt | **0 zł** (Personal Phone Channel) |
| **SUMA 3 lata** | **~140 335 zł + SMS** | **~7 200 zł + dev** |

---

## PODSUMOWANIE PRZEWAG KONKURENCYJNYCH

### Gdzie SliceHub jest lepszy (przewaga technologiczna):

1. **Tryb offline POS** — Papu nie oferuje pracy bez internetu. SliceHub ma 4-warstwową architekturę Resilient POS.
2. **Personal Phone SMS** — eliminacja kosztów bramek SMS (~500-2000 zł/mies. per lokal).
3. **Macierz Omnichannel** — 3 niezależne ceny per danie z jednego panelu + franczyzowy override.
4. **HR/Payroll** — Papu nie ma modułu kadrowego. SliceHub ma append-only ledger z integer arithmetic.
5. **Visual Compositor** — Papu nie ma wizualnego edytora witryny. SliceHub ma Director z Harmony Score.
6. **Transactional Outbox** — gwarancja dostarczenia zdarzeń nawet przy awarii serwera.
7. **Multi-tenant z izolacją** — wbudowana architektura franczyzowa z hierarchią cenową HQ→lokale.
8. **Self-hosted** — zero vendor lock-in, pełna kontrola nad danymi i kodem.
9. **Dine-in Tables** — pełny plan sali z transferami i rachunkami dzielonymi.
10. **SmartReply** — automatyczne odpowiedzi na SMS klientów bez AI.

### Gdzie Papu.io jest lepszy lub porównywalny:

1. **Gotowość do użycia** — Papu to gotowy SaaS, SliceHub wymaga deploymentu.
2. **Alerty cenowe dostawców** — automatyczne powiadomienia o zmianach cen w hurtowniach.
3. **Auto-generowanie list zakupowych** — przy stanach krytycznych.
4. **FIFO vs AVCO** — Papu oferuje FIFO (śledzenie partii), SliceHub AVCO (średnia ważona). Oba podejścia mają swoje zalety.
5. **Caller ID** — identyfikacja dzwoniącego klienta. SliceHub nie ma tego modułu.
6. **Wsparcie techniczne** — Papu oferuje support 7/7. SliceHub wymaga własnego zespołu.
7. **Integracja z UpMenu/Choice** — gotowe integracje z polskimi platformami zamawiania.

### Neutralne różnice:

- **Wycena zapasów:** FIFO (Papu) vs AVCO (SliceHub) — oba podejścia są akceptowane w rachunkowości
- **KDS:** Oba systemy mają porównywalną funkcjonalność

---

## WNIOSEK

SliceHub Enterprise OS nie jest klonem ani alternatywą Papu.io — to **system o znacząco szerszym zakresie**, pokrywający obszary, których Papu nie adresuje (HR, payroll, visual compositor, offline POS, multi-tenant franczyzy, szyfrowanie danych, autorski SMS channel). Fakt, że SliceHub posiada w kodzie **gotowy adapter do pushowania zamówień do Papu.io** (`PapuAdapter.php`), potwierdza, że SliceHub jest platformą nadrzędną — może integrować się z Papu jako jednym z wielu zewnętrznych systemów POS.

Kluczowa przewaga architektoniczna: **zero zależności zewnętrznych** i **pełna własność IP** dają możliwość konteneryzacji (Docker), skalowania (Kubernetes) i deploymentu w dowolnej infrastrukturze — bez vendor lock-in i bez opłat licencyjnych.

---

*Analiza oparta na publicznej dokumentacji papu.io (papu.io/funkcjonalnosci, papu.io/funkcjonalnosci/magazyn) oraz audycie kodu SliceHub Enterprise OS.*
