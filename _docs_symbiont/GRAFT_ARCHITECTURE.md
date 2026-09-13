# Wszczep PHP — architektura B

APPROVED 2026-09-11. Kontrakt przypięty: [protocol-v1.json](../core/Symbiont/contracts/protocol-v1.json). Zasady: [HOST_CONSTITUTION.md](HOST_CONSTITUTION.md).

## Etap A
Własny HTTP JSON v1.0.0, nie deklaracja MCP. Most ma tylko describe/diagnose, bez DB i zmian plików. Domyślnie disabled. Sekret usługowy A->B jest odrębny od uprawnień panelu B i sesji pracownika. Publiczny DTO nie zawiera tokenu, lokalnych ścieżek ani szczegółów bazy.

POST JSON: protocol, version, request_id, operation. Nieznane pola są odrzucane. Token w Authorization: Bearer. Limit request 4096 B, response 16384 B, klient timeout 5 s, brak redirects. Wersja musi być dokładnie 1.0.0. HTTP 200 wymaga prawidłowego envelope z ok=true. Błąd: error.code/message. request_id jest odbijany tylko po walidacji.

Describe: host_id/display_name/environment/adapter_version/capabilities. Diagnose: identity, observed_at i checks bridge=ok, business_access=not_requested. Diagnoza nie oznacza prawidłowej bazy, AST, ERP lub sprzętu.

## Docelowe moduły
Host Context dostarcza zminimalizowany słownik i fakty. Capability Registry publikuje tylko zatwierdzone kontrakty. Authorization sprawdza aktora/delegację/tenant/risk. Command Gateway wykonuje usługi domenowe. Inbox/Outbox przechowują skutki, wyniki i zdarzenia. Operator UI oraz Device Client obsługują człowieka.

## DDD
Właściciele danych pozostają w istniejących domenach. core/ nie jest w całości Shared Kernel. Magazyn, rozliczenia, zamówienia i kuchnia mają własne niezmienniki. Zdarzenia powtarzalne wymagają tożsamości konkretnej zmiany, nie tylko orderId:eventType. Wszczep nie dodaje alternatywnej logiki cen lub drugiego modelu stanów.

## Urządzenia
Słuchawka -> wybudzenie/PTT -> STT -> finalna wypowiedź + sesja -> A -> żądanie kompetencji -> B -> wynik -> ekran/TTS. Telefonia ma własny adapter i call_id; kanał klienta jest odseparowany. Klient urządzeniowy może być Androidem w repo B, nie zmieniając LAMP runtime.

## Niezależność
Brak include/import do repo A. Konstytucja i protokół są przypiętymi artefaktami, nie edytowanymi lokalnie forkami. Ich integralność sprawdza kontrola zgodności między repo. Wszczep można wyłączyć bez usuwania danych i bez zatrzymania ręcznego POS.
