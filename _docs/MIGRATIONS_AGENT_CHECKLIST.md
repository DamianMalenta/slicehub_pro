# Migracje SQL — obowiązek przy każdej nowej migracji (agenci AI i deweloperzy)

Ten plik jest **źródłem procedury**, nie historią sesji. Stosuj go przy **każdym** commicie dodającym lub zmieniającym plik w `database/migrations/`.

## 1. Nowy plik `database/migrations/NNN_opis.sql`

1. Utwórz plik SQL (idempotentnie, jeśli to możliwe — patrz istniejące migracje z `information_schema`).
2. **Na końcu tej samej zmiany** dopisz **dokładną nazwę pliku** (basename) na **końcu** tablicy w  
   `scripts/_migrations_chain.php`  
   — kolejność w tablicy = kolejność wykonania na świeżej bazie i w Install Panelu.
3. Uruchom audyt łańcucha (bez bazy lub z bazą — oba OK dla spójności listy):  
   `php scripts/apply_migrations_chain.php --audit`  
   Oczekiwany wynik: **`OK: łańcuch zgodny z plikami na dysku`**.
4. **Nie zostawiaj** pliku `.sql` w `database/migrations/` bez wpisu w łańcuchu — w **Install Panel → 2b** dostanie tag **`POZA ŁAŃCUCHEM`** i nie da się go bezpiecznie odpalać „obok” produkcyjnego łańcucha.

## 2. Tagi w Install Panel (`scripts/install_panel.php`, sekcja 2b)

| Tag | Znaczenie |
|-----|-----------|
| **łańcuch** | Plik jest w `_migrations_chain.php` — można go zaznaczyć i uruchomić („Uruchom zaznaczone”). |
| **POZA ŁAŃCUCHEM** | Plik leży w `database/migrations/*.sql` (poza `001` i `_archive_*`), ale **nie ma** go w `_migrations_chain.php` — **napraw to** (pkt 1–3). |
| **brak pliku** | Wpis jest w łańcuchu, ale **brak** fizycznego pliku w repo — niespójność do naprawy. |

Wyłączone z porównania „łańcuch vs dysk”: `001_init_slicehub_pro_v2.sql` (osobny krok „A” w panelu), `*_archive_*.sql`.

## 3. Migracja `015_normalize_three_drivers.sql`

Jest **świadomie** pominięta w domyślnym łańcuchu (destrukcyjne dane demo). Jeśli plik leży na dysku, panel pokaże go jako **POZA ŁAŃCUCHEM** z notatką o uruchomieniu CLI z `--include-015` — to oczekiwane, dopóki nie zdecydujecie inaczej w projekcie.

## 4. Dla agenta AI (skrót)

**Koniec każdej sesji, która dodaje migrację:** łańcuch zaktualizowany + audyt `--audit` + brak osieroconych `.sql` w katalogu migracji względem łańcucha.
