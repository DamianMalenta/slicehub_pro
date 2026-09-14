# Uruchomienie Wszczepu Etapu A

PHP >=8.2, bez Composera, Node i zmian bazy. Domyślnie wyłączony. Etap A ma odrębne credential usługowe i panelu administratora integracji; nie nadają praw pracownika ani kompetencji biznesowych.

## Wybrany lokalny pilotaż
Właściciel wybrał osobne procesy, nie zmianę istniejącego Apache. Launcher `scripts/local-pilot.mjs` z repo A uruchamia ograniczony PHP na wolnym porcie, z jawnym --host-root wskazującym B. Operator wpisuje w swoim terminalu dwa nowe odrębne klucze paneli A/B bez ich wyświetlania; token usługowy jest losowany. Nic nie trafia do .env ani czatu.

Otwórz adres panelu B wypisany przez launcher (nie adres istniejącego Apache) i użyj klucza B. Ten sam launcher uruchamia panel A z kreatorem rejestracji. Router blokuje pozostałe API i pliki hosta. Ctrl+C kończy pilot i usuwa tylko jego własną tymczasową historię. Baza ERP i środowisko Apache pozostają nietknięte. Zwykły endpoint w Apache nadal może poprawnie zwracać DISABLED.

## Zmienne procesu PHP (standardowa konfiguracja, niepotrzebna dla launchera)
- SYMBIONT_BRIDGE_ENABLED=1: jawnie włącza most; inna wartość wyłącza.
- SYMBIONT_BRIDGE_SERVICE_TOKEN: losowy sekret >=32 znaki; A używa go jako SYMBIONT_HOST_TOKEN lub wskazanego SYMBIONT_HOST_TOKEN_*.
- SYMBIONT_BRIDGE_ADMIN_TOKEN: inny losowy sekret >=32 znaki, wyłącznie dla panelu Wszczepu.
- SYMBIONT_BRIDGE_HOST_ID: slug, domyślnie slicehub-host; musi odpowiadać konfigurowanemu profilowi A.
- SYMBIONT_BRIDGE_ENVIRONMENT: development/test/staging/production, domyślnie development; musi odpowiadać A.

Administrator przekazuje sekrety przez chronione środowisko procesu, bez czatu, commitów i historii poleceń. Zmienne terminala PHP CLI nie zmieniają środowiska Apache: konfiguracja usługi Apache/PHP wymaga osobnego ustawienia i przeładowania przez administratora. Nie zmieniaj konfiguracji działającego hosta bez uzgodnienia.

## Adresy i role
A wywołuje POST api/symbiont/bridge.php z tokenem service. Panel B wywołuje POST api/symbiont/admin.php z tokenem admin. Tokeny nie są wymienne. Poza literalnym loopbackiem serwer wymaga HTTPS; nie ufa X-Forwarded-Proto jako dowodowi TLS.

Panel: modules/symbiont/index.html. W sekcji Administracja Huba znajduje się kafelek „Symbiont — integracja” dla owner/admin/manager, prowadzący do `../symbiont/index.html?from=hub`. To tylko widoczność UX; backend nadal wymaga osobnego klucza integracji. Link nie przenosi JWT ani tokenów. Powrót „Wróć do Huba SliceHub” jest widoczny po wejściu z parametrem from=hub. Korzysta z istniejącego core/js/sh_api_base.js, więc prefiks API jest zgodny z instalacją. To osobny ekran administratora integracji, nie panel kuchni.

## Obsługa
Wpisz klucz administratora B -> „Sprawdź Wszczep”. Pole zostanie wyczyszczone. Wynik zawiera host, środowisko, adapter i datę. Credential nie jest zapisywany w localStorage/cookies. „Wyczyść wynik i zablokuj” nie wyłącza procesu mostu. Nie myl diagnozy lokalnego B z testem A->B: ten drugi wymaga w A preview, jawnej zgody i rejestracji.

„Odłącz w Mózgu” w panelu A blokuje nowe diagnostyki tego profilu w Core i unieważnia stare zgody. Nie zmienia `SYMBIONT_BRIDGE_ENABLED`, nie zatrzymuje PHP, nie usuwa historii i nie wpływa na ręczny POS.

## Wyłączenie
Ustaw SYMBIONT_BRIDGE_ENABLED=0 i przeładuj środowisko PHP. Odpowiedź mostu to DISABLED. POS, baza, zamówienia i inne moduły nie są zmieniane. Nie usuwaj plików ani tabel. UI nie posiada jeszcze administracyjnego przełącznika konfiguracji procesu.

## Testy
`php tests/symbiont_bridge_test.php` używa wyłącznie własnych wartości testowych i klasy Bridge, bez DB. Lint: php -l na core/Symbiont/Bridge.php, core/Symbiont/http.php, api/symbiont/bridge.php i api/symbiont/admin.php. Kontrolowany test HTTP uruchamia proces PHP z routerem dopuszczającym wyłącznie pliki tego modułu; nigdy całe nieograniczone drzewo hosta. Nawigacja Huba jest testowana przez fixture producenta na rzeczywistych plikach HTML/JS, bez loginu i bazy.

