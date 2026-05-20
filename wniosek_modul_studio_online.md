# MODUŁ ONLINE STUDIO — Analiza Techniczna i Plan Działania

> Szczegółowa dokumentacja modułu Online Studio / Director dla wniosku o dofinansowanie.  
> Audyt przeprowadzony na żywym kodzie repozytorium (**rewizja 2026-05-20**).

---

## 1. ARCHITEKTURA MODUŁU

Online Studio to **dwuczęściowy system** — panel managera (`modules/online_studio/`) i publiczna witryna klienta (`modules/online/`), połączone przez `core/SceneResolver.php` i współdzielony renderer `core/js/scene_renderer.js`.

```
MANAGER (Online Studio)                    KLIENT (Storefront)
┌─────────────────────────┐                ┌─────────────────────────┐
│ Asset Library            │                │ Scena Drzwi (doorway)   │
│ Director's Suite         │───────────────▶│ Counter / Living Table  │
│ Style Conductor          │  SceneResolver │ Dish Sheet (warstwy)    │
│ Companions               │  + AssetResolver│ Koszyk + Checkout      │
│ Surface                  │                │ Tracker (SSE)           │
│ Preview / Storefront     │                │                         │
└─────────────────────────┘                └─────────────────────────┘
```

---

## 2. CO JEST ZROBIONE (status 2026-05-20)

**Uzupełnienie od rewizji wniosku:** storefront (`/modules/online/`) obsługuje **warianty rozmiaru** (badge „Rozmiary →”, modal wyboru) — spójne z POS i `sh_variant_scales` w Studio Menu. Demo produkcyjne: pełne menu Pizza Forno (229 pozycji, seed 2026-05).

| Milestone | Zakres | Status |
|-----------|--------|--------|
| **M1 — Menu Studio Polish** | Connect-dots modyfikator→warstwa, auto-generator sceny, hero picker, miniaturki w drzewie, UX receptur (fuzzy search, stock badge, live food cost) | ✅ DONE |
| **M2.1 — Unifikacja rendererów** | Wspólny `core/js/scene_renderer.js` — SSOT matematyki warstwy (transform, filtry, blend, alpha, shadow, feather). Director i Storefront renderują 1:1 | ✅ DONE |
| **M3 — Pipeline realizmu (7 kroków)** | #1 Directional shadow, #2 LUT inheritance, #3 Wet/grease specular, #4 Auto-perspective match (6 presetów kamery), #5 Scatter presets, #6 Feather refinement, #7 Baked variants (cook_state: raw/cooked/charred) | ✅ DONE |
| **M4 — Scena Drzwi** | Inline SVG ilustracja z 4 wariantami dobowymi, modal mapy (Leaflet lazy), tryb statyczny (a11y), status open/closing/closed, pre-order CTA | ✅ DONE |
| **G1 — Category Style Engine** | Akcja `category_style_apply` — merge stylu (LUT + lighting + ambient + companions + typo) do scen kategorii | ✅ DONE |
| **G2 — Style Conductor** | Dashboard z galerią 12 stylów kinowych + tabela kategorii z Harmony Score + "Zastosuj do całego menu" jednym klikiem | ✅ DONE |
| **G4 — Harmony Score** | Client-side `HarmonyScore.js` (0–100: completeness 50 + polish 30 + consistency 20), cache w `sh_scene_metrics`, badge + modal outlierów, publikacja blokowana < 50 | ✅ DONE |
| **G5 — Magic Conform + Harmonize** | Conform = soft-blend warstw do TYPE_PRESETS + LUT_OFFSETS; Harmonize = targetuje tylko outliery z G4 | ✅ DONE |
| **G6 — Living Scene** | `atmospheric_effects_json` na storefroncie: steam, dust, condensation, sauce_drip, candle_glow, sun_rays, breath. CSS `living-scene.css` + `prefers-reduced-motion` | ✅ DONE |

---

