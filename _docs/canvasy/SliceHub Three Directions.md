3 drogi + odpowiedzi na pytania (analiza po audycie kodu)
Zrobilem audyt calego systemu. Menu Studio, Director, Online, 15+ innych modulow. Dzieki temu wiem teraz konkretnie co jest, co dziala, czego brakuje — i to zmienia rekomendacje. Ponizej: odpowiedz na pytanie o dodawanie skladnikow, audyt faktyczny, 3 drogi z realnym kosztem pracy (nie na oko), i jedno nowe podejscie, ktore wpadlo mi dopiero po audycie.

70%
Director juz zbudowany
40%
Online storefront dziala
2
Odrebne rendery (problem)
0
Jednolity admin dashboard
Najwazniejsze wnioski audytu
1. Twoja obawa o Menu Studio byla czesciowo sluszna. UI jest niedokonczony, ALE architektura danych juz natywnie wspiera laczenie skladnikow (modifiers) z warstwami wizualnymi przez sh_asset_links z rola layer_top_down. Kolumna has_visual_impact (migracja 024) rozroznia juz "skladniki na pizzy" od "dodatkow obok". To wszystko juz jest.

2. Mamy dwa odrebne renderery (SceneRenderer.js w Director, online_renderer.js w Online) — to jest realny problem, ktory trzeba rozwiazac w kazdej drodze, ktora wybierzemy. Kompozycja warstw w Director dziala pieknie, na storefront dociera tylko pizza bez stage/companions/ambient.

3. Director juz ma 6 silnikow Magic (Bake, Companions, Enhance, ColorGrade, Relight, Dust) — zbudowane. Nie musimy ich pisac od nowa, tylko podpiac i doprecyzowac.

4. Proponuje trzy drogi: A) "Film" pelny (3-4 miesiace), B) Realistyczny Counter + Drzwi (6-8 tygodni, leveragujac to co zbudowane), C) "Restaurant Viewfinder" (nowy pomysl, kompromis). Druga jest najszybsza, trzecia jest najbardziej innowacyjna.

5. Realizm warstw: foundation jest w kodzie (blend modes, feather mask, calibration, LUT). Do dociagniecia: contact shadows, wspoldzielona LUT per scena, auto-perspective match. Wszystkie mozliwe i zaplanowane.

Cz. 1 — Jak dziala dodawanie skladnika (konkretnie)
To bylo najwazniejsze pytanie w Twojej wiadomosci. Ponizej konkretny projekt UX, ktory wykorzystuje to, co juz mamy w bazie.

Dwa rodzaje modyfikatorow (juz rozroznione w DB)
Kolumna sh_modifiers.has_visual_impact (migracja 024) juz dzieli modyfikatory na:

• "Na pizze" (has_visual_impact = 1): ser, pepperoni, szynka, cebula, bazylia, sos bazowy. Ten skladnik dolacza jako warstwa do zdjecia pizzy.

• "Obok pizzy" (has_visual_impact = 0 lub osobny produkt w koszyku): frytki, sos dip, cola, nuggetsy. Ten skladnik pojawia sie na stole jako companion, nie na pizzy.

Nie musimy tego projektowac — juz to mamy. Wystarczy uzyc tego w UX.

Dodanie skladnika NA pizze (szynka, pepperoni, ser)
1. Klient patrzy na pizze na scenerii. Pod spodem (mobile: bottom sheet drawer, desktop: prawy panel) sekcja "Komponuj" z chipami skladnikow, kazdy ma minimalna miniaturke.

2. Chip szynka ma kontrolki - 0 +. Klient klika +.

3. Na pizzy materializuje sie warstwa szynki (6-8 plastrow) w pre-definiowanym scatter patternie. Animacja: Cinematic Dissolve (fade + scale 0.9 → 1.0) przez 300ms.

4. Cena w koszyku sie aktualizuje (sliding number).

5. Klient klika + znowu → liczba szynki 2x (12 plastrow), nowe plastry dochodza do juz istniejacych pozycji scatter.

6. Klient klika - → najpierw znikaja plastry z ostatnio dodanych (seeded).

Tech: sh_asset_links role=layer_top_down dla warstwy modyfikatora + SceneRenderer.repaintSurface() juz to robi.

