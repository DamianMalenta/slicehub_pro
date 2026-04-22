# Interaction Contract v1 — klient ↔ serwer (storefront / The Table)

> **Status:** Faza 3.0 ✅ (2026-04-18)
> **Endpoint:** `POST /slicehub/api/online/engine.php`
> **Auth:** PUBLIC (bez sesji / JWT). Tenant scope przez pole `tenantId` w body.
> **Response envelope:** `{ success: bool, data: any, message: string }`

---

## 1. Filozofia

Istniejący storefront POS / Delivery używa `get_menu` + `get_dish` (płaskich kontraktów) i te akcje **zostają nietknięte** (backward-compat).

Nowy storefront **The Table** (Faza 3.1) dostaje trzy **bogatsze** akcje zbudowane nad `core/SceneResolver.php`:

| Akcja | Resolver | Kiedy użyć |
|---|---|---|
| `get_scene_menu` | `SceneResolver::batchResolveForCategory` (per kategoria) | Start listy menu — jedno żądanie dla wszystkich kategorii i pozycji. |
| `get_scene_dish` | `SceneResolver::resolveDishVisualContract` | Widok szczegółu dania (Surface Card) — pełny scene_spec, layers, promotions, companions, modyfikatory. |
| `get_scene_category` | `SceneResolver::resolveCategoryScene` | Widok wspólnej sceny kategorii (layout_mode = `grouped` / `hybrid`). |

Kontrakty są **wersjonowane** (`_meta.contractVersion`). Obecnie v1. Zmiany breaking → bump wersji.

---

## 2. Wspólne pola

Wszystkie akcje przyjmują:

```json
{
  "action": "get_scene_menu",
  "tenantId": 1,
  "channel": "Delivery"   // POS | Takeaway | Delivery (default)
}
```

Wszystkie zwracają w `data._meta`:

```json
{
  "contractVersion": 1,
  "resolver": "SceneResolver::batchResolveForCategory"
}
```

---

## 3. `get_scene_menu`

**Zwraca całe menu**, pogrupowane po kategoriach, z ceną channel-aware i mini-scene-contract per pozycja.

### Request

```json
{ "action": "get_scene_menu", "tenantId": 1, "channel": "Delivery" }
```

### Response

```jsonc
{
  "success": true,
  "data": {
    "tenantId": 1,
    "channel": "Delivery",
    "categories": [
      {
        "id": 42,
        "name": "Pizze",
        "isMenu": true,
        "layoutMode": "individual",            // grouped | individual | hybrid | legacy_list
        "defaultCompositionProfile": "pizza_top_down",
        "hasCategoryScene": false,              // true → można wołać get_scene_category
        "items": [
          {
            "sku": "pizza_margherita",
            "name": "Margherita",
            "description": "...",
            "heroUrl": "/slicehub/uploads/assets/1/hero/margherita.webp",
            "compositionProfile": "pizza_top_down",
            "hasScene": true,                   // true → dedykowana sh_atelier_scenes
            "activeStyle": {                    // null lub style preset z sh_style_presets
              "id": 7,
              "asciiKey": "cottagecore_rustic",
              "name": "Cottagecore",
              "colorPalette": { "primary": "#84cc16", "bg": "#fef3c7", ... },
              "fontFamily": "Merriweather",
              "defaultLut": "warm_summer_evening"
            },
            "price": 32.00,
            "priceFallback": false              // true → pobrano z POS, a nie z Delivery
          }
        ]
      }
    ],
    "totalItems": 24,
    "_meta": { "contractVersion": 1, "resolver": "SceneResolver::batchResolveForCategory" }
  },
  "message": "OK"
}
```

### Zachowanie

- Kategorie bez aktywnych items są pomijane.
- `activeStyle` cascade: scene > category (sh_category_styles) > null (template default ignorowany — dostępny dopiero w `get_scene_dish`).
- `heroUrl` cascade: `sh_asset_links(role=hero)` > `sh_menu_items.image_url` > null.
- `priceFallback=true` oznacza że `sh_price_tiers` nie miał wiersza dla kanału `channel`, użyto fallbacku na POS.

---

## 4. `get_scene_dish`

**Pełny kontrakt jednego dania** — gotowy do renderowania Surface Card / detail view.

### Request

```json
{
  "action": "get_scene_dish",
  "tenantId": 1,
  "channel": "Delivery",
  "itemSku": "pizza_margherita"
}
```

### Response (skrócony)

