# Studio / POS — indeks release bundle (2026-05-11)

> **Cel:** jeden punkt wejścia do siedmiu sesji F-S* / F5 / F6 z tego samego dnia. Czytaj w kolejności poniżej.

## Kolejność czytania

| # | Plik | Fazy | Migracje |
|---|------|------|----------|
| 1 | [`phase_f5_pos_integrity_and_f6_geocoder.md`](2026-05-11_phase_f5_pos_integrity_and_f6_geocoder.md) | F5 POS integrity, F6 geocoder | 047 |
| 2 | [`release_bundle_fs2_fs3_fs4.md`](2026-05-11_release_bundle_fs2_fs3_fs4.md) | F-S1…F-S4 + bundle z F5/F6 | 048–052 |
| 3 | [`phase_fs1_variant_scales.md`](2026-05-11_phase_fs1_variant_scales.md) | F-S1 szczegóły wariantów | 048 |
| 4 | [`followups_fs21_fs31_fs5_fs6.md`](2026-05-11_followups_fs21_fs31_fs5_fs6.md) | F-S2.1, F-S3.1, F-S5, F-S6 UI | 053 |
| 5 | [`phase_fs32_combo_wzengine_expansion.md`](2026-05-11_phase_fs32_combo_wzengine_expansion.md) | F-S3.2 WzEngine combo | — |
| 6 | [`phase_fs51_subrecipe_studio_ui.md`](2026-05-11_phase_fs51_subrecipe_studio_ui.md) | F-S5.1 Studio półprodukty | — |
| 7 | [`final_followups_fs8_fs12_fs61_fs7_fs9.md`](2026-05-11_final_followups_fs8_fs12_fs61_fs7_fs9.md) | F-S8…F-S9 domknięcia | — |

## Co gdzie szukać

| Temat | Sesja |
|-------|--------|
| CartEngine revalidacja, cancel → reverse stock | `phase_f5_pos_integrity_and_f6_geocoder` |
| Geocoder Nominatim, `delivery_lat/lng` | j.w. |
| Topping size pricing, half-half strategy | `release_bundle_fs2_fs3_fs4` |
| Meal packages / combo POS | `release_bundle` + `followups` (F-S3.1) |
| Warianty S/M/L (parent + children) | `phase_fs1_variant_scales` |
| WzEngine zużycie combo + subrecipe | `phase_fs32_combo_wzengine_expansion` |
| Wizard „Nowa Pizza”, recipe DnD | `followups` + `final_followups` |

## Uwaga o formacie

Te sesje powstały przed pełną standaryzacją Prawa X i używają szablonu **„AI Session Audit”** (Zakres, Schema, Test E2E…) zamiast ścisłych 4 sekcji. Treść merytoryczna jest kompletna; przy nowych sesjach Studio stosuj standard z [`README.md`](README.md).

## Powiązane (poza tym indeksem)

- Zużycie magazynu po akceptacji zamówienia: [`2026-05-11_phase_f1_consume_loop.md`](2026-05-11_phase_f1_consume_loop.md)
- Procurement / KSeF: sekcja w [`README.md`](README.md) → F2…F4