## 3. HOLLYWOOD DIRECTOR'S SUITE — Szczegóły Implementacji

### 3.1 Siedem Workspace'ów (`ToolbarPanel.js`)

| Workspace | Funkcja |
|-----------|---------|
| **Compose** | Pozycjonowanie warstw, dodawanie, usuwanie, drag&drop na viewport |
| **Color** | LUT (8 kinowych: Neapolitan Classic, Hollywood Blockbuster, Ghibli, Wes Anderson, Synthwave, Noir, Memphis Pop, Cottagecore), brightness, saturation, hue |
| **Light** | Kierunek światła sceny, cień, winieta, grain |
| **Scenography** | Scene Kit editor — tła, props, światła, badges z szablonów (`pizza_top_down`, `static_hero`) |
| **Companions** | Auto-dobór i manualne zarządzanie dodatkami na stole |
| **Promotions** | Sloty promocyjne na scenie, badge overlay |
| **Preview** | Podgląd jak widzi klient |

### 3.2 Sześć Magic Functions

| Magic | Co robi |
|-------|---------|
| **MagicEnhance** | "THE button" — 10 kroków: profile → LUT → layout → companions → bake → light → dust → grade → letterbox → infoBlock |
| **MagicBake** | Per-ingredient auto-kalibracja (base/sauce/cheese/meat/veggie/herb presets blendMode + alpha + feather + shadow) |
| **MagicRelight** | Kierunek światła + cień + winieta + grain per profil |
| **MagicColorGrade** | Auto-LUT match przez `DishProfiler` (classic/spicy/vegetarian/meat-heavy/white/fancy) |
| **MagicDust** | Ambient: crumbs, steam, oil sheen |
| **MagicCompanions** | Auto-dobór chlebków/napojów/sosów z menu |

### 3.3 Dwanaście Stylów Kinowych (seedowane w m022)

Realistyczny, Pastelowy Watercolor, Anime Ghibli, Pixar 3D, Synthwave 80s, Film Noir B&W, Cyberpunk Blade Runner, Cottagecore, Minimal Editorial, Pop Art, Vintage 50s, Hand-drawn — każdy z `color_palette_json`, `font_family`, `motion_preset`, `default_lut`, `cinema_reference`, `ai_prompt_template`, `lora_ref`.

### 3.4 Sześć Presetów Kamery

`top_down`, `hero_three_quarter`, `macro_close`, `wide_establishing`, `dutch_angle`, `rack_focus` (DOF) — honorowane przez storefront i Director jednakowo dzięki `applyCameraPerspective` w `core/js/scene_renderer.js`.

### 3.5 Harmony Score — Numeryczny Gate Jakości

**Wzór:** `Score = completeness (0–50) + polish (0–30) + consistency (0–20)`

- **Completeness:** liczba warstw, obecność boarda, companions
- **Polish:** obecność LUT, surfaceBg, liczba companions
- **Consistency:** wariancja `cal_scale`, `cal_rotate`, `brightness`, `saturation`, `feather` + per-layer delta vs `TYPE_PRESETS`

Cache w `sh_scene_metrics` (m030). Publikacja blokowana gdy score < 50 (z overridem managera). **Żaden konkurent nie posiada numerycznego gate jakości wizualnej.**

### 3.6 Style Conductor — Zmiana Tożsamości Menu Jednym Klikiem

Dashboard z galerią 12 stylów + tabela kategorii (items, sceny, Harmony avg, aktywny styl, przycisk "Zastosuj"). Akcja `menu_style_apply` iteruje po `sh_categories.is_menu=1` i merge'uje `spec_json` każdego dania z wybranym preset'em.

**Rezultat:** 33 pozycji menu × 8 kategorii = zmiana 264 scen z "Realistyczny" na "Film Noir" w < 30 sekund.

---

## 4. SCENA DRZWI — Pierwsza Interakcja Klienta

