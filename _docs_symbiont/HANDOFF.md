# Handoff Wszczepu B

Aktywny scenariusz: [B-003](slices/B-003.md), IN_PROGRESS. [B-002](slices/B-002.md) oraz [B-001](slices/B-001.md) pozostają zamknięte i ACCEPTED_BY_USER. Konstytucja A/B 1.0.0. Dowody: [CURRENT_STATE](CURRENT_STATE.md).

## B-002 — odebrany

Właściciel jawnie potwierdził ręczny odbiór komunikatem „B-002 odebrane”. Panel katalogu B przeszedł test ręczny według runbooka hosta. Automatyczny dowód A→B obejmuje izolowany PHP, kontrakt i kontroler DOM. Nie wykonywano pozycji katalogu; nie dodawano domeny hosta do A.

## Odbiór i utrwalenie

Właściciel potwierdził ręczny przepływ: rejestracja hosta, inspekcja pakietu, osobna zgoda i wczytanie trzech plików z numerami linii. Potwierdził czytelność UI i poprawne działanie izolacji pilota, zaakceptował B-001 i zlecił osobne commity A/B. To nie certyfikacja CSP/OS sandbox ani odbiór całego Etapu B. Etap A pozostaje ACCEPTED_BY_USER; nie otwierać A-001-B/A-002-B ponownie.

Gałąź B: symbiont/a-001-foundation; bazowy commit przed B-001: 42ea1a8. A pozostaje fizycznie odrębnym repo. Zlecone utrwalenie obejmuje kod, UI, testy i dokumentację B-001. Identyfikatory commitów znajdują się w historii Git i raporcie sesji; nie są wpisywane do samoodnoszącego się dokumentu. Nie udzielono zgody na push.

## Odebrany zakres

Oddzielny engineering-read 1.0.0 i uprawnienie, dwa endpointy PHP i panel metadanych pakietu. Wyłącznie trzy zatwierdzone bloby z 42ea1a8783cd0d5717de204bb8b2012773ae2b74. Źródła są danymi, nigdy wykonywanym PHP/JS. Administrator metadanych nie pobiera kodu; robi to A własnym credentialem po osobnej zgodzie. Stare tokeny diagnostyczne nie działają w tym kontrakcie. Nie rozszerzono Bridge v1, sesji pracownika ani kompetencji biznesowych.

## Następna sesja

Domyślnie PLAN. Nowy scenariusz wymaga nowego ID, Definition of Ready i jawnej zgody. Nie zaczynać kolejnego przyrostu automatycznie ani nie kontynuować zamkniętego B-001. Odtworzenie pilota opisuje [runbook, B-001](RUNBOOK_A.md).

Źródła, dowody i SQLite A pozostają lokalnie; odbiór nie uprawnia do ich usunięcia. Nie czyścić TEMP ani nie stosować starej procedury kasowania historii A. Usuwanie wymaga osobnej zgody. Nie zarządzamy ręcznym procesem pilota uruchomionym przez właściciela.

## Weryfikacja i zakazy

B: `php tests/symbiont_bridge_test.php`; `php tests/symbiont_engineering_test.php <izolowany snapshot.json>` (bez parametru jawnie brak pozytywnych testów źródeł); php -l nowych modułów i node --check modules/symbiont/engineering.js. A: scripts/test-b001-http.mjs, npm test, test-host-http.mjs i check:canon z jawnym host-root. Nie zmieniać historycznych dowodów po commitowaniu. Potwierdzenie ręczne i jego zakres znajdują się w CURRENT_STATE.

Nie zmieniać Apache, php.ini, .env, prawdziwej DB, domeny, offline POS, kont/PIN-ów, urządzeń ani audio. Nie uruchamiać runnera ERP, migracji, seedów lub resetów. PHP pilota ma ograniczony staging/open_basedir i brak sterowników DB/funkcji sieciowych; to nie pełny sandbox OS dla dowolnego kodu. Nie pushować ani usuwać danych bez odrębnego polecenia.
