<?php

declare(strict_types=1);

require_once __DIR__ . '/PayrollLedger.php';

/**
 * AdvanceEngine — cykl życia zaliczki pracowniczej (`sh_advances`).
 *
 * Maszyna stanów (sh_advances.status, ASCII):
 *   requested  ─ approve ─► approved ─ markPaid ─► paid ─ (all installments paid) ─► settled
 *       │                                            │
 *       └─ reject ─► rejected                         └─ voidAdvance ─► void
 *
 * KAŻDA operacja pieniężna przechodzi przez `PayrollLedger` — to ona jest
 * źródłem prawdy o kwotach. Engine tylko zarządza stanem i harmonogramem.
 *
 * Świętość pieniądza:
 *   - `amount_minor` zawsze `int` grosze, SIGNED w ledgerze (tutaj UNSIGNED dla wniosku).
 *   - Reszta przy dzieleniu rat → do OSTATNIEJ raty (rozbicie bez utraty gr).
 *   - `markPaid` + rozbicie + wpis w ledgerze = w jednej transakcji DB.
 *
 * tenant_id: wszystkie zapytania scoped do `$tenantId` z pierwszego argumentu.
 */
final class AdvanceEngine
{
    // === STATUSY (ASCII) =====================================================
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_PAID      = 'paid';
    public const STATUS_SETTLED   = 'settled';
    public const STATUS_VOID      = 'void';

    // === REPAYMENT PLANS =====================================================
    public const PLAN_SINGLE       = 'single';
    public const PLAN_INSTALLMENTS = 'installments';

    // === INSTALLMENT STATUSES ================================================
    public const INST_PENDING = 'pending';
    public const INST_PAID    = 'paid';
    public const INST_VOID    = 'void';

    // === PAID METHODS (ASCII) =================================================
    public const METHOD_CASH     = 'cash';
    public const METHOD_TRANSFER = 'transfer';
    public const METHOD_OTHER    = 'other';
    public const PAID_METHODS = [self::METHOD_CASH, self::METHOD_TRANSFER, self::METHOD_OTHER];

    // === ERROR CODES (ASCII) ==================================================
    public const ERR_INVALID_AMOUNT        = 'INVALID_AMOUNT';
    public const ERR_INVALID_CURRENCY      = 'INVALID_CURRENCY';
    public const ERR_INVALID_PLAN          = 'INVALID_PLAN';
    public const ERR_INVALID_INSTALLMENTS  = 'INVALID_INSTALLMENTS';
    public const ERR_INVALID_METHOD        = 'INVALID_METHOD';
    public const ERR_EMPLOYEE_NOT_FOUND    = 'EMPLOYEE_NOT_FOUND';
    public const ERR_ADVANCE_NOT_FOUND     = 'ADVANCE_NOT_FOUND';
    public const ERR_INSTALLMENT_NOT_FOUND = 'INSTALLMENT_NOT_FOUND';
    public const ERR_INVALID_TRANSITION    = 'INVALID_TRANSITION';
    public const ERR_ALREADY_PAID          = 'INSTALLMENT_ALREADY_PAID';
    public const ERR_PARTIAL_REPAYMENT     = 'VOID_BLOCKED_PARTIAL_REPAYMENT';
    public const ERR_PAYMENT_LEDGER_MISSING= 'PAYMENT_LEDGER_MISSING';

    private const MAX_INSTALLMENTS = 24; // 2 lata — sanity cap
    private const MAX_AMOUNT_MINOR = 100_000_000; // 1 000 000.00 PLN — sanity cap (zapobiega error-inputs)

    // =========================================================================
    // 1) REQUEST — tworzy wniosek (status='requested')
    // =========================================================================

