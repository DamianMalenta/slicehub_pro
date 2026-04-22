# 15. KIERUNEK ONLINE — Decyzja Fazy 2

> **Status:** Zatwierdzony przez Damiana, 2026-04-18.
> **Poprzedzające:** `ARCHIWUM/06_WIZJA_MODULU_ONLINE.md` (szersza wizja historyczna), audyt kodu 2026-04-18, canvas `slicehub-three-directions`.
> **Ten plik jest autorytatywny dla kierunku Online/Studio.** Wszystko, co nie zgadza się z nim w starszych dokumentach, jest nieaktualne — ten plik wygrywa.

---

## 0. DECYZJA W JEDNYM ZDANIU

> **Budujemy drogę B: Realistyczny Counter + Drzwi.** Dwie sceny (Drzwi jako wejście + Counter z Living Table i warstwami), leveragując 70% już zbudowanego Directora, z polerem Menu Studio przed startem. MVP w 6–8 tygodniach.

Droga C (Restaurant Viewfinder) jest naturalną nadbudową w Fazie 3, po weryfikacji B na realnych klientach. Droga A (pełen 5-scenowy Film) jest odłożona do Fazy 5+.

---

## 1. CO WCHODZI DO MVP FAZY 2 (B — Counter + Drzwi)

### 1.1 Scena 1: DRZWI (hero entry)

- Pierwszy widok po otwarciu storefrontu — duże zdjęcie/ilustracja drzwi restauracji.
- Zawiera: nazwa, logo, godziny otwarcia, kanały (Delivery/Takeaway), numer telefonu, adres, mini-mapa (modal).
- Mikroanimacja „otwierania drzwi" (opcjonalna, wywołana kliknięciem/swipe).
- Przycisk **„Przejdź do menu"** + przełącznik **„Tryb statyczny"** (fallback do prostego layoutu dla starych urządzeń).
- Zamknięta restauracja: widok nocny + opcja pre-order (jeśli manager włączył) lub informacja o najbliższych godzinach otwarcia.

### 1.2 Scena 2: COUNTER (Living Table)

- Horyzontalny swipe między daniami (leverage istniejącego scroll-snap w `online_table.js`).
- Każde danie w centrum = pizza z warstwami (stackowane `<img>` z kalibracją per warstwa).
- Pod spodem (mobile) / po prawej (desktop) — **Bottom Sheet „Komponuj / Do stołu"**.
- Companions (frytki, sosy, coca) persist między swipe → pizza się zmienia, dodatki na stole zostają.
- Cena aktualizuje się live (sliding number).
- Przycisk **„Do koszyka"** → drawer koszyka (slide-up bottom sheet).
- Checkout: guest (phone-keyed, zgodnie z `sh_orders.customer_phone`), Apple Pay / Google Pay jako P1.

### 1.3 Co dzieje się po złożeniu zamówienia

- Potwierdzenie (prosty screen — nie pełna scena filmowa).
- Link do trackera (P1 — polling `api/online/engine.php?action=track_order`).
- SMS notifications (P2, po integracji Twilio/Plivo).

---

## 2. CO JEST PREREKWIZYTEM (NIE POMIJAMY)

### 2.1 Menu Studio Polish (1–2 tygodnie, przed Online) ✅ DONE 2026-04-19

- **Intuicyjny connect-dots modyfikator → warstwa wizualna.** ✅ Dzisiaj manager musi ręcznie wrzucić asset, iść do modyfikatora, wkleić link. Redukujemy do jednego kliknięcia „Przypisz warstwę" z live-preview.
- **Auto-generator domyślnej kompozycji pizzy z modyfikatorów `action_type = NONE`.** ✅ System sam składa miniaturę z warstw domyślnych składników. Manager nie zaczyna od pustego płótna.
- **Podgląd miniaturki dania w Studio** ✅ — widać finalny efekt bez wchodzenia w Director.
- **UX recept (BOM)** ✅ — uproszczenie dzisiejszego flow, żeby manager łatwo łączył danie → składniki → magazyn.

**Nie ruszamy schematu DB** — architektura (`sh_asset_links`, `has_visual_impact`, `sh_menu_items`, `sh_modifier_groups`, `sh_modifiers`) już wspiera to, co potrzebujemy. Pracujemy nad UI i auto-generatorami.

**Zaimplementowane zmiany (M1 · 2026-04-19):**

