# Sesje AI — folder audytu (Prawo X Konstytucji v5)

Każda sesja AI zmieniająca `core/`, `api/`, `database/migrations/` lub `_docs/01_KONSTYTUCJA.md` zostawia tutaj plik o nazwie:

```
YYYY-MM-DD_<topic>.md
```

Zawartość — 4 sekcje:

1. **Cel** — jednym zdaniem co sesja miała osiągnąć.
2. **Pliki dotknięte** — lista plików + jednolinjowy opis każdego.
3. **Decyzje architektoniczne** — co świadomie wybrano i dlaczego (zwłaszcza tam gdzie były alternatywy).
4. **Otwarte pytania** — co kolejna sesja musi rozstrzygnąć.

**Drobne sesje** (literówka, fix lint, drobny CSS) — zwolnione. Zasada: jeśli zmiana zmienia zachowanie systemu, wymaga audytu.

**Cel folderu:** kolejny AI / programista / właściciel w 30 sekund rozumie *co*, *dlaczego* i *co dalej* — bez archeologii w git history.

---

## Indeks sesji

- [`2026-05-11_constitution_v5.md`](2026-05-11_constitution_v5.md) — Konstytucja v5: Prawa VIII / IX / X + naprawa drift-u kod-docs.