Dodanie dodatku OBOK pizzy (frytki, sos, cola)
1. W tym samym panelu, druga sekcja: "Do stolu" (chipy dodatkow).

2. Klient klika chip frytki — pojawia sie mini-modal z wyborem rozmiaru (jesli modyfikator ma warianty).

3. Na stole obok pizzy materializuje sie miska z frytkami (ta sama Cinematic Dissolve).

4. Koszyk rosnie o nowa pozycje (osobna — frytki to odrebny produkt, nie modyfikator pizzy).

5. Klient przesuwa palec w prawo na pizze #2 → pizza sie zmienia, frytki zostaja na stole (Living Table).

Tech: companion to osobny hero-asset (sh_asset_links role=modifier_hero). MagicCompanions juz liczy ich pozycje (4 pozycje max). SceneStore trzyma stan companions niezaleznie od pizzy.

Gdzie fizycznie klient naciska
Mobile (dominujace): bottom sheet drawer, dwa taby Komponuj / Do stolu, chipy z ikonami. Drawer otwarty na ~40% ekranu, pizza widoczna powyzej. Swipe w dol = zamknij.

Desktop: prawy panel staly (szerokosc 320px), pizza zajmuje lewe 2/3 ekranu. Klient moze scrollowac liste modyfikatorow bez zakrywania pizzy.

Fallback static: klasyczny dropdown "Wybierz dodatki" → checkboxy → dodaj do koszyka. To jest to, co juz mamy dzis.

Cz. 2 — Stan faktyczny systemu (co JUZ dziala)
Podczas audytu przeskanowalem 15+ modulow, 25 migracji, 6 silnikow Magic, 7 paneli Directora. Oto co naprawde mamy.

