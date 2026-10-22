<?php

declare(strict_types=1);

namespace App\Enums;

enum WarehouseTransactionStatus: string
{
    use HasOptions;

    case Posted = 'posted';
    case PendingApproval = 'pending_approval';
    case Rejected = 'rejected';

    public function color(): string
    {
        return match ($this) {
            self::Posted => 'teal',
            self::PendingApproval => 'orange',
            self::Rejected => 'red',
        };
    }
}
