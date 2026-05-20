# PROMPT V2 — Nagranie SPARK · Pizza Forno · PRAWDZIWE WIDEO (nie sklejka screenshotów)

> **Skopiuj cały blok od linii `---` do NOWEGO okna Cloud Agent.**  
> **Model:** Sonnet (lub równoważny z dostępem do `computerUse` + `RecordScreen` + `videoReview`).  
> **Ten prompt zastępuje podejście z `scripts/record_spark_forno_demo.py` — tamte nagranie było ZŁE.**

---

# Zadanie: nagraj płynne demo produktu (ruch, klikanie, scroll) i dostarcz wideo MP4

## 0. Co poszło źle w poprzedniej próbie (NIE POWTARZAJ)

Poprzedni agent użył **Playwright headless** → osobne pliki `.webm` per strona → **concat ffmpeg** → efekt: **ekran stoi jak sklejone screenshoty**, brak ruchu myszy, brak animacji UI, „slideshow” zamiast nagrania.

| ❌ ZAKAZ | ✅ WYMAGANE |
|---------|-------------|
| `record_spark_forno_demo.py` jako główne wideo | `RecordScreen` + `computerUse` |
| Playwright `record_video_dir` + concat | **Jedno ciągłe nagranie** lub osobne nagrania **z żywymi akcjami** między nimi |
| `page.wait_for_timeout(6000)` bez ruchu | Scroll, hover, klik, przejścia — **min. 3 akcje na scenę** |
| `setpts=0.12` na statycznych klatkach | Montaż max **2× przyspieszenie** na materiale z ruchem |
| Screenshoty PNG jako „wideo” | Tylko **prawdziwy** strumień ekranu |

**Kryterium odrzucenia (videoReview):** jeśli przez 5+ sekund nic się nie zmienia na ekranie (poza loaderem) → **DISCARD** i nagraj scenę od nowa.

---

## 1. Cel i produkcja

- **URL:** https://slicehub.net · tenant **Pizza Forno** (`tenant_id=2`)
- **Menu:** `scripts/seed_pizzaforno.sql` (~190 pozycji, warianty **30/37 cm**, panini **MAŁE/DUŻE**)
- **Odbiorca:** komisja SPARK — **jeden łańcuch procesów**, język po ludzku w głowie (voiceover opcjonalny)
- **Bez** wymyślonego MRR / liczby klientów

---

## 2. Przygotowanie danych (API — możesz z terminala, NIE nagrywaj tego)

```bash
# Weryfikacja seeda (jeśli jeszcze nie wgrany):
bash scripts/seed_pizzaforno_verify.sh slicehub_pro_v2 2

# KDS + kierowca NIE mogą być puste:
python3 scripts/prep_spark_demo_orders.py
```

**Po prep sprawdź API (musi PASS przed nagraniem):**

```bash
# KDS ≥ 1 bilet
# Driver Kasia ≥ 1 zamówienie w get_driver_runs
```

Jeśli `prep` failuje — napraw dane, **nie** nagrywaj pustych modułów.

**Tracking (opcjonalnie):** SQL z v1 promptu — `tracking_token` dla FORNO-006.

---

## 3. Logowanie (JWT inject — OK, ale potem MUSISZ klikać)

Formularz WWW na slicehub **nie działa** z xdotool. Do **pierwszego** wejścia w moduł chroniony:

1. `POST /api/auth/login.php` → `sh_token` + `sh_user`
2. W Chrome (computerUse): DevTools Console **lub** bookmarklet:

```javascript
localStorage.setItem('sh_token', 'TOKEN');
localStorage.setItem('sh_user', JSON.stringify(USER_OBJECT));
location.reload();
```

3. **Hub musi pokazać kafelki** — dopiero wtedy nagrywaj.

| Rola | Login | Hasło |
|------|-------|-------|
| Owner | `Damian` | `Dammalq123123` |
| Kierowca | `kasia@slicehub.net` | `asdasd` |
| POS PIN | — | `1111` |

**Online / track** — bez JWT (publiczne).

---