Director (online_studio) — 70% gotowe
6 silnikow Magic
Bake (blend + shadows), Companions, Enhance (orchestrator), ColorGrade (LUT), Relight (light + vignette + grain), Dust (ambient steam/crumbs/oil)
7 paneli
Toolbar, Viewport, Hierarchy, Inspector, Timeline, Scenography, Promotions
SceneRenderer
Pelna kompozycja: stage + LUT + pizza z warstwami z-index + companions + ambient + letterbox + feather mask
HistoryStack
Undo/redo, 50 snapshotow z labelami
SceneStore
pizza.layers, companions, infoBlock, modifierGrid, ambient — reaktywny stan
Zapis scen
director_save_scene → sh_atelier_scenes.spec_json + historia
WYSIWYG z storefront
Director rysuje pelna scene, storefront rysuje tylko warstwy pizzy — niezgodne
Online storefront (modules/online/) — 40% gotowe
Layer compositing
Stacked <img> w CSS, bez WebGL, z klipowaniem dla half-and-half
Scroll-snap swipe
Native horizontal scroll-snap miedzy sekcjami (juz dziala na mobile)
Companions UI
Grid przyciskow pod pizza, NIE pozycje na stole
Stage + LUT + ambient
Nie dociera z Director do storefront — brakujace w online_renderer.js
Scene swipe (pizza 1 → pizza 2)
Nie ma — klient widzi accordion lub tabelaryczne menu
Living Table (companions persist miedzy daniami)
Nie zaimplementowane w storefront
Drzwi / hero / doors scene
Nie ma
Menu Studio — podstawy + dziwna mieszanka
sh_categories + sh_menu_items
Dzialajace CRUD, VAT, kategorie z layout_mode i category_scene_id (M022)
sh_modifier_groups + sh_modifiers
Dzialajace + action_type (ADD/REMOVE/NONE) + has_visual_impact (M024)
Recipes (sh_recipes)
Recepty BOM menu_item_sku → warehouse_sku (koszty)
Visual layers per modifier
sh_asset_links role=layer_top_down + modifier_hero (m021)
Legacy sh_visual_layers
Stara droga item_sku + layer_sku — nadal dziala rownolegle
Ingredient library API
Usunieta (points to Asset Studio + Online APIs) — niedokonczona
Recipes / modifiers UI w studio_*.js
Podstawowe, wymaga rozbudowy zeby manager latwo zarzadzal
Event system + integracje
OrderStateMachine + OrderEventPublisher
Dziala, pisze do sh_event_outbox w transakcji
Workers (webhooks + integrations)
scripts/worker_webhooks.php + worker_integrations.php
CredentialVault
libsodium XChaCha20-Poly1305 AEAD dla secretow integracji
Inbound callbacks framework
api/integrations/inbound.php + BaseAdapter.parseInboundCallback
Settings Panel (modules/settings/)
SPA dla integracji, webhooks, DLQ, health
Pozostale moduly (POS, KDS, Courses, Driver, Warehouse, Tables, Waiter)
POS (modules/pos/)
Pelne acceptance/settle/print, hooks na OrderStateMachine
KDS (modules/kds/)
Kuchnia widzi bilety, update_ticket dziala
Courses + Driver App
Dzielą api/courses/engine.php — runs, wallet, dispatch
Warehouse (12+ UIs)
Dokumenty PZ/RW/KOR/MM/inwentaryzacja — wszystko zbudowane
Tables (floor plan)
Wizualny uklad stolow, status
Jednolity super-admin
NIE MA — kazdy modul jest silo, laczy je tylko DB i Settings
Cz. 3 — Twoje pomysly vs. co juz jest (gap analysis)
Twoj pomysl	Co juz dziala (fundament)	Co brakuje (do dobudowania)
Scena drzwi na start	Nic w online_renderer.js, ale AssetResolver + sh_assets gotowe	Doors component + hotspots + modal z mapa (czysty new-build)
Warstwy skladnikow	sh_asset_links + role layer_top_down + SceneRenderer zestaw warstw juz tworzy	Unifikacja rendererow (Director + Online) + polerowanie realizmu (shadows, perspective)
Living Table (companions persist)	MagicCompanions liczy do 4 pozycji, SceneStore ma companions osobno od pizzy	Propagacja stanu companions miedzy swipe pizza → pizza w storefront
Time-of-Day automatyka	MagicRelight + MagicDust robia ambient, LUT library dziala	TOD Dial w Director + harmonogram server-side + client-side synchro
Film (5 scen: Drzwi, Lada, Sala, Koszyk, Potwierdzenie)	40% Counter juz jest (menu + Living Table), reszta 0%	4 nowe sceny + sterowanie sekwencja + transitions
Magic Wand (styl per kategoria)	MagicEnhance ma przelaczanie LUT + lighting presets	Style orkiestrator + preset pack per styl (retro/noir/pastel)
Dodawanie skladnika z UX	has_visual_impact juz dzieli modyfikatory, SceneRenderer warstwy renderuje	Bottom-sheet komponujacy + animacja materializacji + live price update
Realizm (nie wyglada jak sklejka)	Blend modes, opacity, CSS filters, radial mask feather, calScale/calRotate	Contact shadows, shared scene LUT per layer, perspective match, wet/grease pass
Cz. 4 — Problem "rubryk" w Menu Studio (Twoja obawa — prawda w polowie)
Twoja obawa, ze "rubryki moga przeszkadzac" w laczeniu warstw do dan, jest czesciowo sluszna — ale inaczej, niz myslisz.

Architektura laczenia juz istnieje
1. Danie: sh_menu_items (ma category_id, ascii_key).

2. Kategoria: sh_categories (ma layout_mode, category_scene_id).

3. Skladniki: sh_modifiers (ma ascii_key, action_type, has_visual_impact).

4. Dziale dopuszczalne do dania: sh_item_modifiers (item_id + group_id).

5. Asset wizualny skladnika: sh_asset_links z entity_type=modifier, role=layer_top_down.

Mozemy juz zbudowac pizze warstwami z danych, ktore sa w DB.

UI / UX Studio jest niedokonczone
1. studio_*.js ma podstawowy CRUD na kategoriach i daniach, ale brak intuicyjnego connect-dots miedzy skladnikiem a jego zdjeciem warstwy.

2. Manager musi dzis reczne wrzucic asset, zostawic go w galerii, pojsc do modyfikatora, wkleic link. To za duzo klikow.

3. Brak podgladu "jak wyglada pizza ze wszystkimi wybranymi warstwami" (auto-miniaturka).

4. Rubryki dzialaja dobrze — problem jest w UX asset-linkingu, nie w schemacie.

Rozwiazanie: Sesja Studio Polish (1-2 tygodnie), zanim zbudujemy online.

