<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact decimal arithmetic for lengths (metres) and money. Never use float maths
 * for values that are compared, summed or stored.
 */
final class Decimal
{
    public static function of(string|int|float|null $value, int $scale = 2): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero()->toScale($scale);
        }

        return BigDecimal::of(is_float($value) ? number_format($value, $scale + 4, '.', '') : (string) $value)
            ->toScale($scale, RoundingMode::HalfUp);
    }

    public static function sum(iterable $values, int $scale = 2): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus(self::of($value, $scale));
        }

        return (string) $total->toScale($scale, RoundingMode::HalfUp);
    }

    public static function diff(string|int|float|null $a, string|int|float|null $b, int $scale = 2): string
    {
        return (string) self::of($a, $scale)->minus(self::of($b, $scale));
    }

    /**
     * True when |actual − expected| exceeds max(absolute, expected × percent / 100).
     */
    public static function exceedsTolerance(
        string|int|float|null $expected,
        string|int|float|null $actual,
        float $absolute,
        float $percent,
        int $scale = 2,
    ): bool {
        $expectedValue = self::of($expected, $scale);
        $difference = self::of($actual, $scale)->minus($expectedValue)->abs();
        $relative = $expectedValue->abs()->multipliedBy(self::of($percent, 4))->dividedBy(100, $scale + 2, RoundingMode::HalfUp);
        $allowed = $relative->isGreaterThan(self::of($absolute, $scale)) ? $relative : self::of($absolute, $scale);

        return $difference->isGreaterThan($allowed);
    }

    public static function percent(string|int|float|null $part, string|int|float|null $whole, int $scale = 1): ?float
    {
        $wholeValue = self::of($whole, 3);
        if ($wholeValue->isZero()) {
            return null;
        }

        return self::of($part, 3)->multipliedBy(100)->dividedBy($wholeValue, $scale, RoundingMode::HalfUp)->toFloat();
    }
}
