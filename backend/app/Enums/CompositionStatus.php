<?php

declare(strict_types=1);

namespace App\Enums;

enum CompositionStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'orange',
            self::Approved => 'teal',
            self::Rejected => 'red',
            self::Superseded => 'gray',
        };
    }
}