    /**
     * @param array $payload {
     *   employee_id:          int,          WYMAGANE
     *   amount_minor:         int,          WYMAGANE — STRICT int, grosze UNSIGNED (> 0)
     *   currency?:            string,       default 'PLN'
     *   repayment_plan:       string,       'single' | 'installments'
     *   installments_count?:  int,          gdy plan='installments'; 2..MAX
     *   reason?:              string,       max 255
     *   requested_by_user_id?: int|null     (kto złożył wniosek)
     *   advance_uuid?:        string        opcjonalnie (idempotency)
     * }
     * @return int id utworzonego `sh_advances`
     */
    public static function request(PDO $pdo, int $tenantId, array $payload): int
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('tenant_id must be positive.');
        }

        $employeeId = (int)($payload['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            throw new \InvalidArgumentException('employee_id must be positive.');
        }
        self::assertEmployeeBelongsToTenant($pdo, $tenantId, $employeeId);

        // amount_minor: STRICT int, > 0, <= MAX
        if (!array_key_exists('amount_minor', $payload) || !is_int($payload['amount_minor'])) {
            throw new \RuntimeException(self::ERR_INVALID_AMOUNT . ' (must be int grosze)');
        }
        $amount = (int)$payload['amount_minor'];
        if ($amount <= 0) {
            throw new \RuntimeException(self::ERR_INVALID_AMOUNT . ' (must be > 0)');
        }
        if ($amount > self::MAX_AMOUNT_MINOR) {
            throw new \RuntimeException(self::ERR_INVALID_AMOUNT . ' (exceeds cap ' . self::MAX_AMOUNT_MINOR . ')');
        }

        $currency = strtoupper(trim((string)($payload['currency'] ?? 'PLN')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \RuntimeException(self::ERR_INVALID_CURRENCY);
        }

        $plan = trim((string)($payload['repayment_plan'] ?? ''));
        if (!in_array($plan, [self::PLAN_SINGLE, self::PLAN_INSTALLMENTS], true)) {
            throw new \RuntimeException(self::ERR_INVALID_PLAN);
        }

        $installmentsCount = 1;
        if ($plan === self::PLAN_INSTALLMENTS) {
            $installmentsCount = (int)($payload['installments_count'] ?? 0);
            if ($installmentsCount < 2 || $installmentsCount > self::MAX_INSTALLMENTS) {
                throw new \RuntimeException(
                    self::ERR_INVALID_INSTALLMENTS . " (must be 2..".self::MAX_INSTALLMENTS.", got {$installmentsCount})"
                );
            }
            // Nie mniej niż 1 gr na ratę
            if ($amount < $installmentsCount) {
                throw new \RuntimeException(
                    self::ERR_INVALID_INSTALLMENTS . ' (amount too small for that many installments)'
                );
            }
        }

        $reason = null;
        if (array_key_exists('reason', $payload) && $payload['reason'] !== null) {
            $reason = mb_substr(trim((string)$payload['reason']), 0, 255);
            if ($reason === '') $reason = null;
        }

        $requestedBy = self::optionalPositiveInt($payload, 'requested_by_user_id');

        $uuid = trim((string)($payload['advance_uuid'] ?? ''));
        if ($uuid === '') {
            $uuid = self::uuidV4();
        } elseif (!self::isValidUuid($uuid)) {
            throw new \InvalidArgumentException('advance_uuid must be a valid UUID.');
        } else {
            $exists = self::findByUuid($pdo, $tenantId, $uuid);
            if ($exists !== null) return (int)$exists['id'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO sh_advances
                (advance_uuid, tenant_id, employee_id,
                 amount_minor, currency, status,
                 repayment_plan, installments_count,
                 reason, requested_at, requested_by_user_id,
                 created_at, updated_at)
            VALUES
                (:uuid, :tid, :eid,
                 :amt, :cur, :st,
                 :plan, :ic,
                 :reason, NOW(), :rby,
                 NOW(), NOW())
        ");
        $stmt->execute([
            ':uuid'   => $uuid,
            ':tid'    => $tenantId,
            ':eid'    => $employeeId,
            ':amt'    => $amount,
            ':cur'    => $currency,
            ':st'     => self::STATUS_REQUESTED,
            ':plan'   => $plan,
            ':ic'     => $installmentsCount,
            ':reason' => $reason,
            ':rby'    => $requestedBy,
        ]);

        return (int)$pdo->lastInsertId();
    }

    // =========================================================================
    // 2) APPROVE — status: requested → approved
    // =========================================================================
    public static function approve(PDO $pdo, int $tenantId, int $advanceId, int $approverUserId): void
    {
        $adv = self::loadAdvance($pdo, $tenantId, $advanceId);
        self::assertTransition($adv['status'], self::STATUS_REQUESTED, self::STATUS_APPROVED);

        $stmt = $pdo->prepare("
            UPDATE sh_advances
               SET status = :st,
                   approved_at = NOW(),
                   approved_by_user_id = :aby,
                   updated_at = NOW()
             WHERE id = :id AND tenant_id = :tid AND status = :prev
        ");
        $stmt->execute([
            ':st'   => self::STATUS_APPROVED,
            ':aby'  => $approverUserId > 0 ? $approverUserId : null,
            ':id'   => $advanceId,
            ':tid'  => $tenantId,
            ':prev' => self::STATUS_REQUESTED,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException(self::ERR_INVALID_TRANSITION . ' (concurrent modification)');
        }
    }

    // =========================================================================
    // 3) REJECT — status: requested → rejected
    // =========================================================================
    public static function reject(PDO $pdo, int $tenantId, int $advanceId, int $rejecterUserId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('rejection reason is required.');
        }
        $adv = self::loadAdvance($pdo, $tenantId, $advanceId);
        self::assertTransition($adv['status'], self::STATUS_REQUESTED, self::STATUS_REJECTED);

        $stmt = $pdo->prepare("
            UPDATE sh_advances
               SET status = :st,
                   rejected_at = NOW(),
                   rejection_reason = :rr,
                   updated_at = NOW()
             WHERE id = :id AND tenant_id = :tid AND status = :prev
        ");
        $stmt->execute([
            ':st'   => self::STATUS_REJECTED,
            ':rr'   => mb_substr($reason, 0, 255),
            ':id'   => $advanceId,
            ':tid'  => $tenantId,
            ':prev' => self::STATUS_REQUESTED,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException(self::ERR_INVALID_TRANSITION . ' (concurrent modification)');
        }
    }

    // =========================================================================
    // 4) MARK PAID — approved → paid
    //    Generuje wpis advance_payment w ledgerze + harmonogram rat (inst).
    // =========================================================================

    /**
     * @return array{ledger_entry_id: int, installments: list<int>}
     */
    public static function markPaid(PDO $pdo, int $tenantId, int $advanceId, int $payerUserId, string $method): array
    {
        if (!in_array($method, self::PAID_METHODS, true)) {
            throw new \RuntimeException(self::ERR_INVALID_METHOD);
        }

        $adv = self::loadAdvance($pdo, $tenantId, $advanceId);
        self::assertTransition($adv['status'], self::STATUS_APPROVED, self::STATUS_PAID);

        $amount            = (int)$adv['amount_minor'];
        $currency          = (string)$adv['currency'];
        $employeeId        = (int)$adv['employee_id'];
        $installmentsCount = (int)$adv['installments_count'];

        // Period, w którym wypłacono zaliczkę (= bieżący miesiąc)
        $payYear  = (int)date('Y');
        $payMonth = (int)date('n');

        // Rozbicie na raty (money-safe: int grosze, reszta do ostatniej raty)
        $installments = self::planInstallments($amount, $installmentsCount, $payYear, $payMonth);

        // Transakcja: insert instalments + ledger entry + flip status
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();

        try {
            $instIds = [];
            $instInsert = $pdo->prepare("
                INSERT INTO sh_advance_installments
                    (tenant_id, advance_id, seq_no, amount_minor, currency,
                     scheduled_period_year, scheduled_period_month,
                     status, created_at, updated_at)
                VALUES
                    (:tid, :aid, :seq, :amt, :cur,
                     :py, :pm,
                     :st, NOW(), NOW())
            ");
            foreach ($installments as $i => $inst) {
                $instInsert->execute([
                    ':tid' => $tenantId,
                    ':aid' => $advanceId,
                    ':seq' => $i + 1,
                    ':amt' => $inst['amount_minor'],
                    ':cur' => $currency,
                    ':py'  => $inst['period_year'],
                    ':pm'  => $inst['period_month'],
                    ':st'  => self::INST_PENDING,
                ]);
                $instIds[] = (int)$pdo->lastInsertId();
            }

            // Wpis w ledgerze: advance_payment = +amount (earning dla pracownika)
            $ledgerUuid = 'adv-pay-' . self::deterministicTail($adv['advance_uuid']);
            $ledgerId = PayrollLedger::record($pdo, $tenantId, [
                'entry_uuid'         => self::synthUuid($ledgerUuid),
                'employee_id'        => $employeeId,
                'period_year'        => $payYear,
                'period_month'       => $payMonth,
                'entry_type'         => PayrollLedger::TYPE_ADVANCE_PAYMENT,
                'amount_minor'       => $amount,       // POSITIVE — to wyplata
                'currency'           => $currency,
                'ref_advance_id'     => $advanceId,
                'description'        => 'Advance #' . $advanceId . ' paid (' . $method . ')',
                'created_by_user_id' => $payerUserId > 0 ? $payerUserId : null,
            ]);

            // Flip status sh_advances
            $upd = $pdo->prepare("
                UPDATE sh_advances
                   SET status = :st,
                       paid_at = NOW(),
                       paid_method = :pm,
                       paid_by_user_id = :pby,
                       updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tid AND status = :prev
            ");
            $upd->execute([
                ':st'   => self::STATUS_PAID,
                ':pm'   => $method,
                ':pby'  => $payerUserId > 0 ? $payerUserId : null,
                ':id'   => $advanceId,
                ':tid'  => $tenantId,
                ':prev' => self::STATUS_APPROVED,
            ]);
            if ($upd->rowCount() === 0) {
                throw new \RuntimeException(self::ERR_INVALID_TRANSITION . ' (concurrent modification)');
            }

            if ($ownTx) $pdo->commit();

            return [
                'ledger_entry_id' => $ledgerId,
                'installments'    => $instIds,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // 5) RECORD REPAYMENT — instalment: pending → paid + wpis w ledgerze
    // =========================================================================

    /**
     * Przy rozliczeniu zaliczki z wypłaty (np. worker dla okresu) wołamy
     * tę metodę dla każdej raty w stanie `pending`. Tworzy wpis kompensujący
     * (advance_repayment, amount < 0) w ledgerze.
     *
     * @return int id nowego wpisu w `sh_payroll_ledger`
     */
    public static function recordRepayment(PDO $pdo, int $tenantId, int $installmentId, int $actorUserId): int
    {
        $inst = self::loadInstallment($pdo, $tenantId, $installmentId);
        if ($inst['status'] === self::INST_PAID) {
            throw new \RuntimeException(self::ERR_ALREADY_PAID);
        }
        if ($inst['status'] !== self::INST_PENDING) {
            throw new \RuntimeException(
                self::ERR_INVALID_TRANSITION . " (installment status='{$inst['status']}')"
            );
        }

        $advanceId = (int)$inst['advance_id'];
        $amount    = (int)$inst['amount_minor'];    // >= 1 (UNSIGNED)
        $currency  = (string)$inst['currency'];

        // Employee z advance (ponieważ w installment nie trzymamy employee)
        $advStmt = $pdo->prepare("
            SELECT employee_id, advance_uuid FROM sh_advances
            WHERE id = :id AND tenant_id = :tid LIMIT 1
        ");
        $advStmt->execute([':id' => $advanceId, ':tid' => $tenantId]);
        $adv = $advStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$adv) throw new \RuntimeException(self::ERR_ADVANCE_NOT_FOUND);

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();

        try {
            $ledgerUuid = 'adv-rep-' . self::deterministicTail($adv['advance_uuid']) . '-' . $installmentId;
            $ledgerId = PayrollLedger::record($pdo, $tenantId, [
                'entry_uuid'         => self::synthUuid($ledgerUuid),
                'employee_id'        => (int)$adv['employee_id'],
                'period_year'        => (int)$inst['scheduled_period_year'],
                'period_month'       => (int)$inst['scheduled_period_month'],
                'entry_type'         => PayrollLedger::TYPE_ADVANCE_REPAYMENT,
                'amount_minor'       => -$amount,    // NEGATIVE — kompensata
                'currency'           => $currency,
                'ref_advance_id'     => $advanceId,
                'ref_installment_id' => $installmentId,
                'description'        => 'Advance #' . $advanceId . ' repayment seq=' . $inst['seq_no'],
                'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ]);

            $stmt = $pdo->prepare("
                UPDATE sh_advance_installments
                   SET status = :st,
                       applied_ledger_entry_id = :led,
                       applied_at = NOW(),
                       updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tid AND status = :prev
            ");
            $stmt->execute([
                ':st'   => self::INST_PAID,
                ':led'  => $ledgerId,
                ':id'   => $installmentId,
                ':tid'  => $tenantId,
                ':prev' => self::INST_PENDING,
            ]);
            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException(self::ERR_INVALID_TRANSITION . ' (concurrent modification on installment)');
            }

            // Check settlement — if all installments paid, flip advance to 'settled'
            self::checkSettlementInternal($pdo, $tenantId, $advanceId);

            if ($ownTx) $pdo->commit();
            return $ledgerId;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // 6) CHECK SETTLEMENT — publiczny helper
    // =========================================================================
    public static function checkSettlement(PDO $pdo, int $tenantId, int $advanceId): bool
    {
        return self::checkSettlementInternal($pdo, $tenantId, $advanceId);
    }

    private static function checkSettlementInternal(PDO $pdo, int $tenantId, int $advanceId): bool
    {
        // Tylko z paid → settled
        $stmt = $pdo->prepare("
            SELECT a.status, a.installments_count,
                   (SELECT COUNT(*) FROM sh_advance_installments i
                     WHERE i.advance_id = a.id AND i.tenant_id = a.tenant_id
                       AND i.status = :paid) AS paid_count
            FROM sh_advances a
            WHERE a.id = :id AND a.tenant_id = :tid
            LIMIT 1
        ");
        $stmt->execute([':paid' => self::INST_PAID, ':id' => $advanceId, ':tid' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException(self::ERR_ADVANCE_NOT_FOUND);

        if ($row['status'] !== self::STATUS_PAID) {
            return false; // settlement possible only from 'paid'
        }
        if ((int)$row['paid_count'] < (int)$row['installments_count']) {
            return false;
        }

        $upd = $pdo->prepare("
            UPDATE sh_advances
               SET status = :st, settled_at = NOW(), updated_at = NOW()
             WHERE id = :id AND tenant_id = :tid AND status = :prev
        ");
        $upd->execute([
            ':st'   => self::STATUS_SETTLED,
            ':id'   => $advanceId,
            ':tid'  => $tenantId,
            ':prev' => self::STATUS_PAID,
        ]);
        return $upd->rowCount() > 0;
    }

    // =========================================================================
    // 7) VOID ADVANCE — wycofanie błędnie wypłaconej zaliczki (paid → void)
    //
    // POLITYKA:
    //   - Dozwolone TYLKO gdy status='paid' ORAZ żadna rata nie została spłacona (pending only).
    //   - Jeśli jakakolwiek rata ma status='paid' → `ERR_PARTIAL_REPAYMENT` (korekta
    //     musi iść inną ścieżką: adjustment + ręczna konsultacja księgowa).
    //   - Wycofanie = transakcja:
    //       1. PayrollLedger::reverse(advance_payment entry)  — append-only reverse
    //       2. UPDATE sh_advance_installments SET status='void' WHERE status='pending'
    //       3. UPDATE sh_advances SET status='void', void_at=NOW()
    //   - `status='approved'` → blokada (nie ma czego wycofywać, jeszcze nie wypłacono).
    //     W tym wypadku caller powinien użyć `reject()`.
    // =========================================================================

    /**
     * Wycofuje błędnie wypłaconą zaliczkę (status 'paid' → 'void').
     *
     * @throws \RuntimeException z ERR_INVALID_TRANSITION / ERR_PARTIAL_REPAYMENT /
     *                          ERR_ADVANCE_NOT_FOUND / ERR_PAYMENT_LEDGER_MISSING
     * @return int id wpisu-kompensującego (reversal) w `sh_payroll_ledger`
     */
    public static function voidAdvance(PDO $pdo, int $tenantId, int $advanceId, int $actorUserId, string $reason): int
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('reason is required for voidAdvance (audit trail).');
        }

        $adv = self::loadAdvance($pdo, $tenantId, $advanceId);
        self::assertTransition($adv['status'], self::STATUS_PAID, self::STATUS_VOID);

        // Blokada: żadna rata nie może być spłacona
        $paidCount = (int)(function() use ($pdo, $tenantId, $advanceId) {
            $s = $pdo->prepare("
                SELECT COUNT(*) FROM sh_advance_installments
                 WHERE tenant_id = :tid AND advance_id = :aid AND status = :paid
            ");
            $s->execute([':tid' => $tenantId, ':aid' => $advanceId, ':paid' => self::INST_PAID]);
            return $s->fetchColumn();
        })();
        if ($paidCount > 0) {
            throw new \RuntimeException(
                self::ERR_PARTIAL_REPAYMENT . " ({$paidCount} installment(s) already paid — use manual adjustment instead)"
            );
        }

        // Znajdź oryginalny wpis `advance_payment` w ledgerze (ten z markPaid)
        $payStmt = $pdo->prepare("
            SELECT id FROM sh_payroll_ledger
             WHERE tenant_id = :tid
               AND ref_advance_id = :aid
               AND entry_type = :etype
               AND reverses_entry_id IS NULL
             ORDER BY id ASC LIMIT 1
        ");
        $payStmt->execute([
            ':tid'   => $tenantId,
            ':aid'   => $advanceId,
            ':etype' => PayrollLedger::TYPE_ADVANCE_PAYMENT,
        ]);
        $paymentEntryId = (int)($payStmt->fetchColumn() ?: 0);
        if ($paymentEntryId <= 0) {
            throw new \RuntimeException(self::ERR_PAYMENT_LEDGER_MISSING . " (advance #{$advanceId} has no ledger payment)");
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();

        try {
            // 1. Reverse w ledgerze (append-only compensation)
            $reversalId = PayrollLedger::reverse(
                $pdo,
                $tenantId,
                $paymentEntryId,
                'Advance #' . $advanceId . ' voided: ' . $reason,
                $actorUserId > 0 ? $actorUserId : null
            );

            // 2. Void pozostałych pending installments
            $voidInst = $pdo->prepare("
                UPDATE sh_advance_installments
                   SET status = :v, updated_at = NOW()
                 WHERE tenant_id = :tid AND advance_id = :aid AND status = :pending
            ");
            $voidInst->execute([
                ':v'       => self::INST_VOID,
                ':tid'     => $tenantId,
                ':aid'     => $advanceId,
                ':pending' => self::INST_PENDING,
            ]);

            // 3. Flip advance na 'void'
            $upd = $pdo->prepare("
                UPDATE sh_advances
                   SET status = :st, void_at = NOW(), updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tid AND status = :prev
            ");
            $upd->execute([
                ':st'   => self::STATUS_VOID,
                ':id'   => $advanceId,
                ':tid'  => $tenantId,
                ':prev' => self::STATUS_PAID,
            ]);
            if ($upd->rowCount() === 0) {
                throw new \RuntimeException(self::ERR_INVALID_TRANSITION . ' (concurrent modification on advance)');
            }

            if ($ownTx) $pdo->commit();
            return $reversalId;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // READERS
    // =========================================================================

    public static function getById(PDO $pdo, int $tenantId, int $advanceId): ?array
    {
        if ($tenantId <= 0 || $advanceId <= 0) return null;
        $stmt = $pdo->prepare("
            SELECT * FROM sh_advances WHERE id = :id AND tenant_id = :tid LIMIT 1
        ");
        $stmt->execute([':id' => $advanceId, ':tid' => $tenantId]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function listInstallments(PDO $pdo, int $tenantId, int $advanceId): array
    {
        if ($tenantId <= 0 || $advanceId <= 0) return [];
        $stmt = $pdo->prepare("
            SELECT * FROM sh_advance_installments
             WHERE tenant_id = :tid AND advance_id = :aid
             ORDER BY seq_no ASC
        ");
        $stmt->execute([':tid' => $tenantId, ':aid' => $advanceId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Rozbija kwotę w groszach na N rat z resztą do ostatniej raty.
     *
     * Harmonogram: pierwszy period = miesiąc PO paidPeriod (tj. następny).
     *
     * @return list<array{amount_minor:int, period_year:int, period_month:int}>
     */
    private static function planInstallments(int $totalMinor, int $count, int $paidYear, int $paidMonth): array
    {
        if ($count <= 0) throw new \RuntimeException(self::ERR_INVALID_INSTALLMENTS);
        $base      = intdiv($totalMinor, $count);
        $remainder = $totalMinor - ($base * $count);
        $out = [];
        $y = $paidYear; $m = $paidMonth;
        for ($i = 0; $i < $count; $i++) {
            // Następny miesiąc po paid
            $m++;
            if ($m > 12) { $m = 1; $y++; }
            $amt = $base;
            if ($i === $count - 1) $amt += $remainder;   // reszta do ostatniej
            $out[] = [
                'amount_minor' => $amt,
                'period_year'  => $y,
                'period_month' => $m,
            ];
        }
        return $out;
    }

    private static function loadAdvance(PDO $pdo, int $tenantId, int $advanceId): array
    {
        $r = self::getById($pdo, $tenantId, $advanceId);
        if (!$r) throw new \RuntimeException(self::ERR_ADVANCE_NOT_FOUND);
        return $r;
    }

    private static function loadInstallment(PDO $pdo, int $tenantId, int $installmentId): array
    {
        if ($tenantId <= 0 || $installmentId <= 0) {
            throw new \RuntimeException(self::ERR_INSTALLMENT_NOT_FOUND);
        }
        $stmt = $pdo->prepare("
            SELECT * FROM sh_advance_installments
            WHERE id = :id AND tenant_id = :tid LIMIT 1
        ");
        $stmt->execute([':id' => $installmentId, ':tid' => $tenantId]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException(self::ERR_INSTALLMENT_NOT_FOUND);
        return $r;
    }

    private static function assertEmployeeBelongsToTenant(PDO $pdo, int $tenantId, int $employeeId): void
    {
        $stmt = $pdo->prepare("
            SELECT 1 FROM sh_employees
            WHERE id = :id AND tenant_id = :tid AND is_deleted = 0 LIMIT 1
        ");
        $stmt->execute([':id' => $employeeId, ':tid' => $tenantId]);
        if (!$stmt->fetchColumn()) {
            throw new \RuntimeException(self::ERR_EMPLOYEE_NOT_FOUND);
        }
    }

    private static function assertTransition(string $current, string $expectedFrom, string $to): void
    {
        if ($current !== $expectedFrom) {
            throw new \RuntimeException(
                self::ERR_INVALID_TRANSITION . " (expected from='{$expectedFrom}', got='{$current}', target='{$to}')"
            );
        }
    }

    private static function findByUuid(PDO $pdo, int $tenantId, string $uuid): ?array
    {
        if ($uuid === '' || $tenantId <= 0) return null;
        $stmt = $pdo->prepare("
            SELECT * FROM sh_advances
            WHERE tenant_id = :tid AND advance_uuid = :uuid LIMIT 1
        ");
        $stmt->execute([':tid' => $tenantId, ':uuid' => $uuid]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private static function optionalPositiveInt(array $payload, string $key): ?int
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) return null;
        $v = $payload[$key];
        if (!is_int($v) || $v <= 0) {
            throw new \InvalidArgumentException("{$key} must be a positive int if provided.");
        }
        return $v;
    }

    /**
     * Zwraca 8 ostatnich znaków z UUID (deterministic tail) — używane do budowy
     * deterministycznych UUID-ów dla ledger entries (idempotency).
     */
    private static function deterministicTail(string $advanceUuid): string
    {
        $h = preg_replace('/[^0-9a-fA-F]/', '', $advanceUuid);
        return strtolower(substr($h, -8));
    }

    /**
     * Generuje deterministyczny UUID v5-like na bazie tekstowego seed.
     * Używane do idempotency ledger entries (ten sam seed → ten sam UUID).
     */
    private static function synthUuid(string $seed): string
    {
        $hash = sha1($seed);
        $b = str_split(substr($hash, 0, 32), 2);
        $bytes = '';
        foreach ($b as $hex) $bytes .= chr(hexdec($hex));
        // Force version 5 (custom) + variant
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $h = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4),
            substr($h, 16, 4), substr($h, 20, 12));
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
