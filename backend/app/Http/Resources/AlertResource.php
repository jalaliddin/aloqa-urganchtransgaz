<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Alert */
class AlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->short_name ?? $this->organization?->name),
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'status' => $this->status->value,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data,
            'triggered_by' => $this->whenLoaded('triggeredBy', fn () => $this->triggeredBy?->name),
            'resolved_by' => $this->whenLoaded('resolvedBy', fn () => $this->resolvedBy?->name),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_note' => $this->resolution_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can_resolve' => $request->user()?->can('resolve', $this->resource) ?? false,
        ];
    }
}