**Lokalizacja:** `modules/online/js/online_doorway.js` + `css/doorway.css`

- Inline SVG ilustracja restauracji parametryzowana CSS variable `--doorway-accent` (kolor marki)
- 4 warianty dobowe: `morning`, `day`, `evening`, `night` (automatyczny wybór)
- Status: `open`, `closing_soon`, `closed` z dynamicznym komunikatem
- Modal mapy (Leaflet lazy-load) + widok tygodniowych godzin otwarcia
- Tryb statyczny (ikona a11y) → klasa `is-static` → wyłącza animacje, desaturuje, honoruje `prefers-reduced-motion`
- Deep-link `?skip=doors` omija drzwi
- Pre-order CTA dla zamkniętej restauracji (jeśli manager włączył)

**Backend:** akcja `get_doorway` w `api/online/engine.php` — KV settings + `opening_hours_json`.

---

## 5. LIVING SCENE — Żywe Okno do Kuchni

**Lokalizacja:** `modules/online/css/living-scene.css` + `online_renderer.js::applyAtmosphericEffects`

Efekty atmosferyczne renderowane na storefroncie jako CSS animacje:

| Efekt | Opis wizualny |
|-------|---------------|
| `steam_rising` | Para unosząca się nad gorącą pizzą |
| `dust_particles_golden` | Złote drobiny kurzu w świetle słonecznym |
| `condensation_drops` | Krople kondensacji na zimnym napoju |
| `sauce_drip` | Spływający sos na krawędzi pizzy |
| `candle_glow` | Blask świecy wieczorną porą |
| `sun_rays` | Promienie słońca na stole |
| `breath` | Oddech pary z gorącego dania |

Wszystkie efekty respektują `prefers-reduced-motion`. Backend zwraca `atmosphericEffects` w `get_menu`, `get_scene_menu`, `get_scene_category`, `get_scene_dish`.

---

## 6. PRZEPŁYW DANYCH: OD STUDIO DO KLIENTA

```
1. MANAGER → Online Studio
   │
   ├─ Asset Library: upload zdjęć składników → sh_assets + sh_asset_links
   ├─ Director: kompozycja sceny → sh_atelier_scenes.spec_json (JSON)
   │   ├─ pizza.layers[] (pozycja, skala, rotacja, blendMode, alpha, feather, shadow)
   │   ├─ stage (boardUrl, LUT, light, grain, vignette, letterbox)
   │   ├─ companions[] (typ, pozycja)
   │   ├─ ambient (dust, steam, crumbs)
   │   └─ active_camera_preset, active_lut, atmospheric_effects_json
   ├─ Style Conductor: bulk apply stylu → merge spec_json per danie
   └─ Harmony Score: cache → sh_scene_metrics (score, outliers)

2. API (SceneResolver.php)
   │
   ├─ resolveDishVisualContract():
   │   ├─ Priorytet: sh_atelier_scenes.spec_json > sh_visual_layers > hero
   │   ├─ Hero: sh_asset_links (role='hero') > sh_menu_items.image_url
   │   ├─ Modifiers: sh_asset_links (role='layer_top_down', 'modifier_hero')
   │   │   └─ cook_state bias: cooked dla layer, raw/either dla hero
   │   └─ Style cascade: scene active_style > category style > template default
   └─ AssetResolver.php: normalizacja URL (/slicehub/uploads/...)

3. KLIENT → Storefront
   │
   ├─ Drzwi (online_doorway.js):
   │   └─ SVG ilustracja → 4 warianty dobowe → kanały → godziny → mapa
   ├─ Menu (online_table.js / online_ui.js):
   │   └─ Karty z heroUrl (static <img>) + atmosphericEffects CSS
   ├─ Dish Sheet (online_renderer.js):
   │   ├─ SharedSceneRenderer.render(spec) → stackowane <div> z warstwami
   │   ├─ Modyfikatory: scatter (warstwa na pizzy) + hero (bąbelek obok)
   │   ├─ Atmospheric effects: steam, dust, candle, sun_rays (CSS animacje)
   │   └─ Camera preset: CSS perspective transform
   ├─ Checkout (online_checkout.js):
   │   └─ init_checkout (lock) → guest_checkout → tracking_token
   └─ Tracker (online_track.js):
       └─ SSE (EventSource) + polling 10s + Leaflet map (GPS delivery)
```

