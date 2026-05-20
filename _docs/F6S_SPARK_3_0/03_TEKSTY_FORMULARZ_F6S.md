# Teksty pod formularz F6S (Spark 3.0) — SliceHub

**Język:** polski (jeśli formularz ma pole „English”, na końcu jest blok EN).  
**Placeholdery:** `[IMIĘ]` `[EMAIL]` `[TELEFON]` `[URL]` `[KWOTA]` `[ETAP]` `[MIASTO]` — uzupełnij przed wysłaniem.

---

## 1. Krótki opis firmy / „Elevator pitch” (~350 znaków)

```
SliceHub Enterprise to multi-tenantowy OS gastronomii: POS, storefront, KDS, magazyn AVCO, natywny KSeF (API v2 MF), dashboard P&L (COGS+OPEX), dostawy i integracje w jednym modelie. Koszyk liczy serwer; omnichannel bez „płaskiej ceny”; magazyn↔menu przez SKU. Stack: Vanilla JS + PHP 8 + MariaDB — bez Node w runtime. Produkcja: slicehub.net. Spark 3.0: pilotaże sieciowe i domknięcie warstwy klienta (Counter + Living Table).
```

---

## 2. Średni opis (~1100 znaków) — typowe pole „Company / Product”

```
SliceHub Enterprise to gastronomiczny system operacyjny, nie kolejna kasa. Jedna platforma multi-tenant: sklep online (sceny wizualne + warianty), POS offline-first, kelner, stoliki, KDS, magazyn AVCO, skrzynka KSeF (e-faktury → PZ + OPEX), dashboard P&L, dyspozytornia i PWA kierowcy — 17 modułów na slicehub.net.

Architektura: macierz cen per kanał, variant scales (jedna receptura, mnożniki rozmiaru), serwerowy CartEngine, magazyn↔menu przez SKU. KSeF API v2: poll faktur, AutoScan linii, akceptacja magazynowa bez ręcznego przepisywania; koszty EXPENSE trafiają do P&L bez podwójnego liczenia PZ.

Logistyka: jeden silnik API kursów; payment lock u kierowcy; emergency recall. BI: COGS z WZ, payroll ledger, zamrożony kapitał ze stanów AVCO.

Integracje: outbox + adaptery (Papu, Dotykačka, GastroSoft). Stack: PHP 8, MariaDB, Vanilla JS — zero Node w runtime, 55 migracji SQL, 62 testy API.

Fundusze Spark 3.0: domknięcie storefrontu (Counter + Living Table) i pilotaże u operatorów sieciowych w CEE.
```

---

## 3. Długi opis (~2400 znaków) — „Tell us more” / załącznik tekstowy

```
PROBLEM
Sieci gastronomiczne i silne single-locale utknęły między „katalogiem online”, „kasa u dostawcy”, „Excel w magazynie” i „własnym skryptem pod dostawy”. Efekt: rozjazd cen między kanałami, brak jednej prawdy o stanie magazynu względem menu, oraz kosztowne integracje na zamówienie.

ROZWIĄZANIE
SliceHub Enterprise to zintegrowany OS restauracji: od pierwszego kontaktu klienta ze sklepem, przez przyjęcie i realizację zamówienia w lokalu lub kuchni, po magazyn i flotę dostaw — w jednym modelu danych i z twardą izolacją tenantów.

PRODUKT (MODUŁY — stan 2026-05-20)
Hub, Online storefront + Online Studio (Director), POS (PWA offline), Menu Studio (variant scales, subreceptury), Tables/Waiter, KDS, Warehouse V2, Procurement/KSeF Inbox, BI P&L, Courses + Driver PWA, Settings, Marketing, Inbox SMS, Kiosk HR, Backoffice kadry.

TECHNOLOGIA I BEZPIECZEŃSTWO BIZNESOWEGO
• Omnichannel: relacyjna macierz cen (osobno dla kanałów), brak pojęcia jednej „płaskiej ceny” w modelu.
• Zero zaufania do frontu: CartEngine na serwerze.
• Multi-tenant: każde zapytanie z barierą tenant_id — standard w kodzie i dokumentacji.
• Silosy danych biznes / słownik / magazyn połączone wyłącznie przez klucze znakowe (SKU), co utrudnia przypadkowe i niebezpieczne joiny między domenami.
• Kwoty operacyjne w groszach (integer) tam, gdzie dotyczy rozliczeń.

LOGISTYKA I OPERACJE
Jedno API dla kursów i kierowców; trzy filary stanu (zamówienie / płatność / dostawa) egzekwowane konsekwentnie. Payment lock i emergency recall to przykłady mechanizmów „anty-błędowych” opisanych w dokumentacji produktu.

INTEGRACJE
Outbox zdarzeń + worker webhooków + worker adapterów per provider, z tabelami dostaw i dead-letter — gotowe pod heterogeniczny krajobraz POS w CEE.

STACK WDROŻENIOWY
Vanilla JS + PHP 8 + MariaDB; hosting bez build-step w runtime. Dev-tooling opcjonalnie lokalnie z artefaktami commitowanymi do repo.

DLACZEGO SPARK 3.0
Szukamy ram programu, mentora sieciowego i dyscypliny go-to-market, żeby przełożyć działający produkt inżynierski na skalowalny pakiet wdrożeń (pilotaże, playbook, referencje).

TEAM
[IMIĘ NAZWISKO — rola] [e-mail] [telefon] [LinkedIn]
[Dopisz współzałożycieli / kluczowych advisorów jeśli są.]

KONTAKT
[EMAIL] · [TELEFON] · [STRONA / REPO PUBLICZNE JEŚLI DOTYCZY]
```

