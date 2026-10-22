<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\InspectionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InspectionItem */
class InspectionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cable_segment_id' => $this->cable_segment_id,
            'segment' => $this->whenLoaded('segment', fn () => [
                'id' => $this->segment->id,
                'code' => $this->segment->code,
                'name' => $this->segment->name,
                'location_description' => $this->segment->location_description,
                'line_name' => $this->segment->relationLoaded('cableLine') ? $this->segment->cableLine?->name : null,
            ]),
            'inspected_on' => $this->inspected_on?->toDateString(),
            'recorded_length_m' => $this->recorded_length_m,
            'measured_length_m' => $this->measured_length_m,
            'length_difference_m' => $this->length_difference_m,
            'exceeds_tolerance' => $this->exceedsLengthTolerance(),
            'difference_reason' => $this->difference_reason,
            'technical_condition' => $this->technical_condition?->value,
            'usage_status' => $this->usage_status?->value,
            'usage_evidence' => $this->usage_evidence,
            'risk_level' => $this->risk_level?->value,
            'complexity_flags' => $this->complexity_flags ?? [],
            'laying_type' => $this->laying_type?->value,
            'depth_m' => $this->depth_m,
            'findings' => $this->findings,
            'is_recorded' => $this->isRecorded(),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
        ];
    }
}
