# Sesja: Konstytucja v5

**Data:** 2026-05-11
**Czas trwania:** ~1.5h
**Architekt:** AI (Cloud Agent) z udziałem właściciela
**Branch:** `projektx/constitution-v5-c3d7`

---

## 1. Cel

Naprawić zidentyfikowane problemy w Konstytucji v4 i strukturze docs:

- Literówka „(KSeF)" w Prawie II — KSeF to e-faktury, nie magazyn.
- Drift kod ↔ docs: `WzEngine::consumeForOrder` ma kompletny kod, 0 call-sitów; docs opisują go jako produkcyjny.
- FREEZE NOTICE bez daty odmrożenia → ryzyko dziedziczonego długu.
- Zakaz Node.js zbyt szeroki — w praktyce blokuje współczesne dev-toolingi.
- Sprzeczność liczby praw: Konstytucja mówi „6 praw", North Star mówi „7 praw".
- Prawo VII (Innowacja) bez twardo zdefiniowanego zakresu — łatwa nadinterpretacja na moduły operacyjne.

Decyzja właściciela: **napraw to wszystko jak powinno być**, zanim ruszymy z implementacją Fazy F1+F2 (pętla zużycia POS↔Magazyn + AutoScan Engine).

---

## 2. Pliki dotknięte

| Plik | Co zmieniono |
|---|---|
| `_docs/01_KONSTYTUCJA.md` | **Pełne przepisanie do v5.** Naprawa literówki w II, uzupełnienia w II/VI, formalne zawężenie Prawa VII do online/online_studio, dodane Prawa VIII/IX/X, złagodzenie zakazu Node.js do warstwy runtime. Dodana sekcja „Historia rewizji". |
| `_docs/00_PAMIEC_SYSTEMU.md` | FREEZE NOTICE Offline POS przepisany w formacie Prawa IX (FROZEN_AT/UNTIL/REASON/UNFREEZE_BY/SCOPE). Wprost wyłączone z freeze: `StaffFleetPresence.php` + `DriverFleetHelper.php`. Synchronizacja liczby praw 6→10. Niezmiennik #1 zaktualizowany do „Zero-Reload Runtime". Dodane skrócone opisy Praw VIII/IX/X. |
| `_docs/02_ARCHITEKTURA.md` | Adnotacja przy `WzEngine.php`: ⚠ `consumeForOrder` jest `@planned` (Prawo VIII) — kod kompletny, 0 call-sitów, hook zaplanowany do sesji F1. |
| `core/WzEngine.php` | Dodany pełny docblock `@planned` przy `consumeForOrder()` z konkretnym planem hooka, krokami testu E2E i instrukcją zwolnienia z `@planned`. |
| `api/payments/settle.php` | Header zaktualizowany — status `@planned` z konkretną decyzją do podjęcia (promote albo merge), deadline: kolejna sesja audytu warstwy płatności. |
| `.cursorrules` | Nagłówek v4→v5. Sekcja STOS TECHNOLOGICZNY zaktualizowana o dev-tooling. Dodane sekcje §10/§11/§12 odpowiadające Prawom VIII/IX/X. |
| `_docs/sessions/README.md` | NEW — opis folderu sesji + indeks. |
| `_docs/sessions/2026-05-11_constitution_v5.md` | NEW — ten plik. |

---

## 3. Decyzje architektoniczne

### 3.1 Konstytucja: rozszerzenie, nie zastąpienie

**Alternatywa rozważana:** napisać Konstytucję od zera w nowym stylu / strukturze.

**Wybrane:** zachować dotychczasowe Prawa I–VII bez zmian merytorycznych (poza naprawą literówki w II i uzupełnieniami w II/VI ze zsynchronizowanej już logiki z `00_PAMIEC_SYSTEMU.md`), dodać Prawa VIII/IX/X jako rozszerzenie. Zachowany **głos manifestu** (wykrzykniki, kapitalizacja, pogrubienia kluczowych terminów).

**Powód:** Konstytucja w SaaS to dokument o ogromnej wadze emocjonalnej i technicznej dla założyciela. Przepisanie od zera zniszczyłoby kontynuację. Rozszerzenie z jasną „Historią rewizji" zachowuje ciągłość + wprowadza dyscyplinę.

### 3.2 Prawo VII — formalne zawężenie zamiast usunięcia

