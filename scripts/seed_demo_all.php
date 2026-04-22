<?php
declare(strict_types=1);

// =============================================================================
// SliceHub Enterprise — Unified Demo Seed (ALL MODULES)
//
// Run via browser: http://localhost/slicehub/scripts/seed_demo_all.php
// Run via CLI:     php scripts/seed_demo_all.php
//
// Creates a complete, coherent test dataset for:
//   POS, Studio, Warehouse, Courses/Dispatch, Driver App, KDS, Dashboard
//
// SAFE TO RE-RUN: Uses ON DUPLICATE KEY UPDATE throughout.
// =============================================================================

require_once __DIR__ . '/../core/db_config.php';

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

$uuid4 = function (): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
};

// Known bcrypt hash of "password" — used for ALL test accounts
$PW = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

// =============================================================================
// 0. SCHEMA GUARDS (run missing ALTERs)
// =============================================================================
seed('Migration 006 — Studio columns', function ($pdo) {
    $alters = [
        "ALTER TABLE sh_categories ADD COLUMN default_vat_dine_in DECIMAL(5,2) NOT NULL DEFAULT 8.00",
        "ALTER TABLE sh_categories ADD COLUMN default_vat_takeaway DECIMAL(5,2) NOT NULL DEFAULT 5.00",
        "ALTER TABLE sh_categories ADD COLUMN default_vat_delivery DECIMAL(5,2) NOT NULL DEFAULT 5.00",
        "ALTER TABLE sh_menu_items ADD COLUMN printer_group VARCHAR(64) NULL DEFAULT NULL",
        "ALTER TABLE sh_menu_items ADD COLUMN plu_code VARCHAR(32) NULL DEFAULT NULL",
        "ALTER TABLE sh_menu_items ADD COLUMN available_days VARCHAR(32) NULL DEFAULT '1,2,3,4,5,6,7'",
        "ALTER TABLE sh_menu_items ADD COLUMN available_start TIME NULL DEFAULT NULL",
        "ALTER TABLE sh_menu_items ADD COLUMN available_end TIME NULL DEFAULT NULL",
    ];
    $applied = 0;
    foreach ($alters as $sql) {
        try { $pdo->exec($sql); $applied++; } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e;
        }
    }
    return "{$applied} new columns";
});

seed('Migration 007 — POS Engine columns', function ($pdo) {
    $alters = [
        "ALTER TABLE sh_orders ADD COLUMN receipt_printed TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE sh_orders ADD COLUMN kitchen_ticket_printed TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE sh_orders ADD COLUMN kitchen_changes TEXT NULL",
        "ALTER TABLE sh_orders ADD COLUMN cart_json JSON NULL",
        "ALTER TABLE sh_orders ADD COLUMN nip VARCHAR(32) NULL",
    ];
    $applied = 0;
    foreach ($alters as $sql) {
        try { $pdo->exec($sql); $applied++; } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e;
        }
    }
    return "{$applied} new columns";
});

