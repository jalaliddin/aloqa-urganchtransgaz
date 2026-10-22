<?php

declare(strict_types=1);

namespace App\Support;

/** Human-readable numbers in messages, matching the UI: space thousands, comma decimal. */
final class Format
{
    public static function number(string|int|float|null $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $formatted = number_format((float) $value, $decimals, ',', ' ');

        return str_contains($formatted, ',') ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }

    public static function metres(string|int|float|null $value): string
    {
        return self::number($value).' m';
    }
}
