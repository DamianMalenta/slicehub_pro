# Faza 1 — fundament (API, kolejka, RPA)

**Typ planu:** [B — wdrożenie bez zmiany „co”](../../PROCES_ZMIANY_I_RAPORT.md) (edycje głównie `plan/` + implementacja **C** w `gema0/`). Nowej intencji produktowej (**A**) tu nie wprowadzasz bez wpisu w [`wizja/`](../../wizja/README.md).

## Cel

Utrwalić warstwę już istniejącą w [`../../gema0/`](../../gema0/README.md) jako **fundament** pod dalsze funkcje — bez zmiany filozofii, chyba że [wizja/05](../../wizja/05_mapowanie_na_gema0.md) wskaże lukę krytyczną.

## Rola → zakres w tej fazie

| Rola | Co robisz tutaj |
|------|------------------|
| **Architekt / autor wizji** | Tylko jeśli fundament wymaga decyzji „co” — wtedy najpierw akt w [`wizja/`](../../wizja/README.md), potem powrót do planu. |
| **Planista wdrożenia** | Utrzymujesz spójność tego pliku z [`../00_zrodla_wizji.md`](../00_zrodla_wizji.md), [`../01_ryzyka_i_zalozenia.md`](../01_ryzyka_i_zalozenia.md); odhaczasz rzeczywiste postępy w checklistach. |
| **Implementer Node/Python** | Kod w [`gema0/`](../../gema0/README.md) zgodnie z [wizja/05](../../wizja/05_mapowanie_na_gema0.md); po zmianie zachowania API/workerów — aktualizacja **tylko** [`wizja/05_mapowanie_na_gema0.md`](../../wizja/05_mapowanie_na_gema0.md) (patrz PROCES, typ **C**). |
| **Operator / tester RPA** | Uruchomienie, healthcheck, `windows_map.json`, scenariusze `DRY PUSH` / `PUSH TO` — bez edycji `wizja/` o ile nie zmienia się produkt. |

Szerszy kontekst ról: [`../../START_TUTAJ_CURSOR.md`](../../START_TUTAJ_CURSOR.md).

## Odniesienia do kodu

- [`../../gema0/backend/routes/chat.js`](../../gema0/backend/routes/chat.js) — endpoint `/api/chat`, payload push.
- [`../../gema0/backend/services/queue.js`](../../gema0/backend/services/queue.js) — kolejka sekwencyjna.
- [`../../gema0/rpa_workers/push_to_cursor.py`](../../gema0/rpa_workers/push_to_cursor.py) — sekwencja RPA.

## Zadania (szkielet)

- [ ] Potwierdzić stabilność timeoutów workerów (`rpaDispatcher`) dla dużych payloadów.
- [ ] Rozszerzyć API tylko tam, gdzie wizja wymaga (np. flaga `submit` z panelu) — odwołanie: [wizja/03](../../wizja/03_panel_ui_historia.md).
- [ ] Utrzymać test ręczny: `HEALTHCHECK`, `DRY PUSH`, `PUSH TO`.

## Zależności wizji

- [../../wizja/01_granice_i_zasady.md](../../wizja/01_granice_i_zasady.md)
- [../../wizja/05_mapowanie_na_gema0.md](../../wizja/05_mapowanie_na_gema0.md)

Powrót: [../README.md](../README.md)
