<?php

declare(strict_types=1);

/**
 * test_hr_payroll_ledger.php — regresje silosu HR / Payroll (Faza 4, audyt 2026-07-27).
 *
 * SEKCJA A (zawsze, bez bazy) — czyste klasy `core/`:
 *   - `Uuid::deterministic` GOLDEN VECTORS: wartości MUSZĄ zostać niezmienne,
 *     bo od nich zależą `entry_uuid` już zapisane w `sh_payroll_ledger`.
 *     Zmiana algorytmu = utrata idempotencji = podwójne księgowanie.
 *   - `Money`: konwersja PLN → grosze (HALF_UP), format i sanity cap.
 *   - `DomainError::split`: kontrakt `ERR_CODE (detal)` → code + message.
 *
 * SEKCJA B (opcjonalna, `--db`) — regresje na żywej bazie, W CAŁOŚCI wewnątrz
 * transakcji zakończonej ROLLBACK (skrypt nic nie zostawia po sobie):
 *   - sanity cap egzekwowany w `PayrollLedger::record`,
 *   - idempotencja `MealEngine` z `idempotency_key`,
 *   - REGRESJA fixa #2: rata zaliczki zaksięgowana wstecz (period = miesiąc M,
 *     created_at = M+1) jest widoczna w raporcie okresu M.
 *
 * Uruchomienie:
 *   php scripts/test_hr_payroll_ledger.php
 *   php scripts/test_hr_payroll_ledger.php --db [--tenant=N]
 */

require_once dirname(__DIR__) . '/core/Uuid.php';
require_once dirname(__DIR__) . '/core/Money.php';
require_once dirname(__DIR__) . '/core/DomainError.php';
require_once dirname(__DIR__) . '/core/PayrollAllocator.php';

$pass = 0;
$fail = 0;

function check(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "  [PASS] {$label}\n";
        return;
    }
    $fail++;
    echo "  [FAIL] {$label}\n";
    echo "         expected: " . var_export($expected, true) . "\n";
    echo "         actual:   " . var_export($actual, true) . "\n";
}

function checkThrows(string $label, string $expectedCodePrefix, callable $fn): void
{
    global $pass, $fail;
    try {
        $fn();
    } catch (\Throwable $e) {
        if (str_starts_with($e->getMessage(), $expectedCodePrefix)) {
            $pass++;
            echo "  [PASS] {$label}\n";
            return;
        }
        $fail++;
        echo "  [FAIL] {$label} — wrong error: {$e->getMessage()}\n";
        return;
    }
    $fail++;
    echo "  [FAIL] {$label} — no exception thrown\n";
}

echo "\n=== HR / Payroll — regresje ===\n";

// =============================================================================
// SEKCJA A1 — Uuid GOLDEN VECTORS (nie wolno ich „poprawić")
// =============================================================================
echo "\nA1. Uuid::deterministic — golden vectors\n";

// Wartości ZAMROŻONE — wyliczone algorytmem sprzed refaktoru (kopie w
// MealEngine::deterministicUuid / AdvanceEngine::synthUuid / legacyLedgerUuid).
// Jeśli którykolwiek z tych testów padnie, znaczy to, że zmiana w `Uuid` właśnie
// unieważniła idempotencję istniejących wpisów — NIE "poprawiaj" wtedy testu.
$golden = [
    'meal-1-42'          => 'c731e545-6e39-53a7-83ae-7f09cd116a86',
    'adv-pay-deadbeef'   => '525830e7-396e-54af-bd0e-3bc9dc9c2408',
    'adv-rep-deadbeef-7' => '6eddd591-d1af-5817-b95e-2bf7fcf3193a',
    'legacy-ded-7'       => '24159d94-96d1-5362-b608-39dce1f5b256',
    'legacy-meal-3'      => 'e9fce413-b7a4-50aa-a5c4-0bbc7db4c761',
];
foreach ($golden as $seed => $expected) {
    check("deterministic('{$seed}') zamrożony", $expected, Uuid::deterministic($seed));
    // Kontrola krzyżowa z kopią algorytmu sprzed konsolidacji.
    check("deterministic('{$seed}') == algorytm sprzed refaktoru",
        legacyAlgorithm($seed), Uuid::deterministic($seed));
}
check('deterministic jest stabilny (2x ten sam seed)',
    Uuid::deterministic('meal-1-42'), Uuid::deterministic('meal-1-42'));
