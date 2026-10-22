<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MaterialReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaterialReceipt */
class MaterialReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'receipt_date' => $this->receipt_date?->toDateString(),
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->short_name ?? $this->organization?->name),
            'dismantling_order_id' => $this->dismantling_order_id,
            'dismantling_order' => $this->whenLoaded('dismantlingOrder', fn () => [
                'id' => $this->dismantlingOrder->id,
                'number' => $this->dismantlingOrder->number,
                'status' => $this->dismantlingOrder->status->value,
                'actual_length_m' => $this->dismantlingOrder->actual_length_m,
            ]),
            'warehouse_name' => $this->warehouse_name,
            'handed_over_by' => $this->whenLoaded('handedOverBy', fn () => $this->handedOverBy?->name),
            'handed_over_by_id' => $this->handed_over_by,
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->name),
            'received_by_id' => $this->received_by,
            'status' => $this->status->value,
            'discrepancy_explanation' => $this->discrepancy_explanation,
            'discrepancy_decided_by' => $this->whenLoaded('discrepancyDecider', fn () => $this->discrepancyDecider?->name),
            'discrepancy_decided_at' => $this->discrepancy_decided_at?->toIso8601String(),
            'notes' => $this->notes,
            'is_demo' => $this->is_demo,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'material_type' => $item->material_type->value,
                'description' => $item->description,
                'unit' => $item->unit->value,
                'handed_over_quantity' => $item->handed_over_quantity,
                'accepted_quantity' => $item->accepted_quantity,
                'difference' => $item->difference(),
                'balance' => $item->balance(),
                'measured_on' => $item->measured_on?->toDateString(),
                'storage_location' => $item->storage_location,
                'segment_code' => $item->segment?->code,
                'transactions' => $item->relationLoaded('transactions') ? $item->transactions->map(fn ($t) => [
                    'id' => $t->id,
                    'type' => $t->type->value,
                    'quantity' => $t->quantity,
                    'status' => $t->status->value,
                    'document_number' => $t->document_number,
                    'document_date' => $t->document_date?->toDateString(),
                    'counterparty' => $t->counterparty,
                    'reason' => $t->reason,
                    'created_by' => $t->creator?->name,
                    'created_by_id' => $t->created_by,
                    'decided_by' => $t->decider?->name,
                    'decision_comment' => $t->decision_comment,
                    'created_at' => $t->created_at?->toIso8601String(),
                ]) : [],
            ])),
            'discrepancies' => $this->when(isset($this->discrepancies), fn () => $this->discrepancies),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => $this->when($user !== null, fn () => [
                'decide' => $user->can('decide', $this->resource) && ! in_array($user->id, [$this->received_by, $this->handed_over_by], true),
                'move' => $user->can('move', $this->resource),
                'attach' => $user->can('attachDocuments', $this->resource),
                'approve_adjustment' => $user->can('warehouse.approve_adjustment'),
            ]),
        ];
    }
}
