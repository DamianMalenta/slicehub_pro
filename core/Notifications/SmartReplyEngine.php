<?php
declare(strict_types=1);

/**
 * SmartReplyEngine — bidirectional SMS intelligence.
 *
 * Wywoływany przez api/integrations/inbound.php gdy SMS Gateway for Android
 * dostarcza przychodzącą wiadomość przez webhook.
 *
 * Flow:
 *   1. Zidentyfikuj klienta po numerze telefonu (sh_customer_contacts)
 *   2. Znajdź aktywne zamówienie klienta (ostatnie 3h)
 *   3. Klasyfikuj intent (keyword matching bez AI)
 *   4. Generuj auto-reply lub eskaluj do managera
 *   5. Zapisz do sh_customer_inbox
 *   6. Zwróć instrukcje dla callera (co wysłać przez PersonalPhoneChannel)
 *
 * Intent categories:
 *   eta_query       — "gdzie", "kiedy", "ile", "długo", "ETA"
 *   cancel_request  — "anuluj", "cancel", "rezygnuję", "cofnij"
 *   info_query      — "adres", "telefon", "gdzie jesteście"
 *   stop            — "STOP", "stop", "wypisz", "nie chcę"
 *   reorder         — "to samo", "ponów", "zamów jeszcze"
 *   other           — wszystko inne → forward do managera
 */
final class SmartReplyEngine
{
    private const INTENT_KEYWORDS = [
        'eta_query' => [
            'gdzie', 'kiedy', 'ile', 'długo', 'dlugo', 'jak długo', 'jak dlugo',
            'eta', 'czas', 'minuty', 'minut', 'gotowe', 'gotowy', 'kiedy będzie',
            'kiedy bedzie', 'co słychać', 'co slychac', 'status',
        ],
        'cancel_request' => [
            'anuluj', 'anulować', 'cancel', 'rezygnuję', 'rezygnuje',
            'cofnij', 'odwołaj', 'odwolaj', 'nie chcę', 'nie chce zamówienia',
        ],
        'info_query' => [
            'adres', 'gdzie jesteście', 'gdzie jestescie', 'telefon',
            'godziny', 'otwarcia', 'lokalizacja', 'jak dojechać', 'jak dojechac',
        ],
        'stop' => [
            'stop', 'nie chcę', 'nie chce', 'wypisz', 'wypisz mnie',
            'usuń', 'usun', 'odpisz', 'unsubscribe', 'koniec',
        ],
        'reorder' => [
            'to samo', 'jeszcze raz', 'ponów', 'ponow', 'zamów jeszcze',
            'zamow jeszcze', 'jak ostatnio', 'znowu', 'powtórz', 'powtorz',
        ],
    ];

    private \PDO $pdo;
    private int  $tenantId;

    public function __construct(\PDO $pdo, int $tenantId)
    {
        $this->pdo      = $pdo;
        $this->tenantId = $tenantId;
    }

