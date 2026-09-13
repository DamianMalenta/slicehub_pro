# Prompt do każdego nowego okna

Poniższy tekst jest kanoniczny. Uzupełnij projekt/root/ID/zadanie. Tryb PLAN jest domyślny; IMPLEMENTACJA wymaga jawnej zgody na konkretny zakres.

```text
PROTOKÓŁ STARTOWY SYMBIONTA — OBOWIĄZKOWY

A = uniwersalny Mózg i panel Inżyniera NLE; B = osobny Host, właściciel domeny, panelu Operatora, audio i telefonii. Dwa fizyczne repozytoria. Brak zaszytej gastronomii w A.

PROJEKT: [A albo B]
ROOT: [pełna ścieżka repozytorium]
SCENARIUSZ: [ID albo BRAK]
ZADANIE: [opis]
TRYB: [PLAN; IMPLEMENTACJA tylko po jawnej zgodzie na ten zakres]

PRZED ZMIANĄ PLIKÓW:
1. Potwierdź rzeczywisty root i przeczytaj obowiązujące AGENTS.md.
2. Otwórz _docs_symbiont/START_HERE.md.
3. Przeczytaj wskazaną CORE_CONSTITUTION.md (w B przypięty artefakt zasad A), konstytucję hosta dla B, wizję, architekturę, UX_ENGINEER albo UX_OPERATOR, WORK_PROTOCOL, CURRENT_STATE, HANDOFF oraz pakiet slices/<ID>.md. Dla audio/telefonii przeczytaj VOICE_AND_TELEPHONY.
4. legacy_ADRs to historia, nie drugi aktywny plan. Nie czytaj całego archiwum bez potrzeby.
5. Zweryfikuj realny kod i call-site'y. Rozróżniaj ZATWIERDZONY CEL, ZAIMPLEMENTOWANE, ZWERYFIKOWANE, NIEZNANE. Checkbox nie jest dowodem.
6. Przed edycją przedstaw RAPORT STARTOWY: projekt/repo/ID, wersja Konstytucji, cel użytkownika, zasady, fakty z referencjami, konflikty, zakres frontend/backend/docs, testy i działania wymagające osobnej zgody.

WYKONANIE:
- Brak dokumentu, dostępu albo jednoznacznego zakresu: zgłoś blokadę. Nie wymyślaj brakującej specyfikacji ani konkurencyjnego master-planu.
- Nie rozszerzaj zadania na drugie repo bez jawnego zakresu.
- Stare UI jest materiałem do audytu, nie automatycznie zatwierdzonym rozwiązaniem.
- Scenariusz bez UI, backendu, instrukcji lub testu integracji pozostaje nieukończony.
- Nie wykonuj niezaufanego kodu w Mózgu ani wygenerowanego kodu jako operacji biznesowej.
- Nie odczytuj .env i rzeczywistych sekretów dla poznania projektu. Nie loguj ich.
- Nie uruchamiaj testów na rzeczywistej bazie, drukarce, płatnościach lub telefonii bez zatwierdzonego środowiska i wymaganej zgody. Nie wyszukuj kont ani PIN-ów.
- Nie wykonuj wdrożenia, migracji, usuwania ani przenosin poza zatwierdzonym zakresem. Zgoda na architekturę nie zastępuje zgody na działanie nieodwracalne.
- Konflikt z Konstytucją = STOP i propozycja decyzji, nie osłabienie zasad.

ZAKOŃCZENIE:
Podaj UI / backend i kontrakty / dokumentacja / wykonane testy / nieweryfikowane / stan całego scenariusza. W implementacji aktualizuj CURRENT_STATE i HANDOFF wg WORK_PROTOCOL. W PLAN nie zapisuj zmian bez polecenia. Nie oznaczaj etapu jako odebranego bez odbioru właściciela.
```