check('deterministic zwraca wersję 5', '5', substr(Uuid::deterministic('meal-1-42'), 14, 1));
check('deterministic przechodzi isValid', true, Uuid::isValid(Uuid::deterministic('x')));
check('v4 przechodzi isValid', true, Uuid::isValid(Uuid::v4()));
check('v4 jest losowy', false, Uuid::v4() === Uuid::v4());
check('isValid odrzuca śmieci', false, Uuid::isValid('nie-uuid'));

/** Kopia algorytmu sprzed refaktoru — wyłącznie jako wartość odniesienia. */
function legacyAlgorithm(string $seed): string
{
    $hash  = sha1($seed);
    $bytes = '';
    foreach (str_split(substr($hash, 0, 32), 2) as $hex) {
        $bytes .= chr((int)hexdec($hex));
    }
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $h = bin2hex($bytes);

    return sprintf('%s-%s-%s-%s-%s',
        substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4),
        substr($h, 16, 4), substr($h, 20, 12));
}

// =============================================================================
// SEKCJA A2 — Money
// =============================================================================
echo "\nA2. Money — konwersja, format, cap\n";

check('fromPln("12.50")', 1250, Money::fromPln('12.50'));
check('fromPln(12.5)', 1250, Money::fromPln(12.5));
check('fromPln("12,50") — przecinek', 1250, Money::fromPln('12,50'));
check('fromPln(12.345) — HALF_UP mimo IEEE 754', 1235, Money::fromPln(12.345));
check('fromPln(0.1 + 0.2)', 30, Money::fromPln(0.1 + 0.2));
check('fromPln(-3.40)', -340, Money::fromPln(-3.40));
check('fromPln(0)', 0, Money::fromPln(0));
check('formatMinor(1250)', '12.50', Money::formatMinor(1250));
check('formatMinor(-1205)', '-12.05', Money::formatMinor(-1205));
check('formatMinor(5)', '0.05', Money::formatMinor(5));
check('isWithinCap(MAX)', true, Money::isWithinCap(Money::MAX_MINOR));
check('isWithinCap(MAX+1)', false, Money::isWithinCap(Money::MAX_MINOR + 1));
check('isWithinCap(-MAX-1)', false, Money::isWithinCap(-Money::MAX_MINOR - 1));
checkThrows('fromPln("abc") rzuca', 'Amount must be numeric', fn() => Money::fromPln('abc'));

// =============================================================================
// SEKCJA A3 — DomainError
// =============================================================================
echo "\nA3. DomainError::split — kontrakt code/message\n";

check('kod + detal w nawiasie',
    ['INVALID_PRICE', 'price_minor must be int > 0'],
    DomainError::split('INVALID_PRICE (price_minor must be int > 0)'));
check('sam kod',
    ['PERIOD_LOCKED', 'PERIOD_LOCKED'],
    DomainError::split('PERIOD_LOCKED'));
check('kod z detalem bez nawiasu',
    ['SIGN_MISMATCH', "type 'bonus' requires amount_minor >= 0"],
    DomainError::split("SIGN_MISMATCH type 'bonus' requires amount_minor >= 0"));
check('komunikat bez kodu → fallback',
    ['DOMAIN_ERROR', 'tenant_id must be positive.'],
    DomainError::split('tenant_id must be positive.'));
