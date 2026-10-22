<?php

declare(strict_types=1);

namespace App\Enums;

enum RiskLevel: string
{
    use HasOptions;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public function isHazardous(): bool
    {
        return $this->rank() >= self::High->rank();
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'teal',
            self::Medium => 'yellow',
            self::High => 'orange',
            self::Critical => 'red',
        };
    }
}