## 4. Ustawienia nagrania (OBOWIĄZKOWE)

| Parametr | Wartość |
|----------|---------|
| Rozdzielczość | **1920×1080** pełny ekran Chrome |
| Kursor | **Widoczny** — użytkownik ma widzieć gdzie klikasz |
| Zoom przeglądarki | **100%** |
| DevTools | **Zamknięte** w trakcie nagrywania |
| Powiadomienia OS | Wyłączone / poza kadr |
| Czas na scenę (surowy) | **45–90 s** aktywności, nie 6 s freeze |

### Procedura RecordScreen

```
1. computerUse → otwórz Chrome → slicehub.net
2. RecordScreen mode=START_RECORDING
3. Wykonaj sceny 1–10 (PONIŻEJ) — żywe ruchy
4. RecordScreen mode=SAVE_RECORDING save_as_filename=spark_forno_live_raw
5. videoReview — odrzuć jeśli slideshow / brak ruchu
6. ffmpeg — tylko lekki trim + max 2× speed (patrz §8)
```

**Możesz nagrać 2–3 osobne klipy** (np. backoffice / kierowca / online), ale **każdy klip** musi mieć ciągły ruch — potem montaż **crossfade 0.3s** między klipami, nie twarde cięcie co 6 s.

---

## 5. Storyboard — akcje DO WYKONANIA (nie „otwórz URL i czekaj”)

Każda scena: minimum **3 interakcje** (scroll, klik, wpisanie, przełączenie zakładki).

### Scena 1 — Online menu + warianty (60–90 s)

1. Wejdź: `https://slicehub.net/modules/online/index.html` (tenant 2 z config).
2. **Powoli scroll** od góry — widać kategorie (PIZZE, PANINI…).
3. Klik **PIZZE** → klik **MARGHERITA** (lub inna pizza).
4. **Kliknij przełącznik 30 cm → 37 cm** — pokaż że **cena się zmienia** (to innowacja!).
5. Klik **Dodaj do koszyka** → otwórz **koszyk** (drawer) — widać pozycję.
6. Opcjonalnie: dodaj napój, scroll w koszyku.
7. **NIE musisz** kończyć checkoutu — wystarczy koszyk z pozycjami.

### Scena 2 — KDS żywa kuchnia (45–60 s)

1. JWT Damian → `/modules/kds/`.
2. Poczekaj aż **widać bilety** (FORNO-004, ORD/… — po `prep_spark_demo_orders.py`).
3. **Najedź** na bilet → przeczytaj pozycje (scroll w karcie jeśli jest).
4. Klik **ROZPOCZNIJ** lub **GOTOWE** na jednym bilecie — status się **zmienia na ekranie**.
5. Zostaw 3 s na animację / odświeżenie listy.

### Scena 3 — Track klienta (30–45 s)

1. Otwórz `track.html?tenant=2&token=…&phone=…` (token z SQL lub z checkoutu).
2. Widać **timeline statusu** — scroll w dół jeśli jest mapa.
3. Jeśli mapa: **delikatny zoom** mapy Leaflet (+) — widać punkt dostawy.

### Scena 4 — Courses dispatch (60–90 s)

1. JWT Damian → `/modules/courses/`.
2. Zakładka **mapa** — przesuń mapę (drag), widać pin kierowcy.
3. Zakładka **zamówienia** — klik zamówienie **gotowe do wysyłki**.
4. **Przypisz do Kasi** (dispatch) — dialog / potwierdzenie — widać **K1 / L1**.
5. Krótko: lista kursów z numerem **K{n}**.

### Scena 5 — Driver PWA (45–60 s)

1. **Wyloguj** / nowa karta → JWT **Kasia** → `/modules/driver_app/`.
2. Widać **listę stopów** (nie „Brak kursów”).
3. Klik w zamówienie → szczegóły adresu / pozycje.
4. **NIE** zamykaj dostawy bez płatności — możesz pokazać komunikat Payment Lock i **anuluj**.

### Scena 6 — POS flota (20–30 s)