    /**
     * Przetworz przychodzący SMS.
     *
     * @param  string $fromPhone   Numer nadawcy (klienta)
     * @param  string $body        Treść wiadomości
     * @param  array  $meta        Dodatkowe metadane z webhookua (timestamp, messageId itp.)
     * @return array {
     *     intent: string,
     *     auto_reply?: string,       // treść auto-odpowiedzi do wysłania
     *     forward_to_manager: bool,
     *     manager_phone?: string,
     *     order_id?: string,
     *     alert_type?: string,       // 'cancel_requested' | 'new_message'
     * }
     */
    public function process(string $fromPhone, string $body, array $meta = []): array
    {
        $fromPhone = $this->normalizePhone($fromPhone);
        $bodyNorm  = mb_strtolower(trim($body));

        // 1. Identyfikacja klienta
        $contact = $this->findContact($fromPhone);

        // 2. Znajdź aktywne zamówienie
        $order = $this->findActiveOrder($fromPhone);

        // 3. Klasyfikacja intent
        $intent = $this->classifyIntent($bodyNorm);

        // 4. Auto-reply logic
        $autoReply        = null;
        $forwardToManager = false;
        $alertType        = null;

        $storeName  = $this->getStoreSetting('storefront_name') ?: 'Restauracja';
        $storePhone = $this->getStoreSetting('storefront_phone') ?: '';
        $storeAddr  = $this->getStoreSetting('storefront_address') ?: '';

        switch ($intent) {
            case 'stop':
                // RODO opt-out
                $this->handleOptOut($fromPhone);
                $autoReply = "{$storeName}: Wypisano Cię z listy powiadomień SMS. Nie będziesz już otrzymywać wiadomości.";
                break;

            case 'eta_query':
                if ($order) {
                    $eta = $this->getOrderEta($order);
                    $trackingUrl = $order['tracking_token']
                        ? "/slicehub/modules/online/track.html?tenant={$this->tenantId}&token={$order['tracking_token']}&phone={$fromPhone}"
                        : null;
                    $autoReply = "{$storeName}: Zamówienie #{$order['order_number']}, status: {$this->statusPl($order['status'])}";
                    if ($eta !== null) $autoReply .= ", szacowany czas: {$eta} min";
                    if ($trackingUrl) $autoReply .= ". Śledzenie: {$trackingUrl}";
                } else {
                    $autoReply = "{$storeName}: Nie znaleziono aktywnego zamówienia. Pytania? Zadzwoń: {$storePhone}.";
                }
                break;

            case 'cancel_request':
                if ($order && !in_array($order['status'], ['completed', 'cancelled', 'in_delivery'], true)) {
                    // Flaga cancel_requested — manager decyduje
                    $this->flagCancelRequest((string)$order['id']);
                    $autoReply = "{$storeName}: Twoja prośba o anulowanie zamówienia #{$order['order_number']} została przekazana. Oddzwonimy.";
                    $forwardToManager = true;
                    $alertType = 'cancel_requested';
                } else {
                    $autoReply = "{$storeName}: Nie możemy anulować — zamówienie jest już w realizacji lub zakończone. Zadzwoń: {$storePhone}.";
                }
                break;

            case 'info_query':
                $parts = ["{$storeName}:"];
                if ($storeAddr)  $parts[] = "Adres: {$storeAddr}.";
                if ($storePhone) $parts[] = "Tel: {$storePhone}.";
                $autoReply = implode(' ', $parts);
                break;

            case 'reorder':
                // Pokaż link do sklepu
                $storeUrl = $this->getStoreSetting('storefront_url') ?: '';
                $autoReply = "{$storeName}: Aby ponowić zamówienie, odwiedź nasz sklep: {$storeUrl}";
                break;

            default: // other
                $forwardToManager = true;
                $alertType = 'new_message';
                break;
        }

        // 5. Zapisz do inbox
        $inboxId = $this->saveToInbox($fromPhone, $body, $intent, $order ? (string)$order['id'] : null,
            $autoReply, $contact ? (int)$contact['id'] : null);

        // 6. Manager forward info
        $managerPhone = $forwardToManager ? ($storePhone ?: null) : null;

        return [
            'intent'             => $intent,
            'auto_reply'         => $autoReply,
            'forward_to_manager' => $forwardToManager,
            'manager_phone'      => $managerPhone,
            'order_id'           => $order ? (string)$order['id'] : null,
            'alert_type'         => $alertType,
            'inbox_id'           => $inboxId,
            'contact_found'      => $contact !== null,
        ];
    }

    // ─── Private helpers ─────────────────────────────────────────────────

    private function classifyIntent(string $bodyNorm): string
    {
        // Priorytet: stop > cancel > eta > info > reorder > other
        $priority = ['stop', 'cancel_request', 'eta_query', 'info_query', 'reorder'];

        foreach ($priority as $intent) {
            foreach (self::INTENT_KEYWORDS[$intent] as $kw) {
                if (str_contains($bodyNorm, mb_strtolower($kw))) {
                    return $intent;
                }
            }
        }
        return 'other';
    }

