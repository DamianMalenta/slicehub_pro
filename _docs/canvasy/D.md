Analiza: jak zrobic The Surface lepiej
Uczciwy audyt obecnego planu (Living Table), 6 alternatywnych kierunkow, oraz framework ktory daje managerowi pelna kontrole a jednoczesnie gwarantuje, ze zadne surowe zdjecie nigdy nie dotrze do klienta.

3
Krytyczne ryzyka planu
6
Alternatywnych kierunkow
5
Bramek jakosci (QA Gates)
100%
Decyzji po stronie managera
TL;DR — co proponuje zmienic
1. Zamiast "auto-enhance na surowym zdjeciu" — przejsc na model Scene Kit: manager wrzuca tylko dobrze wyciety podmiot (food hero), a cala scenografia jest studyjna (pre-validowana, generowana lub z biblioteki). Zero ryzyka, ze tlo managera zepsuje kadr.

2. Dodac kinowa warstwe ruchu (parallax, Ken Burns, ambient light flicker, steam loops) — to daje "czuje sie jak w kinie" bez zaleznosci od jakosci foto.

3. Kazde zdjecie przechodzi przez 5-bramkowy pipeline jakosci, z ktorych 3 bramki sa w rekach managera (akceptuje/odrzuca/regeneruje). System nie moze niczego opublikowac bez ostatniej zgody managera.

4. Manager ma "Safety Net" — jesli foto jest bez ratunku, system oferuje AI-generated placeholder w stylu kategorii zamiast publikowac brzydote.

Cz. 1 — Gdzie obecny plan (Living Table) moze zawiesc
Jestem Twoim wspolnikiem, nie klakierem. Oto trzy realne ryzyka, ktore widze w naszej dotychczasowej koncepcji.

Photo consistency gap
Manager wrzuca pizze od fotografa (idealna) + pasta z telefonu (kiepska) + burger z Instagrama (inny styl). Auto-enhance nie wyrownuje tego do jednej konwencji — wyrownuje kolory, ale nie kompozycje, glebie, kat, jakosc.

Wynik: klient widzi niespojna galerie. "Cinema" wymaga spojnosci reżyserskiej.

Magic Wand hallucination
Style transfer (retro/anime/noir na realistycznym zdjeciu) brzmi super w prezentacji, ale w praktyce AI miesza anatomie dania (pepperoni przestaje wygladac jak pepperoni), kolory jedzenia przestaja byc apetyczne.

Wynik: zamiast zaskakiwac, mozemy odstraszyc. Anime pizza = ciekawostka, nie zamowienie.

Living Table cognitive load
"Sos zostaje na stole przy zmianie pizzy" to metafora elegancka, ale klient moze sie pogubic: czy ten sos juz jest w koszyku? Czy tylko lezy? Czy jak swipne, to znika? UX potrzebuje bardzo jasnej komunikacji.

Wynik: jesli klient zgubi orientacje, wychodzi. Konwersja to kluczowy wskaznik.

Cz. 2 — 6 alternatywnych kierunkow (nowe pomysly)
Kazdy z tych pomyslow mozna wdrozyc samodzielnie albo laczyc. Oceniam je w wymiarach: efekt "wow", ryzyko wdrozeniowe, zaleznosc od jakosci foto managera.

Scene Kit zamiast Surowego Tla
Manager wrzuca tylko wyciety food-hero (podmiot, bez tla). Cala scenografia to Scene Kit — studyjnie przygotowany pakiet (tlo PNG, rekwizyty PNG, swiatlo jako layer mask, LUT, grain, vignette). Klient widzi hero skomponowane w Kit.

Foto dania
+
Studyjny Kit
=
Spojna scena
Plus: gwarantowana spojnosc, niezaleznosc od tla managera, fotograf nie jest potrzebny

Minus: musimy przygotowac biblioteke 20-50 Scene Kitow (jednorazowa inwestycja produkcyjna)

Motion Surface — kinowa warstwa ruchu
Kazda scena ma subtelna ambient-animacje: Ken Burns (slow zoom/pan na hero), parallax glebokosci (3 warstwy roznych predkosci), loop pary nad gorocym daniem (AI-generated cinemagraph), lekkie drganie swiatla. To daje "cinema feel" bez zaleznosci od jakosci foto.

Wzor: Apple TV screensavery, Spotify Canvas, Instagram Motion Posts. Subtelne = nie irytuje, ale czujesz roznice.

Plus: najszybsza droga do "wow", uzupelnia kazdy inny pomysl

Minus: wymaga preloadu + uwaznej optymalizacji bateryjnej (mobile)

Mood Ring — scena reaguje na zdjecie
System wyciaga dominujace kolory z zdjecia dania i auto-tonuje cala scenografie pod nie. Pomidorowa pasta = cieple tlo ceglaste. Zielone pesto = chlodne lesno-oliwkowe. Auto-harmonizacja bez udzialu managera.

Wzor: Spotify Now Playing (tlo przejmuje kolor okladki), iOS 14 widgets color extraction.

Plus: zero dodatkowej pracy managera, automat dziala zawsze

