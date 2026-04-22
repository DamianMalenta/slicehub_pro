# Proces zmian, miejsca edycji i raport końca pracy

**Cel:** żeby przy każdej zmianie dotykać **jak najmniej plików** — tylko właściwych — i żeby wizja, plan i kod nie rozjechały się bez śladu.

**Zanim cokolwiek zmienisz:** przeczytaj najpierw [`START_TUTAJ_CURSOR.md`](START_TUTAJ_CURSOR.md), potem **ten plik**.

---

## 1. Typ zmiany → edytuj TYLKO te miejsca

| Typ zmiany | Edytuj przede wszystkim | Nie rozciągaj bez potrzeby na |
|------------|-------------------------|-------------------------------|
| **A. Nowa lub zmieniona intencja produktowa** („co ma robić system”) | [`wizja/`](wizja/README.md) — odpowiedni plik tematyczny (`01`–`05`); zamknięcia właściciela w `wizja/99_*` (**KONIEC**) | Kod w `gema0/` dopóki nie ma decyzji wdrożeniowej; nie dopisuj wymagań „wprost” do `plan/` bez wpisu w `wizja/` |
| **B. Zmiana sposobu lub kolejności wdrożenia** (bez zmiany „co”) | [`plan/`](plan/README.md), [`plan/fazy/`](plan/fazy/), [`plan/99_analiza_i_roadmap.md`](plan/99_analiza_i_roadmap.md); zaktualizuj [`plan/00_zrodla_wizji.md`](plan/00_zrodla_wizji.md) jeśli pojawił się nowy plik wizji | Pliki `wizja/*` — tylko jeśli faktycznie zmienia się produkt |
| **C. Implementacja w kodzie** (Node / Python / front) | [`gema0/`](gema0/README.md) — tylko potrzebne pliki źródłowe | `wizja/` — **wyjątek:** zaktualizuj [`wizja/05_mapowanie_na_gema0.md`](wizja/05_mapowanie_na_gema0.md), jeśli zmieniło się zachowanie widoczne dla użytkownika lub kontrakt API |
| **D. „Ustaliliśmy — temat zamknięty”** (akcept właściciela) | Jedna linia w [`wizja/99_analiza_podsumowanie.md`](wizja/99_analiza_podsumowanie.md) lub [`plan/99_analiza_i_roadmap.md`](plan/99_analiza_i_roadmap.md), koniec linii: **`KONIEC`** | Nie duplikuj zamknięcia w pięciu plikach — jedna lista „prawdy” w `99_*` |
| **E. Konfiguracja środowiska / uruchomienie** | [`gema0/README.md`](gema0/README.md), `gema0/backend/config/*`, `.env` (nie commituj sekretów) | `wizja/` — tylko jeśli zmienia się założenie produktowe (np. nowy typ ryzyka) |
| **F. Tylko legacy PoC** (AHK, PowerShell w korzeniu `_RPA_AUTOMATION/`) | Wyłącznie dotykane skrypty + jedna notka w raporcie sesji | Nie zapisuj tego jako „oficjalny plan GEMA” bez spięcia z [`plan/`](plan/README.md) |

**Złota zasada synchronizacji:** jeśli zmieniłeś **zachowanie** widoczne z zewnątrz (panel, API, worker RPA), **jedna aktualizacja** [`wizja/05_mapowanie_na_gema0.md`](wizja/05_mapowanie_na_gema0.md) — żeby następna osoba nie czytała wizji sprzecznej z kodem.

---

## 2. Krótko: co robić na starcie sesji (per rola)

| Rola | Na starcie przeczytaj | Potem (tylko jeśli Twoja rola) |
|------|------------------------|--------------------------------|
| **Autor wizji** | [`START_TUTAJ_CURSOR.md`](START_TUTAJ_CURSOR.md) → [`wizja/README.md`](wizja/README.md) | Edytuj wyłącznie pliki w `wizja/` wg tematu; nie koduj bez osobnego zadania |
| **Planista wdrożenia** | START → [`plan/README.md`](plan/README.md) → [`plan/00_zrodla_wizji.md`](plan/00_zrodla_wizji.md) | Edytuj `plan/`; każdy nowy wątek produktowy musi mieć punkt wyjścia w `wizja/` |
| **Implementer** | START → [`wizja/05_mapowanie_na_gema0.md`](wizja/05_mapowanie_na_gema0.md) → aktywny plik z [`plan/fazy/`](plan/fazy/) → [`gema0/README.md`](gema0/README.md) | Kod w `gema0/`; po zmianie zachowania — aktualizacja `wizja/05` |
| **Operator / tester RPA** | START → [`gema0/README.md`](gema0/README.md) | Konfiguracja okien, healthcheck; bez edycji `wizja/` jeśli nie zmieniasz produktu |

---

## 3. Co aktualizować razem (żeby nie było pomylek)

Po **C (implementacja)** sprawdź jednym rzutem oka:

1. Czy [`wizja/05_mapowanie_na_gema0.md`](wizja/05_mapowanie_na_gema0.md) nadal opisuje stan „już jest / luka”?
2. Czy aktywna **faza** w [`plan/fazy/`](plan/fazy/) ma checkboxy zgodne z rzeczywistością (możesz odhaczyć wykonane w tej samej edycji)?
3. Czy nie zostały **osierocone** wpisy w [`plan/00_zrodla_wizji.md`](plan/00_zrodla_wizji.md) — jeśli dodałeś **nowy** plik wizji, dopisz wiersz w tabeli.

**Nie** masz obowiązku dotykać `wizja/01`–`04` przy czystym refaktorze wewnętrznym bez zmiany zachowania — wtedy wystarczy raport i ewentualnie `05`.

---

## 4. Minimalny raport końca sesji (obowiązkowy zwyczaj)

Wklej poniższy szablon na końcu czatu z agentem, do notatki w projekcie albo do opisu commita. **To jedyna wymagana „notatka zbiorcza”** — reszta jest w Git i w plikach, które faktycznie zmieniłeś.

```markdown
### Raport sesji — RPA / GEMA-0

- **Data:**
- **Zakres pracy:** (wizja / plan / gema0 / dokumentacja root / legacy skrypt)
- **Pliki zmienione:** (ścieżki względem `_RPA_AUTOMATION/`)
- **Zsynchronizowano dokumentację:** wizja05 / plan-fazy / 00_zrodla — (tak / nie / nie dotyczy)
- **Krótko co zrobiono:** (1–3 zdania)
- **Następny krok lub ryzyko:** (opcjonalnie)
```

Jeśli **nie było zmian w repo** (same konsultacje), wpisz jedną linijkę: `Brak zmian w plikach — doradztwo / przegląd.`