| Sub-task | Pliki zmodyfikowane | Nowy endpoint |
|----------|--------------------|---------------|
| Connect-dots + picker + live preview + badge | `modules/studio/js/studio_modifiers.js` | — (reuse `list_assets_compact`, `save_modifier_group`) |
| Auto-generator default composition | `api/backoffice/api_menu_studio.php`, `modules/studio/js/studio_item.js` | **`autogenerate_scene`** (upsert do `sh_atelier_scenes.spec_json.pizza.layers[]` + snapshot do `sh_atelier_scene_history`) — zwraca `data.reason='no_source_data'` + `steps[]` gdy brak materiału |
| **Hero picker (dish ↔ asset)** — dopełnienie po testach UX | `api/backoffice/api_menu_studio.php`, `modules/studio/js/studio_item.js` | **`set_item_hero`** + **`unlink_item_hero`** (link w `sh_asset_links` entity_type='menu_item' role='hero', idempotentny upsert z soft-unlink poprzedniego) |
| Miniatura dania w drzewie Studio | `modules/studio/js/studio_ui.js` | — (reuse `get_menu_tree` + `AssetResolver::injectHeros`) |
| UX recept: fuzzy search + stock badge + bulk add + live food cost + save button | `modules/studio/index.html`, `modules/studio/js/studio_recipe.js` | — (reuse `get_recipes_init`, `get_item_recipe`, `save_recipe`, `WarehouseApi.stockList`) |

**Konstytucja respektowana:**
- Prawo II (Bliźniak Cyfrowy): stock badge per-ingredient (`wh_stock.quantity`, `current_avco_price`), live Food Cost total, waste%, ubytek.
- Prawo III (Zero-Reload SPA): czyste Vanilla JS, zero nowych dependencji, żadnych reloadów.
- Prawo VI (Snajper): 5 plików dotkniętych (1 PHP + 3 JS + 1 HTML), wszystkie nowe akcje dopisane w naturalnym miejscu między `save_modifier_visual` a Category Table; picker reuse `ModifierInspector.loadCompactAssets` + `list_assets_compact`.
- Zero zmian schematu DB — działa na istniejących migracjach m020/m021/m022/m024.

### 2.2 Unifikacja rendererów ✅ DONE 2026-04-19 (M2.1)

- **Problem (przed M2):** Director używał własnego `SceneRenderer.js`, Storefront własnego `online_renderer.js`. Każdy liczył matematykę warstwy (transform, filtry, maski, blendMode, alpha, shadow, feather) osobno. Ryzyko dryfu między WYSIWYG a faktycznym renderem klienta.
- **Rozwiązanie (M2.1):** Wydzielony wspólny moduł `core/js/scene_renderer.js` (ES6) z SSOT dla warstwy:
  - `resolveAssetUrl(url)` — jedna konwencja URL (absolute / `/slicehub/` / relative)
  - `sortLayers(layers)` — stabilne sortowanie po `zIndex`
  - `buildLayerElement(L, opts)` — zbudowanie jednego `<div>` warstwy (wrap + inner + img), obsługuje cały zakres atrybutów
  - `renderLayersInto(mount, layers, opts)` — mountuje stack warstw z kompletnym state
  - `CLASS_PACKS` — predefiniowane packi klas (`storefront` — klasy `pizza-*`, tryb `cssVars`; `director` — klasy `sr-*`, tryb `inline`; `studio` — klasy `st-*`, tryb `cssVars`)
- **Storefront teraz pełnoprawnie honoruje atrybuty z Directora:** `offsetX/Y` (poprzez CSS var `--cal-offset-x/y` z `0%` default — backward-compat), `blendMode`, `alpha`, `brightness`, `saturation`, `hueRotate`, `shadowStrength`, `feather`. Dotychczas rozumiał tylko `calScale` + `calRotate`.
- **Director zachowuje swoją klasę `SceneRenderer`** jako kompozytor pełnej sceny (stage, lighting, vignette, grain, letterbox, companions, infoBlock, ambient, promotion badges, modGrid, selection handles) — ale `_renderPizza` teraz woła wspólny `renderLayersInto`.
- **M2.2 (odroczone):** przeniesienie pełnej klasy `SceneRenderer` (scena) do `core/js/` gdy Storefront zechce „full scene view" z companions i LUT. Dziś Storefront renderuje tylko disk, więc zostawiamy kompozycję sceny w Directorze.
- **Efekt:** co manager skonfiguruje w Directorze, klient zobaczy 1:1 na Storefroncie. Zero niespodzianek przy publikacji.
- **Zero zmian DB, zero backendu, dotknięte 4 pliki:** nowy `core/js/scene_renderer.js`, rewrite `modules/online/js/online_renderer.js`, patch `modules/online_studio/js/director/renderer/SceneRenderer.js`, rozszerzenie `.layer-visual` w `modules/online/css/style.css`.

### 2.3 7-stopniowy pipeline realizmu (2–3 tygodnie, w czasie budowy Counter)

Obecny stan vs. docelowy — patrz canvas. Brakuje dobudowania:

