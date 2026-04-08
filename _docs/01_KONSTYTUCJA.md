# KONSTYTUCJA SYSTEMU: SLICEHUB ENTERPRISE

Ten dokument określa nienaruszalne prawa architektury systemu SliceHub. Każdy programista oraz Agent AI pracujący przy tym kodzie ma bezwzględny obowiązek stosowania się do poniższych zasad. Złamanie tych reguł grozi krytycznym uszkodzeniem bazy danych.

## 1. PRAWO MACIERZY CENOWEJ (Omnichannel)
W systemie **nie istnieje pojęcie "płaskiej ceny"** (jednej ceny dla dania/modyfikatora).
- Wyjaśnienie definicji: **POS (Battlefield)** to główny panel operacyjny i terminal w restauracji. Nie jest on "kanałem cenowym" samym w sobie.
- Ceny zawsze funkcjonują w wielowymiarowej macierzy zależnej od **Kanału Sprzedaży**: np. **POS/Sala (Dine-in)**, **Wynos (Takeaway)**, **Dostawa (Delivery)**.
- Ceny nigdy nie są zapisywane jako płaska kolumna `price` w głównej tabeli dania. Zawsze używamy relacyjnej tabeli `sh_price_tiers` (lub struktury JSON w payloadzie) z flagami przypisanymi do konkretnych kanałów.
- Edycja masowa (Bulk) na jednym kanale nie może nadpisywać cen na pozostałych kanałach bez wyraźnego polecenia.

## 2. PRAWO BLIŹNIAKA CYFROWEGO (Zarządzanie Magazynem)
System kasy/menu to tylko front. Prawdziwa gastronomia dzieje się w magazynie (Food Cost).
- Opcje i modyfikatory to nie jest tylko "tekst na paragonie".
- Każdy modyfikator mający wpływ na surowce MUSI zachować logikę powiązania z bazą magazynową (KSeF).
- Akcje takie jak `ADD` lub `REMOVE` muszą zawsze wskazywać na kod surowca (`linked_warehouse_sku`) oraz jego dokładne zużycie ułamkowe (`linked_quantity`, np. 0.05).
- Nigdy nie usuwaj mechanizmów odczytu i zapisu tych wartości w interfejsie.

## 3. PRAWO CZWARTEGO WYMIARU (Temporal Tables)
Widoczność dań i kategorii jest kontrolowana przez zmienne czasowe.
- Używamy statusów publikacji: `Draft` (Szkic), `Live` (Opublikowane), `Archived` (Zarchiwizowane).
- Publikacja może być zaplanowana w czasie za pomocą zmiennych `valid_from` oraz `valid_to`.
- Zamiast usuwać rekordy z bazy (Hard Delete), zawsze preferujemy zmianę statusu na `Archived` (Soft Delete).

## 4. PRAWO ZERA ZAUFANIA (Walidacja i API)
Silnik API i Baza Danych to nasza twierdza.
- Żadne żądanie z Frontendu (JS) nie jest traktowane jako w 100% bezpieczne.
- Interfejs masowy wysyłający dane do API musi restrykcyjnie trzymać się struktury Payloadu (np. `omnichannelPricePatch`).

## 5. PROTOKÓŁ "KOPALNIA WIEDZY" (KOD LEGACY)
W systemie znajdują się foldery ze starym kodem (np. poprzednie wersje POS, Magazynu, Grywalizacji).
- **ZASADA BEZWZGLĘDNA:** Pliki te służą WYŁĄCZNIE jako materiał referencyjny i źródło logiki biznesowej. 
- Nigdy nie kopiuj starego kodu 1:1 do nowego systemu. Należy wyciągnąć z niego zasady, a sam kod napisać od nowa zgodnie z obecną architekturą i najnowszymi standardami API.

## 6. ZASADY ZMIANY KODU DLA AI
- AI ma zakaz przeprowadzania "globalnych optymalizacji" i usuwania nieznanych sobie funkcji z plików (tzw. halucynacje).
- Jeśli zmieniasz funkcję `A`, nie masz prawa dotykać funkcji `B` w tym samym pliku bez pytania.
- Przed każdą zmianą w UI sprawdź, jak dane mapują się na Backend API.