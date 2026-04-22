<?php
// =============================================================================
// SliceHub Enterprise — Server-Authoritative Cart Engine (Reusable Core)
// api/cart/CartEngine.php
//
// Pure calculation class — zero HTTP side effects.
// Used by: calculate.php (preview), checkout.php (persistence).
//
// ALL monetary math is in integers (grosze). Formatting happens only in the
// 'response' key of the returned result array.
// =============================================================================

class CartEngineException extends RuntimeException {}

class CartEngine
{
    // =========================================================================
    // AUTO-PROMOTIONS (sh_promotions) — Faza 4.1
    // Helpery prywatne do oceny rule_json względem stanu koszyka.
    // =========================================================================

    /**
     * Suma line_total_grosze dla linii pasujących do SKU.
     * @param list<array{item_sku:string,line_total_grosze:int,...}> $lines
     */
    private static function sumLinesBySku(array $lines, string $sku): int
    {
        if ($sku === '') return 0;
        $sum = 0;
        foreach ($lines as $ln) {
            if (($ln['item_sku'] ?? '') === $sku) {
                $sum += (int)($ln['line_total_grosze'] ?? 0);
            }
        }
        return $sum;
    }

    /**
     * Suma line_total_grosze dla linii z kategorii (po mapie sku → category_id).
     * @param array<string,int> $catMap
     */
    private static function sumLinesByCategory(array $lines, int $categoryId, array $catMap): int
    {
        if ($categoryId <= 0) return 0;
        $sum = 0;
        foreach ($lines as $ln) {
            $sku = (string)($ln['item_sku'] ?? '');
            if (($catMap[$sku] ?? 0) === $categoryId) {
                $sum += (int)($ln['line_total_grosze'] ?? 0);
            }
        }
        return $sum;
    }

    /**
     * Najtańsza jednostka (unit_price_grosze) dla SKU w koszyku albo null gdy brak.
     */
    private static function cheapestUnitBySku(array $lines, string $sku): ?int
    {
        if ($sku === '') return null;
        $min = null;
        foreach ($lines as $ln) {
            if (($ln['item_sku'] ?? '') === $sku) {
                $unit = (int)($ln['unit_price_grosze'] ?? 0);
                if ($unit > 0 && ($min === null || $unit < $min)) $min = $unit;
            }
        }
        return $min;
    }

    /**
     * Dodatkowe gating po godzinie / dniu tygodnia.
     *   time_window_json: { days:[1..7] (ISO, 1=Pn), start:"HH:MM", end:"HH:MM" }
     */
    private static function isInTimeWindow(?string $twJson, DateTimeImmutable $now): bool
    {
        if ($twJson === null || $twJson === '') return true;
        $tw = json_decode($twJson, true);
        if (!is_array($tw)) return true;

        if (!empty($tw['days']) && is_array($tw['days'])) {
            $dow = (int)$now->format('N');
            $days = array_map('intval', $tw['days']);
            if (!in_array($dow, $days, true)) return false;
        }
        if (!empty($tw['start']) && !empty($tw['end'])) {
            $timeNow = $now->format('H:i');
            $s = (string)$tw['start'];
            $e = (string)$tw['end'];
            if ($s <= $e) {
                if ($timeNow < $s || $timeNow > $e) return false;
            } else {
                // okno przez północ
                if ($timeNow < $s && $timeNow > $e) return false;
            }
        }
        return true;
    }

