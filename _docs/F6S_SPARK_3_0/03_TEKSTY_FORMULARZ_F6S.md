# Teksty pod formularz F6S (Spark 3.0) — SliceHub

**Język:** polski (jeśli formularz ma pole „English”, na końcu jest blok EN).  
**Placeholdery:** `[IMIĘ]` `[EMAIL]` `[TELEFON]` `[URL]` `[KWOTA]` `[ETAP]` `[MIASTO]` — uzupełnij przed wysłaniem.

---

## 1. Krótki opis firmy / „Elevator pitch” (~350 znaków)

```
SliceHub Enterprise to multi-tenantowy system operacyjny gastronomii: POS, sklep klienta, KDS, magazyn, dostawy i integracje w jednym modelu danych. Koszyk i ceny liczy wyłącznie serwer; omnichannel bez „płaskiej ceny”; magazyn spięty z menu przez SKU. Stack: Vanilla JS + PHP 8 + MariaDB — bez Node w runtime na hostingu. Logistyka z jednym silnikiem API i mechanizmami operacyjnymi. Szukamy partnerów Spark 3.0 pod domknięcie storefrontu i pilotaże sieciowe.
```

---

## 2. Średni opis (~1100 znaków) — typowe pole „Company / Product”

```
SliceHub Enterprise to gastronomiczny system operacyjny, nie kolejna kasa. Jedna platforma dla wielu lokali (multi-tenant): sklep online, POS, kelner, stoliki, wyświetlacz kuchenny, magazyn z dokumentami i kosztem surowca, dyspozytornia i PWA kierowcy.

Architektura biznesowa: macierz cen per kanał (sala, wynos, dostawa), temporalna publikacja menu, serwerowa kalkulacja koszyka (klient nigdy nie wysyła totala — tylko SKU i ilości). Magazyn łączy się z menu przez SKU: receptury, modyfikatory, odpady, half-and-half.

Logistyka opiera się na jednym silniku API: spójny model statusu zamówienia, płatności i dostawy; mechanizmy operacyjne jak blokada dostawy bez rozliczenia płatności czy awaryjne wezwanie kierowcy.

Integracje: kanoniczny event bus (outbox) do webhooków oraz adapterów pod popularne POS (np. Papu, Dotykačka, GastroSoft) z retry i historią dostaw.

Stack produkcyjny: PHP 8, MariaDB, Vanilla JS — bez Node w runtime na hostingu, co obniża koszt utrzymania i upraszcza wdrożenia na shared hostingu.

Roadmapa produktowa jest jawna w dokumentacji wewnętrznej (m.in. scenariusz Counter + Drzwi dla storefrontu). Fundusze z akceleratora planujemy przeznaczyć na domknięcie warstwy klienta końcowego i pilotaże u operatorów sieciowych.
```

---

## 3. Długi opis (~2400 znaków) — „Tell us more” / załącznik tekstowy

```
PROBLEM
Sieci gastronomiczne i silne single-locale utknęły między „katalogiem online”, „kasa u dostawcy”, „Excel w magazynie” i „własnym skryptem pod dostawy”. Efekt: rozjazd cen między kanałami, brak jednej prawdy o stanie magazynu względem menu, oraz kosztowne integracje na zamówienie.

ROZWIĄZANIE
SliceHub Enterprise to zintegrowany OS restauracji: od pierwszego kontaktu klienta ze sklepem, przez przyjęcie i realizację zamówienia w lokalu lub kuchni, po magazyn i flotę dostaw — w jednym modelu danych i z twardą izolacją tenantów.

PRODUKT (MODUŁY)
Front klienta (online), Studio menu i warstw wizualnych, POS, Tables/Waiter, KDS, Warehouse (PZ/RW/MM/Inwentaryzacja itd.), Dispatcher + Driver PWA, Settings (integracje, webhooks), Hub startowy, kadry/kiosk wg wdrożenia.

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
