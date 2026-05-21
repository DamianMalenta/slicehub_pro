# Checklist produkcji wideo (Product + Team)

## Środowisko demo

- [ ] Migracje + `setup_database.php` wykonane na docelowej domenie testowej.  
- [ ] **Jednorazowo** `seed_demo_all.php` — `_docs/DEPLOYMENT_HOSTING.md` Krok 6.  
- [ ] Konto owner: **zmień hasło** z domyślnego `password` przed nagraniem lub użyj **świeżego stagingu** bez wrażliwych danych produkcyjnych.  
- [ ] PINy demo (`1111` itd.) — **nie trzymaj** długiego zbliżenia na klawiaturze; możesz wkleić PIN poza kadrem.

## URL-e (podmień domenę)

| Moduł | Ścieżka (względem root deploy) |
|-------|--------------------------------|
| Hub | `/modules/hub/index.html` |
| Online | `/modules/online/index.html?tenant=1` |
| POS | `/modules/pos/index.html` |
| Studio | `/modules/studio/index.html` |
| Courses | `/modules/courses/index.html` |
| Settings | `/modules/settings/` (ścieżka startowa wg instalacji) |

## Sprzęt

- [ ] Rozdzielczość nagrania **1920×1080**.  
- [ ] Mikrofon **kardioidalny** USB lub pojemnościowy — min. 15 cm od ust.  
- [ ] **Pop filtr** jeśli masz.  
- [ ] Wyłącz Slack, Mail, Teams; tryb samolotowy na telefonie obok.

## Postprodukcja (minimalna)

- [ ] Loudness ok. **-16 LUFS** integracyjnie (YouTube i tak normalizuje — ważne: brak przesteru).  
- [ ] Ciemny pasek z logo na **0:54–1:00** jeśli nie masz motion graphics.  
- [ ] Miniaturka YouTube: Hub + napis „SliceHub Enterprise”.

## Upload

- [ ] YouTube lub Vimeo — **Unlisted** lub Public.  
- [ ] **Bez hasła.**  
- [ ] Link wklejony w F6S w polu Product / Team Video.

## Prawa

- [ ] Muzyka tylko z **licencją** (np. YouTube Audio Library) albo **bez muzyki** — najbezpieczniej dla pitchu B2B.
