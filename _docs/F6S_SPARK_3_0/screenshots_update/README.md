# Zrzuty ekranu — uzupełnienie wniosku F6S (czerwiec 2026)

Wygenerowane automatycznie z localhost (`http://localhost/slicehub`) skryptem `scripts/capture_f6s_update_screenshots.py`.

| Plik | Co pokazuje |
|------|-------------|
| `01_hub_moduly_lego.png` | Hub — odizolowane moduły (POS, KDS, Magazyn, KSeF, BI, HR…) łączone jak klocki LEGO |
| `02_ksef_inbox_lista.png` | Inbox KSeF — lista faktur, AutoScan, statusy (nowe / zaakceptowane / PZ) |
| `03_ksef_faktura_mapowanie.png` | Szczegół faktury — mapowanie EXACT/ALIAS, typ Towar → PZ + magazyn |
| `04_bi_pl_dashboard.png` | BI P&L — przychód, zamrożony kapitał, marże (okres 05–06.2026) |
| `05_bi_capital_flow.png` | Przepływ kapitału operacyjnego + OPEX wg kategorii |

**Ponowne wygenerowanie:** uruchom XAMPP (Apache + MySQL), potem:

```bash
python scripts/capture_f6s_update_screenshots.py
```
