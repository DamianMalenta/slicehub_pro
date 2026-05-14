# Sesja 2026-05-14 — KSeF inbox: etykiety statusów, sortowanie, kategorie kosztów

## Cel

- Polskie nazwy statusów w UI (`draft` → Nowe, `rejected` → Odrzucone).
- Sortowanie listy „Wszystkie”: wg daty, z priorytetem statusów Nowe → Zaakceptowane → Odrzucone.
- Akceptacja faktur kosztowych (np. prąd) bez magazynu/PZ oraz pole `cost_category` pod przyszłe statystyki.

## Pliki dotknięte

- `database/migrations/056_ksef_invoice_cost_category.sql` — kolumna `sh_ksef_invoices.cost_category`.
- `scripts/_migrations_chain.php` — wpis łańcucha migracji.
- `api/procurement/inbox.php` — `set_cost_category`, rozszerzony `accept` / `reverse`, sortowanie `list`.
- `modules/procurement/js/procurement_app.js`, `index.html`, `css/procurement.css` — UI kategorii, etykiety, reverse/accept.

## Decyzje architektoniczne

- Wartości kategorii w bazie ASCII: `magazyn`, `media`, `uslugi`, `inne`. Tylko `magazyn` wymaga pełnego mapowania SKU + PZ; pozostałe = akceptacja bez `linked_wh_document_id`.
- Wycofanie akceptacji: jeśli brak PZ, prosty `UPDATE` do `draft` bez KOR.

## Test (E2E)

- `php -l api/procurement/inbox.php` — brak błędów składni.
- Migracja 056: uruchomić na instancji z MariaDB (`mysql … < database/migrations/056_ksef_invoice_cost_category.sql` lub łańcuch migracji).
- Ręcznie: Procurement → faktura w statusie Nowe → zmiana kategorii na „Media / energia” → Akceptuj (potwierdzenie bez PZ) → lista pokazuje „Koszt (bez PZ)” → Wycofaj akceptację (bez KOR).

## Otwarte pytania

- Raporty statystyczne: osobny endpoint agregujący `total_*_minor` po `cost_category` i okresie (do osobnego modułu).
