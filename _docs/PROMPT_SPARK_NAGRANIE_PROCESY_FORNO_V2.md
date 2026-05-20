# PROMPT V2 — Nagranie SPARK · Pizza Forno · PROCESY + PRZEKLIKANIE (nie zrzuty)

> **Skopiuj cały blok od linii `---` do NOWEGO okna Cloud Agent.**  
> **Model:** Sonnet + **`computerUse`** + **`RecordScreen`** + **`videoReview`**.  
> **Zakaz:** Playwright headless, `record_spark_forno_demo.py`, concat screenshotów, skok URL bez kliknięcia w Hub.

---

# Zadanie: nagraj prezentację **procesów biznesowych** — ciągłe przeklikiwanie, zero „slideshow”

## 0. Dlaczego poprzednie nagrania są ZŁE (nie powtarzaj)

| Problem użytkownika | Przyczyna | Twoja reguła |
|---------------------|-----------|--------------|
| „Każdy zrzut stoi kilka sekund” | Agent: otwórz URL → `sleep(6)` → następny moduł | **Max 2 s** bez ruchu myszy/scrollu |
| „Zrzuty losowe” | Nagrywanie **per moduł** zamiast **per proces** | Nagrywaj **7 procesów** w logicznej kolejności |
| „Mało przeklikań” | Brak sekwencji klików, tylko wejście na stronę | **Min. 8–12 klików** na proces |
| „Jak sklejone screenshoty” | Playwright + ffmpeg `setpts=0.12` | Tylko **RecordScreen** + montaż między procesami |

**Odrzuć nagranie (videoReview), jeśli:** między dwoma akcjami użytkownika mija **>3 s** bez zmiany UI (wyjątek: ładowanie API max 5 s z animacją/spinnerem).

---

## 1. Architektura materiału — 7 PROCESÓW (nie 10 modułów)

Nagrywaj **7 osobnych klipów** (7× START/SAVE RecordScreen) **LUB** jeden długi klip z **tytułami procesów** w głowie — ale **wewnątrz procesu ZERO cięć i ZERO zamrożeń**.

| # | Proces (tytuł slajdu / rozdział) | Moduły w łańcuchu | Surowy czas | Min. kliknięć |
|---|-----------------------------------|-------------------|-------------|---------------|
| **P1** | Klient wybiera pizzę (wariant 30/37) | Online | 90–120 s | 10 |
| **P2** | Kuchnia widzi zamówienie i robi „gotowe” | KDS | 60–90 s | 8 |
| **P3** | Klient śledzi status (bez dzwonienia) | Track WWW | 45–60 s | 5 |
| **P4** | Dyspozytor składa kurs i wysyła Kasię | Courses → Hub → POS (flota) | 90–120 s | 12 |
| **P5** | Kierowca jedzie i rozlicza dostawę | Driver PWA | 60–90 s | 8 |
| **P6** | Faktura KSeF → magazyn + koszt lokalu | Procurement (KSeF) | 90 s | 10 |
| **P7** | Właściciel widzi marżę + edycja menu | BI → Studio Menu | 90 s | 10 |

**Kolejność obowiązkowa:** P1 → P2 → P3 → P4 → P5 → P6 → P7 (historia jednego dnia).

**Między procesami (P1→P2):** możesz zatrzymać nagranie, ale w montażu tylko **przeniesienie 0.4 s** — **nie** 5 s czarnego ekranu.

---

## 2. Przygotowanie (terminal — NIE nagrywaj)

```bash
bash scripts/seed_pizzaforno_verify.sh slicehub_pro_v2 2   # opcjonalnie
python3 scripts/prep_spark_demo_orders.py                 # OBOWIĄZKOWE — KDS + kierowca
```

Sprawdź przed kamerą:
- KDS `get_board` ≥ 1 bilet (FORNO-004 / ORD-…)
- Kasia `get_driver_runs` ≥ 1 zamówienie po dispatchu w P4

**Tracking P3:** ustaw `tracking_token` na FORNO-006 (SQL w `PROMPT_SPARK_NAGRANIE_PROCESY_FORNO.md` §1.4).

---

## 3. Zasady PRZEKLIKANIA (najważniejsze)

### 3.1 Ruch ciągły

- Kursor **zawsze widoczny**, poruszaj się **płynnie** między przyciskami (nie teleportuj).
- Po każdym kliku: **natychmiast** następna akcja (scroll, hover, drugi klik) — **nie** celebruj statycznego ekranu.
- **Zakaz:** „pokażę hub 10 sekund” — hub max **3 s** scroll + klik w kafelek.

