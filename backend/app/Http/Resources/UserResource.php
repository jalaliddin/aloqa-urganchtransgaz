<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'position' => $this->position,
            'is_active' => $this->is_active,
            'locale' => $this->locale,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization ? [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
                'short_name' => $this->organization->short_name,
            ] : null),
            'organization_id' => $this->organization_id,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'name' => $role->name,
                'label' => Rbac::roleLabel($role->name),
            ])),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