check('pusty komunikat → fallback',
    ['DOMAIN_ERROR', 'Domain rule violation.'],
    DomainError::split('   '));

// =============================================================================
// SEKCJA A4 — PayrollAllocator (HR-6: sesja przecinająca koniec miesiąca)
// =============================================================================
echo "\nA4. PayrollAllocator — podział sesji i kwoty\n";

// --- splitByPeriod ----------------------------------------------------------

check('sesja w jednym miesiącu → 1 segment',
    [['year' => 2026, 'month' => 7, 'seconds' => 28800]],
    PayrollAllocator::splitByPeriod('2026-07-15 08:00:00', '2026-07-15 16:00:00'));

// KANONICZNY PRZYPADEK HR-6: 31.07 22:00 → 01.08 06:00.
// 2h w lipcu, 6h w sierpniu. Przed fixem: 8h w lipcu.
check('nocna zmiana na przełomie miesiąca → 2 segmenty',
    [
        ['year' => 2026, 'month' => 7, 'seconds' => 7200],
        ['year' => 2026, 'month' => 8, 'seconds' => 21600],
    ],
    PayrollAllocator::splitByPeriod('2026-07-31 22:00:00', '2026-08-01 06:00:00'));

check('przełom roku 31.12 → 01.01',
    [
        ['year' => 2026, 'month' => 12, 'seconds' => 3600],
        ['year' => 2027, 'month' => 1,  'seconds' => 3600],
    ],
    PayrollAllocator::splitByPeriod('2026-12-31 23:00:00', '2027-01-01 01:00:00'));

check('północ w środku miesiąca NIE dzieli (to nie granica okresu)',
    [['year' => 2026, 'month' => 7, 'seconds' => 28800]],
    PayrollAllocator::splitByPeriod('2026-07-15 22:00:00', '2026-07-16 06:00:00'));

check('end == start → brak segmentów',
    [], PayrollAllocator::splitByPeriod('2026-07-15 08:00:00', '2026-07-15 08:00:00'));

check('end < start → brak segmentów (nie zgadujemy)',
    [], PayrollAllocator::splitByPeriod('2026-07-15 08:00:00', '2026-07-15 07:00:00'));

checkThrows('nieparsowalna data rzuca', 'INVALID_DATETIME',
    fn() => PayrollAllocator::splitByPeriod('nie-data', '2026-07-15 08:00:00'));

// Sesja obejmująca cały luty (28 dni) + brzegi — sanity na długim zakresie.
$long = PayrollAllocator::splitByPeriod('2026-01-31 23:00:00', '2026-03-01 01:00:00');
check('sesja przez 3 miesiące → 3 segmenty', 3, count($long));
check('suma sekund == realny czas trwania',
    (new DateTimeImmutable('2026-03-01 01:00:00', new DateTimeZone('UTC')))->getTimestamp()
        - (new DateTimeImmutable('2026-01-31 23:00:00', new DateTimeZone('UTC')))->getTimestamp(),
    array_sum(array_column($long, 'seconds')));

// --- allocate ---------------------------------------------------------------

check('podział 2h/6h kwoty 24000 gr', [6000, 18000],
    PayrollAllocator::allocate(24000, [7200, 21600]));

check('jeden segment dostaje całość', [24000],
    PayrollAllocator::allocate(24000, [28800]));

// ŚWIĘTOŚĆ PIENIĄDZA: suma segmentów MUSI równać się kwocie wejściowej.
// 1000 gr na 3 równe części to klasyczny test na wyciek grosza.
$thirds = PayrollAllocator::allocate(1000, [100, 100, 100]);
check('podział niepodzielny sumuje się dokładnie', 1000, array_sum($thirds));
check('reszta trafia do pierwszego segmentu (deterministycznie)', [334, 333, 333], $thirds);

check('kwota ujemna zachowuje sumę', -1000,
    array_sum(PayrollAllocator::allocate(-1000, [100, 100, 100])));

