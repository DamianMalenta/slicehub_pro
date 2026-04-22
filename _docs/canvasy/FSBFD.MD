Warstwy + Spojnosc — analiza mechaniki
Jak realnie wygladaja warstwy skladnikow, jak skladniki pojawiaja sie bez "latania i tanca", oraz jak rozwiazujemy najwieksze ryzyko calej koncepcji: spojnosc zdjec dan z Scene Kitem.

1
System warstw (tylko pizza)
300ms
Fade-in skladnika
3
Tryby auto-dopasowania
12
Sliderow manual override
Cz. 1 — Jak warstwy pojawiaja sie na pizzy
Ponizej zobacz pseudowizualizacje. To nie sa docelowe assety — pokazuja mechanike kompozycji warstw i ich skali.

Stage 1: Baza
Dough + sauce. To jest Scene Kit hero.

Stage 2: + Ser
Klient klika {"ser"}. Warstwa fade-in 300ms.

Stage 3: + Pepperoni (x1 = 6 plastrow)
Scatter pattern z pre-definiowanych kotwic.

Stage 4: + Pepperoni (x2 = 12) + Cebula
Podwojenie = kolejne 6 z rezerwy scatter.

Jak skladnik "wskakuje" — filozofia animacji
Powiedziales: nie chcesz latajacych skladnikow, tanczacej pani, wlatywania. Zgoda. Ponizej 5 opcji, ktore tego nie robia.

A) Cinematic dissolve
Warstwa fade-in 300ms z delikatnym scale 0.9 → 1.0. Wszystkie plastry pepperoni jednoczesnie.

Wzor: rack-focus w filmie. Kadr ten sam, nowy element wchodzi w focus.

B) Ghost materialize
Warstwa pojawia sie jako 40% opacity w 100ms, potem w 200ms dociaga do 100% + saturacja.

Wzor: gra Hades, efekt "spawn" z niewidzialnosci do pelnej obecnosci.

C) Light pulse reveal
Lekki blysk swiatla na sekundy w miejscu skladnika, skladnik wchodzi "w swiatlo". Blysk znika.

Wzor: efekt "jasny punkt" w Apple keynote slajdach.

D) Hard cut (film style)
Zero animacji. Skladnik pojawia sie w jednej klatce. Jak montazysta filmowy tnie ujecia.

Wzor: montaz Kubricka. Wszystko, co ma byc, jest od razu.

E) Steam reveal
Obloczek pary przeslania miejsce na 200ms, w tym czasie skladnik sie pojawia, para rozwiewa sie.

Wzor: food advertising, zwiastun gorocej pizzy.

F) Layer shuffle
Wszystkie skladniki jednoczesnie mikro-rotuja (±3 stopnie) i wyrownuja. Udaje to "ruch przy dodaniu".

Wzor: "Shake" feedback w iOS widgets.

Moja rekomendacja: A) Cinematic dissolve jako domyslne. Manager moze w Studio wybrac B-F per-kategoria (np. pizza = A, deser = E "steam" dla ciepla).

Scatter pattern — jak 6 plastrow trafia w "losowe" miejsca
Kazdy skladnik ma zdefiniowany w Studio scatter pattern: N kotwic z polem rotacji i skali. System rysuje z puli zaleznie od ilosci (x1 = 6, x2 = 12, extra = 16, light = 3).

Definicja scatter pattern w Studio (przyklad)
Manager w panelu Companions Directora ustawia:

- Liczba kotwic: 16 (rezerwa do rozkladania)

- Rozklad: grid perturbed 10% + avoid border 15%

- Wariancja rotacji: ±45 stopni (pepperoni moze byc obrocone)

- Wariancja skali: ±15% (rozne rozmiary plastrow, jak w prawdzie)

- Seed: order_id + ingredient_id (ta sama pizza = ten sam uklad przy refresh)

