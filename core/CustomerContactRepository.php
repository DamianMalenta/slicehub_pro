<?php
declare(strict_types=1);

/**
 * CustomerContactRepository — mini-CRM do deduplikacji kontaktów klientów.
 *
 * Tabela: sh_customer_contacts (UNIQUE KEY na tenant_id + phone).
 * Wywoływany przez:
 *   - NotificationDispatcher po każdym order.created / order.accepted
 *   - api/online/engine.php guest_checkout (opcjonalnie)
 */
final class CustomerContactRepository
{
    /**
     * Upsert kontaktu po (tenant_id, phone).
     * Aktualizuje: name (jeśli pusty), email (jeśli pusty), zgody (OR — raz wyrażona, trwa),
     * last_order_at, order_count.
     *
     * @param \PDO  $pdo
     * @param int   $tenantId
     * @param string $phone      Znormalizowany numer (może być +48XXXXXXXXX lub 0XXXXXXXXX)
     * @param array  $data {
     *     name?: string,
     *     email?: string,
     *     sms_consent?: bool,
     *     marketing_consent?: bool,
     *     is_new_order?: bool,  // default true — incrementuje order_count
     * }
     */
    public static function upsert(\PDO $pdo, int $tenantId, string $phone, array $data = []): void
    {
        if ($tenantId <= 0 || $phone === '') {
            return;
        }

        $name        = isset($data['name'])  && $data['name']  !== '' ? (string)$data['name']  : null;
        $email       = isset($data['email']) && $data['email'] !== '' ? (string)$data['email'] : null;
        $smsCon      = !empty($data['sms_consent'])       ? 1 : 0;
        $mktCon      = !empty($data['marketing_consent']) ? 1 : 0;
        $isNewOrder  = $data['is_new_order'] ?? true;

        try {
            $pdo->prepare(
                "INSERT INTO sh_customer_contacts
                    (tenant_id, phone, name, email, sms_consent, marketing_consent, first_seen_at, last_order_at, order_count)
                 VALUES (:tid, :phone, :name, :email, :sms, :mkt, NOW(), NOW(), :cnt)
                 ON DUPLICATE KEY UPDATE
                    name              = COALESCE(IF(name = '' OR name IS NULL, VALUES(name), name), name),
                    email             = COALESCE(IF(email = '' OR email IS NULL, VALUES(email), email), email),
                    sms_consent       = GREATEST(sms_consent, VALUES(sms_consent)),
                    marketing_consent = GREATEST(marketing_consent, VALUES(marketing_consent)),
                    last_order_at     = VALUES(last_order_at),
                    order_count       = order_count + :inc,
                    updated_at        = NOW()"
            )->execute([
                ':tid'   => $tenantId,
                ':phone' => $phone,
                ':name'  => $name,
                ':email' => $email,
                ':sms'   => $smsCon,
                ':mkt'   => $mktCon,
                ':cnt'   => $isNewOrder ? 1 : 0,
                ':inc'   => $isNewOrder ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            // Graceful — tabela może nie istnieć jeszcze (przed migracją)
            error_log("[CustomerContactRepository::upsert] {$e->getMessage()}");
        }
    }

    /**
     * Opt-out SMS i marketing — wywoływany przez SmartReplyEngine gdy klient odpisze STOP.
     * Zapisuje też GDPR audit log (sh_gdpr_consent_log).
     */
    public static function optOut(\PDO $pdo, int $tenantId, string $phone, string $source = 'sms_stop'): void
    {
        $phoneHash = hash('sha256', $phone);
        try {
            $pdo->prepare(
                "UPDATE sh_customer_contacts
                 SET sms_consent = 0, marketing_consent = 0, sms_optout_at = NOW(), updated_at = NOW()
                 WHERE tenant_id = :tid AND phone = :phone"
            )->execute([':tid' => $tenantId, ':phone' => $phone]);

            // GDPR consent log — append-only
            $contactStmt = $pdo->prepare(
                "SELECT id FROM sh_customer_contacts WHERE tenant_id = :tid AND phone = :phone LIMIT 1"
            );
            $contactStmt->execute([':tid' => $tenantId, ':phone' => $phone]);
            $contactId = $contactStmt->fetchColumn() ?: null;

            foreach (['sms', 'marketing'] as $type) {
                $pdo->prepare(
                    "INSERT IGNORE INTO sh_gdpr_consent_log
                        (tenant_id, contact_id, phone_hash, consent_type, granted, source, occurred_at)
                     VALUES (:tid, :cid, :ph, :ct, 0, :src, NOW())"
                )->execute([
                    ':tid' => $tenantId,
                    ':cid' => $contactId,
                    ':ph'  => $phoneHash,
                    ':ct'  => $type,
                    ':src' => $source,
                ]);
            }
        } catch (\Throwable $e) {
            error_log("[CustomerContactRepository::optOut] {$e->getMessage()}");
        }
    }

    /**
     * Log consent grant (przy checkout / upsert z nową zgodą).
     */
    public static function logConsentGrant(
        \PDO $pdo, int $tenantId, ?int $contactId, string $phone,
        string $consentType, string $source = 'checkout', ?string $orderId = null
    ): void {
        try {
            $pdo->prepare(
                "INSERT IGNORE INTO sh_gdpr_consent_log
                    (tenant_id, contact_id, phone_hash, consent_type, granted, source, order_id, occurred_at)
                 VALUES (:tid, :cid, :ph, :ct, 1, :src, :oid, NOW())"
            )->execute([
                ':tid' => $tenantId,
                ':cid' => $contactId,
                ':ph'  => hash('sha256', $phone),
                ':ct'  => $consentType,
                ':src' => $source,
                ':oid' => $orderId,
            ]);
        } catch (\Throwable $e) {
            error_log("[CustomerContactRepository::logConsentGrant] {$e->getMessage()}");
        }
    }

    /**
     * Znajdź kontakt po telefonie.
     */
    public static function findByPhone(\PDO $pdo, int $tenantId, string $phone): ?array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM sh_customer_contacts WHERE tenant_id = :tid AND phone = :phone LIMIT 1"
            );
            $stmt->execute([':tid' => $tenantId, ':phone' => $phone]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log("[CustomerContactRepository::findByPhone] {$e->getMessage()}");
            return null;
        }
    }
}
