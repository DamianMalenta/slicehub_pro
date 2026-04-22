# INSTRUKCJA FOTOGRAFICZNA I WALIDACJI UPLOADU — SLICEHUB ENTERPRISE

**Wersja:** 1.0 | **Data:** 2026-04-16
**Dotyczy:** Visual Compositor w Studio (`/modules/studio/`) oraz Online Storefront (`/modules/online/`)

---

## 1. LIMITY UPLOADU — BEZWZGLEDNE ZASADY BEZPIECZENSTWA

### Rozmiar pliku

| Typ assetu | Limit | Powód |
|-----------|-------|-------|
| Warstwa (scatter layer) | **max 3 MB** | Renderowanie wielu warstw naraz (6-10 per pizza) |
| Hero (produkt) | **max 1.5 MB** | Wiele hero na surface jednocześnie |
| Miniaturka dania (menu grid) | **max 800 KB** | Dziesiątki miniaturek na jednej stronie |
| Tło surface | **max 5 MB** | Jeden plik, ale ładowany na starcie strony |
| Companion (sos/napój) | **max 1.5 MB** | Kilka na ekranie |

### Formaty

| Dozwolony format | Rozszerzenia |
|-----------------|-------------|
| WebP | `.webp` |
| PNG (z alpha) | `.png` |

**ZAKAZANE:** `.jpg`, `.jpeg`, `.gif`, `.bmp`, `.svg`, `.tiff`, `.heic`

JPG nie obsługuje przezroczystości (alpha channel) — składniki muszą mieć przezroczyste tło.
Jedyny wyjątek: **tło surface** (`.webp` lub `.jpg` dozwolone, bo nie wymaga alpha).

### Wymiary (piksele)

| Typ assetu | Minimum | Zalecane | Maksimum |
|-----------|---------|----------|----------|
| Warstwa (scatter) | 1000 × 1000 | 1500 × 1500 | 3000 × 3000 |
| Hero (produkt) | 400 × 400 | 600 × 600 | 1200 × 1200 |
| Miniaturka dania | 300 × 300 | 400 × 400 | 800 × 800 |
| Tło surface | 1920 × 1080 | 2560 × 1600 | 3840 × 2400 |
| Companion | 400 × 400 | 600 × 600 | 1200 × 1200 |

**ODRZUCENIE:** Pliki poniżej minimum wymiarów lub powyżej maksimum są odrzucane z komunikatem błędu.

### Walidacja po stronie serwera (PHP)

```
1. Sprawdź MIME type (getimagesize): image/webp lub image/png
2. Sprawdź rozszerzenie: .webp lub .png
3. Sprawdź rozmiar: <= limit per typ (w bajtach)
4. Sprawdź wymiary: >= minimum, <= maksimum
5. Dla warstw/hero: sprawdź alpha channel (imagecolorsforindex na PNG, libwebp na WebP)
6. Wygeneruj unikalną nazwę: {category}_{sub_type}_{6_hex_hash}.{ext}
7. Zapisz do /uploads/global_assets/ (shared) lub /uploads/visual/{tenant_id}/ (per-tenant)
```

### Walidacja po stronie klienta (JS — pre-upload)

```
1. Sprawdź rozszerzenie pliku (.webp / .png)
2. Sprawdź File.size <= limit
3. Załaduj do Image() → sprawdź naturalWidth / naturalHeight vs min/max
4. Pokaż preview w uploaderze PRZED wysłaniem
5. Jeśli walidacja nie przejdzie → pokaż komunikat, ZABLOKUJ upload
```

---

## 2. INSTRUKCJA FOTOGRAFICZNA DLA MANAGERA / FOTOGRAFA

### 2.1. Zasady ogólne

