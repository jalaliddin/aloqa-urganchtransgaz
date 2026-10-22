<?php

declare(strict_types=1);

namespace App\Enums;

enum UsageStatus: string
{
    use HasOptions;

    case InUse = 'in_use';
    case PartiallyUsed = 'partially_used';
    case NotInUse = 'not_in_use';
    case Unknown = 'unknown';

    public function color(): string
    {
        return match ($this) {
            self::InUse => 'teal',
            self::PartiallyUsed => 'yellow',
            self::NotInUse => 'gray',
            self::Unknown => 'grape',
        };
    }
}