Co zaproponowales: "system wylapuje skladniki z menu studio i tworzy z warstw gotowa pizze"
To jest dokladnie to, co mozemy zrobic. Flow:

1. Manager dodaje dania w Studio. Dla kazdego dania wybiera "domyslne skladniki" (modifiers z action_type=NONE, czyli "jest domyslnie na pizzy").

2. System auto-buduje miniaturke: bierze hero scena kategorii + dla kazdego domyslnego modyfikatora podciaga asset layer_top_down + skleja w SceneRenderer.

3. Manager widzi podglad. Jesli jakas warstwa wyglada zle — poprawia w Director (scale, rotate, offset per warstwa).

4. "Zapisz" = scena trafia do sh_atelier_scenes.spec_json. Storefront juz umie to czytac.

To juz jest w 70% zbudowane. Brakuje tylko: (a) auto-generatora domyslnej kompozycji, (b) UX w Studio zeby to bylo intuicyjne.

Cz. 5 — Gwarancje realizmu (pipeline 7 poziomow)
Zeby warstwy naprawde wygladaly jak jedno zdjecie, nie kolaz, potrzebujemy 7-stopniowego pipeline compositingu. Ponizej co juz mamy, czego brakuje.

STOP
CO ROBI
GDZIE W KODZIE
STATUS
1. Scatter
Rozklad N plastrow z kotwicami, wariancja rotacji/skali, seeded
MagicCompanions (companions), manualny layer.calScale/calRotate
2. Blend
MixBlendMode dopasowujace ser/sos/sery do spodu
MagicBake.PRESETS, SceneRenderer.mixBlendMode
3. Feather
Radialny mask zeby krawedz warstwy sie wtopila
SceneRenderer radial-gradient mask (layer.feather)
4. LUT match
Kazda warstwa przechodzi przez LUT sceny (nie wlasny)
SceneRenderer stosuje LUT na cala scene, warstwy dziedzicza
5. Contact shadow
Miekki cien pod warstwa w kierunku swiatla sceny
MagicBake.shadowStrength, ale proste — nie directional
6. Wet/grease
Specular highlight pass, warstwa {"wyglada soczyscie"}
BRAK
7. Perspective
Warstwa auto-dopasowuje perspektywe do kata kamery sceny
Manualnie przez calRotate, brak auto
Wniosek: fundament jest (3 z 7 zbudowane, 3 polowicznie). Do pelnej realizmu potrzeba dobudowac kroki 6 (wet/grease) i 7 (perspective match). To jest okolo 2-3 tygodnie pracy na samym pipelinie.

Jak fotografowac skladniki zeby pipeline mial za co zlapac
1. Top-down 90° exact. Statyw, poziomica. Waznie zeby kazdy skladnik byl z tego samego kata co scena pizzy.