### 3.2 Nawigacja tylko przez UI (prezentacja)

| ❌ | ✅ |
|----|-----|
| Wklejasz URL `/modules/kds/` w pasku | Hub → **klik kafelka KDS** |
| Nowa karta na każdy moduł | Ta sama karta, **wstecz/Hub** lub kafelek |
| JWT inject i koniec | Inject **raz** na start, potem **tylko kliki** |

JWT (formularz WWW nie działa):

```javascript
localStorage.setItem('sh_token','TOKEN');
localStorage.setItem('sh_user', JSON.stringify(USER));
location.reload();
```

| Rola | Login | Hasło |
|------|-------|-------|
| Owner | `Damian` | `Dammalq123123` |
| Kierowca | `kasia@slicehub.net` | `asdasd` |
| POS PIN | `1111` | |

### 3.3 Jedno nagranie = jeden proces

Dla każdego P1…P7:

```
RecordScreen START_RECORDING
→ wykonaj WSZYSTKIE kroki z tabeli §4 (bez przerwy >2s)
→ RecordScreen SAVE save_as_filename=spark_P1_online
```

Nazwy plików: `spark_P1_online`, `spark_P2_kds`, … `spark_P7_bi_studio`.

---

## 4. Scenariusze klik-po-kliku (wykonaj DOKŁADNIE w tej kolejności)

### P1 — Klient: menu i rozmiar (Online)

1. Wejdź Online (link z Huba lub `index.html` tylko na start P1).
2. **Scroll w dół** kategoriami — widać PIZZE, PANINI (2 s scroll, nie stop).
3. **Klik** kategoria PIZZE.
4. **Klik** pizza (np. MARGHERITA) — panel produktu się otwiera.
5. **Klik** przełącznik **30 cm** — czytaj cenę.
6. **Klik** przełącznik **37 cm** — **cena musi się zmienić** (to innowacja SPARK).
7. **Klik** Dodaj do koszyka.
8. **Klik** ikona koszyka — drawer się wysuwa.
9. **Scroll** w koszyku — widać linię z rozmiarem.
10. Opcjonalnie: **klik** + na napój, znowu koszyk.

**Nie kończ checkoutu** jeśli brakuje czasu — koszyk wystarczy.

---

### P2 — Kuchnia: KDS (bilet żyje)

1. **Hub → klik KDS** (nie URL).
2. Poczekaj listę — **hover** na bilecie FORNO-004 lub ORD/…
3. **Klik** przycisk **ROZPOCZNIJ** (accepted→preparing) — UI się odświeża.
4. **Klik** **GOTOWE** na tym samym lub drugim bilecie — status zmienia się na ready.
5. **Klik** drugi bilet — pokaż listę pozycji (scroll w karcie).
6. **Hub** (klik logo/wstecz) — przygotowanie do P3.

---

### P3 — Klient: tracking

1. **Nowa karta** tylko dla track (klient nie ma Hub) — `track.html?tenant=2&token=TOKEN&phone=…`
2. Formularz: wpisz token/telefon jeśli trzeba → **klik** Znajdź.
3. **Scroll** timeline — widać etapy (przyjęte / przygotowanie / …).
4. Jeśli mapa: **klik +** zoom, **przeciągnij** mapę.
5. Zostaw **2 s** na mapie z ruchem — nie 8 s freeze.

---

### P4 — Logistyka: od mapy do kursu K/L

1. Hub → **klik Courses**.
2. **Klik** zakładka **Mapa** → **przeciągnij** mapę (Leaflet drag).
3. **Klik** zakładka **Zamówienia** / lista.
4. **Klik** wiersz zamówienia **ready** (po prep: nowe ORD lub FORNO gotowe).
5. **Klik** przycisk dispatch / przypisz → wybierz **Kasię**.
6. **Klik** Potwierdź — widać **K{n}** i **L1**.
7. Hub → **klik POS** → PIN `1111` (szybko, bez zbliżenia).
8. Jeśli jest widok floty: **klik** zakładka kierowcy — Kasia **busy/zielona**.

---

### P5 — Kierowca: trasa (osobna sesja Kasia)

1. Wyloguj / inject JWT **Kasia** → Hub → **klik Driver App** (lub bezpośrednio po inject).
2. Widać **≥1 stop** — **klik** w kartę zamówienia.
3. **Scroll** szczegóły — adres, pozycje (Diavola…).
4. **Klik** przycisk dostarcz / zapłać — jeśli Payment Lock: **pokaż komunikat**, **klik Anuluj** (nie psuj danych).
5. **Klik** portfel / zakładka jeśli jest — szybki peek.

