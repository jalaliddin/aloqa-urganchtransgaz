<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CableSegment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CableSegment */
class CableSegmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cable_line_id' => $this->cable_line_id,
            'organization_id' => $this->organization_id,
            'sequence' => $this->sequence,
            'code' => $this->code,
            'name' => $this->name,
            'start_point' => $this->start_point,
            'end_point' => $this->end_point,
            'location_description' => $this->location_description,
            'laying_type' => $this->laying_type->value,
            'depth_m' => $this->depth_m,
            'design_length_m' => $this->design_length_m,
            'measured_length_m' => $this->measured_length_m,
            'geometry_length_m' => $this->geometry_length_m,
            'geometry' => $this->geometry,
            'geometry_source' => $this->geometry_source?->value,
            'geometry_source_note' => $this->geometry_source_note,
            'geometry_updated_at' => $this->geometry_updated_at?->toIso8601String(),
            'technical_condition' => $this->technical_condition->value,
            'usage_status' => $this->usage_status->value,
            'risk_level' => $this->risk_level->value,
            'complexity_flags' => $this->complexity_flags ?? [],
            'is_complex' => $this->isComplex(),
            'inspection_status' => $this->inspection_status->value,
            'dismantling_status' => $this->dismantling_status->value,
            'notes' => $this->notes,
            'line' => $this->whenLoaded('cableLine', fn () => [
                'id' => $this->cableLine->id,
                'registry_number' => $this->cableLine->registry_number,
                'name' => $this->cableLine->name,
            ]),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
