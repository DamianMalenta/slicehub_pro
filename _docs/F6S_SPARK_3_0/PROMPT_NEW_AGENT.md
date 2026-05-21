# PROMPT do nowego okna Cursor Cloud Agent — Materiały prezentacyjne SPARK 3.0

> Skopiuj **cały blok poniżej linii ---** i wklej w nowe okno cloud agenta.
> Agent dostanie kompletny kontekst projektu, dostępy do produkcyjnej instancji,
> listę materiałów już istniejących na `main` i konkretne zadania w priorytetach.

---

# Zadanie: Wyprodukuj materiały prezentacyjne dla SPARK 3.0 Accelerator

Jestem solo founderem projektu **SliceHub Pro** — wielomodułowego systemu operacyjnego dla gastronomii (POS, magazyn, kuchnia/KDS, dostawy, Studio menu, KSeF, Online storefront). Aplikuję do akceleratora **SPARK 3.0 (F6S, PFR)** i potrzebuję profesjonalnych materiałów prezentacyjnych. Część jest już w repo, część trzeba dorobić.

## Kontekst projektu (skrót)

- **Stack**: Vanilla JS (ES6+) + Tailwind CSS frontend, PHP 8 + PDO backend, MariaDB. Bez Node/npm w produkcji ("Zero-Reload Runtime") — niska TCO, hostowane na zwykłym shared hostingu (uti.pl).
- **Architektura**: multi-tenant SaaS, 10 zasad konstytucyjnych (Digital Twin, Price Matrix, Zero Trust, AI Session Audit itd.).
- **Moduły**: Hub (kafelki nawigacyjne), Online (storefront B2C), POS (sala/wynos/dostawa), Studio Menu (CRUD pozycji z wariantami/recepturami/combo), Courses (dyspozytor + driver app), Magazyn (AVCO + KSeF auto-PZ), Kadry (HR + PIN clock), Settings.
- **Wyróżniki**: Omnichannel pricing (dine-in/takeaway/delivery różne ceny w DNA), variant scales z multiplierami receptur, multi-stage recipes (półprodukty), KSeF AutoScan (fuzzy matching faktur dostawców).
- **Repo**: GitHub `DamianMalenta/slicehub_pro`, branch `main`.
- **Live**: https://slicehub.net (uti.pl shared hosting, działa produkcyjnie).

## Dostępy do live (do nagrywania demo)

- **URL**: https://slicehub.net
- **Login backoffice**: `Damian` / `Dammalq123123`
- **PIN POS (kiosk)**: `1111`
- **Tenant_id**: `2`

Po zalogowaniu jako backoffice trafiasz do Huba (`/modules/hub/index.html`). Z kafelków otwierasz każdy moduł. Demo dane są wstępnie zaseedowane.

## Co JUŻ JEST na `main` (NIE rób tego ponownie)

Folder `_docs/F6S_SPARK_3_0/`:
- `01_VOICEOVER_PRODUKT_60s.md` — pełny skrypt 60s voiceover PL + ENG + storyboard sekunda-po-sekundzie z URL-ami modułów do pokazania
- `02_VOICEOVER_TEAM_60s.md` — skrypt filmu zespołowego (60s)
- `03_TEKSTY_FORMULARZ_F6S.md` — gotowe odpowiedzi do formularza F6S (różne długości)
- `04_PITCH_DECK_PELNY.md` — 16 slajdów (treść)
- `05_CHECKLIST_PRODUKCJI_WIDEO.md` — przygotowanie nagrania, sprzęt, postprodukcja
- `06_DECK_EXPORT.html` — deck HTML do druku PDF

Folder `_docs/pitchdeck_SPARK_3_output/`:
- `SliceHub_Spark3_Pitch.pdf` — wyeksportowany pitch deck
- `SliceHub_Spark3_Pitch.pptx` — wersja edytowalna w PowerPoint

Pliki `wniosek*.md` w root repo:
- `wniosek.md`, `wniosek_innowacje.md`, `wniosek_porownanie_papu.md`, `wniosek_modul_magazyn.md`, `wniosek_modul_studio_online.md` — gotowe sekcje wniosku

Folder `presentations/spark-30-slicehub/`:
- `index.html` — strona prezentacyjna (do dopracowania)