check('kwota 0 → same zera', [0, 0], PayrollAllocator::allocate(0, [7200, 21600]));

check('segment zerowej długości nie dostaje nic', [24000, 0],
    PayrollAllocator::allocate(24000, [28800, 0]));

// Fuzz: dla losowych kwot i podziałów suma zawsze musi się zgadzać.
$driftFree = true;
mt_srand(20260727);
for ($i = 0; $i < 500; $i++) {
    $total = mt_rand(1, 10_000_000);
    $n     = mt_rand(2, 5);
    $w     = [];
    for ($k = 0; $k < $n; $k++) {
        $w[] = mt_rand(1, 2_678_400);
    }
    if (array_sum(PayrollAllocator::allocate($total, $w)) !== $total) {
        $driftFree = false;
        break;
    }
}
check('fuzz 500× — zero groszowego wycieku', true, $driftFree);

checkThrows('puste wagi rzucają', 'EMPTY_WEIGHTS',
    fn() => PayrollAllocator::allocate(100, []));
checkThrows('same zerowe wagi rzucają', 'ZERO_TOTAL_WEIGHT',
    fn() => PayrollAllocator::allocate(100, [0, 0]));
checkThrows('ujemna waga rzuca', 'INVALID_WEIGHT',
    fn() => PayrollAllocator::allocate(100, [10, -5]));

// --- scenariusz end-to-end (arytmetyka workera, bez bazy) -------------------
// 8h × 30.00 PLN/h = 240.00 PLN, zmiana 31.07 22:00 → 01.08 06:00.
$segs    = PayrollAllocator::splitByPeriod('2026-07-31 22:00:00', '2026-08-01 06:00:00');
$w       = array_column($segs, 'seconds');
$total   = 24000;
$amounts = PayrollAllocator::allocate($total, $w);
check('E2E: lipiec dostaje 60.00 PLN', 6000, $amounts[0]);
check('E2E: sierpień dostaje 180.00 PLN', 18000, $amounts[1]);
check('E2E: suma == 240.00 PLN (nic nie zginęło)', $total, array_sum($amounts));

// =============================================================================
// SEKCJA B — regresje na bazie (opt-in: --db), wszystko w ROLLBACK
// =============================================================================
$withDb = in_array('--db', $argv ?? [], true);
if (!$withDb) {
    echo "\nB. Regresje bazodanowe — POMINIĘTE (dodaj --db, żeby uruchomić).\n";
} else {
    echo "\nB. Regresje bazodanowe (transakcja + ROLLBACK)\n";
    runDbSection($argv ?? []);
}

