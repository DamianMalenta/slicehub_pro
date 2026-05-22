<?php

declare(strict_types=1);

/**
 * Wyciąga gramaturę / objętość opakowania z nazwy pozycji FA (np. „BAZYLIA 20G”).
 */
final class PackSizeExtractor
{
    /**
     * @return array{qty_base: float, base_unit: string, source: string}|null
     *         qty_base = ilość w jednostce bazowej na 1 jednostkę FA (szt/op)
     */
    /**
     * @param string $externalDescription FA P_7A (często tu jest gramatura, gdy P_7 jest skrócone)
     */
    public static function extractPerPiece(
        string $externalName,
        string $targetBaseUnit,
        string $externalDescription = ''
    ): ?array {
        $target = Units::normalizeLabel($targetBaseUnit);
        if (!in_array($target, ['kg', 'l'], true)) {
            return null;
        }

        $name = trim($externalName);
        $desc = trim($externalDescription);
        $haystack = trim($name . ($desc !== '' ? ' ' . $desc : ''));
        if ($haystack === '') {
            return null;
        }

        // 6×1L, 12x500ml
        if (preg_match(
            '/(\d+)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*(kg|g|dag|l|ml)\b/iu',
            $haystack,
            $m
        )) {
            $count = (float) str_replace(',', '.', $m[1]);
            $amount = (float) str_replace(',', '.', $m[2]);
            $u = Units::normalizeLabel($m[3]);
            $perOne = self::amountToBase($amount, $u, $target);
            if ($perOne === null || $count <= 0) {
                return null;
            }

            return [
                'qty_base'   => round($count * $perOne, 6),
                'base_unit'  => $target,
                'source'     => 'name_multipack',
            ];
        }

        // Ostatnie wystąpienie: 20G, 1,5 kg, 750ml (unikaj mylenia z nr artykułu — preferuj sufiks)
        if (preg_match_all(
            '/(\d+(?:[.,]\d+)?)\s*(kg|g|dag|l|ml)\b/iu',
            $haystack,
            $all,
            PREG_SET_ORDER
        )) {
            $last = $all[count($all) - 1];
            $amount = (float) str_replace(',', '.', $last[1]);
            $u = Units::normalizeLabel($last[2]);
            $perOne = self::amountToBase($amount, $u, $target);
            if ($perOne === null) {
                return null;
            }

            return [
                'qty_base'   => round($perOne, 6),
                'base_unit'  => $target,
                'source'     => 'name_weight',
            ];
        }

        return null;
    }

    private static function amountToBase(float $amount, string $unit, string $targetBase): ?float
    {
        $converted = Units::convert($amount, $unit, $targetBase);
        if ($converted === null) {
            return null;
        }

        return $converted;
    }
}
