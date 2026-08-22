<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Unified Demo Seed (ALL MODULES)
//
// Run via browser: http://localhost/slicehub/scripts/seed_demo_all.php
// Run via CLI:     php scripts/seed_demo_all.php
//
// Creates a complete, coherent test dataset for:
//   POS, Studio, Warehouse, Courses/Dispatch, Driver App, KDS, Dashboard,
//   Procurement / KSeF Inbox, Menu Studio + Online (hero SVG, sceny, aliasy)
//
// WYMAGANE PRZED SEEDEM (świeża baza):
//   mysql … < database/migrations/001_init_slicehub_pro_v2.sql   # tylko pusta DB
//   php scripts/apply_migrations_chain.php
//
// SAFE TO RE-RUN: Uses ON DUPLICATE KEY UPDATE throughout.
// =============================================================================

// ──────────────────────────────────────────────────────────────
// Guard: SLICEHUB_SCRIPT_KEY (skip in CLI mode)
// ──────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    $localSecrets = __DIR__ . '/../core/local_secrets.php';
    if (is_file($localSecrets)) require_once $localSecrets;
    $expectedKey = defined('SLICEHUB_SCRIPT_KEY') ? (string) constant('SLICEHUB_SCRIPT_KEY') : '';
    $givenKey = (string)($_SERVER['HTTP_X_SCRIPT_KEY'] ?? $_GET['key'] ?? $_POST['key'] ?? '');
    if ($expectedKey === '' || !hash_equals($expectedKey, $givenKey)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['success' => false, 'message' => 'Brak/zły klucz dostępu (SLICEHUB_SCRIPT_KEY).']));
    }
}

require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/../core/Uuid.php';
require_once __DIR__ . '/lib/seed_search_aliases.php';
require_once __DIR__ . '/lib/seed_dish_visuals.php';

if (!isset($pdo)) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
}

$T = 1; // tenant_id
$results = [];
$ok = 0;
$fail = 0;

function seed(string $label, callable $fn): void {
    global $results, $ok, $fail, $pdo, $T;
    try {
        $msg = $fn($pdo, $T);
        $results[] = ['ok' => true, 'label' => $label, 'msg' => $msg ?? 'OK'];
        $ok++;
    } catch (Throwable $e) {
        $results[] = ['ok' => false, 'label' => $label, 'msg' => $e->getMessage()];
        $fail++;
    }
}

// Alias na SSOT — zachowany, bo skrypt przekazuje go przez `use ($uuid4)`
// do kilkunastu closure'ów seedowych.
$uuid4 = static fn(): string => Uuid::v4();

// Known bcrypt hash of "password" — used for ALL test accounts
$PW = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

