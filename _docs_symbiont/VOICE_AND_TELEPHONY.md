# Audio i telefonia — kontrakt doświadczenia

Status: APPROVED jako kierunek; sprzęt/dostawca NIE WYBRANY. To zależność scenariusza C, nie blokada diagnostyki A. Stan: [CURRENT_STATE.md](CURRENT_STATE.md).

## Tor wejścia
Słuchawka/mikrofon -> klient B -> wybudzenie „Maciuś” / push-to-talk / przycisk -> VAD i koniec wypowiedzi -> STT -> finalny tekst + utterance_id + sesja pracownika/host/tenant -> A. Odpowiedź/żądanie kompetencji wraca do B; B autoryzuje i wykonuje; potwierdzony wynik jest przedstawiony tekstem i TTS.

Utterance_id jest stabilny przy retry. Częściowa transkrypcja jest podglądem, nie komendą. Przerwanie TTS nie cofa skutku. Powtarzane „tak” jest związane z konkretną oczekującą zgodą, nie następną dowolną akcją.

## Urządzenia
Etap B: tekst + rzeczywisty PTT. Etap C: wake word na zatwierdzonym urządzeniu, fallback PTT i ręczny. Preferowany pierwszy profil: klient towarzyszący Android + wybrana słuchawka; wymaga potwierdzenia sprzętu, pracy przy wygaszonym ekranie i toru Bluetooth. Klient to artefakt repo B, nie trzeci produkt.

Web Speech API nie gwarantuje uniwersalnej dostępności, działania offline ani przetwarzania lokalnego. Nie przyjmujemy takiej gwarancji bez testu konkretnej platformy. STT/TTS pozostają wymiennymi adapterami; wybór po ocenie polskiego, hałasu, prywatności i kosztów.

## Telefon
Domyślne „odbierz telefon” odbiera wskazane połączenie do słuchawki pracownika. Samodzielny recepcjonista AI jest osobną kompetencją wymagającą zatwierdzenia. Adapter otrzymuje zdarzenie ringing z call_id; A proponuje telephony.answer, B sprawdza tożsamość pracownika, call_id i stan, potem potwierdza rzeczywiste połączenie.

Rekomendacja: kontrolowany VoIP/SIP lub oficjalny interfejs telefonii. GSM wymaga integracji systemowej; nie obiecujemy obsługi dowolnego telefonu przez stronę WWW. Obecny PersonalPhoneChannel jest kanałem SMS, nie połączeń.

## Granice
Audio rozmówcy nie jest kanałem komend pracownika. Wake word nie uwierzytelnia. Nie transmitujemy domyślnie całodziennego mikrofonu. Wskaźnik nasłuchu, wyciszenie, minimalizacja audio, retencja i zewnętrzny procesor są jawne. Sekrety i dane płatnicze nie trafiają do transkrypcji diagnostycznej.

## Odbiór
Macierz: urządzenie/OS, słuchawka/profil Bluetooth, PL STT/TTS, ekran aktywny/uśpiony, szum, podobne numery, utrata sieci, fałszywe wybudzenie, rozmówca mówiący wake word, echo TTS, bateria, ręczne przejęcie. Mierzymy odsetek błędnych aktywacji i p95 odpowiedzi na wybranym sprzęcie; brak obietnic liczbowych przed pomiarem. Symulator rozmowy nie zalicza połączenia na rzeczywistej linii.
