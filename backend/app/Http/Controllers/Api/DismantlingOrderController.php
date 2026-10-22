<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalType;
use App\Enums\DismantlingEventType;
use App\Enums\DismantlingOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DismantlingOrderResource;
use App\Models\Approval;
use App\Models\CableLine;
use App\Models\DismantlingOrder;
use App\Services\AuditLogger;
use App\Services\DismantlingWorkflow;
use App\Services\MetalMassCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DismantlingOrderController extends Controller
{
    public function __construct(private readonly DismantlingWorkflow $workflow) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DismantlingOrder::class);
        $filters = $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(DismantlingOrderStatus::class)],
            'organization_id' => ['nullable', 'integer'],
            'cable_line_id' => ['nullable', 'integer'],
            'is_complex' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = DismantlingOrder::query()->visibleTo($request->user())
            ->with(['organization:id,name,short_name', 'cableLine:id,registry_number,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->whereIn('status', $s))
            ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['cable_line_id'] ?? null, fn ($q, $id) => $q->where('cable_line_id', $id))
            ->when(isset($filters['is_complex']), fn ($q) => $q->where('is_complex', (bool) $filters['is_complex']))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$term}%")->orWhere('order_number', 'like', "%{$term}%")->orWhere('work_plan_number', 'like', "%{$term}%")))
            ->latest('id');

        return DismantlingOrderResource::collection($query->paginate($filters['per_page'] ?? 20)->withQueryString());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', DismantlingOrder::class);
        $data = $this->validated($request, creating: true);
        $this->authorize('view', CableLine::query()->findOrFail($data['cable_line_id']));

        $order = $this->workflow->create($data, $request->user());

        return (new DismantlingOrderResource($this->loadDetails($order)))->response()->setStatusCode(201);
    }

    public function show(DismantlingOrder $dismantlingOrder): DismantlingOrderResource
    {
        $this->authorize('view', $dismantlingOrder);

        return new DismantlingOrderResource($this->loadDetails($dismantlingOrder));
    }

    public function update(Request $request, DismantlingOrder $dismantlingOrder): DismantlingOrderResource
    {
        $this->authorize('update', $dismantlingOrder);
        $order = $this->workflow->update($dismantlingOrder, $this->validated($request, creating: false), $request->user());

        return new DismantlingOrderResource($this->loadDetails($order));
    }

    public function transition(Request $request, DismantlingOrder $dismantlingOrder): DismantlingOrderResource
    {
        $this->authorize('transition', $dismantlingOrder);
        $data = $request->validate([
            'status' => ['required', Rule::enum(DismantlingOrderStatus::class)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $order = $this->workflow->transition($dismantlingOrder, DismantlingOrderStatus::from($data['status']), $request->user(), $data['reason'] ?? null);

        return new DismantlingOrderResource($this->loadDetails($order));
    }

    public function storeApproval(Request $request, DismantlingOrder $dismantlingOrder): DismantlingOrderResource
    {
        $this->authorize('recordApproval', $dismantlingOrder);
        $data = $request->validate([
            'type' => ['required', Rule::enum(ApprovalType::class)],
            'decision' => ['required', Rule::enum(ApprovalDecision::class)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'reference_date' => ['nullable', 'date', 'before_or_equal:today'],
            'external_party' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'required_if:decision,rejected', 'string', 'max:2000'],
            'document_id' => ['nullable', 'integer'],
        ]);

        $this->workflow->recordApproval($dismantlingOrder, $data, $request->user());

        return new DismantlingOrderResource($this->loadDetails($dismantlingOrder));
    }

    public function revokeApproval(Request $request, DismantlingOrder $dismantlingOrder, Approval $approval): DismantlingOrderResource
    {
        $this->authorize('recordApproval', $dismantlingOrder);
        abort_unless($approval->approvable_type === $dismantlingOrder->getMorphClass() && $approval->approvable_id === $dismantlingOrder->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        $this->workflow->revokeApproval($approval, $data['reason'], $request->user());

        return new DismantlingOrderResource($this->loadDetails($dismantlingOrder));
    }

    public function storeEvent(Request $request, DismantlingOrder $dismantlingOrder): DismantlingOrderResource
    {
        $this->authorize('recordEvent', $dismantlingOrder);
        $data = $request->validate([
            'type' => ['required', Rule::enum(DismantlingEventType::class)->except([DismantlingEventType::StatusChange])],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'cable_segment_id' => ['nullable', 'integer'],
            'extracted_length_m' => ['nullable', 'required_if:type,progress', 'numeric', 'gt:0', 'decimal:0,2'],
            'description' => ['nullable', 'required_unless:type,progress', 'string', 'max:5000'],
        ]);

        $this->workflow->recordEvent($dismantlingOrder, $data, $request->user());

        return new DismantlingOrderResource($this->loadDetails($dismantlingOrder->refresh()));
    }

    /** Masses recovered according to the dismantling act (kg). Empty = unknown, 0 = none recovered. */
    public function actMasses(Request $request, DismantlingOrder $dismantlingOrder): DismantlingOrderResource
    {
        $this->authorize('recordEvent', $dismantlingOrder);
        $metal = ['nullable', 'numeric', 'min:0', 'max:10000000', 'decimal:0,3'];
        $data = $request->validate([
            'aluminium_kg' => $metal,
            'lead_kg' => $metal,
            'copper_kg' => $metal,
            'document_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ], [
            'document_id.required' => 'Massalar asosi bo\'lgan demontaj dalolatnomasini tanlang.',
        ]);
        if (($data['aluminium_kg'] ?? null) === null && ($data['lead_kg'] ?? null) === null && ($data['copper_kg'] ?? null) === null) {
            throw ValidationException::withMessages(['aluminium_kg' => ["Kamida bitta metall massasini kiriting (olinmagan bo'lsa 0)."]]);
        }

        $order = $this->workflow->recordActMasses($dismantlingOrder, $data, $request->user());

        return new DismantlingOrderResource($this->loadDetails($order));
    }

    public function act(DismantlingOrder $dismantlingOrder, AuditLogger $audit): Response
    {
        $this->authorize('view', $dismantlingOrder);
        $order = $this->loadDetails($dismantlingOrder);
        $audit->log('pdf_generated', $order, null, ['document' => 'dismantling_act']);

        return Pdf::loadView('pdf.dismantling-act', ['order' => $order])
            ->setPaper('a4')
            ->download("demontaj-dalolatnoma-{$order->number}.pdf");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'cable_line_id' => [$creating ? 'required' : 'prohibited', 'integer'],
            'segments' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:200'],
            'segments.*.id' => ['required', 'integer', 'distinct'],
            'segments.*.planned_length_m' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'work_plan_number' => ['nullable', 'string', 'max:64'],
            'order_number' => ['nullable', 'string', 'max:64'],
            'order_date' => ['nullable', 'date'],
            'brigade_name' => ['nullable', 'string', 'max:255'],
            'work_supervisor_id' => ['nullable', 'integer', 'exists:users,id'],
            'work_supervisor_name' => ['nullable', 'string', 'max:255'],
            'planned_start' => ['nullable', 'date'],
            'planned_end' => ['nullable', 'date', 'after_or_equal:planned_start'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function loadDetails(DismantlingOrder $order): DismantlingOrder
    {
        $order->load([
            'organization:id,name,short_name', 'cableLine:id,registry_number,name', 'segments', 'workSupervisor:id,name',
            'approvals.decider:id,name', 'approvals.revoker:id,name', 'events.user:id,name', 'events.segment:id,code',
            'documents.uploader:id,name', 'materialReceipts', 'creator:id,name', 'actMassesRecordedBy:id,name',
        ]);

        $order->setAttribute('metal_balance', app(MetalMassCalculator::class)->orderBalance($order));

        // What is still missing for each allowed next status (shown in the UI before the user tries)
        $order->setAttribute('checks', collect($order->status->allowedTransitions())->mapWithKeys(fn (DismantlingOrderStatus $target) => [
            $target->value => $this->workflow->missingPreconditions($order, $target, 'preview'),
        ])->all() + ['required_approvals' => array_map(fn ($t) => $t->value, $this->workflow->requiredApprovals($order))]);

        return $order;
    }
}
