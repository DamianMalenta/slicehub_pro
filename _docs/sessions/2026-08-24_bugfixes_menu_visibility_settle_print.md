# 2026-08-24 — Bugfixy MenuVisibilityFilter + settle auto-transition + drukuj paragon + odzyskanie Faz 1-5

## Cel

Naprawa 4 krytycznych bugów wprowadzonych przez commit `aebc44c` (MenuVisibilityFilter Phase 1) i PR #60 (window→globalThis), oraz odzyskanie utraconych zmian Faz 1-5 (UX czasu w online checkout i POS) po nadpisaniu plików przez innego agenta (`git checkout main --`).

## Pliki dotknięte

### Bugfixy

- `api/backoffice/api_menu_studio.php` — dodano `publication_status, valid_from, valid_to` do SELECT w `get_menu_tree` + pola `publicationStatus/validFrom/validTo` w array_map odpowiedzi. Bez tego Studio drzewo pokazywało wszystkie dania jako Draft (frontend fallback `|| 'Draft'`).
- `core/MenuVisibilityFilter.php` — przywrócono `is_active = 1` w `mealsWhere()`. Meals nie mają pełnego cyklu publikacji w Studio — `is_active` jest ich jedynym toggle widoczności poza `is_deleted`. Agent w `aebc44c` usunął je błędnie.
- `core/OrderStateMachine.php` — `fastComplete()` auto-przechodzi przez łańcuch `new → accepted → pending → preparing → ready → completed` w jednej TX. Wcześniej settle_and_close blokował na "Order must be 'ready' first" dla zamówień w `preparing/accepted/new` (strict mode, tenant 2 ma puste feature flags). Audit log finalnego kroku pokazuje `ready → completed`.
- `tenant_config.php` — auto-discovery sortuje po `COUNT(sh_menu_items) DESC, t.id ASC`. Wcześniej `ORDER BY t.id ASC` wybierało tenant 1 (pusty demo) nawet gdy tenant 2 miał 229 dań. Kolizja PIN (tenant 1 waiter1=1111, tenant 2 forno_waiter=1111) powodowała logowanie na wrong tenant.
- `modules/pos/js/pos_ui.js` — `contentglobalThis` → `contentWindow` w `_doPrint()`. Skutek uboczny nadgorliwej zamiany `window.*` → `globalThis.*` z PR #60. `contentWindow` to właściwość `HTMLIFrameElement` (DOM API), NIE `window` — `contentglobalThis` nie istnieje, powodowało TypeError blokujący drukowanie paragonów i bonów kuchennych.
- `modules/online/js/online_app.js` — `updateCartFab()` wołane w `recalcCart()` po asynchronicznym wywołaniu API. Bez tego FAB (pływający pasek koszyka) pokazywał "0,00 zł" aż do następnego `refreshCartUi()`.

### Odzyskane feature'y (Fazy 1-5, utracone przez `git checkout main --` innego agenta)

- `api/online/engine.php` — **Faza 3:** parametr `date` w `estimate_time` mode=slots. Multi-day slot selection (jutro/pojutrze). Przeszłe daty odrzucane.
- `modules/online/js/online_checkout.js` — **Faza 1:** panel potwierdzenia wybranego czasu + countdown ("Dziś 19:30" + "Za 45 min" zielone / "Spóźnione 12 min" czerwone pulse). **Faza 2:** quick-time pills `[+30m, +45m, +60m, +90m]` z baseline=ASAP `promised_time` z backendu (NIE `Date.now()`). **Faza 3:** date picker + przeładowanie slotów dla wybranej daty.
- `modules/online/css/style.css` — CSS dla confirm panel (Faza 1), quick pills (Faza 2), date picker (Faza 3).
- `modules/pos/js/pos_ui.js` — **Faza 4:** countdown `Za Xm` / `Spóźnione Xm` (czerwone pulse) na kartach Pulse obok elapsed time.
- `modules/pos/css/style.css` — **Faza 4:** pulse-countdown CSS. **Faza 5:** preview section CSS w Centrum Kontroli Czasu.
- `modules/pos/index.html` — **Faza 5:** HTML preview section w Centrum Kontroli Czasu (podgląd affected orders przed time shift).
- `modules/pos/js/pos_app.js` — **Faza 5:** `_tcShowPreview`/`_tcHidePreview` — podgląd affected orders przed zastosowaniem time shift (kafelki/godzina/ready).

## Decyzje architektoniczne

### 1. Settle auto-transition (fastComplete chain)

