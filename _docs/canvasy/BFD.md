The Surface — kinowe zamawianie online
To nie jest strona z menu. To wirtualny, żyjący stół, na którym klient buduje swoją sesję kulinarną jak reżyser buduje scenę w studio filmowym.

2
Typy scen (Dish + Category)
7
Stylów Magic Wand
1
Zywy stol z pamiecia
0
Sztampowych UI
Wizja (cytaty z wczoraj, 2026-04-17)
"To nie moze byc ulepszona wersja konkurencji. To calkiem nowy wymiar zamawiania online z naciskiem na zwiekszanie wartosci koszyka."

"Moze cos w stylu gry na telefon, ale musimy zachowac czystosc, szybkosc dzialania, piekny interface. Klient nie moze sie zawiesic na godzine szukajac dan."

"Stol jest ten sam, tylko przesuwajac palcem w lewo prawo wymieniamy na nastepna pizze z listy — ale caly stol zostaje. Jesli klient juz wczesniej wrzucil na stol sos, sos zostaje."

"Magiczna rozdzka — wygeneruj scene w stylu retro, anime, pastelowym, realistycznym... To ma byc cos, co podbije technologicznie rynek zamowien online."

Zasada #1 — Zywy stol (The Living Table)
To jest serce calego systemu. Kazda akcja klienta (otwarcie kategorii, przesuniecie palcem, dodanie modyfikatora, dorzucenie polecanego produktu) zmienia jedna, dzielona scenografie. Stol nie znika miedzy widokami — ewoluuje.

Krok 1
Wejscie w kategorie PIZZA

stol (pusty)
PIZZA #1
Scena kategorii sie otwiera. Pusta scenografia + hero-danie.

Krok 2
Swipe w prawo

stol (ten sam)
PIZZA #2 (cross-fade)
Tylko danie sie wymienia. Tlo, swiatlo, rekwizyty zostaja.

Krok 3
Klient dorzuca SOS

stol + sos
PIZZA #2
Sos materializuje sie na stole (cross-sell z polecanej listy).

Krok 4
Zmiana zdania: PIZZA #5

stol + sos (nadal)
PIZZA #5
Sos zostaje. To decyzja koszyka — nie decyzja widoku.

Technicznie: stan stolu = globalny store (Zustand-like) z kluczami: stage (tlo, swiatlo, vignette, grain) · hero (aktywne danie) · companions (rzeczy dorzucone przez klienta) · slot_suggestions (sloty na polecajki). Swipe nie zeruje companions.

Zasada #2 — Dwa typy scen (decyzja managera w Studio)
CategoryScene — stol z wieloma daniami
Jedna scena = cala kategoria na jednym stole. Klient widzi np. 5 sosow rozlozonych na drewnianej desce, kazdy klikalny. Uzywamy dla kategorii, ktore maja sensownie zmiescic sie razem.

Typowo: Sosy · Napoje · Dipy · Dodatki proste

Sos A
Sos B
Sos C
Sos D
Sos E
ten sam stol, ten sam kadr
Template w DB: category_flat_table (migracja 023)

CategoryScene — slider w obrebie kategorii
Jedna scena = stol z jednym hero-daniem. Swipe zmienia danie na nastepne z kategorii. Uzywamy gdy danie zasluguje na hero-shot i polecajki dookola.

Typowo: Pizza · Makaron · Burger · Pastel · Deser

<
HERO: Pizza Margherita
+frytki
+cola
+sos
>
swipe lewo/prawo = nastepna pizza · companions zostaja
Template w DB: category_hero_wall (migracja 023)

Plus trzeci poziom: indywidualny DishScene per danie — override ustawien kategorii, kiedy danie zasluguje na unikalna prezentacje (np. signature pizza z custom light-setupem).

Zasada #3 — Magiczna rozdzka (Style Generator w Studio)
Manager zaznacza kategorie, wybiera styl, naciska jeden przycisk — silnik generuje kompletna scenografie (tlo, swiatlo, rekwizyty, LUT, paleta, vignette, grain, letterbox) zgodna z tym stylem. Zadnych checkboxow. Zadnego szablonu z kwiatkami.

Realistyczny
food editorial, miekkie okno

Retro
film 35mm, warm grain, sepia

Anime / manga
cel-shading, celebracja koloru

Pastelowy
candy-land, low contrast, soft

Noir
chiaroscuro, single rim light

Cyberpunk
neon teal/magenta, rain specular

Rysowany
ink + watercolor wash

Glamour
golden hour, macro, specular bokeh

Silnik: MagicColorGrade + MagicRelight + MagicBake + MagicDust + MagicCompanions (zaimplementowane w modules/online_studio/js/director/magic/). Rozdzka = orkiestrator, ktory wola je w odpowiedniej kolejnosci z presetem stylu.

