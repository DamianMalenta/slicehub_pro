# Stan Wszczepu B

Weryfikacja: 2026-09-13 03:53 +02:00. Konstytucje/master-plan: APPROVED 2026-09-11. Odbiór Etapu A: ACCEPTED_BY_USER 2026-09-13.

| Scenariusz | UI | Backend | Dokumentacja | Integracja | Stan |
|---|---|---|---|---|---|
| A-001-B | Kontroler DOM + API PASS; otwarcie i wynik potwierdzone przez użytkownika | PHP isolated PASS | Kanon + pinning PASS; instrukcja uruchomienia wykorzystana przez użytkownika | Node->PHP PASS; pilotaż potwierdzony przez użytkownika | CONFIRMED_BY_USER |
| A-002-B | Panel Wszczepu oraz nawigacja Huba potwierdzone przez użytkownika | Bez zmiany API; PHP isolated PASS | Scenariusz i instrukcje zaktualizowane | Realny Node->PHP A-002 PASS; diagnostyka, link i powrót Huba potwierdzone przez użytkownika | CONFIRMED_BY_USER |

## Dostarczono
Niezależny moduł core/Symbiont, endpoint usługowy i endpoint administratora. Describe/diagnose bez DB, bez czytania kodu i bez komend biznesowych. Domyślnie disabled, osobne credentials, hash_equals, TLS poza loopbackiem, limity i walidacja protokołu. Panel modules/symbiont/index.html korzysta z istniejącego sh_api_base i prezentuje prawdziwe wyniki/odmowy. Odmawia wysłania klucza przez niezabezpieczony lub obcy adres. Credential tylko na czas żądania.

Kafelek „Symbiont — integracja” w sekcji Administracja Huba prowadzi względnym linkiem do modules/symbiont/index.html?from=hub. Widoczność UX korzysta z data-roles owner/admin/manager. Nie przekazuje sh_token, credentiali Wszczepu ani automatycznej sesji. Panel B ma powrót do Huba tylko przy parametrze from=hub i nadal wymaga osobnego klucza administratora integracji.

Kanon integracji, konstytucja hosta, UX Operatora, audio/telefonia, prompt startowy i przypięte artefakty A v1.0.0. Dotychczasowe reguły biznesowe i istniejące endpointy ERP pozostają bez zmian.

## Dowody
- `php tests/symbiont_bridge_test.php`: 20/20 PASS (16 zachowań i 4 kontrole przypiętych artefaktów). Brak DB/kont/PIN-ów.
- `php -l` dla core/Symbiont/Bridge.php, core/Symbiont/http.php, api/symbiont/bridge.php, api/symbiont/admin.php: PASS.
- `node --check modules/symbiont/bridge.js`: PASS, wyłącznie narzędzie developerskie.
- Test producenta `scripts/test-host-http.mjs --host-root "C:/xampp/htdocs/slicehub"`: PASS. Rzeczywisty PHP, oba kontrolery DOM, preview -> zgoda -> rejestracja -> diagnostyka -> disconnect -> odmowa kolejnej diagnostyki. Testowy router blokował m.in. api/pos/engine.php (404). Żadne żądanie nie trafiło do domeny.
- A2-T10 na rzeczywistych plikach Hub i Wszczepu: PASS. Sprawdzono względny link, data-roles i brak credentiali w URL. Użytkownik potwierdził przejście i powrót w prawdziwej sesji; nie testowano tenant discovery ani bazy.
- Kontrola artefaktów A/B: PASS; checksum to wykrywanie driftu, nie podpis wydawcy.
- Istniejący lokalny Apache: `admin.php` i `bridge.php` zwracają 503 DISABLED, ponownie potwierdzone 2026-09-13. To prawidłowy fail-closed; klucze wpisane do izolowanego pilota nie są konfiguracją Apache.

## Ręczne potwierdzenie pilotażu — 2026-09-12
Użytkownik potwierdził otwarcie obu paneli A-001. Przesłany wynik B: „Most PHP odpowiada”, host=slicehub-test, environment=test, adapter=1.0.0, observed_at=2026-09-12T03:09:47+00:00, dane biznesowe niebadane. Wynik A: ta sama tożsamość, bridge=ok, business_access=not_requested, observed_at=2026-09-12T03:15:34+00:00. Potwierdzono lokalny happy-path UI i Mózg->Wszczep, nie pełny odbiór UX lub domeny. Dowód pochodzi z wiadomości użytkownika; nie otrzymano sekretów.

## Ręczne potwierdzenie pilotażu A-002 — 2026-09-13
Panel B zwrócił „Most PHP odpowiada”, host=slicehub-test, environment=test, adapter=1.0.0, observed_at=2026-09-13T01:33:16+00:00. W panelu A użytkownik potwierdził preview -> zgodę -> rejestrację -> diagnostykę read-only -> disconnect. Diagnostyka A observed_at=2026-09-13T01:38:00+00:00; bridge=ok, business_access=not_requested. Historia A zarejestrowała DISCONNECTED rewizja 4 po REGISTERED rewizja 3. W rzeczywistej sesji Huba potwierdzono przejście do `modules/symbiont/index.html?from=hub` i działający powrót do Huba. To potwierdza lokalny przepływ i nawigację, nie produkcyjny Apache ani operacje biznesowe.

## Ograniczenia
Blokada lokalnego uruchomienia jest rozstrzygnięta. Dotychczasowa instrukcja: [RUNBOOK_A](RUNBOOK_A.md). Endpointy Apache pozostają poprawnie DISABLED; testowy router nie uruchamia Huba ani tenant_config.php.

Panel jest samodzielnym ekranem administratora integracji i ma wejście z sekcji Administracja Huba. To nadal nie jest UI kuchni ani autoryzacja pracownika. Nie ma przełącznika konfiguracji procesu w UI. „Odłącz w Mózgu” blokuje nowe diagnostyki po stronie A, ale nie wyłącza PHP/Wszczepu.

Operator biznesowy, STT/TTS, wake word, telefonia, fiskalizacja i magazyn nie są wdrożone. Nie uruchamiano istniejącego runnera wyszukującego PIN-y. Nie sprawdzono layoutu/CSP w prawdziwej przeglądarce, działania ERP, bazy ani sprzętu. PHP zweryfikowany: 8.2.12. Testy i opis celu nie oznaczają odbioru produkcyjnego.
