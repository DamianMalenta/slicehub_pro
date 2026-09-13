# Stan Wszczepu B

Weryfikacja: 2026-09-13 03:53 +02:00. Konstytucje/master-plan: APPROVED 2026-09-11. Odbiór Etapu A: ACCEPTED_BY_USER 2026-09-13.

| Scenariusz | UI | Backend | Dokumentacja | Integracja | Stan |
|---|---|---|---|---|---|
| A-001-B | Kontroler DOM + API PASS; otwarcie i wynik potwierdzone przez użytkownika | PHP isolated PASS | Kanon + pinning PASS; instrukcja uruchomienia wykorzystana przez użytkownika | Node->PHP PASS; pilotaż potwierdzony przez użytkownika | CONFIRMED_BY_USER |
| A-002-B | Panel Wszczepu oraz nawigacja Huba potwierdzone przez użytkownika | Bez zmiany API; PHP isolated PASS | Scenariusz i instrukcje zaktualizowane | Realny Node->PHP A-002 PASS; diagnostyka, link i powrót Huba potwierdzone przez użytkownika | CONFIRMED_BY_USER |
| B-001 | Przepływ, trzy źródła z liniami i czytelność potwierdzone ręcznie | PHP isolated PASS | Specyfikacja, runbook i odbiór zapisane | Node→PHP PASS; pilot potwierdzony przez właściciela | ACCEPTED_BY_USER |

## B-001 — odebrany; zapis 2026-09-14 +02:00

Status B-001: ACCEPTED_BY_USER. Zapis odbioru: 2026-09-14 00:44 +02:00. Właściciel potwierdził ręczny przepływ: rejestracja hosta, inspekcja pakietu, osobna zgoda i poprawne wczytanie trzech plików źródłowych z numerami linii. Potwierdził czytelność interfejsu i poprawne działanie izolacji w pilocie, zaakceptował B-001 oraz zlecił osobne commity obu repozytoriów. Źródło: wiadomość właściciela w sesji odbiorowej, bez przekazania sekretów.

B-001 jest zamknięty; nie oznacza to odbioru całego Etapu B ani certyfikacji bezpieczeństwa/CSP/OS sandbox. Nie rozszerzamy potwierdzenia ręcznego o niewymienione testy restartu i odmów — te mają dowody automatyczne. Etap A nadal ACCEPTED_BY_USER. Specyfikacja i lokalne granice: [B-001](slices/B-001.md).

Dostarczono osobny adapter EngineeringRead, service/metadata-admin API, przypięty kontrakt engineering-read 1.0.0 oraz politykę trzech blobów z commita 42ea1a8783cd0d5717de204bb8b2012773ae2b74. Stary Bridge/protokół/konstytucje nie zmieniły znaczenia. Nowe credentiale są niezależne od diagnostyki; administrator pakietu widzi metadane, nie kod. Panel ma rzeczywisty wynik, odmowy, wyczyszczenie i powrót do diagnostyki. Nie zmienia konfiguracji PHP ani praw pracowników.

Dowody: producent A `scripts/test-b001-http.mjs --host-root "C:/xampp/htdocs/slicehub"` PASS — rzeczywisty PHP w izolowanym stagingu, oba kontrolery DOM, porównanie źródeł z Git, zgoda, idempotencja, historia po restarcie i bez PHP, odmowa nowego pobrania. PHP `tests/symbiont_engineering_test.php <izolowany snapshot>` 22/22 PASS. Bez parametru tylko 16 testów odmów, bez deklaracji przetestowania źródeł. A npm test 56/56 PASS; regresja starego HTTP A→B PASS. Lint nowych modułów PHP/kontrolera JS PASS. A `npm run check:canon -- --host-root "C:/xampp/htdocs/slicehub"`: PASS; `git diff --check` w obu repo: PASS.

Pilot B-001 nie uruchamia żywego checkoutu: kopiuje wyłącznie jawne pliki runtime, udostępnia osobny JSON źródeł poza docrootem i używa PHP -n/open_basedir, blokad funkcji procesów/sieci oraz braku sterowników DB. Readiness weryfikuje odmowę odczytu własnego pliku spoza zakresu. Nie jest to pełny sandbox OS dla arbitralnego kodu. Źródła i SQLite A są zachowane do odbioru; przykład dowodu: `C:\Users\Damian\AppData\Local\Temp\symbiont-b001-t9H1NN\integration-evidence.json`. Nie czyścić tych katalogów TEMP przed odbiorem.

Poza odebranym zakresem pozostają rozszerzony przegląd CSP/mobile, produkcja, IAM, domena, prawdziwa baza, sprzęt, analiza semantyczna i AST. Właściciel zlecił utrwalenie B-001 w osobnych commitach obu repo; bazowy commit B przed przyrostem to 42ea1a8. Identyfikatory utrwalenia znajdują się w historii Git i raporcie sesji. Nie udzielono zgody na push ani usunięcie źródeł/dowodów pilota; pozostają lokalnie również po odbiorze. Stare sekrety/Apache/ERP są nietknięte. Nie zarządzano procesem ręcznego pilota użytkownika; odtworzenie opisuje [runbook](RUNBOOK_A.md).

## Dostarczono w Etapie A
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

Operator biznesowy, STT/TTS, wake word, telefonia, fiskalizacja i magazyn nie są wdrożone. Nie uruchamiano istniejącego runnera wyszukującego PIN-y. Właściciel potwierdził czytelność UI i ręczny przepływ B-001; nie jest to rozszerzony audyt CSP/mobile ani test ERP, bazy czy sprzętu. PHP zweryfikowany: 8.2.12. Testy i opis celu nie oznaczają odbioru produkcyjnego.
