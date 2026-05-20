# SliceHub Pro — opisy pod wniosek (język ludzki)

> Teksty do wklejenia w formularz F6S / SPARK 3.0. Bez kodu — dla osób oceniających wniosek.  
> **Autor:** Damian Malenta · **Produkcja:** https://slicehub.net · **Rewizja:** 2026-05-20

---

## Kim jestem i dlaczego to robię

Jestem **Damian Malenta**, solo founder. Sam projektuję, programuję i wdrażam SliceHub — nie dlatego, że „startup musi mieć zespół”, ale dlatego, że przez lata widziałem ten sam obraz w gastronomii: **pięć różnych programów, pięć haseł, zero jednej prawdy o marży**.

SliceHub powstał, żeby **właściciel pizzerii lub sieci mógł w jednym miejscu** przyjąć zamówienie (sala, wynos, dostawa, internet), zobaczyć co się dzieje w kuchni, wysłać kierowcę, przyjąć fakturę z KSeF i **wiedzieć, czy ten tydzień się opłaca** — bez Excela na koniec miesiąca.

---

## Dla kogo jest produkt

| Odbiorca | Co zyskuje |
|----------|------------|
| **Właściciel jednej pizzerii / burgerowni** | Mniej chaosu, szybsze faktury, kontrola cen per kanał |
| **Sieć 3–20 lokali** | Jedna platforma, osobne dane per lokal (multi-tenant) |
| **Operator z dostawą** | Dyspozytornia + aplikacja kierowcy + rozliczenie gotówki |
| **Lokal z KSeF od 2026** | Faktury dostawców bez przepisywania linijka po linijce |

Rynek startowy: **Polska**, ekspansja: **CEE** (Czechy, Słowacja, Rumunia) — podobne problemy, inne systemy fiskalne (roadmapa w SPARK).

---

## Co to jest SliceHub (jedno zdanie)

**SliceHub to system operacyjny restauracji** — nie kolejna kasa. Łączy sprzedaż, kuchnię, magazyn, dostawy, e-faktury i prosty raport rentowności w **jednej historii zamówienia**.

---

## Problem (jak to widzi właściciel)

1. **Rozjechane narzędzia** — kasa, sklep internetowy, magazyn i Excel do faktur nie rozmawiają ze sobą.  
2. **Inne ceny w różnych kanałach** — sala, wynos i delivery wymagają ręcznych obejść albo duplikowania menu.  
3. **KSeF od 2026** — faktury są cyfrowe, ale pracownik nadal przepisuje pozycje do magazynu (**ok. 15–20 minut na fakturę**).  
4. **Brak obrazu „czy zarabiam”** — przychód w kasie ≠ koszt surowca ≠ prąd ≠ pensje.

---

## Rozwiązanie (co zbudowałem)

### Jedna platforma zamiast pięciu subskrypcji

Po zalogowaniu właściciel widzi **Hub** — kafelki do wszystkich obszarów: kasa, sklep online, kuchnia (KDS), magazyn, faktury KSeF, dyspozytornia, aplikacja kierowcy, menu, kadry, raport P&L.

### Kasa, która naprawdę pracuje

POS obsługuje **salę, wynos i dostawę** z osobnymi cenami. W koszyku widać żywe zamówienia (nie pusty ekran testowy). System **działa też przy słabym internecie** — zamówienia nie giną, synchronizują się po powrocie sieci.

### Faktury KSeF bez „przepisywania do Excela”

Faktura z Krajowego Systemu trafia do **skrzynki**. System **sugeruje dopasowanie** pozycji do surowców w magazynie (np. mąka, ser, opakowania). Właściciel zatwierdza — **magazyn rośnie sam**, zamiast 20 minut ręcznej pracy.

**Ważne:** każda linia faktury może być oznaczona jako **surowiec do kuchni** albo **koszt operacyjny** (prąd, czynsz, marketing). Dzięki temu **prąd nie trafia podwójnie** — raz jako faktura, drugi raz jako „zakup sera”.

### Dostawy: dyspozytor widzi mapę, kierowca ma aplikację

Dyspozytornia na mapie pokazuje kierowców i zamówienia. Kierowca loguje się na telefonie (np. konto firmowe), widzi trasę, **nie może zamknąć dostawy bez rozliczenia płatności** — mniej „zgubionej gotówki”. W razie potrzeby dyspozytor może **wezwać kierowcę awaryjnie** na ekranie telefonu.

### Klient internetowy widzi status zamówienia

Po zamówieniu online klient może **śledzić postęp na stronie** (przygotowanie, w drodze) — bez dzwonienia do lokalu.

### Raport dla właściciela (bez analityka BI)

