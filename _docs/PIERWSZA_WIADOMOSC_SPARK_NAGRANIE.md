# Pierwsza wiadomość — wklej do nowego okna Cursor

> Skopiuj **całość** sekcji poniżej (od linii zadania do końca checklisty).

---

Nagraj demo SliceHub Pro pod wniosek SPARK 3.0 — **na wirtualnym serwerze (localhost)**, nie na slicehub.net. Musisz **sam utworzyć tenant** i zaseedować menu Pizza Forno (~190 pozycji, warianty 30/37 cm). Efekt: **płynne wideo z przeklikiwaniem** (7 procesów biznesowych), nie sklejka stojących zrzutów ekranu.

## Czego NIE robić (poprzednie próby były złe)

- ❌ Playwright headless, `scripts/record_spark_forno_demo.py`, concat webm z `wait 6s`
- ❌ slicehub.net (brak naszego tenant_id)
- ❌ Otwieranie modułów przez wklejanie URL — tylko **Hub → klik kafelka**
- ❌ Ekran stoi **>3 sekundy** bez scrollu/kliku/animacji
- ❌ Montaż `setpts=0.12` — max **1.25×** przyspieszenie na materiale z ruchem

## Narzędzia

`computerUse` + `RecordScreen` + `videoReview`. Kursor **widoczny**, Chrome **1920×1080**, DevTools zamknięte.

---

## KROK A — Bootstrap (przed kamerą)

```bash
cd /workspace
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld 2>/dev/null || true
mysqld_safe &
sleep 3
ln -sf /workspace /var/www/html/slicehub 2>/dev/null || true
apachectl start 2>/dev/null || service apache2 start
sleep 2

chmod +x scripts/bootstrap_spark_recording_env.sh
bash scripts/bootstrap_spark_recording_env.sh

cat /opt/cursor/artifacts/spark_recording_env.json
TID=$(python3 -c "import json; print(json.load(open('/opt/cursor/artifacts/spark_recording_env.json'))['tenant_id'])")
bash scripts/seed_pizzaforno_verify.sh slicehub_pro_v2 "$TID"
```

Bootstrap tworzy tenant **„Pizza Forno SPARK”**, konta **`spark_owner` / `password`**, **`spark_driver` / `password`**, wgrywa `seed_pizzaforno.sql`, odpala prep (KDS + kierowca nie mogą być puste).

**Jeśli KDS=0 lub Driver=0 → STOP, napraw, nie nagrywaj.**

Logowanie do modułów (formularz WWW często nie działa):

```bash
curl -s -X POST 'http://localhost/slicehub/api/auth/login.php' \
  -H 'Content-Type: application/json' \
  -d '{"mode":"system","username":"spark_owner","password":"password"}'
```

→ JWT do `localStorage.sh_token` + `sh_user` → reload. Online/track: URL z `?tenant=TID` w `spark_recording_env.json`.

---

## KROK B — 7 procesów (nagrywaj osobno: `spark_P1_online.webm` … `spark_P7_bi.webm`)

**Reguła:** min. **8 kliknięć** na proces, max **2–3 s** bez ruchu.

### P1 — Klient wybiera pizzę (60–90 s)

1. Otwórz `online_url` z JSON (`?tenant=TID`).
2. Scroll w dół — kategorie PIZZE, PANINI.
3. Klik **PIZZE** → klik **MARGHERITA**.
4. Klik **30 cm** — zobacz cenę.
5. Klik **37 cm** — **cena musi się zmienić** (innowacja SPARK).
6. Klik **Dodaj do koszyka**.
7. Klik ikona **koszyka** — drawer, scroll pozycji.
8. Opcjonalnie: dodaj napój.

### P2 — Kuchnia KDS (60 s)

1. Hub → **klik KDS** (nie URL z paska).
2. Hover na bilecie (FORNO-004 / ORD-…).
3. Klik **ROZPOCZNIJ** — status się zmienia.
4. Klik **GOTOWE** — drugi bilet lub ten sam.
5. Scroll listy pozycji na bilecie.

### P3 — Klient śledzi zamówienie (45 s)

1. Otwórz `track_url` z JSON.
2. Jeśli formularz: wpisz dane → **Znajdź**.
3. Scroll **timeline** statusów.
4. Mapa: zoom **+**, przeciągnij mapę.

### P4 — Dyspozytor i kurs K/L (90 s)

1. Hub → **Courses**.
2. Klik zakładka **Mapa** → **przeciągnij** mapę.
3. Zakładka **Zamówienia** → klik wiersz **ready**.
4. **Dispatch** → wybierz **spark_driver** → Potwierdź → widać **K{n}** / **L1**.
5. Hub → **POS** → PIN `0000` szybko.
6. Jeśli jest flota: Kasia/driver **busy** (krótko).

### P5 — Kierowca (60 s)

1. Inject JWT **spark_driver** → Hub → **Driver App**.
2. Musi być **≥1 stop** (nie „Brak kursów”).
3. Klik kartę zamówienia → scroll adres/pozycje.
4. Klik dostarcz — jeśli Payment Lock: pokaż komunikat → **Anuluj**.

### P6 — Faktura KSeF (60 s)

1. Hub → Procurement / KSeF.
2. Klik **FA/FORNO/2026/001**.
3. Scroll linie faktury.
4. Klik linia → mapowanie SKU; pokaż tag **magazyn** vs **OPEX** jeśli jest w UI.

### P7 — Właściciel: P&L + menu (90 s)

1. Hub → **BI** → klik **Załaduj** → scroll liczb.
2. Hub → **Studio Menu** → drzewo **PIZZE** → **MARGHERITA**.
3. Klik wariant **30CM** → **37CM** (parent/children).
4. Scroll **receptura**.
5. Hub — scroll kafelków (zakończenie).

---

## KROK C — Montaż i dostawa

- Sklej 7 klipów: **crossfade 0.4 s**, bez twardego cięcia co 6 s.
- Przyspieszenie max **1.25×**.
- Plik: `/opt/cursor/artifacts/spark_forno_procesy_final.mp4` (cel 90–120 s finału).

W odpowiedzi podaj:

```html
<video src="/opt/cursor/artifacts/spark_forno_procesy_final.mp4" controls></video>
```

oraz tabelę: proces | liczba klików | tenant_id | PASS/FAIL videoReview.

**videoReview:** odrzuć klip jeśli >3 s freeze lub brak zmiany ceny 30↔37 w P1.

Dokumentacja w repo: `_docs/PROMPT_SPARK_NAGRANIE_PROCESY_FORNO_V2.md`, skrypty `scripts/bootstrap_spark_recording_env.sh`.
