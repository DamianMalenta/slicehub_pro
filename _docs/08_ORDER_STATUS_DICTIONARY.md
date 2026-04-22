# Kanoniczny słownik statusów zamówień

**Faza 6.2 — Status Flow Repair**
**Data wejścia w życie:** 2026-04-18

---

## 1. Rationale

System historycznie rozwinął się z dwoma konkurującymi słownikami statusów (`new/accepted/preparing/ready/completed` z jednego modułu vs. `pending/preparing/ready/delivered/cancelled` z innego), co wywoływało:

- zamówienia z `/api/orders/guest_checkout` (`status='new'`) nigdy nie trafiały do KDS (który szukał `status='pending'`),
- destrukcyjna migracja w `api/courses/engine.php` przy każdym uruchomieniu (przy braku kolumny `delivery_status`) zmieniała `new → pending`,
- `track_order` zwracał wartość `status='in_delivery'` jako element enum statusu, podczas gdy w schemie to była zawsze kolumna `sh_orders.delivery_status` (osobna od `sh_orders.status`).

Sesja 6.2 standardyzuje słownik w **jednym kanonicznym modelu**, którego zobowiązane są przestrzegać wszystkie nowe endpointy.

---

## 2. Model danych

Zamówienie ma **dwie ortogonalne kolumny statusowe**:

| Kolumna | Cel | Enum |
|---|---|---|
| `sh_orders.status` | Stan w pipeline **kuchennym / ogólnym**. Zawsze ustawione. | `new · accepted · preparing · ready · completed · cancelled` |
| `sh_orders.delivery_status` | Stan w pipeline **dostawy kierowcy**. `NULL` dla `dine_in`/`takeaway`. | `unassigned · queued · in_delivery · delivered` |

Dodatkowo `sh_orders.payment_status` obsługuje płatność (`to_pay · unpaid · cash · card · online_unpaid · online_paid · paid · refunded`) — poza zakresem tego dokumentu.

---

## 3. Kanoniczny pipeline

```
          ┌─────────┐   KDS.bump_order        ┌──────────┐
  CREATE  │   new   │────────────────────────▶│ accepted │
 (online, └─────────┘                         └──────────┘
  POS,                                               │
  kiosk)                                             │ KDS.bump_order
                                                     ▼
                                             ┌─────────────┐
                                             │  preparing  │
                                             └─────────────┘
                                                     │
                                                     │ KDS.bump_order
                                                     ▼
                                             ┌─────────┐
                                             │  ready  │◀── Dispatch.assign/dispatch
                                             └─────────┘       sets delivery_status=in_delivery
                                                │                  and driver_id
                                     ┌──────────┴────────┐
                                     │                   │
                     delivery_status=│                   │ order_type!='delivery'
                     'in_delivery'   │                   │ (customer pickup confirmation)
                                     ▼                   ▼
                               DELIVERY FLOW      ┌──────────────┐
                                     │            │  completed   │
                                     │            └──────────────┘
                                     ▼
                     ┌──────────────────────────────────────┐
                     │ delivery_status='delivered'          │
                     │ AND status='completed'               │
                     └──────────────────────────────────────┘
```

### Transitions — autorytatywna tabela

| From | To | Kto może | API |
|---|---|---|---|
| `new` | `accepted` | KDS / manager | `kds/engine.php#bump_order` |
| `new` | `cancelled` | Manager / system (timeout) | `pos/engine.php` / `courses/cancel_order` |
| `accepted` | `preparing` | KDS | `kds/engine.php#bump_order` |
| `preparing` | `ready` | KDS | `kds/engine.php#bump_order` |
| `ready` | `preparing` | KDS (recall) | `kds/engine.php#recall_order` |
| `ready` | `completed` | POS pickup confirmation (pickup/takeaway) | `pos/engine.php` |
| `ready` (+ `delivery_status=unassigned`) | `ready` (+ `delivery_status=in_delivery`) | Dispatcher | `courses/engine.php#dispatch` |
| `ready` (+ `delivery_status=in_delivery`) | `completed` (+ `delivery_status=delivered`) | Driver | `courses/engine.php#deliver_order` |
| any | `cancelled` | Manager (reason required) | `courses/engine.php#cancel_order` |

