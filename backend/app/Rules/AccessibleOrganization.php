<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The organisation exists, is active and lies within the user's data scope. */
final class AccessibleOrganization implements ValidationRule
{
    public function __construct(private readonly ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = Organization::query()->whereKey($value)->where('is_active', true)->exists();

        if (! $exists || $this->user === null || ! $this->user->canAccessOrganization((int) $value)) {
            $fail("Tanlangan tashkilot mavjud emas yoki unga ruxsatingiz yo'q.");
        }
    }
}
