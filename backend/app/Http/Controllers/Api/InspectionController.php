<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ComplexityFlag;
use App\Enums\InspectionMemberRole;
use App\Enums\InspectionStatus;
use App\Enums\LayingType;
use App\Enums\RiskLevel;
use App\Enums\TechnicalCondition;
use App\Enums\UsageStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\InspectionItemResource;
use App\Http\Resources\InspectionResource;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Rules\AccessibleOrganization;
use App\Services\AuditLogger;
use App\Services\InspectionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class InspectionController extends Controller
{
    public function __construct(private readonly InspectionService $inspections) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Inspection::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InspectionStatus::class)],
            'organization_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = Inspection::query()->visibleTo($request->user())
            ->with('organization:id,name,short_name')
            ->withCount(['items', 'items as recorded_items_count' => fn ($q) => $q->whereNotNull('recorded_at')])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('number', 'like', "%{$term}%")->orWhere('title', 'like', "%{$term}%")))
            ->latest('id');

        return InspectionResource::collection($query->paginate($filters['per_page'] ?? 20)->withQueryString());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Inspection::class);
        $inspection = $this->inspections->create($this->validated($request, creating: true), $request->user());

        return (new InspectionResource($this->loadDetails($inspection)))->response()->setStatusCode(201);
    }

    public function show(Inspection $inspection): InspectionResource
    {
        $this->authorize('view', $inspection);

        return new InspectionResource($this->loadDetails($inspection));
    }

    public function update(Request $request, Inspection $inspection): InspectionResource
    {
        $this->authorize('manage', $inspection);
        $inspection = $this->inspections->update($inspection, $this->validated($request, creating: false), $request->user());

        return new InspectionResource($this->loadDetails($inspection));
    }

    public function start(Request $request, Inspection $inspection): InspectionResource
    {
        $this->authorize('manage', $inspection);

        return new InspectionResource($this->loadDetails($this->inspections->start($inspection, $request->user())));
    }

    public function submit(Request $request, Inspection $inspection): InspectionResource
    {
        $this->authorize('manage', $inspection);

        return new InspectionResource($this->loadDetails($this->inspections->submit($inspection, $request->user())));
    }

    public function approve(Request $request, Inspection $inspection): InspectionResource
    {
        $this->authorize('decide', $inspection);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return new InspectionResource($this->loadDetails($this->inspections->approve($inspection, $request->user(), $data['comment'] ?? null)));
    }

    public function reject(Request $request, Inspection $inspection): InspectionResource
    {
        $this->authorize('decide', $inspection);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return new InspectionResource($this->loadDetails($this->inspections->reject($inspection, $request->user(), $data['reason'])));
    }

    public function cancel(Request $request, Inspection $inspection): InspectionResource
    {
        $this->authorize('manage', $inspection);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return new InspectionResource($this->loadDetails($this->inspections->cancel($inspection, $request->user(), $data['reason'])));
    }

    public function recordItem(Request $request, Inspection $inspection, InspectionItem $item): InspectionItemResource
    {
        $this->authorize('manage', $inspection);
        $data = $request->validate([
            'inspected_on' => ['required', 'date', 'before_or_equal:today'],
            'measured_length_m' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'difference_reason' => ['nullable', 'string', 'max:2000'],
            'technical_condition' => ['required', Rule::enum(TechnicalCondition::class)],
            'usage_status' => ['required', Rule::enum(UsageStatus::class)],
            'usage_evidence' => ['nullable', 'required_if:usage_status,'.UsageStatus::NotInUse->value, 'string', 'max:2000'],
            'risk_level' => ['required', Rule::enum(RiskLevel::class)],
            'complexity_flags' => ['nullable', 'array'],
            'complexity_flags.*' => [Rule::enum(ComplexityFlag::class)],
            'laying_type' => ['nullable', Rule::enum(LayingType::class)],
            'depth_m' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'findings' => ['nullable', 'string', 'max:5000'],
        ], [
            'usage_evidence.required_if' => '"Aloqa tizimida ishlatilmaydi" xulosasi uchun dalil (asos) kiritilishi shart.',
        ]);

        $item = $this->inspections->recordItem($item, $data, $request->user());

        return new InspectionItemResource($item->load('segment', 'documents'));
    }

    public function protocol(Inspection $inspection, AuditLogger $audit): Response
    {
        $this->authorize('view', $inspection);
        $inspection = $this->loadDetails($inspection);
        $audit->log('pdf_generated', $inspection, null, ['document' => 'inspection_protocol']);

        return Pdf::loadView('pdf.inspection-protocol', ['inspection' => $inspection])
            ->setPaper('a4', 'landscape')
            ->download("inventarizatsiya-{$inspection->number}.pdf");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'organization_id' => [$creating ? 'required' : 'prohibited', 'integer', new AccessibleOrganization($request->user())],
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'planned_start' => ['nullable', 'date'],
            'planned_end' => ['nullable', 'date', 'after_or_equal:planned_start'],
            'segment_ids' => [$required, 'array', 'min:1', 'max:500'],
            'segment_ids.*' => ['integer', 'distinct'],
            'members' => [$required, 'array', 'min:1', 'max:20'],
            'members.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'members.*.full_name' => ['required', 'string', 'max:255'],
            'members.*.position' => ['nullable', 'string', 'max:255'],
            'members.*.role' => ['required', Rule::enum(InspectionMemberRole::class)],
        ]);
    }

    private function loadDetails(Inspection $inspection): Inspection
    {
        return $inspection->load([
            'organization:id,name,short_name', 'members', 'items.segment.cableLine:id,registry_number,name',
            'items.documents.uploader:id,name', 'documents.uploader:id,name', 'creator:id,name', 'submitter:id,name', 'decider:id,name',
        ])->loadCount('items');
    }
}
