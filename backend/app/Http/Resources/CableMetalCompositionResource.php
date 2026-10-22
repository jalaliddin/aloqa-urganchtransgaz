<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CableLine;
use App\Models\CableMetalComposition;
use App\Models\CableSegment;
use App\Models\CableType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CableMetalComposition */
class CableMetalCompositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $scope = $this->relationLoaded('scope') ? $this->scope : null;
        $user = $request->user();

        return [
            'id' => $this->id,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'scope_label' => match (true) {
                $scope instanceof CableType => $scope->name,
                $scope instanceof CableLine => $scope->registry_number,
                $scope instanceof CableSegment => $scope->code,
                default => null,
            },
            // null = unknown, "0.000000" = verified zero — kept distinct on purpose
            'aluminium_kg_per_m' => $this->aluminium_kg_per_m,
            'lead_kg_per_m' => $this->lead_kg_per_m,
            'copper_kg_per_m' => $this->copper_kg_per_m,
            'source_type' => $this->source_type->value,
            'source_reference' => $this->source_reference,
            'document_id' => $this->document_id,
            'document_title' => $this->whenLoaded('document', fn () => $this->document?->title),
            'note' => $this->note,
            'status' => $this->status->value,
            'entered_by' => $this->whenLoaded('enteredBy', fn () => $this->enteredBy?->name),
            'entered_by_id' => $this->entered_by,
            'entered_at' => $this->entered_at?->toIso8601String(),
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'is_demo' => $this->is_demo,
            'can_decide' => $user !== null
                && $this->status->value === 'pending'
                && $this->entered_by !== $user->id
                && $user->can('decide', $this->resource),
        ];
    }
}