Moduł **P&L** pokazuje w prostych liczbach: przychód, koszt sprzedanych dań (z magazynu), koszty pracy, **koszty operacyjne z oznaczonych faktur** oraz **wartość zapasu na magazynie dziś** („zamrożony kapitał”).

### SMS bez abonamentu „bramki SMS”

Wiadomości do klientów mogą iść **przez telefon firmowy** (tańsze niż Twilio). Skrzynka odpowiedzi i proste auto-odpowiedzi (np. „gdzie jest zamówienie?”) odciążają telefon w godzinach szczytu.

---

## Czym się wyróżniam (vs. „kolejna kasa”)

| Wyróżnik | Korzyść dla biznesu |
|---------|---------------------|
| **Wszystko w jednym** | Jeden login, jedna historia zamówienia od WWW do kuchni |
| **KSeF + podział surowiec / koszt** | Zgodność z 2026 i sensowny P&L, nie tylko magazyn |
| **Ceny sala / wynos / delivery w DNA** | Nie trzeba trzymać trzech cenników w głowie |
| **Jedna receptura, wiele rozmiarów pizzy** | Mniej błędów w food cost przy zmianie rozmiaru |
| **Kasa offline** | Restauracja nie staje przy awarii internetu |
| **Niski koszt hostingu** | Działa na zwykłym hostingu — nie wymaga drogiej chmury devops |
| **Solo founder = spójna wizja** | Bez rozjazdu między działami produktu |

---

## Co już działa (dowód, nie obietnica)

- Publiczna instancja: **slicehub.net**  
- Pełne demo menu (Pizza Forno), zamówienia testowe, kierowcy na mapie, faktury demo w KSeF  
- **17 obszarów funkcjonalnych** (kasa, magazyn, KSeF, BI, dostawy, kuchnia, online…)  
- Produkt rozwijany w otwartej dokumentacji i na żywym hostingu — widać iteracje (np. POS z koszykiem i wariantami rozmiaru — wcześniej na nagraniu wniosku tego nie było widać; **materiały zostały odświeżone**)

---

## Po co SPARK 3.0

Szukam **ram akceleratora, mentora sieciowego i pierwszych płatnych pilotaży** u operatorów z prawdziwym ruchem — żeby przełożyć działający produkt na **powtarzalny pakiet wdrożeń** (playbook, referencje, wejście CEE), a nie kolejny rok „samego kodowania w próżni”.

Plan wykorzystania wsparcia (szkic): pilotaże w PL → materiały sprzedażowe → lokalizacja CZ/SK → pierwsze umowy abonamentowe.

---

## Elevator pitch (~350 znaków)

```
SliceHub to system operacyjny dla restauracji: jedna platforma na kasę, sklep online, kuchnię, magazyn, KSeF i dostawy. Faktury z KSeF trafiają do magazynu w minutach zamiast ręcznego przepisywania; właściciel widzi prosty P&L. Działa na slicehub.net. Szukam Spark 3.0 pod pilotaże i wejście na rynki CEE.
```

---

## Opis średni (~1100 znaków)

```
Gastronomia w Polsce utknęła między kasą, Excelem a pięcioma subskrypcjami. Zbudowałem SliceHub — jeden system dla właściciela pizzerii lub sieci: sprzedaż (sala, wynos, delivery, internet), kuchnia, magazyn z kosztem dania, dyspozytornia z aplikacją kierowcy oraz skrzynka e-faktur KSeF.

Każda faktura może być rozdzielona: surowce idą do magazynu, koszty typu prąd czy czynsz — do raportu rentowności, bez podwójnego liczenia. Kierowca nie zamknie dostawy bez rozliczenia płatności. Klient online widzi status zamówienia na stronie.

Produkt działa produkcyjnie na slicehub.net — nie mockup. Jestem solo founderem; cała architektura i UX powstały z obserwacji realnych lokali. Spark 3.0: pilotaże u operatorów, playbook wdrożeń, ekspansja CEE.
```

---

## Notatka do nowego wideo demo (60 s)

**Kolejność ujęć (ważne: pokazać POS z koszykiem!):**

1. Hub — „jeden ekran startowy”  
2. Sklep online — dania, rozmiar pizzy  
3. **POS — koszyk z 2–3 pozycjami, sidebar** (10 s minimum)  
4. KDS — tickety  
5. Dyspozytornia — mapa + kierowcy  
6. KSeF — lista faktur + jedna otwarta z podziałem surowiec/koszt  
7. BI — liczby P&L  
8. Slajd: slicehub.net + Damian Malenta

---

*Techniczne załączniki dla due diligence: `wniosek.md`, `wniosek_innowacje.md` — tylko jeśli komisja pyta o architekturę.*
