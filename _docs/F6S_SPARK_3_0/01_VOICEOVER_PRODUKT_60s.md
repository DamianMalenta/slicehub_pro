# Product Video — teleprompter PL (≤ 60 s)

**Format:** głos lektora (Ty) + nagranie ekranu. Tempo: **~2,6 sylaby na sekundę** (spokojny lektor PL).  
**Baza URL:** zamień `https://[TWOJA-DOMENA]/` na realny host z seedem (`seed_demo_all.php` — `_docs/DEPLOYMENT_HOSTING.md`).

---

## 0:00–0:60 — jeden ciągły blok (do czytania na jednym wdechu w częściach)

> SliceHub Enterprise to system operacyjny gastronomii: jedna platforma na sklep klienta, kasę, kuchnię, magazyn i dostawy.  
> Klient widzi storefront — wejście do marki i menu — zamiast płaskiego katalogu.  
> W kasie koszyk liczy serwer: Ty wybierasz dania, system przelicza cenę zgodnie z kanałem.  
> Ceny są omnichannel: sala, wynos i dostawa mogą się różnić — to jest w DNA produktu.  
> Logistyka łączy dyspozytora z aplikacją kierowcy: status zamówienia, płatność i dostawa są ze sobą zgodne.  
> Integracje idą przez jeden bus zdarzeń do webhooków i gotowych adapterów dla popularnych POS.  
> SliceHub: mniej chaosu narzędzi, więcej kontroli marży. Zobacz slicehub.pro.

**Długość odczytu:** ~52–58 s przy normalnym tempie. Jeśli masz zapas czasu, po „marży” dodaj półsekundy pauzy przed ostatnim zdaniem.

---

## Sekunda po sekundzie — co pokazywać na ekranie

| Czas | Ekran (sugerowany URL / moduł) | Uwaga operatorska |
|------|----------------------------------|-------------------|
| 0:00–0:06 | Hub `modules/hub/index.html` po zalogowaniu — kafelki modułów | Scroll lekki po kafelkach |
| 0:06–0:16 | Online `modules/online/index.html?tenant=1` — wejście, kanały, lista/menu | Klik „Wejdź” / scroll po daniach |
| 0:16–0:26 | POS — login `waiter1`, PIN z seeda (`1111`) — dodaj 1 pozycję, pokaż koszyk | **Zbliżenie na PIN max 1 s** lub zasłoń w postprodukcji |
| 0:26–0:36 | Studio `modules/studio/index.html` — drzewo menu **albo** pozycja z podglądem miniatury / receptury | Jeśli brak czasu: 3 s POS + 7 s Studio |
| 0:36–0:46 | Courses `modules/courses/index.html` — mapa lub lista kursów / kierowcy | Jeśli brak danych dostaw: pokaż UI + jedno zamówienie „ready” z listy |
| 0:46–0:54 | Settings `modules/settings/` — zakładka integracje / webhooks (bez ujawniania kluczy API) | Scroll po nazwach providerów |
| 0:54–0:60 | Slajd końcowy (Canva / Figma): logo + **slicehub.pro** + „SliceHub Enterprise” | Czytaj ostatnie zdanie bloku głosowego |

---

## Wersja angielska (opcjonalnie — międzynarodowi partnerzy)

> SliceHub Enterprise is a restaurant operating system: one platform for your storefront, POS, kitchen, warehouse, and delivery.  
> The customer sees a branded entry and menu—not a flat catalog.  
> The cart is always calculated on the server; you pick items, the system applies the right channel price.  
> Omnichannel pricing is built in: dine-in, takeaway, and delivery can differ by design.  
> Logistics connects dispatch with the driver app: order, payment, and delivery state stay consistent.  
> Integrations use one event bus to webhooks and adapters for common POS providers.  
> Less tool chaos, more margin control. slicehub.pro

---

## Check audio (przed ostatecznym REC)

- [ ] Poziom -12 … -6 dBFS szczytowo, brak przesteru.  
- [ ] Wyłączone powiadomienia, tryb „Nie przeszkadzać”.  
- [ ] Jedna sesja nagrania ekranu + osobny mikrofon (nie wbudowany z lapka na kolanach — szum).  
- [ ] Eksport **1080p**, **H.264**, dźwięk 48 kHz.