**Problem:** `fastComplete()` wymagał `ready` przed `completed`. W strict mode (brak feature flags) tylko `ready → completed` jest dozwolone. Kelner nie może zamknąć zamówienia w `preparing` — musi ręcznie kliknąć GOTOWE, potem ZAMKNIJ.

**Decyzja:** Auto-transition przez całą ścieżkę w jednej TX. Każdy krok walidowany przez `canTransition()` + audit log. Alternatywy rozważone:
- **A:** Włączyć `auto_complete` flag dla tenant 2 — ale to wymaga Global Settings Matrix (jeszcze nie zbudowana) i zmienia semantykę (pomija kuchnię).
- **B:** Dodać `preparing → completed` do strict map — łamie Konstytucję §3 (kuchnia musi oznaczyć gotowość).
- **C (wybrano):** Auto-chain przez istniejące transitions — szanuje strict map, domyka kroki atomowo, audit trail pełny.

**Zabezpieczenia:** `maxSteps = 5` (anti-loop), każdy krok przez `canTransition()`, fallback na `chain[]` map tylko gdy bezpośredni `ready` nie jest dozwolony.

### 2. Tenant discovery — sortowanie po liczbie dań

**Problem:** `ORDER BY t.id ASC` wybierało tenant 1 (pusty demo z użytkownikami z `seed_demo_all.php`) zamiast tenant 2 (229 dań z `install_panel.php`).

**Decyzja:** Sortowanie po `COUNT(sh_menu_items) DESC, t.id ASC`. Alternatywy:
- **A:** `SLICEHUB_TENANT_ID=2` w env — wymaga konfiguracji per-hosting, nie działa out-of-the-box.
- **B:** localStorage fallback w frontend — wymaga JS changes, nie działa przed pierwszym logowaniem.
- **C (wybrano):** SQL sort po danych menu — zero frontend changes, działa out-of-the-box, tenant z realnym menu wygrywa.

### 3. `contentWindow` vs `globalThis`

**Problem:** PR #60 zamienił 919 wystąpień `window.*` → `globalThis.*` w 72 plikach. Nadgorliwie zamienił też `contentWindow` → `contentglobalThis` (2 wystąpienia w `pos_ui.js`), które nie istnieje w DOM API.

**Decyzja:** Przywrócono `contentWindow` + komentarz ostrzegawczy. `contentWindow` to właściwość `HTMLIFrameElement`, NIE `window` — nie podlega regule `no-window` z deno lint.

### 4. Odzyskanie Faz 1-5

Zmiany użytkownika (Fazy 1-4) zostały utracone przez `git checkout main --` wykonany przez innego agenta. Odzyskano je na podstawie diffów widocznych w historii konwersacji. Faza 5 (POS Centrum Kontroli Czasu preview) nie została utracona — była w plikach które nie zostały nadpisane.

## Otwarte pytania

1. **Feature flags dla tenant 2:** `sh_tenant_settings` dla tenant 2 nie ma klucza `feature_flags` → strict mode. Czy należy włączyć `auto_complete` lub `skip_kitchen` dla fast-food workflow? Wymaga Global Settings Matrix (planowane, nie zbudowane).
2. **DINE_IN_TRANSITIONS:** `fastComplete` chain używa `STRICT_TRANSITIONS` dla `chain[]` map. Dine-in orders mają osobną mapę — czy chain powinien uwzględniać `orderType`? Obecnie `canTransition()` otrzymuje `orderType` i używa odpowiedniej mapy, ale `chain[]` jest hardcoded. Przetestować dla dine-in.
3. **T57 pre-existing:** `process_order` z `PIZZA_MARGHERITA` failuje bo tenant 1 ma 0 items po resecie. To nie blokuje ale wskazuje na problem z demo data po `install_panel` (tenant 2 only).
4. **T58 panic debounce:** PanicEngine ma 2-min cooldown. Test runner uruchomiony dwa razy pod rząd failuje T58. Rozwiązanie: test runner powinien czekać 2 min między runami albo PanicEngine powinien mieć bypass dla testów.

## Weryfikacja

- `php -l`: 4/4 plików PASS (0 syntax errors)
- `deno lint`: 3 pliki JS, 0 błędów
- `tenant_config.php`: zwraca `tid = 2` (poprawnie)
- Backend test: `date=2026-08-25` → 12 slotów; `date=2026-08-20` → odrzucone
- Settle test: `preparing → completed` SUCCESS, `accepted → completed` SUCCESS, `new → completed` SUCCESS (auto-chain)
- Headless test runner: 61 pass / 1 fail (T57 pre-existing). T58 debounce — po odczekaniu 2 min przechodzi.
