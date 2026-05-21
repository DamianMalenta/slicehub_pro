# Sesja: Settings — KSeF widoczny + szablon bez Papu

> **Status wdrożenia:** scalone do `main` w ramach merge `projektx/ksef-client-api-v2-3f1a` (2026-05-14), razem z migracją `core/Ksef/Client.php` na API v2 — patrz `_docs/sessions/2026-05-14_ksef_api_v2_client.md`.

## Cel

- Nie ukrywać rekordu `provider=ksef` w Settings (wspólna tabela pod inbox i **przyszły** wysył faktur).
- Zapobiec przypadkowemu otwarciu formularza POS z dropdownem bez `ksef` (ryzyko nadpisania providera na Papu).

## Pliki dotknięte

| Plik | Zmiana |
|------|--------|
| `api/settings/engine.php` | Lista znów zwraca wszystkie integracje; `provider_labels` z etykietą `ksef`; usunięto filtr SQL i blokady toggle/test_ping; zachowano blokadę `integrations_save` dla wiersza `ksef` i INSERT `provider=ksef`. |
| `modules/settings/js/settings_app.js` | Karta `st-card--ksef`, przycisk „Szczegóły” + modal z linkiem do Inbox KSeF; `Edit` / formularz POS nie otwiera się dla `ksef`; dropdown edycji tylko z `available_providers`. |
| `modules/settings/css/style.css` | Styl `.st-card--ksef`. |

## Decyzje architektoniczne

- **Token / środowisko:** nadal wyłącznie `ksef_config.php` (Inbox); Settings tylko podgląd + link.
- **Etykieta:** `provider_labels['ksef']` z PHP — jedna prawda dla listy kart.
- **Outbound:** ten sam rekord `sh_tenant_integrations`; UI wyśle/rozwinie się później bez zmiany sensu listy.

## Otwarte pytania

- Czy `Client` / worker mają respektować `is_active` na wierszu `ksef` (dziś `Client` tego nie czyta).
