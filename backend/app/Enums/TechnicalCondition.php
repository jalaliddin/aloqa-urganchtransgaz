<?php

declare(strict_types=1);

namespace App\Enums;

enum TechnicalCondition: string
{
    use HasOptions;

    case Good = 'good';
    case Satisfactory = 'satisfactory';
    case Poor = 'poor';
    case Critical = 'critical';
    case Unknown = 'unknown';

    /** Higher = worse. Unknown has no rank and never hides a known worse state. */
    public function rank(): int
    {
        return match ($this) {
            self::Good => 1,
            self::Satisfactory => 2,
            self::Poor => 3,
            self::Critical => 4,
            self::Unknown => 0,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Good => 'teal',
            self::Satisfactory => 'blue',
            self::Poor => 'orange',
            self::Critical => 'red',
            self::Unknown => 'gray',
        };
    }
}
