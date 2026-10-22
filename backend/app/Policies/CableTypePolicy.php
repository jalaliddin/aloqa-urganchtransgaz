<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CableType;
use App\Models\User;

/** Cable types are shared reference data (not organisation-bound). */
class CableTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cable_lines.view');
    }

    public function view(User $user, CableType $type): bool
    {
        return $user->can('cable_lines.view');
    }

    public function create(User $user): bool
    {
        return $user->can('metal_composition.manage');
    }

    public function update(User $user, CableType $type): bool
    {
        return $user->can('metal_composition.manage');
    }

    public function attachDocuments(User $user, CableType $type): bool
    {
        return $user->can('documents.upload') && $user->can('metal_composition.manage');
    }
}