```jsonc
{
  "success": true,
  "data": {
    "tenantId": 1,
    "channel": "Delivery",
    "sceneContract": {
      "sku": "pizza_margherita",
      "name": "Margherita",
      "description": "...",
      "category_id": 42,
      "composition_profile": "pizza_top_down",
      "hero_url": "/slicehub/uploads/assets/1/hero/margherita.webp",
      "hero_meta": { "width": 1200, "height": 1200, "mime": "image/webp", "source": "asset_link" },
      "scene_spec": {                          // raw sh_atelier_scenes.spec_json (lub null)
        "version": 12,
        "kind": "pizza",
        "template_key": "pizza_top_down",
        "stage": { "boardUrl": "/slicehub/uploads/assets/1/board_rustic.webp", "lightX": 50, "lightY": 15 },
        "pizza": {
          "layers": [
            { "layerSku": "sauce_tomato", "assetUrl": "/slicehub/...", "zIndex": 10, "isBase": true },
            { "layerSku": "cheese_mozzarella", "assetUrl": "/slicehub/...", "zIndex": 30 }
          ]
        }
      },
      "scene_meta": {
        "scene_id": 99,
        "template": {
          "id": 1, "ascii_key": "pizza_top_down", "name": "Pizza — kamera z góry", "kind": "item",
          "available_cameras": ["top_down", "macro_close", "wide_establishing"],
          "available_luts": ["warm_summer_evening", "golden_hour", "crisp_morning", "teal_orange_blockbuster"],
          "atmospheric_effects": ["steam_rising", "dust_particles_golden"],
          "photographer_brief_md": "## Pizza Top-Down — Brief..."
        },
        "active_style": { "id": 7, "asciiKey": "cottagecore_rustic", ... },
        "active_style_source": "category",     // scene | category | template_default | null
        "active_camera": "top_down",
        "active_lut": "warm_summer_evening",
        "atmospheric_effects": ["steam_rising"],
        "active_trigger": null                 // lub { id, name, reason } gdy aktywny trigger
      },
      "layers": [                              // canonical layer list (scene > visual_layers fallback)
        { "layerSku": "sauce_tomato", "assetUrl": "...", "zIndex": 10, "isBase": true,
          "calScale": 1.0, "calRotate": 0, "offsetX": 0, "offsetY": 0 }
      ],
      "promotions": [                          // promo slots pozycjonowane na scenie (M022)
        { "slotId": 5, "promotionId": 2, "asciiKey": "half_price_tuesday",
          "name": "Wtorek -50%", "badgeText": "-50%", "badgeStyle": "amber",
          "ruleKind": "discount_percent",
          "slotX": 75, "slotY": 12, "slotZIndex": 100, "displayOrder": 1 }
      ],
      "_meta": { "resolver": "SceneResolver", "channel": "Delivery", "has_m022": true }
    },
    "price": 32.00,
    "priceFallback": false,
    "modifierGroups": [
      {
        "groupId": 10, "name": "Rozmiar", "asciiKey": "size",
        "minSelection": 1, "maxSelection": 1, "freeLimit": 0,
        "options": [
          { "sku": "size_30cm", "name": "30cm", "isDefault": true, "price": 0.00 },
          { "sku": "size_40cm", "name": "40cm", "isDefault": false, "price": 8.00 }
        ]
      }
    ],
    "companions": [
      { "sku": "coca_cola_330", "name": "Coca-Cola 330ml",
        "type": "drink", "boardSlot": "right",
        "heroUrl": "/slicehub/uploads/...", "price": 7.00 }
    ],
    "_meta": { "contractVersion": 1, "resolver": "SceneResolver::resolveDishVisualContract" }
  },
  "message": "OK"
}
```

### Zachowanie

- `scene_spec` może być `null` — dish nie ma jeszcze sceny (tylko hero_url z `sh_asset_links`).
- `layers` zwracane z `sh_atelier_scenes.spec_json.pizza.layers` jeśli są, inaczej fallback na `sh_visual_layers`.
- `promotions` to **tylko pozycje na scenie** (badges) — reguły liczenia rabatu wykonuje `CartEngine::calculate` w `cart_calculate`.
- `companions` pobiera `hero_url` z `sh_asset_links` (z fallbackiem na `sh_menu_items.image_url`).

---

## 5. `get_scene_category`

**Pełna scena kategorii** — dla `layout_mode IN ('grouped', 'hybrid')`. Zawiera wspólny `scene_spec` (np. rozłożenie dań na stole przez `placements`) oraz listę items.