---

## 7. SCHEMAT BAZY DANYCH — Tabele Modułu

| Tabela | Migracja | Przeznaczenie |
|--------|----------|---------------|
| `sh_atelier_scenes` | m020 | DishSceneSpec JSON per danie + `version` (optimistic locking) + `active_camera_preset` + `active_lut` + `atmospheric_effects_json` |
| `sh_atelier_scene_history` | m020 | Audyt edycji scen (kto, kiedy, snapshot spec) |
| `sh_assets` | m021 | Unified asset library — pliki ze statusem, kategorią, `cook_state`, `display_name`, `tags_json` |
| `sh_asset_links` | m021 | Relacje asset→encja z rolami (`hero`, `layer_top_down`, `modifier_hero`, `modifier_cutout`, `companion`, `surface`) |
| `sh_scene_templates` | m022 | Szablony scenografii: `stage_preset_json`, `composition_schema_json`, `atmospheric_effects_json`, `photographer_brief_md`, `available_luts_json`, `default_style_id` |
| `sh_style_presets` | m022 | 12 stylów kinowych: `color_palette_json`, `font_family`, `motion_preset`, `default_lut`, `cinema_reference`, `ai_prompt_template`, `lora_ref` |
| `sh_category_styles` | m022 | Aktywny styl per kategoria + `applied_at`, `applied_by_user_id`, `ai_cost_zl` |
| `sh_scene_triggers` | m022 | Auto-aktywacja scen po date/time/weather |
| `sh_scene_variants` | m022 | A/B testing wariantów scen |
| `sh_ai_jobs` | m022 | Kolejka AI: `style_transform` / `background_remove` / `enhance` / `generate_variant` |
| `sh_promotions` | m022 | Promocje z aktywacją/deaktywacją |
| `sh_scene_promotion_slots` | m022 | Sloty promocyjne na scenie (pozycja, rozmiar, badge) |
| `sh_scene_metrics` | m030 | Cache Harmony Score: `harmony_score` (0–100), `outliers_json`, `variance_json` |
| `sh_visual_layers` | m012 | Legacy warstwy wizualne (przed Directorem) |
| `sh_global_assets` | m014 | Legacy globalna biblioteka assetów |
| `sh_board_companions` | m013 | Companions per danie (typ, pozycja) |
| `sh_modifiers.has_visual_impact` | m024 | Flaga "modyfikator zmienia wygląd" |

---

## 8. API ONLINE STUDIO — Kompletna Lista Akcji

### 8.1 `api/online_studio/engine.php`

