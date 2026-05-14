#!/usr/bin/env bash
# =============================================================================
# scripts/seed_pizzaforno_verify.sh
# Weryfikacja seeda Pizza Forno po wgraniu do bazy.
#
# Użycie:
#   bash scripts/seed_pizzaforno_verify.sh [database_name] [tenant_id]
#
# Domyślnie: database=slicehub_pro_v2, tenant_id=2
# Dla sandboxu: bash scripts/seed_pizzaforno_verify.sh seed_test
# =============================================================================

DB="${1:-slicehub_pro_v2}"
TID="${2:-2}"

# MariaDB connection (dostosuj jeśli potrzeba)
if mysql -u sh -psh "$DB" -e "SELECT 1;" > /dev/null 2>&1; then
    MYSQL="mysql -u sh -psh $DB"
elif mysql -u root "$DB" -e "SELECT 1;" > /dev/null 2>&1; then
    MYSQL="mysql -u root $DB"
else
    echo "❌ Nie można połączyć się z bazą $DB. Sprawdź dostęp."
    exit 1
fi

Q() { $MYSQL --batch --skip-column-names -e "$1" 2>/dev/null; }
QE() { $MYSQL -e "$1" 2>/dev/null; }

PASS=0
FAIL=0
WARN=0

ok()   { echo "  ✅ $1"; ((PASS++)); }
fail() { echo "  ❌ $1"; ((FAIL++)); }
warn() { echo "  ⚠️  $1"; ((WARN++)); }
sep()  { echo ""; echo "── $1 ──────────────────────────────────────────"; }

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  SliceHub Seed Verifier — Pizza Forno"
echo "  Baza: $DB | tenant_id: $TID"
echo "═══════════════════════════════════════════════════════════"

# ─── 1. MENU ITEMS ──────────────────────────────────────────────────────────
sep "1. Menu Items"

n_items=$(Q "SELECT COUNT(*) FROM sh_menu_items WHERE tenant_id=$TID AND is_deleted=0")
if [ "$n_items" -ge 190 ]; then
    ok "sh_menu_items: $n_items (≥ 190 wymagane)"
else
    fail "sh_menu_items: $n_items (WYMAGANE ≥ 190)"
fi

n_parents=$(Q "SELECT COUNT(*) FROM sh_menu_items WHERE tenant_id=$TID AND is_variant_parent=1")
if [ "$n_parents" -ge 30 ]; then
    ok "Variant parents (pizze + panini): $n_parents"
else
    fail "Variant parents zbyt mało: $n_parents (oczekiwane ≥ 30)"
fi

n_pizza30=$(Q "SELECT COUNT(*) FROM sh_menu_items WHERE tenant_id=$TID AND variant_option_id=(SELECT id FROM sh_variant_scale_options WHERE tenant_id=$TID AND key_ascii='30CM' LIMIT 1)")
n_pizza37=$(Q "SELECT COUNT(*) FROM sh_menu_items WHERE tenant_id=$TID AND variant_option_id=(SELECT id FROM sh_variant_scale_options WHERE tenant_id=$TID AND key_ascii='37CM' LIMIT 1)")
ok "Pizza 30cm variants: $n_pizza30 | 37cm variants: $n_pizza37"

# Sprawdź czy każdy parent ma przynajmniej 1 child
orphan_parents=$(Q "SELECT COUNT(*) FROM sh_menu_items p WHERE p.tenant_id=$TID AND p.is_variant_parent=1 AND NOT EXISTS (SELECT 1 FROM sh_menu_items c WHERE c.parent_item_id=p.id AND c.tenant_id=$TID)")
if [ "$orphan_parents" -eq 0 ]; then
    ok "Brak orphanowych parentów (każdy parent ma ≥ 1 child)"
else
    fail "Orphanowe parenty (bez dzieci): $orphan_parents"
fi

# Sprawdź czy każdy child ma istniejącego parenta
bad_parent_refs=$(Q "SELECT COUNT(*) FROM sh_menu_items c WHERE c.tenant_id=$TID AND c.parent_item_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM sh_menu_items p WHERE p.id=c.parent_item_id AND p.tenant_id=$TID)")
if [ "$bad_parent_refs" -eq 0 ]; then
    ok "Wszystkie parent_item_id referencje prawidłowe"
else
    fail "Nieprawidłowe parent_item_id referencje: $bad_parent_refs"
