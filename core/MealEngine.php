<?php

declare(strict_types=1);

require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/Uuid.php';
require_once __DIR__ . '/PayrollLedger.php';

/**
 * MealEngine — kanoniczny pisarz posiłków pracowniczych (`sh_meals`).
 *
 * Faza 4 (_docs/18_BACKOFFICE_HR_LOGIC.md §6.1): `sh_meals` zostaje jako tabela
 * domenowa (który posiłek, kiedy wydany), ale KAŻDY nowy rekord natychmiast
 * emituje wpis `meal_deduction` do `sh_payroll_ledger` (SSOT wyliczeń payrollu).
 * Oba zapisy dzieją się w JEDNEJ transakcji — nie istnieje stan, w którym
 * posiłek jest zapisany, a potrącenie nie (i odwrotnie).
 *
 * RYGOR:
 *   - tenant_id w każdym zapytaniu (autorytatywny z sesji/JWT, nigdy z payloadu).
 *   - Kwoty jako int grosze — zero floatów w pipeline pieniędzy.
 *   - PERIOD_LOCKED z ledgera wycofuje CAŁĄ transakcję — posiłek nie wpada
 *     "bokiem" do zamkniętego okresu.
 *
 * IDEMPOTENCJA (uczciwie):
 *   - BEZ `idempotency_key` retry HTTP tworzy NOWY posiłek i NOWE potrącenie.
 *     Deterministyczny `entry_uuid` liczony z świeżo nadanego `sh_meals.id`
 *     chroni tylko przed powtórnym zaksięgowaniem TEGO SAMEGO posiłku.
 *   - Z `idempotency_key` (UUID od klienta — POS/UI) drugie wywołanie nie zapisuje
 *     niczego i zwraca poprzedni wynik. SSOT dedupu to `sh_payroll_ledger`
 *     (`entry_uuid` UNIQUE + `ref_meal_id`) — bez nowej kolumny w `sh_meals`.
 */
final class MealEngine
{
    public const ERR_INVALID_PRICE       = 'INVALID_PRICE';
    public const ERR_EMPLOYEE_NOT_FOUND  = 'EMPLOYEE_NOT_FOUND';
    public const ERR_EMPLOYEE_NO_ACCOUNT = 'EMPLOYEE_NO_ACCOUNT';
    public const ERR_INVALID_IDEMPOTENCY = 'INVALID_IDEMPOTENCY_KEY';

    /**
     * Rejestruje posiłek pracowniczy + potrącenie w ledgerze (atomowo).
     *
     * @param PDO   $pdo
     * @param int   $tenantId autorytatywny tenant (z sesji/JWT)
     * @param array $payload {
     *   employee_id:         int,         // WYMAGANE — sh_employees.id (ten tenant)
     *   price_minor:         int,         // WYMAGANE — cena pracownicza w groszach, > 0
     *   description?:        string|null, // np. nazwa posiłku
     *   created_by_user_id?: int|null,    // aktor (manager / self)
     *   idempotency_key?:    string|null  // UUID od klienta — chroni przed retry
     * }
     *
     * @return array{meal_id:int, ledger_entry_id:int, amount_minor:int, duplicate:bool}
     *
     * @throws \InvalidArgumentException / \RuntimeException (kody ERR_*)
     */
    public static function record(PDO $pdo, int $tenantId, array $payload): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('tenant_id must be positive.');
        }

        $employeeId = (int)($payload['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            throw new \InvalidArgumentException('employee_id must be positive.');
        }

        $priceMinor = $payload['price_minor'] ?? null;
        if (!is_int($priceMinor) || $priceMinor <= 0) {
            throw new \RuntimeException(self::ERR_INVALID_PRICE . ' (price_minor must be int > 0)');
        }

        // Pracownik musi należeć do tenanta i mieć powiązane konto sh_users
        // (sh_meals.user_id ma FK do sh_users — schema 001).
        $st = $pdo->prepare('
            SELECT user_id FROM sh_employees
            WHERE id = :eid AND tenant_id = :tid AND is_deleted = 0
            LIMIT 1
        ');
        $st->execute([':eid' => $employeeId, ':tid' => $tenantId]);
        $userId = $st->fetchColumn();
        if ($userId === false) {
            throw new \RuntimeException(self::ERR_EMPLOYEE_NOT_FOUND);
        }
        if ($userId === null || (int)$userId <= 0) {
            throw new \RuntimeException(self::ERR_EMPLOYEE_NO_ACCOUNT . ' (sh_meals.user_id FK requires linked sh_users account)');
        }
        $userId = (int)$userId;

        $description = null;
        if (array_key_exists('description', $payload) && $payload['description'] !== null) {
            $description = mb_substr(trim((string)$payload['description']), 0, 255);
            if ($description === '') {
                $description = null;
            }
        }

        $createdBy = isset($payload['created_by_user_id']) && (int)$payload['created_by_user_id'] > 0
            ? (int)$payload['created_by_user_id']
            : null;

        // Klucz idempotencji: jeśli klient go podał i wpis już istnieje, nie
        // zapisujemy NICZEGO — ani posiłku, ani potrącenia. Źródłem prawdy o tym,
        // że operacja przeszła, jest ledger (`entry_uuid` UNIQUE).
        $idempotencyKey = null;
        if (isset($payload['idempotency_key']) && (string)$payload['idempotency_key'] !== '') {
            $idempotencyKey = trim((string)$payload['idempotency_key']);
            if (!Uuid::isValid($idempotencyKey)) {
                throw new \RuntimeException(self::ERR_INVALID_IDEMPOTENCY . ' (must be a valid UUID)');
            }
            $prior = PayrollLedger::getByUuid($pdo, $tenantId, $idempotencyKey);
            if ($prior !== null) {
                return [
                    'meal_id'         => (int)($prior['ref_meal_id'] ?? 0),
                    'ledger_entry_id' => (int)$prior['id'],
                    'amount_minor'    => (int)$prior['amount_minor'],
                    'duplicate'       => true,
                ];
            }
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        try {
            // 1. Rekord domenowy — sh_meals (DECIMAL(10,2) PLN — konwersja z groszy).
            $ins = $pdo->prepare('
                INSERT INTO sh_meals (tenant_id, user_id, employee_price, created_at)
                VALUES (:tid, :uid, :price, NOW())
            ');
            $ins->execute([
                ':tid'   => $tenantId,
                ':uid'   => $userId,
                ':price' => Money::formatMinor($priceMinor),
            ]);
            $mealId = (int)$pdo->lastInsertId();

            // 2. Potrącenie w ledgerze (ujemne, okres = teraz, ref_meal_id).
            $ledgerId = PayrollLedger::record($pdo, $tenantId, [
                'entry_uuid'         => $idempotencyKey ?? Uuid::deterministic('meal-' . $tenantId . '-' . $mealId),
                'employee_id'        => $employeeId,
                'period_year'        => (int)date('Y'),
                'period_month'       => (int)date('n'),
                'entry_type'         => PayrollLedger::TYPE_MEAL_DEDUCTION,
                'amount_minor'       => -$priceMinor,
                'currency'           => 'PLN',
                'ref_meal_id'        => $mealId,
                'description'        => $description ?? ('Posiłek pracowniczy #' . $mealId),
                'created_by_user_id' => $createdBy,
            ]);

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'meal_id'         => $mealId,
            'ledger_entry_id' => $ledgerId,
            'amount_minor'    => -$priceMinor,
            'duplicate'       => false,
        ];
    }
}