- **Kąt kamery:** 100% zdjęć z **bezpośrednio nad** (bird's-eye / flat lay). Kamera idealnie równoległa do powierzchni.
- **Tło:** ZAWSZE przezroczyste (białe/szare studio, usunięte w postprodukcji) — z wyjątkiem zdjęcia surface.
- **Oświetlenie:** Miękkie, ciepłe, z jednej konsekwentnej strony (zalecane: ~godz. 10:00, z góry-lewej). Identyczne we WSZYSTKICH sesjach.
- **Kalibracja kolorów:** Ciepłe tony. Używaj karty referencyjnej kolorów (ColorChecker) w każdej sesji. Pliki finalne muszą wyglądać apetycznie na ciemnym tle — nie wyprane, nie przesycone.
- **Rozdzielczość:** Dostarczaj w 2x (retina-ready). Jeśli wymagane minimum to 1500px, fotografuj w minimum 3000px i skaluj w dół.

### 2.2. Warstwa (scatter layer) — do nakładania na pizzę

- Składnik rozsypany jak na pizzy, widok z góry.
- Wypełnia ~60-70% kwadratowej ramki. Zostawia margines na brzegach (nie dotyka krawędzi kadru).
- Naturalny, organiczny układ — garść oliwek rzuconych na blat, startki mozzarelli luźno rozłożone, plasterki salami nierówno rozrzucone. **NIE układaj idealnie symetrycznie.**
- Format docelowy: kwadrat (proporcje 1:1).
- Przezroczyste tło (alpha).
- Rozdzielczość: min. 1500 × 1500px, zalecane 2000 × 2000px.
- Nazwa pliku: `{typ}_{skladnik}_{wersja}.webp` np. `layer_tomato_cherry_v1.webp`

### 2.3. Hero (produkt) — do wyświetlania na powierzchni i w siatce menu

- Piękny, kompaktowy, pojedynczy obiekt.
- Przykłady:
  - JEDEN pomidorek koktajlowy na łodyżce
  - 3 listki bazylii artystycznie ułożone
  - Mała kupka (4-5 szt.) plastrów salami
  - Mozzarella ball przecięta na pół
  - Garść kukurydzy w miniaturowej miseczce z drewna (opcjonalnie)
- Format: kwadrat (1:1).
- Przezroczyste tło (alpha).
- Rozdzielczość: min. 600 × 600px, zalecane 800 × 800px.
- Musi wyglądać jak **postawiony na ciemnej powierzchni przez food stylistę**.
- Nazwa pliku: `hero_{skladnik}_{wersja}.webp` np. `hero_tomato_cherry_v1.webp`

### 2.4. Tło surface — ciemna powierzchnia restauracji

- Prawdziwa fizyczna powierzchnia sfotografowana z góry.
- Materiały: ciemny kamień, stary marmur, ciemne drewno, łupek, beton. Coś z subtelną teksturą.
- **NIE:** silne powtarzające się wzory, jasne kolory, błyszczące powierzchnie.
- Rozdzielczość: min. 2560 × 1600px, idealnie 3840 × 2400px.
- Format: `.webp` (dopuszczalny też `.jpg` — nie wymaga alpha).
- Jedno zdjęcie per motyw marki (np. jedno na sezon, jedno na całą sieć).
- Nazwa pliku: `surface_{motyw}_{wersja}.webp` np. `surface_dark_marble_v1.webp`

### 2.5. Companion (sos, napój, deser, dodatek)

- Widok z góry (widzimy czubek butelki, wieczko sosu, wierzch paczki).
- Naturalny lekki cień pod produktem dozwolony (dodaje realizmu na ciemnym tle).
- Przezroczyste tło (alpha).
- Rozdzielczość: min. 600 × 600px.
- Nazwa pliku: `comp_{typ}_{nazwa}_{wersja}.webp` np. `comp_sauce_garlic_v1.webp`

### 2.6. Miniaturka dania (dla listy menu online)

- Danie widziane z góry, pięknie skomponowane, na przezroczystym tle LUB na tej samej ciemnej powierzchni.
- Kwadrat (1:1).
- Rozdzielczość: min. 400 × 400px.
- Nazwa pliku: `thumb_{sku}_{wersja}.webp` np. `thumb_PIZZA_MARGHERITA_v1.webp`

---

## 3. KONWENCJA NAZW PLIKÓW (AUTOMATYCZNA)

System automatycznie generuje nazwy plików przy uploaderze. Manager nie musi się o to martwić.

Format: `{category}_{sub_type}_{6_hex_hash}.{ext}`

Przykłady:
- `meat_salami_cc276c.webp`
- `veg_tomato_af2636.webp`
- `herb_basil_acb969.webp`
- `board_plate_133aed.webp`

Hash jest generowany z SHA-256 zawartości pliku (pierwsze 6 znaków). Gwarantuje unikalność bez kolizji.

---

## 4. CHECKLIST DLA MANAGERA PRZED UPLODEM

- [ ] Plik jest w formacie `.webp` lub `.png`
- [ ] Plik waży poniżej limitu (3 MB dla warstwy, 1.5 MB dla hero)
- [ ] Zdjęcie jest kwadratowe (proporcje 1:1) — z wyjątkiem tła surface
- [ ] Zdjęcie ma przezroczyste tło (alpha channel) — z wyjątkiem tła surface
- [ ] Zdjęcie jest ostre, nie rozmazane
- [ ] Oświetlenie jest spójne z resztą assetów (ciepłe, z tej samej strony)
- [ ] Składnik jest widoczny na ciemnym tle (nie za ciemny sam w sobie)
- [ ] Wymiary spełniają minimum (1000px warstwa / 400px hero / 1920px surface)

---

## 5. KOMUNIKATY BŁĘDÓW DLA UŻYTKOWNIKA (JS/PHP)

| Błąd | Komunikat PL |
|------|-------------|
| Zły format | "Dozwolone formaty: .webp i .png. Przesłany plik ma niedozwolone rozszerzenie." |
| Za duży plik | "Plik jest za duży. Maksymalny rozmiar dla tego typu to {limit} MB." |
| Za małe wymiary | "Zdjęcie jest za małe. Minimalne wymiary to {min_w}×{min_h} pikseli." |
| Za duże wymiary | "Zdjęcie jest za duże. Maksymalne wymiary to {max_w}×{max_h} pikseli." |
| Brak alpha (warstwa/hero) | "Zdjęcie nie ma przezroczystego tła. Warstwy i produkty wymagają formatu z alpha channel." |
| Upload błąd serwera | "Błąd przesyłania. Spróbuj ponownie lub zmniejsz plik." |
