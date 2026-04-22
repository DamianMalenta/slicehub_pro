SliceHub — Audyt po Sesji 7.6
2026-04-18 · zamknięcie Fazy 7 (Event System + Integrations) · snapshot przed kolejną decyzją produktową

29
Migracje w DB
56
Endpointy API (PHP)
14
Moduły (UI)
15
Dokumenty techniczne
5/6
Faz ukończonych
7.6
Aktualna sesja
1
Blocker (krytyczny)
2
Luki (wysokie)
Stan faz
Faza	Zakres	Status	Ostatnia sesja
Faza 1	Scene Studio + The Table · fundament	
1.5
Faza 2	Scene Kit · Category Scene · Modifier Visual Slots + runtime	
2.9
Faza 3	Interaction Contract v1 + The Table klient	
3.1
Faza 4-5	POS v2 · Courses · KDS · Delivery pipeline	
6.x
Faza 6	Warehouse (IN/PZ/RW/MM) · Food-cost · Payroll	
6.x
Faza 7	Event System · Gateway v2 · Integration Adapters · Settings Panel	
7.6
Faza 8	KDS deep integration · split-board · modifier heat-map	
—
Co zostało zbudowane w Sesji 7.6
Migracja 029
sh_settings_audit — trail zmian w Settings Panel (user, ip, before/after z redact).

sh_inbound_callbacks — surowy log callbacków od 3rd-party z idempotency przez UNIQUE(provider, external_event_id).

Inbound callback framework
api/integrations/inbound.php — generic receiver (log → verify → match → publish).

BaseAdapter::parseInboundCallback() + supportsInbound(). Papu pełna impl, Dotykacka/GastroSoft stub.

Security layer
CSRF token (session-stored, double-submit header).

Rate-limit test_ping (5/min przez audit).

Audit auto-logging we wszystkich mutacjach engine.php.

CLI tooling
scripts/bootstrap_vault.php — init klucza XChaCha20 (0600).

scripts/rotate_credentials_to_vault.php — plaintext → vault:v1: z dry-run i self-test roundtrip.

Znaleziska audytu — uporządkowane wg priorytetu
Blockery (produkcja tuż za rogiem)
#	Problem	Gdzie	Skutek	Naprawa
B-1	CSRF client-side NIE jest wdrożony	modules/settings/js/settings_app.js	
Backend od 7.6 wymaga header X-CSRF-Token przy każdej mutacji. Front go nie wysyła → wszystkie akcje save/toggle/delete/generate/revoke/replay/test_ping zwracają 403. Po 7.6 panel jest technicznie zepsuty.

Dodać bootstrap tokena + preload w headerze w callApi (15 min)
Luki wysokiego priorytetu
#	Problem	Gdzie	Skutek	Naprawa
W-1	Inbound callbacks nie są wyeksponowane w Health panel	api/settings/engine.php · health_summary	Admin nie zobaczy bad-sig ataków ani DLQ dla inbound bez wchodzenia w SQL.	Dodać sekcję inbound_summary (ostatnie 24h: counts per provider/status) + tab „Inbound" w UI
W-2	Brak udokumentowanego cron/worker setupu	scripts/worker_webhooks.php · worker_integrations.php	
Workery istnieją, ale żaden dokument deploymentu nie mówi jak je uruchomić. Świeży deploy bez crona = eventy gniją w sh_event_outbox.

Dodać sekcję „Cron setup" w 09_EVENT_SYSTEM.md + sample systemd/crontab
Zadania średnie (nice-to-have, bez pilności)
#	Problem	Koszt	Priorytet
M-1	Delivery inspector UI — timeline HTTP requestów per event (z paginacją)	1 sesja	
M-2	Dotykacka + GastroSoft inbound parseInboundCallback (obecnie 501)	0.5 sesji każdy	
M-3	Banner „X plaintext credentials — uruchom rotate_credentials_to_vault.php"	15 min	
M-4	Webhook auto-registration (automatyczny POST naszego URL-a do providera)	0.5 sesji	
M-5	Scope picker UX — checkboxy zamiast textbox dla API Keys	30 min	
Spójność systemu — co jest w pełni podpięte
Event flow (outbound)
POS/Courses/API → OrderEventPublisher::publishOrderLifecycle → sh_event_outbox → worker webhooks + worker integrations → WebhookDispatcher / adapter → subskrybenci + 3rd-party.

Event flow (inbound) — nowe w 7.6
3rd-party → api/integrations/inbound.php → log → adapter.parseInboundCallback → sh_orders update → publishOrderLifecycle → KDS/Driver/notif.

Settings Panel
5 zakładek · Integrations/Webhooks/API Keys/DLQ/Health · secret-once · vault badge.

Credential Vault
XChaCha20-Poly1305 AEAD · graceful degradation · transparent decrypt w BaseAdapter + WebhookDispatcher.

Dalsze kroki — trzy ścieżki do wyboru
A · HOTFIX 7.6.1
Zamknij regresję CSRF + wyeksponuj inbound callbacks w UI + dopisz cron sample do docs.

1. Dodaj CSRF bootstrap w settings_app.js

2. health_summary + inbound_list action

3. Tab „Inbound" w UI (ostatnie 100 callbacków)

4. Banner „X plaintext — rotate now"

5. Sekcja cron w 09_EVENT_SYSTEM.md

Wszystko co jest niezbędne zanim puści się to komukolwiek do testów.

B · FAZA 8 — KDS deep integration
Zamykamy pętlę kuchni: courses → KDS delta, split-board, modifier heat-map, station routing, expo screen.

• KDS split-view: stations (pizza/sushi/grill)

• Ticket lifecycle events → ekipa (Driver panel)

• Modifier heat-map — które modyfikatory spowalniają wydawkę

• Expo screen (runner view) — przed expedycją

• KDS metrics → Dashboard (avg prep time, slowest station)

Wymaga fundamentu 7.x (gotowe) + solidnej pętli sprzężenia KDS ↔ POS (jest).

C · Observability & Ops
Produkcyjne monitorowanie zanim klient zacznie klikać: structured logs, health endpoint, Sentry, JS error tracking.

• /api/health.php — uptime + worker lag

• Structured JSON logging (psr-3)

• Frontend error tracking (sentry-lite)

• Worker heartbeat w sh_worker_heartbeats

• Dashboard alertów (stale outbox, stuck deliveries)

Sensowne tylko jak planujemy realny deploy w najbliższych tygodniach.

Moja rekomendacja
Sekwencja
Ścieżka A — Hotfix 7.6.1 (~40 min)

Bez tego Settings Panel jest nieużyteczny dla klienta (CSRF breakage) i niediagnozowalny z UI (brak inbound view). Zamykamy to jedną małą sesją, żeby 7.6 była naprawdę gotowa.

Pytanie do Ciebie — B albo C

Jeśli chcesz jechać w stronę gotowego produktu pod restauracje — jedziemy Fazą 8 (KDS deep). Jeśli planujesz w najbliższym czasie realne wdrożenie (klient, testy) — jedziemy C (observability). B ma większą wartość biznesową, C większą wartość operacyjną.

Pytanie
Mam puścić Ścieżkę A (hotfix 7.6.1) od razu jako małą sesję i potem pytać o kierunek (B/C)? Czy wolisz zdecydować jedną ścieżkę (A+B albo A+C) od razu i jechać w ciągu?