**Alternatywa rozważana:** wyrzucić Prawo VII jako subiektywne kryterium („krok przed konkurencją").

**Wybrane:** zostawić, ale **twardo wymienić listę plików** które obejmuje (`modules/online/`, `modules/online_studio/`, `api/online/engine.php`, `api/online_studio/engine.php`, `api/assets/engine.php`, `core/SceneResolver.php`, `core/SceneRenderer.php`, `core/js/scene_renderer.js`). Reszta modułów — wprost wyłączona.

**Powód:** Prawo VII ma sens dla Storefrontu i Online Studio (tam gdzie konkurencja oferuje katalogi SKU, my chcemy teatr fotograficzny). W magazynie / POS / kadrach to balast — tam liczy się żelazna funkcjonalność i UX, nie „innowacja albo nic". Zawężenie eliminuje gun na hamletyczne dyskusje przy każdym dropdown-cie w Settings.

### 3.3 Prawo VIII (Domknięcie Kontraktu) — kluczowe Nowo Wprowadzone

Inspiracja: bug który właśnie znaleźliśmy — `WzEngine::consumeForOrder` udawał produkcyjny, kod kompletny, ale 0 call-sitów. AI czytał docs i zakładał że działa.

**Mechanizm:**
- Funkcja w docs jako produkcyjna → wymaga call-site + test.
- Funkcja kompletna ale niewpięta → adnotacja `@planned (Prawo VIII)` z planem.
- Audyt modułu raportuje listę `@planned` na końcu — nie wolno ukryć.

**Konsekwencja praktyczna:** każda sesja audytu (typu „przejrzyj X") musi się kończyć tabelą drift-u. Kolejny AI/programista wie co jest realne, co planowane.

### 3.4 Prawo IX (Datowane Zamrożenia)

Inspiracja: FREEZE NOTICE Offline POS bez daty odmrożenia, zamrożone od 2026-04-23.

**Mechanizm:** `FREEZE NOTICE` musi mieć `UNTIL` (data lub trigger biznesowy), `REASON`, `UNFREEZE_BY`, `SCOPE`. Po dacie `UNTIL` AI może zaproponować odmrożenie z konkretnym planem.

**Zastosowanie do Offline POS:** `UNTIL = 2026-08-23` lub trigger „3 lokale produkcji + 60 dni bez P0/P1". Wprost wyłączyłem `StaffFleetPresence.php` i `DriverFleetHelper.php` z freeze (były niejednoznaczne — pliki w `core/` oznaczone „NEW · 2026-05-04", ale niejasne czy pod ochroną). To są fleet presence dla mobile apps, NIE offline-POS.

### 3.5 Prawo X (Audyt Sesji AI)

Mechanizm: `_docs/sessions/YYYY-MM-DD_<topic>.md` po każdej znaczącej sesji. 4 sekcje: Cel / Pliki / Decyzje / Otwarte pytania.

**Decyzja:** drobne sesje zwolnione. Próg: jeżeli zmiana zmienia zachowanie systemu, wymaga audytu.

### 3.6 Złagodzenie zakazu Node.js do warstwy runtime

**Alternatywa rozważana:** utrzymać absolutny zakaz „żadnego Node.js, żadnego npm, żadnego build-step" jak w v4.

**Wybrane:** zakaz **runtime** Node.js / framework-ów. Dev-tooling (Vite, esbuild, TypeScript→vanilla JS, prettier, eslint, PostCSS, Sass) **dozwolony lokalnie**, pod warunkiem że output jest commit-owany do repo. Hosting nigdy nie odpala build-step.

**Powód:** zakaz absolutny był ideologicznie spójny ale praktycznie niemożliwy do egzekwowania (Service Worker offline POS i tak jest „toolingiem ręcznym"). Zawęża pulę programistów (PL rynek to TS/React większość). Złagodzenie utrzymuje pełną zgodność deploymentu (czyste pliki na hostingu uti.pl) + otwiera drogę do produktywniejszego dev workflow.

**Reguła kompromisu:** każdy programista po `git clone` ma działający kod **bez `npm install`**. Output dev-toolingu MUSI być w repo. Hosting nigdy nie zobaczy `node_modules/`.

---

## 4. Otwarte pytania

### 4.1 Migracja istniejących `@planned` funkcji

Zidentyfikowane:
- `core/WzEngine.php::consumeForOrder` — sesja **F1** (Pętla zużycia POS↔Magazyn). To była planowana następna sesja przed pytaniem o Konstytucję — teraz robimy ją z czystym fundamentem.
- `api/payments/settle.php` — wymaga decyzji właściciela: promote do canonical settlement albo merge do `pos/engine.php`. Deadline: kolejna sesja audytu płatności (planowanie po fiskalizacji).
- `api/orders/edit.php` / `estimate.php` / `sla_monitor.php` — placeholdery, oznaczone `🟡 PLANNED` w `02_ARCHITEKTURA.md`. Każdy wymaga osobnej sesji.

### 4.2 Czy `_docs/01_KONSTYTUCJA.md` jest jedynym źródłem prawdy?

Obecnie skrót Konstytucji jest w 3 miejscach: `_docs/01_KONSTYTUCJA.md` (kanon), `_docs/00_PAMIEC_SYSTEMU.md` § 1 (skrót), `.cursorrules` § 2-12 (operacyjny dla AI). Synchronizacja zrobiona w v5, ale długoterminowo: **czy nie warto trzymać tylko `01_KONSTYTUCJA.md` jako kanonu** i odsyłać z reszty?

Plus: docs odsyłające do nieistniejących sekcji (np. „§ 15.9 backlog" w opisie `_archive/`) — wymaga osobnej sesji audytu poprawności linków.

### 4.3 `_docs/03_MAPA_KOPALNI.md` — czy nadal aktualne?

Legacy `_KOPALNIA_WIEDZY_LEGACY/` i `_archive/` są poza repo (gitignore). `03_MAPA_KOPALNI.md` to inwentarz historyczny — czy jest aktualizowany przy każdym powrocie do legacy, czy zamrożony? Decyzja architektoniczna: rozważyć przeniesienie legacy do **prywatnego git submodule** (np. `slicehub-legacy-vault`), zamiast trzymać tylko na dysku Damiana — single point of failure.

**Status:** ZAPLANOWANE (nie w tej sesji). Wymaga decyzji właściciela o setup-ie repo prywatnego.

### 4.4 Czy Prawo I (Macierz Cenowa) i Prawo III (Temporal) potrzebują uzupełnień?

W `00_PAMIEC_SYSTEMU.md` mają więcej szczegółów (np. fallback POS w I, soft delete flagi w III) — czy synchronizować do `01_KONSTYTUCJA.md`?

**Decyzja w tej sesji:** zostawiamy. v5 i tak wprowadziła dużo zmian. Pełna synchronizacja Prawa I/III do osobnej sesji jak ktoś zauważy konkretny problem w runtime.

### 4.5 Faza F1 (Pętla zużycia POS↔Magazyn)

To jest następny krok zgodnie z planem właściciela. Konstytucja v5 daje czysty fundament:
- Prawo VIII wymusza wpięcie + test.
- Prawo X wymusza session audit po sesji.

**Plan F1:**
1. Hook `WzEngine::consumeForOrder` w `OrderStateMachine::transitionOrder('accepted')`.
2. Sync inline (nie async) — Konstytucja §3 wymaga że hosting nie odpala workerów; shared hosting uti.pl nie ma stabilnego cron-a.
3. Test E2E: sprzedaj pizzę → sprawdź `wh_stock` przed/po.
4. Po teście: usunąć `@planned` z `WzEngine.php` + zaktualizować listę w `01_KONSTYTUCJA.md` § Prawo VIII + zaktualizować `02_ARCHITEKTURA.md`.
5. Audyt sesji w `_docs/sessions/2026-05-XX_phase_f1_consume_loop.md`.

---

## Test (E2E)

Konstytucja v5 to dokument tekstowy + kilka adnotacji w kodzie — nie ma „testu E2E" w sensie runtime. Weryfikacja:

- ✅ `_docs/01_KONSTYTUCJA.md` — czytany manualnie pod kątem: zachowania głosu, logicznej spójności, braku sprzeczności wewnętrznych.
- ✅ `_docs/00_PAMIEC_SYSTEMU.md` — wyrywkowy grep na „6 praw" (powinien zwracać pusto), „Zero-Reload SPA" (powinien zwracać pusto, zastąpione przez „Zero-Reload Runtime"), `FROZEN_AT` (powinien zwracać 1 trafienie w FREEZE NOTICE).
- ✅ `core/WzEngine.php` — `php -l` (lint).
- ✅ `api/payments/settle.php` — `php -l` (lint).
- ✅ Cross-reference Prawa VIII / IX / X w `01_KONSTYTUCJA.md` ↔ `00_PAMIEC_SYSTEMU.md` ↔ `.cursorrules` — synchronizacja zachowana.

---

**Status sesji: ✅ DONE.** Branch `projektx/constitution-v5-c3d7` gotowy do merge / push.
