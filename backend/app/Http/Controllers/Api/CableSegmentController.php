<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\GeometrySource;
use App\Http\Controllers\Controller;
use App\Http\Requests\CableSegmentRequest;
use App\Http\Resources\CableSegmentResource;
use App\Models\CableLine;
use App\Models\CableSegment;
use App\Services\CableRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CableSegmentController extends Controller
{
    public function __construct(private readonly CableRegistryService $registry) {}

    /** Segment picker for workflows (inspection / dismantling), scoped to the user's organisations. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CableLine::class);
        $filters = $request->validate([
            'organization_id' => ['nullable', 'integer'],
            'cable_line_id' => ['nullable', 'integer'],
            'inspection_status' => ['nullable', 'array'],
            'inspection_status.*' => ['string', 'max:32'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:500'],
        ]);

        $segments = CableSegment::query()->visibleTo($request->user())
            ->with('cableLine:id,registry_number,name')
            ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['cable_line_id'] ?? null, fn ($q, $id) => $q->where('cable_line_id', $id))
            ->when($filters['inspection_status'] ?? null, fn ($q, $v) => $q->whereIn('inspection_status', $v))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")->orWhere('location_description', 'like', "%{$term}%")
                ->orWhereHas('cableLine', fn ($l) => $l->where('name', 'like', "%{$term}%")->orWhere('registry_number', 'like', "%{$term}%"))))
            ->orderBy('code')
            ->paginate($filters['per_page'] ?? 100);

        return CableSegmentResource::collection($segments)->response();
    }

    public function store(CableSegmentRequest $request, CableLine $cableLine): JsonResponse
    {
        $this->authorize('update', $cableLine);
        $data = $request->validated();
        if (! empty($data['geometry'])) {
            $this->authorize('editGeometry', $cableLine);
        }

        $segment = $this->registry->createSegment($cableLine, $data, $request->user());

        return (new CableSegmentResource($segment))->response()->setStatusCode(201);
    }

    public function show(CableSegment $cableSegment): CableSegmentResource
    {
        $this->authorize('view', $cableSegment);

        return new CableSegmentResource($cableSegment->load('cableLine:id,registry_number,name'));
    }

    public function update(CableSegmentRequest $request, CableSegment $cableSegment): CableSegmentResource
    {
        $this->authorize('update', $cableSegment);

        return new CableSegmentResource($this->registry->updateSegment($cableSegment, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, CableSegment $cableSegment): JsonResponse
    {
        $this->authorize('delete', $cableSegment);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->registry->deleteSegment($cableSegment, $data['reason'], $request->user());

        return response()->json(['message' => "Uchastka o'chirildi."]);
    }

    public function updateGeometry(Request $request, CableSegment $cableSegment): CableSegmentResource
    {
        $this->authorize('editGeometry', $cableSegment);
        $data = $request->validate([
            'geometry' => ['required', 'array'],
            'source' => ['required', Rule::enum(GeometrySource::class)->except([GeometrySource::Demo])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $segment = $this->registry->updateGeometry(
            $cableSegment, $data['geometry'], GeometrySource::from($data['source']), $data['note'] ?? null, $request->user(),
        );

        return new CableSegmentResource($segment->load('cableLine:id,registry_number,name'));
    }

    public function clearGeometry(Request $request, CableSegment $cableSegment): CableSegmentResource
    {
        $this->authorize('editGeometry', $cableSegment);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return new CableSegmentResource($this->registry->clearGeometry($cableSegment, $data['reason'], $request->user()));
    }
}