    private function findContact(string $phone): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM sh_customer_contacts WHERE tenant_id = :tid AND phone = :phone LIMIT 1"
            );
            $stmt->execute([':tid' => $this->tenantId, ':phone' => $phone]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) { return null; }
    }

    private function findActiveOrder(string $phone): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, order_number, status, promised_time, tracking_token
                 FROM sh_orders
                 WHERE tenant_id = :tid AND customer_phone = :phone
                   AND status NOT IN ('completed', 'cancelled')
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 3 HOUR)
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $stmt->execute([':tid' => $this->tenantId, ':phone' => $phone]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) { return null; }
    }

    private function getOrderEta(array $order): ?int
    {
        if (empty($order['promised_time'])) return null;
        try {
            $promised = new \DateTime((string)$order['promised_time']);
            $seconds = $promised->getTimestamp() - time();
            return $seconds > 0 ? (int)ceil($seconds / 60) : 0;
        } catch (\Throwable $e) { return null; }
    }

    private function statusPl(string $status): string
    {
        return match ($status) {
            'new'         => 'nowe',
            'accepted'    => 'zaakceptowane',
            'pending'     => 'zaakceptowane',
            'preparing'   => 'w przygotowaniu',
            'ready'       => 'gotowe',
            'in_delivery' => 'w drodze',
            'completed'   => 'zakończone',
            'cancelled'   => 'anulowane',
            default       => $status,
        };
    }

    private function handleOptOut(string $phone): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE sh_customer_contacts
                 SET sms_consent = 0, marketing_consent = 0, sms_optout_at = NOW(), updated_at = NOW()
                 WHERE tenant_id = :tid AND phone = :phone"
            )->execute([':tid' => $this->tenantId, ':phone' => $phone]);

            // Też aktualizuj sh_orders (jeśli ktoś kiedyś sprawdzi)
            $this->pdo->prepare(
                "UPDATE sh_orders SET sms_consent = 0, marketing_consent = 0
                 WHERE tenant_id = :tid AND customer_phone = :phone AND status NOT IN ('completed','cancelled')"
            )->execute([':tid' => $this->tenantId, ':phone' => $phone]);
        } catch (\Throwable $e) {
            error_log("[SmartReplyEngine::handleOptOut] {$e->getMessage()}");
        }
    }

    private function flagCancelRequest(string $orderId): void
    {
        try {
            $this->pdo->prepare(
                "UPDATE sh_orders SET customer_requested_cancel = 1 WHERE id = :id AND tenant_id = :tid"
            )->execute([':id' => $orderId, ':tid' => $this->tenantId]);
        } catch (\Throwable $e) {
            error_log("[SmartReplyEngine::flagCancelRequest] {$e->getMessage()}");
        }
    }

    private function saveToInbox(
        string $fromPhone, string $body, string $intent,
        ?string $orderId, ?string $autoReply, ?int $contactId
    ): ?int {
        try {
            $this->pdo->prepare(
                "INSERT INTO sh_customer_inbox
                    (tenant_id, contact_id, from_phone, body, intent, order_id,
                     auto_replied, auto_reply_body, received_at)
                 VALUES (:tid, :cid, :phone, :body, :intent, :oid,
                         :replied, :reply_body, NOW())"
            )->execute([
                ':tid'        => $this->tenantId,
                ':cid'        => $contactId,
                ':phone'      => $fromPhone,
                ':body'       => $body,
                ':intent'     => $intent,
                ':oid'        => $orderId,
                ':replied'    => $autoReply !== null ? 1 : 0,
                ':reply_body' => $autoReply,
            ]);
            return (int)$this->pdo->lastInsertId();
        } catch (\Throwable $e) {
            error_log("[SmartReplyEngine::saveToInbox] {$e->getMessage()}");
            return null;
        }
    }

    private function getStoreSetting(string $key): string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT setting_value FROM sh_tenant_settings WHERE tenant_id = :tid AND setting_key = :key LIMIT 1"
            );
            $stmt->execute([':tid' => $this->tenantId, ':key' => $key]);
            return (string)($stmt->fetchColumn() ?: '');
        } catch (\Throwable $e) { return ''; }
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', $phone);
        // Jeśli zaczyna od 0 → +48
        if (str_starts_with($phone, '0') && strlen($phone) === 10) {
            $phone = '+48' . substr($phone, 1);
        }
        return $phone;
    }
}
