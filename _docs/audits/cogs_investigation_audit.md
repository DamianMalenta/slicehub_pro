# Audyt: COGS z magazynu (P&L / BI) — `line_net_value`, `* 100`, `MIN(id)`

**Data:** 2026-05-14  
**Tryb:** wyłącznie odczyt kodu i migracji (brak zmian w logice produkcyjnej).  
**Zakres:** `core/WzEngine.php`, `core/PzEngine.php`, `core/WarehouseConsumeHook.php`, `database/migrations/001_init_slicehub_pro_v2.sql`, `core/WarehouseReverseHook.php` (kontekst wielu WZ).

---

## Obszar 1: Format pieniędzy w magazynie (`wh_document_lines`)

### Jakiego typu są `line_net_value` i `unit_net_cost`?

W inicjalizacji schematu (`001_init_slicehub_pro_v2.sql`):

| Kolumna          | Typ SQL                         |
|-----------------|----------------------------------|
| `unit_net_cost` | `DECIMAL(10,4) NOT NULL DEFAULT 0.0000` |
| `line_net_value`| `DECIMAL(10,2) NOT NULL DEFAULT 0.00`   |

Źródło: definicja `CREATE TABLE wh_document_lines` w `database/migrations/001_init_slicehub_pro_v2.sql` (ok. linie 633–645 w pliku inicjalizującym).

### Jak fizycznie zapisywana jest wartość?

- W warstwie **aplikacji** PHP używa typów `float` / `round(..., 2)` i przekazuje je do PDO → MariaDB zapisuje je jako **`DECIMAL`** (wartość dziesiętna, nie „natywne grosze jako INTEGER”).
- **WzEngine (WZ / zużycie na zamówienie):** wartość linii to `round(deductQty * currentAvco, 2)` — czyli **kwota w złotych z dokładnością do grosza (2 miejsca)** przy założeniu, że `current_avco_price` z `wh_stock` jest ceną jednostkową w tej samej walucie (PLN / jednostka magazynowa).

Fragment zapisu linii dokumentu WZ:

```586:614:core/WzEngine.php
            foreach ($deductions as $sku => $deductQty) {
                $deductQty = round($deductQty, 3);
                // ...
                $currentAvco = $stockRow ? (float) $stockRow['current_avco_price'] : 0.0;

                $lineValue = round($deductQty * $currentAvco, 2);
                $totalCost += $lineValue;

                $stmtDocLine->execute([
                    ':docId'    => $docId,
                    ':sku'      => $sku,
                    ':qty'      => $deductQty,
                    ':unc'      => $currentAvco,
                    ':lnv'      => $lineValue,
                    ':vat'      => 0.0,
                    ':oldAvco'  => $currentAvco,
                    ':newAvco'  => $currentAvco,
                ]);
```

**Odpowiedź wprost:** WzEngine **nie** zapisuje kosztu jako integerowych groszy w `wh_document_lines`. Zapisuje **złotówki z ułamkiem** (`DECIMAL`), spójnie z typem kolumny `DECIMAL(10,2)` dla `line_net_value` i `DECIMAL(10,4)` dla `unit_net_cost` / AVCO.

**PzEngine (PZ / przyjęcie towaru):** `line_net_value` jest liczone jako `round(quantity * unitNetCost, 2)` z payloadu `unit_net_cost` interpretowanego jako `float` — znowu model **dziesiętny PLN**, nie INT groszy.

```157:163:core/PzEngine.php
            $mappedLines[] = [
                'external_name'  => $externalName,
                'resolved_sku'   => $resolvedSku,
                'quantity'       => $quantity,
                'unit_net_cost'  => $unitNetCost,
                'line_net_value' => round($quantity * $unitNetCost, 2),
                'vat_rate'       => $vatRate,
            ];
```

### Porównanie ze „światem” `sh_orders`

