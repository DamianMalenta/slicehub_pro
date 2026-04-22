# Gdzie czytać fazy A–G i mapę Online / Studio

> Krótka instrukcja nawigacji po repo — **nie zastępuje** `00_PAMIEC_SYSTEMU.md`, tylko mówi *gdzie* szukać.

---

## 0. Jedna linia na start nowej sesji

Wklej do nowego okna:

```markdown
Pracujemy nad SliceHub Online / Studio. Przeczytaj `_docs/GDZIE_CZYTAC_FAZY_ONLINE.md` i `_docs/00_PAMIEC_SYSTEMU.md` (sekcja „WIZJA CELU” + tabela faz). Status faz A–E: DONE w dokumencie; F i G: według tabeli.
```

Jeśli w nowej sesji coś „zginie”, zacznij od tego pliku, a potem przejdź do `00_PAMIEC_SYSTEMU.md`.

---

## 1. Główny dokument: fazy + wizja

**Plik:** `_docs/00_PAMIEC_SYSTEMU.md`

- Na górze sekcja **„★ WIZJA CELU”** — co budujemy, 4 sceny klienta, flow managera, historia SSOT biblioteki, niezmienniki.
- Niżej tabela **„Gdzie jesteśmy teraz (plan krótkoterminowy)”** — **fazy A–G** i status (DONE / oddzielna sesja / LATER).

To jest **jedno źródło prawdy** dla opisu faz i decyzji z sesji (np. 2026-04-19: A→E zamknięte).

---

## 2. Drugi plik (kierunek Online — szerszy kontekst)

**Plik:** `_docs/15_KIERUNEK_ONLINE.md` (jeśli istnieje w projekcie)

Rozwija temat storefrontu / studia. **Tabela faz A–E jest w `00_PAMIEC`**, nie trzeba jej szukać w wielu plikach naraz.

---

## 3. Mapa: faza → pliki w repozytorium

| Faza | Zakres | Gdzie w kodzie / dokumencie |
|------|--------|------------------------------|
| **A** | Wizja celu, spójność dokumentacji | `_docs/00_PAMIEC_SYSTEMU.md` (sekcja wizji + tabela) |
| **B** | SSOT biblioteki (`library_list` → `assetsList`) | `modules/online_studio/js/studio_api.js`, `studio_app.js`, `tabs/surface.js`, `director/lib/AssetPicker.js`, `api/online_studio/engine.php` |
| **C** | Harmony Score (model + UI + cache v2) | `modules/online_studio/js/director/harmony/HarmonyScore.js`, `DirectorApp.js`, `css/director.css`, `api/online_studio/engine.php` (`scene_harmony_*`) |
| **D** | Ustawienia sklepu | `api/online_studio/engine.php` (`storefront_settings_get` / `storefront_settings_save`), `modules/online_studio/js/tabs/storefront.js`, `studio_api.js`, `modules/online_studio/index.html`, `css/studio.css` (`.sf-*`) |
| **E** | Track (śledzenie zamówienia) | `api/online/engine.php` (`track_order`), `modules/online/track.html`, `js/online_track.js`, `css/track.css` |
| **F** | Counter + Living Table | **Planowane / osobna sesja** — opis w `00_PAMIEC_SYSTEMU.md` |
| **G** | Admin Hub (wiele tenantów) | **LATER** — `00_PAMIEC_SYSTEMU.md` |

---

## 4. Jak czytać, żeby zrozumieć

1. Otwórz **`_docs/00_PAMIEC_SYSTEMU.md`** i przeczytaj od **„★ WIZJA CELU”** do końca tabeli faz.
2. Jeśli chcesz wejść w szczegół jednej fazy — użyj tabeli z pkt. 3 i otwórz wskazane pliki.
3. Ten plik (`GDZIE_CZYTAC_FAZY_ONLINE.md`) trzymaj jako **ściągę nawigacyjną**; szczegóły techniczne zawsze w `00_PAMIEC` + właściwym module.

---

## 5. Co czytać dalej po `00_PAMIEC`

Jeśli chcesz zrozumieć tylko aktualny zakres prac, czytaj w tej kolejności:

1. **Najpierw** `00_PAMIEC_SYSTEMU.md` od sekcji **„★ WIZJA CELU”** do końca tabeli faz.
2. **Potem** fazę, która Cię interesuje, według mapy z pkt. 3.
3. **Na końcu** pliki wykonawcze:
   - Studio / manager: `modules/online_studio/...`
   - Storefront / klient: `modules/online/...`
   - Backend API: `api/online_studio/...`, `api/online/...`

---

**Kompilacja:** 2026-04-19
