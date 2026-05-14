# Sesja: Settings — izolacja wpisu `provider=ksef`

## Cel

Zapobiec sytuacji, w której zapis KSeF z modułu Inbox (`sh_tenant_integrations`, `provider=ksef`) pojawia się w Settings → Integrations, a formularz edycji (dropdown bez `ksef`) przy zapisie nadpisuje provider (np. na Papu).

## Pliki dotknięte

| Plik | Zmiana |
|------|--------|
| `api/settings/engine.php` | Lista integracji filtruje `provider <> 'ksef'`; odpowiedź z `ksef_integration_present`; blokada UPDATE wiersza ksef, INSERT z `provider=ksef`, toggle i test_ping dla ksef. |
| `modules/settings/js/settings_app.js` | Baner informacyjny gdy `ksef_integration_present`. |
| `_docs/sessions/2026-05-14_settings_ksef_integration_isolation.md` | Ten plik (Prawo XII). |

## Decyzje architektoniczne

- **Jedno źródło prawdy dla KSeF:** mutacja credentials / environment pozostaje w `api/procurement/ksef_config.php`.
- **Settings:** tylko adaptery z `AdapterRegistry` (+ `custom` / `webhook`); rekord `ksef` jest ukryty przed listą i chroniony przed przypadkową mutacją z tego panelu.
- **Usuwanie:** `integrations_delete` dla `ksef` nie jest blokowane (np. wywołanie API / skrypt administracyjny); z UI i tak nie da się trafić w ukryty wiersz.

## Otwarte pytania

- Czy w Inbox KSeF dodać jawny „usuń integrację” (DELETE wiersza `ksef`) — dziś brak w UI.