    /**
     * Wylicza rabat w grosze dla jednej promocji.
     * @param array{id:int,ascii_key:string,name:string,rule_kind:string,rule:?array,badge_text:?string,badge_style:?string} $promo
     * @param array{subtotal_grosze:int,lines:array,category_map:array<string,int>} $ctx
     * @return array{promotion_id:int,ascii_key:string,name:string,rule_kind:string,badge_text:?string,badge_style:string,discount_grosze:int,note:string}|null
     */
    private static function evaluateRule(array $promo, array $ctx): ?array
    {
        $kind = (string)$promo['rule_kind'];
        $rule = $promo['rule'] ?? null;
        if (!is_array($rule)) return null;

        $discount = 0;
        $note = '';
        $subtotal = (int)$ctx['subtotal_grosze'];
        $lines    = $ctx['lines'];
        $catMap   = $ctx['category_map'];

        switch ($kind) {
            case 'discount_percent': {
                $target  = (string)($rule['target'] ?? 'cart');
                $percent = (float)($rule['percent'] ?? 0);
                if ($percent <= 0 || $percent > 100) return null;
                $minSubtotal = isset($rule['min_subtotal'])
                    ? (int)round((float)$rule['min_subtotal'] * 100) : 0;
                if ($subtotal < $minSubtotal) return null;

                if ($target === 'cart') {
                    $discount = (int)round($subtotal * $percent / 100);
                    $note = "-{$percent}% od koszyka";
                } elseif ($target === 'item') {
                    $sku = (string)($rule['sku'] ?? '');
                    $matched = self::sumLinesBySku($lines, $sku);
                    $discount = (int)round($matched * $percent / 100);
                    $note = "-{$percent}% na {$sku}";
                } elseif ($target === 'category') {
                    $cid = (int)($rule['category_id'] ?? 0);
                    $matched = self::sumLinesByCategory($lines, $cid, $catMap);
                    $discount = (int)round($matched * $percent / 100);
                    $note = "-{$percent}% na kategorię #{$cid}";
                }
                break;
            }

            case 'discount_amount': {
                $target = (string)($rule['target'] ?? 'cart');
                $amountGrosze = (int)round((float)($rule['amount'] ?? 0) * 100);
                if ($amountGrosze <= 0) return null;
                $minSubtotal = isset($rule['min_subtotal'])
                    ? (int)round((float)$rule['min_subtotal'] * 100) : 0;
                if ($subtotal < $minSubtotal) return null;

                if ($target === 'cart') {
                    $discount = min($amountGrosze, $subtotal);
                    $note = '-' . number_format($amountGrosze / 100, 2) . ' od koszyka';
                } elseif ($target === 'item') {
                    $sku = (string)($rule['sku'] ?? '');
                    $matched = self::sumLinesBySku($lines, $sku);
                    if ($matched <= 0) return null;
                    $discount = min($amountGrosze, $matched);
                    $note = '-' . number_format($discount / 100, 2) . " na {$sku}";
                } elseif ($target === 'category') {
                    $cid = (int)($rule['category_id'] ?? 0);
                    $matched = self::sumLinesByCategory($lines, $cid, $catMap);
                    if ($matched <= 0) return null;
                    $discount = min($amountGrosze, $matched);
                    $note = '-' . number_format($discount / 100, 2) . " na kategorię #{$cid}";
                }
                break;
            }

            case 'combo_half_price': {
                // Kup `anchor_sku`, drugą `combo_sku` za `percent`% ceny (default 50%).
                $anchorSku = (string)($rule['anchor_sku'] ?? '');
                $comboSku  = (string)($rule['combo_sku']  ?? '');
                $percent   = (float)($rule['percent'] ?? 50);
                if ($anchorSku === '' || $comboSku === '' || $percent <= 0 || $percent > 100) return null;

                if (self::sumLinesBySku($lines, $anchorSku) <= 0) return null;
                $comboUnit = self::cheapestUnitBySku($lines, $comboSku);
                if ($comboUnit === null) return null;

                $discount = (int)round($comboUnit * $percent / 100);
                $note = "kombo {$anchorSku} + -{$percent}% na {$comboSku}";
                break;
            }

            case 'free_item_if_threshold': {
                $minSubtotal = (int)round((float)($rule['min_subtotal'] ?? 0) * 100);
                $freeSku     = (string)($rule['free_sku'] ?? '');
                if ($subtotal < $minSubtotal || $freeSku === '') return null;

                $unit = self::cheapestUnitBySku($lines, $freeSku);
                if ($unit === null) return null;
                $discount = $unit;
                $note = "gratis {$freeSku} (próg " . number_format($minSubtotal / 100, 2) . ')';
                break;
            }

            case 'bundle': {
                $skus = $rule['skus'] ?? [];
                $bundlePriceGrosze = (int)round((float)($rule['bundle_price'] ?? 0) * 100);
                if (!is_array($skus) || count($skus) < 2 || $bundlePriceGrosze <= 0) return null;

                $bundleValue = 0;
                foreach ($skus as $sku) {
                    $unit = self::cheapestUnitBySku($lines, (string)$sku);
                    if ($unit === null) return null;
                    $bundleValue += $unit;
                }
                if ($bundleValue <= $bundlePriceGrosze) return null;
                $discount = $bundleValue - $bundlePriceGrosze;
                $note = 'bundle (-' . number_format($discount / 100, 2) . ')';
                break;
            }

            default:
                return null;
        }

        if ($discount <= 0) return null;
        $discount = min($discount, $subtotal);

        return [
            'promotion_id'    => (int)$promo['id'],
            'ascii_key'       => (string)$promo['ascii_key'],
            'name'            => (string)$promo['name'],
            'rule_kind'       => $kind,
            'badge_text'      => $promo['badge_text']  ?? null,
            'badge_style'     => (string)($promo['badge_style'] ?? 'amber'),
            'discount_grosze' => $discount,
            'note'            => $note,
        ];
    }

