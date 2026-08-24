# Sesje AI — folder audytu (Prawo X Konstytucji v5)

Każda sesja AI zmieniająca `core/`, `api/`, `database/migrations/` lub `_docs/01_KONSTYTUCJA.md` zostawia tutaj plik o nazwie:

```
YYYY-MM-DD_<topic>.md
```

Zawartość — 4 sekcje:

1. **Cel** — jednym zdaniem co sesja miała osiągnąć.
2. **Pliki dotknięte** — lista plików + jednolinjowy opis każdego.
3. **Decyzje architektoniczne** — co świadomie wybrano i dlaczego (zwłaszcza tam gdzie były alternatywy).
4. **Otwarte pytania** — co kolejna sesja musi rozstrzygnąć.

**Drobne sesje** (literówka, fix lint, drobny CSS) — zwolnione. Zasada: jeśli zmiana zmienia zachowanie systemu, wymaga audytu.

**Cel folderu:** kolejny AI / programista / właściciel w 30 sekund rozumie *co*, *dlaczego* i *co dalej* — bez archeologii w git history.

**Uwaga:** starsze sesje Studio (2026-05-11) używają rozszerzonego szablonu „AI Session Audit” — patrz [`2026-05-11_STUDIO_RELEASE_INDEX.md`](2026-05-11_STUDIO_RELEASE_INDEX.md). Pliki `HANDOFF_*` to skróty operacyjne, nie kanon audytu.

---

## Indeks sesji

### Fundament (Konstytucja + magazyn + procurement)

- [`2026-05-11_constitution_v5.md`](2026-05-11_constitution_v5.md) — Konstytucja v5: Prawa VIII / IX / X + naprawa drift-u kod-docs.
- [`2026-05-11_phase_f1_consume_loop.md`](2026-05-11_phase_f1_consume_loop.md) — F1 Pętla zużycia POS↔Magazyn: `WarehouseConsumeHook` → `accept_order`; `consumeForOrder` zdjęte z `@planned`.
- [`2026-05-11_phase_f2_autoscan.md`](2026-05-11_phase_f2_autoscan.md) — F2 Shared AutoScan Engine (EXACT/ALIAS/NAME/FUZZY/NONE) + `api/procurement/suggest.php`.
- [`2026-05-11_phase_f2_5_pz_autoscan.md`](2026-05-11_phase_f2_5_pz_autoscan.md) — F2.5 PzEngine na shared AutoScan + UI sugestie w `warehouse_pz.js`.
- [`2026-05-11_phase_f3_procurement_inbox.md`](2026-05-11_phase_f3_procurement_inbox.md) — F3 Procurement Inbox UI + FA(2) Parser + m046; upload XML → AutoScan → accept → PZ.
- [`2026-05-11_phase_f4_ksef_client.md`](2026-05-11_phase_f4_ksef_client.md) — F4 KSeF client **API 1.0 / mock** + worker + smart-create + reverse-PZ. **⚠️ Superseded** przez API v2 (patrz 2026-05-14).
- [`2026-05-14_ksef_api_v2_client.md`](2026-05-14_ksef_api_v2_client.md) — **Aktualny** klient KSeF API v2 (JWT, NIP); worker / inbox; duplikat `upload_xml` (409 + `replace`).
- [`2026-05-14_settings_ksef_integration_isolation.md`](2026-05-14_settings_ksef_integration_isolation.md) — Settings: `provider=ksef`, karta + modal; blokada edycji POS na wierszu KSeF.
- [`2026-05-14_ksef_inbox_cost_category.md`](2026-05-14_ksef_inbox_cost_category.md) — UI statusów inbox; nagłówkowe `cost_category` (m056); akceptacja kosztów bez PZ.
- [`2026-05-14_ksef_opex_line_types.md`](2026-05-14_ksef_opex_line_types.md) — m057: linie `INVENTORY` vs `EXPENSE`, `sh_expense_categories`, `commission_amount`; `main` @ `71a0e3f`.
- [`2026-05-14_procurement_bulk_line_edit.md`](2026-05-14_procurement_bulk_line_edit.md) — Edycja grupowa linii inbox (`bulk_update_lines`) + checkboxy w UI.
- [`2026-05-22_ksef_qty_normalization.md`](2026-05-22_ksef_qty_normalization.md) — **Kanon** m058/m059: normalizacja qty FA → `base_unit`, P_7A, mapping per NIP, KOR z liniami.
- [`HANDOFF_2026-05-22_ksef_inbox_continue.md`](HANDOFF_2026-05-22_ksef_inbox_continue.md) — Skrót operacyjny (archiwum); pełny audyt → `2026-05-22_ksef_qty_normalization.md`.

### Studio / POS / release bundle (2026-05-11)

Spine czytania: [`2026-05-11_STUDIO_RELEASE_INDEX.md`](2026-05-11_STUDIO_RELEASE_INDEX.md).

