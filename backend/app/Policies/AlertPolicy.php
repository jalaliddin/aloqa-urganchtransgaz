<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

class AlertPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('alerts.view');
    }

    public function view(User $user, Alert $alert): bool
    {
        return $user->can('alerts.view') && $user->canAccessOrganization($alert->organization_id);
    }

    public function resolve(User $user, Alert $alert): bool
    {
        return $user->can('alerts.resolve') && $user->canAccessOrganization($alert->organization_id);
    }
}
