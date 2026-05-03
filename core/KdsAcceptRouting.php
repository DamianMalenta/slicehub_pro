<?php
declare(strict_types=1);

/**
 * Tworzy bilety KDS per stacja przy przejściu zamówienia → accepted.
 * Współdzielone przez api/pos/engine.php (accept_order) i api/orders/accept.php.
 *
 * Wywoływać wyłącznie wewnątrz aktywnej transakcji PDO.
 */
final class KdsAcceptRouting
{
    /**
     * @return array<int, array{ticket_id: string, station_id: string, status: string, lines: array<int, array<string, mixed>>}>
     */
    public static function createTicketsForAcceptedOrder(\PDO $pdo, int $tenantId, string $orderId): array
    {
        $stmtLines = $pdo->prepare(
            "SELECT ol.id          AS line_id,
                    ol.item_sku,
                    ol.snapshot_name,
                    ol.quantity,
                    ol.modifiers_json,
                    ol.removed_ingredients_json,
                    ol.comment,
                    COALESCE(NULLIF(mi.kds_station_id, ''), 'KITCHEN_MAIN') AS station_id
             FROM sh_order_lines ol
             LEFT JOIN sh_menu_items mi
                    ON mi.ascii_key = ol.item_sku
                   AND mi.tenant_id = :tid
             WHERE ol.order_id = :oid"
        );
        $stmtLines->execute([':oid' => $orderId, ':tid' => $tenantId]);
        $lines = $stmtLines->fetchAll(\PDO::FETCH_ASSOC);

        if (count($lines) === 0) {
            throw new \InvalidArgumentException('Order has no lines to route.');
        }

        $stationGroups = [];
        foreach ($lines as $line) {
            $sid = (string)$line['station_id'];
            if (!isset($stationGroups[$sid])) {
                $stationGroups[$sid] = [];
            }
            $stationGroups[$sid][] = $line;
        }

        $stmtInsertTicket = $pdo->prepare(
            "INSERT INTO sh_kds_tickets (id, tenant_id, order_id, station_id, status)
             VALUES (:id, :tid, :oid, :station, 'pending')"
        );

        $stmtLinkLine = $pdo->prepare(
            "UPDATE sh_order_lines SET kds_ticket_id = :ticket_id WHERE id = :line_id AND order_id = :oid"
        );

        $ticketsCreated = [];

        foreach ($stationGroups as $stationId => $stationLines) {
            $ticketId = self::uuidV4();

            $stmtInsertTicket->execute([
                ':id'      => $ticketId,
                ':tid'     => $tenantId,
                ':oid'     => $orderId,
                ':station' => $stationId,
            ]);

            $ticketLines = [];
            foreach ($stationLines as $sl) {
                $stmtLinkLine->execute([
                    ':ticket_id' => $ticketId,
                    ':line_id'   => $sl['line_id'],
                    ':oid'       => $orderId,
                ]);

                $mods    = json_decode($sl['modifiers_json'] ?? '[]', true) ?: [];
                $removed = json_decode($sl['removed_ingredients_json'] ?? '[]', true) ?: [];

                $ticketLines[] = [
                    'line_id'             => $sl['line_id'],
                    'snapshot_name'       => $sl['snapshot_name'],
                    'quantity'            => (int)$sl['quantity'],
                    'modifiers_added'     => array_column($mods, 'name'),
                    'ingredients_removed' => array_column($removed, 'name'),
                    'comment'             => $sl['comment'],
                ];
            }

            $ticketsCreated[] = [
                'ticket_id'  => $ticketId,
                'station_id' => $stationId,
                'status'     => 'pending',
                'lines'      => $ticketLines,
            ];
        }

        return $ticketsCreated;
    }

    private static function uuidV4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