// =============================================================================
// 0. SCHEMA PREFLIGHT (nie duplikuje apply_migrations_chain — tylko brakujące legacy)
// =============================================================================
seed('Schema preflight', function ($pdo) {
    $need = [];
    foreach (['sh_ksef_invoices', 'sh_meal_packages', 'sh_expense_categories'] as $tbl) {
        try {
            $pdo->query("SELECT 1 FROM {$tbl} LIMIT 0");
        } catch (Throwable $e) {
            $need[] = $tbl;
        }
    }
    if ($need !== []) {
        return 'Brak: ' . implode(', ', $need) . ' — uruchom: php scripts/apply_migrations_chain.php';
    }
    try {
        $pdo->query('SELECT 1 FROM sh_driver_locations LIMIT 0');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sh_driver_locations (
            driver_id BIGINT UNSIGNED NOT NULL, tenant_id INT UNSIGNED NOT NULL,
            lat DECIMAL(10,7) NOT NULL, lng DECIMAL(10,7) NOT NULL,
            heading SMALLINT NULL, speed_kmh DECIMAL(5,1) NULL, accuracy_m DECIMAL(6,1) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (tenant_id, driver_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return 'Utworzono sh_driver_locations (legacy); zalecany pełny chain';
    }
    return 'OK';
});

// =============================================================================
// 1. TENANT + SETTINGS
// =============================================================================
seed('Tenant + Settings', function ($pdo, $T) {
    $pdo->exec("INSERT INTO sh_tenant (id, name) VALUES ({$T}, 'SliceHub Pizzeria Poznań')
                ON DUPLICATE KEY UPDATE name = VALUES(name)");

    $settings = [
        ["''", 1, 0, 30, 10, 5, 25, 30, 'NULL'],
        ["'half_half_surcharge'", 'NULL','NULL','NULL','NULL','NULL','NULL','NULL', "'200'"],
        ["'currency'",           'NULL','NULL','NULL','NULL','NULL','NULL','NULL', "'PLN'"],
        ["'default_vat_dine_in'",'NULL','NULL','NULL','NULL','NULL','NULL','NULL', "'8'"],
        ["'default_vat_takeaway'",'NULL','NULL','NULL','NULL','NULL','NULL','NULL', "'5'"],
    ];
    foreach ($settings as $s) {
        $pdo->exec("INSERT INTO sh_tenant_settings (tenant_id, setting_key, is_active, min_order_value, min_prep_time_minutes, sla_green_min, sla_yellow_min, base_prep_minutes, min_lead_time_minutes, setting_value)
            VALUES ({$T}, {$s[0]}, {$s[1]}, {$s[2]}, {$s[3]}, {$s[4]}, {$s[5]}, {$s[6]}, {$s[7]}, {$s[8]})
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    }
    return '5 settings';
});

// =============================================================================
// 2. USERS — unique PINs, consistent roles
// =============================================================================
// Stawki godzinowe (int grosze) — Faza 4: źródłem prawdy jest sh_employee_rates,
// NIE sh_users.hourly_rate (kolumna zdropowana w migracji 061).
$HR_RATES_MINOR = [
    'manager' => 2800,
    'waiter1' => 2200,
    'waiter2' => 2200,
    'cook1'   => 2500,
    'driver1' => 2000,
    'driver2' => 2000,
    'team1'   => 1950,
];

seed('Users (8 accounts)', function ($pdo, $T) use ($PW) {
    $users = [
        [1, 'admin',   null,   'Administrator',   'Jan',    'Kowalski',  'owner'],
        [2, 'manager', '0000', 'Kierownik Anna',  'Anna',   'Nowak',     'manager'],
        [3, 'waiter1', '1111', 'Kelner Marek',    'Marek',  'Zieliński', 'waiter'],
        [4, 'waiter2', '2222', 'Kelnerka Ola',    'Ola',    'Wójcik',    'waiter'],
        [5, 'cook1',   '3333', 'Kucharz Piotr',   'Piotr',  'Mazur',     'cook'],
        [6, 'driver1', '4444', 'Kierowca Tomek',  'Tomek',  'Kaczmarek', 'driver'],
        [7, 'driver2', '5555', 'Kierowca Ania',   'Ania',   'Kowalczyk', 'driver'],
        [8, 'team1',   '6666', 'Pracownik Asia',  'Asia',   'Dąbrowska', 'team'],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO sh_users (id, tenant_id, username, password_hash, pin_code, name, first_name, last_name, role, status, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1)
         ON DUPLICATE KEY UPDATE pin_code=VALUES(pin_code), name=VALUES(name), first_name=VALUES(first_name),
           last_name=VALUES(last_name), role=VALUES(role), status='active', is_active=1"
    );
    foreach ($users as $u) {
        $stmt->execute([$u[0], $T, $u[1], $PW, $u[2], $u[3], $u[4], $u[5], $u[6]]);
    }
    return count($users) . ' users upserted';
});

// =============================================================================
// 3. CATEGORIES
// =============================================================================
seed('Categories (8)', function ($pdo, $T) {
    $cats = [[1,'Pizza',1],[2,'Burgery',2],[3,'Makarony',3],[4,'Sałatki',4],[5,'Napoje',5],[6,'Dodatki',6],[7,'Desery',7],[8,'Zestawy',8]];
    $stmt = $pdo->prepare("INSERT INTO sh_categories (id, tenant_id, name, is_menu, display_order) VALUES (?,?,?,1,?) ON DUPLICATE KEY UPDATE name=VALUES(name), display_order=VALUES(display_order)");
    foreach ($cats as $c) $stmt->execute([$c[0], $T, $c[1], $c[2]]);
    return count($cats) . ' categories';
});

// =============================================================================
// 4. MENU ITEMS (33 items)
// =============================================================================
seed('Menu Items (33)', function ($pdo, $T) {
    $items = [
        [1,1,'Margherita','PIZZA_MARGHERITA','PIZZA'],
        [2,1,'Pepperoni','PIZZA_PEPPERONI','PIZZA'],
        [3,1,'Capricciosa','PIZZA_CAPRICCIOSA','PIZZA'],
        [4,1,'Hawajska','PIZZA_HAWAJSKA','PIZZA'],
        [5,1,'Quattro Formaggi','PIZZA_4FORMAGGI','PIZZA'],
        [6,1,'Diavola','PIZZA_DIAVOLA','PIZZA'],
        [7,1,'Vegetariana','PIZZA_VEGETARIANA','PIZZA'],
        [8,1,'BBQ Chicken','PIZZA_BBQ_CHICKEN','PIZZA'],
        [9,1,'Prosciutto e Funghi','PIZZA_PROSC_FUNGHI','PIZZA'],
        [10,1,'Calzone','PIZZA_CALZONE','PIZZA'],
        [11,2,'Classic Burger','BURGER_CLASSIC','GRILL'],
        [12,2,'Cheese Burger','BURGER_CHEESE','GRILL'],
        [13,2,'BBQ Burger','BURGER_BBQ','GRILL'],
        [14,2,'Chicken Burger','BURGER_CHICKEN','GRILL'],
        [15,2,'Veggie Burger','BURGER_VEGGIE','GRILL'],
        [16,3,'Spaghetti Bolognese','PASTA_BOLOGNESE','PASTA'],
        [17,3,'Penne Carbonara','PASTA_CARBONARA','PASTA'],
        [18,3,'Lasagne','PASTA_LASAGNE','PASTA'],
        [19,4,'Sałatka Cezar','SALAD_CAESAR','COLD'],
        [20,4,'Sałatka Grecka','SALAD_GREEK','COLD'],
        [21,5,'Coca-Cola 0.5L','DRINK_COLA_05',null],
        [22,5,'Sprite 0.5L','DRINK_SPRITE_05',null],
        [23,5,'Woda mineralna 0.5L','DRINK_WATER_05',null],
        [24,5,'Sok pomarańczowy','DRINK_JUICE_ORANGE',null],
        [25,5,'Piwo Tyskie 0.5L','DRINK_BEER_TYSKIE',null],
        [26,6,'Frytki','SIDE_FRIES','GRILL'],
        [27,6,'Sos czosnkowy','SIDE_GARLIC_SAUCE',null],
        [28,6,'Krążki cebulowe','SIDE_ONION_RINGS','GRILL'],
        [29,6,'Nuggetsy 6szt','SIDE_NUGGETS_6','GRILL'],
        [30,7,'Tiramisu','DESSERT_TIRAMISU','COLD'],
        [31,7,'Panna Cotta','DESSERT_PANNA_COTTA','COLD'],
        [32,8,'Zestaw Lunch (pizza+napój)','SET_LUNCH_PIZZA','PIZZA'],
        [33,8,'Zestaw Burger+Frytki+Napój','SET_BURGER_COMBO','GRILL'],
    ];
    $isDrink = fn($sku) => str_starts_with($sku, 'DRINK_');
    $stmt = $pdo->prepare(
        "INSERT INTO sh_menu_items (id, tenant_id, category_id, name, ascii_key, `type`, is_active, display_order,
            publication_status, vat_rate_dine_in, vat_rate_takeaway, kds_station_id)
         VALUES (?,?,?,?,?,'standard',1,?, 'Live', ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            ascii_key=VALUES(ascii_key),
            name=VALUES(name),
            category_id=VALUES(category_id),
            is_active=1,
            publication_status='Live',
            display_order=VALUES(display_order),
            vat_rate_dine_in=VALUES(vat_rate_dine_in),
            vat_rate_takeaway=VALUES(vat_rate_takeaway),
            kds_station_id=VALUES(kds_station_id)"
    );
    foreach ($items as $i => $it) {
        $vatD = $isDrink($it[3]) ? 23.00 : 8.00;
        $vatT = $isDrink($it[3]) ? 23.00 : 5.00;
        $stmt->execute([$it[0], $T, $it[1], $it[2], $it[3], $i+1, $vatD, $vatT, $it[4]]);
    }
    return count($items) . ' items';
});

// =============================================================================
// 5. PRICE TIERS (ITEM + MODIFIER)
// =============================================================================
seed('Price Tiers (items)', function ($pdo, $T) {
    $prices = [
        ['PIZZA_MARGHERITA',24],['PIZZA_PEPPERONI',28],['PIZZA_CAPRICCIOSA',30],['PIZZA_HAWAJSKA',28],
        ['PIZZA_4FORMAGGI',32],['PIZZA_DIAVOLA',30],['PIZZA_VEGETARIANA',26],['PIZZA_BBQ_CHICKEN',32],
        ['PIZZA_PROSC_FUNGHI',30],['PIZZA_CALZONE',28],
        ['BURGER_CLASSIC',22],['BURGER_CHEESE',24],['BURGER_BBQ',26],['BURGER_CHICKEN',24],['BURGER_VEGGIE',22],
        ['PASTA_BOLOGNESE',26],['PASTA_CARBONARA',28],['PASTA_LASAGNE',30],
        ['SALAD_CAESAR',22],['SALAD_GREEK',20],
        ['DRINK_COLA_05',7],['DRINK_SPRITE_05',7],['DRINK_WATER_05',5],['DRINK_JUICE_ORANGE',8],['DRINK_BEER_TYSKIE',9],
        ['SIDE_FRIES',9],['SIDE_GARLIC_SAUCE',3],['SIDE_ONION_RINGS',10],['SIDE_NUGGETS_6',14],
        ['DESSERT_TIRAMISU',16],['DESSERT_PANNA_COTTA',14],
        ['SET_LUNCH_PIZZA',29],['SET_BURGER_COMBO',32],
    ];
    $stmt = $pdo->prepare("INSERT INTO sh_price_tiers (tenant_id, target_type, target_sku, channel, price) VALUES (?,'ITEM',?,?,?) ON DUPLICATE KEY UPDATE price=VALUES(price)");
    $n = 0;
    foreach ($prices as [$sku, $pos]) {
        $del = round($pos * 1.08, 2);
        $stmt->execute([$T, $sku, 'POS', $pos]); $n++;
        $stmt->execute([$T, $sku, 'Takeaway', $pos]); $n++;
        $stmt->execute([$T, $sku, 'Delivery', $del]); $n++;
    }
    return "{$n} price tiers";
});

// =============================================================================
// 6. MODIFIER GROUPS + MODIFIERS + LINKS
// =============================================================================
seed('Modifiers (2 groups, 7 mods)', function ($pdo, $T) {
    // Rozmiar pizzy i burgera obsługiwany przez warianty (variant_scale_options),
    // nie jako modyfikatory. Grupy 1 i 4 usunięte — zostają Dodatki i Sosy.
    $pdo->exec("INSERT INTO sh_modifier_groups (id,tenant_id,name,ascii_key,min_selection,max_selection,free_limit) VALUES
        (2,{$T},'Dodatki do pizzy','EXTRA_PIZZA',0,5,0),
        (3,{$T},'Sosy','SAUCES',0,3,1)
        ON DUPLICATE KEY UPDATE name=VALUES(name)");

    // Usuń stare grupy rozmiarów jeśli istnieją (id 1 i 4)
    $pdo->exec("DELETE FROM sh_modifier_groups WHERE tenant_id={$T} AND id IN (1,4)");
    $pdo->exec("DELETE FROM sh_modifiers WHERE group_id IN (1,4)");
    $pdo->exec("DELETE FROM sh_item_modifiers WHERE group_id IN (1,4)");
    $pdo->exec("DELETE FROM sh_price_tiers WHERE tenant_id={$T} AND target_type='MODIFIER' AND target_sku IN ('SIZE_S','SIZE_M','SIZE_L','SIZE_XL','BURG_STD','BURG_DBL')");

    $pdo->exec("INSERT INTO sh_modifiers (id,group_id,name,ascii_key,action_type,price,is_default) VALUES
        (5,2,'Podwójny ser','EXTRA_CHEESE','ADD',4.00,0),
        (6,2,'Jalapeno','EXTRA_JALAP','ADD',3.00,0),
        (7,2,'Oliwki','EXTRA_OLIVES','ADD',3.00,0),
        (8,2,'Szynka','EXTRA_HAM','ADD',5.00,0),
        (9,3,'Czosnkowy','SAUCE_GARLIC','ADD',2.00,0),
        (10,3,'BBQ','SAUCE_BBQ','ADD',2.00,0),
        (11,3,'Ostry','SAUCE_HOT','ADD',2.00,0)
        ON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price)");

    // Pizza items → dodatki, burger items → sosy
    $pdo->exec("INSERT IGNORE INTO sh_item_modifiers (item_id,group_id) VALUES
        (1,2),(2,2),(3,2),(4,2),(5,2),(6,2),(7,2),(8,2),(9,2),(10,2),
        (11,3),(12,3),(13,3),(14,3),(15,3)");

    $pdo->exec("INSERT INTO sh_price_tiers (tenant_id,target_type,target_sku,channel,price) VALUES
        ({$T},'MODIFIER','EXTRA_CHEESE','POS',4.00),({$T},'MODIFIER','EXTRA_JALAP','POS',3.00),
        ({$T},'MODIFIER','EXTRA_OLIVES','POS',3.00),({$T},'MODIFIER','EXTRA_HAM','POS',5.00),
        ({$T},'MODIFIER','SAUCE_GARLIC','POS',2.00),({$T},'MODIFIER','SAUCE_BBQ','POS',2.00),
        ({$T},'MODIFIER','SAUCE_HOT','POS',2.00)
        ON DUPLICATE KEY UPDATE price=VALUES(price)");

    return '2 groups (Dodatki + Sosy), 7 modifiers, 15 links';
});

// =============================================================================
// 7. WAREHOUSE — sys_items + wh_stock
// =============================================================================
seed('Warehouse (47 items + stock)', function ($pdo, $T) {
    $items = [
        ['MKA_TIPO00','Mąka Caputo Tipo 00','kg',50.0,3.85],
        ['SER_MOZZ','Ser Mozzarella Fior di Latte','kg',18.5,28.50],
        ['SOS_POM','Sos pomidorowy San Marzano','l',24.0,8.90],
        ['OLJ_OLIWA','Oliwa z oliwek Extra Virgin','l',6.0,32.00],
        ['DRZ_SUCHE','Drożdże suche instant','kg',2.0,18.00],
        ['SOL_MORSKA','Sól morska drobna','kg',5.0,2.50],
        ['PEPP_SALAMI','Pepperoni / Salami pikantne','kg',4.2,42.00],
        ['SZYNKA_PARM','Szynka parmeńska (Prosciutto)','kg',3.0,65.00],
        ['PIECZARKI','Pieczarki krojone','kg',6.0,12.00],
        ['CEBULA','Cebula biała','kg',8.0,3.50],
        ['ANANAS','Ananas plastry (puszka)','kg',3.5,14.00],
        ['SER_GORG','Ser Gorgonzola DOP','kg',2.0,55.00],
        ['SER_PARM','Parmezan (Grana Padano)','kg',1.8,72.00],
        ['SER_CHEDDAR','Ser Cheddar','kg',4.0,32.00],
        ['JALAPENO','Jalapeno krojone (słoik)','kg',1.5,28.00],
        ['OLIWKI_CZ','Oliwki czarne bez pestek','kg',2.0,24.00],
        ['KURCZAK','Filet z kurczaka','kg',10.0,22.00],
        ['SOS_BBQ','Sos BBQ','l',3.0,15.00],
        ['BULKA_BURG','Bułka burgerowa brioche','szt',80.0,1.80],
        ['WOLOWINA_M','Mięso wołowe mielone (burger)','kg',8.0,38.00],
        ['SALATA_RZY','Sałata rzymska','kg',3.0,12.00],
        ['POMIDOR','Pomidory świeże','kg',5.0,8.50],
        ['OGOREK_KIS','Ogórek kiszony','kg',4.0,9.00],
        ['SOS_CZOSN','Sos czosnkowy','l',5.0,18.00],
        ['SOS_OSTRY','Sos ostry (chili)','l',2.0,22.00],
        ['MAKARON_SPAG','Makaron Spaghetti','kg',10.0,6.50],
        ['MAKARON_PENN','Makaron Penne Rigate','kg',8.0,6.50],
        ['MAKARON_LAS','Płaty lasagne','kg',4.0,9.00],
        ['FETA','Ser Feta','kg',2.5,35.00],
        ['FRYTKI_MRZ','Frytki mrożone','kg',15.0,7.50],
        ['NUGGETS_MRZ','Nuggetsy mrożone','szt',120.0,0.95],
        ['COCA_COLA_05','Coca-Cola 0.5L','szt',48.0,2.80],
        ['SPRITE_05','Sprite 0.5L','szt',36.0,2.80],
        ['WODA_05','Woda mineralna 0.5L','szt',60.0,1.20],
        ['SOK_POM_1L','Sok pomarańczowy 1L','l',12.0,4.50],
        ['PIWO_TYSKIE','Piwo Tyskie 0.5L','szt',24.0,3.20],
        ['KRAZKI_CEB','Krążki cebulowe mrożone','kg',5.0,14.00],
        ['MASCARPONE','Mascarpone','kg',3.0,22.00],
        ['SMIETANKA_30','Śmietanka 30%','l',6.0,8.00],
        ['CUKIER','Cukier biały','kg',5.0,4.00],
        ['BAZYLIA_SW','Bazylia świeża (doniczka)','szt',10.0,4.50],
        ['PAPRYKA','Papryka świeża (czerwona/zielona)','kg',4.0,12.00],
        ['JAJKO','Jajka kurze (karton 30szt)','szt',60.0,0.40],
        ['BOCZEK','Boczek surowy wędzony','kg',3.0,24.00],
        ['KOTLET_WEG','Kotlet warzywny (sojowy)','szt',40.0,3.50],
        ['OPAK_PIZZA','Opakowanie karton pizza 32cm','szt',200.0,1.20],
        ['OPAK_BURGER','Opakowanie styro burger','szt',150.0,0.80],
    ];

    $stmtI = $pdo->prepare(
        "INSERT INTO sys_items (tenant_id,sku,name,base_unit,is_active,is_deleted)
         VALUES (?,?,?,?,1,0)
         ON DUPLICATE KEY UPDATE name=VALUES(name), base_unit=VALUES(base_unit), is_active=1, is_deleted=0"
    );
    $stmtS = $pdo->prepare("INSERT INTO wh_stock (tenant_id,warehouse_id,sku,quantity,current_avco_price,unit_net_cost) VALUES (?,'MAIN',?,?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity), current_avco_price=VALUES(current_avco_price)");

    foreach ($items as $it) {
        $stmtI->execute([$T, $it[0], $it[1], $it[2]]);
        $stmtS->execute([$T, $it[0], $it[3], $it[4], $it[4]]);
    }
    return count($items) . ' items + stock';
});

// =============================================================================
// 7b. SEARCH ALIASES (AutoScan — migracja 004, po sys_items)
// =============================================================================
seed('Search aliases (AutoScan)', function ($pdo) {
    try {
        $pdo->query('SELECT search_aliases FROM sys_items LIMIT 0');
    } catch (Throwable $e) {
        return 'Pominięto — brak kolumny search_aliases (chain 004)';
    }
    $n = seed_apply_search_aliases($pdo);
    return "{$n} aliasów z 004";
});

// =============================================================================
// 8. RECIPES
// =============================================================================
seed('Recipes (menu → warehouse)', function ($pdo, $T) {
    // Usuń stare linie demo — unikaj FK na nieistniejące ascii_key po zmianie menu
    $demoMenuSkus = [
        'PIZZA_MARGHERITA','PIZZA_PEPPERONI','PIZZA_CAPRICCIOSA','PIZZA_HAWAJSKA','PIZZA_4FORMAGGI',
        'PIZZA_DIAVOLA','PIZZA_VEGETARIANA','PIZZA_BBQ_CHICKEN','PIZZA_PROSC_FUNGHI','PIZZA_CALZONE',
        'BURGER_CLASSIC','BURGER_CHEESE','BURGER_BBQ','BURGER_CHICKEN','BURGER_VEGGIE',
        'PASTA_BOLOGNESE','PASTA_CARBONARA','PASTA_LASAGNE',
        'SALAD_CAESAR','SALAD_GREEK','SIDE_FRIES',
    ];
    $ph = implode(',', array_fill(0, count($demoMenuSkus), '?'));
    $del = $pdo->prepare(
        "DELETE FROM sh_recipes WHERE tenant_id = ? AND menu_item_sku IN ({$ph})"
    );
    $del->execute(array_merge([$T], $demoMenuSkus));

    $recipes = [
        ['PIZZA_MARGHERITA','MKA_TIPO00',0.25,2],['PIZZA_MARGHERITA','SER_MOZZ',0.20,0],['PIZZA_MARGHERITA','SOS_POM',0.10,0],['PIZZA_MARGHERITA','OLJ_OLIWA',0.015,0],['PIZZA_MARGHERITA','DRZ_SUCHE',0.003,0],['PIZZA_MARGHERITA','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_PEPPERONI','MKA_TIPO00',0.25,2],['PIZZA_PEPPERONI','SER_MOZZ',0.18,0],['PIZZA_PEPPERONI','SOS_POM',0.10,0],['PIZZA_PEPPERONI','PEPP_SALAMI',0.08,0],['PIZZA_PEPPERONI','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_CAPRICCIOSA','MKA_TIPO00',0.25,2],['PIZZA_CAPRICCIOSA','SER_MOZZ',0.18,0],['PIZZA_CAPRICCIOSA','SOS_POM',0.10,0],['PIZZA_CAPRICCIOSA','SZYNKA_PARM',0.06,0],['PIZZA_CAPRICCIOSA','PIECZARKI',0.05,0],['PIZZA_CAPRICCIOSA','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_HAWAJSKA','MKA_TIPO00',0.25,2],['PIZZA_HAWAJSKA','SER_MOZZ',0.18,0],['PIZZA_HAWAJSKA','SOS_POM',0.10,0],['PIZZA_HAWAJSKA','SZYNKA_PARM',0.06,0],['PIZZA_HAWAJSKA','ANANAS',0.06,0],['PIZZA_HAWAJSKA','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_4FORMAGGI','MKA_TIPO00',0.25,2],['PIZZA_4FORMAGGI','SER_MOZZ',0.12,0],['PIZZA_4FORMAGGI','SER_GORG',0.05,0],['PIZZA_4FORMAGGI','SER_PARM',0.04,0],['PIZZA_4FORMAGGI','SER_CHEDDAR',0.04,0],['PIZZA_4FORMAGGI','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_DIAVOLA','MKA_TIPO00',0.25,2],['PIZZA_DIAVOLA','SER_MOZZ',0.18,0],['PIZZA_DIAVOLA','SOS_POM',0.10,0],['PIZZA_DIAVOLA','PEPP_SALAMI',0.08,0],['PIZZA_DIAVOLA','SOS_OSTRY',0.03,0],['PIZZA_DIAVOLA','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_VEGETARIANA','MKA_TIPO00',0.25,2],['PIZZA_VEGETARIANA','SER_MOZZ',0.18,0],['PIZZA_VEGETARIANA','SOS_POM',0.10,0],['PIZZA_VEGETARIANA','PIECZARKI',0.05,0],['PIZZA_VEGETARIANA','PAPRYKA',0.04,0],['PIZZA_VEGETARIANA','CEBULA',0.03,0],['PIZZA_VEGETARIANA','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_BBQ_CHICKEN','MKA_TIPO00',0.25,2],['PIZZA_BBQ_CHICKEN','SER_MOZZ',0.18,0],['PIZZA_BBQ_CHICKEN','SOS_BBQ',0.08,0],['PIZZA_BBQ_CHICKEN','KURCZAK',0.08,0],['PIZZA_BBQ_CHICKEN','CEBULA',0.03,0],['PIZZA_BBQ_CHICKEN','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_PROSC_FUNGHI','MKA_TIPO00',0.25,2],['PIZZA_PROSC_FUNGHI','SER_MOZZ',0.18,0],['PIZZA_PROSC_FUNGHI','SOS_POM',0.10,0],['PIZZA_PROSC_FUNGHI','SZYNKA_PARM',0.06,0],['PIZZA_PROSC_FUNGHI','PIECZARKI',0.05,0],['PIZZA_PROSC_FUNGHI','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_CALZONE','MKA_TIPO00',0.25,2],['PIZZA_CALZONE','SER_MOZZ',0.18,0],['PIZZA_CALZONE','SOS_POM',0.10,0],['PIZZA_CALZONE','SZYNKA_PARM',0.06,0],['PIZZA_CALZONE','PIECZARKI',0.05,0],['PIZZA_CALZONE','JAJKO',0.02,0],['PIZZA_CALZONE','OPAK_PIZZA',1.0,0,1],
        ['BURGER_CLASSIC','WOLOWINA_M',0.18,3],['BURGER_CLASSIC','BULKA_BURG',1.0,0],['BURGER_CLASSIC','SALATA_RZY',0.03,0],['BURGER_CLASSIC','POMIDOR',0.04,0],['BURGER_CLASSIC','CEBULA',0.02,0],['BURGER_CLASSIC','OPAK_BURGER',1.0,0,1],
        ['BURGER_CHEESE','WOLOWINA_M',0.18,3],['BURGER_CHEESE','BULKA_BURG',1.0,0],['BURGER_CHEESE','SER_CHEDDAR',0.05,0],['BURGER_CHEESE','SALATA_RZY',0.03,0],['BURGER_CHEESE','POMIDOR',0.04,0],['BURGER_CHEESE','OPAK_BURGER',1.0,0,1],
        ['BURGER_BBQ','WOLOWINA_M',0.18,3],['BURGER_BBQ','BULKA_BURG',1.0,0],['BURGER_BBQ','SOS_BBQ',0.04,0],['BURGER_BBQ','CEBULA',0.03,0],['BURGER_BBQ','KURCZAK',0.08,0],['BURGER_BBQ','OPAK_BURGER',1.0,0,1],
        ['BURGER_CHICKEN','KURCZAK',0.15,3],['BURGER_CHICKEN','BULKA_BURG',1.0,0],['BURGER_CHICKEN','SALATA_RZY',0.03,0],['BURGER_CHICKEN','POMIDOR',0.04,0],['BURGER_CHICKEN','SOS_CZOSN',0.03,0],['BURGER_CHICKEN','OPAK_BURGER',1.0,0,1],
        ['BURGER_VEGGIE','KOTLET_WEG',0.15,3],['BURGER_VEGGIE','BULKA_BURG',1.0,0],['BURGER_VEGGIE','SALATA_RZY',0.03,0],['BURGER_VEGGIE','POMIDOR',0.04,0],['BURGER_VEGGIE','CEBULA',0.02,0],['BURGER_VEGGIE','OPAK_BURGER',1.0,0,1],
        ['PASTA_BOLOGNESE','MAKARON_SPAG',0.15,0],['PASTA_BOLOGNESE','WOLOWINA_M',0.12,3],['PASTA_BOLOGNESE','SOS_POM',0.12,0],['PASTA_BOLOGNESE','CEBULA',0.03,0],
        ['PASTA_CARBONARA','MAKARON_PENN',0.15,0],['PASTA_CARBONARA','BOCZEK',0.08,0],['PASTA_CARBONARA','SER_PARM',0.04,0],['PASTA_CARBONARA','JAJKO',0.04,0],
        ['PASTA_LASAGNE','MAKARON_LAS',0.10,0],['PASTA_LASAGNE','WOLOWINA_M',0.12,3],['PASTA_LASAGNE','SOS_POM',0.10,0],['PASTA_LASAGNE','SER_MOZZ',0.10,0],['PASTA_LASAGNE','SER_PARM',0.03,0],
        ['SALAD_CAESAR','SALATA_RZY',0.15,5],['SALAD_CAESAR','KURCZAK',0.10,0],['SALAD_CAESAR','SER_PARM',0.03,0],['SALAD_CAESAR','OLJ_OLIWA',0.02,0],
        ['SALAD_GREEK','FETA',0.08,0],['SALAD_GREEK','POMIDOR',0.08,0],['SALAD_GREEK','OGOREK_KIS',0.06,0],['SALAD_GREEK','CEBULA',0.03,0],['SALAD_GREEK','OLJ_OLIWA',0.02,0],
        ['SIDE_FRIES','FRYTKI_MRZ',0.25,5],
    ];
    $chk = $pdo->prepare(
        "SELECT 1 FROM sh_menu_items WHERE tenant_id = ? AND ascii_key = ? LIMIT 1"
    );
    $stmt = $pdo->prepare(
        "INSERT INTO sh_recipes (tenant_id,menu_item_sku,warehouse_sku,quantity_base,waste_percent,is_packaging)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE quantity_base=VALUES(quantity_base), waste_percent=VALUES(waste_percent)"
    );
    $ins = 0;
    $skip = 0;
    foreach ($recipes as $r) {
        $chk->execute([$T, $r[0]]);
        if (!$chk->fetchColumn()) {
            $skip++;
            continue;
        }
        $pkg = $r[4] ?? 0;
        $stmt->execute([$T, $r[0], $r[1], $r[2], $r[3], $pkg]);
        $ins++;
    }
    $msg = "{$ins} recipe lines";
    if ($skip > 0) {
        $msg .= " (pominięto {$skip} — brak menu_item_sku; uruchom ponownie od kroku Menu Items)";
    }
    return $msg;
});

// =============================================================================
// 9. PRODUCT MAPPING + MODIFIER WAREHOUSE LINKS
// =============================================================================
seed('Product Mapping + Modifier links', function ($pdo, $T) {
    // supplier_nip + pack_* (m058/m059) — unikalny klucz (tenant, nip, external_name)
    $nipMakro = '5252311234';
    $nipGastro = '7780012345';
    $nipNapoje = '9511111111';

    $maps = [
        [$nipMakro, 'Mąka pszenna Caputo "00"', 'MKA_TIPO00', null, null],
        [$nipMakro, 'Mozzarella Fior di Latte 1kg', 'SER_MOZZ', 1.0, 'kg'],
        [$nipMakro, 'Passata pomidorowa S.Marzano 2.5L', 'SOS_POM', 2.5, 'l'],
        [$nipGastro, 'Oliwa extra vergine Ferrini 5L', 'OLJ_OLIWA', 5.0, 'l'],
        [$nipGastro, 'Bazylia świeża P_7A op. 20G', 'BAZYLIA_SW', 0.02, 'kg'],
        [$nipNapoje, 'Coca-Cola 0.5L x24 zgrzewka', 'COCA_COLA_05', null, null],
        [$nipNapoje, 'Woda Żywiec 0.5L x12', 'WODA_05', null, null],
    ];

    $hasPack = false;
    try {
        $pdo->query('SELECT pack_qty_base FROM sh_product_mapping LIMIT 0');
        $hasPack = true;
    } catch (Throwable $e) {
        // m058 nie zastosowany
    }

    if ($hasPack) {
        $stmt = $pdo->prepare(
            "INSERT INTO sh_product_mapping (tenant_id, supplier_nip, external_name, internal_sku, pack_qty_base, pack_invoice_unit)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE internal_sku=VALUES(internal_sku),
               pack_qty_base=VALUES(pack_qty_base), pack_invoice_unit=VALUES(pack_invoice_unit)"
        );
        foreach ($maps as $m) {
            $stmt->execute([$T, $m[0], $m[1], $m[2], $m[3], $m[4]]);
        }
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO sh_product_mapping (tenant_id, external_name, internal_sku)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE internal_sku=VALUES(internal_sku)"
        );
        foreach ($maps as $m) {
            $stmt->execute([$T, $m[1], $m[2]]);
        }
    }

    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='SER_MOZZ', linked_quantity=0.1 WHERE ascii_key='EXTRA_CHEESE'");
    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='JALAPENO', linked_quantity=0.03 WHERE ascii_key='EXTRA_JALAP'");
    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='OLIWKI_CZ', linked_quantity=0.03 WHERE ascii_key='EXTRA_OLIVES'");
    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='SZYNKA_PARM', linked_quantity=0.05 WHERE ascii_key='EXTRA_HAM'");
    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='SOS_CZOSN', linked_quantity=0.03 WHERE ascii_key='SAUCE_GARLIC'");
    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='SOS_BBQ', linked_quantity=0.03 WHERE ascii_key='SAUCE_BBQ'");
    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='SOS_OSTRY', linked_quantity=0.03 WHERE ascii_key='SAUCE_HOT'");

    return count($maps) . ' mappings (NIP+dostawca), 7 modifier links';
});

// =============================================================================
// 9b. KSEF INBOX (demo faktury — status draft / accepted / error)
// =============================================================================
seed('KSeF Inbox (3 demo invoices)', function ($pdo, $T) {
    try {
        $pdo->query('SELECT 1 FROM sh_ksef_invoices LIMIT 0');
    } catch (Throwable $e) {
        return 'Pominięto — uruchom apply_migrations_chain.php (046+)';
    }

    $pdo->exec(
        "DELETE l FROM sh_ksef_invoice_lines l
         INNER JOIN sh_ksef_invoices i ON i.id = l.ksef_invoice_id
         WHERE i.tenant_id = {$T} AND i.invoice_number LIKE 'FA/DEMO/%'"
    );
    $pdo->exec("DELETE FROM sh_ksef_invoices WHERE tenant_id = {$T} AND invoice_number LIKE 'FA/DEMO/%'");

    $nipMakro = '5252311234';
    $nipGastro = '7780012345';

    $hasLineType = false;
    try {
        $pdo->query('SELECT line_type FROM sh_ksef_invoice_lines LIMIT 0');
        $hasLineType = true;
    } catch (Throwable $e) {
    }

    $hasNorm = false;
    try {
        $pdo->query('SELECT qty_normalized FROM sh_ksef_invoice_lines LIMIT 0');
        $hasNorm = true;
    } catch (Throwable $e) {
    }

    // 1) draft — do akceptacji w Inbox (linie z mapowaniem + P_7A bazylia)
    $pdo->prepare(
        "INSERT INTO sh_ksef_invoices
            (tenant_id, ksef_reference_id, supplier_nip, supplier_name, supplier_address,
             buyer_nip, buyer_name, invoice_number, issue_date, sale_date, payment_due_date,
             currency, total_net_minor, total_vat_minor, total_gross_minor,
             status, status_message, fetched_at)
         VALUES (?, 'KSEF-DEMO-REF-001', ?, 'Makro Cash & Carry Sp. z o.o.',
                 'ul. Hurtowa 1, Poznań', '7790000001', 'SliceHub Pizzeria Poznań',
                 'FA/DEMO/2026/001', CURDATE() - INTERVAL 2 DAY, CURDATE() - INTERVAL 2 DAY,
                 CURDATE() + INTERVAL 14 DAY, 'PLN', 65965, 3298, 69263,
                 'draft', NULL, NOW() - INTERVAL 1 HOUR)"
    )->execute([$T, $nipMakro]);
    $inv1 = (int)$pdo->lastInsertId();

    $lines1 = [
        [1, 'Mąka pszenna Caputo "00"', 25.0, 'kg', 385, 9625, 5.0, 'MKA_TIPO00', 'ALIAS', 92],
        [2, 'Mozzarella Fior di Latte 1kg', 10.0, 'szt', 2850, 28500, 5.0, 'SER_MOZZ', 'ALIAS', 90],
        [3, 'Passata pomidorowa S.Marzano 2.5L', 6.0, 'szt', 890, 5340, 5.0, 'SOS_POM', 'ALIAS', 88],
        [4, 'Bazylia świeża P_7A op. 20G', 50.0, 'szt', 450, 22500, 5.0, 'BAZYLIA_SW', 'ALIAS', 85],
    ];

    if ($hasLineType && $hasNorm) {
        $stmtL = $pdo->prepare(
            "INSERT INTO sh_ksef_invoice_lines
                (ksef_invoice_id, line_no, external_name, qty, unit, unit_net, line_net_minor, vat_rate,
                 resolved_sku, match_type, match_confidence, line_type,
                 qty_normalized, unit_net_normalized, normalization_status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,'INVENTORY',?,?,?)"
        );
        foreach ($lines1 as $ln) {
            $qtyNorm = $ln[0] === 4 ? 1.0 : $ln[2];
            $unitNorm = $ln[0] === 4 ? 9.0 : $ln[4];
            $normSt = $ln[0] === 4 ? 'warn' : 'ok';
            $stmtL->execute([
                $inv1, $ln[0], $ln[1], $ln[2], $ln[3], $ln[4], $ln[5], $ln[6],
                $ln[7], $ln[8], $ln[9], $qtyNorm, $unitNorm, $normSt,
            ]);
        }
    } elseif ($hasLineType) {
        $stmtL = $pdo->prepare(
            "INSERT INTO sh_ksef_invoice_lines
                (ksef_invoice_id, line_no, external_name, qty, unit, unit_net, line_net_minor, vat_rate,
                 resolved_sku, match_type, match_confidence, line_type)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,'INVENTORY')"
        );
        foreach ($lines1 as $ln) {
            $stmtL->execute([$inv1, $ln[0], $ln[1], $ln[2], $ln[3], $ln[4], $ln[5], $ln[6], $ln[7], $ln[8], $ln[9]]);
        }
    } else {
        $stmtL = $pdo->prepare(
            "INSERT INTO sh_ksef_invoice_lines
                (ksef_invoice_id, line_no, external_name, qty, unit, unit_net, line_net_minor, vat_rate,
                 resolved_sku, match_type, match_confidence)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        foreach ($lines1 as $ln) {
            $stmtL->execute([$inv1, $ln[0], $ln[1], $ln[2], $ln[3], $ln[4], $ln[5], $ln[6], $ln[7], $ln[8], $ln[9]]);
        }
    }

    // 2) accepted — historia (powiązana z PZ #1 z seeda magazynu)
    $pdo->prepare(
        "INSERT INTO sh_ksef_invoices
            (tenant_id, ksef_reference_id, supplier_nip, supplier_name, invoice_number,
             issue_date, currency, total_net_minor, total_vat_minor, total_gross_minor,
             status, linked_wh_document_id, fetched_at, processed_at, processed_by_user_id)
         VALUES (?, 'KSEF-DEMO-REF-002', ?, 'Hurtownia Gastro-Pol', 'FA/DEMO/2026/002',
                 CURDATE() - INTERVAL 10 DAY, 'PLN', 11300, 565, 11865,
                 'accepted', 1, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 9 DAY, 2)"
    )->execute([$T, $nipGastro]);
    $inv2 = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO sh_ksef_invoice_lines
            (ksef_invoice_id, line_no, external_name, qty, unit, unit_net, line_net_minor, vat_rate,
             resolved_sku, match_type, match_confidence)
         VALUES (?, 1, 'Oliwa extra vergine Ferrini 5L', 2.0, 'szt', 3200, 6400, 5.0, 'OLJ_OLIWA', 'ALIAS', 90),
                (?, 2, 'Ser Mozzarella Fior di Latte', 2.0, 'kg', 2450, 4900, 5.0, 'SER_MOZZ', 'FUZZY', 72)"
    )->execute([$inv2, $inv2]);

    // 3) error — pusta korekta (workflow błędu)
    $pdo->prepare(
        "INSERT INTO sh_ksef_invoices
            (tenant_id, ksef_reference_id, supplier_nip, supplier_name, invoice_number,
             issue_date, currency, total_net_minor, total_vat_minor, total_gross_minor,
             status, status_message, fetched_at)
         VALUES (?, 'KSEF-DEMO-REF-003', ?, 'Dostawca Demo KOR', 'FA/DEMO/2026/KOR-001',
                 CURDATE(), 'PLN', 0, 0, 0, 'error',
                 'Korekta bez pozycji — wymaga ręcznej weryfikacji lub odrzucenia', NOW() - INTERVAL 2 HOUR)"
    )->execute([$T, $nipMakro]);

    return '3 faktury (draft/accepted/error), ' . count($lines1) . ' linii na draft';
});

// =============================================================================
// 9c. EXPENSE CATEGORIES (OPEX — m057, jeśli chain pominął INSERT)
// =============================================================================
seed('Expense categories (OPEX)', function ($pdo, $T) {
    try {
        $pdo->query('SELECT 1 FROM sh_expense_categories LIMIT 0');
    } catch (Throwable $e) {
        return 'Pominięto — m057';
    }
    $names = ['Food Cost', 'Packaging', 'Logistics', 'Facility', 'Sales & Marketing', 'IT & Admin'];
    $stmt = $pdo->prepare(
        "INSERT INTO sh_expense_categories (tenant_id, name, is_system, is_active, is_deleted)
         SELECT ?, ?, 1, 1, 0 FROM DUAL
         WHERE NOT EXISTS (
           SELECT 1 FROM sh_expense_categories WHERE tenant_id = ? AND name = ? AND is_deleted = 0
         )"
    );
    $n = 0;
    foreach ($names as $name) {
        $stmt->execute([$T, $name, $T, $name]);
        $n += $stmt->rowCount();
    }
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM sh_expense_categories WHERE tenant_id = {$T} AND is_deleted = 0")->fetchColumn();
    return "{$cnt} kategorii (+" . $n . ' nowych)';
});

// =============================================================================
// 10. WAREHOUSE DOCUMENTS (PZ/RW history)
// =============================================================================
seed('Warehouse Documents (3 PZ + 1 RW)', function ($pdo, $T) {
    $pdo->exec("INSERT INTO wh_documents (id,tenant_id,doc_number,type,warehouse_id,status,supplier_name,supplier_invoice,notes,created_by) VALUES
        (1,{$T},'PZ/2026/04/0001','PZ','MAIN','completed','Makro Cash & Carry','FV/2026/3345','Dostawa tygodniowa',2),
        (2,{$T},'PZ/2026/04/0002','PZ','MAIN','completed','Hurtownia Gastro-Pol','FV/2026/1102','Nabiał + sery',2),
        (3,{$T},'PZ/2026/04/0003','PZ','MAIN','completed','Coca-Cola HBC Polska','FV/2026/8890','Napoje',2),
        (4,{$T},'RW/2026/04/0001','RW','MAIN','completed',NULL,NULL,'Strata — przeterminowane pieczarki',2)
        ON DUPLICATE KEY UPDATE doc_number=VALUES(doc_number)");

    $pdo->exec("INSERT INTO wh_document_lines (document_id,sku,quantity,unit_net_cost,line_net_value,vat_rate,old_avco,new_avco) VALUES
        (1,'MKA_TIPO00',25.0,3.80,95.00,5.00,0.0,3.80),(1,'DRZ_SUCHE',1.0,18.00,18.00,5.00,0.0,18.00),
        (2,'SER_MOZZ',10.0,28.00,280.00,5.00,0.0,28.00),(2,'SER_GORG',2.0,55.00,110.00,5.00,0.0,55.00),
        (3,'COCA_COLA_05',48.0,2.80,134.40,23.00,0.0,2.80),(3,'WODA_05',60.0,1.20,72.00,23.00,0.0,1.20),
        (4,'PIECZARKI',2.0,12.00,24.00,5.00,12.00,12.00)
        ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)");

    $pdo->exec("INSERT INTO wh_stock_logs (tenant_id,warehouse_id,sku,change_qty,after_qty,document_type,document_id,created_by) VALUES
        ({$T},'MAIN','MKA_TIPO00',25.0,25.0,'PZ',1,2),({$T},'MAIN','SER_MOZZ',10.0,10.0,'PZ',2,2),
        ({$T},'MAIN','COCA_COLA_05',48.0,48.0,'PZ',3,2),({$T},'MAIN','PIECZARKI',-2.0,4.0,'RW',4,2)
        ON DUPLICATE KEY UPDATE change_qty=VALUES(change_qty)");

    $pdo->exec("INSERT INTO sh_doc_sequences (tenant_id,doc_type,doc_date,seq) VALUES
        ({$T},'PZ','2026-04-13',3),({$T},'RW','2026-04-13',1)
        ON DUPLICATE KEY UPDATE seq=GREATEST(seq,VALUES(seq))");

    return '4 documents, 7 lines, 4 logs';
});

// =============================================================================
// 11. DRIVERS + SHIFTS + GPS
// =============================================================================
seed('Drivers (2) + Shifts + GPS', function ($pdo, $T) {
    foreach ([6, 7] as $uid) {
        $pdo->prepare("INSERT INTO sh_drivers (user_id,tenant_id,status) VALUES (?,?,'available') ON DUPLICATE KEY UPDATE status='available'")
            ->execute([$uid, $T]);
        $pdo->prepare("INSERT INTO sh_driver_shifts (tenant_id,driver_id,initial_cash,status) SELECT ?,?,'10000','active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sh_driver_shifts WHERE driver_id=? AND tenant_id=? AND status='active')")
            ->execute([$T, $uid, $uid, $T]);
    }
    // GPS positions (near Poznań center)
    $pdo->prepare("INSERT INTO sh_driver_locations (driver_id,tenant_id,lat,lng,updated_at) VALUES (?,?,52.4080,16.9210,NOW()) ON DUPLICATE KEY UPDATE lat=VALUES(lat),lng=VALUES(lng),updated_at=NOW()")->execute([6, $T]);
    $pdo->prepare("INSERT INTO sh_driver_locations (driver_id,tenant_id,lat,lng,updated_at) VALUES (?,?,52.4020,16.9300,NOW()) ON DUPLICATE KEY UPDATE lat=VALUES(lat),lng=VALUES(lng),updated_at=NOW()")->execute([7, $T]);

    return '2 drivers with shifts + GPS';
});

// =============================================================================
// 12. ORDERS — mixed types and statuses
// =============================================================================
seed('Orders (12 total)', function ($pdo, $T) use ($uuid4) {
    // Init sequences
    $pdo->exec("INSERT INTO sh_order_sequences (tenant_id,`date`,seq) VALUES ({$T},CURDATE(),0) ON DUPLICATE KEY UPDATE seq=seq");
    $pdo->exec("INSERT INTO sh_course_sequences (tenant_id,`date`,seq) VALUES ({$T},CURDATE(),0) ON DUPLICATE KEY UPDATE seq=seq");

    $bumpSeq = function () use ($pdo, $T): string {
        $pdo->prepare("UPDATE sh_order_sequences SET seq=LAST_INSERT_ID(seq+1) WHERE tenant_id=? AND `date`=CURDATE()")->execute([$T]);
        return (string)$pdo->lastInsertId();
    };

    $stmtO = $pdo->prepare(
        "INSERT INTO sh_orders (id,tenant_id,order_number,channel,order_type,source,subtotal,delivery_fee,grand_total,status,payment_status,payment_method,customer_name,customer_phone,delivery_address,lat,lng,promised_time,user_id,created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE status=VALUES(status)"
    );
    $stmtL = $pdo->prepare(
        "INSERT INTO sh_order_lines (id,order_id,item_sku,snapshot_name,unit_price,quantity,line_total,comment)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE snapshot_name=VALUES(snapshot_name)"
    );
    $stmtA = $pdo->prepare(
        "INSERT INTO sh_order_audit (order_id,user_id,old_status,new_status,timestamp) VALUES (?,?,?,?,NOW())"
    );

    $count = 0;

    // --- 3 DINE-IN orders ---
    $dineIn = [
        ['preparing', 'unpaid', 'cash',   [['PIZZA_MARGHERITA','Margherita',2400,1],['DRINK_COLA_05','Coca-Cola 0.5L',700,2]], 3],
        ['ready',     'unpaid', 'card',   [['BURGER_CHEESE','Cheese Burger',2400,2],['SIDE_FRIES','Frytki',900,1]], 3],
        ['completed', 'paid',   'cash',   [['PASTA_CARBONARA','Penne Carbonara',2800,1],['DRINK_WATER_05','Woda 0.5L',500,1]], 3],
    ];
    foreach ($dineIn as [$status, $ps, $pm, $lines, $userId]) {
        $oid = $uuid4();
        $seq = $bumpSeq();
        $sub = array_sum(array_map(fn($l) => $l[2] * $l[3], $lines));
        $stmtO->execute([$oid, $T, 'S'.$seq, 'pos', 'dine_in', 'pos', $sub, 0, $sub, $status, $ps, $pm, null, null, null, null, null, null, $userId]);
        foreach ($lines as $l) $stmtL->execute([$uuid4(), $oid, $l[0], $l[1], $l[2], $l[3], $l[2]*$l[3], null]);
        $stmtA->execute([$oid, $userId, 'new', $status]);
        $count++;
    }

    // --- 2 TAKEAWAY orders ---
    $takeaway = [
        ['pending',  'unpaid','online',[['PIZZA_PEPPERONI','Pepperoni',2800,1],['DRINK_SPRITE_05','Sprite 0.5L',700,1]], null],
        ['ready',    'paid',  'online',[['SET_BURGER_COMBO','Zestaw Burger+Frytki+Napój',3200,1]], null],
    ];
    foreach ($takeaway as [$status, $ps, $pm, $lines, $userId]) {
        $oid = $uuid4();
        $seq = $bumpSeq();
        $sub = array_sum(array_map(fn($l) => $l[2] * $l[3], $lines));
        $stmtO->execute([$oid, $T, 'T'.$seq, 'online', 'takeaway', 'web', $sub, 0, $sub, $status, $ps, $pm, 'Klient Online', '500-100-200', null, null, null, date('Y-m-d H:i:s', time()+1800), $userId]);
        foreach ($lines as $l) $stmtL->execute([$uuid4(), $oid, $l[0], $l[1], $l[2], $l[3], $l[2]*$l[3], null]);
        $stmtA->execute([$oid, $userId, 'new', $status]);
        $count++;
    }

    // --- 5 DELIVERY orders (ready — for dispatch testing) ---
    $deliveries = [
        ['ul. Święty Marcin 42/3, 61-807 Poznań',52.4069,16.9163,'501-123-456','Piotr Wiśniewski','cash','unpaid',
            [['PIZZA_CAPRICCIOSA','Capricciosa',3000,1],['DRINK_COLA_05','Coca-Cola 0.5L',800,1]]],
        ['ul. Garbary 78/12, 61-758 Poznań',52.4122,16.9387,'602-234-567','Katarzyna Zielińska','card','unpaid',
            [['PIZZA_4FORMAGGI','Quattro Formaggi',3400,1],['SIDE_GARLIC_SAUCE','Sos czosnkowy',300,2]]],
        ['os. Bohaterów II WŚ 15/4, 61-381 Poznań',52.4218,16.9511,'512-345-678','Tomasz Lewandowski','online','paid',
            [['BURGER_BBQ','BBQ Burger',2800,2],['SIDE_FRIES','Frytki',1000,1]]],
        ['ul. Głogowska 120, 60-243 Poznań',52.3929,16.8873,'693-456-789','Agnieszka Kamińska','cash','unpaid',
            [['PIZZA_MARGHERITA','Margherita',2600,1],['PIZZA_DIAVOLA','Diavola',3200,1]]],
        ['ul. Winogrady 144/8, 61-626 Poznań',52.4336,16.9245,'781-567-890','Michał Dąbrowski','online','paid',
            [['PASTA_LASAGNE','Lasagne',3200,1],['DESSERT_TIRAMISU','Tiramisu',1800,1],['DRINK_BEER_TYSKIE','Piwo Tyskie',1000,1]]],
    ];
    $fee = 500;
    foreach ($deliveries as $idx => $d) {
        $oid = $uuid4();
        $seq = $bumpSeq();
        $sub = array_sum(array_map(fn($l) => $l[2] * $l[3], $d[7]));
        $total = $sub + $fee;
        $promised = date('Y-m-d H:i:s', time() + (20 + $idx * 8) * 60);
        $comment = $idx === 2 ? 'Bez cebuli, extra sos' : null;
        $stmtO->execute([$oid, $T, 'D'.$seq, 'pos', 'delivery', 'pos', $sub, $fee, $total, 'ready', $d[6], $d[5], $d[4], $d[3], $d[0], $d[1], $d[2], $promised, 3]);
        foreach ($d[7] as $li => $l) $stmtL->execute([$uuid4(), $oid, $l[0], $l[1], $l[2], $l[3], $l[2]*$l[3], ($li === 0 ? $comment : null)]);
        $stmtA->execute([$oid, null, 'preparing', 'ready']);
        $count++;
    }

    // --- 2 COMPLETED delivery orders (for cash reconciliation testing) ---
    $completed = [
        ['ul. Ratajczaka 20, Poznań',52.4050,16.9180,'600-111-222','Jan Testowy','cash','unpaid',
            [['PIZZA_HAWAJSKA','Hawajska',3000,1]], 6],
        ['ul. Półwiejska 8, Poznań',52.4040,16.9200,'600-333-444','Maria Testowa','cash','unpaid',
            [['BURGER_CLASSIC','Classic Burger',2400,1],['DRINK_COLA_05','Cola 0.5L',800,1]], 6],
    ];
    foreach ($completed as $d) {
        $oid = $uuid4();
        $seq = $bumpSeq();
        $sub = array_sum(array_map(fn($l) => $l[2] * $l[3], $d[7]));
        $total = $sub + $fee;
        $stmtO->execute([$oid, $T, 'D'.$seq, 'pos', 'delivery', 'pos', $sub, $fee, $total, 'completed', $d[6], $d[5], $d[4], $d[3], $d[0], $d[1], $d[2], date('Y-m-d H:i:s', time()-3600), $d[8]]);
        foreach ($d[7] as $l) $stmtL->execute([$uuid4(), $oid, $l[0], $l[1], $l[2], $l[3], $l[2]*$l[3], null]);
        $stmtA->execute([$oid, $d[8], 'in_delivery', 'completed']);
        $count++;
    }

    return "{$count} orders with lines + audit";
});

// =============================================================================
// 12b. MEAL PACKAGES (F-S3 combo — POS kafelki 🍔)
// =============================================================================
seed('Meal Packages (2 combos)', function ($pdo, $T) {
    // Guard: tabele z migracji 050.
    try {
        $pdo->query('SELECT 1 FROM sh_meal_packages LIMIT 0');
    } catch (Throwable $e) {
        return 'Pominięto — uruchom apply_migrations_chain.php (050)';
    }

    $meals = [
        [
            'ascii_key' => 'COMBO_FAMILY_FIXED',
            'name' => 'Zestaw Rodzinny',
            'category_id' => 8,
            'type' => 'fixed',
            'final_price_grosze' => 3500,
            'description' => 'Margherita + Frytki + Cola',
            'components' => [
                ['fixed_item', 'PIZZA_MARGHERITA', null, 1],
                ['fixed_item', 'SIDE_FRIES', null, 1],
                ['fixed_item', 'DRINK_COLA_05', null, 1],
            ],
        ],
        [
            'ascii_key' => 'COMBO_BURGER_CHOICE',
            'name' => 'Burger Menu (wybór)',
            'category_id' => 8,
            'type' => 'choice',
            'final_price_grosze' => 3200,
            'description' => 'Burger z kategorii + frytki + napój',
            'components' => [
                ['category_choice', null, 2, 1],
                ['fixed_item', 'SIDE_FRIES', null, 1],
                ['fixed_item', 'DRINK_COLA_05', null, 1],
            ],
        ],
    ];

    $stmtUpsert = $pdo->prepare(
        "INSERT INTO sh_meal_packages
            (tenant_id, ascii_key, name, description, category_id, type,
             final_price_grosze, publication_status, is_active, display_order)
         VALUES (?,?,?,?,?,?,?,'Live',1,?)
         ON DUPLICATE KEY UPDATE
            name=VALUES(name), description=VALUES(description), category_id=VALUES(category_id),
            type=VALUES(type), final_price_grosze=VALUES(final_price_grosze),
            publication_status='Live', is_active=1, is_deleted=0"
    );
    $stmtId = $pdo->prepare(
        "SELECT id FROM sh_meal_packages WHERE tenant_id = ? AND ascii_key = ? LIMIT 1"
    );
    $stmtDelComp = $pdo->prepare(
        'DELETE FROM sh_meal_components WHERE meal_id = ? AND tenant_id = ?'
    );
    $stmtComp = $pdo->prepare(
        "INSERT INTO sh_meal_components
            (meal_id, tenant_id, component_type, item_sku, category_id, qty, display_order)
         VALUES (?,?,?,?,?,?,?)"
    );

    $ord = 0;
    foreach ($meals as $m) {
        $stmtUpsert->execute([
            $T, $m['ascii_key'], $m['name'], $m['description'], $m['category_id'], $m['type'],
            $m['final_price_grosze'], $ord,
        ]);
        $stmtId->execute([$T, $m['ascii_key']]);
        $mealId = (int)$stmtId->fetchColumn();
        $stmtDelComp->execute([$mealId, $T]);
        $cOrd = 0;
        foreach ($m['components'] as $c) {
            $stmtComp->execute([
                $mealId, $T, $c[0], $c[1], $c[2], $c[3], $cOrd++,
            ]);
        }
        $ord++;
    }
    return count($meals) . ' meal packages (Zestawy cat #8)';
});

// =============================================================================
// 12c. STUDIO + ONLINE — zdjęcia, sceny, storefront (po menu + zestawach)
// =============================================================================
seed('Studio visuals (heroes + scenes)', function ($pdo, $T) {
    return seed_apply_studio_visuals($pdo, $T);
});

// =============================================================================
// 13. WORK SESSIONS (active staff)
// =============================================================================
seed('Work Sessions', function ($pdo, $T) use ($uuid4) {
    foreach ([2, 3, 4, 5, 6, 7] as $uid) {
        $sid = $uuid4();
        $pdo->prepare("INSERT INTO sh_work_sessions (session_uuid,tenant_id,user_id,start_time) SELECT ?,?,?,NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sh_work_sessions WHERE tenant_id=? AND user_id=? AND end_time IS NULL)")
            ->execute([$sid, $T, $uid, $T, $uid]);
    }
    return '6 active sessions';
});

// =============================================================================
// 14. HR — profile pracowników + stawki temporalne
// (migracja 041 backfilluje tylko userów istniejących w momencie migracji;
//  seed tworzy userów później, więc profile HR domykamy tutaj — bez nich
//  payroll po Fazie 4 nie ma z czego liczyć)
// =============================================================================
seed('HR employees + rates', function ($pdo, $T) use ($HR_RATES_MINOR) {
    $tables = $pdo->query("SHOW TABLES LIKE 'sh_employees'")->fetchAll();
    if ($tables === []) {
        return 'skipped (migracja 041 nieprzyjęta)';
    }

    $pdo->prepare("
        INSERT INTO sh_employees
            (tenant_id, user_id, employee_code, display_name, first_name, last_name,
             hire_date, primary_role, status, default_currency)
        SELECT u.tenant_id, u.id, CONCAT('EMP-', LPAD(u.id, 5, '0')),
               COALESCE(NULLIF(u.name, ''), u.username),
               COALESCE(NULLIF(u.first_name, ''), u.username),
               COALESCE(NULLIF(u.last_name, ''), '-'),
               DATE(u.created_at), u.role, 'active', 'PLN'
        FROM sh_users u
        WHERE u.tenant_id = ? AND u.is_deleted = 0
          AND NOT EXISTS (
              SELECT 1 FROM sh_employees e
              WHERE e.tenant_id = u.tenant_id AND e.user_id = u.id
          )
    ")->execute([$T]);

    // Stawki z mapy PHP (username → grosze) — bez odczytu z sh_users (kolumna
    // hourly_rate zdropowana w migracji 061; sh_employee_rates = SSOT stawek).
    $rateStmt = $pdo->prepare("
        INSERT INTO sh_employee_rates
            (tenant_id, employee_id, rate_type, amount_minor, currency,
             effective_from, effective_to, reason, note)
        SELECT e.tenant_id, e.id, 'hourly', ?, 'PLN',
               TIMESTAMP(e.hire_date, '00:00:00'), NULL, 'hiring', 'Seed demo'
        FROM sh_employees e
        INNER JOIN sh_users u ON u.id = e.user_id AND u.tenant_id = e.tenant_id
        WHERE e.tenant_id = ? AND e.is_deleted = 0 AND u.username = ?
          AND NOT EXISTS (
              SELECT 1 FROM sh_employee_rates r
              WHERE r.tenant_id = e.tenant_id AND r.employee_id = e.id AND r.rate_type = 'hourly'
          )
    ");
    foreach ($HR_RATES_MINOR as $username => $amountMinor) {
        if ($amountMinor > 0) {
            $rateStmt->execute([$amountMinor, $T, $username]);
        }
    }

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM sh_employees WHERE tenant_id = ? AND is_deleted = 0');
    $cnt->execute([$T]);

    return $cnt->fetchColumn() . ' profili HR ze stawkami';
});

// =============================================================================
// 14b. RESTAURANT ZONES + TABLES (POS floor-plan)
// =============================================================================
seed('Restaurant zones + tables (10)', function ($pdo, $T) {
    try {
        $pdo->query('SELECT 1 FROM sh_zones LIMIT 0');
    } catch (Throwable $e) {
        return 'Pominięto — uruchom apply_migrations_chain.php (037+)';
    }

    $pdo->exec("INSERT INTO sh_zones (id, tenant_id, name, display_order, is_active) VALUES
        (1, {$T}, 'Sala Główna', 1, 1),
        (2, {$T}, 'Taras', 2, 1),
        (3, {$T}, 'Loft', 3, 1)
        ON DUPLICATE KEY UPDATE name=VALUES(name), display_order=VALUES(display_order), is_active=1");

    // pos_x / pos_y are PERCENTAGES (0-100) of the floor canvas — NOT pixels.
    // UI applies them as el.style.left = pos_x + '%' (tables_ui.js renderFloor).
    $tables = [
        [1,  1, '1',  4, 'square',    15, 20],
        [2,  1, '2',  4, 'square',    35, 20],
        [3,  1, '3',  6, 'rectangle', 15, 45],
        [4,  1, '4',  2, 'round',     35, 45],
        [5,  1, '5',  4, 'square',    15, 70],
        [6,  1, '6',  8, 'rectangle', 35, 70],
        [7,  2, 'T1', 4, 'square',    60, 20],
        [8,  2, 'T2', 4, 'square',    80, 20],
        [9,  3, 'L1', 2, 'round',      60, 70],
        [10, 3, 'L2', 6, 'rectangle',  80, 70],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO sh_tables (id, tenant_id, zone_id, table_number, seats, shape, pos_x, pos_y, physical_status, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'free', 1)
         ON DUPLICATE KEY UPDATE zone_id=VALUES(zone_id), seats=VALUES(seats), shape=VALUES(shape),
           pos_x=VALUES(pos_x), pos_y=VALUES(pos_y), is_active=1"
    );
    foreach ($tables as $t) {
        $stmt->execute([$t[0], $T, $t[1], $t[2], $t[3], $t[4], $t[5], $t[6]]);
    }
    return '3 strefy, 10 stołów';
});

// =============================================================================
// 14c. DELIVERY ZONES (poligon Poznań)
// =============================================================================
seed('Delivery zones (3)', function ($pdo, $T) {
    try {
        $pdo->query('SELECT 1 FROM sh_delivery_zones LIMIT 0');
    } catch (Throwable $e) {
        return 'Pominięto — brak tabeli sh_delivery_zones';
    }

    // POLYGON — przybliżone obszary dostawy w Poznaniu (SRID 4326)
    $zones = [
        [1, 'Centrum (2km)',   'POLYGON((16.9100 52.4200, 16.9600 52.4200, 16.9600 52.3900, 16.9100 52.3900, 16.9100 52.4200))'],
        [2, 'Winogrady (4km)', 'POLYGON((16.9200 52.4500, 16.9700 52.4500, 16.9700 52.4200, 16.9200 52.4200, 16.9200 52.4500))'],
        [3, 'Grunwald (5km)',  'POLYGON((16.8800 52.4000, 16.9300 52.4000, 16.9300 52.3700, 16.8800 52.3700, 16.8800 52.4000))'],
    ];
    foreach ($zones as $z) {
        try {
            $pdo->prepare(
                "INSERT INTO sh_delivery_zones (id, tenant_id, name, zone_polygon)
                 VALUES (?, ?, ?, ST_GeomFromText(?))
                 ON DUPLICATE KEY UPDATE name=VALUES(name), zone_polygon=ST_GeomFromText(VALUES(zone_polygon))"
            )->execute([$z[0], $T, $z[1], $z[2]]);
        } catch (Throwable $e) {
            // MariaDB 10.4 — fallback: UPDATE tylko name, pomiń poligon
            $pdo->prepare(
                "INSERT INTO sh_delivery_zones (id, tenant_id, name, zone_polygon)
                 VALUES (?, ?, ?, ST_GeomFromText(?))
                 ON DUPLICATE KEY UPDATE name=VALUES(name)"
            )->execute([$z[0], $T, $z[1], $z[2]]);
        }
    }
    return '3 strefy dostawy (Centrum, Winogrady, Grunwald)';
});

// =============================================================================
// 14d. VARIANT SCALES (rozmiary pizzy + burgera)
// =============================================================================
seed('Variant scales (2 scales, 7 options)', function ($pdo, $T) {
    try {
        $pdo->query('SELECT 1 FROM sh_variant_scales LIMIT 0');
    } catch (Throwable $e) {
        return 'Pominięto — uruchom apply_migrations_chain.php (048)';
    }

    $pdo->exec("INSERT INTO sh_variant_scales (id, tenant_id, name, key_ascii, description, is_active) VALUES
        (1, {$T}, 'Rozmiary Pizzy', 'SCALE_PIZZA', 'Mała / Średnia / Duża / Rodzinna', 1),
        (2, {$T}, 'Rozmiar Burgera', 'SCALE_BURGER', 'Standard / Double', 1)
        ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), is_active=1");

    $options = [
        // scale_id, tenant_id, name, key_ascii, display_order, diameter_cm, multiplier, is_default
        [1, $T, 'Mała (25cm)',    'S',  1, 25, 0.700, 0],
        [1, $T, 'Średnia (32cm)', 'M',  2, 32, 1.000, 1],
        [1, $T, 'Duża (40cm)',    'L',  3, 40, 1.300, 0],
        [1, $T, 'Rodzinna (50cm)','XL', 4, 50, 1.800, 0],
        [2, $T, 'Standard',      'STD',1, null, 1.000, 1],
        [2, $T, 'Double',        'DBL',2, null, 1.600, 0],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO sh_variant_scale_options (scale_id, tenant_id, name, key_ascii, display_order, diameter_cm, multiplier, is_default)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name=VALUES(name), display_order=VALUES(display_order),
           diameter_cm=VALUES(diameter_cm), multiplier=VALUES(multiplier), is_default=VALUES(is_default)"
    );
    foreach ($options as $o) {
        $stmt->execute([$o[0], $o[1], $o[2], $o[3], $o[4], $o[5], $o[6], $o[7]]);
    }

    // Powiąż pizzę ze skalą — jako variant parent (ukryte z POS, is_variant_parent=1)
    $hasVariantCols = false;
    try {
        $pdo->query('SELECT variant_scale_id FROM sh_menu_items LIMIT 0');
        $hasVariantCols = true;
    } catch (Throwable $e) {}

    if ($hasVariantCols) {
        $pdo->exec("UPDATE sh_menu_items SET variant_scale_id = 1, is_variant_parent = 1
                     WHERE tenant_id = {$T} AND ascii_key = 'PIZZA_MARGHERITA'");
        $pdo->exec("UPDATE sh_menu_items SET variant_scale_id = 1, is_variant_parent = 1
                     WHERE tenant_id = {$T} AND ascii_key = 'PIZZA_PEPPERONI'");
        $pdo->exec("UPDATE sh_menu_items SET variant_scale_id = 2, is_variant_parent = 1
                     WHERE tenant_id = {$T} AND ascii_key = 'BURGER_CLASSIC'");

        // Stwórz child itemy per rozmiar — one są widoczne w POS (is_variant_parent=0).
        // Frontend grupuje po parentAsciiKey → pokazuje 1 kafelek →
        // klik → dish card z przełącznikiem rozmiarów.
        // Pizza: 4 rozmiary (S/M/L/XL), Burger: 2 rozmiary (STD/DBL).
        $pizzaParents = [
            ['PIZZA_MARGHERITA', 1, 24, 'PIZZA'],  // ascii_key, parent_menu_id, base_pos_price, kds_station
            ['PIZZA_PEPPERONI',  2, 28, 'PIZZA'],
        ];
        $pizzaSizes = [
            ['S',  1, 0.700],   // option_key, option_id, multiplier
            ['M',  2, 1.000],
            ['L',  3, 1.300],
            ['XL', 4, 1.800],
        ];
        $burgerParents = [
            ['BURGER_CLASSIC', 11, 22, 'GRILL'],
        ];
        $burgerSizes = [
            ['STD', 5, 1.000],
            ['DBL', 6, 1.600],
        ];

        $stmtChild = $pdo->prepare(
            "INSERT INTO sh_menu_items (id, tenant_id, category_id, name, ascii_key, `type`, is_active,
                display_order, publication_status, vat_rate_dine_in, vat_rate_takeaway, kds_station_id,
                variant_scale_id, is_variant_parent, parent_item_id, variant_option_id)
             VALUES (?,?,?,?,?, 'standard', 1, ?, 'Live', 8.00, 5.00, ?,
                ?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE
                name=VALUES(name), category_id=VALUES(category_id),
                parent_item_id=VALUES(parent_item_id),
                variant_option_id=VALUES(variant_option_id), is_variant_parent=0,
                variant_scale_id=VALUES(variant_scale_id),
                kds_station_id=VALUES(kds_station_id),
                display_order=VALUES(display_order),
                is_active=1, publication_status='Live'"
        );
        $stmtPrice = $pdo->prepare(
            "INSERT INTO sh_price_tiers (tenant_id, target_type, target_sku, channel, price)
             VALUES (?, 'ITEM', ?, ?, ?)
             ON DUPLICATE KEY UPDATE price=VALUES(price)"
        );

        $childCount = 0;
        $childId = 100;

        // Pizza children (IDs 100-107) — category_id=1 (Pizza)
        $pizzaCatId = 1;
        foreach ($pizzaParents as $p) {
            foreach ($pizzaSizes as $sz) {
                $childSku = $p[0] . '_' . $sz[0];
                $childName = str_replace('PIZZA_', '', $p[0]) . ' ' . $sz[0];
                $childPrice = round($p[2] * $sz[2], 2);
                $delPrice = round($childPrice * 1.08, 2);
                $stmtChild->execute([$childId, $T, $pizzaCatId, $childName, $childSku, $childId, $p[3], 1, $p[1], $sz[1]]);
                $stmtPrice->execute([$T, $childSku, 'POS', $childPrice]);
                $stmtPrice->execute([$T, $childSku, 'Takeaway', $childPrice]);
                $stmtPrice->execute([$T, $childSku, 'Delivery', $delPrice]);
                $childId++;
                $childCount++;
            }
        }

        // Burger children (IDs 108-109) — category_id=2 (Burgery)
        $burgerCatId = 2;
        foreach ($burgerParents as $p) {
            foreach ($burgerSizes as $sz) {
                $childSku = $p[0] . '_' . $sz[0];
                $childName = str_replace('BURGER_', '', $p[0]) . ' ' . $sz[0];
                $childPrice = round($p[2] * $sz[2], 2);
                $delPrice = round($childPrice * 1.08, 2);
                $stmtChild->execute([$childId, $T, $burgerCatId, $childName, $childSku, $childId, $p[3], 2, $p[1], $sz[1]]);
                $stmtPrice->execute([$T, $childSku, 'POS', $childPrice]);
                $stmtPrice->execute([$T, $childSku, 'Takeaway', $childPrice]);
                $stmtPrice->execute([$T, $childSku, 'Delivery', $delPrice]);
                $childId++;
                $childCount++;
            }
        }

        // Skopiuj linki modifierów z parenta na dzieci — dish card filtruje
        // po item.id w sh_item_modifiers, więc bez tego dzieci nie mają dodatków.
        $parentModLinks = $pdo->prepare(
            "SELECT group_id FROM sh_item_modifiers WHERE item_id = ?"
        );
        $insModLink = $pdo->prepare(
            "INSERT IGNORE INTO sh_item_modifiers (item_id, group_id) VALUES (?, ?)"
        );

        // Pizza children: linki z parenta (grupa 2 = Dodatki)
        $childId = 100;
        foreach ($pizzaParents as $p) {
            $parentModLinks->execute([$p[1]]);
            $groupIds = $parentModLinks->fetchAll(PDO::FETCH_COLUMN);
            foreach ($pizzaSizes as $sz) {
                foreach ($groupIds as $gid) {
                    $insModLink->execute([$childId, $gid]);
                }
                $childId++;
            }
        }

        // Burger children: linki z parenta (grupa 3 = Sosy)
        foreach ($burgerParents as $p) {
            $parentModLinks->execute([$p[1]]);
            $groupIds = $parentModLinks->fetchAll(PDO::FETCH_COLUMN);
            foreach ($burgerSizes as $sz) {
                foreach ($groupIds as $gid) {
                    $insModLink->execute([$childId, $gid]);
                }
                $childId++;
            }
        }

        return "2 skale, 3 parents (2 pizza + 1 burger) + {$childCount} children z cenami (POS+Takeaway+Delivery) + modifierami";
    }

    return '2 skale (bez children — brak kolumn variant w sh_menu_items)';
});

// =============================================================================
// 14e. PRINT DECKS (Wachlarz A5 — demo dla tenant 1)
// =============================================================================
seed('Print decks (1 demo deck)', function ($pdo, $T) {
    try {
        $pdo->query('SELECT 1 FROM sh_print_decks LIMIT 0');
    } catch (Throwable $e) {
        return 'Pominięto — uruchom apply_migrations_chain.php (060)';
    }

    $pdo->exec("INSERT INTO sh_print_decks (id, tenant_id, ascii_key, name, status, brand, notes) VALUES
        (1, {$T}, 'DEMO_WACHLARZ_V1', 'Wachlarz Demo A5', 'ready', 'SliceHub Demo',
         'Kuratorowana karta drukowana A5 — demo dla tenant 1')
        ON DUPLICATE KEY UPDATE name=VALUES(name), status=VALUES(status), brand=VALUES(brand)");

    $cards = [
        [1, $T, 1, 'cover',       0, '{"title":"SliceHub Pizzeria","subtitle":"Najlepsza pizza w mieście","bg_color":"#1a1a2e"}'],
        [2, $T, 1, 'hero_duo',    1, '{"title":"Polecamy","item_1":"PIZZA_MARGHERITA","price_1":"24 zł","item_2":"PIZZA_PEPPERONI","price_2":"28 zł"}'],
        [3, $T, 1, 'hero_sizes',  2, '{"title":"Rozmiary pizzy","sizes":["Mała 25cm","Średnia 32cm","Duża 40cm","Rodzinna 50cm"],"ascii_key":"SCALE_PIZZA"}'],
        [4, $T, 1, 'hero_list',   3, '{"title":"Menu","items":[{"name":"Margherita","price":"24 zł"},{"name":"Pepperoni","price":"28 zł"},{"name":"Capricciosa","price":"30 zł"},{"name":"BBQ Chicken","price":"32 zł"}]}'],
        [5, $T, 1, 'cta',         4, '{"title":"Zamów teraz","phone":"500-100-200","website":"slicehub.pl"}'],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO sh_print_deck_cards (id, tenant_id, deck_id, card_key, card_type, sort_order, payload_json)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE card_type=VALUES(card_type), sort_order=VALUES(sort_order), payload_json=VALUES(payload_json)"
    );
    $cardKeys = ['cover', 'hero_duo_pizzas', 'hero_sizes_pizza', 'hero_list_menu', 'cta_order'];
    foreach ($cards as $i => $c) {
        $stmt->execute([$c[0], $c[1], $c[2], $cardKeys[$i], $c[3], $c[4], $c[5]]);
    }
    return '1 deck (5 kart: cover, hero_duo, hero_sizes, hero_list, cta)';
});

// =============================================================================
// 15. PAYROLL HISTORY — zamknięte sesje + wpisy ledger (ostatnie 4 miesiące)
// -----------------------------------------------------------------------------
// Seedy 13/14 tworzą tylko aktywne (otwarte) sesje i profile HR ze stawkami.
// Bez historii zamkniętych sesji + wpisów w sh_payroll_ledger raporty płacowe
// (PayrollEngine, TeamPayrollEngine) i BI P&L (BiEngine::aggregateLaborMinor)
// pokazują zera. Tu generujemy ~20 sesji miesięcznie per pracownik przez
// 4 ostatnie miesiące + odpowiadające im wpisy work_earnings w ledgerze.
//
// Idempotentność: entry_uuid = deterministyczny UUID per (employee, month, day).
// sh_work_sessions: ON DUPLICATE KEY UPDATE — re-run nie dubluje.
// =============================================================================
seed('Payroll history (4 months × ~20 shifts)', function ($pdo, $T) use ($uuid4, $HR_RATES_MINOR) {
    // Guard: wymagany profil HR + ledger
    try {
        $pdo->query('SELECT 1 FROM sh_payroll_ledger LIMIT 0');
        $pdo->query('SELECT employee_id FROM sh_work_sessions LIMIT 0');
    } catch (Throwable $e) {
        return 'Pominięto — migracje 041-043 nieprzyjęte';
    }

    // Mapa username → (user_id, employee_id, rate_minor)
    $empMap = [];
    $stmtEmp = $pdo->prepare("
        SELECT u.id AS user_id, u.username, e.id AS employee_id
        FROM sh_users u
        INNER JOIN sh_employees e ON e.user_id = u.id AND e.tenant_id = u.tenant_id
        WHERE u.tenant_id = ? AND u.is_deleted = 0 AND e.is_deleted = 0
    ");
    $stmtEmp->execute([$T]);
    foreach ($stmtEmp->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uname = $r['username'];
        $rateMinor = $HR_RATES_MINOR[$uname] ?? 0;
        if ($rateMinor > 0) {
            $empMap[$uname] = [
                'user_id'     => (int)$r['user_id'],
                'employee_id' => (int)$r['employee_id'],
                'rate_minor'  => $rateMinor,
            ];
        }
    }

    if ($empMap === []) {
        return 'Brak pracowników ze stawkami — pominięto';
    }

    // Pobierz stawki z sh_employee_rates (temporalne — effective_from hire_date)
    $stmtRate = $pdo->prepare("
        SELECT amount_minor FROM sh_employee_rates
        WHERE tenant_id = ? AND employee_id = ? AND rate_type = 'hourly'
          AND effective_from <= ? AND (effective_to IS NULL OR effective_to > ?)
        ORDER BY effective_from DESC LIMIT 1
    ");

    // Insert zamkniętej sesji
    $stmtWs = $pdo->prepare("
        INSERT INTO sh_work_sessions
            (session_uuid, tenant_id, user_id, employee_id, start_time, end_time,
             total_hours, clock_in_source, clock_out_source)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'kiosk', 'kiosk')
        ON DUPLICATE KEY UPDATE
            end_time = VALUES(end_time),
            total_hours = VALUES(total_hours)
    ");

    // Insert wpisu work_earnings w ledgerze
    $stmtLedger = $pdo->prepare("
        INSERT INTO sh_payroll_ledger
            (entry_uuid, tenant_id, employee_id, period_year, period_month,
             entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
             ref_work_session_id, description, created_at)
        VALUES (?, ?, ?, ?, ?, 'work_earnings', ?, 'PLN', ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            amount_minor = VALUES(amount_minor),
            hours_qty = VALUES(hours_qty)
    ");

    // Pobierz ID sesji po UUID
    $stmtWsId = $pdo->prepare("SELECT id FROM sh_work_sessions WHERE session_uuid = ? LIMIT 1");

    $now = new DateTimeImmutable('now');
    $monthsBack = [3, 2, 1, 0]; // 4 miesiące wstecz (0 = bieżący)
    $shiftsCreated = 0;
    $ledgerEntries = 0;

    foreach ($monthsBack as $offset) {
        $monthStart = $now->modify("first day of this month")->modify("-{$offset} months")->setTime(0, 0, 0);
        $year  = (int)$monthStart->format('Y');
        $month = (int)$monthStart->format('n');
        $daysInMonth = (int)$monthStart->format('t');

        // Bieżący miesiąc — nie generuj przyszłości, tylko do dzisiaj
        $maxDay = $offset === 0 ? (int)$now->format('j') : $daysInMonth;

        foreach ($empMap as $uname => $emp) {
            // ~20 zmian miesięcznie (pon-pt, ~4 tygodnie)
            $shiftsThisMonth = 0;
            for ($day = 1; $day <= $maxDay && $shiftsThisMonth < 22; $day++) {
                $date = $monthStart->modify("+{$day} days");
                $dow = (int)$date->format('N'); // 1=Mon, 7=Sun
                if ($dow >= 6) continue; // skip weekends

                $shiftsThisMonth++;

                // Start 10:00, end 18:00 (8h zmiana)
                $start = $date->setTime(10, 0, 0);
                $end   = $date->setTime(18, 0, 0);

                // Pomiń przyszłość
                if ($start > $now) continue;

                $startSql = $start->format('Y-m-d H:i:s');
                $endSql   = $end->format('Y-m-d H:i:s');
                $hours    = 8.0;

                // Stawka temporalna na moment startu
                $stmtRate->execute([$T, $emp['employee_id'], $startSql, $startSql]);
                $rateMinor = (int)$stmtRate->fetchColumn();
                if ($rateMinor <= 0) $rateMinor = $emp['rate_minor'];

                $earningsMinor = (int)round($hours * $rateMinor);

                // Deterministyczny UUID per (employee, month, day, shift)
                $wsUuid = Uuid::deterministic("seed-hist-{$emp['employee_id']}-{$year}-{$month}-{$day}");
                $ledgerUuid = Uuid::deterministic("seed-ledger-{$emp['employee_id']}-{$year}-{$month}-{$day}");

                $stmtWs->execute([
                    $wsUuid, $T, $emp['user_id'], $emp['employee_id'],
                    $startSql, $endSql, $hours,
                ]);

                $stmtWsId->execute([$wsUuid]);
                $wsId = (int)$stmtWsId->fetchColumn();

                $desc = "Seed historyczny: {$uname} {$startSql} (8h × " . number_format($rateMinor / 100, 2) . " zł/h)";

                $stmtLedger->execute([
                    $ledgerUuid, $T, $emp['employee_id'], $year, $month,
                    $earningsMinor, $hours, $rateMinor,
                    $wsId, $desc, $endSql,
                ]);

                $shiftsCreated++;
                $ledgerEntries++;
            }
        }
    }

    $cntLedger = (int)$pdo->query("SELECT COUNT(*) FROM sh_payroll_ledger WHERE tenant_id = {$T}")->fetchColumn();
    $cntWs = (int)$pdo->query("SELECT COUNT(*) FROM sh_work_sessions WHERE tenant_id = {$T} AND end_time IS NOT NULL")->fetchColumn();

    return "{$shiftsCreated} sesji, {$ledgerEntries} wpisów ledger (łącznie: {$cntWs} zamkniętych, {$cntLedger} w ledgerze)";
});

// =============================================================================
// Zaliczki demo — jedna jednorazowa + jedna z planem rat (3 raty)
// Idempotentność: sprawdzenie po employee_id + amount_minor + status.
// =============================================================================
seed('Advances demo (single + installments)', function ($pdo, $T) use ($uuid4) {
    try {
        $pdo->query('SELECT 1 FROM sh_advances LIMIT 0');
        $pdo->query('SELECT 1 FROM sh_advance_installments LIMIT 0');
    } catch (Throwable $e) {
        return 'Pominięto — migracja 044 nieprzyjęta';
    }

    // Znajdź pracowników: waiter1 (uid=3) i cook1 (uid=5)
    $empStmt = $pdo->prepare("SELECT e.id, e.user_id FROM sh_employees e WHERE e.tenant_id = ? AND e.is_deleted = 0 AND e.user_id IN (3, 5) ORDER BY e.user_id");
    $empStmt->execute([$T]);
    $emps = $empStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($emps) < 2) return 'Brak 2 pracowników (uid 3,5) — pominięto';

    $waiter = $emps[0]['id']; // uid=3
    $cook = $emps[1]['id'];   // uid=5

    // 1. Zaliczka jednorazowa dla waiter1 (200 PLN, approved)
    $exists1 = $pdo->prepare("SELECT 1 FROM sh_advances WHERE tenant_id = ? AND employee_id = ? AND amount_minor = 20000 AND repayment_plan = 'single' LIMIT 1");
    $exists1->execute([$T, $waiter]);
    if (!$exists1->fetchColumn()) {
        $pdo->prepare("INSERT INTO sh_advances (advance_uuid, tenant_id, employee_id, amount_minor, currency, status, repayment_plan, installments_count, reason, requested_at, approved_at)
            VALUES (?, ?, ?, 20000, 'PLN', 'approved', 'single', 1, 'demo seed', NOW(), NOW())")
            ->execute([$uuid4(), $T, $waiter]);
    }

    // 2. Zaliczka z ratami dla cook1 (300 PLN, paid, 3 raty)
    $exists2 = $pdo->prepare("SELECT 1 FROM sh_advances WHERE tenant_id = ? AND employee_id = ? AND amount_minor = 30000 AND repayment_plan = 'installments' LIMIT 1");
    $exists2->execute([$T, $cook]);
    if (!$exists2->fetchColumn()) {
        $advUuid = $uuid4();
        $pdo->prepare("INSERT INTO sh_advances (advance_uuid, tenant_id, employee_id, amount_minor, currency, status, repayment_plan, installments_count, reason, requested_at, approved_at, paid_at)
            VALUES (?, ?, ?, 30000, 'PLN', 'paid', 'installments', 3, 'demo seed — raty', NOW(), NOW(), NOW())")
            ->execute([$advUuid, $T, $cook]);

        $advId = $pdo->lastInsertId();
        $now = new DateTimeImmutable('now');
        $instStmt = $pdo->prepare("INSERT INTO sh_advance_installments (tenant_id, advance_id, seq_no, amount_minor, currency, scheduled_period_year, scheduled_period_month, status)
            VALUES (?, ?, ?, ?, 'PLN', ?, ?, ?)");

        // Rata 1: bieżący miesiąc — pending
        $m1 = $now->modify('first day of this month');
        $instStmt->execute([$T, $advId, 1, 10000, (int)$m1->format('Y'), (int)$m1->format('n'), 'pending']);

        // Rata 2: następny miesiąc — pending
        $m2 = $now->modify('first day of next month');
        $instStmt->execute([$T, $advId, 2, 10000, (int)$m2->format('Y'), (int)$m2->format('n'), 'pending']);

        // Rata 3: +2 miesiące — pending
        $m3 = $now->modify('first day of +2 month');
        $instStmt->execute([$T, $advId, 3, 10000, (int)$m3->format('Y'), (int)$m3->format('n'), 'pending']);
    }

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM sh_advances WHERE tenant_id = {$T}")->fetchColumn();
    $cntInst = (int)$pdo->query("SELECT COUNT(*) FROM sh_advance_installments WHERE tenant_id = {$T}")->fetchColumn();
    return "{$cnt} zaliczek, {$cntInst} rat w harmonogramie";
});

// =============================================================================
// OUTPUT
// =============================================================================
$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    echo "\n=== SliceHub — Unified Demo Seed ===\n\n";
    foreach ($results as $r) {
        $icon = $r['ok'] ? '[OK]' : '[!!]';
        echo "  {$icon} {$r['label']} — {$r['msg']}\n";
    }
    echo "\n  Total: {$ok} OK, {$fail} ERRORS\n";
    echo "\n  --- Konta testowe ---\n";
    echo "  admin    (owner)   — system login only, password: password\n";
    echo "  manager  (manager) — PIN: 0000\n";
    echo "  waiter1  (waiter)  — PIN: 1111\n";
    echo "  waiter2  (waiter)  — PIN: 2222\n";
    echo "  cook1    (cook)    — PIN: 3333\n";
    echo "  driver1  (driver)  — PIN: 4444\n";
    echo "  driver2  (driver)  — PIN: 5555\n";
    echo "  team1    (team)    — PIN: 6666\n\n";
    echo "  Inbox KSeF: FA/DEMO/2026/001 (draft), FA/DEMO/2026/002 (accepted), FA/DEMO/KOR-001 (error)\n";
    echo "  Studio: miniatury hero SVG + 10 scen pizza + Scene Kit (tenant 0)\n\n";
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>SliceHub — Unified Demo Seed</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#05050a; color:#e2e8f0; font-family:'Segoe UI',system-ui,sans-serif; padding:40px; }
        .hdr { text-align:center; margin-bottom:40px; }
        .hdr h1 { color:#a78bfa; font-size:28px; }
        .hdr p { color:#64748b; font-size:13px; margin-top:8px; }
        .sum { display:flex; justify-content:center; gap:20px; margin-bottom:30px; }
        .sum .b { padding:16px 32px; border-radius:12px; text-align:center; border:1px solid rgba(255,255,255,0.06); }
        .sum .ok { background:rgba(34,197,94,0.1); border-color:rgba(34,197,94,0.3); }
        .sum .ok .n { color:#22c55e; font-size:28px; font-weight:900; }
        .sum .er { background:rgba(239,68,68,0.1); border-color:rgba(239,68,68,0.3); }
        .sum .er .n { color:#ef4444; font-size:28px; font-weight:900; }
        .sec { margin-bottom:16px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:12px; overflow:hidden; }
        .row { display:flex; align-items:center; padding:10px 20px; border-bottom:1px solid rgba(255,255,255,0.02); font-size:13px; gap:12px; }
        .row:last-child { border-bottom:none; }
        .dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        .dot.ok { background:#22c55e; }
        .dot.er { background:#ef4444; }
        .row .lbl { flex:1; font-weight:600; }
        .row .msg { color:#94a3b8; text-align:right; font-size:11px; }
        .links { text-align:center; margin-top:40px; display:flex; gap:12px; justify-content:center; flex-wrap:wrap; }
        .links a { display:inline-block; padding:12px 24px; border-radius:12px; text-decoration:none; font-weight:700; font-size:12px; text-transform:uppercase; letter-spacing:0.05em; color:#fff; }
        .cred { max-width:600px; margin:30px auto 0; padding:20px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:12px; }
        .cred h3 { color:#a78bfa; font-size:11px; text-transform:uppercase; letter-spacing:0.1em; margin-bottom:12px; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        td { padding:5px 12px; border-bottom:1px solid rgba(255,255,255,0.03); }
        .pin { color:#22c55e; font-weight:700; font-family:monospace; }
        .role { color:#64748b; }
    </style>
</head>
<body>
    <div class="hdr">
        <h1>SliceHub — Unified Demo Seed</h1>
        <p>Kompletny zestaw testowy dla wszystkich modułów systemu</p>
    </div>
    <div class="sum">
        <div class="b ok"><div class="n"><?= $ok ?></div><div style="color:#64748b;font-size:11px;">OK</div></div>
        <div class="b er"><div class="n"><?= $fail ?></div><div style="color:#64748b;font-size:11px;">BŁĘDY</div></div>
    </div>
    <div class="sec">
        <?php foreach ($results as $r): ?>
        <div class="row">
            <div class="dot <?= $r['ok'] ? 'ok' : 'er' ?>"></div>
            <div class="lbl"><?= htmlspecialchars($r['label']) ?></div>
            <div class="msg"><?= htmlspecialchars($r['msg']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="links">
        <a href="/slicehub/modules/pos/" style="background:#3b82f6;">POS</a>
        <a href="/slicehub/modules/studio/" style="background:#06b6d4;">Menu Studio</a>
        <a href="/slicehub/modules/online_studio/" style="background:#0ea5e9;">Online Studio</a>
        <a href="/slicehub/modules/online/" style="background:#14b8a6;">Sklep online</a>
        <a href="/slicehub/modules/courses/" style="background:#a855f7;">Kursy / Dispatch</a>
        <a href="/slicehub/modules/driver_app/" style="background:#22c55e;">Driver App</a>
        <a href="/slicehub/modules/warehouse/" style="background:#f97316;">Magazyn</a>
        <a href="/slicehub/modules/procurement/" style="background:#10b981;">Inbox KSeF</a>
        <a href="/slicehub/tests/test_runner.html" style="background:#64748b;">Test Runner</a>
    </div>

    <div class="cred">
        <h3>Konta testowe (hasło systemowe: password)</h3>
        <table>
            <tr><td>admin</td><td class="role">owner</td><td>—</td><td class="role">tylko login systemowy</td></tr>
            <tr><td>manager</td><td class="role">manager</td><td class="pin">PIN: 0000</td><td class="role">POS / Dispatch</td></tr>
            <tr><td>waiter1</td><td class="role">waiter</td><td class="pin">PIN: 1111</td><td class="role">POS</td></tr>
            <tr><td>waiter2</td><td class="role">waiter</td><td class="pin">PIN: 2222</td><td class="role">POS</td></tr>
            <tr><td>cook1</td><td class="role">cook</td><td class="pin">PIN: 3333</td><td class="role">KDS</td></tr>
            <tr><td>driver1</td><td class="role">driver</td><td class="pin">PIN: 4444</td><td class="role">Driver App</td></tr>
            <tr><td>driver2</td><td class="role">driver</td><td class="pin">PIN: 5555</td><td class="role">Driver App</td></tr>
            <tr><td>team1</td><td class="role">team</td><td class="pin">PIN: 6666</td><td class="role">Team App</td></tr>
        </table>
    </div>
</body>
</html>