Inne: `_docs/PITCH_DECK_SPARK_3.0_ACCELERATOR.md`, `_docs/canvasy/PITCH_DECK_SPARK_3.0_ACCELERATOR_10SLAJDOW.md`, `scripts/build_spark_pitchdeck.py`.

## Co MASZ WYPRODUKOWAĆ (priorytety)

### Priorytet 1 — Demo wideo MP4 (60 sekund, dla pola "Product Video" w F6S)

Nagraj wideo dokładnie według storyboardu w `_docs/F6S_SPARK_3_0/01_VOICEOVER_PRODUKT_60s.md`, sekcja "Sekunda po sekundzie":

| Czas | Co pokazać |
|------|-----------|
| 0:00–0:06 | Hub (`/modules/hub/index.html`) — kafelki modułów, delikatny scroll |
| 0:06–0:16 | Online (`/modules/online/index.html?tenant=2`) — storefront, menu |
| 0:16–0:26 | POS — login PIN `1111`, dodanie pozycji do koszyka |
| 0:26–0:36 | Studio Menu — drzewo kategorii + edytor pozycji (receptura/warianty) |
| 0:36–0:46 | Courses — mapa lub lista kursów |
| 0:46–0:54 | Settings — zakładka integracje/webhooks (bez kluczy API w kadrze) |
| 0:54–0:60 | Slajd końcowy z logo + `slicehub.pro` (możesz wygenerować w HTML/CSS i zrobić mu screenshot) |

**Wykonanie**:
1. Użyj **`computerUse` subagent**, żeby otworzyć Chrome i przeklikać scenariusz live.
2. Zacznij `RecordScreen mode=START_RECORDING` PRZED otwarciem pierwszego modułu.
3. Każdą sekcję 6–10 sekund (zgodnie z tabelą).
4. `RecordScreen mode=SAVE_RECORDING save_as_filename=spark_30_product_demo` po skończeniu.
5. **NIE pokazuj zbliżenia na klawiaturę przy wpisywaniu PIN** (zasłoń, użyj PIN w jednym tempie).
6. Plik finalny ma trafić do `/opt/cursor/artifacts/spark_30_product_demo.webm` (automatycznie zapis).
7. Zacytuj wideo w finalnej odpowiedzi: `<video src="/opt/cursor/artifacts/spark_30_product_demo.webm" controls></video>`.

**Jakość**: 1920×1080, sliderem nie ruszaj okna, nie miej widocznych innych kart / pasków zadań z prywatnymi rzeczami.

### Priorytet 2 — Hero screenshoty każdego modułu (6 plików PNG, 1920×1080)

Wykonaj wysokiej jakości screeny każdego z 6 modułów. Każdy ma trafić do `/opt/cursor/artifacts/`:
- `screenshot_hub.png` — Hub z kafelkami
- `screenshot_online.png` — Online storefront z menu
- `screenshot_pos.png` — POS z otwartym koszykiem
- `screenshot_studio.png` — Studio Menu z otwartym edytorem pozycji (najlepiej pizza z recepturą)
- `screenshot_courses.png` — Courses z mapą lub listą
- `screenshot_settings.png` — Settings z zakładką integracji

Zasady:
- Pełna szerokość okna, viewport 1920×1080.
- Bez DevTools, bez powiadomień, bez private info.
- Studio screen — najlepiej pokaż widok z recepturą rozwinięta (drag-and-drop reorder F-S9), żeby było widać unikalne feature.
- Każdy screen wykonaj przez `computerUse` (po zalogowaniu).

### Priorytet 3 — One-pager A4 PDF (dla inwestorów / mentorów akceleratora)

Stwórz **jednostronicowy A4 PDF** (portrait) zawierający:
- Logo + tagline „SliceHub: System operacyjny gastronomii — POS + Magazyn + Dostawy + KSeF w jednej platformie"
- Sekcję "Problem" (3 punkty: chaos narzędzi, brak omnichannel pricing, ręczne wpisywanie faktur)
- Sekcję "Rozwiązanie" (4 wyróżniki: variant scales, KSeF AutoScan, omnichannel, zero-reload runtime na shared hostingu)
- Sekcję "Trakcja / Status" (działający produkcyjnie na slicehub.net, 17 feature tagów wyprodukowanych, X modułów)
- Sekcję "Zespół" (Damian Malenta — solo founder, czerpać z `02_VOICEOVER_TEAM_60s.md`)
- CTA "slicehub.pro" + email
- 2–3 thumbnaile screenów (use Priority 2 outputs)

