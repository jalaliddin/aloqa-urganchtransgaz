<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CableLine;
use App\Models\User;

class CableLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cable_lines.view');
    }

    public function view(User $user, CableLine $line): bool
    {
        return $user->can('cable_lines.view') && $line->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('cable_lines.create');
    }

    public function update(User $user, CableLine $line): bool
    {
        return $user->can('cable_lines.update') && $line->isVisibleTo($user);
    }

    /** Financial-only edits (accounting) do not require general edit rights. */
    public function updateFinancial(User $user, CableLine $line): bool
    {
        return $user->can('cable_lines.update_financial') && $line->isVisibleTo($user);
    }

    public function delete(User $user, CableLine $line): bool
    {
        return $user->can('cable_lines.delete') && $line->isVisibleTo($user);
    }

    public function editGeometry(User $user, CableLine $line): bool
    {
        return $user->can('cable_lines.edit_geometry') && $line->isVisibleTo($user);
    }
}
