# PROMPT V2 — Nagranie SPARK · localhost (VM) · własny tenant · 7 procesów

> **Skopiuj cały blok od linii `---` do NOWEGO okna Cloud Agent.**  
> **Środowisko:** wirtualny serwer / Cursor Cloud — **NIE slicehub.net** (tam trzeba znać cudzy `tenant_id`).  
> **Narzędzia:** `computerUse` + `RecordScreen` + `videoReview`.  
> **Zakaz:** Playwright headless, `record_spark_forno_demo.py`, sklejka zrzutów, nagrywanie na produkcji bez polecenia usera.

---

# Zadanie: postaw lokalne demo, utwórz tenant, nagraj 7 procesów z przeklikiwaniem

## 0. Zasady jakości (FAIL = przerób)

| ❌ Odrzuć | ✅ Akceptuj |
|----------|-------------|
| Ekran stoi **>3 s** bez scrollu/kliku | Ciągły ruch myszy między akcjami |
| Skok URL z paska adresu między modułami | **Hub → klik kafelka** |
| Losowe moduły bez historii | **7 procesów** w kolejności P1→P7 |
| slicehub.net bez własnego tenanta | **`http://localhost/slicehub`** + tenant z bootstrap |

---

## 1. Bootstrap środowiska (OBOWIĄZKOWE — zrób PRZED nagraniem)

### 1.1 Uruchom stack

```bash
cd /workspace

# MariaDB + Apache (AGENTS.md)
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld 2>/dev/null || true
mysqld_safe &
sleep 3
ln -sf /workspace /var/www/html/slicehub 2>/dev/null || true
apachectl start 2>/dev/null || service apache2 start
sleep 2
curl -sI http://localhost/slicehub/ | head -3
```

### 1.2 Utwórz **własny tenant** + menu Pizza Forno

```bash
chmod +x scripts/bootstrap_spark_recording_env.sh
bash scripts/bootstrap_spark_recording_env.sh
```

Skrypt:
1. Tworzy tenant **„Pizza Forno SPARK”** (nowe `tenant_id` — np. 2, 3…)
2. Zakłada konta: **`spark_owner` / `password`**, **`spark_driver` / `password`**
3. Wgrywa `seed_pizzaforno.sql` z podmienionym `@tid`
4. Odpala `prep_spark_demo_orders.py` (KDS + kierowca niepuste)

**Wynik:** plik `/opt/cursor/artifacts/spark_recording_env.json` — **przeczytaj go** przed nagraniem.

Przykład zawartości:

```json
{
  "base_url": "http://localhost/slicehub",
  "tenant_id": 3,
  "owner": { "username": "spark_owner", "password": "password" },
  "driver": { "username": "spark_driver", "password": "password" },
  "online_url": "http://localhost/slicehub/modules/online/index.html?tenant=3",
  "track_url": "http://localhost/slicehub/modules/online/track.html?tenant=3&token=..."
}
```

### 1.3 Weryfikacja (musi PASS)

```bash
TID=$(python3 -c "import json; print(json.load(open('/opt/cursor/artifacts/spark_recording_env.json'))['tenant_id'])")
bash scripts/seed_pizzaforno_verify.sh slicehub_pro_v2 "$TID"
python3 scripts/prep_spark_demo_orders.py
```

- KDS ≥ 1 bilet  
- Driver ≥ 1 zamówienie w trasie  

Jeśli FAIL — **nie nagrywaj**, napraw bootstrap.

---

## 2. URL i logowanie (localhost)

| Co | Wartość |
|----|---------|
| **Baza URL** | `http://localhost/slicehub` |
| **Hub** | `/modules/hub/index.html` |
| **Online** | `/modules/online/index.html?tenant=TID` ← **TID z JSON** |
| **Track** | `track_url` z `spark_recording_env.json` |
| **Owner** | `spark_owner` / `password` |
| **Kierowca** | `spark_driver` / `password` |
| **POS PIN** | `0000` (manager) lub `4444` (driver kiosk) |