Nie uruchamiaj istniejącego headless runnera wyszukującego tenanty/PIN-y na prawdziwej bazie. Testy mostu nie są testem reguł ERP. Wake word, telefonia i rozliczenia nie są wdrożone.

## B-001 — izolowane udostępnianie pakietu źródeł

Osobny scenariusz [B-001](slices/B-001.md), bez rozszerzenia Bridge v1. Uruchom z własnego interaktywnego terminala launcher w odrębnym repo A:

```powershell
node "C:\xampp\htdocs\programdocursora\_RPA_AUTOMATION\symbiont-core\scripts\b001-pilot.mjs" --host-root "C:\xampp\htdocs\slicehub"
```

Opcja --check wykonuje tylko kontrolę kontraktu i zatwierdzonych blobów, bez usług i SQLite. Launcher wymaga trzech NOWYCH, różnych credentiali: panel A, diagnostyka B, metadane B-001 w B. Generuje osobno dwa losowe tokeny usługowe. Nie wpisuj starych sekretów, nie zmieniaj .env, php.ini ani konfiguracji Apache.

Nowe zmienne są ustawiane WYŁĄCZNIE w izolowanym procesie PHP przez launcher: SYMBIONT_ENGINEERING_ENABLED, SYMBIONT_ENGINEERING_SERVICE_TOKEN, SYMBIONT_ENGINEERING_ADMIN_TOKEN, SYMBIONT_ENGINEERING_PACKAGE_FILE. Nie konfiguruj ich w istniejącym Apache w ramach B-001. Bez nich nowe endpointy pozostają DISABLED. Service i metadata-admin są odrębne od starych bridge credentials; metadata-admin nie może pobierać treści kodu.

PHP -n uruchamia skopiowany jawny runtime adaptera, nie żywy checkout. Pakiet JSON z trzech zatwierdzonych blobów jest poza docrootem. open_basedir ogranicza pliki do runtime i pakietu, blokady PHP wyłączają URL fopen, procesy i funkcje sieciowe, sterowniki DB muszą być nieobecne. Kontrola readiness sprawdza także odmowę odczytu własnego znacznika pilota spoza zakresu. To ograniczenie znanego adaptera, nie pełny sandbox OS.

Otwórz adres B/engineering.html wypisany przez launcher, nie adres Apache. Użyj trzeciego klucza. „Sprawdź udostępnienie” ma pokazać trzy zatwierdzone pliki i commit 42ea1a8783cd0d5717de204bb8b2012773ae2b74. Stary/diagnostyczny klucz powinien otrzymać odmowę. „Wyczyść i zablokuj” usuwa wynik; nie wyłącza PHP. Kod i osobna zgoda są w panelu A/inspection po rejestracji tożsamości hosta w A. Historia, linie, pokrycie i odbiór całego przepływu opisane są w runbooku A i specyfikacji B-001.

Ctrl+C zatrzymuje procesy, NIE USUWA pakietu ani SQLite. Restart: to samo polecenie z --data-dir i dokładnym zachowanym katalogiem wypisanym przez launcher. Nowe credentiale/port wymagają ponownej rejestracji w A, historia pozostaje. Katalog jest pod TEMP użytkownika; nie czyść go przed odbiorem. Nie używaj starej procedury kasowania historii Etapu A. Usunięcie źródeł po odbiorze wymaga osobnej zgody.

Weryfikacja: w A `node scripts/test-b001-http.mjs --host-root "C:/xampp/htdocs/slicehub"`, a w B `php tests/symbiont_engineering_test.php "<izolowany katalog>/package/snapshot.json"`. Bez parametru PHP uruchamia tylko testy odmów i jawnie oznacza brak testu rzeczywistych źródeł. Node jest tutaj narzędziem developerskim A, nie zależnością runtime SliceHuba. Nie uruchamiaj runnera ERP, seedów, baz, sprzętu ani kont/PIN-ów.

## B-002 — katalog kompetencji

B-002 używa tego samego ograniczonego pilota, rozszerzonego wyłącznie o jawne pliki Capability Registry. Nowe, różne zmienne procesu to `SYMBIONT_CAPABILITY_CATALOG_ENABLED=1`, `SYMBIONT_CAPABILITY_CATALOG_SERVICE_TOKEN` i `SYMBIONT_CAPABILITY_CATALOG_ADMIN_TOKEN`. Nie ustawiaj ich w Apache do ręcznego odbioru lokalnego.

Uruchom `node "C:\xampp\htdocs\programdocursora\_RPA_AUTOMATION\symbiont-core\scripts\b001-pilot.mjs" --host-root "C:\xampp\htdocs\slicehub"`. Launcher poprosi również o osobny klucz administratora katalogu B-002 i wypisze adres panelu `modules/symbiont/capabilities.html`. Użyj tego klucza do pobrania listy, następnie sprawdź odmowę kluczem diagnostycznym oraz „Wyczyść i zablokuj”. Żadna pozycja nie może zostać wykonana; panel ma pokazać „Wykonywanie: Niedozwolone”.

Automatycznie: w A `node scripts/test-b002-http.mjs --host-root "C:/xampp/htdocs/slicehub"`; w B `php tests/symbiont_capability_test.php`. Pilot nie udostępnia DB, tenant_config ani tras ERP. Nie uruchamiaj runnera ERP, bazy, audio ani urządzeń.
