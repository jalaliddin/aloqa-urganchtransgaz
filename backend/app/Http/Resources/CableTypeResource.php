<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CableType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CableType */
class CableTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'brand' => $this->brand,
            'core_count' => $this->core_count,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'is_demo' => $this->is_demo,
            'lines_count' => $this->whenCounted('lines'),
            'pending_count' => $this->when(isset($this->pending_count), fn () => (int) $this->pending_count),
            'current_composition' => new CableMetalCompositionResource($this->whenLoaded('currentComposition')),
            'compositions' => CableMetalCompositionResource::collection($this->whenLoaded('compositions')),
        ];
    }
}
