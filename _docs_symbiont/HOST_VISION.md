# Wizja B — Host z cyfrowym pracownikiem

APPROVED 2026-09-11; stan implementacji: [CURRENT_STATE.md](CURRENT_STATE.md).

Symbiont najpierw pomaga zbudować SliceHub przez bezpieczną inżynierię. Następnie jest pracownikiem działającym przez kompetencje biznesowe. Nie jest kolejną zakładką ze skanerem kodu. Mózg pozostaje niezależny, a Wszczep nadaje mu kontrolowany dostęp do rzeczywistej domeny.

Operator kuchni/obsługi używa własnego panelu i docelowo słuchawki. Polecenia: dodanie istniejącego modyfikatora do jednoznacznego zamówienia, rozliczenie i druk z kontrolą skutku, inwentaryzacja oparta o rzeczywisty spis. Domyślne „odbierz telefon” łączy z pracownikiem; samodzielny recepcjonista AI jest osobnym zakresem.

## Odpowiedzialności
B: reguły DDD, stan, autoryzacja pracownika, kontrola tenantów, panel Operatora, urządzenia, STT/TTS i telefonia. A: rozumowanie, plan, polityki delegacji, historia i nadzór. A poznaje słownik i kompetencje z wersjonowanych danych B, nie z hardcoded gastronomii.

## Interfejs
Operator nie widzi AST, diffów, kluczy modeli ani torów generowania kodu. Widzi zrozumiane polecenie, pytanie, dozwolony skutek i wynik potwierdzony przez hosta. Administrator ma osobny widok ustawień integracji. Etap A dostarcza tylko ten widok i diagnostykę, nie udaje gotowego pracownika.

## Granice odbioru
Każda kompetencja ma UI/dialog, backend, instrukcję i test. Głos nie jest dodatkiem „na koniec”; projektujemy go od początku, wdrażając read-only w B, operacje i wake word w C, odporność w D. Pusta obietnica w panelu nie jest funkcją.