function runDbSection(array $argv): void
{
    global $pass, $fail, $pdo;

    require_once dirname(__DIR__) . '/core/db_config.php';
    require_once dirname(__DIR__) . '/core/PayrollLedger.php';
    require_once dirname(__DIR__) . '/core/MealEngine.php';
    require_once dirname(__DIR__) . '/core/PayrollEngine.php';

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tenantId = null;
    foreach ($argv as $arg) {
        if (preg_match('/^--tenant=(\d+)$/', $arg, $m)) {
            $tenantId = (int)$m[1];
        }
    }

    // Pracownik z kontem sh_users (MealEngine wymaga FK sh_meals.user_id).
    $sql = "
        SELECT e.id AS employee_id, e.tenant_id, e.user_id
        FROM sh_employees e
        WHERE e.is_deleted = 0 AND e.user_id IS NOT NULL
    " . ($tenantId !== null ? ' AND e.tenant_id = ' . $tenantId : '') . "
        ORDER BY e.id ASC LIMIT 1
    ";
    $emp = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        echo "  [SKIP] brak pracownika z kontem sh_users — uruchom seed_demo_all.php\n";
        return;
    }
    $tid = (int)$emp['tenant_id'];
    $eid = (int)$emp['employee_id'];
    echo "  (tenant={$tid}, employee={$eid})\n";

    // Okres testowy: poprzedni miesiąc (zamknięty kalendarzowo, ale NIE locked).
    $prev  = (new DateTimeImmutable('first day of last month'))->setTime(0, 0);
    $pYear = (int)$prev->format('Y');
    $pMon  = (int)$prev->format('n');

    if (PayrollLedger::isPeriodLocked($pdo, $tid, $pYear, $pMon)) {
        echo "  [SKIP] okres {$pYear}-{$pMon} jest zamknięty — regresja repayment pominięta\n";
        return;
    }

    $pdo->beginTransaction();
    try {
        // B1 — sanity cap w jedynej bramie zapisu
        checkThrows('PayrollLedger::record odrzuca kwotę > Money::MAX_MINOR', 'INVALID_AMOUNT',
            function () use ($pdo, $tid, $eid, $pYear, $pMon) {
                PayrollLedger::record($pdo, $tid, [
                    'employee_id'  => $eid,
                    'period_year'  => $pYear,
                    'period_month' => $pMon,
                    'entry_type'   => PayrollLedger::TYPE_BONUS,
                    'amount_minor' => Money::MAX_MINOR + 1,
                    'description'  => 'TEST cap',
                ]);
            });

        // B2 — idempotencja posiłku
        $key   = Uuid::v4();
        $first = MealEngine::record($pdo, $tid, [
            'employee_id'     => $eid,
            'price_minor'     => 1234,
            'description'     => 'TEST meal idempotency',
            'idempotency_key' => $key,
        ]);
        $again = MealEngine::record($pdo, $tid, [
            'employee_id'     => $eid,
            'price_minor'     => 1234,
            'description'     => 'TEST meal idempotency',
            'idempotency_key' => $key,
        ]);
        check('MealEngine: retry z tym samym kluczem nie tworzy 2. posiłku',
            $first['meal_id'], $again['meal_id']);
        check('MealEngine: retry oznaczony jako duplicate', true, (bool)$again['duplicate']);
        check('MealEngine: retry wskazuje ten sam wpis ledgera',
            $first['ledger_entry_id'], $again['ledger_entry_id']);

        $mealCount = $pdo->prepare('SELECT COUNT(*) FROM sh_meals WHERE tenant_id = :t AND id = :m');
        $mealCount->execute([':t' => $tid, ':m' => $first['meal_id']]);
        check('MealEngine: dokładnie 1 wiersz sh_meals', 1, (int)$mealCount->fetchColumn());

        // B3 — REGRESJA fixa #2: wpis księgowany wstecz (period != miesiąc created_at)
        $before = PayrollEngine::calculate($pdo, $tid, (string)$emp['user_id'], 'month', 1);
        $repaidBefore = (float)$before['payroll']['advances']['repaid'];

        PayrollLedger::record($pdo, $tid, [
            'employee_id'  => $eid,
            'period_year'  => $pYear,
            'period_month' => $pMon,
            'entry_type'   => PayrollLedger::TYPE_ADVANCE_REPAYMENT,
            'amount_minor' => -5000,          // 50,00 zł
            'description'  => 'TEST back-dated repayment',
        ]);

        $after = PayrollEngine::calculate($pdo, $tid, (string)$emp['user_id'], 'month', 1);
        $repaidAfter = (float)$after['payroll']['advances']['repaid'];

        check('PayrollEngine widzi ratę zaksięgowaną wstecz (created_at poza oknem)',
            round($repaidBefore + 50.0, 2), round($repaidAfter, 2));

        $pdo->rollBack();
        echo "  (ROLLBACK — baza nietknięta)\n";
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $fail++;
        echo "  [FAIL] sekcja B wyjątek: " . $e->getMessage() . "\n";
    }
}

echo "\n=== WYNIK: {$pass} PASS / {$fail} FAIL ===\n";
exit($fail === 0 ? 0 : 1);