2. Jednolite szare tlo (#808080). Latwo usuwalne.

3. Miekkie rozproszenie swiatla z gory-prawej. Nasz pipeline zaklada to ustawienie.

4. Bez cienia pod skladnikiem. System sam dorzuci directional shadow.

5. Kazdy skladnik — 3-5 wariantow (inny rozklad, inny kat rotacji, inna ilosc). System losuje z puli.

6. Rozdzielczosc min 2048x2048 per asset. PNG z alpha kanalu albo plain solid bg do wyrzucenia.

7. Wariant "upieczony" (cooked) — dla skladnikow, ktore zmieniaja sie w piecu (cebula sie zeszkla, ser sie rozplynie).

Moge napisac pelna instrukcje z checklista + wzory shot-listy przed sesja zdjeciowa. Daj znac.

Cz. 6 — Nowy pomysl uderzenia w rynek: "Restaurant Viewfinder"
Po audycie wpadl mi pomysl, ktory laczy realizm z filmowoscia w sposob tanszy niz pelen Film, a bardziej innowacyjny niz prosty Counter. Nazwijmy to Restaurant Viewfinder.

Klient patrzy przez "wizjer kamery" na restauracje
Zamiast 5 odrebnych scen z nawigacja miedzy nimi, caly ekran to jedna scena — wnetrze restauracji widziane z perspektywy klienta. Klient "rozglada sie" swipujac w roznych kierunkach:

↑ GORA
Menu na scianach
(grid kategorii)
CENTRUM
Stol z daniem
(Living Table)
↓ DOL
Koszyk + kasa
(slide-up drawer)
← LEWO
Kuchnia
(chef anim + live KDS)
DRZWI
Entry scene
(przed wejsciem)
→ PRAWO
Sala gosci
(recenzje, gamifikacja)
To jest kompromis miedzy "5 scen niezaleznych" (Film) a "jedna plasska strona" (Simplified). Klient ma jedno spojne doswiadczenie przestrzenne, a jednoczesnie kazdy kierunek ma inna funkcje.

Dlaczego to dziala
1. Jedna scena — mniej compositingu, mniej preloadu, szybciej na mobile.

2. Spojnosc wizualna gwarantowana — wszystko w tym samym oswietleniu, tym samym LUT.

3. Swipe jako mentalny model — kazdy telefon wie swipe. Nie trzeba uczyc klienta.

4. Living Table dostaje pelne miejsce w centrum — to jest glowna atrakcja.

5. Drzwi na start zostaja — to hero shot, potem klient wchodzi "do srodka" (animacja otwierania drzwi raz).

6. Viewfinder daje mozliwosc "parallax depth" — swipujac widzisz rozne glebie sceny, jak w grze izometrycznej.

Co trzeba zbudowac
1. Jeden "stage" w SceneRenderer rozszerzony o 4 kierunki swipe.

2. Smooth pan transition (CSS transform-translate) miedzy kierunkami.

3. Living Table w centrum (to juz jest w Director, trzeba tylko unifikacja do storefront).

4. 4 dodatkowe "widoki boczne": menu, kuchnia, sala, drzwi-po-przejsciu.

5. Koszyk slide-up drawer (natywnie na mobile).

6. Preload strategii — najpierw centrum, na swipe boczne doladowujemy.

Estimate: 4-6 tygodni, duzo mniej niz pelen Film (3-4 miesiace).

Cz. 7 — 3 drogi do wyboru (teraz)
Kazda droga zachowuje drzwi na start, zachowuje warstwy, nie duplikuje istniejacych funkcji. Roznia sie zasiegiem, kosztem, ryzykiem.

Droga A — "Film" pelny
Pelen Film z 5 scenami (Drzwi, Lada, Sala, Koszyk, Potwierdzenie) + TOD + Closed/Preorder + 10 narzedzi Directora.

Mocno rozszerza fundament. Wymaga rewrite frontendu online jako SPA z PixiJS/React. Unifikacja rendererow konieczna.

Plusy
• Maksymalny "wow".

• Realna innowacja na rynku.

• Wszystkie Twoje wizje zmieszczone.

Minusy
• 3-4 miesiace pracy (realnie).

• Wymaga rewrite stack front-end.

• Wysokie ryzyko performance na slabych telefonach.

• Jesli przesuniemy cos dalej, caly MVP przestaje miec sens.

Droga B — Realistyczny Counter + Drzwi
Dwie sceny: Drzwi (hero) + Counter (Living Table z warstwami). Leveragujemy 70% Directora. Bez Sali, Filmu, TOD.

Unifikacja rendererow. Menu Studio Polish (1-2 tyg) najpierw. Potem Living Table w storefront.

Plusy
• Najkrotsza droga do czegos spektakularnego.

• Leveraguje wszystko, co juz zbudowane.

• Niskie ryzyko performance.

• Menu Studio zostaje dopolerowane przy okazji.

Minusy
• Mniejszy "wow" niz Film.

• Sala, Promocje, Gamifikacja zostaja na pozniej.

• Nie aspiruje do "najnowszego na rynku", tylko "duzo ladniejszego niz konkurencja".

Droga C — Restaurant Viewfinder
Jedna duza scena, swipe w 4 kierunkach (menu / kuchnia / sala / koszyk), centrum to Living Table. Drzwi jako entrada.

Kompromis miedzy A i B. Spojnosc wizualna gwarantowana (jedna scena), innowacyjna nawigacja.

Plusy
• Unikalne na rynku.

• Spojnosc wizualna z natury.

• Jeden mentalny model dla klienta (swipe).

• Kuchnia animowana + live KDS jako wow-feature.

Minusy
• Ryzyko "klient gubi sie" — trzeba dobrych wskazowek.

• Wymaga dobrej preloading strategii.

• Skalowanie: kazdy szablon (Pizza/Burger/Sushi) to 4 widoki boczne.

Ktora droge radze wybrac
B (Realistyczny Counter + Drzwi) — jako MVP Fazy 2. Leveragujemy wszystko, co zbudowane, dostarczamy cos spektakularnego w 6-8 tygodniach, testujemy na prawdziwych klientach.

Potem — jesli wyniki sa dobre — rozbudujemy do C (Viewfinder) w Fazie 3. C jest naturalnym rozszerzeniem B (dodajemy kierunki).

A (pelen Film) jako Phase 5+, gdy juz wiemy, co dziala i mamy zespol wiekszy niz jedna osoba.

Alternatywnie: jesli Twoj instynkt mowi "uderz mocniej" — C od razu. To sensowne ryzyko, bo zlewaramy 70% Directora, a kierunek jest nowy na rynku.

Cz. 8 — Logika zarzadzania (Twoja uwaga byla sluszna)
Powiedziales: "troche brakuje mi logiki zarzadzania tym wszystkim". Audyt potwierdza — nie ma jednego super-admin dashboardu. Kazdy modul (POS, KDS, Courses, Warehouse, Settings, Tables, Menu Studio, Online Studio) to silos z wlasnym UI.

Co brakuje do "logiki zarzadzania"
1. Unified dashboard — jedno miejsce, gdzie widzisz: biezace zamowienia, stan kuchni, flote kurierow, stock magazynu, dzisiejszy revenue, alerty.

2. Cross-module search — wpisujesz "zamowienie 12345" i widzisz je we wszystkich modulach z jednego miejsca.

3. Settings jako command center — juz mamy modules/settings, ale nie jest glownym wejsciem. Manager musi pamietac, gdzie co jest.

4. Role-based menu per user — pizzerowi pokazujemy tylko POS + KDS, managerowi wszystko. Dzis brak tej warstwy.

5. Multi-tenant panel — chain owner widzi wszystkie lokale z jednego miejsca. Dzis kazdy lokal osobno.

Rekomendacja: wybieramy droge Online (A/B/C), potem w Fazie 3 dodajemy modules/admin_hub/ — unified dashboard, ktory aggreguje dane ze wszystkich modulow. To jest odrebna sprawa i nie blokuje kierunku Online. Flagujemy do zrobienia, ale nie mieszamy.

Cz. 9 — Pytania do Ciebie (teraz, konkretnie)
Pytanie	Moja rekomendacja
Ktora droga: A / B / C?	B na start, C w Fazie 3 jako rozszerzenie. A tylko jesli decydujesz sie wplacac 3-4 miesiace pracy na raz.
Menu Studio Polish — teraz czy pozniej?	Teraz (1-2 tyg przed Online) — zeby rubryki i connect-dots modyfikator-do-warstwy byly porzadne.
Shot-lista dla fotografii skladnikow — potrzebna?	Tak — rozpisze Ci pelna instrukcje + checklist + wzory ustawienia studia, zanim zaczniesz strzelac.
Unifikacja rendererow (Director + Online) — jak?	SceneRenderer.js wydzielany jako wspolny modul. Wymaga refactoru w obu miejscach. 1-2 tyg pracy.
Admin hub — kiedy?	Faza 3 (po Online). Flagujemy juz teraz zeby nie zapomniec, ale nie blokuje Online.
Drzwi — na start zawsze?	Tak — zgodnie z Twoim zyczeniem. Niezaleznie od kierunku (A/B/C).
Magic Wand style per kategoria — ktora droga?	B: styl = zamiana LUT + ambient preset. C: dodatkowo zmiana kierunku swipe. A: pelen style override. Zacznijmy od B.
Co od Ciebie potrzebuje, zeby ruszyc
1. Wybor drogi: A, B, czy C?

2. Czy zgadzasz sie na Menu Studio Polish na wejsciu (1-2 tyg)?

3. Czy chcesz, zebym rozpisal shot-liste dla sesji zdjeciowej skladnikow (instrukcja krok po kroku)?

4. Czy zgadzasz sie, zebym opublikowal ten dokument do _docs/07_KIERUNEK_ONLINE.md jako oficjalna decyzja fazy?

Dopoki nie odpowiesz — nie dotykam kodu, nie modyfikuje dokumentacji, nie uruchamiam nic. Czekam.