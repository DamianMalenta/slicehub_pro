<?php

declare(strict_types=1);

/**
 * PayrollLedger — append-only writer dla `sh_payroll_ledger`.
 *
 * ŚWIĘTOŚĆ PIENIĄDZA (§KONSTYTUCJA Backoffice):
 *   1. **Wpisy są immutable.** Klasa NIE udostępnia `update()` ani `delete()`.
 *      Korekta błędnego wpisu = nowy wpis kompensujący przez `reverse()`.
 *   2. **Wszystkie kwoty to `int` grosze.** `float` są odrzucane (`INVALID_AMOUNT`).
 *      To eliminuje ryzyko utraty precyzji IEEE 754 (klasyczny bug „0.1 + 0.2 != 0.3").
 *   3. **tenant_id w każdym zapytaniu** — SELECT i INSERT zawsze skalowane do tenantu
 *      z pierwszego argumentu. Zero zaufania do danych z `$payload`.
 *   4. **Sign-per-type** — `entry_type` narzuca znak kwoty:
 *        work_earnings / advance_payment / bonus  →  amount_minor >= 0
 *        meal_deduction / advance_repayment       →  amount_minor <= 0
 *        adjustment / reversal                    →  dowolny znak
 *   5. **Cross-tenant leak guard** — każde `ref_*_id` weryfikowane:
 *      referowana tabela musi mieć wiersz o danym `id` W TYM SAMYM tenant.
 *   6. **Idempotency** — `entry_uuid` jest UNIQUE; drugi `record()` z tym samym
 *      UUID zwraca id już istniejącego wpisu bez błędu (idempotent retry).
 *
 * Spec: _docs/18_BACKOFFICE_HR_LOGIC.md §3.5 + §9
 */
final class PayrollLedger
{
    // === WHITELIST: dozwolone entry_type (ASCII) ============================
    public const TYPE_WORK_EARNINGS     = 'work_earnings';
    public const TYPE_MEAL_DEDUCTION    = 'meal_deduction';
    public const TYPE_ADVANCE_PAYMENT   = 'advance_payment';
    public const TYPE_ADVANCE_REPAYMENT = 'advance_repayment';
    public const TYPE_BONUS             = 'bonus';
    public const TYPE_ADJUSTMENT        = 'adjustment';
    public const TYPE_REVERSAL          = 'reversal';

    public const ENTRY_TYPES = [
        self::TYPE_WORK_EARNINGS,
        self::TYPE_MEAL_DEDUCTION,
        self::TYPE_ADVANCE_PAYMENT,
        self::TYPE_ADVANCE_REPAYMENT,
        self::TYPE_BONUS,
        self::TYPE_ADJUSTMENT,
        self::TYPE_REVERSAL,
    ];

    /** @var list<string> Typy, które muszą mieć amount >= 0. */
    private const POSITIVE_TYPES = [
        self::TYPE_WORK_EARNINGS,
        self::TYPE_ADVANCE_PAYMENT,
        self::TYPE_BONUS,
    ];

    /** @var list<string> Typy, które muszą mieć amount <= 0. */
    private const NEGATIVE_TYPES = [
        self::TYPE_MEAL_DEDUCTION,
        self::TYPE_ADVANCE_REPAYMENT,
    ];

    // === ASCII error codes (kontrakt klasy) =================================
    public const ERR_INVALID_AMOUNT            = 'INVALID_AMOUNT';
    public const ERR_INVALID_CURRENCY          = 'INVALID_CURRENCY';
    public const ERR_INVALID_ENTRY_TYPE        = 'INVALID_ENTRY_TYPE';
    public const ERR_INVALID_PERIOD            = 'INVALID_PERIOD';
    public const ERR_SIGN_MISMATCH             = 'SIGN_MISMATCH';
    public const ERR_EMPLOYEE_NOT_FOUND        = 'EMPLOYEE_NOT_FOUND';
    public const ERR_REFERENCE_TENANT_MISMATCH = 'REFERENCE_TENANT_MISMATCH';
    public const ERR_ORIGINAL_NOT_FOUND        = 'ORIGINAL_NOT_FOUND';
    public const ERR_ORIGINAL_ALREADY_REVERSED = 'ORIGINAL_ALREADY_REVERSED';
    public const ERR_ORIGINAL_IS_REVERSAL      = 'ORIGINAL_IS_REVERSAL';
    public const ERR_REVERSAL_NOT_ALLOWED      = 'REVERSAL_NOT_ALLOWED';

