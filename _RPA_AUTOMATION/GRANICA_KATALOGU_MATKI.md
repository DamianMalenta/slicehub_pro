# Granica katalogu „matki” (workspace → sandbox `_RPA_AUTOMATION`)

Krótka definicja zakresu: **co jest w środku sandboxu**, a **czego nie tykać** bez wyraźnej zgody (reszta monorepo SliceHub).

## W środku (domyślny zakres pracy)

- **`_RPA_AUTOMATION/`** — automatyzacja RPA / Cursor (Windows).
- **`_RPA_AUTOMATION/gema0/`** — **GEMA-0 Command Center** (backend Node, worker Python, panel).

Intencja produktowa przechodzi przez **`wizja/`**; wdrożenie przez **`plan/`** — patrz [`START_TUTAJ_CURSOR.md`](START_TUTAJ_CURSOR.md).

## Na zewnątrz (poza tym folderem)

- PHP, moduły SliceHub, `database/`, `api/` itd. — **poza zakresem**, dopóki użytkownik wyraźnie nie rozciągnie zadania.
- Nie dopisuj wymagań z „dużego” repo do `wizja/` bez uzgodnienia — granice produktu: [`wizja/01_granice_i_zasady.md`](wizja/01_granice_i_zasady.md).

## Gdzie czytać dalej

| Potrzeba | Plik |
|----------|------|
| Role (kto czego dotyka) | [`START_TUTAJ_CURSOR.md`](START_TUTAJ_CURSOR.md) — sekcja **Role** |
| Typ zmiany → które foldery | [`PROCES_ZMIANY_I_RAPORT.md`](PROCES_ZMIANY_I_RAPORT.md) |
