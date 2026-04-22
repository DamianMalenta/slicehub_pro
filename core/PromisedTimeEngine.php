<?php
// =============================================================================
// SliceHub Enterprise — Promised Time Engine (Section 13)
// core/PromisedTimeEngine.php
//
// Stateless calculator that resolves the "promised_time" for an order.
// Handles ASAP estimation (prep × load + channel buffer) and scheduled-order
// validation (lead-time gate + business-hours gate).
//
// Schema: sh_tenant_settings, sh_orders
// =============================================================================

class PromisedTimeEngine
{
    private const TIMEZONE = 'Europe/Warsaw';

    private const CHANNEL_BUFFERS = [
        'dine_in'   => 0,
        'takeaway'  => 5,
        'delivery'  => 15,
    ];

    private const DEFAULT_BASE_PREP      = 25;
    private const DEFAULT_MIN_LEAD_TIME  = 30;
    private const MAX_LOAD_FACTOR        = 2.0;
    private const LOAD_DIVISOR           = 20.0;

    /**
     * @return array{
     *   mode: string,
     *   base_prep_minutes: int,
     *   channel_buffer_minutes: int,
     *   load_factor: float,
     *   active_orders: int,
     *   estimated_minutes: int,
     *   promised_time: string
     * }
     *
     * @throws InvalidArgumentException on validation failure (bad mode, too soon, outside hours)
     */
    public static function calculate(
        PDO    $pdo,
        int    $tenantId,
        string $mode,
        string $channel,
        ?string $requestedTime = null
    ): array {
        $tz  = new DateTimeZone(self::TIMEZONE);
        $now = new DateTime('now', $tz);

        // =====================================================================
        // 1. SETTINGS FETCH
        // =====================================================================
        $stmt = $pdo->prepare(
            "SELECT base_prep_minutes, min_lead_time_minutes, opening_hours_json
             FROM sh_tenant_settings
             WHERE tenant_id = :tid AND setting_key = ''
             LIMIT 1"
        );
        $stmt->execute([':tid' => $tenantId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        $basePrepMinutes   = (int)($settings['base_prep_minutes']    ?? self::DEFAULT_BASE_PREP);
        $minLeadMinutes    = (int)($settings['min_lead_time_minutes'] ?? self::DEFAULT_MIN_LEAD_TIME);
        $openingHoursRaw   = $settings['opening_hours_json'] ?? null;

        // =====================================================================
        // 2. BUFFER & LOAD
        // =====================================================================
        $channelBuffer = self::CHANNEL_BUFFERS[$channel] ?? 15;

        $stmtLoad = $pdo->prepare(
            "SELECT COUNT(*) FROM sh_orders
             WHERE tenant_id = :tid AND status IN ('accepted', 'preparing')"
        );
        $stmtLoad->execute([':tid' => $tenantId]);
        $activeOrders = (int)$stmtLoad->fetchColumn();

        $loadFactor          = min(self::MAX_LOAD_FACTOR, 1.0 + ($activeOrders / self::LOAD_DIVISOR));
        $estimatedPrepMinutes = (int)round($basePrepMinutes * $loadFactor);
        $totalEstimatedMinutes = $estimatedPrepMinutes + $channelBuffer;

        // =====================================================================
        // 3. TIME RESOLUTION & VALIDATION
        // =====================================================================
        if ($mode === 'asap') {
            $promised = (clone $now)->modify("+{$totalEstimatedMinutes} minutes");
            $promisedTime = $promised->format('c');

        } elseif ($mode === 'scheduled') {
            if (empty($requestedTime)) {
                throw new InvalidArgumentException('requested_time is required for scheduled orders.');
            }

            $requested = self::parseIsoDate($requestedTime, $tz);

            // Check 1 — Lead Time
            $earliestAllowed = (clone $now)->modify("+{$minLeadMinutes} minutes");
            if ($requested < $earliestAllowed) {
                throw new InvalidArgumentException(
                    "Requested time is too soon. Earliest allowed: {$earliestAllowed->format('c')} "
                    . "(minimum lead time: {$minLeadMinutes} min)."
                );
            }

            // Check 2 — Business Hours
            self::validateBusinessHours($requested, $openingHoursRaw);

            $promisedTime = $requested->format('c');

        } else {
            throw new InvalidArgumentException("Invalid mode '{$mode}'. Accepted: asap, scheduled.");
        }

        return [
            'mode'                   => $mode,
            'base_prep_minutes'      => $basePrepMinutes,
            'channel_buffer_minutes' => $channelBuffer,
            'load_factor'            => round($loadFactor, 2),
            'active_orders'          => $activeOrders,
            'estimated_minutes'      => $totalEstimatedMinutes,
            'promised_time'          => $promisedTime,
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private static function parseIsoDate(string $raw, DateTimeZone $tz): DateTime
    {
        $dt = DateTime::createFromFormat(DateTime::ATOM, $raw);
        if (!$dt) {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $raw, $tz);
        }
        if (!$dt) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $raw, $tz);
        }
        if (!$dt) {
            throw new InvalidArgumentException(
                "Invalid requested_time format: '{$raw}'. Use ISO 8601 (e.g. 2025-06-15T18:30:00+02:00)."
            );
        }
        $dt->setTimezone($tz);
        return $dt;
    }

    /**
     * Validates that $requested falls within the tenant's opening hours.
     *
     * Expected opening_hours_json structure:
     * {
     *   "monday":    { "open": "11:00", "close": "22:00" },
     *   "tuesday":   { "open": "11:00", "close": "22:00" },
     *   ...
     *   "sunday":    null   // null or absent = closed that day
     * }
     */
    private static function validateBusinessHours(DateTime $requested, ?string $openingHoursRaw): void
    {
        if ($openingHoursRaw === null || $openingHoursRaw === '') {
            return; // no hours configured — assume always open
        }

        $hours = json_decode($openingHoursRaw, true);
        if (!is_array($hours)) {
            return; // malformed JSON — fail open to avoid blocking orders
        }

        $dayName  = strtolower($requested->format('l')); // e.g. "monday"
        $dayHours = $hours[$dayName] ?? null;

        if ($dayHours === null) {
            throw new InvalidArgumentException(
                "Restaurant is closed on {$dayName}. Please choose a different day."
            );
        }

        $openStr  = $dayHours['open']  ?? null;
        $closeStr = $dayHours['close'] ?? null;

        if ($openStr === null || $closeStr === null) {
            throw new InvalidArgumentException(
                "Restaurant is closed on {$dayName}. Please choose a different day."
            );
        }

        $requestedHHMM = $requested->format('H:i');

        if ($requestedHHMM < $openStr || $requestedHHMM >= $closeStr) {
            throw new InvalidArgumentException(
                "Requested time {$requestedHHMM} is outside business hours for {$dayName} ({$openStr}–{$closeStr})."
            );
        }
    }
}
