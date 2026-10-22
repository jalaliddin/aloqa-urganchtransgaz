<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MaterialReceiptStatus;
use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Enums\WarehouseTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\MaterialReceiptResource;
use App\Models\DismantlingOrder;
use App\Models\MaterialItem;
use App\Models\MaterialReceipt;
use App\Models\User;
use App\Models\WarehouseTransaction;
use App\Services\MaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class MaterialController extends Controller
{
    public function __construct(private readonly MaterialService $materials) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MaterialReceipt::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(MaterialReceiptStatus::class)],
            'organization_id' => ['nullable', 'integer'],
            'dismantling_order_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = MaterialReceipt::query()->visibleTo($request->user())
            ->with(['organization:id,name,short_name', 'dismantlingOrder:id,number,status,actual_length_m', 'handedOverBy:id,name', 'receivedBy:id,name', 'items'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['dismantling_order_id'] ?? null, fn ($q, $id) => $q->where('dismantling_order_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('number', 'like', "%{$term}%")->orWhere('warehouse_name', 'like', "%{$term}%")))
            ->latest('receipt_date')->latest('id');

        return MaterialReceiptResource::collection($query->paginate($filters['per_page'] ?? 20)->withQueryString());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MaterialReceipt::class);
        $data = $request->validate([
            'dismantling_order_id' => ['required', 'integer'],
            'number' => ['required', 'string', 'max:64', 'unique:material_receipts,number'],
            'receipt_date' => ['required', 'date', 'before_or_equal:today'],
            'warehouse_name' => ['required', 'string', 'max:255'],
            'handed_over_by' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.material_type' => ['required', Rule::enum(MaterialType::class)],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            // Non-ferrous metals are weighed: kg only, so masses can be compared with the act and theory
            'items.*.unit' => ['required', Rule::enum(MaterialUnit::class), function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                $type = MaterialType::tryFrom((string) $request->input(str_replace('.unit', '.material_type', $attribute)));
                if ($type?->metal() !== null && $value !== MaterialUnit::Kg->value) {
                    $fail("Alyuminiy, qo'rg'oshin va mis kilogrammda (kg) kiritiladi.");
                }
            }],
            'items.*.handed_over_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'items.*.accepted_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'items.*.measured_on' => ['required', 'date', 'before_or_equal:today'],
            'items.*.cable_segment_id' => ['nullable', 'integer'],
            'items.*.storage_location' => ['nullable', 'string', 'max:255'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->authorize('view', DismantlingOrder::query()->findOrFail($data['dismantling_order_id']));
        $receipt = $this->materials->receive($data, $request->user());

        return (new MaterialReceiptResource($this->loadDetails($receipt)))->response()->setStatusCode(201);
    }

    public function show(MaterialReceipt $materialReceipt): MaterialReceiptResource
    {
        $this->authorize('view', $materialReceipt);

        return new MaterialReceiptResource($this->loadDetails($materialReceipt));
    }

    public function decide(Request $request, MaterialReceipt $materialReceipt): MaterialReceiptResource
    {
        $this->authorize('decide', $materialReceipt);
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'explanation' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $receipt = $this->materials->decideDiscrepancy($materialReceipt, (bool) $data['approve'], $data['explanation'], $request->user());

        return new MaterialReceiptResource($this->loadDetails($receipt));
    }

    public function move(Request $request, MaterialItem $materialItem): MaterialReceiptResource
    {
        $receipt = $materialItem->receipt;
        $this->authorize('move', $receipt);
        $data = $request->validate([
            'type' => ['required', Rule::enum(WarehouseTransactionType::class)->except([WarehouseTransactionType::Receipt])],
            'quantity' => ['required', 'numeric', 'not_in:0', 'decimal:0,3'],
            'document_number' => ['nullable', 'required_unless:type,adjustment', 'string', 'max:100'],
            'document_date' => ['nullable', 'required_unless:type,adjustment', 'date', 'before_or_equal:today'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'storage_location' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'required_if:type,adjustment', 'string', 'max:2000'],
        ]);

        $this->materials->move($materialItem, $data, $request->user());

        return new MaterialReceiptResource($this->loadDetails($receipt));
    }

    public function decideTransaction(Request $request, WarehouseTransaction $warehouseTransaction): MaterialReceiptResource
    {
        $this->authorize('decide', $warehouseTransaction);
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'comment' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $this->materials->decideTransaction($warehouseTransaction, (bool) $data['approve'], $data['comment'], $request->user());

        return new MaterialReceiptResource($this->loadDetails($warehouseTransaction->item->receipt));
    }

    /** Orders from which material can be received, with candidate "handed over by" people. */
    public function receivableOrders(Request $request): JsonResponse
    {
        $this->authorize('create', MaterialReceipt::class);

        $orders = DismantlingOrder::query()->visibleTo($request->user())
            ->whereIn('status', ['in_progress', 'partially_completed', 'completed'])
            ->with(['cableLine:id,registry_number,name', 'segments:id,code'])
            ->latest('id')->limit(200)->get();

        $people = User::query()->where('is_active', true)
            ->whereIn('organization_id', $orders->pluck('organization_id')->unique())
            ->orderBy('name')->get(['id', 'name', 'position', 'organization_id']);

        return response()->json(['data' => [
            'orders' => $orders->map(fn (DismantlingOrder $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'organization_id' => $o->organization_id,
                'line' => $o->cableLine?->registry_number.' — '.$o->cableLine?->name,
                'status' => $o->status->value,
                'actual_length_m' => $o->actual_length_m,
                'accepted_cable_m' => $this->materials->acceptedCableMeters($o),
                'segments' => $o->segments->map(fn ($s) => ['id' => $s->id, 'code' => $s->code]),
            ]),
            'people' => $people,
        ]]);
    }

    private function loadDetails(MaterialReceipt $receipt): MaterialReceipt
    {
        $receipt->load([
            'organization:id,name,short_name', 'dismantlingOrder', 'handedOverBy:id,name', 'receivedBy:id,name',
            'discrepancyDecider:id,name', 'items.segment:id,code', 'items.transactions.creator:id,name', 'items.transactions.decider:id,name',
            'documents.uploader:id,name',
        ]);
        $receipt->setAttribute('discrepancies', $this->materials->discrepancies($receipt));

        return $receipt;
    }
}
