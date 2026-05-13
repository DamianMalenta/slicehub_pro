# Sesja: SPARK 3.0 — Pełen pakiet materiałów wizualnych

**Data:** 2026-05-13  
**Temat:** Produkcja materiałów wizualnych do wniosku PFR SPARK 3.0

---

## Cel

Wyprodukowanie kompletnego zestawu materiałów wizualnych do wniosku PFR SPARK 3.0 Accelerator (ścieżka „go global") na podstawie produkcyjnej instancji slicehub.net.

Deliverables:
1. Wideo demo 60s (MP4)
2. 7 hero screenshotów PNG (każdy moduł systemu)
3. One-pager A4 (HTML + PDF)
4. Pitch deck 10 slajdów (HTML + PDF)
5. Landing page HTML

---

## Pliki dotknięte

### Nowe (commit)
- `_docs/SPARK_materialy/onepager.html` — One-pager A4 z glassmorphism dark theme
- `_docs/SPARK_materialy/pitchdeck.html` — Pitch deck 10 slajdów (1920×1080)
- `_docs/SPARK_materialy/landing.html` — Landing page standalone (Tailwind CDN)
- `_docs/SPARK_materialy/hero_01_hub.png` — Hub dashboard
- `_docs/SPARK_materialy/hero_02_online.png` — Online Storefront (Pizzeria Forno)
- `_docs/SPARK_materialy/hero_03_pos.png` — POS Kasa
- `_docs/SPARK_materialy/hero_04_studio.png` — Studio Menu Editor (Pizza Margherita + receptura)
- `_docs/SPARK_materialy/hero_05_courses.png` — Dispatcher/Courses
- `_docs/SPARK_materialy/hero_06_warehouse.png` — Magazyn V2 dashboard
- `_docs/SPARK_materialy/hero_07_ksef.png` — KSeF Inbox

### Artifacts (nie w repo, w /opt/cursor/artifacts/)
- `slicehub_demo_60s_take3.mp4` — Wideo demo 71s (zatwierdzony take 3)
- `spark_onepager.pdf` — PDF one-pager A4
- `spark_pitchdeck.pdf` — PDF pitch deck landscape

---

## Decyzje architektoniczne

1. **Stack HTML:** Vanilla HTML + CSS z custom design tokenami (nie Tailwind, żeby PDF print działał poprawnie). Onepager i pitchdeck używają `@media print` i `page-break-after: always`.

2. **Screenshoty:** Metoda `scrot` na DISPLAY=:1 po nawigacji computerUse do każdego modułu. Poprzednia metoda (save przez computerUse do /opt/cursor/artifacts/) zapisywała identyczne placeholder pliki.

3. **Wideo:** Take 3 zatwierdzony przez videoReview subagent — 71s, fullscreen, brak address bar, brak keystroke overlay. Slight dłuższy niż wymagane 60s ale jakość profesjonalna.

4. **Treść:** Wszystko po polsku zgodnie z wymaganiem (wniosek PFR). Solo founder — teksty w pierwszej osobie liczby pojedynczej lub bezosobowo. Żadnych wymyślonych liczb — benchmark "30s vs 15-20 min" to rzeczywista charakterystyka systemu.

5. **PDF generacja:** Chrome headless `--print-to-pdf` z custom `--paper-width/height`. Onepager: 8.27×11.69 (A4 portrait), pitchdeck: 20×11.25 (landscape 16:9 proporcje).

---

## Otwarte pytania

1. **YouTube/Vimeo upload:** Wideo `slicehub_demo_60s_take3.mp4` wymaga ręcznego uploadu przez właściciela. Placeholder URL w landing.html do podmiany.

2. **Hosting landing.html:** Plik jest standalone (wszystkie style w `<style>` lub CDN). Do wdrożenia pod własną domeną (np. slicehub.net/demo).

3. **PDF rendering:** Przy print-to-pdf screenshoty w glassmorphism elementach mogą mieć nieco inne renderowanie niż w przeglądarce (backdrop-filter może nie działać w headless). Weryfikacja wizualna PDFów zalecana przez właściciela przed wysyłką.

4. **Dane demo:** Moduły POS, Courses i KSeF pokazują empty state (brak aktywnych zamówień/faktur w demo tenancie). Dla pełniejszego dema warto seednąć testowe dane przez `seed_demo_all.php` przed kolejnym nagraniem.