| Akcja | Opis |
|-------|------|
| `whoami` | Tożsamość managera (tenant, rola) |
| `menu_list` | Lista dań + kategorie + modyfikatory + flaga `isPizza` |
| `menu_set_product_image` | Ustawienie image_url dania |
| `composer_load_dish` | Ładowanie warstw wizualnych dania (legacy `sh_visual_layers` + companions + surface) |
| `composer_save_layers` | Zapis warstw (replace-all lub merge upsert) |
| `composer_calibrate` | Kalibracja skali/rotacji warstwy |
| `composer_clone` | Klonowanie stacku warstw między daniami |
| `composer_autofit_suggest` | Auto-kalibracja na podstawie średnich z tego samego sub_type |
| `composer_auto_match_dishes` | Token match pizz do assetów + opcjonalny auto-apply |
| `companions_list` / `companions_save` | CRUD companions per danie |
| `surface_apply` | Ustawienie tła surface (storefront_surface_bg) |
| `storefront_settings_get` / `save` | Ustawienia sklepu: tagline, kontakt, mapa, godziny, kanały, preorder |
| `preview_url` | URL podglądu dla iframe |
| `director_load_scene` | Ładowanie `sh_atelier_scenes.spec_json` + wersja + kamera + LUT + efekty |
| `director_save_scene` | Zapis sceny z optimistic locking (`expectedVersion` → 409 na konflikcie) + historia |
| `scene_harmony_save` | Cache Harmony Score do `sh_scene_metrics` |
| `scene_harmony_get` | Pobranie score per danie lub `all` (dla Style Conductor) |
| `style_presets_list` | Lista 12 stylów kinowych |
| `category_style_apply` | Aplikacja stylu do kategorii (merge spec_json per danie) |
| `menu_style_apply` | Aplikacja stylu do całego menu |
| `category_styles_list` | Aktywne style per kategoria |
| `promotions_list` / `promotion_save` / `promotion_delete` | CRUD promocji |
| `scene_promotion_slots_get` / `save` | Sloty promocyjne na scenie |

### 8.2 `api/assets/engine.php` (Unified Asset Library)

| Akcja | Opis |
|-------|------|
| `list` | Paginowana lista + health flags (orphans, duplikaty, brak kategorii/cook) |
| `upload` | Upload z SHA dedup |
| `update` | Metadane + opcjonalny regenerate ascii_key |
| `soft_delete` / `restore` | Soft delete z przywróceniem |
| `link` / `unlink` | Linkowanie assetu do encji z rolą |
| `list_usage` / `list_entities` | Użycie assetu + słowniki picker |
| `bulk_update` / `bulk_soft_delete` | Operacje masowe |
| `duplicate` | Duplikacja (nowy ascii_key, ten sam plik, bez linków) |
| `merge_duplicates` | Merge duplikatów (re-point linków, soft-delete merged) |
| `scan_health` | Audit: count, duplicate groups |

---

## 9. CO POZOSTAJE DO ZROBIENIA