    /**
     * Ładuje aktywne sh_promotions dla tenanta (time-gated po SQL) i ocenia je.
     * MVP: best-wins — wybiera jedną promocję dającą największy rabat.
     * V2 (future): flaga `stackable:true` w rule_json, priorities, wykluczenia.
     *
     * @return array{discount_grosze:int, applied:list<array>}
     */
    private static function applyAutoPromotions(
        PDO $pdo,
        int $tenantId,
        int $subtotalGrosze,
        array $linesRaw
    ): array {
        // Schema detection — gdy sh_promotions nie istnieje (migracja 022 nie przeszła), zwracamy zera.
        try {
            $pdo->query('SELECT 1 FROM sh_promotions LIMIT 0');
        } catch (\PDOException $e) {
            return ['discount_grosze' => 0, 'applied' => []];
        }

        // Load
        try {
            $stmt = $pdo->prepare(
                "SELECT id, ascii_key, name, rule_kind, rule_json,
                        badge_text, badge_style, time_window_json
                 FROM sh_promotions
                 WHERE tenant_id = :tid AND is_active = 1
                   AND (valid_from IS NULL OR valid_from <= NOW())
                   AND (valid_to   IS NULL OR valid_to   >= NOW())
                 ORDER BY id ASC"
            );
            $stmt->execute([':tid' => $tenantId]);
            $promoRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return ['discount_grosze' => 0, 'applied' => []];
        }
        if (!$promoRows) return ['discount_grosze' => 0, 'applied' => []];

        // Batch mapa sku → category_id (dla target=category)
        $catMap = [];
        $skus = array_values(array_unique(array_filter(array_map(
            fn($l) => (string)($l['item_sku'] ?? ''),
            $linesRaw
        ))));
        if ($skus) {
            try {
                $ph = implode(',', array_fill(0, count($skus), '?'));
                $stmtCat = $pdo->prepare(
                    "SELECT ascii_key, category_id FROM sh_menu_items
                     WHERE tenant_id = ? AND ascii_key IN ({$ph})"
                );
                $stmtCat->execute(array_merge([$tenantId], $skus));
                foreach ($stmtCat->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $catMap[(string)$r['ascii_key']] = (int)($r['category_id'] ?? 0);
                }
            } catch (\PDOException $e) {
                // skip — target=category nie zadziała, ale reszta tak
            }
        }

        $now = new DateTimeImmutable('now');
        $ctx = [
            'subtotal_grosze' => $subtotalGrosze,
            'lines'           => $linesRaw,
            'category_map'    => $catMap,
        ];

        $candidates = [];
        foreach ($promoRows as $row) {
            if (!self::isInTimeWindow($row['time_window_json'] ?? null, $now)) continue;

            $rule = null;
            if (!empty($row['rule_json'])) {
                $decoded = json_decode((string)$row['rule_json'], true);
                if (is_array($decoded)) $rule = $decoded;
            }

            $evaluated = self::evaluateRule([
                'id'          => (int)$row['id'],
                'ascii_key'   => (string)$row['ascii_key'],
                'name'        => (string)$row['name'],
                'rule_kind'   => (string)$row['rule_kind'],
                'rule'        => $rule,
                'badge_text'  => $row['badge_text']  ?? null,
                'badge_style' => $row['badge_style'] ?? 'amber',
            ], $ctx);

            if ($evaluated !== null) $candidates[] = $evaluated;
        }

        if (!$candidates) return ['discount_grosze' => 0, 'applied' => []];

        // Best-wins (MVP)
        usort($candidates, fn($a, $b) => $b['discount_grosze'] <=> $a['discount_grosze']);
        $best = $candidates[0];

        return [
            'discount_grosze' => $best['discount_grosze'],
            'applied'         => [$best],
        ];
    }


