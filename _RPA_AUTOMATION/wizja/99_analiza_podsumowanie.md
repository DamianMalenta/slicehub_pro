# Analiza końcowa — wizja (szablon)

## Krótko: co ustala ten katalog

Zestaw dokumentów od [README.md](README.md) definiuje **docelowe zachowanie** GEMA-0 / RPA (granice, przepływy, UI, automatyzacja) niezależnie od aktualnego stanu kodu. Szczegóły implementacji należy zestawiać z [05_mapowanie_na_gema0.md](05_mapowanie_na_gema0.md) i z [`../plan/`](../plan/README.md).

## Analiza / konsekwencje

*(Do uzupełnienia po dyskusji: np. konsekwencje braku API Cursor, konieczność kolejki RPA, ryzyko schowka.)*

## Co robiono w tej iteracji dokumentu

*(Krótki log: np. „utworzono szkielet katalogu wizja 2026-04-20” potwierdzone przez użytkownika.)*

- Utworzono strukturę katalogów `wizja/` i powiązane pliki szablonowe.

## Sugestie — co dalej

1. Uzupełnić checklisty w `01`–`05` decyzjami produktowymi.
2. Przenieść stabilne ustalenia do [`../plan/00_zrodla_wizji.md`](../plan/00_zrodla_wizji.md).
3. Po wdrożeniu funkcji aktualizować [05_mapowanie_na_gema0.md](05_mapowanie_na_gema0.md).

## Punkty potwierdzone przez właściciela

*(Każda linia potwierdzonego punktu kończy się wyłącznie słowem **KONIEC** — bez innych oznaczeń.)*

- Szkielet katalogu wizja utworzony — KONIEC

## Punkty otwarte

*(Tu lista bez słowa KONIEC dopóki nie zaakceptujesz.)*

- Trwała historia Gemini + Cursor w jednym UI
- Automatyczny push wybranego `.md` z regułami dedupe / cooldown
- Zapis odpowiedzi Cursor do plików po stronie GEMA