Cz. 2 — Problem spojnosci: czego Scene Kit NIE rozwiazuje
Masz racje, ze sie zastanawiasz. Scene Kit gwarantuje spojne tlo, ale nie gwarantuje, ze wrzucona fotka dania pasuje do tego tla. Honestly — to byla dziura w moim poprzednim planie.

Co moze sie nie zgadzac miedzy zdjeciem a Scene Kit
Kat kamery: Kit oczekuje top-down 90°. Manager strzela z 60°.

Balans bieli: Kit = zimne swiatlo z okna. Foto = zolta kuchenna zarowka.

Kierunek swiatla: Kit = swiatlo z lewej (cien na prawo). Foto = swiatlo z gory (brak bocznego cienia).

Kontrast i ekspozycja: Kit = kinowy, delikatny. Foto = flat albo przepalone.

Niechciane obiekty: dlon, widelec, serwetka, cien telefonu, czesc talerza.

Aspekt i crop: Kit wymaga 1:1. Foto to 4:3 z daniem w lewym dolnym rogu.

Glebia ostrosci: Kit zaklada plynny bokeh. Foto z telefonu ma wszystko w focusie.

Cz. 3 — 3 tryby auto-dopasowania (+ manual override)
Proponuje kaskade — system stara sie auto-dopasowac, a to co nie wyszlo, manager moze nadpisac recznie. Trzy poziomy, rosnaca glebokosc ingerencji.

Smart Suggest
System analizuje wrzucone zdjecie (kat, swiatlo, temp barwowa, aspekt) i sam proponuje 3 Scene Kity, ktore najlepiej do niego pasuja. Manager wybiera.

Backend: analiza obrazu CV (OpenCV / ONNX model) zwraca feature vector. Porownanie z feature vectorami wszystkich Kitow (euclid). Top-3 sa rekomendowane.

Efekt: manager nie walczy z Kitem — system sam dobiera najlepszy.

Auto-Normalize
Na wybranym Kicie, system poprawia foto do jego specyfikacji: deskew (prostuje kat), white-balance match, exposure match, letterbox do aspektu, subtelny relight.

Backend: kolejka Magic ("tone-map-to-kit", "auto-deskew", "background-remove", "composite-on-kit"). Kazdy krok ma before/after preview.

Efekt: zdjecie "wlasciwie pasuje" w 80% przypadkow bez recznej pracy.

Manual Override
Pelny panel sliderow: rotacja, skala, X/Y pozycja, warmth, brightness, contrast, saturation, shadow-intensity, highlight-rolloff, blur, grain. Manager widzi live preview na Kicie.

Backend: sluchacze na slidery przeliczaja preview realtime (GPU shader). Zapisane jako sh_menu_items.override_params.

Efekt: zaawansowany manager ma 100% kontrole.

Kaskada: Poziom 1 dziala zawsze (system proponuje Kit). Poziom 2 dziala po akceptacji Kit (system normalizuje). Poziom 3 jest opcjonalny — odpalany gdy manager klika Fine-tune w pipelinie z Gate 3.

Cz. 4 — Manual Override Panel (jak to wyglada dla managera)
Mockup interfejsu w Studio Director. Manager widzi live preview (lewa) i 12 sliderow pogrupowanych w 4 sekcje (prawa).

LIVE PREVIEW (Scene Kit + hero z override)
aktualizacja na kazdy ruch slidera · 60fps
POZYCJA I ORIENTACJA
Rotate (-180° do +180°)
Scale (50% do 150%)
Offset X (-50% do +50%)
Offset Y (-50% do +50%)
KOLOR I EKSPOZYCJA
Warmth (-100 do +100)
Exposure (-2EV do +2EV)
Contrast (0 do 150%)
Saturation (0 do 150%)
GLEBIA I DETAL
Shadow intensity (0 do 100%)
Highlight rolloff (0 do 100%)
Edge blur (0 do 8px)
Grain (0 do 30)
Plus 2 przyciski: Reset do auto (cofa wszystkie overrides, wraca do Smart Suggest + Auto-Normalize) oraz Save as Preset (zapisuje zestaw sliderow jako preset, do uzycia na innych daniach).

