<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MaterialReceipt;
use App\Models\User;

class MaterialReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('materials.view');
    }

    public function view(User $user, MaterialReceipt $receipt): bool
    {
        return $user->can('materials.view') && $receipt->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('materials.receive');
    }

    public function decide(User $user, MaterialReceipt $receipt): bool
    {
        return $user->can('materials.approve_discrepancy') && $receipt->isVisibleTo($user);
    }

    public function move(User $user, MaterialReceipt $receipt): bool
    {
        return $user->can('warehouse.move') && $receipt->isVisibleTo($user);
    }

    public function attachDocuments(User $user, MaterialReceipt $receipt): bool
    {
        return $user->can('documents.upload')
            && ($user->can('materials.receive') || $user->can('materials.approve_discrepancy') || $user->can('warehouse.move'))
            && $receipt->isVisibleTo($user);
    }
}
