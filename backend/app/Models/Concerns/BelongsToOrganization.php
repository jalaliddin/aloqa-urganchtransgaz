<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Organisation-level data separation. Every list endpoint must go through
 * visibleTo(); policies use isVisibleTo() for single records.
 */
trait BelongsToOrganization
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = $user->visibleOrganizationIds();

        return $ids === null
            ? $query
            : $query->whereIn($this->qualifyColumn('organization_id'), $ids);
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->canAccessOrganization($this->organization_id);
    }
}
