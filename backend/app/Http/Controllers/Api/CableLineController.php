<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Filters\CableFilter;
use App\Http\Requests\CableLineRequest;
use App\Http\Resources\CableLineResource;
use App\Http\Resources\CableMetalCompositionResource;
use App\Models\CableLine;
use App\Models\CableMetalComposition;
use App\Models\DismantlingOrder;
use App\Models\InspectionItem;
use App\Models\MetalMassCalculation;
use App\Services\CableRegistryService;
use App\Services\MetalMassCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

class CableLineController extends Controller
{
    public function __construct(private readonly CableRegistryService $registry) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CableLine::class);
        $filters = CableFilter::fromRequest($request);

        $query = CableLine::query()->visibleTo($request->user())
            ->with(['organization:id,name,short_name', 'balanceHolder:id,name,short_name', 'gasPipelineAsset:id,code,name', 'cableType:id,code,name,brand,is_active'])
            ->withCount('segments');

        CableFilter::applyToLines($query, $filters)
            ->orderBy($filters['sort'] ?? 'registry_number', $filters['direction'] ?? 'asc')
            ->orderBy('id');

        return CableLineResource::collection($query->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function store(CableLineRequest $request): JsonResponse
    {
        $this->authorize('create', CableLine::class);
        $line = $this->registry->createLine($request->validated(), $request->user());

        return (new CableLineResource($this->loadDetails($line)))->response()->setStatusCode(201);
    }

    public function show(CableLine $cableLine): CableLineResource
    {
        $this->authorize('view', $cableLine);

        $line = $this->loadDetails($cableLine);
        $line->setAttribute('documents_missing', $this->missingDocuments($line));

        return new CableLineResource($line);
    }

    public function update(CableLineRequest $request, CableLine $cableLine): CableLineResource
    {
        $data = $request->validated();

        if ($request->user()->can('update', $cableLine)) {
            $line = $this->registry->updateLine($cableLine, $data, $request->user());
        } else {
            // Accounting may change only the financial fields
            $this->authorize('updateFinancial', $cableLine);
            abort_if(array_diff_key($data, array_flip(CableLine::FINANCIAL_FIELDS)) !== [], 403);
            $line = $this->registry->updateLine($cableLine, Arr::only($data, CableLine::FINANCIAL_FIELDS), $request->user());
        }

        return new CableLineResource($this->loadDetails($line));
    }

    public function destroy(Request $request, CableLine $cableLine): JsonResponse
    {
        $this->authorize('delete', $cableLine);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->registry->deleteLine($cableLine, $data['reason'], $request->user());

        return response()->json(['message' => "Liniya o'chirildi."]);
    }

    /** Combined inspection and dismantling history of the line ("Demontaj tarixi"). */
    public function timeline(Request $request, CableLine $cableLine): JsonResponse
    {
        $this->authorize('view', $cableLine);
        $user = $request->user();
        $events = collect();

        if ($user->can('inspections.view')) {
            InspectionItem::query()
                ->whereIn('cable_segment_id', $cableLine->segments()->pluck('id'))
                ->with(['inspection:id,number,status,decided_at,submitted_at,created_at', 'segment:id,code'])
                ->get()
                ->each(fn (InspectionItem $item) => $events->push([
                    'kind' => 'inspection',
                    'date' => ($item->inspection->decided_at ?? $item->inspection->submitted_at ?? $item->inspection->created_at)?->toIso8601String(),
                    'reference' => $item->inspection->number,
                    'reference_id' => $item->inspection_id,
                    'status' => $item->inspection->status->value,
                    'segment' => $item->segment->code,
                    'details' => $item->measured_length_m !== null
                        ? "Hisobda {$item->recorded_length_m} m, aniqlangan {$item->measured_length_m} m"
                        : 'Natija kiritilmagan',
                ]));
        }

        if ($user->can('dismantling.view')) {
            DismantlingOrder::query()->where('cable_line_id', $cableLine->id)
                ->with(['events.user:id,name', 'events.segment:id,code'])
                ->get()
                ->each(fn (DismantlingOrder $order) => $order->events->each(fn ($event) => $events->push([
                    'kind' => 'dismantling',
                    'date' => $event->occurred_at?->toIso8601String(),
                    'reference' => $order->number,
                    'reference_id' => $order->id,
                    'event_type' => $event->type->value,
                    'status' => $event->to_status?->value,
                    'segment' => $event->segment?->code,
                    'details' => $event->description ?? ($event->extracted_length_m ? "Chiqarildi: {$event->extracted_length_m} m" : null),
                    'user' => $event->user?->name,
                ])));
        }

        return response()->json(['data' => $events->sortByDesc('date')->values()]);
    }

    /**
     * Non-ferrous metal content: effective kg/m per segment (segment → line → cable type),
     * theoretical masses with per-metal totals and completeness, versions and calculation history.
     */
    public function metalContent(CableLine $cableLine, MetalMassCalculator $calculator): JsonResponse
    {
        $this->authorize('view', $cableLine);
        $segmentIds = $cableLine->segments()->pluck('id');

        $versions = CableMetalComposition::query()
            ->with(['scope', 'enteredBy:id,name', 'decidedBy:id,name', 'document:id,title'])
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('scope_type', 'cable_line')->where('scope_id', $cableLine->id))
                ->orWhere(fn ($w) => $w->where('scope_type', 'cable_segment')->whereIn('scope_id', $segmentIds))
                ->when($cableLine->cable_type_id, fn ($o) => $o->orWhere(fn ($w) => $w->where('scope_type', 'cable_type')->where('scope_id', $cableLine->cable_type_id))))
            ->latest('id')
            ->get();

        $history = MetalMassCalculation::query()
            ->with('calculatedBy:id,name', 'subject')
            ->where('subject_type', 'cable_segment')
            ->whereIn('subject_id', $segmentIds)
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => [
            'cable_type' => $cableLine->cableType ? ['id' => $cableLine->cableType->id, 'name' => $cableLine->cableType->name, 'code' => $cableLine->cableType->code] : null,
            ...$calculator->forLine($cableLine),
            'versions' => CableMetalCompositionResource::collection($versions)->resolve(),
            'history' => $history->map(fn (MetalMassCalculation $c) => [
                'id' => $c->id,
                'segment_code' => $c->subject?->code,
                'trigger' => $c->trigger->value,
                'composition_id' => $c->composition_id,
                'length_m' => $c->length_m,
                'aluminium_kg' => $c->aluminium_kg,
                'lead_kg' => $c->lead_kg,
                'copper_kg' => $c->copper_kg,
                'is_complete' => $c->is_complete,
                'details' => $c->details,
                'calculated_by' => $c->calculatedBy?->name,
                'created_at' => $c->created_at?->toIso8601String(),
            ]),
            'can' => [
                'submit' => request()->user()->can('submitFor', [CableMetalComposition::class, $cableLine]),
            ],
        ]]);
    }

    private function loadDetails(CableLine $line): CableLine
    {
        return $line->load([
            'organization:id,name,short_name', 'balanceHolder:id,name,short_name', 'gasPipelineAsset:id,code,name',
            'cableType:id,code,name,brand,is_active', 'segments', 'creator:id,name', 'updater:id,name',
        ])->loadCount('segments');
    }

    /** @return list<string> */
    private function missingDocuments(CableLine $line): array
    {
        $present = $line->documents()->whereNull('voided_at')->pluck('type')->map(fn ($t) => $t->value)->all();

        return array_values(array_diff(config('aloqa.documents.required.cable_line', []), $present));
    }
}