Zasada #4 — Auto-enhance dla managera (nie fotograf)
Manager wrzuca zdjecie burgera z telefonu. Silnik robi w tle: background-remove · tone-map-to-kit · warm-boost · auto drop-shadow · auto-letterbox do aspect-ratio sceny. Zdjecie dociera do sceny juz dopasowane do stylu kategorii.

Kategoria	Scena	Oczekiwane zdjecie	Auto-enhance robi
pizza	Warstwowa (top-down)	Kazdy skladnik osobno	scatter generator + z-index stacking
pasta	Miska 3/4 kat	Cale danie w misce	background-remove + drop-shadow + warm-boost
burger	Hero-shot bokiem	Cale danie na deseczce	tone-map-to-kit + letterbox + rim-light sim
fries	Hero-shot gora-przod	Cale danie w koszyku	background-remove + drop-shadow
sauces	Flat table (top-down)	Zdjecie sosu w miseczce	background-remove + auto-center in grid slot
drinks	Hero-shot pionowy	Cale danie + kropelki	tone-map-to-kit + specular-boost
Gwarancja dla managera: manager nie musi umiec fotografowac. Magic robi 80% roboty. Jak zdjecie jest rozpaczliwie zle — Studio je po prostu odrzuca z czytelnym komunikatem (zbyt ciemne / brak kontrastu / brak centralnego tematu).

Architektura techniczna (co juz mamy, czego nie)
Zaimplementowane
database/migrations/020_director_scenes.sql — DishScene DB

022_scene_kit.sql — scene kit (presety plan/light/lut)

023_scene_templates_content.sql — 4 szablony dan + 2 kategorii

core/SceneResolver.php — resolver kategoria/danie override

core/AssetResolver.php — dwutorowe czytanie sh_assets + legacy

modules/online_studio/js/director/ — DirectorApp + 5 paneli

.../director/magic/ — 6 silnikow Magic (Bake, ColorGrade, Relight, Dust, Companions, Enhance)

api/online/engine.php — 4 akcje storefront (get_menu, get_scene itd.)

Brakujace (to jest roadmapa)
Magic Wand orkiestrator stylow — brak pojedynczego wejscia, ktore odpala caly lancuch

Surface renderer z persystencja stolu — jest szkielet online_renderer.js, brak store companions

Swipe gesture engine + cross-fade miedzy daniami w kategorii

Hot-slot dla polecajek (cross-sell) — klikniecie przedmiotu na stole = dodaj do koszyka

Pre-baked compositing pipeline — warstwy pizzy renderowane do jednego PNG raz, nie za kazdym requestem

Migracja 017_online_module_extensions.sql — jeszcze nie uruchomiona

Director: zakladka Style Wand + preset selector

Checkout 3-step z live order tracker (zalezy od Phase 8)

Mea culpa — gdzie zawalilem w poprzednim podsumowaniu
Wczesniej opisalem Ci to jako "CategoryScene z dwoma wariantami i unifikacja fotograficzna". To bylo technicznie prawda, ale kompletnie zgubilo sedno. Kazdy moglby przeczytac moj opis i zbudowac zwykly sklep z ladnymi zdjeciami. To nie jest to, co ustalilismy.

Co napisalem wczesniej	Co w rzeczywistosci ustalilismy
DishScene i CategoryScene (dwa typy scen)	Zywy stol, ktory pamieta companions miedzy swipe
Hybrid mode: jeden ekran albo slider	Slider zachowuje ten sam stol — rotuje sie tylko hero
Unifikacja fotograficzna	Auto-enhance + Magic dla managera, ktory nie jest fotografem
Magic wand dla sceny	Rozdzka generuje caly STYL (retro/anime/noir) per kategoria
Manager override per-danie	Plus: polecajki lezace wprost na stole jako cross-sell hook
(brak w moim opisie)	Klient jak w kinie — scenariusz pisany w Studio
Pierwsza kolumna to moje zle podsumowanie. Druga — to, co powinno bylo od razu w nim wladowac. Dzieki, ze mnie zlapales.

Co proponuje dalej
Wkleic ta wizje do _docs/06_WIZJA_MODULU_ONLINE.md jako rozdzial "Living Table" (nadpisujac obecny, zbyt tamy opis).

Dopisac do _docs/ustalenia.md wpis na dzis z 4 zasadami: Living Table · Dwa typy scen · Magic Wand · Auto-enhance.

Przed pisaniem kolejnego kodu: audyt modules/online/js/online_renderer.js pod katem persystencji stolu (czy store juz umie companions).

Nastepna sesja (7.7 lub "Phase 8 prep"): zaprojektowac Magic Wand orkiestrator jako pojedynczy wpis w Directorze.

Najpierw potwierdz, czy teraz lapie sedno. Jesli tak — puszczam dokumenty. Jesli cos dalej odbiega — poprawiamy, zanim trafi do _docs/.