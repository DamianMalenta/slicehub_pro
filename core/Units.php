<?php

declare(strict_types=1);

/**
 * Kanoniczne jednostki — port logiki z core/js/core_validator.js (UNITS_CANON).
 */
final class Units
{
    /** @var array<string, array{base: string, factor: float}> */
    private const CANON = [
        'kg'  => ['base' => 'kg',  'factor' => 1.0],
        'g'   => ['base' => 'kg',  'factor' => 0.001],
        'dag' => ['base' => 'kg',  'factor' => 0.01],
        'l'   => ['base' => 'l',   'factor' => 1.0],
        'ml'  => ['base' => 'l',   'factor' => 0.001],
        'szt' => ['base' => 'szt', 'factor' => 1.0],
        'pcs' => ['base' => 'szt', 'factor' => 1.0],
        'op'  => ['base' => 'op',  'factor' => 1.0],
    ];

    /** Jednostki „opakowaniowe” z FA — wymagają pack_size z nazwy / mappingu. */
    private const PIECE_LIKE = ['szt', 'pcs', 'op', 'opak', 'opakowanie', 'sztuka', 'szt.', 'kpl', 'kart', 'karton'];

    public static function normalizeLabel(string $raw): string
    {
        $s = mb_strtolower(trim($raw), 'UTF-8');
        $s = str_replace(['.', ' '], '', $s);
        if ($s === '') {
            return '';
        }
        $aliases = [
            'szt' => ['szt', 'sztuka', 'sztuk', 'pcs', 'piece', 'kpl', 'kart', 'karton'],
            'op'  => ['op', 'opak', 'opakowanie', 'opakow', 'pak'],
            'kg'  => ['kg', 'kilogram', 'kilogramy'],
            'g'   => ['g', 'gram', 'gramy', 'gr'],
            'l'   => ['l', 'litr', 'litry', 'ltr'],
            'ml'  => ['ml', 'mililitr', 'mililitry'],
        ];
        foreach ($aliases as $canon => $list) {
            if (in_array($s, $list, true)) {
                return $canon;
            }
        }
        if (isset(self::CANON[$s])) {
            return $s;
        }

        return $s;
    }

    public static function isPieceLikeUnit(string $unit): bool
    {
        $u = self::normalizeLabel($unit);

        return in_array($u, self::PIECE_LIKE, true) || $u === 'szt' || $u === 'op';
    }

    /**
     * @return array{value: float, base: string}|null
     */
    public static function toBaseGroup(float $qty, string $unit): ?array
    {
        $u = self::normalizeLabel($unit);
        if ($u === '' || !isset(self::CANON[$u])) {
            return null;
        }
        $map = self::CANON[$u];

        return [
            'value' => $qty * $map['factor'],
            'base'  => $map['base'],
        ];
    }

    public static function convert(float $qty, string $fromUnit, string $toUnit): ?float
    {
        $from = self::normalizeLabel($fromUnit);
        $to   = self::normalizeLabel($toUnit);
        if ($from === $to) {
            return $qty;
        }
        $f = self::toBaseGroup($qty, $from);
        $tMap = self::CANON[$to] ?? null;
        if ($f === null || $tMap === null || $f['base'] !== $tMap['base']) {
            return null;
        }
        if ($tMap['factor'] == 0.0) {
            return null;
        }

        return $f['value'] / $tMap['factor'];
    }

    public static function baseGroupOf(string $unit): ?string
    {
        $u = self::normalizeLabel($unit);
        if ($u === '' || !isset(self::CANON[$u])) {
            return null;
        }

        return self::CANON[$u]['base'];
    }
}
