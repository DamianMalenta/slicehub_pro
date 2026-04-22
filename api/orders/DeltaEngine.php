<?php
// =============================================================================
// SliceHub Enterprise — Kitchen Delta Detection Engine
// api/orders/DeltaEngine.php
//
// Stateless utility: compares old DB order lines with newly calculated lines
// from CartEngine to produce a structured diff for the kitchen display.
//
// Matching is done by line_id (UUID). Lines without a line_id are always 'added'.
// JSON array fields (modifiers, removals) are compared structurally after
// decode + sort, so ["A","B"] == ["B","A"].
// =============================================================================

class DeltaEngine
{
    /**
     * @param array $oldLinesDB        Rows from sh_order_lines (keyed: id, item_sku, snapshot_name,
     *                                 quantity, unit_price, line_total, vat_rate, vat_amount,
     *                                 modifiers_json, removed_ingredients_json, comment)
     * @param array $newLinesCartEngine Rows from CartEngine::calculate()['lines_raw']
     *                                 (keyed: line_id, item_sku, snapshot_name, quantity,
     *                                 unit_price_grosze, line_total_grosze, vat_rate,
     *                                 vat_amount_grosze, modifiers_json,
     *                                 removed_ingredients_json, comment)
     *
     * @return array Empty array if zero changes; otherwise keyed:
     *               added[], removed[], modified[], unchanged_count, generated_at
     */
    public static function computeDelta(array $oldLinesDB, array $newLinesCartEngine): array
    {
        $oldById = [];
        foreach ($oldLinesDB as $row) {
            $oldById[$row['id']] = $row;
        }

        $newById     = [];
        $addedLines  = [];
        foreach ($newLinesCartEngine as $nl) {
            $lid = $nl['line_id'] ?? null;
            if ($lid === null || !isset($oldById[$lid])) {
                $addedLines[] = [
                    'line_id'       => $lid,
                    'item_sku'      => $nl['item_sku'],
                    'snapshot_name' => $nl['snapshot_name'],
                    'quantity'      => $nl['quantity'],
                ];
            } else {
                $newById[$lid] = $nl;
            }
        }

        $removedLines    = [];
        $modifiedLines   = [];
        $unchangedCount  = 0;

        foreach ($oldById as $id => $old) {
            if (!isset($newById[$id])) {
                $removedLines[] = [
                    'line_id'       => $id,
                    'item_sku'      => $old['item_sku'],
                    'snapshot_name' => $old['snapshot_name'],
                    'quantity'      => (int)$old['quantity'],
                ];
                continue;
            }

            $new     = $newById[$id];
            $changes = [];

            if ((int)$old['quantity'] !== $new['quantity']) {
                $changes['quantity'] = [
                    'old' => (int)$old['quantity'],
                    'new' => $new['quantity'],
                ];
            }

            if (!self::jsonEqual($old['modifiers_json'] ?? null, $new['modifiers_json'] ?? null)) {
                $changes['modifiers_json'] = [
                    'old' => $old['modifiers_json'],
                    'new' => $new['modifiers_json'],
                ];
            }

            if (!self::jsonEqual($old['removed_ingredients_json'] ?? null, $new['removed_ingredients_json'] ?? null)) {
                $changes['removed_ingredients_json'] = [
                    'old' => $old['removed_ingredients_json'],
                    'new' => $new['removed_ingredients_json'],
                ];
            }

            $oldComment = ($old['comment'] ?? null) ?: null;
            $newComment = $new['comment'] ?? null;
            if ($oldComment !== $newComment) {
                $changes['comment'] = [
                    'old' => $oldComment,
                    'new' => $newComment,
                ];
            }

            if (!empty($changes)) {
                $modifiedLines[] = [
                    'line_id'       => $id,
                    'item_sku'      => $old['item_sku'],
                    'snapshot_name' => $old['snapshot_name'],
                    'changes'       => $changes,
                ];
            } else {
                $unchangedCount++;
            }
        }

        if (empty($addedLines) && empty($removedLines) && empty($modifiedLines)) {
            return [];
        }

        return [
            'added'           => $addedLines,
            'removed'         => $removedLines,
            'modified'        => $modifiedLines,
            'unchanged_count' => $unchangedCount,
            'generated_at'    => date('c'),
        ];
    }

    /**
     * Order-insensitive comparison of two JSON-encoded arrays.
     * Handles null, empty string, "null", and reordered elements gracefully.
     */
    private static function jsonEqual(?string $a, ?string $b): bool
    {
        $decA = self::normalizeJson($a);
        $decB = self::normalizeJson($b);

        if ($decA === null && $decB === null) {
            return true;
        }
        if ($decA === null || $decB === null) {
            return false;
        }

        return $decA === $decB;
    }

    /**
     * Decode a JSON string, sort its elements recursively, then re-encode
     * to a canonical form for comparison.
     */
    private static function normalizeJson(?string $raw): ?string
    {
        if ($raw === null || $raw === '' || $raw === 'null') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded)) {
            return null;
        }

        self::recursiveSort($decoded);
        return json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    private static function recursiveSort(array &$arr): void
    {
        foreach ($arr as &$val) {
            if (is_array($val)) {
                self::recursiveSort($val);
            }
        }
        unset($val);

        if (array_is_list($arr)) {
            usort($arr, fn($a, $b) => strcmp(
                is_array($a) ? json_encode($a) : (string)$a,
                is_array($b) ? json_encode($b) : (string)$b
            ));
        } else {
            ksort($arr);
        }
    }
}