| Milestone | Zakres | Status | Estymata |
|-----------|--------|--------|----------|
| **M5 — Counter + Living Table** | Horyzontalny swipe między daniami, Bottom Sheet "Komponuj / Do stołu", companions persist przy swipe, live cena sliding number | ❌ TODO | 2–3 tyg |
| **M6 — Checkout path** | Drawer koszyka (slide-up), guest checkout (phone-keyed), tracker P1 | ❌ TODO | 1–2 tyg |
| **M7 — QA + polish** | Real-device testing, performance (<100ms layer add, FMP <2s, bundle <200KB gzip), dostępność, fallback static mode | ❌ TODO | 1 tyg |
| **G3 — AI Jobs Runner** | Queue `sh_ai_jobs` + worker dla style_transform / background_remove / enhance / generate_variant | ❌ ODŁOŻONE → Faza AI |
| **G7 — Magic Workshop** | Edytor per-foto z wariantami `sh_assets.variant_of` + `corrections_json` | ❌ ODŁOŻONE → Faza AI |
| **M2.2 — Full Scene w core/** | Przeniesienie pełnej klasy SceneRenderer (stage + companions + LUT) do `core/js/` gdy storefront będzie chciał "full scene view" | ❌ ODŁOŻONE |

---

## 10. ROADMAPA FAZ

| Faza | Zakres | Status |
|------|--------|--------|
| **Faza 2 (B — Counter + Drzwi)** | M5 + M6 + M7 — **obecna faza** | W toku (M1–M4, G1–G6 done) |
| **Faza 3 (C — Viewfinder)** | 4 kierunki swipe (Menu ↑, Kuchnia ←, Sala →, Koszyk ↓), TOD automatyka, gamifikacja, pełny preorder, Admin Hub | Planowana |
| **Faza AI** | G3 (AI Jobs Runner) + G7 (Magic Workshop), integracja Replicate/Flux | Planowana |
| **Faza 5+ (A — Pełen Film)** | 5-scenowy film (Drzwi → Lada → Sala → Koszyk → Potwierdzenie), kompletne Director's Suite (Timeline, TOD Dial, Hotspot Editor, Version History, Template Browser, Publish Pipeline, Analytics Overlay), Film Template Library, AI illustration | Wizja długoterminowa |

---

## 11. ZNANE PROBLEMY TECHNICZNE (z audytu kodu)

| Problem | Opis | Priorytet |
|---------|------|-----------|
| **Fallback `composer_load_dish` → Director** | PHP zwraca `visualLayers`, Director szuka `data.layers` — fallback z legacy `sh_visual_layers` do nowego Directora nigdy nie importuje warstw | Średni |
| **Brakujące metody w DirectorApp** | `HierarchyPanel` i `InspectorPanel` wywołują `moveLayerUp`, `moveLayerDown`, `reorderLayers`, `duplicateLayer`, `duplicateCompanion` — te metody nie istnieją na prototypie `DirectorApp`. Hierarchy drag-reorder i quick actions z-order są niedziałające | Wysoki |
| **`get_scene_menu` vs `scene_meta`** | `batchResolveForCategory` zwraca `has_scene`, `active_style` na top-level, ale enrichment szuka `$it['scene_meta']` — `activeStyle` na kartach The Table jest prawdopodobnie zawsze pusty | Średni |
| **Storefront tab w Studio** | Tab `storefront` nie jest w `tabOrder` ani `validTabs` — brak shortcutu klawiszowego i deep-linku | Niski |

---

## 12. SUCCESS CRITERIA FAZY 2

### Techniczne
- WYSIWYG w Directorze = 1:1 z tym, co widzi klient (unified renderer)
- Layer compositing na iPhone 11 / Samsung A52 / średni Android z 2022 bez lagów (< 100ms przy dodaniu warstwy)
- First Meaningful Paint < 2s na 4G
- Bundle JS online < 200KB gzipped

### UX
- Klient skomponuje pizzę i złoży zamówienie w mniej niż 60 sekund
- Manager wrzuci nową pizzę do menu (z warstwami) w mniej niż 5 minut
- Tryb statyczny działa na każdym urządzeniu z przeglądarką z ostatnich 5 lat

### Biznesowe
- Konwersja mobile ≥ 2.5% (benchmark 1.8–2.8%)
- Cart abandonment < 65% (benchmark 70%)

---

## 13. PODSUMOWANIE

**Stan: ~70% gotowe.** Cały backend scen, Director z 7 workspace'ami i 6 Magic Functions, pipeline realizmu (7 kroków), unifikacja rendererów, Scena Drzwi, Style Conductor z 12 stylami, Harmony Score, Living Scene na storefroncie — wszystko to jest zaimplementowane i działa w kodzie.

**Pozostaje M5 (Counter/Living Table), M6 (Checkout), M7 (QA)** — czyli front-end storefront z nowym UX zakupowym. Backend (`CartEngine`, `SceneResolver`, checkout flow) jest już gotowy i przetestowany.

**Kluczowa innowacja:** System, który pozwala managerowi restauracji zmienić całą tożsamość wizualną 33 dań w 8 kategoriach z "Realistyczny" na "Film Noir" jednym kliknięciem (Style Conductor), z automatycznym gate jakości (Harmony Score ≥ 50 do publikacji) i Living Scene (para nad pizzą, świece wieczorem) na storefroncie — to nie istnieje w żadnym konkurencyjnym systemie POS/online ordering na rynku.

---

*Dokument wygenerowany na podstawie audytu żywego kodu repozytorium SliceHub Enterprise OS oraz dokumentacji planistycznej w `_docs/15_KIERUNEK_ONLINE.md` i `_docs/00_PAMIEC_SYSTEMU.md`.*