Cz. 5 — Category Grid Preview (spojnosc zbiorowa)
Zanim manager opublikuje cokolwiek, widzi cala kategorie jako siatke. Wszystkie dania w tym samym Kit, w tym samym kadrze. To jest koncowy test spojnosci — jesli jedno zdjecie odstaje, manager widzi to od razu.

Pizza #1
score 85 — OK
Pizza #2
score 85 — OK
Pizza #3
score 62 — warning
Pizza #4
score 85 — OK
Pizza #5
score 85 — OK
Pizza #6
score 85 — OK
Pizza #7
score 85 — OK
Pizza #8
score 85 — OK
kazde danie w tym samym Kit · manager widzi outliera (np. #3) i moze go osobno poprawic
Gate 5 (Publish) jest cala siatka na raz, nie per-danie. Nie da sie opublikowac kategorii z jednym rozjezdzonym daniem bez swiadomego potwierdzenia.

Cz. 6 — Pelen flow managera (od wrzutu do publish)
Krok	Aktor	Co sie dzieje	Co widzi manager
1. Upload	Manager	Wrzuca zdjecie (telefon, drag-drop, paste)	Progress bar + score 0-100 po zakonczeniu
2. AI Analysis	System	Mierzy kat, swiatlo, WB, aspekt, rozdzielczosc	Checklist: {"kat OK"}, {"WB cieple"}, {"za ciemne"}
3. Smart Suggest	System	Proponuje 3 Scene Kity pasujace	3 miniatury Kit + score dopasowania (A, B, C)
4. Kit Choice	Manager	Wybiera Kit z 3 lub szuka innego	Live preview w wybranym Kit
5. Auto-Normalize	System	Dopasowuje foto do Kit (WB, deskew, crop)	Before / After slider
6. Accept or Fine-tune	Manager	Akceptuje lub wchodzi w Manual Override	Decyzja: {"ok"} / {"popraw recznie"} / {"zamien Kit"}
7. Fine-tune (opcja)	Manager	Gra sliderami w panelu Manual Override	Live preview aktualizujacy sie w czasie rzeczywistym
8. Preview in Grid	System	Wstawia do category grid, przeliczajac outliery	Cala kategoria w kadrze, czerwone outliery
9. Publish Gate	Manager	Widzi dokladnie to, co zobaczy klient	Publish / Hold for review / Reject
10. Rollback (opcja)	Manager	Kazda publikacja wersjonowana	Historia: cofnij do poprzedniej w 1 kliku
Cz. 7 — Teraz Twoja kolej (pytania + zaproszenie)
1. Ktora animacja dla wskakiwania skladnika? A-F opisane powyzej (moja rekomendacja: A Cinematic dissolve jako default, manager moze zmienic per-kategoria).

2. Czy kaskada auto-dopasowania (3 poziomy: Smart Suggest → Auto-Normalize → Manual Override) brzmi sensownie? Czy chcesz, zeby poziom 3 byl zawsze widoczny czy tylko po klikniecu Fine-tune?

3. Twoje wlasne wyobrazenia, ktore wspomniales — rozpisz mi je. Lepiej wiedziec co masz w glowie, zanim wybierzemy kierunek. Moze sa lepsze od moich, albo sa elementy z Twoich pomyslow, ktorych ja nie uzyje.

4. Czy Category Grid Preview (cala kategoria w kadrze zanim publish) jako koncowy test spojnosci — wystarczy, czy chcesz dodac cos wiecej (np. porownanie z kategoriami konkurencji, heatmap obszarow niespojnych)?

Powiedz wszystko, co masz w glowie — to zmienia plan. Jesli Twoje wizje roznia sie od moich, to nie problem — dlatego robimy analize teraz, zanim padnie linia kodu.