fi

# Sprawdź czy każdy variant_option_id istnieje
bad_option_refs=$(Q "SELECT COUNT(*) FROM sh_menu_items WHERE tenant_id=$TID AND variant_option_id IS NOT NULL AND variant_option_id NOT IN (SELECT id FROM sh_variant_scale_options)")
if [ "$bad_option_refs" -eq 0 ]; then
    ok "Wszystkie variant_option_id referencje prawidłowe"
else
    fail "Nieprawidłowe variant_option_id referencje: $bad_option_refs"
fi

# Sprawdź kategorie
n_cats=$(Q "SELECT COUNT(*) FROM sh_categories WHERE tenant_id=$TID AND name IN ('PIZZE','PANINI','CALZONE','MAKARONY','FOCCACIA','ZAPIEKANKI','GYROSY','SAŁATKI','SOSY','DESERY','DLA DZIECI','NAPOJE','PIWA','POZOSTAŁE','NOWOŚCI','ZIMOWE MENU')")
if [ "$n_cats" -ge 15 ]; then
    ok "Kategorie: $n_cats (oczekiwane 16)"
else
    warn "Kategorie: $n_cats (oczekiwane 16 — mogą być pre-existing)"
fi

# ─── 2. WARIANT SKALE ────────────────────────────────────────────────────────
sep "2. Variant Scales"

n_scales=$(Q "SELECT COUNT(*) FROM sh_variant_scales WHERE tenant_id=$TID AND key_ascii IN ('SCALE_PIZZA','SCALE_PANINI')")
if [ "$n_scales" -eq 2 ]; then
    ok "Variant scales: SCALE_PIZZA + SCALE_PANINI"
else
    fail "Variant scales: oczekiwane 2, jest $n_scales"
fi

n_opts=$(Q "SELECT COUNT(*) FROM sh_variant_scale_options WHERE tenant_id=$TID")
if [ "$n_opts" -eq 4 ]; then
    ok "Scale options: 4 (30CM, 37CM, MALE, DUZE)"
else
    fail "Scale options: oczekiwane 4, jest $n_opts"
fi

# ─── 3. MODIFIER GROUPS ──────────────────────────────────────────────────────
sep "3. Modifier Groups"

n_grps=$(Q "SELECT COUNT(*) FROM sh_modifier_groups WHERE tenant_id=$TID")
if [ "$n_grps" -ge 15 ]; then
    ok "Modifier groups: $n_grps (≥ 15)"
else
    fail "Modifier groups zbyt mało: $n_grps"
fi

n_mods=$(Q "SELECT COUNT(*) FROM sh_modifiers m JOIN sh_modifier_groups g ON g.id=m.group_id WHERE g.tenant_id=$TID")
if [ "$n_mods" -ge 100 ]; then
    ok "Modifiers: $n_mods (≥ 100)"
else
    fail "Modifiers zbyt mało: $n_mods"
fi

n_pricing=$(Q "SELECT COUNT(*) FROM sh_modifier_pricing WHERE tenant_id=$TID")
if [ "$n_pricing" -ge 100 ]; then
    ok "Modifier pricing (F-S2): $n_pricing rekordów"
else
    warn "Modifier pricing: $n_pricing (oczekiwane ≥ 100)"
fi

n_item_mods=$(Q "SELECT COUNT(*) FROM sh_item_modifiers im JOIN sh_menu_items mi ON mi.id=im.item_id WHERE mi.tenant_id=$TID")
ok "Item-modifier links: $n_item_mods"

# ─── 4. PRICE TIERS ──────────────────────────────────────────────────────────
sep "4. Price Tiers"

n_prices=$(Q "SELECT COUNT(*) FROM sh_price_tiers WHERE tenant_id=$TID AND target_type='ITEM'")
if [ "$n_prices" -ge 300 ]; then
    ok "Price tiers (ITEM): $n_prices (POS+Takeaway+Delivery per item)"
else
    fail "Price tiers zbyt mało: $n_prices"
fi

# Sprawdź czy nie ma itemów bez ceny
no_price=$(Q "SELECT COUNT(*) FROM sh_menu_items mi WHERE mi.tenant_id=$TID AND mi.is_variant_parent=0 AND NOT EXISTS (SELECT 1 FROM sh_price_tiers pt WHERE pt.tenant_id=$TID AND pt.target_type='ITEM' AND pt.target_sku=mi.ascii_key AND pt.channel='POS')")
if [ "$no_price" -eq 0 ]; then
    ok "Wszystkie sprzedawalne itemy mają cenę POS"
