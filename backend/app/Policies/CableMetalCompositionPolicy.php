<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CableMetalComposition;
use App\Models\CableType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CableMetalCompositionPolicy
{
    public function view(User $user, CableMetalComposition $composition): bool
    {
        return $user->can('cable_lines.view') && $this->scopeVisible($user, $composition->scope);
    }

    /** Type-level values need the manage right; line/segment overrides also need edit rights on that record. */
    public function submitFor(User $user, Model $scope): bool
    {
        if (! $user->can('metal_composition.manage')) {
            return false;
        }

        return $scope instanceof CableType || $user->can('update', $scope);
    }

    public function decide(User $user, CableMetalComposition $composition): bool
    {
        return $user->can('metal_composition.approve') && $this->scopeVisible($user, $composition->scope);
    }

    private function scopeVisible(User $user, ?Model $scope): bool
    {
        return $scope instanceof CableType || ($scope !== null && $user->canAccessOrganization($scope->getAttribute('organization_id')));
    }
}
