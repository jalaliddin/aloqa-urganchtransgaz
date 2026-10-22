<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'type' => $this->type->value,
            'code' => $this->code,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'address' => $this->address,
            'is_active' => $this->is_active,
            'is_demo' => $this->is_demo,
        ];
    }
}
