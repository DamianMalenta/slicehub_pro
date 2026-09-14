# Interfejs Operatora

APPROVED 2026-09-11; wdrożenie: [CURRENT_STATE.md](CURRENT_STATE.md).

## Dwa widoki B
Administrator integracji widzi stan włączenia, konfigurację, połączenie, uprawnienia, instrukcję i diagnostykę. Pracownik widzi polecenie, pytanie, skutek i wynik; nie widzi kodu, AST, tokenów ani torów generacji. Nie osadzamy panelu Inżyniera w iframe jako UI kuchni.

## Makieta
Góra: lokal, zalogowany pracownik, połączenie, słuchawka/mikrofon. Centrum: ostatnio zrozumiane polecenie, bieżąca karta zadania, pytanie lub potwierdzony skutek. Dół: tekst/PTT, zatrzymanie słuchania, ręczne przejęcie. Osobna kolejka spraw wymagających człowieka. Komunikaty mają tekstowe etykiety i aria-live; nie polegamy wyłącznie na kolorze lub dźwięku.

## A-001-B/A-002-B
Ekran Wszczepu jasno pokazuje „diagnostyka read-only”. Wymaga uprawnienia administratora integracji, nie ujawnia sekretów. Brak konfiguracji powoduje instrukcję, nie uruchomienie trybu demo. Wyłączenie nie wpływa na POS. Wejście z Huba jest nawigacją administratora, nie automatycznym uwierzytelnieniem ani panelem pracownika. Audio i kompetencje biznesowe są oznaczone jako niewdrożone, bez działających atrap przycisków.

## B-001 — administrator pakietu źródeł (zatwierdzony 2026-09-13)
Link z panelu diagnostycznego prowadzi do osobnego ekranu metadanych B-001. Nowy klucz administratora pakietu nie jest kluczem diagnostycznym ani sesją pracownika. „Sprawdź udostępnienie” pokazuje rzeczywisty manifest i czas obserwacji z PHP; „Wyczyść i zablokuj” usuwa wynik i unieważnia spóźnione odpowiedzi w UI. Ekran nie zmienia konfiguracji serwera i nie udostępnia kodu administratorowi metadanych. Kod, zgoda i historia są w panelu Inżyniera A. Błędy wyłączenia, auth i integralności nie uruchamiają demo. Przepływ i odbiór: [B-001](slices/B-001.md).

## B-002 — administrator katalogu
Osobny ekran pokazuje rzeczywisty katalog kompetencji integracyjnych z wersją, rodzajem, statusem, rolą i skutkami. Stale komunikuje, że odkrywanie nie jest wykonaniem ani nadaniem praw. Wymaga osobnego klucza, nie zapisuje go i umożliwia wyczyszczenie wyniku. Nie jest panelem pracownika.

## Docelowy dialog
„Dodaj sos czosnkowy do piątki” -> ustalenie jednoznacznego zamówienia i właściwego SKU -> ewentualne pytanie pozycja/modyfikator -> preview skutku -> zgoda wg polityki -> wykonanie -> krótka odpowiedź oparta o wynik. Brak SKU lub niejednoznaczność to pytanie/odmowa, nie fikcyjna zmiana.

„Zamknij i wydrukuj” -> płatność, status i uprawnienia -> kontrolowany proces -> oddzielne potwierdzenie rozliczenia i fiskalizacji. Brak odpowiedzi drukarki nie daje „wydrukowano”.

„Zrób inwentaryzację” -> otwarcie spisu -> rzeczywiste pomiary -> różnice -> polityka zatwierdzenia -> dokument. Stan ewidencji nie zastępuje pomiaru.

## Stany
Wyciszony, Gotowy, Słucham, Rozpoznaję, Doprecyzuj, Potwierdź, Wykonuję, Wykonano, Odmowa, Wynik nieznany. Ekran i TTS mówią to samo. Częściowa transkrypcja nie uruchamia komendy. Stop zatrzymuje przyszłe kroki, nie cofa dokonanych skutków.

## Testowanie
Osobno testy modelu widoku, integracji HTTP, headless interfejsu i rzeczywistego sprzętu. Zgodnie z AGENTS nie uruchamiamy interaktywnego browser-walka ani istniejącego runnera na rzeczywistej bazie. Odbiór słuchawki wymaga referencyjnego urządzenia i hałasu kuchni.
