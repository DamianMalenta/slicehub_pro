# ARCHITEKTURA SYSTEMU I MAPA KATALOGÓW

Ten dokument służy Agentom AI (np. Cursor) jako oficjalna mapa drogowa po projekcie SliceHub Enterprise. Nie próbuj zgadywać, gdzie są pliki ani nie przeszukuj całego dysku w ciemno – sprawdzaj strukturę tutaj.

## 1. GŁÓWNE STREFY SYSTEMU (PRODUKCJA)

### A. BACKOFFICE (STUDIO) - Silnik Zarządzania Menu
**Katalog:** `/modules/studio/`
To jest nowoczesne serce zarządzania systemem (Menu, Ceny, KSeF).
- `index.html` - Główny szkielet i interfejs Studio.
- `studio_ui.js` - Renderowanie drzewa menu, kategorii i checkboxy zaznaczania masowego.
- `studio_item.js` - Edytor pojedynczego dania oraz Macierzy Cenowej (Omnichannel).
- `studio_modifiers.js` - Obsługa Bliźniaka Cyfrowego, ułamków zużycia surowców i akcji ADD/REMOVE.
- `studio_bulk.js` - Potężny silnik Edycji Masowej wysyłający masowe modyfikacje cenowe i temporalne.

### B. BATTLEFIELD (POS) - Strefa Operacyjna Front-Line
**Katalog:** `/modules/pos/` (w trakcie budowy/migracji)
To główny ekran restauracji dla załogi.
Główne moduły operacyjne (docelowe):
- **The Pulse:** Wąska kolumna agregująca zamówienia z własnej strony i portali (Delivery/Online).
- **The Panic Button:** System masowego zarządzania kryzysowego (opóźnienia, SMS Alerting).
- **Battlefield Main:** Centralny ekran wydawki, podział na sekcje i nabijanie na salę.

### C. BACKEND API (PHP)
**Katalog:** `/api/backoffice/`
- `api_menu_studio.php` - Główny plik operacyjny (router/switch) odbierający żądania ze Studio.
- Wszystkie połączenia z API wymagają strukturalnych obiektów JSON (np. `omnichannelPricePatch`, `temporalPublicationPatch`), zakaz przesyłania płaskich wartości zastępujących stare struktury bazy danych.

## 2. STREFA KWARANTANNY (KOD LEGACY)

### KOPALNIA WIEDZY / ZŁOMOWISKO (Dawca Organów)
**Katalog:** `/_KOPALNIA_WIEDZY_LEGACY/` (lub podobny katalog ze starymi plikami)
Tutaj znajduje się stary kod (m.in. Magazyn, Grywalizacja załogi, stary POS, system kurierski, ponad 60 plików PHP/HTML).

**BEZWZGLĘDNA ZASADA DLA AI:** 1. Cały ten folder ma status **STRICTLY READ-ONLY** (Tylko do odczytu). 
2. Masz absolutny zakaz edytowania tamtych plików. 
3. Masz zakaz linkowania tych starych plików do nowego interfejsu (np. do `index.html`).
4. Służą one wyłącznie jako encyklopedia zasad biznesowych. Kiedy tworzysz nową funkcję, masz przeczytać stary plik z kopalni, zrozumieć jego logikę działania (np. punktację w grywalizacji), a następnie napisać CAŁKOWICIE NOWY, zoptymalizowany kod w odpowiednim folderze produkcyjnym, zachowując zgodność z naszą Konstytucją.