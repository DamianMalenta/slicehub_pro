# Granice i zasady

## Sandbox

- Cały „Command Center” i dokumentacja automatyzacji ma sens **w obrębie** [`../_RPA_AUTOMATION/`](../README.md) — w szczególności [`../gema0/`](../gema0/).
- Integracja z innymi częściami repozytorium (np. backend SliceHub) wymaga **osobnej decyzji architektonicznej**; nie jest częścią domyślnej wizji GEMA-0.

## Bezpieczeństwo RPA

- Automatyzacja oparta na **focusie okna** i **symulacji klawiatury** jest podatna na błędy (zły focus, zmiana skrótów w Cursorze, powiadomienia OS).
- **BlockInput** (Windows) i weryfikacja foreground (HWND) zmniejszają ryzyko; nie eliminują go w 100%.
- Panel nie powinien wykonywać akcji RPA „w tle” bez świadomości użytkownika — kolejka + log + panic są obowiązkowym modelem psychologicznym.

## Dane wrażliwe

- Klucz Gemini trzymać w `.env` (nie commitować).
- Notatki w `storage/notes` mogą zawierać promptów z projektu — traktować jak lokalne dane robocze.

## Do uzupełnienia

- [ ] Czy dozwolone jest uruchamianie GEMA na maszynie współdzielonej (inne konta / RDP)?
- [ ] Polityka retencji logów i screenshotów w `storage/`.
