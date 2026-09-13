# Konstytucja Wszczepu B

Wersja 1.0.0. APPROVED 2026-09-11. Zasady nadrzędne domeny: [_docs/01_KONSTYTUCJA.md](../_docs/01_KONSTYTUCJA.md). Wspólny kontrakt: [CORE_CONSTITUTION.md](CORE_CONSTITUTION.md).

1. SliceHub jest odrębnym modularnym monolitem LAMP. Wszczep jest PHP, panel jest natywnym Vanilla JS. Nie dodajemy Node/React/Composera do runtime hosta.
2. Host jest właścicielem cen, podatków, stanów zamówień, rozliczeń i magazynu. Mózg nie obchodzi CartEngine, usług domenowych ani izolacji tenant_id.
3. Host_id identyfikuje instalację, nie restaurację. Kompetencje biznesowe wymagają właściwego tenanta i aktora; token diagnostyczny A nie jest sesją pracownika.
4. Wszczep ma domyślnie wyłączone działanie. Etap A udostępnia tylko opis i diagnozę mostu, bez DB, plików źródłowych, audio i komend biznesowych.
5. Zatwierdzone kontrakty nie uprawniają do wykonywania arbitralnego SQL, PHP, JS, powłoki ani dowolnych wywołań API.
6. Produkcyjny Operator używa kompetencji z walidacją, deduplikacją i kontrolą wersji. Nie wykonywać ich przed testami domeny i odbiorem.
7. Ceny przelicza serwer; SKU i ilości pochodzą z istniejącego katalogu. Niepewny numer zamówienia wymaga rozstrzygnięcia, nie zgadywania.
8. Faktu płatności, fizycznego spisu lub wydruku nie wolno wywnioskować z samej transkrypcji. Nieznany skutek wymaga uzgodnienia.
9. Progi zatwierdzania korekt pochodzą z polityki hosta. Model nie dobiera progów, aby zatwierdzić własną pracę.
10. Audio klienta i personelu to osobne konteksty uprawnień. Wake word nie uwierzytelnia. Operator ma tekstowy i ręczny fallback.
11. Wyłączenie A/Wszczepu pozostawia POS i dane nietknięte. Brak automatycznego odtwarzania przeterminowanych komend.
12. Testy integracji używają izolowanych fixtures. Nie uruchamiaj skanowania PIN-ów, seedów, resetu DB, fiskalizacji lub rzeczywistych rozmów bez osobnej zgody.
13. Zmiana granic DDD lub zamrożonego offline POS wymaga odrębnej decyzji; Wszczep nie jest drogą obejścia tych ograniczeń.
