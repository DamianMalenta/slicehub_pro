<?php

declare(strict_types=1);

/**
 * Atomic document numbering (Section 28) — sh_doc_sequences + LAST_INSERT_ID() upsert.
 */
final class SequenceEngine
{
    /** @var list<string> */
    private const DOC_TYPES = ['ORD', 'WWW', 'KIO', 'PZ', 'WZ', 'MM', 'KOR', 'INW', 'RW', 'PW'];

    /**
     * @return array{doc_number: string, doc_type: string, sequence: int, date: string}
     */
    public static function generate(PDO $pdo, int $tenantId, string $docType, ?string $businessDate = null): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Invalid tenant_id.');
        }

        $businessDate = $businessDate ?? date('Y-m-d');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if ($parsed === false) {
            throw new \InvalidArgumentException('Invalid business_date. Use Y-m-d.');
        }
        $businessDate = $parsed->format('Y-m-d');

        $strDate = date('Ymd', strtotime($businessDate));

        if (!in_array($docType, self::DOC_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Invalid doc_type. Allowed: ' . implode(', ', self::DOC_TYPES)
            );
        }

        $sql = 'INSERT INTO sh_doc_sequences (tenant_id, doc_type, doc_date, seq)
                VALUES (:tid, :type, :date, LAST_INSERT_ID(1))
                ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tid'  => $tenantId,
            ':type' => $docType,
            ':date' => $businessDate,
        ]);

        $seq = (int) $pdo->lastInsertId();
        if ($seq < 1) {
            throw new \RuntimeException('Sequence allocation failed.');
        }

        $docNumber = sprintf('%s/%s/%04d', $docType, $strDate, $seq);

        return [
            'doc_number' => $docNumber,
            'doc_type'   => $docType,
            'sequence'   => $seq,
            'date'       => $businessDate,
        ];
    }
}
