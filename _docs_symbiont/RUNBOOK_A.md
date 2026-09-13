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