    // =========================================================================
    // PUBLIC API — tylko dwie pisarskie metody: record() i reverse().
    // Brak update() i brak delete() — to jest append-only.
    // =========================================================================

    /**
     * Zapisuje nowy wpis do ledgera.
     *
     * @param PDO   $pdo
     * @param int   $tenantId  autorytatywny tenant (z sesji/JWT), NIE z payloadu
     * @param array $payload {
     *   entry_uuid?:          string,       // opcjonalne; brak → generujemy UUID v4
     *   employee_id:          int,          // WYMAGANE
     *   period_year:          int,          // WYMAGANE (2000–2099)
     *   period_month:         int,          // WYMAGANE (1–12)
     *   entry_type:           string,       // WYMAGANE (self::ENTRY_TYPES)
     *   amount_minor:         int,          // WYMAGANE — STRICT int, grosze SIGNED
     *   currency?:            string,       // default 'PLN'; 3 znaki ASCII A-Z
     *   hours_qty?:           float|null,
     *   rate_applied_minor?:  int|null,     // grosze UNSIGNED (≥ 0)
     *   ref_work_session_id?: int|null,
     *   ref_advance_id?:      int|null,
     *   ref_installment_id?:  int|null,
     *   ref_meal_id?:         int|null,
     *   reverses_entry_id?:   int|null,     // ustawia tylko reverse(); manualnie NIEZALECANE
     *   description?:         string|null,  // max 255 znaków
     *   created_by_user_id?:  int|null
     * }
     *
     * @return int id nowego wpisu (lub id istniejącego gdy UUID był już użyty — idempotency)
     *
     * @throws \InvalidArgumentException  gdy tenant_id niepoprawny
     * @throws \RuntimeException          z ERR_* code (walidacje domenowe)
     * @throws \Throwable                 błąd DB
     */
    public static function record(PDO $pdo, int $tenantId, array $payload): int
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('tenant_id must be positive.');
        }

        // --- 1. Wymagane pola --------------------------------------------
        $employeeId  = (int)($payload['employee_id']  ?? 0);
        $periodYear  = (int)($payload['period_year']  ?? 0);
        $periodMonth = (int)($payload['period_month'] ?? 0);
        $entryType   = trim((string)($payload['entry_type'] ?? ''));

        if ($employeeId <= 0) {
            throw new \InvalidArgumentException('employee_id must be positive.');
        }

        // --- 2. entry_type whitelist -------------------------------------
        if (!in_array($entryType, self::ENTRY_TYPES, true)) {
            throw new \RuntimeException(self::ERR_INVALID_ENTRY_TYPE);
        }

        // --- 3. period range ---------------------------------------------
        if ($periodYear < 2000 || $periodYear > 2099) {
            throw new \RuntimeException(self::ERR_INVALID_PERIOD);
        }
        if ($periodMonth < 1 || $periodMonth > 12) {
            throw new \RuntimeException(self::ERR_INVALID_PERIOD);
        }

        // --- 4. amount_minor: STRICT int ---------------------------------
        if (!array_key_exists('amount_minor', $payload)) {
            throw new \RuntimeException(self::ERR_INVALID_AMOUNT . ' (missing)');
        }
        $amount = $payload['amount_minor'];
        if (!is_int($amount)) {
            throw new \RuntimeException(
                self::ERR_INVALID_AMOUNT . ' (must be int, got ' . gettype($amount) . ')'
            );
        }
        if ($amount < PHP_INT_MIN || $amount > PHP_INT_MAX) {
            throw new \RuntimeException(self::ERR_INVALID_AMOUNT . ' (out of range)');
        }

        // --- 5. sign per entry_type -------------------------------------
        if (in_array($entryType, self::POSITIVE_TYPES, true) && $amount < 0) {
            throw new \RuntimeException(
                self::ERR_SIGN_MISMATCH . " (type '{$entryType}' requires amount_minor >= 0, got {$amount})"
            );
        }
        if (in_array($entryType, self::NEGATIVE_TYPES, true) && $amount > 0) {
            throw new \RuntimeException(
                self::ERR_SIGN_MISMATCH . " (type '{$entryType}' requires amount_minor <= 0, got {$amount})"
            );
        }

        // --- 6. currency — ISO 4217 format (3 znaki ASCII uppercase) ----
        $currency = strtoupper(trim((string)($payload['currency'] ?? 'PLN')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \RuntimeException(self::ERR_INVALID_CURRENCY);
        }

        // --- 7. employee musi należeć do tenanta -------------------------
        self::assertEmployeeBelongsToTenant($pdo, $tenantId, $employeeId);

        // --- 8. cross-tenant ref guard -----------------------------------
        $refWorkSession = self::optionalPositiveInt($payload, 'ref_work_session_id');
        $refAdvance     = self::optionalPositiveInt($payload, 'ref_advance_id');
        $refInstallment = self::optionalPositiveInt($payload, 'ref_installment_id');
        $refMeal        = self::optionalPositiveInt($payload, 'ref_meal_id');
        $reversesEntry  = self::optionalPositiveInt($payload, 'reverses_entry_id');

        if ($refWorkSession !== null) self::assertRefBelongsToTenant($pdo, $tenantId, 'sh_work_sessions', $refWorkSession);
        if ($refAdvance !== null)     self::assertRefBelongsToTenant($pdo, $tenantId, 'sh_advances', $refAdvance);
        if ($refInstallment !== null) self::assertRefBelongsToTenant($pdo, $tenantId, 'sh_advance_installments', $refInstallment);
        if ($refMeal !== null)        self::assertRefBelongsToTenant($pdo, $tenantId, 'sh_meals', $refMeal);
        if ($reversesEntry !== null)  self::assertRefBelongsToTenant($pdo, $tenantId, 'sh_payroll_ledger', $reversesEntry);

        // --- 9. hours_qty / rate_applied_minor — sanity -------------------
        $hoursQty = null;
        if (array_key_exists('hours_qty', $payload) && $payload['hours_qty'] !== null) {
            if (!is_int($payload['hours_qty']) && !is_float($payload['hours_qty'])) {
                throw new \RuntimeException('INVALID_HOURS_QTY');
            }
            $hoursQty = (float)$payload['hours_qty'];
        }
        $rateApplied = null;
        if (array_key_exists('rate_applied_minor', $payload) && $payload['rate_applied_minor'] !== null) {
            $rA = $payload['rate_applied_minor'];
            if (!is_int($rA) || $rA < 0) {
                throw new \RuntimeException('INVALID_RATE_APPLIED');
            }
            $rateApplied = $rA;
        }

        // --- 10. entry_uuid: idempotency key ------------------------------
        $entryUuid = trim((string)($payload['entry_uuid'] ?? ''));
        if ($entryUuid === '') {
            $entryUuid = self::uuidV4();
        } elseif (!self::isValidUuid($entryUuid)) {
            throw new \InvalidArgumentException('entry_uuid must be a valid UUID.');
        }

        $existing = self::findByUuid($pdo, $tenantId, $entryUuid);
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        // --- 11. description sanitacja -----------------------------------
        $description = null;
        if (array_key_exists('description', $payload) && $payload['description'] !== null) {
            $description = mb_substr(trim((string)$payload['description']), 0, 255);
            if ($description === '') $description = null;
        }

        $createdBy = self::optionalPositiveInt($payload, 'created_by_user_id');

        // --- 12. INSERT --------------------------------------------------
        $stmt = $pdo->prepare("
            INSERT INTO sh_payroll_ledger
                (entry_uuid, tenant_id, employee_id,
                 period_year, period_month,
                 entry_type, amount_minor, currency,
                 hours_qty, rate_applied_minor,
                 ref_work_session_id, ref_advance_id, ref_installment_id, ref_meal_id,
                 reverses_entry_id,
                 description, created_by_user_id,
                 created_at, is_locked)
            VALUES
                (:uuid, :tid, :eid,
                 :py, :pm,
                 :etype, :amt, :cur,
                 :hours, :rate,
                 :ws, :adv, :inst, :meal,
                 :rev,
                 :desc, :created_by,
                 NOW(), 0)
        ");

        $stmt->execute([
            ':uuid'       => $entryUuid,
            ':tid'        => $tenantId,
            ':eid'        => $employeeId,
            ':py'         => $periodYear,
            ':pm'         => $periodMonth,
            ':etype'      => $entryType,
            ':amt'        => $amount,
            ':cur'        => $currency,
            ':hours'      => $hoursQty,
            ':rate'       => $rateApplied,
            ':ws'         => $refWorkSession,
            ':adv'        => $refAdvance,
            ':inst'       => $refInstallment,
            ':meal'       => $refMeal,
            ':rev'        => $reversesEntry,
            ':desc'       => $description,
            ':created_by' => $createdBy,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Generuje wpis KOMPENSUJĄCY (reverse) dla istniejącego wpisu.
     *
     * Wzorzec: oryginalny wpis zostaje w bazie nietknięty. Dodajemy nowy wpis:
     *   - `entry_type` = 'reversal'
     *   - `amount_minor` = -original.amount_minor
     *   - `hours_qty` = -original.hours_qty (jeśli było)
     *   - `reverses_entry_id` = original.id
     *   - wszystkie refs (work_session / advance / installment / meal) przeniesione
     *     z oryginału — dla audytu można jednym zapytaniem zobaczyć pełny cykl.
     *
     * Guard: nie można odwrócić wpisu, który już został odwrócony
     *        ani wpisu, który sam jest odwrotnością (no chain reverses).
     *
     * @param int    $originalId    id wpisu do odwrócenia
     * @param string $reason        ASCII / UTF-8 text, wymagane (audit trail)
     * @param int|null $actorUserId aktor wykonujący reversal (sh_users.id)
     *
     * @return int id nowego wpisu-kompensującego
     */
    public static function reverse(PDO $pdo, int $tenantId, int $originalId, string $reason, ?int $actorUserId = null): int
    {
        if ($tenantId <= 0 || $originalId <= 0) {
            throw new \InvalidArgumentException('tenant_id and original_id must be positive.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('reason is required for reversal (audit trail).');
        }

        // Załaduj oryginał — skalowany do tenanta
        $stmt = $pdo->prepare("
            SELECT id, employee_id, period_year, period_month,
                   entry_type, amount_minor, currency, hours_qty, rate_applied_minor,
                   ref_work_session_id, ref_advance_id, ref_installment_id, ref_meal_id,
                   reverses_entry_id
            FROM sh_payroll_ledger
            WHERE id = :id AND tenant_id = :tid
            LIMIT 1
        ");
        $stmt->execute([':id' => $originalId, ':tid' => $tenantId]);
        $orig = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$orig) {
            throw new \RuntimeException(self::ERR_ORIGINAL_NOT_FOUND);
        }

        // Blokuj chain-reverse: odwrotność odwrotności
        if ($orig['entry_type'] === self::TYPE_REVERSAL || ((int)($orig['reverses_entry_id'] ?? 0)) > 0) {
            throw new \RuntimeException(self::ERR_ORIGINAL_IS_REVERSAL);
        }

        // Sprawdź, czy już nie ma reversala dla tego wpisu
        $stmt = $pdo->prepare("
            SELECT id FROM sh_payroll_ledger
            WHERE tenant_id = :tid AND reverses_entry_id = :rid
            LIMIT 1
        ");
        $stmt->execute([':tid' => $tenantId, ':rid' => $originalId]);
        if ($stmt->fetchColumn()) {
            throw new \RuntimeException(self::ERR_ORIGINAL_ALREADY_REVERSED);
        }

        $reversalAmount = -1 * (int)$orig['amount_minor'];
        $reversalHours  = $orig['hours_qty'] !== null ? -1.0 * (float)$orig['hours_qty'] : null;
        $description    = 'REVERSE of ledger #' . $originalId . ': ' . $reason;

        return self::record($pdo, $tenantId, [
            'employee_id'         => (int)$orig['employee_id'],
            'period_year'         => (int)$orig['period_year'],
            'period_month'        => (int)$orig['period_month'],
            'entry_type'          => self::TYPE_REVERSAL,
            'amount_minor'        => $reversalAmount,
            'currency'            => (string)$orig['currency'],
            'hours_qty'           => $reversalHours,
            'rate_applied_minor'  => $orig['rate_applied_minor'] !== null ? (int)$orig['rate_applied_minor'] : null,
            'ref_work_session_id' => $orig['ref_work_session_id'] !== null ? (int)$orig['ref_work_session_id'] : null,
            'ref_advance_id'      => $orig['ref_advance_id']      !== null ? (int)$orig['ref_advance_id']      : null,
            'ref_installment_id'  => $orig['ref_installment_id']  !== null ? (int)$orig['ref_installment_id']  : null,
            'ref_meal_id'         => $orig['ref_meal_id']         !== null ? (int)$orig['ref_meal_id']         : null,
            'reverses_entry_id'   => $originalId,
            'description'         => $description,
            'created_by_user_id'  => $actorUserId,
        ]);
    }

    // =========================================================================
    // READERS (tylko SELECT — nie modyfikują niczego)
    // =========================================================================

    /**
     * Zwraca wpis po id, lub null. Scoped do tenanta.
     * @return array<string,mixed>|null
     */
    public static function getById(PDO $pdo, int $tenantId, int $id): ?array
    {
        if ($tenantId <= 0 || $id <= 0) return null;
        $stmt = $pdo->prepare("
            SELECT * FROM sh_payroll_ledger
            WHERE id = :id AND tenant_id = :tid
            LIMIT 1
        ");
        $stmt->execute([':id' => $id, ':tid' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Zwraca wpis po UUID. Scoped do tenanta.
     * @return array<string,mixed>|null
     */
    public static function getByUuid(PDO $pdo, int $tenantId, string $uuid): ?array
    {
        return self::findByUuid($pdo, $tenantId, $uuid);
    }

    /**
     * Agreguje wpisy w okresie rozliczeniowym per-currency:
     *   earnings_minor  (sum(amount where amount > 0))
     *   deductions_minor (sum(amount where amount < 0))
     *   net_minor        (sum(amount))
     *   entries_count
     *
     * @return array<string, array{earnings_minor:int, deductions_minor:int, net_minor:int, entries_count:int}>
     */
    public static function sumForPeriod(PDO $pdo, int $tenantId, int $employeeId, int $year, int $month): array
    {
        if ($tenantId <= 0 || $employeeId <= 0) return [];
        $stmt = $pdo->prepare("
            SELECT currency,
                   SUM(CASE WHEN amount_minor > 0 THEN amount_minor ELSE 0 END) AS earnings,
                   SUM(CASE WHEN amount_minor < 0 THEN amount_minor ELSE 0 END) AS deductions,
                   SUM(amount_minor) AS net,
                   COUNT(*)           AS cnt
            FROM sh_payroll_ledger
            WHERE tenant_id = :tid
              AND employee_id = :eid
              AND period_year = :py
              AND period_month = :pm
            GROUP BY currency
        ");
        $stmt->execute([
            ':tid' => $tenantId,
            ':eid' => $employeeId,
            ':py'  => $year,
            ':pm'  => $month,
        ]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['currency']] = [
                'earnings_minor'   => (int)$r['earnings'],
                'deductions_minor' => (int)$r['deductions'],
                'net_minor'        => (int)$r['net'],
                'entries_count'    => (int)$r['cnt'],
            ];
        }
        return $out;
    }

    /**
     * Lista wpisów w okresie, posortowana chronologicznie.
     * @return list<array<string,mixed>>
     */
    public static function listForPeriod(PDO $pdo, int $tenantId, int $employeeId, int $year, int $month): array
    {
        if ($tenantId <= 0 || $employeeId <= 0) return [];
        $stmt = $pdo->prepare("
            SELECT id, entry_uuid, entry_type, amount_minor, currency,
                   hours_qty, rate_applied_minor,
                   ref_work_session_id, ref_advance_id, ref_installment_id, ref_meal_id,
                   reverses_entry_id,
                   description, created_by_user_id, created_at, is_locked
            FROM sh_payroll_ledger
            WHERE tenant_id = :tid
              AND employee_id = :eid
              AND period_year = :py
              AND period_month = :pm
            ORDER BY created_at ASC, id ASC
        ");
        $stmt->execute([
            ':tid' => $tenantId,
            ':eid' => $employeeId,
            ':py'  => $year,
            ':pm'  => $month,
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private static function findByUuid(PDO $pdo, int $tenantId, string $uuid): ?array
    {
        if ($uuid === '' || $tenantId <= 0) return null;
        $stmt = $pdo->prepare("
            SELECT * FROM sh_payroll_ledger
            WHERE tenant_id = :tid AND entry_uuid = :uuid
            LIMIT 1
        ");
        $stmt->execute([':tid' => $tenantId, ':uuid' => $uuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function assertEmployeeBelongsToTenant(PDO $pdo, int $tenantId, int $employeeId): void
    {
        $stmt = $pdo->prepare("
            SELECT 1 FROM sh_employees
            WHERE id = :id AND tenant_id = :tid AND is_deleted = 0
            LIMIT 1
        ");
        $stmt->execute([':id' => $employeeId, ':tid' => $tenantId]);
        if (!$stmt->fetchColumn()) {
            throw new \RuntimeException(self::ERR_EMPLOYEE_NOT_FOUND);
        }
    }

    /**
     * Whitelist tabel referencyjnych (prevent SQL injection przez parametr $table).
     * @var list<string>
     */
    private const REF_TABLE_WHITELIST = [
        'sh_work_sessions',
        'sh_advances',
        'sh_advance_installments',
        'sh_meals',
        'sh_payroll_ledger',
    ];

    private static function assertRefBelongsToTenant(PDO $pdo, int $tenantId, string $table, int $refId): void
    {
        if (!in_array($table, self::REF_TABLE_WHITELIST, true)) {
            throw new \RuntimeException(self::ERR_REFERENCE_TENANT_MISMATCH . " (unknown table: {$table})");
        }
        // Tabela z whitelisty — bezpiecznie interpolować w SQL
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id AND tenant_id = :tid LIMIT 1");
        $stmt->execute([':id' => $refId, ':tid' => $tenantId]);
        if (!$stmt->fetchColumn()) {
            throw new \RuntimeException(
                self::ERR_REFERENCE_TENANT_MISMATCH . " ({$table}#{$refId} does not belong to tenant {$tenantId})"
            );
        }
    }

    private static function optionalPositiveInt(array $payload, string $key): ?int
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }
        $v = $payload[$key];
        if (!is_int($v) || $v <= 0) {
            throw new \InvalidArgumentException("{$key} must be a positive int if provided.");
        }
        return $v;
    }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return sprintf('%s-%s-%s-%s-%s',
            substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4),
            substr($h, 16, 4), substr($h, 20, 12));
    }

    private static function isValidUuid(string $uuid): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid);
    }
}
