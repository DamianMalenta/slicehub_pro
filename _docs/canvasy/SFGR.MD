SliceHub — Podrecznik operatora
Pelna instrukcja: co zrobic raz (setup), co zrobic cyklicznie (workery), a co dopiero jak bedziesz realnie podpinal zewnetrznego dostawce.

TL;DR — minimum zeby system dzialal
Dopoki nie podpinasz zadnego zewnetrznego dostawcy (Papu, Dotykacka, GastroSoft), jedyne co musisz zrobic:

1. Wkleic migracje do bazy (skrypt PHP, 1 klikniecie).

2. Wygenerowac klucz szyfrujacy (jedno polecenie w konsoli).

3. Uruchomic dwa workery co minute — na devie mozesz odpalac recznie.

Cala reszta (klucze API, webhooki, konfiguracja dostawcy) — tylko gdy pierwszy klient ma realna integracje.

Jak to dziala — umyslowy model
Pomysl o SliceHub jako o skrzynce pocztowej. POS, Courses, Kitchen, Driver — wszyscy wrzucaja listy (zdarzenia) do jednej wspolnej skrzynki. Dwa niezalezne listonosze (workery) wyciagaja te listy i roznosza je do roznych adresatow. System nie czeka az listonosz je odbierze — to asynchroniczne.

WYCHODZACE — z nas na zewnatrz
Przyklad: kierowca zaznacza Dostarczono

1. Silnik zapisuje zamowienie jako delivered.

2. Publikuje zdarzenie w tabeli outbox.

3. Worker webhooks wysyla POST do abonentow (CRM).

4. Worker integrations wola adapter dostawcy.

PRZYCHODZACE — nowe w 7.6
Przyklad: Papu informuje o odbiorze

1. Papu wysyla POST na nasz inbound URL.

2. Weryfikujemy podpis HMAC.

3. Logujemy surowy request do audytu.

4. Update statusu w tabeli orders.

5. Publikujemy event → KDS/Driver/notif.

Dlaczego outbox + worker, a nie bezposredni HTTP
Gdyby POS dzwonil do Papu bezposrednio przy akceptacji, kazda awaria Papu = zawieszony POS. Skrzynka pocztowa izoluje nas:

Setup jednorazowy — 5 do 15 minut
Te rzeczy robisz DOKLADNIE raz, na starcie.

Krok 1 — Migracje bazy danych
Wpisz w przegladarke adres skryptu — on sam wykona wszystkie potrzebne ALTER i CREATE TABLE. Bezpieczny do wielokrotnego odpalania.

W przegladarce:

http://localhost/slicehub/scripts/setup_database.php
Powinienes zobaczyc liste OK/SKIP dla migracji 026 (event system), 027 (gateway v2), 028 (integration deliveries), 029 (infrastructure completion).

Krok 2 — Wlacz libsodium w XAMPP
CredentialVault szyfruje hasla i klucze API w bazie. Uzywa libsodium, ktore w XAMPPie zwykle jest wylaczone.

Co zrobic:

1. Otworz plik konfiguracyjny PHP:

C:\xampp\php\php.ini
2. Znajdz linie:

;extension=sodium
3. Usun srednik z poczatku, zapisz, restart Apache.

Weryfikacja w PowerShell:

C:\xampp\php\php.exe -m
Na liscie modulow powinien byc wpis sodium.

Krok 3 — Wygeneruj klucz vault
To jeden z dwoch najwazniejszych sekretow systemu (drugi to haslo MySQL). Generujesz raz, robisz backup POZA serwerem, nigdy nie nadpisujesz.

W PowerShell (katalog slicehub):

C:\xampp\php\php.exe scripts/bootstrap_vault.php
Skrypt utworzy plik config/vault_key.txt z uprawnieniami 0600. Zrob kopie w menedzerze hasel (Bitwarden, 1Password) albo na kartce w sejfie.

Krok 4 — (Pomin jesli baza czysta) migracja plaintext do vault
Jesli mialbys istniejace hasla w bazie (z testow), ten skrypt je zaszyfruje w miejscu. Dla swiezej instalacji nic nie robi.

Dry-run:

C:\xampp\php\php.exe scripts/rotate_credentials_to_vault.php --dry-run
Wlasciwe wykonanie:

C:\xampp\php\php.exe scripts/rotate_credentials_to_vault.php --live
Workery — jak uruchomic listonoszy
Dwa workery musza dzialac cyklicznie: co minute wyciagaja nowe zdarzenia z outbox. Jesli ich nie uruchomisz, eventy zbieraja sie w tabeli i nie opuszczaja serwera. KDS i POS lokalne dzialaja bez nich, ale zewnetrzne integracje nie.

