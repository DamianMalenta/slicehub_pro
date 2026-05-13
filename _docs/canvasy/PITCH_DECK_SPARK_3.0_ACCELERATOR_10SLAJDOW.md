# SliceHub — Spark 3.0 · **10 slajdów** (3 min + Q&A)

**Format:** tytuł slajdu + **maks. 5 linii** na slajd (do wklejenia w Google Slides / Keynote).  
**Pełna wersja + notatki:** [`PITCH_DECK_SPARK_3.0_ACCELERATOR.md`](./PITCH_DECK_SPARK_3.0_ACCELERATOR.md)

---

### SLIDE 1 — Tytuł

**SliceHub Enterprise**  
*System operacyjny gastronomii: operacje, koszt, kanał własny*

- SaaS multi-tenant dla lokali i sieci: jedna prawda danych end‑to‑end.
- Nie „kolejny POS” — warstwa enterprise: menu, magazyn, logistyka, online.
- Vanilla JS + PHP + MariaDB w produkcji — przewidywalny deploy bez Node w runtime.

---

### SLIDE 2 — Problem

**Kasa działa, ale biznes się rozjeżdża**

- Menu, magazyn, delivery i strona w osobnych narzędziach → food cost po fakcie.
- Jedna płaska cena nie oddaje sali / wynosu / dostawy → marża na intuicji.
- Kanał własny często = szablon; brak głębokiego SKU ↔ magazyn ↔ paragon.
- Skala i sieci wymagają twardej izolacji tenantów i powtarzalnych procesów.

---

### SLIDE 3 — Rozwiązanie

**Jeden OS dla lokalu: ta sama baza dla kuchni, kasy i klienta**

- Bariera `tenant_id` w każdej operacji — bezpieczeństwo multi‑najemcy.
- Macierz cen omnichannel (sala, wynos, dostawa) — nie „jedna cena w CSV”.
- Receptury i modyfikatory spięte ze SKU magazynu — cyfrowy bliźniak kosztu.
- Koszyk i totaly liczy serwer (`CartEngine`) — frontend tylko wysyła SKU i ilości.

---

### SLIDE 4 — Produkt i przewaga techniczna

**Głęboki produkt + architektura pod skalę**

- Moduły: POS, KDS, Tables/Waiter, Warehouse (PZ/RW/INW…), Menu Studio, Courses + Driver PWA, Online + Director, Hub.
- Silosy DB `sh_` / `sys_` / `wh_` — mosty między domenami tylko po `sku` / `ascii_key`, nie po losowych ID.
- Menu czasowe (draft / live / okna ważności) — gastro w czasie, nie statyczny PDF.
- Stack „zero‑reload” na hostingu — niski TCO i przewidywalność dla SME.

---

### SLIDE 5 — Online (MVP zatwierdzony)

**Faza 2: Counter + Drzwi (droga B, `15_KIERUNEK_ONLINE.md`)**

- Drzwi: wejście marki, godziny, kanały, mapa, zamknięcie / pre‑order.
- Counter: swipe dań, warstwy składników, „stół” z dodatkami, cena live z API.
- Następnie: Restaurant Viewfinder (faza 3); pełny „film” scen — świadomie faza 5+.
- Cel: klient widzi **scenę restauracji**, nie grid SKU (North Star w `00_PAMIEC_SYSTEMU.md`).

---

### SLIDE 6 — Status (uczciwie)

**Nie slajd na idei — szeroki produkt, online dopracowywany**

- Warstwa operacyjna i backoffice: zaawansowana (mapa w `02_ARCHITEKTURA.md`).
- Director / studio scen: ~70% wg audytu wewnętrznego; storefront ~40% — praca pod F.
- Wspólny renderer sceny (`core/js/scene_renderer.js`) — mniej dryfu WYSIWYG vs klient.
- Proces: Konstytucja v5 + sesje w `_docs/sessions/` — kontrola „docs vs kod”.

---

### SLIDE 7 — Dlaczego my

**Moat w jednym slajdzie**

- Jedno repo prawdy: margin, magazyn i zamówienie w jednym silniku, nie integracja „na kablu”.
- Defensibility: kontrakty API, audytowalna warstwa danych, świadome cięcie „paint” w online (Prawo VII).
- Timing: presja surowcowa + kanał własny + compliance (KSeF / backoffice w roadmapie).
- *(Tu jedna liczba z rynku — uzupełnij po researchu z cytatem źródła.)*

---

### SLIDE 8 — Biznes + prosba

**B2B SaaS + wdrożenia; środki z programu na konkret**

- Abonament per lokal / pakiety modułów; upsell: onboarding menu i scen, integracje.
- **Spark:** Faza F (Counter + Living Table), pilotaże z success managerem, integracje płatności.
- Admin Hub / multi‑lokal — faza późniejsza (`00_PAMIEC_SYSTEMU.md`).
- **Kwota / equity:** *uzupełnij wg regulaminu Spark i cap table.*

---

### SLIDE 9 — Ryzyka

**Wiemy, gdzie jest ryzyko — mamy plan**

- Złożoność produktu → moduły „klocki lego”, jeden styl API (`switch` po akcji).
- Dryf dokumentacji → Prawo VIII + krótkie raporty sesji.
- UX online vs słabe telefony → etapowe ładowanie, brak ciężkiego SPA w prod.
- Zależność od pilotów → jasne milestone’y i metryki konwersji zamówienia.

---

### SLIDE 10 — Zamknięcie

**SliceHub: każda złotówka ma źródło w danych; klient widzi markę, nie szablon**

- Kontakt: *[email · telefon · strona www]*
- Załączniki: one‑pager, lista modułów, roadmapa F2–F3, *(opcjonalnie demo 60–90 s)*.

---

*Wersja skrócona 2026-05-11 — treść spójna z pełnym deckiem i `_docs`.*
