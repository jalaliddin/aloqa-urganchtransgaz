<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CompositionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CableTypeResource;
use App\Models\CableType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CableTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CableType::class);

        $types = CableType::query()
            ->with(['currentComposition.enteredBy:id,name', 'currentComposition.decidedBy:id,name', 'currentComposition.document:id,title'])
            ->withCount(['lines', 'compositions as pending_count' => fn ($q) => $q->where('status', CompositionStatus::Pending->value)])
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return CableTypeResource::collection($types);
    }

    public function show(CableType $cableType): CableTypeResource
    {
        $this->authorize('view', $cableType);

        return new CableTypeResource($cableType->load([
            'currentComposition', 'compositions.enteredBy:id,name', 'compositions.decidedBy:id,name', 'compositions.document:id,title',
        ])->loadCount('lines'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CableType::class);
        $type = CableType::query()->create([...$this->validated($request), 'created_by' => $request->user()->id]);

        return (new CableTypeResource($type))->response()->setStatusCode(201);
    }

    public function update(Request $request, CableType $cableType): CableTypeResource
    {
        $this->authorize('update', $cableType);
        $cableType->update($this->validated($request, $cableType));

        return new CableTypeResource($cableType);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CableType $type = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('cable_types', 'code')->ignore($type?->id)],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'],
            'core_count' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);
    }
}
