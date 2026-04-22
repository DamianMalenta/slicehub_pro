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
             LIMIT 1"
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
             LIMIT 1"
        );

        $stmtModPriceFallback = $pdo->prepare(
            "SELECT price FROM sh_price_tiers
             WHERE target_type = 'MODIFIER' AND target_sku = :sku AND channel = 'POS'
             LIMIT 1"
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

        $resolveModPrice = function (string $modSku) use ($stmtModPrice, $stmtModPriceFallback, $channel): int {
            $stmtModPrice->execute([':sku' => $modSku, ':channel' => $channel]);
            $row = $stmtModPrice->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return (int)round((float)$row['price'] * 100);
            }

            if ($channel !== 'POS') {
                $stmtModPriceFallback->execute([':sku' => $modSku]);
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

                $stmtItemPrice->execute([':sku' => $halfASku, ':channel' => $channel]);
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

                $stmtItemPrice->execute([':sku' => $halfBSku, ':channel' => $channel]);
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

                $stmtItemPrice->execute([':sku' => $effectiveSku, ':channel' => $channel]);
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
                'item_sku'         => $effectiveSku,
                'snapshot_name'    => $snapshotName,
                'unit_price_grosze'=> $unitPriceGrosze,
                'quantity'         => $quantity,
                'line_total_grosze'=> $lineTotalGrosze,
                'vat_rate'         => $vatRate,
                'vat_amount_grosze'=> $vatAmountGrosze,
            ];
        }

        // =====================================================================
        // 6. PROMO / DISCOUNT ENGINE
        // =====================================================================
        $discountGrosze    = 0;
        $appliedDiscount   = null;
        $appliedPromoCode  = null;
        $promoCode         = trim($input['promo_code'] ?? '');

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
                        && $subtotalGrosze >= $minOrderGrosze
                        && in_array($channel, $allowedList, true);

                if ($isValid) {
                    $type  = $promo['type'];
                    $value = (float)$promo['value'];

                    if ($type === 'percentage') {
                        $discountGrosze = (int)round(($subtotalGrosze * $value) / 100);
                    } elseif ($type === 'fixed_amount') {
                        $discountGrosze = (int)($value * 100);
                    }

                    $discountGrosze = min($discountGrosze, $subtotalGrosze);

                    if ($discountGrosze > 0) {
                        $appliedPromoCode = $promo['code'];
                        $appliedDiscount  = [
                            'code'            => $promo['code'],
                            'type'            => $type,
                            'subtotal_before' => $fmtMoney($subtotalGrosze),
                            'discount_amount' => $fmtMoney($discountGrosze),
                            'subtotal_after'  => $fmtMoney($subtotalGrosze - $discountGrosze),
                        ];
                    }
                }
            }
        }

        // =====================================================================
        // 7. ORDER TOTALS
        // =====================================================================
        $deliveryFeeGrosze = 0;
        $grandTotalGrosze  = $subtotalGrosze - $discountGrosze + $deliveryFeeGrosze;
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
            'discount'     => $fmtMoney($discountGrosze),
            'delivery_fee' => $fmtMoney($deliveryFeeGrosze),
            'grand_total'  => $fmtMoney($grandTotalGrosze),
            'vat_summary'  => $vatSummary,
        ];

        if ($appliedDiscount !== null) {
            $responseData['applied_discount'] = $appliedDiscount;
        }

        return [
            'channel'            => $channel,
            'order_type'         => $orderType,
            'subtotal_grosze'    => $subtotalGrosze,
            'discount_grosze'    => $discountGrosze,
            'delivery_fee_grosze'=> $deliveryFeeGrosze,
            'grand_total_grosze' => $grandTotalGrosze,
            'loyalty_points'     => $loyaltyPoints,
            'applied_promo_code' => $appliedPromoCode,
            'lines_raw'          => $linesRaw,
            'response'           => $responseData,
        ];
    }
}