**Forma**: HTML + CSS print stylesheet, eksport przez Chrome `--headless --print-to-pdf`. Zapisz w `/opt/cursor/artifacts/spark_30_onepager.pdf` oraz źródłowy HTML w `_docs/F6S_SPARK_3_0/07_ONEPAGER_A4.html`.

Bazą stylistyczną może być `_docs/F6S_SPARK_3_0/06_DECK_EXPORT.html` (zachowaj spójność wizualną).

### Priorytet 4 — Strona prezentacyjna landing (refresh)

Plik `presentations/spark-30-slicehub/index.html` istnieje ale wymaga dopracowania. Zaktualizuj:
- Hero section z screenem POS (Priority 2)
- Sekcja modułów (6 kafelków, jeden pod każdy moduł, z thumbnailem ze screenów Priority 2)
- Sekcja "Liczby" — np. liczba modułów, feature tags, migracji DB
- Sekcja "Wideo demo" — embed wideo z Priority 1 (jeśli `<video src=...>` lokalne nie działa, dodaj placeholder z linkiem do YouTube)
- Footer z linkiem do GitHuba, slicehub.pro, kontaktem

Styl: Tailwind CSS (pobierz CDN), ciemny motyw glassmorphism (`bg-white/5 backdrop-blur` itd.) zgodnie z konstytucją SliceHub.

### Priorytet 5 (opcjonalnie, jeśli zostanie czas)

- **GIF-y do social media**: 3 krótkie (5–10s) GIF-y/MP4 z kluczowych interakcji (dodaj pizzę w Studio → POS od razu widzi; koszyk z modyfikatorami; mapa kursów). Zapisz jako `social_*.mp4` w artifacts.
- **Thumbnail YouTube** 1280×720 PNG: gradient + logo SliceHub + tytuł "Restaurant OS in 60 seconds". Zapisz `youtube_thumbnail.png`.

## Workflow

1. **Najpierw** przeczytaj te dokumenty żeby mieć kontekst:
   - `_docs/F6S_SPARK_3_0/01_VOICEOVER_PRODUKT_60s.md`
   - `_docs/F6S_SPARK_3_0/05_CHECKLIST_PRODUKCJI_WIDEO.md`
   - `_docs/F6S_SPARK_3_0/04_PITCH_DECK_PELNY.md` (kontekst marketingowy)
   - `wniosek.md` (kontekst wniosku)
2. **Sprawdź dostępność** slicehub.net (czy login `Damian` / `Dammalq123123` działa).
3. **Wykonaj zadania w kolejności P1 → P4**.
4. **Commit i push do `main`** wszystkie pliki HTML/PDF/MD (artifacts wideo / PNG nie commituj — są w `/opt/cursor/artifacts/`).
5. **Stwórz draft PR** z podsumowaniem materiałów.
6. **W finalnej odpowiedzi** zacytuj wszystkie artefakty: wideo, screeny, onepager PDF, link do landing page.

## Reguły jakości

- **Wszystkie demo akcje** wykonuj na **prawdziwej instancji** slicehub.net (nie na localhost / mocku).
- **PIN 1111 nie pokazuj klatka po klatce** — wpisz szybko lub zasłoń kursorem.
- **Polski język** w voiceoverze i tekstach (jest też wersja ENG w `01_VOICEOVER_PRODUKT_60s.md` na opcjonalne nagranie międzynarodowe).
- **Cytaty visualne** używaj tylko z `/opt/cursor/artifacts/` (nie zewnętrznych URL-i).
- **Markdown linki** do plików repo używaj relatywnych ścieżek od root repo (`_docs/F6S_SPARK_3_0/...`).

## Wynik końcowy oczekiwany przez user'a

Po zakończeniu Twojej pracy ja:
- Pobieram MP4 wideo i wrzucam na YouTube Unlisted, wklejam link do F6S w polu Product Video.
- Pobieram one-pager PDF i wysyłam do mentorów / akceleratora.
- Mam landing page do podrzucenia jako "Demo" obok wniosku.

**Powodzenia, lec.**