1. `/modules/pos/` → PIN **1111** szybko (nie zbliżaj się na klawiaturę długo).
2. Jeśli jest widok **floty / kierowców** — pokaż Kasię zieloną/zajętą po dispatchu.

### Scena 7 — KSeF (60 s)

1. `/modules/procurement/` → lista faktur.
2. Klik **FA/FORNO/2026/001** (status new).
3. Scroll po liniach → pokaż **mapowanie SKU** / tag **magazyn vs OPEX**.
4. Klik akceptuj / zapisz (jeśli UI pozwala bez psucia danych — inaczej tylko podgląd).

### Scena 8 — BI P&L (30 s)

1. `/modules/bi/` → klik **Załaduj**.
2. Poczekaj na wykres/liczby → **scroll** po kartach COGS / OPEX.

### Scena 9 — Studio Menu warianty (45 s)

1. `/modules/studio/` → drzewo **PIZZE** → **MARGHERITA**.
2. Pokaż **parent + dzieci 30CM / 37CM** — przełącz między wariantami.
3. Scroll do **receptury** (składniki).

### Scena 10 — Zamknięcie (15 s)

1. Hub — powolny scroll po kafelkach → najedź na Online / Courses.

---

## 6. Czego NIE pokazywać / uczciwie

- **Online Studio** — jeśli WIP: 5 s + napis w głowie „w rozwoju”, nie udawaj gotowca.
- Puste KDS / pusty kierowca — **STOP**, odpal `prep_spark_demo_orders.py`, nagrywaj od nowa.
- PIN na cały ekran przez 10 s.

---

## 7. Kontrola jakości (videoReview — obowiązkowa)

Przed oddaniem użytkownikowi odpal `videoReview` z pytaniami:

1. Czy widać **ruch myszy** lub scroll w ≥80% scen?
2. Czy **KDS** ma bilety z nazwami pizz?
3. Czy **Driver** ma co najmniej 1 stop?
4. Czy **30/37 cm** widać na Online?
5. Czy długość surowa **≥ 4 min** (przed przyspieszeniem)?

Jeśli NIE → `DISCARD_RECORDING` i powtórz **tylko** słabe sceny.

---

## 8. Montaż (delikatny — nie zamieniaj w slideshow)

```bash
IN="/opt/cursor/artifacts/spark_forno_live_raw.webm"
OUT="/opt/cursor/artifacts/spark_forno_live_final.mp4"

# Tylko lekkie przyspieszenie (2× max), zachowaj płynność
ffmpeg -y -i "$IN" -filter:v "setpts=0.5*PTS,minterpolate=fps=30:mi_mode=mci" \
  -an -c:v libx264 -preset medium -crf 20 -movflags +faststart \
  -t 120 "$OUT"
```

**Docelowa długość finału:** 90–120 s (nie 14 s ze statycznych klatek).

Opcjonalnie: ciepły voiceover PL (napisy w F6S) — osobna ścieżka audio.

---

## 9. Deliverables (checklist dla użytkownika)

W odpowiedzi **MUSI** być:

```html
<video src="/opt/cursor/artifacts/spark_forno_live_final.mp4" controls></video>
```

oraz krótki raport:

- Co kliknięto w każdej scenie (1 zdanie)
- Czy `prep_spark_demo_orders.py` był uruchomiony
- Długość surowa vs finał
- Co odrzuciłeś i dlaczego (jeśli były ponowne próby)

**NIE** wysyłaj samych PNG jako „wideo”.

---

## 10. Pliki pomocnicze w repo

| Plik | Użycie |
|------|--------|
| `scripts/prep_spark_demo_orders.py` | Tylko przygotowanie API — **tak** |
| `scripts/record_spark_forno_demo.py` | **NIE** używać do wideo v2 |
| `scripts/seed_pizzaforno.sql` | Menu Forno |
| `_docs/PROMPT_SPARK_NAGRANIE_PROCESY_FORNO.md` | v1 — referencja merytoryczna |

---

*Rewizja: 2026-05-21 · V2 — anty-slideshow, RecordScreen + computerUse obowiązkowe*