    /**
     * Run a full server-authoritative cart calculation.
     *
     * @return array{
     *   channel: string,
     *   order_type: string,
     *   subtotal_grosze: int,
     *   discount_grosze: int,
     *   delivery_fee_grosze: int,
     *   grand_total_grosze: int,
     *   loyalty_points: int,
     *   applied_promo_code: ?string,
     *   lines_raw: list<array>,
     *   response: array
     * }
     *
     * @throws CartEngineException on validation failure (bad SKU, missing price, etc.)
     */
    public static function calculate(PDO $pdo, int $tenantId, array $input): array
    {
        // =====================================================================
        // 1. VALIDATE INPUT
        // =====================================================================
        $channel   = $input['channel'] ?? '';
        $orderType = $input['order_type'] ?? '';
        $lines     = $input['lines'] ?? [];

        $validChannels   = ['POS', 'Takeaway', 'Delivery'];
        $validOrderTypes = ['dine_in', 'takeaway', 'delivery'];

        if (!in_array($channel, $validChannels, true)) {
            throw new CartEngineException("Invalid channel. Expected: " . implode(', ', $validChannels));
        }
        if (!in_array($orderType, $validOrderTypes, true)) {
            throw new CartEngineException("Invalid order_type. Expected: " . implode(', ', $validOrderTypes));
        }
        if (empty($lines) || !is_array($lines)) {
            throw new CartEngineException('Cart lines array is required and cannot be empty.');
        }

        // =====================================================================
        // 2. RESOLVE HALF-HALF SURCHARGE
        // =====================================================================
        $halfSurchargeGrosze = 200;

        try {
            $stmtSetting = $pdo->prepare(
                "SELECT setting_value FROM sh_tenant_settings
                 WHERE tenant_id = :tid AND setting_key = 'half_half_surcharge'
                 LIMIT 1"
            );
            $stmtSetting->execute([':tid' => $tenantId]);
            $settingRow = $stmtSetting->fetch(PDO::FETCH_ASSOC);
            if ($settingRow) {
                $halfSurchargeGrosze = (int)round((float)$settingRow['setting_value'] * 100);
            }
        } catch (PDOException) {
            // table may not exist yet — use default
        }

        // =====================================================================
        // 3. PREPARE REUSABLE STATEMENTS
        // =====================================================================
        $stmtItem = $pdo->prepare(
            "SELECT ascii_key, name, vat_rate_dine_in, vat_rate_takeaway
             FROM sh_menu_items
             WHERE ascii_key = :sku AND tenant_id = :tid AND is_deleted = 0
             LIMIT 1"
        );

        $stmtItemPrice = $pdo->prepare(
            "SELECT price FROM sh_price_tiers
             WHERE target_type = 'ITEM' AND target_sku = :sku AND channel = :channel
               AND (tenant_id = :tid OR tenant_id = 0)
             ORDER BY tenant_id DESC LIMIT 1"
        );

        $stmtModifier = $pdo->prepare(
            "SELECT m.ascii_key, m.name, m.action_type
             FROM sh_modifiers m
             JOIN sh_modifier_groups mg ON mg.id = m.group_id
             WHERE m.ascii_key = :sku
               AND mg.tenant_id = :tid
               AND m.is_deleted = 0
               AND mg.is_deleted = 0
             LIMIT 1"
        );

        $stmtModPrice = $pdo->prepare(
            "SELECT price FROM sh_price_tiers
             WHERE target_type = 'MODIFIER' AND target_sku = :sku AND channel = :channel
               AND (tenant_id = :tid OR tenant_id = 0)
             ORDER BY tenant_id DESC LIMIT 1"
        );

        $stmtModPriceFallback = $pdo->prepare(
            "SELECT price FROM sh_price_tiers
             WHERE target_type = 'MODIFIER' AND target_sku = :sku AND channel = 'POS'
               AND (tenant_id = :tid OR tenant_id = 0)
             ORDER BY tenant_id DESC LIMIT 1"
        );

        $stmtWarehouseItem = $pdo->prepare(
            "SELECT name FROM sys_items
             WHERE sku = :sku AND tenant_id = :tid
             LIMIT 1"
        );

        // =====================================================================
        // 4. HELPERS
        // =====================================================================
        $fmtMoney = fn(int $grosze): string => number_format($grosze / 100, 2, '.', '');
        $fmtRate  = fn(float $rate): string => number_format($rate, 2, '.', '');

        $resolveModPrice = function (string $modSku) use ($stmtModPrice, $stmtModPriceFallback, $channel, $tenantId): int {
            $stmtModPrice->execute([':sku' => $modSku, ':channel' => $channel, ':tid' => $tenantId]);
            $row = $stmtModPrice->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return (int)round((float)$row['price'] * 100);
            }

            if ($channel !== 'POS') {
                $stmtModPriceFallback->execute([':sku' => $modSku, ':tid' => $tenantId]);
                $row = $stmtModPriceFallback->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return (int)round((float)$row['price'] * 100);
                }
            }