Worker	Co robi	Jak czesto
worker_webhooks.php	POST do URL-i z Settings > Webhooks (generyczne, np. CRM klienta).	co 1 min
worker_integrations.php	Wola adapter dostawcy POS (Papu/Dotykacka/GastroSoft).	co 1 do 2 min
DEV — XAMPP Windows, recznie
Na devie nie stawiaj cronow. Otworz dwa okna PowerShella i pusc workery w petli:

Okno 1:

C:\xampp\php\php.exe scripts/worker_webhooks.php --loop --sleep=30
Okno 2:

C:\xampp\php\php.exe scripts/worker_integrations.php --loop --sleep=30
Flaga --loop = worker nie konczy po jednym batchu, tylko spi sleep sekund i lapie nastepny. Ctrl+C zeby zatrzymac.

DEV — Task Scheduler (automat)
1. Task Scheduler → Create Basic Task.

2. Trigger: Daily, repeat every 1 minute.

3. Action: Start a program.

4. Program:

C:\xampp\php\php.exe
5. Arguments: scripts/worker_webhooks.php

6. Start in:

C:\xampp\htdocs\slicehub
7. Powtorz dla worker_integrations.php.

PROD — Linux cron
Edycja: crontab -e

* * * * * cd /var/www/slicehub && /usr/bin/php scripts/worker_webhooks.php >> logs/webhooks.log 2>&1
*/2 * * * * cd /var/www/slicehub && /usr/bin/php scripts/worker_integrations.php >> logs/integrations.log 2>&1
PID-file lock pilnuje zeby dwa procesy nie rozjechaly sie przy pokrywajacych sie odpaleniach.

PROD — systemd (rekomendowane)
Jeden plik .service per worker z Restart=always. Worker chodzi jako daemon, systemd dba o restarty, logi w journalctl. Wzorzec pliku wrzuce do dokumentacji przy hotfixie 7.6.1.

Kiedy realnie podpinasz dostawce (np. Papu)
To NIE jest czesc startowego setupu — robisz per restauracja, per integracja. Dla pelnosci obrazu pokazuje jak.

Flow podlaczenia — 5 punktow
1. Dostawca daje Ci klucze

Kontaktujesz sie z Papu, dostajesz api_key oraz api_secret dla konkretnej restauracji.

2. Wpisujesz klucze w Settings Panel

Zaloguj sie jako admin, wejdz w modules/settings, zakladka Integrations, Add, wybierz provider Papu, wklej klucze, Save. Klucze sa szyfrowane vault-em od razu — nigdy nie zapisane w plain text.

3. Test ping

Klikasz Test connection — system dzwoni do API Papu bez wysylania zadnego zamowienia. Zielona ikonka = klucze ok. Limit: 5 pingow na minute na tenanta.

4. Podaj dostawcy swoj inbound URL

Wysylasz do Papu adres:

https://twojadomena.pl/api/integrations/inbound.php?provider=papu&tenant_id=1
Tam beda wysylali callbacki jak zmieni sie status zamowienia.

5. Pierwsze zamowienie testowe

Pusczasz recznie jedno zamowienie, obserwujesz dwie tabele: sh_integration_deliveries (outbound) i sh_inbound_callbacks (inbound). Obie pokazuja status=processed — dziala.

Koncretnie — co masz zrobic TERAZ
Checklist startowy
1. Migracje bazy

http://localhost/slicehub/scripts/setup_database.php
2. Wlacz sodium + wygeneruj klucz vault

Edytuj php.ini, restart Apache, potem:

C:\xampp\php\php.exe scripts/bootstrap_vault.php
3. Workery — dopiero jak testujesz

Na starcie mozesz ignorowac. Pierwsze odpalenie w trybie sucho:

C:\xampp\php\php.exe scripts/worker_webhooks.php -v --dry-run
4. Podpinanie Papu / Dotykacki — gdy klient bedzie potrzebowal

Wszystkie adaptery sa gotowe, panel Settings czeka. Jak dostaniesz klienta — wracasz do sekcji Kiedy realnie podpinasz dostawce.

Pytanie
Chcesz zebym Cie przeprowadzil krok-po-kroku przez Krok 1 (migracje) i Krok 2 (sodium + vault), a potem zrobil hotfix CSRF 7.6.1 z audytu? Razem okolo 30 do 45 minut i system jest gotowy na pierwszego realnego klienta.