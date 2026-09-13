# Handoff Wszczepu B

Aktywny scenariusz: BRAK — Etap A zamknięty i odebrany przez właściciela 2026-09-13. Konstytucja A/B: 1.0.0. Implementacja master-planu zatwierdzona 2026-09-11. Stan: [CURRENT_STATE.md](CURRENT_STATE.md).

## Priorytet
A-001-B i A-002-B są odebrane: Wszczep read-only działa w izolowanym pilocie, panel administratora odrzuca złe kanały, a wejście z Huba oraz powrót zostały potwierdzone w przeglądarce. Apache pozostaje poprawnie DISABLED bez osobnej konfiguracji.

Następna praca wymaga nowego ID scenariusza. Nie rozszerzaj Wszczepu o kompetencje biznesowe, role pracownika, audio lub telefonię bez osobnej specyfikacji i zgody.

## Granice
Brak zmian DB, urządzeń, telefonii, istniejącej Konstytucji i zamrożonego offline POS. Endpointy domyślnie wyłączone; sekrety dostarcza administrator poza czatem. Instrukcja i testy muszą rozróżniać service authentication od panelu integracji. Odłączenie w A nie wyłącza procesu PHP.

## Przyszłe decyzje
Referencyjny Android/słuchawka, STT/TTS i źródło linii wymagają wyboru przed C. Wpięcie ról pracownika jest obowiązkowe przed operacjami biznesowymi. Produkcyjna konfiguracja Apache/PHP i sekretów wymaga osobnej procedury.