1. **Directional contact shadow** — cień pod warstwą zgodny z kierunkiem światła sceny (nie prosty drop-shadow). → część **G6** (Living Scene wymaga spójnego oświetlenia na foto i ambient) + **G5** (Magic Conform jako pass per-warstwa).
2. **Scene-wide LUT inheritance** — każda warstwa przechodzi przez LUT sceny, nie ma własnego koloru. → **G1** (Category Style Engine) + **G5** (Magic Harmonize weryfikuje dziedziczenie LUT po zastosowaniu stylu).
3. **Wet/grease specular pass** — opcjonalna warstwa połysku dla składników soczystych. → **G6** (atmospheric_effects_json + Living Scene CSS pass).
4. ✅ **Auto-perspective match — DONE (2026-04-19)** — system dopasuje perspektywę warstwy do kąta kamery sceny. `CAMERA_PRESETS` + `applyCameraPerspective(viewport, disk, preset)` w `core/js/scene_renderer.js`. 6 presetów: `top_down`, `hero_three_quarter`, `macro_close`, `wide_establishing`, `dutch_angle`, `rack_focus` (DOF). Honorują wszyscy konsumenci: storefront (`online_renderer.mountPizzaScene`, `online_ui.mountTilePizzaScene`, `online_ui.refreshPizzaScene`), Director (`SceneRenderer.render`, `ViewportPanel.refresh`) + persist (`sh_atelier_scenes.active_camera_preset`, load/save actions). UI: dropdown kamery w `ToolbarPanel` Directora, zapisywany razem z `director_save_scene`.
5. **Scatter presets ulepszone** — seeded rozkład z wariancją rotacji/skali per składnik. → już w `MagicBake.js` (PRESETS per typ), doszlifowanie w **G5**.
6. **Feather mask refinement** — istniejący radial mask dopieszczony. → **G4** (Harmony Score wychwyci outliery feather) + **G5** (Magic Conform koryguje).
7. ✅ **Baked variants — DONE (2026-04-19)** — składniki mają warianty `raw`/`cooked`/`charred` (dla widoku top-down pizzy zawsze preferujemy `cooked`, dla hero `raw`/`either`). Migracja **m031** dodaje `sh_assets.cook_state` ENUM(`either`,`raw`,`cooked`,`charred`) + index. `SceneResolver::resolveModifierVisuals` — UNION ALL z bias'em w `ORDER BY` (`cooked` na layer_top_down, `raw`/`either` na modifier_hero). Asset Studio — dropdown „Stan pieczenia" w wizardzie + edytorze + badge `chip--cook-*` na gridzie. Backend `api/assets/engine.php` — stała `AE_COOK_STATES` + obsługa w `list`/`upload`/`update`.

**Wniosek:** Po domknięciu M3 #4 i #7 (2026-04-19) cały **7-stopniowy pipeline realizmu jest ZAMKNIĘTY** (5 punktów weszło w G1–G6, 2 zostały dokończone jako osobny mikromilestone).

---

### 2.4 Studio Evolution — Plan G1-G7 (zatwierdzony · 2026-04-19)

> **Geneza:** po audycie 2026-04-19 okazało się, że **~80% fundamentu** pod „cinematic studio" **już istnieje** — migracja m022 (12 seedowanych stylów Hollywood, Scene Templates, Category Styles, Scene Triggers, AI Jobs queue) + Hollywood Director's Suite w `modules/online_studio/js/director/` (DirectorApp, 7 workspace'ów, MagicEnhance „THE button", MagicBake/Relight/ColorGrade/Dust/Companions, LutLibrary 8 LUT-ów, 7 ScenePresets, DishProfiler, ScenographyPanel z Scene Kit editorem, HistoryStack undo/redo). Zamiast budować od zera — domykamy 6–7 konkretnych luk.

**Zasada przewodnia (→ Prawo VII w `00_PAMIEC_SYSTEMU.md`):** „Innowacja albo nic". Każdy nowy przycisk / silnik / metryka musi być o krok przed Domino's / NUV / EZ Pizza / Papa John's / WooFood / Apprication. Jeśli opisuje się jednym słowem typu „filtr", „slider", „picker" — to jest paint, nie piszemy. Jeśli zacznę się osuwać, user zatrzymuje słowem `paint`.

#### 2.4.1 Foundation (JUŻ JEST — nie dotykamy)

**DB (m020–m022, wdrożone):**

