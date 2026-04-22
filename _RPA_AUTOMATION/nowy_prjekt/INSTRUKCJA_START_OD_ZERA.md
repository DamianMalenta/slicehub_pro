# Nowy projekt: praca przez polecenia w plikach `.md`

Idea jest prosta: **Ty sam piszesz** (lub wspólnie ustalacie) **tekst w plikach `.md`** — to jest źródło prawdy dla chłopaków i dla Cursora. Czat służy do doprecyzowań, ale **decyzja stoi w repo**.

---

## 1. Jeden główny folder na ten sposób pracy

W **nowym** repozytorium (np. strona, inna apka) utwórz **jeden** katalog, np.:

- `polecenia/` albo `docs/ustalenia/` albo `RPA_SANDBOX` — nazwa obojętna, byle **wszyscy wiedzieli: „tu są pliki, z których się pracuje”**.

Możesz użyć skryptu [tools/bootstrap_nowy_projekt.ps1](tools/bootstrap_nowy_projekt.ps1) z parametrem `-TargetRoot` — stworzy szkielet z przykładowymi **`BRIEF.md`**, **`KOLEJKA.md`** oraz krótkimi plikami startowymi i skopiuje tę instrukcję do `_instrukcje_zrodlowe/`.

---

## 2. Co wkładać do plików (minimum)

| Plik (przykładowa nazwa) | Zawartość |
|--------------------------|-----------|
| `BRIEF.md` albo `00_CEL.md` | O co chodzi w projekcie, dla kogo, must-have |
| `KOLEJKA.md` albo `ZADANIA.md` | Kto co robi, co jest „następne w kolejce” (nawet tabela markdown) |
| Kolejne `01_...md`, `02_...md` | Rozbicie tematów: layout, treści, integracje — **Ty decydujesz** |

Zasada: **jeden wiodący plik** z celem, reszta albo linkuje do niego, albo ma w nagłówku „zależność: …”.

Cursor i ludzie dostają kontekst przez **`@nazwa_pliku.md`** w swoim workspace.

---

## 3. Jak zespół ma z tego korzystać

1. Pull / otwarcie projektu w Cursorze.
2. Pierwsza rzecz: przeczytać **`BRIEF`** (albo jak nazwiesz plik celu).
3. Zadanie w czacie / w issue = **„zrób zgodnie z `@KOLEJKA.md` punkt X”** albo „zgodnie z `@01_layout.md`”.
4. Po zmianie w kodzie — krótka notka w `KOLEJKA.md` lub w PR („zrobione: …”), żebyś Ty widział postęp bez zgadywania.

---

## 4. Granica (opcjonalnie)

Jeśli chcesz, żeby **agent AI nie edytował** części repozytorium, dopisz regułę w `.cursor/rules` albo trzymaj **cały projekt mały** i pracujcie tylko w jednym folderze — to już polityka zespołu, nie technologia z innego podfolderu SliceHub.

---

## 5. Co jest świadomie poza tą instrukcją

Automatyczne skrypty, panele do wysyłania czatu, integracje z Gemini — **nie są potrzebne**, żeby zacząć. Ty piszesz `.md`, oni czytają i robią.

Szczegóły organizacyjne (logistyka, kto zatwierdza): [OCZEKIWANIE_NA_UZGODNIENIA_ZESPOLU.md](OCZEKIWANIE_NA_UZGODNIENIA_ZESPOLU.md).
