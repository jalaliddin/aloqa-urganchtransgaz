<?php

declare(strict_types=1);

namespace App\Enums;

enum ApprovalDecision: string
{
    use HasOptions;

    case Approved = 'approved';
    case Rejected = 'rejected';

    public function color(): string
    {
        return $this === self::Approved ? 'teal' : 'red';
    }
}
