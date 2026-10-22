<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WarehouseTransaction;

class WarehouseTransactionPolicy
{
    public function decide(User $user, WarehouseTransaction $transaction): bool
    {
        return $user->can('warehouse.approve_adjustment') && $transaction->isVisibleTo($user);
    }

    public function attachDocuments(User $user, WarehouseTransaction $transaction): bool
    {
        return $user->can('documents.upload')
            && ($user->can('warehouse.move') || $user->can('warehouse.approve_adjustment'))
            && $transaction->isVisibleTo($user);
    }
}
