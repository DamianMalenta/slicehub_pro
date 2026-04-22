# Start tutaj — jak uzupełniać katalog wizji

## Cel tego pliku

Instrukcja dla kolejnych sesji Cursor (i dla Ciebie): **nie rozrzucać wizji po jednym gigantycznym pliku** — każdy temat ma osobny dokument połączony linkami z [README.md](README.md).

## Minimalna checklista pytań przed dopisywaniem treści

- **Granice:** Czy cały staging działa wyłącznie w `_RPA_AUTOMATION/` (bez migracji do reszty monorepo bez decyzji)?
- **Źródło prawdy dla promptów:** Notatki w `gema0/storage/notes/` — kto je tworzy (Gemini / ręcznie / skrypt)?
- **Cursor:** Czy kontekst ma iść przez `@plik.md`, sam wklejony tekst, czy oba?
- **Zwrot „odpowiedzi”:** Co uznajesz za sukces (tekst w czacie vs zmiany w repo)? Czy potrzebujesz zapisu zwrotnego do pliku — jeśli tak, skąd technicznie weźmiemy tekst (RPA OCR vs schowek vs wyłącznie ręczny eksport)?
- **Automatyzacja:** Które pliki lub zdarzenia mają automatycznie kolejkować PUSH (glob, cooldown, dedupe)?
- **UX:** Dwa niezależne „okna” konwersacji (Gemini / Cursor) vs jedna oś czasu — co jest priorytetem?

## Konwencja zamknięcia tematu

W [99_analiza_podsumowanie.md](99_analiza_podsumowanie.md) punkt **potwierdzony przez właściciela** kończy się wyłącznie słowem **`KONIEC`** w tej samej linii (bez przekreśleń markdown jako głównej metody).

## Co dalej po wypełnieniu wizji

Przenieś ustalenia do [`../plan/00_zrodla_wizji.md`](../plan/00_zrodla_wizji.md) i buduj fazy wdrożenia tam.
