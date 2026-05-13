<?php
// =============================================================================
// SliceHub — core/local_secrets.php (TEMPLATE)
// -----------------------------------------------------------------------------
// Wgranie: menedżer plików hostingu → slicehub.net/core/local_secrets.php
//          (TYLKO ten jeden plik w katalogu core/, nie cały folder)
//
// Po co:
//   scripts/install_panel.php szuka stałej SLICEHUB_SCRIPT_KEY w tym pliku.
//   Bez niej panel zwraca "Brak/zły klucz dostępu" i nie pozwala aplikować
//   migracji ani zakładać konta owner.
//
// Co musisz zrobić:
//   1. Wygeneruj losowy klucz min. 32 znaki:
//        - openssl rand -hex 32   (Linux/Mac)
//        - https://generate-secret.now.sh/32  (klik refresh)
//        - bin2hex(random_bytes(32))  w PHP REPL
//        - ręcznie wymyśl 32+ losowych znaków
//
//   2. Zamień placeholder <TUTAJ_WYGENERUJ_LOSOWY_KLUCZ_MIN_32_ZNAKI>
//      poniżej na swój klucz.
//
//   3. Wgraj plik jako slicehub.net/core/local_secrets.php
//
// BEZPIECZEŃSTWO:
//   - Plik jest BLOKOWANY przez .htaccess sekcja [4] — żaden URL z internetu
//     go nie pobierze (403 Forbidden).
//   - NIE commituj swojego prawdziwego klucza do gita. Ten plik jest TYLKO
//     template'em — wgrywasz lokalny plik z prawdziwą wartością bez commitu.
//   - Klucz służy WYŁĄCZNIE do logowania do install_panel.php
//     (admin DB: migracje, register owner, drop tables).
//   - To NIE jest ten sam klucz co JWT_SECRET z .htaccess — to dwa różne
//     klucze chroniące dwie różne rzeczy. Generuj dwa osobne stringi.
// =============================================================================

declare(strict_types=1);

define('SLICEHUB_SCRIPT_KEY', '<TUTAJ_WYGENERUJ_LOSOWY_KLUCZ_MIN_32_ZNAKI>');
