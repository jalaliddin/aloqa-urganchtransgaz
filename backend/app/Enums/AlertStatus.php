<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertStatus: string
{
    use HasOptions;

    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function color(): string
    {
        return match ($this) {
            self::Open => 'orange',
            self::Acknowledged => 'blue',
            self::Resolved => 'teal',
        };
    }
}