else
    warn "Itemy bez ceny POS: $no_price"
fi

# ─── 5. RECEPTURY ────────────────────────────────────────────────────────────
sep "5. Receptury (sh_recipes)"

n_recipes=$(Q "SELECT COUNT(*) FROM sh_recipes WHERE tenant_id=$TID")
if [ "$n_recipes" -ge 100 ]; then
    ok "Receptury: $n_recipes linii"
else
    warn "Receptury: $n_recipes (oczekiwane ≥ 100)"
fi

# Sprawdź czy wszystkie warehouse_sku istnieją w sys_items
bad_recipe_skus=$(Q "SELECT COUNT(*) FROM sh_recipes r WHERE r.tenant_id=$TID AND NOT EXISTS (SELECT 1 FROM sys_items s WHERE s.sku=r.warehouse_sku AND s.tenant_id=$TID)")
if [ "$bad_recipe_skus" -eq 0 ]; then
    ok "Wszystkie recipe.warehouse_sku istnieją w sys_items"
else
    fail "Nieprawidłowe warehouse_sku w recepturach: $bad_recipe_skus"
fi

# ─── 6. SYS_ITEMS ────────────────────────────────────────────────────────────
sep "6. sys_items (Słownik surowców)"

n_sys=$(Q "SELECT COUNT(*) FROM sys_items WHERE tenant_id=$TID")
if [ "$n_sys" -ge 60 ]; then
    ok "sys_items: $n_sys SKU (≥ 60)"
else
    fail "sys_items zbyt mało: $n_sys"
fi

# ─── 7. MAGAZYN (wh_stock) ───────────────────────────────────────────────────
sep "7. Magazyn (wh_stock)"

n_stock=$(Q "SELECT COUNT(*) FROM wh_stock WHERE tenant_id=$TID AND quantity > 0")
if [ "$n_stock" -ge 60 ]; then
    ok "wh_stock: $n_stock pozycji z qty > 0"
else
    warn "wh_stock: $n_stock pozycji (oczekiwane ≥ 60)"
fi

neg_stock=$(Q "SELECT COUNT(*) FROM wh_stock WHERE tenant_id=$TID AND quantity < 0")
if [ "$neg_stock" -eq 0 ]; then
    ok "Brak ujemnych stanów magazynowych"
else
    warn "Ujemne stany: $neg_stock pozycji"
fi

# ─── 8. DOKUMENTY PZ ────────────────────────────────────────────────────────
sep "8. Dokumenty PZ (wh_documents)"

n_pz=$(Q "SELECT COUNT(*) FROM wh_documents WHERE tenant_id=$TID AND doc_number LIKE 'PZ-2026/%/FORNO%'")
if [ "$n_pz" -ge 3 ]; then
    ok "PZ dokumenty: $n_pz (oczekiwane 5)"
else
    fail "PZ dokumenty: $n_pz (oczekiwane ≥ 3)"
fi

n_pz_lines=$(Q "SELECT COUNT(*) FROM wh_document_lines dl JOIN wh_documents d ON d.id=dl.document_id WHERE d.tenant_id=$TID AND d.doc_number LIKE 'PZ-2026/%/FORNO%'")
ok "PZ linie: $n_pz_lines"

# Sprawdź czy PZ linie mają prawidłowe SKU
bad_pz_skus=$(Q "SELECT COUNT(*) FROM wh_document_lines dl JOIN wh_documents d ON d.id=dl.document_id WHERE d.tenant_id=$TID AND d.doc_number LIKE 'PZ-2026/%/FORNO%' AND NOT EXISTS (SELECT 1 FROM sys_items s WHERE s.sku=dl.sku AND s.tenant_id=$TID)")
if [ "$bad_pz_skus" -eq 0 ]; then
    ok "Wszystkie PZ linie mają prawidłowe SKU (istnieją w sys_items)"
else
    fail "Nieprawidłowe SKU w PZ liniach: $bad_pz_skus"
fi

# ─── 9. KSeF INBOX ───────────────────────────────────────────────────────────
sep "9. KSeF Inbox (sh_ksef_invoices)"