seed('Migration 008 — Driver Locations', function ($pdo) {
    try {
        $chk = $pdo->query("SELECT 1 FROM sh_driver_locations LIMIT 0");
        $chk->closeCursor();
        return 'Table exists';
    } catch (\Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sh_driver_locations (
            driver_id BIGINT UNSIGNED NOT NULL, tenant_id INT UNSIGNED NOT NULL,
            lat DECIMAL(10,7) NOT NULL, lng DECIMAL(10,7) NOT NULL,
            heading SMALLINT NULL, speed_kmh DECIMAL(5,1) NULL, accuracy_m DECIMAL(6,1) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (tenant_id, driver_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return 'Created';
    }
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
seed('Users (8 accounts)', function ($pdo, $T) use ($PW) {
    $users = [
        [1, 'admin',   null,   'Administrator',   'Jan',    'Kowalski',  'owner',   0.00],
        [2, 'manager', '0000', 'Kierownik Anna',  'Anna',   'Nowak',     'manager', 28.00],
        [3, 'waiter1', '1111', 'Kelner Marek',    'Marek',  'Zieliński', 'waiter',  22.00],
        [4, 'waiter2', '2222', 'Kelnerka Ola',    'Ola',    'Wójcik',    'waiter',  22.00],
        [5, 'cook1',   '3333', 'Kucharz Piotr',   'Piotr',  'Mazur',     'cook',    25.00],
        [6, 'driver1', '4444', 'Kierowca Tomek',  'Tomek',  'Kaczmarek', 'driver',  20.00],
        [7, 'driver2', '5555', 'Kierowca Ania',   'Ania',   'Kowalczyk', 'driver',  20.00],
        [8, 'team1',   '6666', 'Pracownik Asia',  'Asia',   'Dąbrowska', 'team',    19.50],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO sh_users (id, tenant_id, username, password_hash, pin_code, name, first_name, last_name, role, status, hourly_rate, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, 1)
         ON DUPLICATE KEY UPDATE pin_code=VALUES(pin_code), name=VALUES(name), first_name=VALUES(first_name),
           last_name=VALUES(last_name), role=VALUES(role), status='active', hourly_rate=VALUES(hourly_rate), is_active=1"
    );
    foreach ($users as $u) {
        $stmt->execute([$u[0], $T, $u[1], $PW, $u[2], $u[3], $u[4], $u[5], $u[6], $u[7]]);
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
        "INSERT INTO sh_menu_items (id, tenant_id, category_id, name, ascii_key, `type`, is_active, display_order, vat_rate_dine_in, vat_rate_takeaway, kds_station_id)
         VALUES (?,?,?,?,?,'standard',1,?,?,?,?)
         ON DUPLICATE KEY UPDATE name=VALUES(name), category_id=VALUES(category_id), is_active=1, kds_station_id=VALUES(kds_station_id)"
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
seed('Modifiers (4 groups, 13 mods)', function ($pdo, $T) {
    $pdo->exec("INSERT INTO sh_modifier_groups (id,tenant_id,name,ascii_key,min_selection,max_selection,free_limit) VALUES
        (1,{$T},'Rozmiar pizzy','SIZE_PIZZA',1,1,0),
        (2,{$T},'Dodatki do pizzy','EXTRA_PIZZA',0,5,0),
        (3,{$T},'Sosy','SAUCES',0,3,1),
        (4,{$T},'Rozmiar burgera','SIZE_BURGER',1,1,0)
        ON DUPLICATE KEY UPDATE name=VALUES(name)");

    $pdo->exec("INSERT INTO sh_modifiers (id,group_id,name,ascii_key,action_type,price,is_default) VALUES
        (1,1,'Mała (25cm)','SIZE_S','ADD',0.00,0),
        (2,1,'Średnia (32cm)','SIZE_M','ADD',0.00,1),
        (3,1,'Duża (40cm)','SIZE_L','ADD',6.00,0),
        (4,1,'Rodzinna (50cm)','SIZE_XL','ADD',14.00,0),
        (5,2,'Podwójny ser','EXTRA_CHEESE','ADD',4.00,0),
        (6,2,'Jalapeno','EXTRA_JALAP','ADD',3.00,0),
        (7,2,'Oliwki','EXTRA_OLIVES','ADD',3.00,0),
        (8,2,'Szynka','EXTRA_HAM','ADD',5.00,0),
        (9,3,'Czosnkowy','SAUCE_GARLIC','ADD',2.00,0),
        (10,3,'BBQ','SAUCE_BBQ','ADD',2.00,0),
        (11,3,'Ostry','SAUCE_HOT','ADD',2.00,0),
        (12,4,'Standard','BURG_STD','ADD',0.00,1),
        (13,4,'Double','BURG_DBL','ADD',8.00,0)
        ON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price)");

    // Pizza items → size + extras, burger items → sauces + size
    $pdo->exec("INSERT IGNORE INTO sh_item_modifiers (item_id,group_id) VALUES
        (1,1),(1,2),(2,1),(2,2),(3,1),(3,2),(4,1),(4,2),(5,1),(5,2),(6,1),(6,2),(7,1),(7,2),(8,1),(8,2),(9,1),(9,2),(10,1),(10,2),
        (11,3),(11,4),(12,3),(12,4),(13,3),(13,4),(14,3),(14,4),(15,3),(15,4)");

    $pdo->exec("INSERT INTO sh_price_tiers (tenant_id,target_type,target_sku,channel,price) VALUES
        ({$T},'MODIFIER','SIZE_S','POS',-4.00),({$T},'MODIFIER','SIZE_M','POS',0.00),
        ({$T},'MODIFIER','SIZE_L','POS',6.00),({$T},'MODIFIER','SIZE_XL','POS',14.00),
        ({$T},'MODIFIER','EXTRA_CHEESE','POS',4.00),({$T},'MODIFIER','EXTRA_JALAP','POS',3.00),
        ({$T},'MODIFIER','EXTRA_OLIVES','POS',3.00),({$T},'MODIFIER','EXTRA_HAM','POS',5.00),
        ({$T},'MODIFIER','SAUCE_GARLIC','POS',2.00),({$T},'MODIFIER','SAUCE_BBQ','POS',2.00),
        ({$T},'MODIFIER','SAUCE_HOT','POS',2.00),({$T},'MODIFIER','BURG_STD','POS',0.00),
        ({$T},'MODIFIER','BURG_DBL','POS',8.00)
        ON DUPLICATE KEY UPDATE price=VALUES(price)");

    return '4 groups, 13 modifiers, 30 links';
});

// =============================================================================
// 7. WAREHOUSE — sys_items + wh_stock
// =============================================================================
seed('Warehouse (43 items + stock)', function ($pdo, $T) {
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
        ['OPAK_PIZZA','Opakowanie karton pizza 32cm','szt',200.0,1.20],
        ['OPAK_BURGER','Opakowanie styro burger','szt',150.0,0.80],
    ];

    $stmtI = $pdo->prepare("INSERT INTO sys_items (tenant_id,sku,name,base_unit) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), base_unit=VALUES(base_unit)");
    $stmtS = $pdo->prepare("INSERT INTO wh_stock (tenant_id,warehouse_id,sku,quantity,current_avco_price,unit_net_cost) VALUES (?,'MAIN',?,?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity), current_avco_price=VALUES(current_avco_price)");

    foreach ($items as $it) {
        $stmtI->execute([$T, $it[0], $it[1], $it[2]]);
        $stmtS->execute([$T, $it[0], $it[3], $it[4], $it[4]]);
    }
    return count($items) . ' items + stock';
});

// =============================================================================
// 8. RECIPES
// =============================================================================
seed('Recipes (menu → warehouse)', function ($pdo, $T) {
    $recipes = [
        ['PIZZA_MARGHERITA','MKA_TIPO00',0.25,2],['PIZZA_MARGHERITA','SER_MOZZ',0.20,0],['PIZZA_MARGHERITA','SOS_POM',0.10,0],['PIZZA_MARGHERITA','OLJ_OLIWA',0.015,0],['PIZZA_MARGHERITA','DRZ_SUCHE',0.003,0],['PIZZA_MARGHERITA','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_PEPPERONI','MKA_TIPO00',0.25,2],['PIZZA_PEPPERONI','SER_MOZZ',0.18,0],['PIZZA_PEPPERONI','SOS_POM',0.10,0],['PIZZA_PEPPERONI','PEPP_SALAMI',0.08,0],['PIZZA_PEPPERONI','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_CAPRICCIOSA','MKA_TIPO00',0.25,2],['PIZZA_CAPRICCIOSA','SER_MOZZ',0.18,0],['PIZZA_CAPRICCIOSA','SOS_POM',0.10,0],['PIZZA_CAPRICCIOSA','SZYNKA_PARM',0.06,0],['PIZZA_CAPRICCIOSA','PIECZARKI',0.05,0],['PIZZA_CAPRICCIOSA','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_HAWAJSKA','MKA_TIPO00',0.25,2],['PIZZA_HAWAJSKA','SER_MOZZ',0.18,0],['PIZZA_HAWAJSKA','SOS_POM',0.10,0],['PIZZA_HAWAJSKA','SZYNKA_PARM',0.06,0],['PIZZA_HAWAJSKA','ANANAS',0.06,0],['PIZZA_HAWAJSKA','OPAK_PIZZA',1.0,0,1],
        ['PIZZA_4FORMAGGI','MKA_TIPO00',0.25,2],['PIZZA_4FORMAGGI','SER_MOZZ',0.12,0],['PIZZA_4FORMAGGI','SER_GORG',0.05,0],['PIZZA_4FORMAGGI','SER_PARM',0.04,0],['PIZZA_4FORMAGGI','SER_CHEDDAR',0.04,0],['PIZZA_4FORMAGGI','OPAK_PIZZA',1.0,0,1],
        ['BURGER_CLASSIC','WOLOWINA_M',0.18,3],['BURGER_CLASSIC','BULKA_BURG',1.0,0],['BURGER_CLASSIC','SALATA_RZY',0.03,0],['BURGER_CLASSIC','POMIDOR',0.04,0],['BURGER_CLASSIC','CEBULA',0.02,0],['BURGER_CLASSIC','OPAK_BURGER',1.0,0,1],
        ['PASTA_BOLOGNESE','MAKARON_SPAG',0.15,0],['PASTA_BOLOGNESE','WOLOWINA_M',0.12,3],['PASTA_BOLOGNESE','SOS_POM',0.12,0],['PASTA_BOLOGNESE','CEBULA',0.03,0],
        ['SALAD_CAESAR','SALATA_RZY',0.15,5],['SALAD_CAESAR','KURCZAK',0.10,0],['SALAD_CAESAR','SER_PARM',0.03,0],['SALAD_CAESAR','OLJ_OLIWA',0.02,0],
        ['SIDE_FRIES','FRYTKI_MRZ',0.25,5],
    ];
    $stmt = $pdo->prepare("INSERT INTO sh_recipes (tenant_id,menu_item_sku,warehouse_sku,quantity_base,waste_percent,is_packaging) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE quantity_base=VALUES(quantity_base), waste_percent=VALUES(waste_percent)");
    foreach ($recipes as $r) {
        $pkg = $r[4] ?? 0;
        $stmt->execute([$T, $r[0], $r[1], $r[2], $r[3], $pkg]);
    }
    return count($recipes) . ' recipe lines';
});

// =============================================================================
// 9. PRODUCT MAPPING + MODIFIER WAREHOUSE LINKS
// =============================================================================
seed('Product Mapping + Modifier links', function ($pdo, $T) {
    $pdo->exec("INSERT INTO sh_product_mapping (tenant_id,external_name,internal_sku) VALUES
        ({$T},'Mąka pszenna Caputo \"00\"','MKA_TIPO00'),
        ({$T},'Mozzarella Fior di Latte 1kg','SER_MOZZ'),
        ({$T},'Passata pomidorowa S.Marzano 2.5L','SOS_POM'),
        ({$T},'Oliwa extra vergine Ferrini 5L','OLJ_OLIWA'),
        ({$T},'Coca-Cola 0.5L x24 zgrzewka','COCA_COLA_05'),
        ({$T},'Woda Żywiec 0.5L x12','WODA_05')
        ON DUPLICATE KEY UPDATE internal_sku=VALUES(internal_sku)");

    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='SER_MOZZ', linked_quantity=0.1 WHERE ascii_key='EXTRA_CHEESE'");
    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='JALAPENO', linked_quantity=0.03 WHERE ascii_key='EXTRA_JALAP'");
    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='OLIWKI_CZ', linked_quantity=0.03 WHERE ascii_key='EXTRA_OLIVES'");
    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='SZYNKA_PARM', linked_quantity=0.05 WHERE ascii_key='EXTRA_HAM'");
    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='SOS_CZOSN', linked_quantity=0.03 WHERE ascii_key='SAUCE_GARLIC'");
    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='SOS_BBQ', linked_quantity=0.03 WHERE ascii_key='SAUCE_BBQ'");
    $pdo->exec("UPDATE sh_modifiers SET linked_warehouse_sku='SOS_OSTRY', linked_quantity=0.03 WHERE ascii_key='SAUCE_HOT'");

    return '6 mappings, 7 modifier links';
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
        <a href="/slicehub/modules/studio/" style="background:#06b6d4;">Studio</a>
        <a href="/slicehub/modules/courses/" style="background:#a855f7;">Kursy / Dispatch</a>
        <a href="/slicehub/modules/driver_app/" style="background:#22c55e;">Driver App</a>
        <a href="/slicehub/modules/warehouse/" style="background:#f97316;">Magazyn</a>
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