- [`2026-05-11_phase_f5_pos_integrity_and_f6_geocoder.md`](2026-05-11_phase_f5_pos_integrity_and_f6_geocoder.md) — F5 POS integrity (CartEngine revalid, reverse stock, temporal filter) + F6 geocoder (047).
- [`2026-05-11_release_bundle_fs2_fs3_fs4.md`](2026-05-11_release_bundle_fs2_fs3_fs4.md) — Paczka F5+F6+F-S1…F-S4: topping pricing, meal packages, drift cleanup (049–052).
- [`2026-05-11_followups_fs21_fs31_fs5_fs6.md`](2026-05-11_followups_fs21_fs31_fs5_fs6.md) — F-S2.1 size pricing UI, F-S3.1 combo POS, F-S5 subrecipes (053), F-S6 wizard Nowa Pizza.
- [`2026-05-11_phase_fs1_variant_scales.md`](2026-05-11_phase_fs1_variant_scales.md) — F-S1 warianty Mała/Średnia/Duża (048).
- [`2026-05-11_phase_fs32_combo_wzengine_expansion.md`](2026-05-11_phase_fs32_combo_wzengine_expansion.md) — F-S3.2 WzEngine rozszerzony o combo + warianty + subrecipe.
- [`2026-05-11_phase_fs51_subrecipe_studio_ui.md`](2026-05-11_phase_fs51_subrecipe_studio_ui.md) — F-S5.1 Studio UI półproduktów.
- [`2026-05-11_final_followups_fs8_fs12_fs61_fs7_fs9.md`](2026-05-11_final_followups_fs8_fs12_fs61_fs7_fs9.md) — F-S8…F-S9: combo reverse audit, presets, wizard step 5, PRICE_MISMATCH, recipe DnD.

### BI (P&L)

- [`2026-05-14_bi_engine.md`](2026-05-14_bi_engine.md) — **Część 1:** `BiEngine`, COGS/OPEX/net sales, `stock_value_minor`, T62.
- [`2026-05-21_bi_opex_flow_from_pr28.md`](2026-05-21_bi_opex_flow_from_pr28.md) — **Część 2:** OPEX per kategoria, capital flow, prime cost (z PR #28).

### Infra / deploy / seed / auth

- [`2026-05-21_api_base_paths.md`](2026-05-21_api_base_paths.md) — SSOT prefiksów API (`sh_api_base.js`, Tier 1–5); 62/62 test_runner.
- [`2026-05-22_tenant_discovery_auth.md`](2026-05-22_tenant_discovery_auth.md) — `tenant_config.php` discovery tenanta; test runner `discoverAuthTenant()`; `install_panel` `pin_code`.
- [`2026-05-22_seed_refresh.md`](2026-05-22_seed_refresh.md) — `seed_demo_all.php` po m046–059; aliasy, KSeF demo, Studio visuals.

- [`2026-07-07_docs_start_tutaj_verification.md`](2026-07-07_docs_start_tutaj_verification.md) — START_TUTAJ.md, weryfikacja 62/62, fix Deno CI workflow_dispatch.
- [`2026-07-22_audyt_produkcyjny.md`](2026-07-22_audyt_produkcyjny.md) — pełny audyt gotowości produkcyjnej; inwentarz stanu plików (A–I), realne bugi (ChannelRegistry, DirectorApp), martwy/zdublowany kod, białe plamy.

### Promised Time / SLA (Fazy A–E + audyty)

- [`2026-07-29_promised_time_sla_audit_and_plan.md`](2026-07-29_promised_time_sla_audit_and_plan.md) — audyt pierwotny obiegu `promised_time`; 6 ścieżek nadawania, 5 frontendów SLA, plan naprawczy Fazy A–E.
- [`2026-07-29_phase_a_sla_thresholds.md`](2026-07-29_phase_a_sla_thresholds.md) — Faza A: SSOT progów SLA (`core/SlaThresholds.php`), 4 frontendy czytają z `sh_tenant_settings`.
- [`2026-07-29_phase_b_promised_time_engine.md`](2026-07-29_phase_b_promised_time_engine.md) — Faza B: wpięcie `PromisedTimeEngine` ASAP w 4 ścieżki (online, gateway, choiceqr, POS accept_order).
- [`2026-07-29_phase_c_sla_breach_panel.md`](2026-07-29_phase_c_sla_breach_panel.md) — Faza C: SLA breach panel w Dispatcher + cron `worker_sla_monitor.php`.
- [`2026-08-03_promised_time_wiring_audit.md`](2026-08-03_promised_time_wiring_audit.md) — **Audyt follow-up Faza B**: 3 luki krytyczne (POS "ZAAKCEPTUJ" wysyła `now` zamiast pustego → silnik ASAP nie odpala; online scheduled zapis surowy bez walidacji; tryb `scheduled` silnika = martwy kod) + 2 kosmetyczne.
- [`2026-08-24_online_promised_time_scheduled_wiring.md`](2026-08-24_online_promised_time_scheduled_wiring.md) — **Domknięcie L2+L3**: publiczny wrapper `estimate_time` w `api/online/engine.php` (slots filtrowane po opening hours); walidacja scheduled w `guest_checkout` przez silnik; przebudowa UI checkoutu (toggle ASAP/scheduled + selector slotów, delivery + takeaway). 62/62 PASS, deno lint 0.

### SPARK (materiały wniosku — bez zmian runtime)

- [`2026-05-20_spark_prezentacja_ludzka.md`](2026-05-20_spark_prezentacja_ludzka.md) — Prezentacja SPARK: screeny, HTML/PDF, teksty F6S.
- [`2026-05-20_spark_nagranie_forno_prompt.md`](2026-05-20_spark_nagranie_forno_prompt.md) — Brief nagrania procesów Pizza Forno (`seed_pizzaforno.sql`).
