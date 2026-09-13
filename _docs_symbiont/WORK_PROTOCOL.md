# Protokół pracy

Wersja 1.0.0, APPROVED 2026-09-11. Obowiązuje [CORE_CONSTITUTION.md](CORE_CONSTITUTION.md).

## Start
Raport startowy zawiera projekt/repo, ID scenariusza, wersję Konstytucji, cel użytkownika, fakty z kodu, konflikty, zakres UI/backend/docs i plan testów. Tryb PLAN, chyba że użytkownik jawnie zlecił implementację. Obecna realizacja master-planu została zatwierdzona; zgody na działania nieodwracalne pozostają osobne.

## Jednostka pracy
Pionowy scenariusz ma jeden ID. Jego plik w slices zawiera cel, zakres, non-goals, przepływ interfejsu lub dialogu, kontrakt, stany błędne, niezmienniki, testy i dowody. ID łączy PR, testy, artefakty i CURRENT_STATE. Nie prowadzimy osobnych niespójnych backlogów frontend/backend/docs.

## Kolejność
Scenariusz -> projekt obsługi i błędów -> kontrakt i testy akceptacyjne -> małe przyrosty UI/backend -> integracja -> instrukcja -> odbiór. Mock służy projektowaniu i testom; nie zalicza połączenia z rzeczywistym dostawcą.

## Definition of Ready
Wymagane są: aktor, cel, zakres, uprawnienia, wejście/wyjście, stany błędne, makieta/dialog, testy, środowisko i brak nierozstrzygniętej zależności blokującej ten konkretny scenariusz. Brak wyboru telefonii blokuje telefoniczny scenariusz C, nie read-only połączenie A.

## Definition of Done
UI, backend, dokumentacja i integracja są zweryfikowane. Test jednostkowy nie zastępuje testu call-site'u. Stan błędu jest obsługiwalny. Instrukcja pozwala przejść przepływ bez autora. Właściciel odbiera etap; agent nie wpisuje sam akceptacji właściciela. Niezmieniony obszar może mieć status NOT_CHANGED_VERIFIED z uzasadnieniem, nie pominięcie bez sprawdzenia.

## Dowody
Podaj polecenie, środowisko, zakres, wynik i ograniczenia. Nie zapisuj tokenów, audio, danych klientów ani sekretów. Wynik historyczny nie jest wynikiem bieżącej sesji. Testy mogą używać wyłącznie izolowanych fixtures i własnych katalogów tymczasowych; nie wyszukuj kont/PIN-ów na prawdziwej bazie.

## Dwa repo
Zmiana kontraktu ma producenta A i konsumenta B, przypięte wersje i test zgodności. B nie importuje kodu z sąsiedniego A. Niekompatybilna wersja jest odrzucana. Aktywacja funkcji następuje dopiero przy zgodnych wydaniach. Historia i aktualne pliki źródłowe pozostają nietknięte podczas niedestrukcyjnej migracji.

## Dokumentacja
Normatywna: konstytucja, wizja, UX, kontrakty i decyzje. Empiryczna: CURRENT_STATE. Operacyjna: HANDOFF. Historyczna: legacy_ADRs. ROADMAP nie kopiuje statusów. Każdy nowy kanoniczny dokument trafia do indeksu. Zmiana zasad wymaga decyzji i aktualizacji wszystkich konsumentów przypiętej wersji.

## Zakończenie
Zaktualizuj stan i handoff dla zatwierdzonych zmian. Raport: UI / backend / dokumentacja / testy / nieweryfikowane / stan całego scenariusza. Nie pushuj bez polecenia. Nie oznaczaj gotowości operacyjnej na podstawie samego projektu dokumentacji.
