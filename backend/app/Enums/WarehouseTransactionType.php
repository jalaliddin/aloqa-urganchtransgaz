<?php

declare(strict_types=1);

namespace App\Enums;

enum WarehouseTransactionType: string
{
    use HasOptions;

    case Receipt = 'receipt';
    case Transfer = 'transfer';
    case Sale = 'sale';
    case Disposal = 'disposal';
    case Adjustment = 'adjustment';
}
