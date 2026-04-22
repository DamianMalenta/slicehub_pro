<?php

declare(strict_types=1);

/**
 * Food Cost Engine — Theoretical Cost & Margin Analysis
 *
 * Read-only engine that combines recipe definitions, modifier warehouse links,
 * and live AVCO valuations to produce per-channel margin analysis for a menu item.
 *
 * Formula per ingredient:
 *   ingredient_cost = qty × (1 + waste% / 100) × AVCO_per_unit
 *
 * AVCO is never modified — this engine is purely analytical.
 *
 * Target schema: sh_recipes, sh_modifiers, sh_item_modifiers, sh_menu_items,
 *                sh_price_tiers, wh_stock.
 */
class FoodCostEngine
{
    private const STATUS_EXCELLENT = 'excellent';
    private const STATUS_HEALTHY   = 'healthy';
    private const STATUS_AT_RISK   = 'at_risk';
    private const STATUS_CRITICAL  = 'critical';

    /**
     * @return array{success: true, item_sku: string, food_cost_analysis: array}
     *
     * @throws \InvalidArgumentException on missing/invalid input
     * @throws \RuntimeException         when item or recipe not found
     */
    public static function calculateForSku(
        PDO    $pdo,
        int    $tenantId,
        string $warehouseId,
        string $itemSku
    ): array {

        if (trim($itemSku) === '') {
            throw new \InvalidArgumentException('Missing required parameter: item_sku.');
        }

        // =================================================================
        // STEP 1: BASE RECIPE — ingredients + AVCO
        // =================================================================

        $stmtRecipe = $pdo->prepare("
            SELECT
                r.warehouse_sku,
                r.quantity_base,
                r.waste_percent,
                r.is_packaging,
                ws.current_avco_price,
                ws.updated_at AS avco_updated_at
            FROM sh_recipes r
            LEFT JOIN wh_stock ws
                ON ws.sku          = r.warehouse_sku
               AND ws.tenant_id    = r.tenant_id
               AND ws.warehouse_id = :wid
            WHERE r.tenant_id    = :tid
              AND r.menu_item_sku = :itemSku
        ");
        $stmtRecipe->execute([
            ':tid'     => $tenantId,
            ':wid'     => $warehouseId,
            ':itemSku' => $itemSku,
        ]);
        $recipeRows = $stmtRecipe->fetchAll(PDO::FETCH_ASSOC);

        if (empty($recipeRows)) {
            throw new \RuntimeException(
                "No recipe found for item SKU '{$itemSku}'. Cannot compute food cost."
            );
        }

        $recipeCost      = 0.0;
        $wasteCost       = 0.0;
        $missingAvco     = false;
        $latestAvcoDate  = null;
        $ingredientDetails = [];

        foreach ($recipeRows as $row) {
            $qty       = (float)$row['quantity_base'];
            $wastePct  = (float)$row['waste_percent'];
            $avco      = null;

            if ($row['current_avco_price'] === null) {
                $missingAvco = true;
                $avco = 0.0;
            } else {
                $avco = (float)$row['current_avco_price'];
            }

            $lineBaseCost  = round($qty * $avco, 4);
            $lineWasteCost = round($qty * ($wastePct / 100) * $avco, 4);

            $recipeCost += $lineBaseCost;
            $wasteCost  += $lineWasteCost;

            if ($row['avco_updated_at'] !== null) {
                if ($latestAvcoDate === null || $row['avco_updated_at'] > $latestAvcoDate) {
                    $latestAvcoDate = $row['avco_updated_at'];
                }
            }

            $ingredientDetails[] = [
                'warehouse_sku' => $row['warehouse_sku'],
                'quantity'      => $qty,
                'waste_percent' => $wastePct,
                'avco'          => $avco !== null ? number_format($avco, 2, '.', '') : null,
                'base_cost'     => number_format($lineBaseCost, 2, '.', ''),
                'waste_cost'    => number_format($lineWasteCost, 2, '.', ''),
                'total_cost'    => number_format(round($lineBaseCost + $lineWasteCost, 2), 2, '.', ''),
                'is_packaging'  => (bool)$row['is_packaging'],
                'avco_missing'  => ($row['current_avco_price'] === null),
            ];
        }

        $recipeCost = round($recipeCost, 2);
        $wasteCost  = round($wasteCost, 2);

        // =================================================================
        // STEP 2: MODIFIERS — item-specific via sh_item_modifiers join
        // =================================================================

        $stmtModifiers = $pdo->prepare("
            SELECT
                m.ascii_key       AS modifier_sku,
                m.name            AS modifier_name,
                m.linked_warehouse_sku,
                m.linked_quantity,
                m.linked_waste_percent,
                m.is_default,
                ws.current_avco_price,
                pt.channel        AS price_channel,
                pt.price          AS selling_price
            FROM sh_menu_items mi
            JOIN sh_item_modifiers im
                ON im.item_id = mi.id
            JOIN sh_modifiers m
                ON m.group_id   = im.group_id
               AND m.is_active  = 1
               AND m.is_deleted = 0
            LEFT JOIN wh_stock ws
                ON ws.sku          = m.linked_warehouse_sku
               AND ws.tenant_id    = :tid2
               AND ws.warehouse_id = :wid2
            LEFT JOIN sh_price_tiers pt
                ON pt.target_type = 'MODIFIER'
               AND pt.target_sku  = m.ascii_key
               AND (pt.tenant_id  = :tid3 OR pt.tenant_id = 0)
            WHERE mi.tenant_id = :tid
              AND mi.ascii_key = :itemSku
            ORDER BY m.ascii_key, pt.tenant_id DESC
        ");
        $stmtModifiers->execute([
            ':tid'     => $tenantId,
            ':tid2'    => $tenantId,
            ':tid3'    => $tenantId,
            ':wid2'    => $warehouseId,
            ':itemSku' => $itemSku,
        ]);
        $modifierRows = $stmtModifiers->fetchAll(PDO::FETCH_ASSOC);

        // Group by modifier SKU, collect per-channel prices
        $modByKey = [];
        foreach ($modifierRows as $mr) {
            $key = $mr['modifier_sku'];

            if (!isset($modByKey[$key])) {
                $linkedQty   = (float)$mr['linked_quantity'];
                $linkedWaste = (float)$mr['linked_waste_percent'];
                $modAvco     = ($mr['current_avco_price'] !== null)
                    ? (float)$mr['current_avco_price']
                    : 0.0;

                $hasWarehouseLink = ($mr['linked_warehouse_sku'] !== null
                                 && $mr['linked_warehouse_sku'] !== '');

                $modFoodCost = 0.0;
                if ($hasWarehouseLink) {
                    $modFoodCost = round(
                        $linkedQty * (1 + $linkedWaste / 100) * $modAvco, 2
                    );
                }

                if ($mr['current_avco_price'] === null && $hasWarehouseLink) {
                    $missingAvco = true;
                }

                $modByKey[$key] = [
                    'modifier_sku'         => $key,
                    'name'                 => $mr['modifier_name'],
                    'linked_warehouse_sku' => $mr['linked_warehouse_sku'],
                    'linked_quantity'      => $linkedQty,
                    'linked_waste_percent' => $linkedWaste,
                    'is_default'           => (bool)$mr['is_default'],
                    'food_cost'            => number_format($modFoodCost, 2, '.', ''),
                    'avco_missing'         => ($mr['current_avco_price'] === null && $hasWarehouseLink),
                    'selling_prices'       => [],
                ];
            }

            if ($mr['price_channel'] !== null && !isset($modByKey[$key]['selling_prices'][$mr['price_channel']])) {
                $modByKey[$key]['selling_prices'][$mr['price_channel']]
                    = number_format((float)$mr['selling_price'], 2, '.', '');
            }
        }

        $modifiersAnalysis = array_values($modByKey);

        // =================================================================
        // STEP 3: CHANNEL MARGINS
        // =================================================================

        $totalFoodCost = $recipeCost + $wasteCost;

        $stmtPrices = $pdo->prepare("
            SELECT channel, price
            FROM sh_price_tiers
            WHERE target_type = 'ITEM'
              AND target_sku  = :itemSku
              AND (tenant_id  = :tid OR tenant_id = 0)
            ORDER BY channel ASC, tenant_id DESC
        ");
        $stmtPrices->execute([
            ':itemSku' => $itemSku,
            ':tid'     => $tenantId,
        ]);
        $priceRows = $stmtPrices->fetchAll(PDO::FETCH_ASSOC);

        $channelMap = [];
        foreach ($priceRows as $pr) {
            $ch = $pr['channel'];
            if (isset($channelMap[$ch])) continue;
            $channelMap[$ch] = (float)$pr['price'];
        }

        $channels = [];
        foreach ($channelMap as $ch => $price) {
            if ($price == 0.0) {
                $fcPct     = 0.0;
                $marginPct = 0.0;
                $status    = self::STATUS_CRITICAL;
            } else {
                $fcPct     = round(($totalFoodCost / $price) * 100, 1);
                $marginPct = round(100 - $fcPct, 1);
                $status    = self::resolveStatus($fcPct);
            }

            $channels[] = [
                'channel'       => $ch,
                'price'         => number_format($price, 2, '.', ''),
                'food_cost_pct' => $fcPct,
                'margin_pct'    => $marginPct,
                'status'        => $status,
            ];
        }

        // =================================================================
        // STEP 4: FORMAT RETURN
        // =================================================================

        return [
            'success'  => true,
            'item_sku' => $itemSku,
            'food_cost_analysis' => [
                'recipe_cost'          => number_format($recipeCost, 2, '.', ''),
                'waste_cost'           => number_format($wasteCost, 2, '.', ''),
                'total_food_cost'      => number_format($totalFoodCost, 2, '.', ''),
                'ingredients'          => $ingredientDetails,
                'modifiers'            => $modifiersAnalysis,
                'channels'             => $channels,
                'missing_avco_warning' => $missingAvco,
                'last_avco_update'     => $latestAvcoDate,
            ],
        ];
    }

    /**
     * Maps food cost percentage to a status tier.
     */
    private static function resolveStatus(float $fcPct): string
    {
        if ($fcPct <= 25.0) {
            return self::STATUS_EXCELLENT;
        }
        if ($fcPct <= 33.0) {
            return self::STATUS_HEALTHY;
        }
        if ($fcPct <= 40.0) {
            return self::STATUS_AT_RISK;
        }
        return self::STATUS_CRITICAL;
    }
}