### Request

```json
{ "action": "get_scene_category", "tenantId": 1, "channel": "Delivery", "categoryId": 42 }
```

### Response

```jsonc
{
  "success": true,
  "data": {
    "tenantId": 1,
    "channel": "Delivery",
    "categoryId": 42,
    "categoryName": "Sosy",
    "isMenu": true,
    "layoutMode": "grouped",
    "defaultCompositionProfile": "static_hero",
    "sceneSpec": {
      "version": 3,
      "kind": "category_table",
      "template_key": "category_flat_table",
      "placements": [
        { "sku": "sauce_bbq",    "x": 0.25, "y": 0.40, "scale": 1.0, "z_index": 40 },
        { "sku": "sauce_garlic", "x": 0.50, "y": 0.45, "scale": 1.0, "z_index": 41 },
        { "sku": "sauce_hot",    "x": 0.75, "y": 0.40, "scale": 1.0, "z_index": 42 }
      ]
    },
    "sceneMeta": {
      "scene_id": 201,
      "template": null,
      "active_style": { ... },
      "active_trigger": null
    },
    "items": [
      {
        "sku": "sauce_bbq",
        "name": "Sos BBQ",
        "description": "...",
        "heroUrl": "/slicehub/uploads/.../bbq.webp",
        "compositionProfile": "static_hero",
        "hasScene": false,
        "activeStyle": { ... },
        "price": 3.00,
        "priceFallback": false
      }
    ],
    "_meta": { "contractVersion": 1, "resolver": "SceneResolver::resolveCategoryScene" }
  }
}
```

### Zachowanie

- Jeśli `sh_categories.category_scene_id` jest `NULL` — `sceneSpec = null`, `sceneMeta.scene_id = null`. Klient powinien wtedy zrobić fallback do `layout_mode=legacy_list` (pokazać items jak w `get_scene_menu`).
- `placements.x/y ∈ [0..1]` — pozycja środka talerza względem szerokości/wysokości sceny (CategoryTableEditor w Menu Studio pozwala managerowi drag&drop).

---

## 6. Koszyk (cart_calculate, bez zmian)

`POST /slicehub/api/online/engine.php`

```json
{
  "action": "cart_calculate",
  "tenantId": 1,
  "channel": "Delivery",
  "order_type": "delivery",
  "lines": [
    { "sku": "pizza_margherita", "qty": 1, "modifiers": [{ "sku": "size_40cm" }] },
    { "sku": "coca_cola_330", "qty": 2 }
  ],
  "promo_code": ""
}
```

Zwraca `CartEngine::calculate(...)['response']`. Pola:

```jsonc
{
  "channel": "Delivery",
  "order_type": "delivery",
  "subtotal": "42,00",
  "delivery_fee": "8,00",
  "discount": "10,00",               // SUMA (auto + manual code)
  "grand_total": "40,00",

  // ── Auto-promocje (sh_promotions — Faza 4.1) ──
  "auto_promotion_discount": "10,00",
  "applied_auto_promotions": [
    {
      "id": 2,
      "ascii_key": "tuesday_half_price",
      "name": "Wtorek − 50% na pizzę",
      "rule_kind": "discount_percent",
      "amount": "10,00",                        // rabat sformatowany w zł
      "badge_text": "-50%",
      "badge_style": "amber",                   // amber | emerald | rose | sky | violet | neutral
      "note": "-50% na kategorię #12"
    }
  ],

  // ── Kod ręczny (legacy sh_promo_codes) ──
  "applied_promo_code": "HAPPY20",              // lub null
  "applied_discount": "5,00"                    // jeżeli był kod
}
```

**Reguły promocji (`rule_kind` + `rule_json`) obsługiwane przez CartEngine:**

| `rule_kind` | Parametry `rule_json` | Zachowanie |
|---|---|---|
| `discount_percent` | `target: cart/item/category`, `percent`, `min_subtotal?`, `sku?`, `category_id?` | `%` rabat od wybranego targetu; target=`cart` liczy od subtotala. |
| `discount_amount` | jw. + `amount` (zł) | Stała kwota rabatu, capped do matched subtotal. |
| `combo_half_price` | `anchor_sku`, `combo_sku`, `percent?=50` | Gdy w koszyku jest `anchor_sku` → najtańsza jednostka `combo_sku` jest tańsza o `percent%`. |
| `free_item_if_threshold` | `min_subtotal`, `free_sku` | Gdy subtotal ≥ próg i `free_sku` w koszyku → rabat równy cenie najtańszej jednostki tego SKU. |
| `bundle` | `skus: [...]`, `bundle_price` (zł) | Jeśli wszystkie SKU w koszyku → rabat = (suma ich unit) − `bundle_price`. |

