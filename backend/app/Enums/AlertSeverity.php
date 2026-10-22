<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertSeverity: string
{
    use HasOptions;

    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function color(): string
    {
        return match ($this) {
            self::Info => 'blue',
            self::Warning => 'orange',
            self::Critical => 'red',
        };
    }
}
