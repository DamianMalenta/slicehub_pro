# Konstytucja Rdzenia Symbiont

Wersja: 1.0.0. Status normatywny: APPROVED. Akceptacja właściciela: 2026-09-11, zatwierdzony audyt i master-plan dwóch produktów. Akceptacja nie jest dowodem implementacji; ten opisuje [CURRENT_STATE.md](CURRENT_STATE.md).

## C1 — Uniwersalność
Rdzeń nie zawiera reguł konkretnego hosta. Wiedzę domenową otrzymuje jako wersjonowane kontrakty i fakty. Host testowy musi działać bez pojęć gastronomicznych.

## C2 — Niezależność
Projekt A i Projekt B mają osobne repozytoria, procesy, sekrety, dane i wydania. Brak importów między ich drzewami. Protokół jest artefaktem A przypiętym przez B, nie trzecim produktem.

## C3 — Kompletność
Scenariusz jest ukończony dopiero, gdy jego interfejs, backend, dokumentacja i integracja są zweryfikowane. Zmiana wewnętrzna może pozostawić UI bez edycji, lecz wymaga sprawdzenia jego kontraktu. Brak atrap udających gotowość.

## C4 — Prawdziwy interfejs
UI przedstawia stan potwierdzony przez backend. Wygenerowano, zapisano, przetestowano, zaakceptowano i wdrożono to różne stany. Rozłączenie nigdy nie uruchamia symulacji. Replay jest odczytem, nie ponownym wykonaniem.

## C5 — Dwa tory wykonania
ENGINEER tworzy propozycje zmian kodu. OPERATE wywołuje uprzednio wdrożone kompetencje. Operator nie wykonuje wygenerowanego kodu, arbitralnego SQL, powłoki ani dowolnego URL na hoście.

## C6 — Zero Trust
Model, plik, transkrypcja i manifest są niezaufanym wejściem. Nie nadają sobie uprawnień. Autoryzacja obejmuje aktora, delegację, host, środowisko, tenant, kompetencję, zakres i czas ważności. Host sprawdza ją ponownie.

## C7 — Własność domeny
Host jest właścicielem danych i niezmienników DDD. Wszczep deleguje do usług domenowych; nie duplikuje ich reguł. Host_id nie jest tenant_id. Dane różnych hostów i tenantów pozostają odseparowane.

## C8 — Dowody
Twierdzenia i decyzje wskazują źródło, wersję, zakres i aktualność. AST dowodzi struktury, nie poprawności biznesowej. Nieodczytane i nieobsługiwane nie znaczy poprawne. Nie wolno cicho obcinać kontekstu i udawać pełnego badania.

## C9 — Trwała praca
Zadania, ich decyzje i skutki są odtwarzalne po restarcie. Pamięć procesu i SSE nie są jedynym źródłem prawdy. Potrzebne są jawne stany częściowego i nieznanego skutku.

## C10 — Skutki
Komendy mają identyfikator, warunki wstępne, oczekiwaną wersję i klucz deduplikacji związany z payloadem. Timeout nie dowodzi braku wykonania. Dla urządzeń fizycznych wymagane jest uzgodnienie wyniku; nie obiecujemy exactly-once.

## C11 — Inżynieria
Praca nad kodem odbywa się w izolowanym runnerze i checkout/worktree repo właściwego hosta, bez sekretów produkcyjnych. Worktree nie jest sandboxem procesu; node:vm nie jest granicą bezpieczeństwa. Testy odpowiadają stackowi hosta. Agent nie zatwierdza własnego PR.

## C12 — Zgody
Akceptacja celu nie zastępuje zgody na konkretne działanie nieodwracalne, migrację, wdrożenie lub usunięcie danych. Zgoda jest związana z planem i artefaktem; ich istotna zmiana wymaga nowej oceny.

## C13 — Prywatność
Sekrety nie trafiają do promptów, publicznych manifestów ani logów. Audio i dane osobowe podlegają minimalizacji, jawnej polityce retencji i zatwierdzeniu usług zewnętrznych. Głos i wake word nie są mechanizmem uwierzytelnienia.

## C14 — Ciągłość
Host działa ręcznie bez Mózgu. Wyłączenie Wszczepu nie usuwa danych. Zaległych komend nie wykonujemy bez kontroli ważności. Stop/anuluj nie obiecuje cofnięcia już wykonanego skutku.

## C15 — Ewolucja
Normatywne zasady, zweryfikowany stan i historia to trzy różne role dokumentacji. Konflikt wymaga jawnej decyzji. Kontrakty są wersjonowane i testowane po obu stronach. Nie przywracamy starej roadmapy z archiwum.