Plus gating `time_window_json`:

```jsonc
{ "days": [2], "start": "10:00", "end": "15:00" }  // 2=Wt (ISO, 1=Pn)
```

**MVP: best-wins** — gdy wiele promocji kwalifikuje się, CartEngine wybiera jedną dającą największy rabat. V2 doda `stackable:true` + priorities + wykluczenia.

---

## 7. Checkout i tracking storefrontu

```json
{
  "action": "init_checkout",
  "tenantId": 1,
  "channel": "Delivery",
  "order_type": "delivery",
  "lines": [
    { "item_sku": "pizza_margherita", "quantity": 1, "added_modifier_skus": ["extra_cheese"] }
  ],
  "promo_code": "",
  "customer_phone": "+48123123123"
}
```

### `init_checkout`

Zwraca `lock_token` (TTL 5 min) oraz authoritative total dla aktualnego koszyka. Ten token jest obowiązkowy przy `guest_checkout`.

### `guest_checkout`

```json
{
  "action": "guest_checkout",
  "tenantId": 1,
  "channel": "Delivery",
  "order_type": "delivery",
  "lock_token": "550e8400-e29b-41d4-a716-446655440000",
  "lines": [
    { "item_sku": "pizza_margherita", "quantity": 1, "added_modifier_skus": ["extra_cheese"] }
  ],
  "customer": {
    "name": "Jan",
    "phone": "+48123123123",
    "email": "jan@example.com"
  },
  "delivery": {
    "address": "Poznań, ul. Przykładowa 10/4",
    "lat": 52.4064,
    "lng": 16.9252,
    "notes": "Kod 1234"
  },
  "payment_method": "cash_on_delivery"
}
```

Zwraca m.in.:

```jsonc
{
  "success": true,
  "data": {
    "orderId": "uuid",
    "orderNumber": "WWW/20260419/0001",
    "trackingToken": "16hexchars",
    "trackingUrl": "/slicehub/modules/online/track.html?tenant=1&token=...&phone=..."
  }
}
```

### `track_order`

```json
{
  "action": "track_order",
  "tenantId": 1,
  "tracking_token": "16hexchars",
  "customer_phone": "+48123123123"
}
```

**Ważne:** aktualny kontrakt trackera działa po `tracking_token + customer_phone`. Sam `order_number` nie jest dziś alternatywnym kluczem odzyskania zamówienia.

### `delivery_zones`

```json
{
  "action": "delivery_zones",
  "tenantId": 1,
  "address": "Poznań, ul. Przykładowa 10/4",
  "lat": 52.4064,
  "lng": 16.9252
}
```

**Ważne:** autorytatywne sprawdzenie strefy wymaga `lat` + `lng`. Jeśli storefront poda tylko `address`, backend zwróci miękki fallback (`in_zone = null`) do manualnej weryfikacji zamiast twardego green/red.

---

## 8. Zalecane wzorce wywołań (The Table)

### A) Start sesji
```
POST get_storefront_settings          → brand, surfaceBg, halfHalfSurcharge
POST get_scene_menu                   → wszystkie kategorie + pozycje w jednym żądaniu
```

### B) Pojedyncza kategoria z grouped scene
```
if (category.layoutMode === 'grouped' || category.layoutMode === 'hybrid'):
    POST get_scene_category { categoryId }   → pełna wspólna scena
else:
    render items z get_scene_menu            → indywidualne karty
```

### C) Klik w danie
```
POST get_scene_dish { itemSku }       → Surface Card z layers/scene_spec/promotions/mods/companions
```

### D) Przeliczenie koszyka
```
POST cart_calculate { lines, promo_code, order_type }
```

Każde kliknięcie w item lub zmiana modyfikatora → nowy `cart_calculate` (server-authoritative).

---

## 9. Wersjonowanie

| Wersja | Data | Zmiany |
|---|---|---|
| 1 | 2026-04-18 | Pierwsza publiczna wersja. `get_scene_menu` / `get_scene_dish` / `get_scene_category`. |

Przy breaking changes — `contractVersion: 2`. Klient powinien odrzucać response z wyższą wersją niż obsługuje.
