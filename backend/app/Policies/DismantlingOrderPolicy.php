<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DismantlingOrder;
use App\Models\User;

class DismantlingOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('dismantling.view');
    }

    public function view(User $user, DismantlingOrder $order): bool
    {
        return $user->can('dismantling.view') && $order->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('dismantling.manage');
    }

    public function update(User $user, DismantlingOrder $order): bool
    {
        return $user->can('dismantling.manage') && $order->isVisibleTo($user);
    }

    /** Fine-grained checks (authorize-only targets) happen in DismantlingWorkflow. */
    public function transition(User $user, DismantlingOrder $order): bool
    {
        return ($user->can('dismantling.manage') || $user->can('dismantling.authorize')) && $order->isVisibleTo($user);
    }

    public function recordApproval(User $user, DismantlingOrder $order): bool
    {
        return ($user->can('approvals.record_safety') || $user->can('approvals.record_specialist') || $user->can('dismantling.authorize'))
            && $order->isVisibleTo($user);
    }

    public function recordEvent(User $user, DismantlingOrder $order): bool
    {
        return $user->can('dismantling.manage') && $order->isVisibleTo($user);
    }

    public function attachDocuments(User $user, DismantlingOrder $order): bool
    {
        return $user->can('documents.upload')
            && ($user->can('dismantling.manage') || $this->recordApproval($user, $order))
            && $order->isVisibleTo($user);
    }
}