---

## 4. „What are you building?” (1–2 zdania, EN)

```
SliceHub is a multi-tenant restaurant operating system: omnichannel pricing, server-side cart, warehouse tied to recipes/modifiers, kitchen + POS + delivery dispatch in one modular PHP/JS/MariaDB stack—built for chains and high-throughput pizza/QSR.
```

---

## 5. Traction / milestones (uczciwie, bez liczb finansowych)

```
• Działający monorepo modułów (Hub, POS, Online, Studio, KDS, Tables, Waiter, Courses, Driver PWA, Warehouse, Settings) zmapowany w dokumentacji architektury.
• Konstytucja produktu v5 (m.in. zasady omnichannel, multi-tenant, integracji, drift guard między docs a kodem).
• Zunifikowany renderer scen (SSOT między Directorem a storefrontem) — część roadmapy online wdrożona.
• Integracje: outbox + adaptery pod wybranych operatorów POS + webhooki.
• Ścieżka wdrożeniowa na hostingu opisana krok po kroku (migracje, seed demo, smoke test).
```

---

## 6. Competitive advantage (EN, często wymagane)

```
1) Omnichannel price matrix as a first-class model—not a bolt-on. 2) Server-authoritative cart and inventory semantics (SKU-linked modifiers, waste, half-and-half). 3) One logistics engine with operational safety patterns. 4) Integration adapters + webhooks from the same event outbox. 5) Low ops cost: no Node in production runtime—Vanilla JS + PHP 8 + MariaDB.
```

---

## 7. „Why now?” (PL)

```
Presja kosztów surowca i pracy wymusza food cost w czasie rzeczywistym, a klient oczekuje spójnej ceny i śledzenia zamówienia niezależnie od kanału. Jednocześnie sieci w CEE mają mieszany krajobraz POS — potrzebują adapterów i outboxa, nie kolejnego „zamkniętego ogrodu”.
```

---

## 8. Social / App Store — gotowe zdania do pola „Notes”

```
Social: profil LinkedIn [URL] (TikTok — brak / w przygotowaniu).
Mobile: PWA Driver + Waiter w przeglądarce; brak publikacji w Apple App Store / Google Play — roadmapa natywna nie jest obietnicą na ten wniosek.
```

---

## 9. Finanse — szablony zdań (uzupełnij liczbami)

```
Raising: [TAK/NIE] · Stage: [pre-seed / seed / …] · Amount: [KWOTA + WALUTA] · Valuation: [KWOTA lub „cap on SAFE …”] · Raised to date: [0 lub SUMA] · Cap table: [bootstrap / wspólnicy / granty / dług] — szczegóły na żądanie pod NDA.
```

---

*Materiał roboczy; nie zawiera obietnic prawnych ani gwarancji przychodu.*