---

### P6 — KSeF: faktura → magazyn vs OPEX

1. Hub → **klik** moduł faktur / Procurement.
2. **Klik** wiersz **FA/FORNO/2026/001** (status new).
3. **Scroll** linie faktury w dół.
4. **Klik** pierwsza linia → wybierz mapowanie SKU (dropdown).
5. **Klik** tag **INVENTORY** na jednej linii, **EXPENSE/OPEX** na innej (jeśli UI ma).
6. **Scroll** do podsumowania.
7. **Klik** Akceptuj / Zapisz **tylko** jeśli bezpieczne na demo — inaczej cofnij i zostaw podgląd.

---

### P7 — Właściciel: P&L + menu wariantów

1. Hub → **klik BI**.
2. **Klik** Załaduj → poczekaj spinner → **scroll** karty P&L.
3. Hub → **klik Studio Menu**.
4. **Klik** drzewo PIZZE → **klik** MARGHERITA parent.
5. **Klik** wariant **30CM** → **klik** **37CM** — widać dzieci i ceny/recepturę.
6. **Scroll** receptura — składniki.
7. Hub — **powolny scroll** kafelków (zakończenie).

---

## 5. Montaż — 7 procesów, nie 7 screenshotów

```bash
# Po nagraniu 7 klipów — sklej Z CROSSFADE, bez przyspieszania do slideshow
ls -1 /opt/cursor/artifacts/spark_P*.webm

ffmpeg -y \
  -i spark_P1_online.webm -i spark_P2_kds.webm -i spark_P3_track.webm \
  -i spark_P4_courses.webm -i spark_P5_driver.webm \
  -i spark_P6_ksef.webm -i spark_P7_bi_studio.webm \
  -filter_complex "
    [0:v]setpts=0.85*PTS,fade=t=out:st=80:d=0.4[v0];
    [1:v]setpts=0.85*PTS,fade=t=in:st=0:d=0.4,fade=t=out:st=50:d=0.4[v1];
    ... concat or xfade=transition=fade:duration=0.4
  " \
  -an /opt/cursor/artifacts/spark_forno_procesy_final.mp4
```

**Uproszczenie:** jeśli xfade trudne — concat z **0.4 s fade** między klipami; **bez** `setpts=0.12`.

| Parametr | Wartość |
|----------|---------|
| Przyspieszenie wewnątrz procesu | **1.0–1.25×** (realne tempo klików) |
| Przyspieszenie między procesami | tylko crossfade |
| Długość finału | **3–5 min** (widać procesy), skrót **90 s** tylko jeśli user prosi |

---

## 6. Kontrola jakości (videoReview — PASS/FAIL)

Oceń każdy proces P1–P7 osobno:

| Pytanie | PASS jeśli |
|---------|------------|
| Czy widać **ciąg kliknięć**, nie jeden ekran? | ≥8 klików / proces |
| Czy **30↔37 cm** zmienia cenę? | P1 |
| Czy KDS **zmienia status** po kliku? | P2 |
| Czy dispatch pokazuje **K/L**? | P4 |
| Czy kierowca ma **listę**, nie pustkę? | P5 |
| Czy żaden segment **>3 s freeze**? | wszystkie |

**FAIL całości** → przerób tylko procesy, które nie przeszły.

---

## 7. Deliverables

```html
<video src="/opt/cursor/artifacts/spark_forno_procesy_final.mp4" controls></video>
```

Raport (tabela):

| Proces | Plik | Liczba klików (szac.) | Uwagi |
|--------|------|----------------------|-------|
| P1 | spark_P1_… | | |
| … | | | |

**NIE** wysyłaj: PNG, jeden plik ze stojącymi modułami, wideo <60 s z 7 zamrożeniami.

---

## 8. Pliki repo

| Plik | Rola |
|------|------|
| `scripts/prep_spark_demo_orders.py` | Dane pod KDS + kierowcę |
| `scripts/record_spark_forno_demo.py` | **NIE** do wideo |
| `_docs/PROMPT_SPARK_NAGRANIE_PROCESY_FORNO.md` | Seed, SQL track |

---

*Rewizja: 2026-05-21 · V2.1 — 7 procesów, klik-po-kliku, anty-zamrożenie 3s+*
