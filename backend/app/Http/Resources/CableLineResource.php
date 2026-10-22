<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CableLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CableLine */
class CableLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $financial = $user?->can('cable_lines.view_financial') ?? false;

        return [
            'id' => $this->id,
            'registry_number' => $this->registry_number,
            'cable_code' => $this->cable_code,
            'name' => $this->name,
            'cable_type' => $this->cable_type,
            'brand' => $this->brand,
            'core_count' => $this->core_count,
            'laid_year' => $this->laid_year,
            'laid_year_precision' => $this->laid_year_precision->value,
            'laid_year_label' => $this->laidYearLabel(),
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->brief($this->organization)),
            'balance_holder_id' => $this->balance_holder_id,
            'balance_holder' => $this->whenLoaded('balanceHolder', fn () => $this->brief($this->balanceHolder)),
            'gas_pipeline_asset_id' => $this->gas_pipeline_asset_id,
            'gas_pipeline' => $this->whenLoaded('gasPipelineAsset', fn () => $this->gasPipelineAsset ? [
                'id' => $this->gasPipelineAsset->id,
                'code' => $this->gasPipelineAsset->code,
                'name' => $this->gasPipelineAsset->name,
            ] : null),
            'cable_type_id' => $this->cable_type_id,
            'cable_type_ref' => $this->whenLoaded('cableType', fn () => $this->cableType ? [
                'id' => $this->cableType->id,
                'code' => $this->cableType->code,
                'name' => $this->cableType->name,
            ] : null),
            'object_name' => $this->object_name,
            'start_point' => $this->start_point,
            'end_point' => $this->end_point,
            'design_length_m' => $this->design_length_m,
            'measured_length_m' => $this->measured_length_m,
            $this->mergeWhen($financial, fn () => [
                'inventory_number' => $this->inventory_number,
                'initial_cost' => $this->initial_cost,
                'accumulated_depreciation' => $this->accumulated_depreciation,
                'residual_value' => $this->residual_value,
            ]),
            'technical_condition' => $this->technical_condition->value,
            'usage_status' => $this->usage_status->value,
            'risk_level' => $this->risk_level->value,
            'inspection_status' => $this->inspection_status->value,
            'dismantling_status' => $this->dismantling_status->value,
            'has_geometry' => $this->has_geometry,
            'is_complex' => $this->is_complex,
            'is_demo' => $this->is_demo,
            'notes' => $this->notes,
            'segments_count' => $this->whenCounted('segments'),
            'segments' => CableSegmentResource::collection($this->whenLoaded('segments')),
            'documents_missing' => $this->when(isset($this->documents_missing), fn () => $this->documents_missing),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => $this->when($user !== null, fn () => [
                'update' => $user->can('update', $this->resource),
                'update_financial' => $user->can('updateFinancial', $this->resource),
                'delete' => $user->can('delete', $this->resource),
                'edit_geometry' => $user->can('editGeometry', $this->resource),
            ]),
        ];
    }

    private function brief(?object $organization): ?array
    {
        return $organization ? ['id' => $organization->id, 'name' => $organization->name, 'short_name' => $organization->short_name] : null;
    }
}