W tym samym pliku migracji, **`sh_orders` i `sh_order_lines` używają `INT` z komentarzem `'Grosze'`** (np. `subtotal`, `grand_total`, `unit_price`, `line_total`). To jest **inny kontrakt danych** niż magazynowe `DECIMAL` na dokumentach `wh_*`.

**Wniosek architektoniczny (DDD / bounded contexts):** front zamówienia (SliceHub order) trzyma kwoty rozliczeniowe w **integer groszach**; magazyn (warehouse documents) trzyma **koszt jednostkowy i wartość linii dokumentu w DECIMAL** interpretowanym jako kwota pieniężna z ułamkiem. Most między kontekstami nie jest „automatycznie ten sam typ” — trzeba jawnej konwersji przy raportach.

### Czy `ROUND(line_net_value * 100)` ma sens? Czy zawyży koszt 100×?

- **`line_net_value` w bazie = już złote (DECIMAL(10,2)), nie „1/100 złotego`.**  
  Mnożenie przez **100** zamienia złote na **integerową reprezentację groszy** (np. `15.50` → `1550`) **tylko wtedy**, gdy dalsza część raportu **konsekwentnie** traktuje wynik jako grosze (i np. porównuje z `sh_orders.grand_total` też w groszach).
- Jeśli ktokolwiek zsumuje `ROUND(line_net_value * 100)` i **nadal** wyświetli / porówna wynik jako „złote PLN” bez podziału przez 100 — **COGS będzie zawyżony stukrotnie.** To nie jest „magia typu SQL”; to błąd warstwy prezentacji / jednostki.
- Jeśli celem było „naprawić” DECIMAL przez `* 100` pod pozorem, że w magazynie są już grosze jako małe liczby dziesiętne — **to jest błędne względem faktycznego zapisu WzEngine/PzEngine**: tam wartość jest już w pełnych złotych do 2 miejsc po przecinku (linia) lub koszt jednostkowy do 4 miejsc (AVCO / unit).

**Odpowiedź brutalna:** Samo `* 100` **nie** wynika z definicji tabeli `wh_document_lines`; jest **zewnętrzną** konwersją jednostki. Ma sens **wyłącznie** jako jawny bridge do świata `INT` groszy zamówień. Traktowanie go jako „korekty formatu bazy” przy COGS z WZ **bez ścisłego kontraktu jednostki na wyjściu** = klasyczny przepis na błąd rzędu **×100** albo **÷100** w złym miejscu.

---

## Obszar 2: Relacja zamówienie ↔ dokument WZ

### Jak `order_id` trafia na WZ?

**Bez tabeli pośredniej** w analizowanym przepływie: `WzEngine::consumeForOrder` wstawia nagłówek `wh_documents` z kolumną `order_id` ustawioną na UUID zamówienia (`CHAR(36)` — zgodnie ze schematem `wh_documents.order_id`).

Fragment:

```528:540:core/WzEngine.php
            $stmtDoc = $pdo->prepare("
                INSERT INTO wh_documents
                    (tenant_id, doc_number, type, warehouse_id, order_id, status, notes, created_by)
                VALUES
                    (:tid, '', 'WZ', :wid, :oid, 'approved', :notes, :uid)
            ");
            $stmtDoc->execute([
                ':tid'   => $tenantId,
                ':wid'   => $warehouseId,
                ':oid'   => $orderId,
                ':notes' => "Order: {$orderId}",
                ':uid'   => $userId,
            ]);
```

Hook produkcyjny tylko przekazuje ten sam `orderId` dalej:

```62:77:core/WarehouseConsumeHook.php
    public static function onOrderAccepted(
        PDO $pdo,
        int $tenantId,
        string $orderId,
        int $userId,
        ?string $warehouseId = null
    ): array {
        $resolvedWarehouseId = $warehouseId ?: self::resolveDefaultWarehouseId($pdo, $tenantId);

        try {
            $result = WzEngine::consumeForOrder(
                $pdo,
                $tenantId,
                $resolvedWarehouseId,
                $orderId,
                $userId
            );
```

**Uwaga terminologiczna:** w `wh_documents` jest kolumna `references_wz` (np. dla KOR), **nie** `reference_id` jako główny link zamówienia → WZ. Link zamówienia to **`order_id`**.

### Czy system dopuszcza wiele WZ na jedno zamówienie?

**Tak — warstwa odwrotna magazynu to explicite zakłada.**

`WarehouseReverseHook` szuka **wszystkich** dokumentów `WZ` z danym `order_id` i statusem `approved`, a w komentarzu przyznaje scenariusz wielokrotnej konsumpcji:

```49:71:core/WarehouseReverseHook.php
            $stmtWZ = $pdo->prepare(
                "SELECT id, warehouse_id
                   FROM wh_documents
                  WHERE tenant_id = :tid
                    AND order_id = :oid
                    AND type = 'WZ'
                    AND status = 'approved'"
            );
            // ...
            // Załóż 1 WZ na zamówienie (wzorzec WzEngine::consumeForOrder).
            // Jeśli pojawi się wiele (re-konsumpcja po edycji) — reverse wszystkie sumarycznie.
            $wzIds        = array_map(static fn($r) => (int)$r['id'], $wzDocs);
```

**Wniosek:** architektonicznie **nie** ma gwarancji „rygorystyczne 1:1” na poziomie bazy dla `(tenant_id, order_id) → pojedynczy WZ`. Wzorzec docelowy to **jeden WZ na akceptację** (`WzEngine` tworzy jeden dokument na wywołanie), ale **istnienie wielu WZ** jest przewidziane i obsługiwane przy anulowaniu (agregacja `SUM` po liniach wielu dokumentów).

`WzEngine::consumeForOrder` **nie** sprawdza przed `INSERT`, czy WZ dla zamówienia już istnieje — każde wywołanie tworzy **nowy** nagłówek i nowe linie. Z punktu widzenia idempotencji API akceptacji: `OrderStateMachine` nie pozwala ponownie wejść w `accepted` z `accepted` (ścieżki stanów w `core/OrderStateMachine.php`), więc **typowy** przepłyś POS/REST nie powinien wywoływać hooka dwukrotnie dla tego samego stanu — ale **baza i kod reverse** nie zakładają twardego singletonu WZ per order.

### Czy `MIN(id)` do „deduplikacji” WZ ma sens?

- Jeśli problemem są **duplikaty wierszy w wyniku JOIN-a** (jedna linia WZ powielona przez złączenie) — deduplikacja powinna iść po **kluczu logicznym linii** (np. `wh_document_lines.id`) lub po poprawnym modelu relacji, a nie po „minimalnym id dokumentu” obciętym do jednego WZ.
- Jeśli na zamówienie przypada **więcej niż jeden prawidłowy WZ** (jak wyżej), **`MIN(id)` na `wh_documents`** wybiera **tylko najstarszy** dokument i **wyrzuca koszt** z pozostałych WZ → **niedoszacowanie COGS**, a nie „bezpieczna deduplikacja”.
- Kod produkcyjny przy reverse **sumuje wiele WZ**; propozycja BI oparta o `MIN(id)` jest **sprzeczna** z tym wzorcem.

**Odpowiedź brutalna:** użycie `MIN(id)` jako proxy „jednego WZ na zamówienie” jest **na siłę** w świetle `WarehouseReverseHook` i **może być matematycznie fałszywe** przy wielu dokumentach. Jeśli duplikaty powstają wyłącznie z błędnego SQL, naprawą jest **JOIN / GROUP BY po właściwym kluczu**, nie wybór losowo-najstarszego dokumentu magazynowego.

---

## Podsumowanie jednym zdaniem

Magazynowy COGS z WZ jest w **`DECIMAL` PLN na linię dokumentu**; mnożenie przez **100** to **świadoma zmiana jednostki** w stronę modelu groszowego zamówień, a **`MIN(id)`** jest **niebezpiecznym skrótem** sprzecznym z obsługą **wielu WZ** już obecną w `WarehouseReverseHook`.