n_ksef=$(Q "SELECT COUNT(*) FROM sh_ksef_invoices WHERE tenant_id=$TID AND invoice_number LIKE 'FA/FORNO/%'")
if [ "$n_ksef" -eq 3 ]; then
    ok "KSeF faktury: $n_ksef (nowa/accepted/processing)"
else
    fail "KSeF faktury: $n_ksef (oczekiwane 3)"
fi

ksef_new=$(Q "SELECT COUNT(*) FROM sh_ksef_invoices WHERE tenant_id=$TID AND invoice_number LIKE 'FA/FORNO/%' AND status='new'")
ksef_acc=$(Q "SELECT COUNT(*) FROM sh_ksef_invoices WHERE tenant_id=$TID AND invoice_number LIKE 'FA/FORNO/%' AND status='accepted'")
ksef_proc=$(Q "SELECT COUNT(*) FROM sh_ksef_invoices WHERE tenant_id=$TID AND invoice_number LIKE 'FA/FORNO/%' AND status='processing'")
ok "  Statusy: new=$ksef_new accepted=$ksef_acc processing=$ksef_proc"

n_ksef_lines=$(Q "SELECT COUNT(*) FROM sh_ksef_invoice_lines kl JOIN sh_ksef_invoices ki ON ki.id=kl.ksef_invoice_id WHERE ki.tenant_id=$TID AND ki.invoice_number LIKE 'FA/FORNO/%'")
ok "KSeF linie: $n_ksef_lines"

# Sprawdź powiązanie faktury 'accepted' z dokumentem PZ
linked=$(Q "SELECT COUNT(*) FROM sh_ksef_invoices WHERE tenant_id=$TID AND status='accepted' AND linked_wh_document_id IS NOT NULL AND invoice_number LIKE 'FA/FORNO/%'")
if [ "$linked" -ge 1 ]; then
    ok "Faktura 'accepted' powiązana z PZ dokumentem"
else
    fail "Brak powiązania faktury 'accepted' z PZ"
fi

# ─── 10. ZAMÓWIENIA ──────────────────────────────────────────────────────────
sep "10. Zamówienia (sh_orders)"

n_orders=$(Q "SELECT COUNT(*) FROM sh_orders WHERE tenant_id=$TID AND order_number LIKE 'FORNO-%'")
if [ "$n_orders" -eq 8 ]; then
    ok "Zamówienia: $n_orders (FORNO-001 do FORNO-008)"
else
    fail "Zamówienia: $n_orders (oczekiwane 8)"
fi

n_order_lines=$(Q "SELECT COUNT(*) FROM sh_order_lines ol JOIN sh_orders o ON o.id=ol.order_id WHERE o.tenant_id=$TID AND o.order_number LIKE 'FORNO-%'")
ok "Order lines: $n_order_lines"

# Sprawdź różnorodność statusów
statuses=$(Q "SELECT GROUP_CONCAT(DISTINCT status ORDER BY status SEPARATOR ',') FROM sh_orders WHERE tenant_id=$TID AND order_number LIKE 'FORNO-%'")
ok "Statusy zamówień: $statuses"

# Sprawdź czy totale się zgadzają
bad_totals=$(Q "SELECT COUNT(*) FROM sh_orders o WHERE o.tenant_id=$TID AND o.order_number LIKE 'FORNO-%' AND o.grand_total != o.subtotal + o.delivery_fee")
if [ "$bad_totals" -eq 0 ]; then
    ok "Sumy zamówień prawidłowe (grand_total = subtotal + delivery_fee)"
else
    fail "Nieprawidłowe sumy: $bad_totals zamówień"
fi

# ─── WYNIK KOŃCOWY ───────────────────────────────────────────────────────────
echo ""
echo "═══════════════════════════════════════════════════════════"

if [ "$FAIL" -eq 0 ]; then
    echo "✅ Seed Pizza Forno verified — $n_items items, $n_mods modifiers, $n_orders orders, $n_ksef invoices"
    echo "   PASS: $PASS | WARN: $WARN | FAIL: 0"
else
    echo "❌ Weryfikacja nieudana — $FAIL błędów, $WARN ostrzeżeń, $PASS OK"
    echo "   Sprawdź komunikaty powyżej."
fi

echo "═══════════════════════════════════════════════════════════"
echo ""

exit $FAIL
