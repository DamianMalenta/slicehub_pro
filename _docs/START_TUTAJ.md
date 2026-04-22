# START TUTAJ — aktualna mapa dokumentacji SliceHub

> Ten plik jest **punktem wejścia do `_docs/`**. Jeśli nie wiesz, co czytać, zacznij tutaj.
> Cel: zero chaosu, zero zgadywania, jedno miejsce startowe.

---

## 1. Kolejność czytania

Czytaj dokumenty w tej kolejności:

1. `START_TUTAJ.md` — ten plik
2. `00_PAMIEC_SYSTEMU.md` — aktualny stan systemu, priorytety, statusy, zasady
3. `02_ARCHITEKTURA.md` — gdzie fizycznie leżą moduły i API
4. `04_BAZA_DANYCH.md` — tabele, kolumny, relacje, prefiksy

Dopiero potem wchodź w dokumenty modułowe.

---

## 2. Co jest źródłem prawdy

### Kanoniczne dokumenty

- `00_PAMIEC_SYSTEMU.md` — główny dokument systemu
- `01_KONSTYTUCJA.md` — zasady architektoniczne
- `02_ARCHITEKTURA.md` — mapa katalogów
- `03_MAPA_KOPALNI.md` — mapa legacy, tylko referencyjnie
- `04_BAZA_DANYCH.md` — schemat danych
- `05_INSTRUKCJA_FOTO_UPLOAD.md` — zasady assetów i uploadu

### Dokumenty aktywne, ale modułowe

- `07_INTERACTION_CONTRACT.md` — kontrakty storefrontu / The Table
- `08_ORDER_STATUS_DICTIONARY.md` — słownik statusów zamówień
- `09_EVENT_SYSTEM.md` — event outbox
- `10_GATEWAY_API.md` — intake zewnętrznych zamówień
- `11_WEBHOOK_DISPATCHER.md` — outbound webhooks
- `12_INTEGRATION_ADAPTERS.md` — adaptery Papu / Dotykacka / GastroSoft
- `13_SETTINGS_PANEL.md` — panel settings / integracje / DLQ
- `14_INBOUND_CALLBACKS.md` — inbound callbacks od providerów
- `15_KIERUNEK_ONLINE.md` — aktualny kierunek Online / Studio

---

## 3. Co czytać zależnie od modułu

### A. Magazyn

Czytaj:

1. `00_PAMIEC_SYSTEMU.md` — część o magazynie / food cost / silosach `sys_` i `wh_`
2. `04_BAZA_DANYCH.md` — `sys_items`, `wh_stock`, `wh_documents`, `wh_document_lines`
3. `02_ARCHITEKTURA.md` — sekcja `modules/warehouse/` i `api/warehouse/*`
4. `03_MAPA_KOPALNI.md` — tylko jeśli szukasz starej logiki magazynowej

Kod:

- `modules/warehouse/`
- `api/warehouse/`
- `core/PzEngine.php`
- `core/WzEngine.php`
- `core/MmEngine.php`
- `core/InwEngine.php`
- `core/KorEngine.php`

### B. Studio Menu

Czytaj:

1. `00_PAMIEC_SYSTEMU.md` — zasady menu, recipes, modifiers, omnichannel
2. `02_ARCHITEKTURA.md` — sekcja `modules/studio/`
3. `04_BAZA_DANYCH.md` — `sh_menu_items`, `sh_modifiers`, `sh_price_tiers`, `sh_recipes`

Kod:

- `modules/studio/`
- `api/backoffice/api_menu_studio.php`

Najważniejsze pliki frontu:

- `modules/studio/js/studio_core.js`
- `modules/studio/js/studio_item.js`
- `modules/studio/js/studio_modifiers.js`
- `modules/studio/js/studio_recipe.js`
- `modules/studio/js/studio_bulk.js`

### C. Online / Online Studio

Czytaj:

1. `00_PAMIEC_SYSTEMU.md` — sekcja wizji i tabela faz
2. `15_KIERUNEK_ONLINE.md` — aktualny kierunek
3. `07_INTERACTION_CONTRACT.md` — kontrakt klient ↔ serwer
4. `05_INSTRUKCJA_FOTO_UPLOAD.md` — jeśli dotykasz assetów

Kod:

- `modules/online/`
- `modules/online_studio/`
- `api/online/`
- `api/online_studio/`
- `api/assets/`

### D. Integracje / eventy / webhooki

Czytaj:

1. `09_EVENT_SYSTEM.md`
2. `10_GATEWAY_API.md`
3. `11_WEBHOOK_DISPATCHER.md`
4. `12_INTEGRATION_ADAPTERS.md`
5. `13_SETTINGS_PANEL.md`
6. `14_INBOUND_CALLBACKS.md`

---

## 4. Czego nie traktować jako źródła prawdy

Jeśli coś jest w `_docs/ARCHIWUM/`, to:

- jest historyczne,
- może być przydatne do kontekstu,
- ale **nie wygrywa** z aktywnymi dokumentami z katalogu głównego `_docs/`.

Archiwum czytaj tylko wtedy, gdy potrzebujesz historii decyzji albo starego toku myślenia.

---

## 5. Decyzja porządkowa z 2026-04-19

Do `ARCHIWUM` zostały przeniesione dokumenty, które:

- były historyczne,
- dublowały nowsze pliki,
- albo były pomocniczą ściągą i robiły bałagan na głównym poziomie `_docs/`.

Aktywny układ ma być prosty:

- najpierw `START_TUTAJ.md`,
- potem `00_PAMIEC_SYSTEMU.md`,
- potem dokument modułowy zależny od zadania.

---

## 6. Zasada praktyczna

Jeśli masz tylko jedno pytanie: **„od czego zacząć?”**

Odpowiedź brzmi:

1. `START_TUTAJ.md`
2. `00_PAMIEC_SYSTEMU.md`
3. odpowiedni moduł z sekcji 3

---

**Kompilacja:** 2026-04-19