Minus: efekt subtelny (latwy do przeoczenia), wymaga przemyslanej palety bazowej

Time-of-Day Storefront
O 11:00 witryna ma ranne, chlodne, apetyczne swiatlo i promuje lunche. O 19:00 — cieple, filmowe, komfortowe, promuje kolacje. O 22:00 — przytulne, nocne, comfort food. Bez kiwa palcem managera.

Wzor: Apple Watch tarcze reagujace na pore dnia, Netflix personalizacja hero.

Plus: za darmo dorzuca personalizacje bez danych uzytkownika

Minus: manager moze chciec nadpisac (np. noc swietokrzyska), potrzebny override

Composition Canvas — klient buduje posilek
Ewolucja Living Table: klient dosłownie przeciaga dania na wirtualny obrus (touch/drag). System automatycznie ustawia plates, napkin, szklo proporcjonalnie. Finalna kompozycja = photorealistic rendering zamowienia tego konkretnego klienta. Udostepnialne.

Gamifikacja: "Perfect Meal" badge za danie glowne + dodatek + napoj + deser. Social hook.

Plus: unikatowy UX, silny hook social, zwieksza wartosc koszyka (chce miec "pelny obraz")

Minus: wiecej pracy inzynierskiej (drag-drop na webgl), dluzsza droga do zamowienia

Chef Narrative — menu jako magazyn
Menu zorganizowane jak cyfrowy magazyn kulinarny: full-bleed zdjecia, typografia, krotkie "intro" kazdej kategorii ("Pizza — historia Neapolu od 1734"). Scroll-driven animacje. Manager wpisuje 2-3 zdania "why this dish" i system formatuje.

Wzor: Kinfolk magazine, Apple product pages, Airbnb Stories.

Plus: premium feel, wyrazne roznicowanie od konkurencji, edu-content buduje marke

Minus: wolniejsze skanowanie menu (moze zmniejszyc konwersje dla "szybkiego zamowienia")

Cz. 3 — Rekomendowany hybryd (co zlaczyc)
Nie wszystkie 6 naraz. Proponuje Scene Kit (1) + Motion Surface (2) + Time-of-Day (4) jako fundament, z opcjonalnym Composition Canvas (5) w Phase 10+. Mood Ring (3) i Chef Narrative (6) moga dojsc pozniej jako opcje managera.

Pomysl	Kiedy wdrozyc	Zaleznosci	Efekt dla klienta
Scene Kit (1)	Phase 2 (teraz)	Biblioteka 20 Kitow, sh_scene_kits w DB	Spojna, studyjna galeria
Motion Surface (2)	Phase 2-3	WebGL/CSS animations, cinemagraph generator	Kinowy ruch, {"cinema feel"}
Time-of-Day (4)	Phase 3	Cron + LUT switching	Witryna zyje w czasie
Mood Ring (3)	Phase 4 (opcja)	Color extraction worker	Subtelna harmonia kolorow
Composition Canvas (5)	Phase 10+	WebGL drag-drop, render farma	Unikat rynkowy, social
Chef Narrative (6)	Phase 6+ (opcja)	CMS dla tekstow managera	Premium positioning marki
Magic Wand z Ryzyka #2 zostaje — ale pracuje w ramach Scene Kitow (styl = inna paczka Kit), a nie na surowym zdjeciu. Dzieki temu nie powstaje anime-pepperoni: manager wybiera styl sceny (Kit), a foto hero zostaje realistyczne.

Cz. 4 — Quality Gate Pipeline (jak gwarantujemy brak surowki u klienta)
Kazde zdjecie wrzucone przez managera przechodzi przez 5 bramek, zanim moze byc opublikowane. 3 z 5 bramek wymagaja decyzji managera — nigdy nie publikujemy bez jego akceptacji.

GATE 1
SYSTEM
Upload + Ingest
System: weryfikuje rozmiar, EXIF, rozdzielczosc, usuwa metadane prywatne.
GATE 2
SYSTEM
AI Quality Check
System ocenia: ostrosc, ekspozycja, kadr, kontrast, kolor dominujacy. Wystawia score 0-100 + checklist.
GATE 3
MANAGER
Auto-Enhance + Preview
System generuje 3 warianty (neutral / warm / bright) z drop-shadow i composite na Scene Kit. Manager widzi kazdy w kontekscie.
GATE 4
MANAGER
Manager Decision
Manager wybiera wariant albo: {"retake"} / {"regenerate w innym Kit"} / {"uzyj AI-placeholder"}.
GATE 5
MANAGER
Publish Gate
Ostateczny preview {"tak bedzie wygladac u klienta"}. Manager klika {"opublikuj"}. Dopiero wtedy idzie live.
Co sie dzieje, jesli zdjecie jest zle?
Score < 40: System blokuje dalej. Pokazuje checkliste: "za ciemne", "rozmyte", "obcy obiekt w kadrze", "brak glownego tematu". Sugeruje retake z wskazowkami kamery.

Score 40-70: Mozna publikowac, ale system ostrzega. Manager widzi wyliczone ryzyka.

Score > 70: Zielone swiatlo. Auto-enhance + 3 warianty.