---

## 4. Reguły dla `track_order` (klient)

API `online/engine.php#track_order` zwraca **logical status** — zunifikowany stan dla interfejsu klienta. Reguły:

1. Jeśli `delivery_status='delivered'` → `logicalStatus='completed'` (nawet jeśli `status='ready'` z jakiegoś powodu).
2. Jeśli `delivery_status='in_delivery'` → `logicalStatus='in_delivery'` (nawet jeśli `status='ready'`).
3. W przeciwnym wypadku: `logicalStatus = status`.

Dla `order_type='takeaway'` / `'dine_in'` stage `in_delivery` jest **usuwany** z timeline (nie występuje w tym flow).

Odpowiedź zawiera:

```json
{
  "order": {
    "status": "in_delivery",        // ← logical, dla UI
    "rawStatus": "ready",           // sh_orders.status
    "deliveryStatus": "in_delivery" // sh_orders.delivery_status
  },
  "stages": [
    { "key": "new",          "label": "Otrzymane",        "reached": true  },
    { "key": "accepted",     "label": "Zaakceptowane",    "reached": true  },
    { "key": "preparing",    "label": "Przygotowanie",    "reached": true  },
    { "key": "ready",        "label": "Gotowe",           "reached": true  },
    { "key": "in_delivery",  "label": "W drodze",         "reached": true  },
    { "key": "completed",    "label": "Dostarczone",      "reached": false }
  ]
}
```

---

## 5. Rekord deprecacji

| Wartość | Status | Co robić |
|---|---|---|
| `status='in_delivery'` | **Deprecated** — przed 2026-04 niektóre ścieżki zapisywały `in_delivery` jako `status`. Auto-migracja w `courses/engine.php` przywraca `status='ready'` + `delivery_status='in_delivery'`. | Nie pisz. Czytaj ostrożnie (zastąp logical status). |
| `status='pending'` | **Legacy** — używane przez stary POS + `orders/accept.php`. Zostaje jako alias `new` dla systemów legacy. Nowe moduły używają `new`. | Czytaj jako `new`; nie pisz w nowych endpointach. |
| `payment_status='unpaid'` | Stare dane. Auto-migracja w `courses/engine.php` mapuje na `to_pay`/`online_unpaid`. | Czytaj — legacy. |

---

## 6. Lista modułów i ich zakres

| Moduł | Pisze | Czyta | Słownik |
|---|---|---|---|
| `api/orders/guest_checkout` (online) | `new` | — | kanoniczny |
| `api/pos/engine` (POS ekran zamówień) | `new`, `ready`, `completed` (+ normalizacja zapisu `pending`→`new`) | `new`/`pending`/pełny pipeline | kanoniczny (zapis) + odczyt legacy `pending` ✓ |
| `api/kds/engine` (KDS) | `accepted/preparing/ready` | `new/accepted/preparing` | kanoniczny ✓ |
| `api/courses/engine` (dispatcher) | `delivery_status=*`; `status='completed'` (deliver) | wszystkie statusy | kanoniczny ✓ |
| `api/online/engine#track_order` | — (read-only) | wszystkie + delivery_status; mapuje na logical | kanoniczny + logical mapping ✓ |

---

## 7. Roadmap — pozostała praca

- [x] **Sesja 7.1**: migracja `api/pos/engine.php` z `pending → new`. Ekran POS czyta oba (backward-compat), ale nowe zamówienia z POS piszą `new` (payload `pending` jest normalizowany do `new`). Przejście POS „PRZYGOTUJ”: `new`→`preparing` w `OrderStateMachine`.
- [ ] **Sesja 7.2**: sygnał realtime (SSE/WebSocket) track_order — zamiast pollingu co 5/15s.
- [ ] **Sesja 7.3**: formalne triggery DB bazujące na transitions table (audit-by-DB zamiast tylko audit-by-app).