JWT inject (formularz WWW często nie działa z automacji):

```javascript
localStorage.setItem('sh_token', 'TOKEN_Z_API');
localStorage.setItem('sh_user', JSON.stringify(USER_Z_API));
location.reload();
```

Login API:

```bash
curl -s -X POST 'http://localhost/slicehub/api/auth/login.php' \
  -H 'Content-Type: application/json' \
  -d '{"mode":"system","username":"spark_owner","password":"password"}'
```

---

## 3. Nagrywanie — 7 PROCESÓW (osobne klipy lub jeden długi)

**Max 2–3 s** bez ruchu. **Min. 8 klików** na proces. Kursor **widoczny**.

Nagrywaj: `RecordScreen START` → akcje → `SAVE spark_P1_online.webm` (itd.).

| Proces | Tytuł | Kluczowe przeklikania |
|--------|-------|------------------------|
| **P1** | Klient wybiera pizzę | Online `?tenant=TID` → scroll → PIZZE → MARGHERITA → **30↔37 cm** (cena!) → koszyk |
| **P2** | Kuchnia | Hub → **KDS** → hover bilet → **ROZPOCZNIJ** → **GOTOWE** |
| **P3** | Śledzenie | `track_url` z JSON → timeline → scroll → mapa (+ zoom) |
| **P4** | Logistyka | Hub → **Courses** → mapa (drag) → zamówienie → **dispatch** na kierowcę → **K/L** |
| **P5** | Kierowca | JWT **spark_driver** → **Driver App** → klik stop → szczegóły (nie zamykaj dostawy bez płatności) |
| **P6** | KSeF | Hub → Procurement → **FA/FORNO/2026/001** → scroll linii → tag magazyn/OPEX |
| **P7** | Właściciel | Hub → **BI** → Załaduj → scroll → **Studio** → MARGHERITA parent → 30CM/37CM → receptura |

Szczegóły krok-po-kroku: sekcja 4 w poprzedniej wersji — **te same kliknięcia**, tylko URL = localhost + TID z JSON.

---

## 4. Montaż

7 plików `spark_P1_*.webm` … `spark_P7_*.webm` → concat z **crossfade 0.4 s**, przyspieszenie **max 1.25×** (nie `setpts=0.12`).

Wyjście: `/opt/cursor/artifacts/spark_forno_procesy_final.mp4` (3–5 min surowego → ~90–120 s finał).

```html
<video src="/opt/cursor/artifacts/spark_forno_procesy_final.mp4" controls></video>
```

---

## 5. videoReview — PASS/FAIL

- [ ] Nagranie z **localhost**, nie produkcja  
- [ ] Tenant utworzony przez bootstrap (podaj `tenant_id` w raporcie)  
- [ ] P1: widać zmianę ceny 30↔37 cm  
- [ ] P2: KDS zmienia status po kliku  
- [ ] P5: kierowca ma listę, nie „Brak kursów”  
- [ ] Brak segmentów >3 s freeze  

---

## 6. Pliki repo

| Plik | Rola |
|------|------|
| `scripts/bootstrap_spark_recording_env.sh` | **Start tutaj** — DB + tenant + seed |
| `scripts/bootstrap_spark_recording_tenant.php` | Tworzy tenant + users + seed SQL |
| `scripts/prep_spark_demo_orders.py` | KDS + dispatch (env: SLICEHUB_BASE) |
| `scripts/seed_pizzaforno.sql` | Menu 1:1 Forno |
| `scripts/record_spark_forno_demo.py` | **NIE** do wideo |

---

## 7. Produkcja (slicehub.net) — tylko jeśli user wyraźnie każe

Wymaga istniejącego `tenant_id=2` i kont Damian/Kasia. **Domyślnie: localhost.**

---

*Rewizja: 2026-05-21 · V2.2 — localhost, własny tenant, 7 procesów, anty-slideshow*