Bez ratunku (score < 20): Oferujemy "AI-generated placeholder w stylu Scene Kit dla tej kategorii". Manager decyduje, czy uzyc. Klient nigdy nie widzi brzydkiego foto.

Cz. 5 — Manager Control Framework (co moze decydowac)
Lista wszystkich decyzji, ktore zostaja w rekach managera. System proponuje, manager wybiera lub odrzuca.

Decyzja	Co proponuje system	Co moze zrobic manager	Domyslny efekt
Scene Kit dla kategorii	Rekomenduje 3 Kity pasujace do typu dan	Wybrac inny, stworzyc wlasny, zmodyfikowac istniejacy	Uzyty Kit zostaje przypiety do kategorii
Auto-enhance wariant	3 warianty (neutral / warm / bright)	Wybrac 1, odrzucic wszystkie, zamowic retake, zmienic parametry recznie	Wybrany wariant idzie do Gate 5
Tryb kategorii	Sugeruje flat-table / hero-wall na podstawie liczby dan i typu	Wymusic inny tryb, zmiksowac z custom	Tryb zapisany w sh_category_scene
Styl (Magic Wand)	Rekomenduje styl sceny (realistic / retro / noir...)	Zmienic styl, zablokowac zmiany sezonowe	Styl przypiety do kategorii lub dania
Time-of-Day overlay	Auto-zmienna paleta co 4h	Wlaczyc, wylaczyc, sztywno zablokowac na wybranym LUT	Overlay aktywny lub wylaczony
Placeholder AI	Oferuje, gdy foto nie nadaje sie do uzycia	Zaakceptowac, odrzucic, zamowic retake	Placeholder oznaczony jako {"tymczasowy"}
Publish	Nic — system nie publikuje nigdy sam	Jedyny aktor, ktory moze opublikowac	Sh_menu_items.published = 1
Unpublish / rollback	Loguje historie wersji fotki	Cofnac do poprzedniej wersji w 1 kliku	Klient widzi poprzednia wersje natychmiast
Cz. 6 — Smart Capture (pomagamy managerowi zrobic lepsze zdjecie)
"Nie kazdy manager jest fotografem" — wiec mobilna aplikacja Studio (lub PWA) ma tryb Smart Capture:

Live guidance
Overlay na kamerze: siatka kadru, wskaznik swiatla ("za ciemno", "za jasno"), wskaznik ostrosci, sugestia kata.

Wzor: apka Food Photography Studio, Instagram live shooting guides.

Template overlay
Polprzezroczysty obrys "gdzie ma byc danie" wg wybranego Scene Kit. Manager widzi, czy kompozycja pasuje.

Efekt: zdjecie juz na wejsciu jest dopasowane do Kit.

One-shot + retake
Automatyczny retake po zlym score. Manager nie spedza 30 minut w pipelinie — system po 2-3 probach proponuje placeholder.

Efekt: manager nigdy nie "utknie".

Cz. 7 — Decyzje do podjecia (pytania do Ciebie)
Zanim cokolwiek dopisze do planu i dokumentacji, potrzebuje Twoich odpowiedzi na kilka rozwidlen.

Decyzja	Opcja A (bezpieczna)	Opcja B (odwazna)	Moja rekomendacja
Scene Kit: biblioteka vs AI-generowane	Recznie zrobiona biblioteka 20-50 Kitow (inwestycja: 1 fotograf + 1 tydzien)	AI-generator Kitow w Studio (manager tworzy Kit pisemnym opisem)	A na start (kontrola jakosci), B jako rozszerzenie w Phase 5+
Motion Surface: wszystko czy opcja	Motion zawsze wlaczony (silne wow)	Manager wybiera per kategoria (performance + edge case)	B — manager decyduje, szybsze fallbacki na slabych urzadzeniach
Magic Wand: style dan czy tylko scen	Tylko style scen (tlo / swiatlo) — foto dania realistyczne	Pelne style transfer (anime-pepperoni)	A — zgodnie z Ryzykiem #2, chronimy apetycznosc
Living Table: pelne czy uproszczone	Stol z companions zostaje miedzy swipe (jak dzis planujemy)	Stol resetuje sie przy swipe — companions idą do koszyka bezposrednio	A w Phase 3, z mocnym user testingiem - jesli confusion, B
Smart Capture: wbudowane czy osobne	Wbudowane w Studio (PWA z kamera)	Osobna aplikacja mobilna (React Native)	A — szybciej na rynek, bez dodatkowego deploymentu
Placeholder AI: kiedy	Tylko na zadanie managera (guzik {"generate placeholder"})	Automatycznie gdy score &lt; 20, manager potwierdza	B — mniej klikow, ale zawsze z potwierdzeniem
Jak odpowiesz na te 6 pytan, wkleje nowa wersje wizji do _docs/06_WIZJA_MODULU_ONLINE.md i dopisze konkretna liste zadan do _docs/FAZA_2_PLAN.md. Jesli ktores pytanie wymaga deep-dive ("omow mi plusy-minusy Scene Kit vs foto-first") — powiedz, rozpisze osobny brief.