            return 0;
        };

        // =====================================================================
        // 5. PROCESS EACH CART LINE
        // =====================================================================
        $resultLines    = [];
        $linesRaw       = [];
        $subtotalGrosze = 0;
        $vatBuckets     = [];

        foreach ($lines as $idx => $line) {
            $lineId       = $line['line_id'] ?? null;
            $itemSku      = trim($line['item_sku'] ?? '');
            $variantSku   = trim($line['variant_sku'] ?? '');
            $quantity      = (int)($line['quantity'] ?? 0);
            $addedModSkus = $line['added_modifier_skus'] ?? [];
            $removedSkus  = $line['removed_ingredient_skus'] ?? [];
            $comment      = trim($line['comment'] ?? '');
            $isHalf       = !empty($line['is_half']);
            $halfASku     = trim($line['half_a_sku'] ?? '');
            $halfBSku     = trim($line['half_b_sku'] ?? '');

            if ($quantity < 1) {
                throw new CartEngineException("Line #{$idx}: quantity must be at least 1.");
            }

            $basePriceGrosze = 0;
            $snapshotName    = '';
            $vatRate         = 0.0;
            $effectiveSku    = '';
            $lineOutput      = ['line_id' => $lineId];

            // -----------------------------------------------------------------
            // A) HALF-HALF COMPOSITE
            // -----------------------------------------------------------------
            if ($isHalf) {
                if ($halfASku === '' || $halfBSku === '') {
                    throw new CartEngineException("Line #{$idx}: half_a_sku and half_b_sku are required when is_half = true.");
                }

                $stmtItem->execute([':sku' => $halfASku, ':tid' => $tenantId]);
                $halfA = $stmtItem->fetch(PDO::FETCH_ASSOC);
                if (!$halfA) {
                    throw new CartEngineException("Line #{$idx}: half_a_sku '{$halfASku}' not found for this tenant.");
                }

                $stmtItemPrice->execute([':sku' => $halfASku, ':channel' => $channel, ':tid' => $tenantId]);
                $pA = $stmtItemPrice->fetch(PDO::FETCH_ASSOC);
                if (!$pA) {
                    throw new CartEngineException("Line #{$idx}: no '{$channel}' price tier for half_a_sku '{$halfASku}'.");
                }
                $priceAGrosze = (int)round((float)$pA['price'] * 100);

                $stmtItem->execute([':sku' => $halfBSku, ':tid' => $tenantId]);
                $halfB = $stmtItem->fetch(PDO::FETCH_ASSOC);
                if (!$halfB) {
                    throw new CartEngineException("Line #{$idx}: half_b_sku '{$halfBSku}' not found for this tenant.");
                }

                $stmtItemPrice->execute([':sku' => $halfBSku, ':channel' => $channel, ':tid' => $tenantId]);
                $pB = $stmtItemPrice->fetch(PDO::FETCH_ASSOC);
                if (!$pB) {
                    throw new CartEngineException("Line #{$idx}: no '{$channel}' price tier for half_b_sku '{$halfBSku}'.");
                }
                $priceBGrosze = (int)round((float)$pB['price'] * 100);

                $basePriceGrosze = max($priceAGrosze, $priceBGrosze) + $halfSurchargeGrosze;
                $snapshotName    = "½ {$halfA['name']} + ½ {$halfB['name']}";
                $effectiveSku    = "{$halfASku}+{$halfBSku}";

                $vatRate = ($orderType === 'dine_in')
                    ? (float)$halfA['vat_rate_dine_in']
                    : (float)$halfA['vat_rate_takeaway'];

                $lineOutput['is_half']        = true;
                $lineOutput['half_a_sku']     = $halfASku;
                $lineOutput['half_b_sku']     = $halfBSku;
                $lineOutput['snapshot_name']  = $snapshotName;
                $lineOutput['base_price_a']   = $fmtMoney($priceAGrosze);
                $lineOutput['base_price_b']   = $fmtMoney($priceBGrosze);
                $lineOutput['half_surcharge'] = $fmtMoney($halfSurchargeGrosze);
                $lineOutput['composite_base'] = $fmtMoney($basePriceGrosze);
            }
            // -----------------------------------------------------------------
            // B) STANDARD ITEM
            // -----------------------------------------------------------------
            else {
                $effectiveSku = $variantSku !== '' ? $variantSku : $itemSku;

                if ($effectiveSku === '') {
                    throw new CartEngineException("Line #{$idx}: item_sku is required.");
                }

                $stmtItem->execute([':sku' => $effectiveSku, ':tid' => $tenantId]);
                $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
                if (!$item) {
                    throw new CartEngineException("Line #{$idx}: SKU '{$effectiveSku}' not found for this tenant.");
                }

                $stmtItemPrice->execute([':sku' => $effectiveSku, ':channel' => $channel, ':tid' => $tenantId]);
                $priceRow = $stmtItemPrice->fetch(PDO::FETCH_ASSOC);
                if (!$priceRow) {
                    throw new CartEngineException("Line #{$idx}: no '{$channel}' price tier for SKU '{$effectiveSku}'.");
                }

                $basePriceGrosze = (int)round((float)$priceRow['price'] * 100);
                $snapshotName    = $item['name'];

                $vatRate = ($orderType === 'dine_in')
                    ? (float)$item['vat_rate_dine_in']
                    : (float)$item['vat_rate_takeaway'];

                $lineOutput['item_sku'] = $itemSku;
                if ($variantSku !== '') {
                    $lineOutput['variant_sku'] = $variantSku;
                }
                $lineOutput['snapshot_name'] = $snapshotName;
                $lineOutput['base_price']    = $fmtMoney($basePriceGrosze);
            }

            // -----------------------------------------------------------------
            // C) ADDED MODIFIERS
            // -----------------------------------------------------------------
            $resolvedModifiers    = [];
            $modifiersTotalGrosze = 0;

            if (is_array($addedModSkus)) {
                foreach ($addedModSkus as $modSku) {
                    $modSku = trim((string)$modSku);
                    if ($modSku === '') continue;

                    $stmtModifier->execute([':sku' => $modSku, ':tid' => $tenantId]);
                    $mod = $stmtModifier->fetch(PDO::FETCH_ASSOC);
                    if (!$mod) {
                        throw new CartEngineException("Line #{$idx}: modifier SKU '{$modSku}' not found for this tenant.");
                    }

                    $modPriceGrosze        = $resolveModPrice($modSku);
                    $modifiersTotalGrosze += $modPriceGrosze;

                    $resolvedModifiers[] = [
                        'sku'   => $modSku,
                        'name'  => $mod['name'],
                        'price' => $fmtMoney($modPriceGrosze),
                    ];
                }
            }

            // -----------------------------------------------------------------
            // D) REMOVED INGREDIENTS
            // -----------------------------------------------------------------
            $resolvedRemovals = [];

            if (is_array($removedSkus)) {
                foreach ($removedSkus as $remSku) {
                    $remSku = trim((string)$remSku);
                    if ($remSku === '') continue;

                    $remName = $remSku;

                    $stmtModifier->execute([':sku' => $remSku, ':tid' => $tenantId]);
                    $remMod = $stmtModifier->fetch(PDO::FETCH_ASSOC);

                    if ($remMod) {
                        $remName = $remMod['name'];
                    } else {
                        $stmtWarehouseItem->execute([':sku' => $remSku, ':tid' => $tenantId]);
                        $remWh = $stmtWarehouseItem->fetch(PDO::FETCH_ASSOC);
                        if ($remWh) {
                            $remName = $remWh['name'];
                        }
                    }

                    $resolvedRemovals[] = [
                        'sku'   => $remSku,
                        'name'  => $remName,
                        'price' => '0.00',
                    ];
                }
            }

            // -----------------------------------------------------------------
            // E) LINE TOTALS (integer grosze math)
            // -----------------------------------------------------------------
            $unitPriceGrosze = $basePriceGrosze + $modifiersTotalGrosze;
            $lineTotalGrosze = $unitPriceGrosze * $quantity;

            $vatAmountGrosze = ($vatRate > 0)
                ? (int)round($lineTotalGrosze * $vatRate / (100.0 + $vatRate))
                : 0;

            $subtotalGrosze += $lineTotalGrosze;

            $rateKey = $fmtRate($vatRate);
            if (!isset($vatBuckets[$rateKey])) {
                $vatBuckets[$rateKey] = ['gross' => 0, 'vat' => 0];
            }
            $vatBuckets[$rateKey]['gross'] += $lineTotalGrosze;
            $vatBuckets[$rateKey]['vat']   += $vatAmountGrosze;

            // -----------------------------------------------------------------
            // F) BUILD LINE OUTPUT
            // -----------------------------------------------------------------
            $lineOutput['modifiers']           = $resolvedModifiers;
            $lineOutput['removed_ingredients'] = $resolvedRemovals;
            $lineOutput['unit_price']          = $fmtMoney($unitPriceGrosze);
            $lineOutput['quantity']            = $quantity;
            $lineOutput['line_total']          = $fmtMoney($lineTotalGrosze);
            $lineOutput['vat_rate']            = $fmtRate($vatRate);
            $lineOutput['vat_amount']          = $fmtMoney($vatAmountGrosze);

            if ($comment !== '') {
                $lineOutput['comment'] = $comment;
            }

            $resultLines[] = $lineOutput;

            $linesRaw[] = [
                'line_id'               => $lineId,
                'item_sku'              => $effectiveSku,
                'snapshot_name'         => $snapshotName,
                'unit_price_grosze'     => $unitPriceGrosze,
                'quantity'              => $quantity,
                'line_total_grosze'     => $lineTotalGrosze,
                'vat_rate'              => $vatRate,
                'vat_amount_grosze'     => $vatAmountGrosze,
                'modifiers_json'        => !empty($resolvedModifiers)
                    ? json_encode($resolvedModifiers, JSON_UNESCAPED_UNICODE)
                    : null,
                'removed_ingredients_json' => !empty($resolvedRemovals)
                    ? json_encode($resolvedRemovals, JSON_UNESCAPED_UNICODE)
                    : null,
                'comment'               => $comment !== '' ? $comment : null,
            ];
        }

        // =====================================================================
        // 5.5. AUTO-PROMOTIONS (sh_promotions — M022) — Faza 4.1
        //
        // Promocje auto-aplikowane (bez kodu) — time-gated w SQL, window-gated
        // w PHP. MVP: best-wins (jedna promocja = największy rabat). Aplikujemy
        // PRZED promo_code aby kod liczył się od subtotal after auto-promo.
        // =====================================================================
        $autoPromoResult   = self::applyAutoPromotions($pdo, $tenantId, $subtotalGrosze, $linesRaw);
        $autoDiscountGrosze = (int)$autoPromoResult['discount_grosze'];
        $appliedAutoPromos  = $autoPromoResult['applied'];

        // =====================================================================
        // 6. PROMO / DISCOUNT ENGINE (kod ręczny — sh_promo_codes, legacy)
        // =====================================================================
        $discountGrosze    = 0;
        $appliedDiscount   = null;
        $appliedPromoCode  = null;
        $promoCode         = trim($input['promo_code'] ?? '');

        // Subtotal „po" auto-promocji dla oceny progu kodu (min_order_value)
        $subtotalForCode = max(0, $subtotalGrosze - $autoDiscountGrosze);

        if ($promoCode !== '') {
            $stmtPromo = $pdo->prepare(
                "SELECT code, type, value, min_order_value, max_uses, current_uses,
                        valid_from, valid_to, allowed_channels
                 FROM sh_promo_codes
                 WHERE code = :code AND tenant_id = :tid AND is_active = 1
                 LIMIT 1"
            );
            $stmtPromo->execute([':code' => $promoCode, ':tid' => $tenantId]);
            $promo = $stmtPromo->fetch(PDO::FETCH_ASSOC);

            if ($promo) {
                $now            = date('Y-m-d H:i:s');
                $minOrderGrosze = (int)round((float)$promo['min_order_value'] * 100);

                $allowedRaw  = $promo['allowed_channels'];
                $allowedList = json_decode($allowedRaw, true);
                if (!is_array($allowedList)) {
                    $allowedList = array_map('trim', explode(',', (string)$allowedRaw));
                }

                $isValid = $now >= $promo['valid_from']
                        && $now <= $promo['valid_to']
                        && (int)$promo['current_uses'] < (int)$promo['max_uses']
                        && $subtotalForCode >= $minOrderGrosze
                        && in_array($channel, $allowedList, true);

                if ($isValid) {
                    $type  = $promo['type'];
                    $value = (float)$promo['value'];

                    if ($type === 'percentage') {
                        $discountGrosze = (int)round(($subtotalForCode * $value) / 100);
                    } elseif ($type === 'fixed_amount') {
                        $discountGrosze = (int)($value * 100);
                    }

                    $discountGrosze = min($discountGrosze, $subtotalForCode);

                    if ($discountGrosze > 0) {
                        $appliedPromoCode = $promo['code'];
                        $appliedDiscount  = [
                            'code'            => $promo['code'],
                            'type'            => $type,
                            'subtotal_before' => $fmtMoney($subtotalForCode),
                            'discount_amount' => $fmtMoney($discountGrosze),
                            'subtotal_after'  => $fmtMoney($subtotalForCode - $discountGrosze),
                        ];
                    }
                }
            }
        }

        // Łączny rabat = auto-promocje + kod ręczny
        $totalDiscountGrosze = $autoDiscountGrosze + $discountGrosze;
        $totalDiscountGrosze = min($totalDiscountGrosze, $subtotalGrosze);

        // =====================================================================
        // 7. ORDER TOTALS
        // =====================================================================
        $deliveryFeeGrosze = 0;
        $grandTotalGrosze  = $subtotalGrosze - $totalDiscountGrosze + $deliveryFeeGrosze;
        $loyaltyPoints     = (int)floor($grandTotalGrosze / 10);

        $vatSummary = [];
        foreach ($vatBuckets as $rate => $bucket) {
            $netGrosze    = $bucket['gross'] - $bucket['vat'];
            $vatSummary[] = [
                'rate'        => $rate,
                'net_total'   => $fmtMoney($netGrosze),
                'vat_total'   => $fmtMoney($bucket['vat']),
                'gross_total' => $fmtMoney($bucket['gross']),
            ];
        }

        // =====================================================================
        // 8. BUILD RESULT
        // =====================================================================
        $responseData = [
            'channel'      => $channel,
            'order_type'   => $orderType,
            'lines'        => $resultLines,
            'subtotal'     => $fmtMoney($subtotalGrosze),
            'discount'     => $fmtMoney($totalDiscountGrosze),
            'delivery_fee' => $fmtMoney($deliveryFeeGrosze),
            'grand_total'  => $fmtMoney($grandTotalGrosze),
            'vat_summary'  => $vatSummary,
        ];

        if ($appliedDiscount !== null) {
            $responseData['applied_discount'] = $appliedDiscount;
        }

        // Auto-promocje — format czytelny dla klienta (grosze → string "zł.gr")
        if (!empty($appliedAutoPromos)) {
            $responseData['applied_auto_promotions'] = array_map(fn($p) => [
                'promotionId' => $p['promotion_id'],
                'asciiKey'    => $p['ascii_key'],
                'name'        => $p['name'],
                'ruleKind'    => $p['rule_kind'],
                'badgeText'   => $p['badge_text'],
                'badgeStyle'  => $p['badge_style'],
                'discount'    => $fmtMoney($p['discount_grosze']),
                'note'        => $p['note'],
            ], $appliedAutoPromos);
            $responseData['auto_promotion_discount'] = $fmtMoney($autoDiscountGrosze);
        }

        return [
            'channel'                  => $channel,
            'order_type'               => $orderType,
            'subtotal_grosze'          => $subtotalGrosze,
            'discount_grosze'          => $totalDiscountGrosze,
            'auto_discount_grosze'     => $autoDiscountGrosze,
            'code_discount_grosze'     => $discountGrosze,
            'delivery_fee_grosze'      => $deliveryFeeGrosze,
            'grand_total_grosze'       => $grandTotalGrosze,
            'loyalty_points'           => $loyaltyPoints,
            'applied_promo_code'       => $appliedPromoCode,
            'applied_auto_promotions'  => $appliedAutoPromos,
            'lines_raw'                => $linesRaw,
            'response'                 => $responseData,
        ];
    }
}
