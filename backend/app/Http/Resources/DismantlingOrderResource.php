<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DismantlingOrder;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DismantlingOrder */
class DismantlingOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->short_name ?? $this->organization?->name),
            'cable_line_id' => $this->cable_line_id,
            'line' => $this->whenLoaded('cableLine', fn () => [
                'id' => $this->cableLine->id,
                'registry_number' => $this->cableLine->registry_number,
                'name' => $this->cableLine->name,
            ]),
            'work_plan_number' => $this->work_plan_number,
            'order_number' => $this->order_number,
            'order_date' => $this->order_date?->toDateString(),
            'brigade_name' => $this->brigade_name,
            'work_supervisor_id' => $this->work_supervisor_id,
            'work_supervisor' => $this->whenLoaded('workSupervisor', fn () => $this->workSupervisor?->name),
            'work_supervisor_name' => $this->work_supervisor_name,
            'planned_start' => $this->planned_start?->toDateString(),
            'planned_end' => $this->planned_end?->toDateString(),
            'actual_start' => $this->actual_start?->toDateString(),
            'actual_end' => $this->actual_end?->toDateString(),
            'planned_length_m' => $this->planned_length_m,
            'actual_length_m' => $this->actual_length_m,
            'difference_m' => Decimal::diff($this->actual_length_m, $this->planned_length_m),
            'percent' => Decimal::percent($this->actual_length_m, $this->planned_length_m),
            'status' => $this->status->value,
            'allowed_transitions' => array_map(fn ($s) => $s->value, $this->status->allowedTransitions()),
            'is_complex' => $this->is_complex,
            'is_demo' => $this->is_demo,
            'suspension_reason' => $this->suspension_reason,
            'notes' => $this->notes,
            'segments' => $this->whenLoaded('segments', fn () => $this->segments->map(fn ($s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'planned_length_m' => $s->pivot->planned_length_m,
                'risk_level' => $s->risk_level->value,
                'complexity_flags' => $s->complexity_flags ?? [],
                'inspection_status' => $s->inspection_status->value,
                'dismantling_status' => $s->dismantling_status->value,
                'has_geometry' => $s->hasGeometry(),
            ])),
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->sortByDesc('decided_at')->values()->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type->value,
                'decision' => $a->decision->value,
                'reference_number' => $a->reference_number,
                'reference_date' => $a->reference_date?->toDateString(),
                'external_party' => $a->external_party,
                'comment' => $a->comment,
                'document_id' => $a->document_id,
                'decided_by' => $a->decider?->name,
                'decided_at' => $a->decided_at?->toIso8601String(),
                'revoked_at' => $a->revoked_at?->toIso8601String(),
                'revoked_by' => $a->revoker?->name,
                'revoke_reason' => $a->revoke_reason,
            ])),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type->value,
                'from_status' => $e->from_status?->value,
                'to_status' => $e->to_status?->value,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'segment_code' => $e->segment?->code,
                'extracted_length_m' => $e->extracted_length_m,
                'description' => $e->description,
                'user' => $e->user?->name,
            ])),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'material_receipts' => $this->whenLoaded('materialReceipts', fn () => $this->materialReceipts->map(fn ($r) => [
                'id' => $r->id, 'number' => $r->number, 'receipt_date' => $r->receipt_date?->toDateString(), 'status' => $r->status->value,
            ])),
            'checks' => $this->when(isset($this->checks), fn () => $this->checks),
            'act_masses' => [
                'aluminium_kg' => $this->act_aluminium_kg,
                'lead_kg' => $this->act_lead_kg,
                'copper_kg' => $this->act_copper_kg,
                'document_id' => $this->act_masses_document_id,
                'recorded_by' => $this->whenLoaded('actMassesRecordedBy', fn () => $this->actMassesRecordedBy?->name),
                'recorded_at' => $this->act_masses_recorded_at?->toIso8601String(),
            ],
            'metal_balance' => $this->when(isset($this->metal_balance), fn () => $this->metal_balance),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_by_id' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => $this->when($user !== null, fn () => [
                'update' => $user->can('update', $this->resource),
                'transition' => $user->can('transition', $this->resource),
                'authorize' => $user->can('dismantling.authorize'),
                'record_safety' => $user->can('approvals.record_safety'),
                'record_specialist' => $user->can('approvals.record_specialist'),
                'record_event' => $user->can('recordEvent', $this->resource),
                'attach' => $user->can('attachDocuments', $this->resource),
            ]),
        ];
    }
}
