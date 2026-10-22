<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CompositionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\MetalCompositionRequest;
use App\Http\Resources\CableMetalCompositionResource;
use App\Models\CableMetalComposition;
use App\Services\MetalCompositionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class MetalCompositionController extends Controller
{
    public function __construct(private readonly MetalCompositionService $compositions) {}

    /** Versions of a scope, or all pending values the user may decide on (?status=pending). */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('cable_lines.view'), 403);
        $filters = $request->validate([
            'scope_type' => ['nullable', Rule::in(array_keys(MetalCompositionService::SCOPES))],
            'scope_id' => ['nullable', 'integer', 'required_with:scope_type'],
            'status' => ['nullable', Rule::enum(CompositionStatus::class)],
        ]);

        $items = CableMetalComposition::query()
            ->with(['scope', 'enteredBy:id,name', 'decidedBy:id,name', 'document:id,title'])
            ->when($filters['scope_type'] ?? null, fn ($q, $type) => $q->where('scope_type', $type)->where('scope_id', $filters['scope_id']))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->limit(500)
            ->get()
            ->filter(fn (CableMetalComposition $c) => $request->user()->can('view', $c))
            ->values();

        return CableMetalCompositionResource::collection($items);
    }

    public function store(MetalCompositionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $scope = $this->compositions->resolveScope($data['scope_type'], (int) $data['scope_id']);
        $composition = $this->compositions->submit($scope, $data, $request->user());

        return (new CableMetalCompositionResource($composition->load('scope', 'enteredBy:id,name')))->response()->setStatusCode(201);
    }

    public function approve(Request $request, CableMetalComposition $metalComposition): CableMetalCompositionResource
    {
        $this->authorize('decide', $metalComposition);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return new CableMetalCompositionResource(
            $this->compositions->approve($metalComposition, $request->user(), $data['comment'] ?? null)->load('scope', 'enteredBy:id,name', 'decidedBy:id,name'),
        );
    }

    public function reject(Request $request, CableMetalComposition $metalComposition): CableMetalCompositionResource
    {
        $this->authorize('decide', $metalComposition);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return new CableMetalCompositionResource(
            $this->compositions->reject($metalComposition, $request->user(), $data['reason'])->load('scope', 'enteredBy:id,name', 'decidedBy:id,name'),
        );
    }
}
