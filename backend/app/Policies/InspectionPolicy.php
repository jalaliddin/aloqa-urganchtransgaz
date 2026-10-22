<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Inspection;
use App\Models\User;

class InspectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inspections.view');
    }

    public function view(User $user, Inspection $inspection): bool
    {
        return $user->can('inspections.view') && $inspection->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('inspections.manage');
    }

    public function manage(User $user, Inspection $inspection): bool
    {
        return $user->can('inspections.manage') && $inspection->isVisibleTo($user);
    }

    public function decide(User $user, Inspection $inspection): bool
    {
        return $user->can('inspections.approve') && $inspection->isVisibleTo($user);
    }

    public function attachDocuments(User $user, Inspection $inspection): bool
    {
        return $user->can('documents.upload')
            && ($user->can('inspections.manage') || $user->can('inspections.approve'))
            && $inspection->isVisibleTo($user);
    }
}
