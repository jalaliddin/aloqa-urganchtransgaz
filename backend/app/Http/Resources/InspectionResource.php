<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Inspection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Inspection */
class InspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->short_name ?? $this->organization?->name),
            'title' => $this->title,
            'description' => $this->description,
            'planned_start' => $this->planned_start?->toDateString(),
            'planned_end' => $this->planned_end?->toDateString(),
            'status' => $this->status->value,
            'is_demo' => $this->is_demo,
            'items_count' => $this->whenCounted('items'),
            'recorded_items_count' => $this->when(isset($this->recorded_items_count), fn () => (int) $this->recorded_items_count),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($m) => [
                'id' => $m->id, 'user_id' => $m->user_id, 'full_name' => $m->full_name, 'position' => $m->position, 'role' => $m->role->value,
            ])),
            'items' => InspectionItemResource::collection($this->whenLoaded('items')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'submitted_by' => $this->whenLoaded('submitter', fn () => $this->submitter?->name),
            'submitted_by_id' => $this->submitted_by,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider?->name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => $this->when($user !== null, fn () => [
                'manage' => $user->can('manage', $this->resource),
                'decide' => $user->can('decide', $this->resource) && $this->submitted_by !== $user->id,
                'attach' => $user->can('attachDocuments', $this->resource),
            ]),
        ];
    }
}
