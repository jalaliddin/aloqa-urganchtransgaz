<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CableSegment;
use App\Models\User;

class CableSegmentPolicy
{
    public function view(User $user, CableSegment $segment): bool
    {
        return $user->can('cable_lines.view') && $segment->isVisibleTo($user);
    }

    public function update(User $user, CableSegment $segment): bool
    {
        return $user->can('cable_lines.update') && $segment->isVisibleTo($user);
    }

    public function delete(User $user, CableSegment $segment): bool
    {
        return $user->can('cable_lines.update') && $segment->isVisibleTo($user);
    }

    public function editGeometry(User $user, CableSegment $segment): bool
    {
        return $user->can('cable_lines.edit_geometry') && $segment->isVisibleTo($user);
    }
}
