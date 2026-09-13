# Start — Projekt B / Wszczep SliceHub

Kanon integracji Symbionta, zatwierdzony 2026-09-11. SliceHub pozostaje osobnym produktem PHP/Vanilla JS/MariaDB, nie podkatalogiem Mózgu.

## Kolejność
1. [CORE_CONSTITUTION.md](CORE_CONSTITUTION.md) — przypięta kopia zasad A v1.0.0, nie lokalny fork.
2. [HOST_CONSTITUTION.md](HOST_CONSTITUTION.md) i [Konstytucja SliceHub](../_docs/01_KONSTYTUCJA.md).
3. [HOST_VISION.md](HOST_VISION.md).
4. [GRAFT_ARCHITECTURE.md](GRAFT_ARCHITECTURE.md).
5. [UX_OPERATOR.md](UX_OPERATOR.md); dla głosu/telefonii [VOICE_AND_TELEPHONY.md](VOICE_AND_TELEPHONY.md).
6. [WORK_PROTOCOL.md](WORK_PROTOCOL.md) — przypięty wspólny protokół pracy v1.0.0.
7. [CURRENT_STATE.md](CURRENT_STATE.md) i [HANDOFF.md](HANDOFF.md).
8. [A-001-B](slices/A-001-B.md) i [A-002-B](slices/A-002-B.md) — zamknięte scenariusze Wszczepu; kolejny zakres wymaga nowego ID.

## Odebrany przyrost Etapu B
[B-001 — udostępnienie zatwierdzonych źródeł](slices/B-001.md): ACCEPTED_BY_USER, zapis odbioru 2026-09-14 +02:00. Brak aktywnego scenariusza; następny wymaga nowego ID, Definition of Ready i zgody właściciela. Osobny [engineering-read v1](../core/Symbiont/contracts/engineering-read-v1.json) i [polityka trzech plików](../core/Symbiont/contracts/b001-package.json). Bez rozszerzenia Bridge v1 ani praw pracownika. Odbiór odnotowuje CURRENT_STATE.

## Obsługa
[RUNBOOK_A.md](RUNBOOK_A.md): konfiguracja procesu PHP, diagnostyka i wyłączenie. [Baseline v1](../core/Symbiont/contracts/core-baseline-v1.json): przypięte artefakty A z kontrolą integralności.

## Kontrakty
[Bridge v1](../core/Symbiont/contracts/protocol-v1.json) jest przypiętym artefaktem A. B implementuje własny adapter PHP, bez runtime dostępu do repo A. Opis semantyki: [GRAFT_ARCHITECTURE.md](GRAFT_ARCHITECTURE.md). Zasady biznesowe pozostają w istniejącym _docs; nie tworzymy konkurencyjnej kopii schematu ERP.

## Nowa sesja
Stosuj [BOOT_PROMPT.md](BOOT_PROMPT.md). Przy braku dokumentu lub niezgodnej wersji zatrzymaj pracę; nie zgaduj kontraktu. Zatwierdzony projekt != wdrożona funkcja. Jedyny rejestr integracji to CURRENT_STATE.
