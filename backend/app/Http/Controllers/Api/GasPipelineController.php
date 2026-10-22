<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GasPipelineAsset;
use App\Rules\AccessibleOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GasPipelineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = GasPipelineAsset::query()->visibleTo($request->user())
            ->with('organization:id,name,short_name')
            ->when($request->integer('organization_id'), fn ($q, $id) => $q->where('organization_id', $id))
            ->orderBy('name')
            ->get()
            ->map(fn (GasPipelineAsset $p) => [
                'id' => $p->id,
                'organization_id' => $p->organization_id,
                'organization' => $p->organization?->short_name ?? $p->organization?->name,
                'code' => $p->code,
                'name' => $p->name,
                'diameter_mm' => $p->diameter_mm,
                'length_m' => $p->length_m,
                'description' => $p->description,
                'is_active' => $p->is_active,
                'is_demo' => $p->is_demo,
            ]);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $pipeline = GasPipelineAsset::query()->create($this->validated($request));

        return response()->json(['data' => $pipeline], 201);
    }

    public function update(Request $request, GasPipelineAsset $gasPipeline): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $gasPipeline->update($this->validated($request, $gasPipeline));

        return response()->json(['data' => $gasPipeline]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?GasPipelineAsset $pipeline = null): array
    {
        return $request->validate([
            'organization_id' => ['required', 'integer', new AccessibleOrganization($request->user())],
            'code' => ['required', 'string', 'max:32', Rule::unique('gas_pipeline_assets', 'code')->ignore($pipeline?->id)],
            'name' => ['required', 'string', 'max:255'],
            'diameter_mm' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'length_m' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);
    }
}