| Migracja | Co daje |
|----------|---------|
| m020 | `sh_atelier_scenes` — DishSceneSpec JSON per danie + `sh_atelier_scene_history` (audyt edycji) |
| m021 | `sh_assets` + `sh_asset_links` — unified asset library z rolami (`hero`, `layer_top_down`, `modifier_hero`, `modifier_cutout`, `companion`, `surface`) |
| m022 | `sh_scene_templates` (biblioteka scenografii: `stage_preset_json`, `composition_schema_json`, `scene_kit_assets_json`, `atmospheric_effects_json`, `photographer_brief_md`, `available_luts_json`, `default_style_id`), **`sh_style_presets`** (12 seedowanych stylów kinowych: Realistyczny / Pastelowy Watercolor / Anime Ghibli / Pixar 3D / Synthwave 80s / Film Noir B&W / Cyberpunk Blade Runner / Cottagecore / Minimal Editorial / Pop Art / Vintage 50s / Hand-drawn — każdy z `color_palette_json`, `font_family`, `motion_preset`, `default_lut`, `cinema_reference`, `ai_prompt_template`, `lora_ref`), `sh_category_styles` (aktywny styl per kategoria + `applied_at`, `applied_by_user_id`, `ai_cost_zl`), `sh_scene_triggers` (auto-aktywacja po date/time/weather), `sh_scene_variants` (A/B), **`sh_ai_jobs`** (kolejka: `style_transform` / `background_remove` / `enhance` / `generate_variant`), `sh_promotions` + `sh_scene_promotion_slots` |
| m023 | seed 2 scene templates (`pizza_top_down`, `static_hero`) z pełnym photographer brief + default pipeline |
| m024 | `sh_modifiers.has_visual_impact` (flaga „modyfikator zmienia wygląd") |

**Code (Hollywood Director's Suite — wdrożone):**

- `modules/online_studio/js/director/DirectorApp.js` — orchestrator + mount + autosave
- **7 workspace'ów** (`panels/ToolbarPanel.js`): Compose · Color · Light · Scenography · Companions · Promotions · Preview
- `panels/ScenographyPanel.js` — edytor Scene Kit (backgrounds / props / lights / badges) z modalem „Edytuj kit"
- **Magic Functions** (`magic/`):
  - `MagicEnhance.js` — **THE button**, 10-kroków: profile → LUT → pizza position → companions → bake (per-layer) → light → dust → grade → letterbox → infoBlock
  - `MagicBake.js` — per-ingredient-type auto-kalibracja (base / sauce / cheese / meat / veggie / herb presets blendMode + alpha + feather + shadow)
  - `MagicRelight.js` — kierunek światła, cień, winieta, grain per profile
  - `MagicColorGrade.js` — auto-LUT match przez `DishProfiler`
  - `MagicDust.js` — crumbs, steam, oil sheen (ambient)
  - `MagicCompanions.js` — auto-dobór chlebków / napojów / sosów
- `lib/LutLibrary.js` — 8 cinematic LUT-ów (Neapolitan Classic, Hollywood Blockbuster, Ghibli, Wes Anderson, Synthwave, Noir, Memphis Pop, Cottagecore)
- `lib/ScenePresets.js` — 7 layout presets (bottom-left-bleed, top-right-drama, centered-hero, magazine-editorial, festival-split, minimal-zen, split-banner)
- `lib/DishProfiler.js` — heurystyka profilu (classic / spicy / vegetarian / meat-heavy / white / fancy) z nazwy + opisu + składników
- `state/SceneStore.js` + `state/HistoryStack.js` + `state/SelectionManager.js` — undo/redo, multi-select, autosave

**Core (PHP):**
- `core/SceneResolver.php` — `resolveDishVisualContract`, `resolveCategoryScene`, `resolveCategoryStyle`, `checkActiveTrigger` (już produkcyjne)

**SSOT frontendu warstwy (M2.1):**
- `core/js/scene_renderer.js` — shared matematyka warstwy (Director + Storefront)

#### 2.4.2 Realne luki (TBD)

| ID | Luka | Implementacja | Status |
|----|------|---------------|--------|
| **G1** | Category Style Engine runner | Backend: akcja `category_style_apply(category_id, style_preset_id, options)` w `api/online_studio/engine.php` — iteruje po daniach kategorii, merge'uje `spec_json` (LUT + lighting + companions + ambient + typo), loguje do `sh_category_styles` (`applied_at`, `applied_by_user_id`, `ai_cost_zl`=0 dla lokalnych transformacji). Frontend: zakładka **Style Conductor** (w miejsce przycisku w `ScenographyPanel` — lepsza UX dyrygenta niż pojedynczy przycisk). **Używa istniejących stylów i istniejącego MagicEnhance — nie buduje niczego od nowa.** | ✅ **DONE 2026-04-19** |
| **G2** | Bulk ops na całe menu + Style Conductor | Backend: akcja `menu_style_apply(style_preset_id)` — iteruje po `sh_categories.is_menu=1`. Frontend: **Style Conductor dashboard** (`modules/online_studio/js/tabs/conductor.js` + `css/conductor.css`) — galeria 12 stylów z m022 + tabela kategorii (items, sceny, Harmony avg, aktywny styl, `[Zastosuj]`) + „Zastosuj do całego menu". Innowacja: nie tylko zmienia LUT, zmienia CAŁĄ tożsamość wizualną menu jednym klikiem. | ✅ **DONE 2026-04-19** |
| **G3** | AI Jobs Runner (`sh_ai_jobs`) | Queue + worker dla `style_transform` / `background_remove` / `enhance` / `generate_variant` z integracją Replicate / Flux / inną. | **ODŁOŻONE → Faza AI** |
| **G4** | Scene Harmony Score | Client-side `HarmonyScore.js` — liczy wariancję `cal_scale`, `cal_rotate`, `brightness`, `saturation`, `feather` + per-layer deltę vs. `TYPE_PRESETS` (z MagicBake). Cache w `sh_scene_metrics` (m030). UI: badge w status-barze Directora (tier: kino/ok/warn/block) + modal outlierów z akcjami. Publikacja blokowana gdy score < 50 (z overridem managera). **Innowacja: numeryczne gate jakości, którego nie ma żaden konkurent.** | ✅ **DONE 2026-04-19** |
| **G5** | Magic Conform + Magic Harmonize | **Magic Conform** (`MagicConform.js`) = soft-blend wszystkich warstw do `TYPE_PRESETS` + `LUT_OFFSETS` sceny (zachowując intencjonalne transformacje managera). **Magic Harmonize** (`MagicHarmonize.js`) = targetuje **tylko outliery** z G4 i dociąga do mediany sąsiadów tego samego typu. Oba wpięte w `ToolbarPanel` + palette `DirectorApp._runMagic`. | ✅ **DONE 2026-04-19** |
| **G6** | Living Scene na Storefroncie | `atmospheric_effects_json` honorowany w storefroncie (`modules/online/css/living-scene.css` + `applyAtmosphericEffects` w `online_renderer.js`). Renderuje: `steam_rising`, `dust_particles_golden`, `condensation_drops`, `sauce_drip`, `candle_glow`, `sun_rays`, `breath`. Respektuje `prefers-reduced-motion`. `api/online/engine.php` zwraca `atmosphericEffects` w `get_menu`, `get_scene_menu`, `get_scene_category`; `get_scene_dish` via `sceneContract.scene_meta.atmospheric_effects`. **Klient widzi ŻYWE okno do kuchni, nie product grid.** | ✅ **DONE 2026-04-19** |
| **G7** | Magic Workshop (per-foto) | Edytor pojedynczego assetu z zapisem jako wariant w `sh_assets.variant_of` + `corrections_json`. Korekty bez AI najpierw (filtry kalibrowane numerycznie), AI-enhance (`sh_ai_jobs`) dopiero po G3. | **ODŁOŻONE razem z G3** |

#### 2.4.3 Migracja m030 (jedyna nowa, minimalna)

```sql
-- database/migrations/030_scene_harmony_cache.sql
CREATE TABLE IF NOT EXISTS sh_scene_metrics (
  scene_id            INT              NOT NULL,
  tenant_id           INT              NOT NULL,
  harmony_score       TINYINT UNSIGNED NOT NULL DEFAULT 0  COMMENT '0-100',
  layer_count         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  outliers_json       JSON             NULL  COMMENT 'lista layerId z outlinem jakości',
  variance_json       JSON             NULL  COMMENT 'breakdown wariancji per metric',
  last_computed_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (scene_id),
  INDEX idx_tenant_score (tenant_id, harmony_score)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Opcjonalnie (tylko jeśli G7 idzie przed Fazą AI — decyzja usera):
-- ALTER TABLE sh_assets
--   ADD COLUMN variant_of       BIGINT UNSIGNED NULL AFTER id,
--   ADD COLUMN corrections_json JSON             NULL AFTER variant_of,
--   ADD INDEX idx_variant_of (variant_of);
```

**Zero innych migracji.** Wszystkie G1/G2/G4/G5/G6 korzystają z już istniejących tabel m020–m024.

#### 2.4.4 Kolejność implementacji — do decyzji usera

- **Opcja A — G1 + G2 najpierw** (max wow-factor dla managera): „12 stylów × 8 kategorii × 33 pozycje menu = 3168 permutacji, jeden klik i całe menu wygląda jak Ghibli / Noir / Synthwave". Najlepszy content do marketingu. Ryzyko: jakość bez G4/G5 bywa nierówna.
- **Opcja B — G4 + G5 najpierw** (max wartość techniczna): fundament pod każdą inną funkcję (Harmony Score → gate publikacji, Magic Conform → konsystencja). Bez tego G1/G2 produkują wizualnie nierówne menu.
- **Opcja C — G6 najpierw** (max wow-factor dla klienta): klient widzi parę nad pizzą, świece wieczorem, kurz w słońcu — „to nigdy nie było w żadnym systemie online". Ryzyko: bez G1/G2 scena jest „sama". Ale Living Scene działa na istniejących scenach.

**Zalecenie AI (niewiążące):** **B → A → C**. Najpierw jakościowy fundament (G4/G5), potem skala (G1/G2 z Harmony Score jako gate), na końcu biżuteria (G6 jako finisz kinowy).

#### 2.4.5 Success criteria Planu G

- **G1:** Manager aplikuje styl „Synthwave" do kategorii „Pizze Klasyczne" (8 dań) < 10 s. Każde danie dostaje LUT + ambient + companions + typo z preset'u. Wynik 1:1 z preview.
- **G2:** Manager zamienia całe menu (33 pozycji × 8 kategorii) na „Film Noir" < 30 s. Style Conductor pokazuje postęp + Harmony Score per kategoria na żywo.
- **G4:** Każda scena ma Harmony Score < 100ms po otwarciu. Outliery zaznaczone czerwonym outline w Viewport. Publikacja blocked gdy < 50.
- **G5:** Magic Conform ściąga outlier do mediany < 200 ms (animowany transition). Magic Harmonize poprawia całą scenę w jednym kroku z podglądem przed/po.
- **G6:** Storefront honoruje `atmospheric_effects_json` na mobile bez spadku FPS < 60. Pora dnia zmienia ambient automatycznie.

---

## 3. CO ODKŁADAMY (świadomie)

### 3.1 Do Fazy 3 (po weryfikacji B)

- **Restaurant Viewfinder (droga C)** — rozszerzenie Counter o 4 kierunki swipe (Menu ↑, Kuchnia ←, Sala →, Koszyk ↓, Drzwi przed).
- **Scena Sali** — lista gości, komentarze, integracja Google Reviews.
- **Gamifikacja klienta** — Slice Collection, Daily Challenges, Guest Wall.
- **Time-of-Day automatyka** — zmiana LUT + ambient według pory dnia.
- **Closed / Preorder mode** — w pełnym wydaniu z harmonogramem i wyjątkami świątecznymi.
- **Magic Wand styles per kategoria** — zamiana LUT + ambient preset zależnie od wybranej kategorii.

### 3.2 Do Fazy 5+ (pełen Film)

- **5-scenowy Film** (Drzwi → Lada → Sala → Koszyk → Potwierdzenie) z kompletną reżyserią.
- **Kompletne Director's Suite** (Timeline, TOD Dial, Hotspot Editor, Version History, Template Browser, Publish Pipeline, Analytics Overlay).
- **Film Template Library** (Pizza Film + Burger Film + Sushi Film + warianty).
- **AI illustration mode** — opcjonalna alternatywa dla fotografii.

### 3.3 Do Fazy 3+ (osobna sprawa)

- **`modules/admin_hub/`** — unified super-admin dashboard. Obecnie każdy moduł (POS, KDS, Courses, Warehouse, Settings, Tables, Studio, Online Studio) to silos. Manager potrzebuje jednego punktu wejścia. To NIE blokuje Fazy 2.

---

## 4. ZASADY I OGRANICZENIA

### 4.1 Co ZAWSZE zostaje

- **Drzwi jako pierwszy ekran.** Niezależnie od tego, dokąd pójdziemy dalej (C, A).
- **Warstwy jako system kompozycji** — `sh_asset_links` + `SceneRenderer` + `has_visual_impact`. To jest moat technologiczny.
- **Living Table** jako metafora — danie + companions persist przy swipe.
- **Tryb statyczny** jako fallback dla starych urządzeń i użytkowników preferujących prosty UX.

### 4.2 Technologia (manifest „Zero-Reload SPA" zachowany)

- **Vanilla JS (ES6+).** Zero Reacta, zero Vue, zero frameworków.
- **CSS-based compositing** (stacked `<img>` + blend modes + filters). Bez WebGL w Fazie 2.
- **Mobile-first.** Desktop jako drugi priorytet.
- **No backend rewrite.** `api/online/engine.php`, `CartEngine`, `SceneResolver` zostają jakie są.

### 4.3 Fotografia składników

- **Teraz pracujemy na testowych assetach** (to, co już jest w `sh_assets` + `uploads/`).
- **Shot-lista dla sesji zdjęciowej** zostanie rozpisana później, gdy będziemy przechodzić na produkcyjne materiały. Plik docelowy: `_docs/16_SHOT_LIST_SKLADNIKI.md` (do zrobienia).

---

## 5. KAMIENIE MILOWE (draft, do dopracowania przed kodem)

| Milestone | Zakres | Estymata |
|-----------|--------|----------|
| **M1 — Menu Studio Polish** | Connect-dots, auto-miniatura, UX recept | 1–2 tyg |
| **M2 — Unifikacja rendererów** | Wydzielenie SceneRenderer jako wspólny moduł | 1–2 tyg (może równolegle z M1) |
| ✅ **M3 — Pipeline realizmu** | Kroki 1–7 z sekcji 2.3 (wszystkie domknięte · 2026-04-19) | DONE |
| ✅ **M4 — Scena Drzwi** | Hero entry (ilustracja SVG) + modal mapy (Leaflet lazy) + tryb statyczny + zamknięta restauracja z pre-order (DONE · 2026-04-19) | DONE |
| **M5 — Counter + Living Table** | Swipe między daniami, Bottom Sheet Komponuj/Do stołu, companions persist, live price | 2–3 tyg |
| **M6 — Checkout path** | Drawer koszyka, guest checkout, phone-keyed orders, tracker P1 | 1–2 tyg |
| **M7 — QA + polish** | Real-device testing, performance, dostępność, fallback static mode | 1 tyg |

**Razem:** 9–15 tygodni worst-case, 7–10 tygodni przy dobrej równoległości. Trzymamy cel 6–8 tygodni dla MVP (M4–M6) + prerekwizyty M1–M3.

---

## 6. SUCCESS CRITERIA (co musi się zgadzać po Fazie 2)

- **Techniczne:**
  - WYSIWYG w Directorze = 1:1 z tym, co widzi klient (unified renderer).
  - Layer compositing działa na iPhone 11 / Samsung A52 / średni Android z 2022 bez lagów (< 100ms przy dodaniu warstwy).
  - First Meaningful Paint < 2s na 4G.
  - Bundle JS online < 200KB gzipped.
- **UX:**
  - Klient skomponuje pizzę i złoży zamówienie w mniej niż 60 sekund.
  - Manager wrzuci nową pizzę do menu (z warstwami) w mniej niż 5 minut.
  - Tryb statyczny działa na każdym urządzeniu z przeglądarką z ostatnich 5 lat.
- **Biznesowe:**
  - Konwersja mobile ≥ 2.5% (benchmark 1.8–2.8% wg `_docs/ARCHIWUM/06_WIZJA_MODULU_ONLINE.md`).
  - Cart abandonment < 65% (benchmark 70%).

---

## 7. OPEN QUESTIONS (do rozstrzygnięcia zanim dotkniemy kodu)

1. ~~**Menu Studio Polish — dokładny zakres:** lista zmian UI, priorytet connect-dots vs. auto-generator vs. podgląd vs. UX recept. Kolejność?~~ ✅ **ROZSTRZYGNIĘTE 2026-04-19:** Data-Layer First (A). Wykonane w kolejności: (1) Connect-dots · picker + live preview + badge → (2) Auto-generator `sh_atelier_scenes.spec_json` → (3) Miniatura w drzewie Studio → (4) UX recept — pełen overhaul (fuzzy search, stock badge, bulk add, live Food Cost, save button). Patrz § 2.1.
2. ~~**Unified renderer — lokalizacja modułu:** `core/js/scene_renderer.js` czy `modules/shared/scene_renderer.js` czy zupełnie osobny pakiet?~~ ✅ **ROZSTRZYGNIĘTE 2026-04-19:** `core/js/scene_renderer.js` — spójne z `api_client.js`, `core_validator.js`, `neon_pizza_engine.js`. Moduł ES6, eksportuje `resolveAssetUrl`, `sortLayers`, `buildLayerElement`, `renderLayersInto`, `CLASS_PACKS`. Patrz § 2.2.
3. ~~**Plan G1–G7 — kolejność implementacji:** A / B / C?~~ ✅ **ROZSTRZYGNIĘTE 2026-04-19:** wykonano **B → A → C** w jednej sesji — G4+G5 (fundament jakości), potem G1+G2 (Style Conductor), na końcu G6 (Living Scene). G3 + G7 odłożone do Fazy AI.
4. ~~**Migracja m030 — zakres:**~~ ✅ **ROZSTRZYGNIĘTE 2026-04-19:** wyłącznie `sh_scene_metrics` (minimalny zakres). `sh_assets.variant_of` + `corrections_json` czekają na Fazę AI razem z G7.
5. ~~**Drzwi — ilustracja czy zdjęcie?**~~ ✅ **ROZSTRZYGNIĘTE 2026-04-19:** **ilustracja SVG** (spójność wizualna niezależna od jakości zdjęć tenanta, parametryzowana CSS var `--doorway-accent` kolorem marki, 4 warianty dobowe `data-time-of-day`: morning/day/evening/night). Patrz § 2.3 + `00_PAMIEC_SYSTEMU.md` § 15.
6. ~~**Tryb statyczny — dokładny layout:**~~ ✅ **ROZSTRZYGNIĘTE 2026-04-19:** toggle `.doorway__static-toggle` (ikona dostępności) dodaje klasę `is-static` → wyłącza animacje, desaturuje ilustrację, honoruje `prefers-reduced-motion`. Osobna iteracja pod starsze urządzenia (accordion) nie jest potrzebna w MVP — menu i tak renderuje się po wejściu za ilustracją.
7. **Apple Pay / Google Pay — w Fazie 2 czy Fazie 3?** (P1 wg `06_WIZJA`, ale realnie to +2 tyg i integracja).

---

## 8. REFERENCJE

- `_docs/ARCHIWUM/06_WIZJA_MODULU_ONLINE.md` — starsza, szersza wizja; ten plik jest aktywną decyzją Fazy 2.
- `_docs/00_PAMIEC_SYSTEMU.md` — aktualizacja sekcji „Moduł Online" do odzwierciedlenia drogi B.
- `_docs/02_ARCHITEKTURA.md` — do aktualizacji w M2 gdy unified renderer powstanie.
- `canvases/slicehub-three-directions.canvas.tsx` — pełen kontekst decyzji (audyt + 3 drogi).
- Migracje DB: 012, 020–024 — fundament warstw, scen, companions, visual-impact.
- Kod: `core/SceneResolver.php`, `modules/online_studio/js/director/`, `modules/online/js/online_renderer.js`.

---

## 9. HISTORIA ZMIAN

| Data | Autor | Zmiana |
|------|-------|--------|
| 2026-04-18 | Damian + AI | Publikacja dokumentu. Wybór drogi B zatwierdzony. |
| 2026-04-19 | Damian + AI | ✅ M1 (Menu Studio Polish) wdrożony — patrz § 2.1. Rozstrzygnięte Q1 (Data-Layer First). |
| 2026-04-19 | Damian + AI | ✅ M2.1 (Unifikacja rendererów) wdrożona — patrz § 2.2. Rozstrzygnięte Q2 (`core/js/scene_renderer.js`). |
| 2026-04-19 | Damian + AI | ➕ § 2.4 Studio Evolution G1–G7 (konsolidacja po audycie — ~80% już jest w m022 + Hollywood Director's Suite). Dopisane Prawo VII w `00_PAMIEC_SYSTEMU.md`. Mapping punktów 7-stopniowego pipeline'u realizmu → G1/G4/G5/G6. Open questions rozszerzone o kolejność G-planu i zakres m030. |
| 2026-04-19 | Damian + AI | ✅ **G1–G6 DONE** (jedna sesja, kolejność B→A→C): migracja `m030_scene_harmony_cache`, `HarmonyScore.js` + badge + modal outlierów, `MagicConform` + `MagicHarmonize` (soft-blend/retouch), backend actions `scene_harmony_save/get`, `style_presets_list`, `category_style_apply`, `menu_style_apply`, `category_styles_list`, zakładka **Style Conductor** (`tabs/conductor.js` + `css/conductor.css`), Living Scene na storefroncie (`css/living-scene.css` + `applyAtmosphericEffects`, `atmosphericEffects` w `get_menu`/`get_scene_menu`/`get_scene_category`). **G3 + G7 ODŁOŻONE do Fazy AI** zgodnie z decyzją z § 2.4.2. |
| 2026-04-19 | Damian + AI | ✅ **M3 · Pipeline realizmu DOMKNIĘTY** — #4 **Auto-perspective match** (`CAMERA_PRESETS` + `applyCameraPerspective` w `core/js/scene_renderer.js`, 6 presetów, dropdown kamery w `ToolbarPanel`, persist w `sh_atelier_scenes.active_camera_preset`, konsumowane przez storefront+Director) oraz #7 **Baked variants** (migracja **m031** `sh_assets.cook_state` ENUM, `SceneResolver` bias UNION ALL, Asset Studio UI badge+dropdown, `api/assets/engine.php` `AE_COOK_STATES` + list/upload/update). Rozstrzygnięte Q (reszta pipeline'u). |
| 2026-04-19 | Damian + AI | ✅ **M4 · Scena Drzwi DONE** — `modules/online/js/online_doorway.js` + `css/doorway.css` + nowa sekcja w `modules/online/index.html`. Inline SVG ilustracja restauracji z 4 wariantami dobowymi, status open/closing_soon/closed, modal mapy (Leaflet lazy) + view tygodniowych godzin, tryb statyczny (a11y), deep-link `?skip=doors`, pre-order CTA dla zamkniętej restauracji. Backend: `get_doorway` w `api/online/engine.php` (KV settings + `opening_hours_json`). `OnlineAPI.getDoorway`. Integracja w `online_app.js` (`mountDoorway` → `enterAfterDoorway` → `loadMenuAndPopular`). Rozstrzygnięte Q5 (ilustracja) i Q6 (tryb statyczny). |
