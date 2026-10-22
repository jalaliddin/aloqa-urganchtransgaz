<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InspectionItem;
use App\Models\User;

class InspectionItemPolicy
{
    public function attachDocuments(User $user, InspectionItem $item): bool
    {
        return (new InspectionPolicy)->attachDocuments($user, $item->inspection);
    }
}
