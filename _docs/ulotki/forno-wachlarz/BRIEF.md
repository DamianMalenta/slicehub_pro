# FORNO Wachlarz — Brief produktu

**FROZEN_AT:** n/a (żywy dokument)  
**Cel:** Edytowalny zestaw kart A5 na deskę ze śrubą (wachlarz / paleta), spójny styl street + papier rzeźniczy.

## Fizyczny montaż

| Element | Wymiar |
|---------|--------|
| Karta | A5 **148 × 210 mm**, jednostronna |
| Baza (sklejka) | ok. **160 × 220 mm** |
| Oś | Śruba **Ø ~7 mm**, lewy górny róg |
| Otwór na karcie | Ø 8–10 mm + martwa strefa ~14 mm od rogu |

Kolejność kart w decku = kolejność w stosie (od góry / pierwszej widocznej).

## Architektura karty (szablon)

- **Góra ~60%** — Hero Zone (zdjęcie produktu + nagłówek sign-painting + cena highlighter)
- **Dół ~40%** — warianty / lista, oddzielone przerywaną linią markerową
- Tło: delikatna tekstura papieru rzeźniczego (szary/krem)

## Typy kart (`card_type`)

| Typ | Dolna strefa |
|-----|----------------|
| `cover` | Marka + tagline + CTA |
| `hero_duo` | Dwie kolumny L/P (np. Fryto / Grillo) |
| `hero_sizes` | 30 cm \| 37 cm + wspólne składniki |
| `hero_list` | 4–6 pozycji w 2 kolumnach |
| `cta` | Telefon / QR / godziny |

## Limity treści (panel waliduje)

- `tab_label`: max **14** znaków (pasek wachlarza)
- `title`: max **28** znaków
- bullets wariantu: max **5**, każdy max **36** znaków
- jedna cena z highlighterem na kartę (`price`)

## Edycja

1. **Panel:** `modules/marketing/deck/` (CRUD + podgląd + druk)
2. **API:** `api/marketing/deck_engine.php`
3. **Referencja seed:** `content.json` w tym folderze (= payload kart)

Źródło prawdy operacyjnej menu = `sh_menu_items` / seed Pizza Forno. Wachlarz to **kuratorowana oferta marketingowa** (most opcjonalny: `ascii_key` w payload).

## Pliki silnika

| Plik | Rola |
|------|------|
| `modules/marketing/deck/css/deck.css` | Look & print A5 |
| `modules/marketing/deck/js/deck-renderer.js` | Render kart z payload |
| `modules/marketing/deck/print.html` | Wydruk całego decku |
| `modules/marketing/deck/index.html` | Panel użytkownika |
