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

---

## Indeks sesji

- [`2026-05-11_constitution_v5.md`](2026-05-11_constitution_v5.md) — Konstytucja v5: Prawa VIII / IX / X + naprawa drift-u kod-docs.
- [`2026-05-11_phase_f1_consume_loop.md`](2026-05-11_phase_f1_consume_loop.md) — F1 Pętla zużycia POS↔Magazyn: hook `WzEngine::consumeForOrder` w `accept_order`, naprawa 3 bug-ów PDO w WzEngine, `consumeForOrder` zdjęte z `@planned`.
- [`2026-05-11_phase_f2_autoscan.md`](2026-05-11_phase_f2_autoscan.md) — F2 Shared AutoScan Engine: 4-stopniowy confidence (EXACT/ALIAS/NAME/FUZZY/NONE) + self-learning + endpoint `api/procurement/suggest.php` z RBAC. Fundament pod F3 (Procurement Inbox) i F4 (KSeF).
- [`2026-05-11_phase_f2_5_pz_autoscan.md`](2026-05-11_phase_f2_5_pz_autoscan.md) — F2.5 PzEngine na shared AutoScan + UI sugestie. Network effect potwierdzony (ALIAS match → mapping memory → następny PZ EXACT). UI `warehouse_pz.js` z confidence pill + auto-fill SKU.
- [`2026-05-11_phase_f3_procurement_inbox.md`](2026-05-11_phase_f3_procurement_inbox.md) — F3 Procurement Inbox UI + FA(2) Parser + m046 KSeF schema. Manual upload XML → parser → AutoScan match → 1-click accept → PZ. Hub kafelek „Inbox KSeF" (owner/admin/manager). Fundament pod F4 KSeF API client.
- [`2026-05-11_phase_f4_ksef_client.md`](2026-05-11_phase_f4_ksef_client.md) — F4 KSeF API client (sandbox/prod/mock) + worker (cron + HTTP trigger) + Settings UI z CredentialVault tokenu. F4.5: Smart-create dla NONE linii + Reverse-PZ z KOR dokumentem.
