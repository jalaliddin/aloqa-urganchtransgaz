<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\DismantlingEventType;
use App\Enums\DismantlingOrderStatus;
use App\Enums\DismantlingStatus;
use App\Enums\DocumentType;
use App\Enums\Metal;
use App\Enums\OrganizationType;
use App\Enums\SegmentInspectionStatus;
use App\Models\Alert;
use App\Models\CableLine;
use App\Models\CableSegment;
use App\Models\DismantlingOrder;
use App\Models\MaterialItem;
use App\Models\MaterialReceipt;
use App\Models\Organization;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard figures. Every number is computed from stored records within the
 * user's organisation scope — nothing is estimated or invented.
 */
final class DashboardService
{
    public function __construct(private readonly MetalMassCalculator $calculator) {}

    /** Orders that still count towards the dismantling plan. */
    private const PLAN_EXCLUDED = [DismantlingOrderStatus::Cancelled, DismantlingOrderStatus::LeftInPlace];

    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        $lines = CableLine::query()->visibleTo($user);
        $segments = CableSegment::query()->visibleTo($user);
        $orders = DismantlingOrder::query()->visibleTo($user);
        $planOrders = (clone $orders)->whereNotIn('status', array_map(fn ($s) => $s->value, self::PLAN_EXCLUDED));

        $planned = (string) ((clone $planOrders)->sum('planned_length_m') ?: '0');
        $dismantled = (string) ((clone $orders)->where('status', '!=', DismantlingOrderStatus::Cancelled->value)->sum('actual_length_m') ?: '0');
        $documents = $this->incompleteDocuments($user);

        return [
            'has_demo_data' => (clone $lines)->where('is_demo', true)->exists(),
            'kpis' => [
                'lines_count' => (clone $lines)->count(),
                'segments_count' => (clone $segments)->count(),
                'total_length_m' => $this->money((clone $lines)->sum('design_length_m')),
                'inventoried_length_m' => $this->money((clone $segments)->where('inspection_status', SegmentInspectionStatus::Inspected->value)->sum('measured_length_m')),
                'planned_dismantling_length_m' => $this->money($planned),
                'dismantled_length_m' => $this->money($dismantled),
                'dismantling_percent' => Decimal::percent($dismantled, $planned),
                'materials_received' => $this->materialsReceived($user),
                'segments_requiring_inspection' => (clone $segments)->where('inspection_status', '!=', SegmentInspectionStatus::Inspected->value)->count(),
                'incomplete_documents' => array_sum($documents),
                'incomplete_documents_breakdown' => $documents,
                'hazardous_segments' => (clone $segments)->complex()->count(),
                'segments_without_geometry' => (clone $segments)->whereNull('geometry')->count(),
                'open_alerts' => Alert::query()->visibleTo($user)->where('status', '!=', AlertStatus::Resolved->value)->count(),
            ],
            'metals' => $this->metals($user),
            'by_organization' => $this->byOrganization($user),
            'status_distribution' => $this->statusDistribution($segments),
            'monthly_dismantling' => $this->monthlyDismantling($user),
        ];
    }

    /** @return array<string, int> */
    public function incompleteDocuments(User $user): array
    {
        $missingDoc = fn (Builder $query, string $type) => $query->whereDoesntHave(
            'documents',
            fn (Builder $d) => $d->where('type', $type)->whereNull('voided_at'),
        );

        return [
            'cable_lines' => CableLine::query()->visibleTo($user)->withIncompleteDocuments()->count(),
            'completed_orders' => $missingDoc(
                DismantlingOrder::query()->visibleTo($user)->where('status', DismantlingOrderStatus::Completed->value),
                DocumentType::DismantlingAct->value,
            )->count(),
            'left_in_place_orders' => $missingDoc(
                DismantlingOrder::query()->visibleTo($user)->where('status', DismantlingOrderStatus::LeftInPlace->value),
                DocumentType::LeaveInPlaceDecision->value,
            )->count(),
            'material_receipts' => $missingDoc(
                MaterialReceipt::query()->visibleTo($user),
                DocumentType::WarehouseReceipt->value,
            )->count(),
        ];
    }

    /**
     * Non-ferrous metals: theoretical mass of the registry (with completeness), mass per dismantling
     * acts and mass accepted by the warehouse — kept as three separate figures.
     *
     * @return array<string, array<string, mixed>>
     */
    private function metals(User $user): array
    {
        $segments = CableSegment::query()->visibleTo($user)->with('cableLine:id,cable_type_id,organization_id')->get();
        $theory = $this->calculator->summarise($segments)['totals'];
        $ids = $user->visibleOrganizationIds();

        $warehouse = MaterialItem::query()
            ->join('material_receipts', 'material_receipts.id', '=', 'material_items.material_receipt_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('material_receipts.organization_id', $ids))
            ->where('material_items.unit', 'kg')
            ->groupBy('material_items.material_type')
            ->selectRaw('material_items.material_type as type, SUM(material_items.accepted_quantity) as kg')
            ->pluck('kg', 'type');

        $orders = DismantlingOrder::query()->visibleTo($user)->whereNotNull('act_masses_recorded_at');

        $result = [];
        foreach (Metal::cases() as $metal) {
            $result[$metal->value] = [
                'theoretical_kg' => $theory[$metal->value]['known_kg'],
                'theoretical_complete' => $theory[$metal->value]['complete'],
                'unknown_segments' => count($theory[$metal->value]['unknown_segments']),
                'act_kg' => (string) Decimal::of((string) ((clone $orders)->sum($metal->actColumn()) ?: '0'), 3),
                'warehouse_kg' => (string) Decimal::of((string) ($warehouse[$metal->materialType()->value] ?? '0'), 3),
            ];
        }

        return $result;
    }

    /** @return list<array{unit: string, quantity: string}> */
    private function materialsReceived(User $user): array
    {
        $ids = $user->visibleOrganizationIds();

        return MaterialItem::query()
            ->join('material_receipts', 'material_receipts.id', '=', 'material_items.material_receipt_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('material_receipts.organization_id', $ids))
            ->groupBy('material_items.unit')
            ->selectRaw('material_items.unit as unit, SUM(material_items.accepted_quantity) as quantity')
            ->get()
            ->map(fn ($row) => ['unit' => $row->unit, 'quantity' => number_format((float) $row->quantity, 3, '.', '')])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function byOrganization(User $user): array
    {
        $ids = $user->visibleOrganizationIds();
        $organizations = Organization::query()
            ->where('type', OrganizationType::Mgqb->value)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get();

        $sum = fn (string $table, string $column, ?callable $filter = null, bool $softDeletes = true) => DB::table($table)
            ->when($softDeletes, fn ($q) => $q->whereNull("{$table}.deleted_at"))
            ->when($filter, $filter)
            ->groupBy('organization_id')
            ->selectRaw("organization_id, COUNT(*) as cnt, COALESCE(SUM({$column}), 0) as total")
            ->get()
            ->keyBy('organization_id');

        $lines = $sum('cable_lines', 'design_length_m');
        $inventoried = $sum('cable_segments', 'measured_length_m', fn ($q) => $q->where('inspection_status', SegmentInspectionStatus::Inspected->value));
        $planned = $sum('dismantling_orders', 'planned_length_m', fn ($q) => $q->whereNotIn('status', array_map(fn ($s) => $s->value, self::PLAN_EXCLUDED)), false);
        $dismantled = $sum('dismantling_orders', 'actual_length_m', fn ($q) => $q->where('status', '!=', DismantlingOrderStatus::Cancelled->value), false);

        return $organizations->map(function (Organization $org) use ($lines, $inventoried, $planned, $dismantled): array {
            $total = (string) ($lines[$org->id]->total ?? '0');
            $inv = (string) ($inventoried[$org->id]->total ?? '0');
            $plan = (string) ($planned[$org->id]->total ?? '0');
            $done = (string) ($dismantled[$org->id]->total ?? '0');

            return [
                'organization_id' => $org->id,
                'name' => $org->short_name ?: $org->name,
                'is_demo' => $org->is_demo,
                'lines_count' => (int) ($lines[$org->id]->cnt ?? 0),
                'total_length_m' => $this->money($total),
                'inventoried_length_m' => $this->money($inv),
                'planned_length_m' => $this->money($plan),
                'dismantled_length_m' => $this->money($done),
                'inventory_percent' => Decimal::percent($inv, $total),
                'dismantling_percent' => Decimal::percent($done, $plan),
            ];
        })->values()->all();
    }

    /** @return list<array{status: string, segments: int, length_m: string}> */
    private function statusDistribution(Builder $segments): array
    {
        $rows = (clone $segments)
            ->groupBy('dismantling_status')
            ->selectRaw('dismantling_status, COUNT(*) as segments, COALESCE(SUM(COALESCE(measured_length_m, design_length_m)), 0) as length_m')
            ->toBase()
            ->get()
            ->keyBy('dismantling_status');

        return array_map(fn (DismantlingStatus $status) => [
            'status' => $status->value,
            'segments' => (int) ($rows[$status->value]->segments ?? 0),
            'length_m' => $this->money($rows[$status->value]->length_m ?? 0),
        ], DismantlingStatus::cases());
    }

    /** Extracted length per month over the last 12 months (from recorded progress). */
    private function monthlyDismantling(User $user): array
    {
        $ids = $user->visibleOrganizationIds();
        $from = now()->startOfMonth()->subMonths(11);

        $rows = DB::table('dismantling_events')
            ->join('dismantling_orders', 'dismantling_orders.id', '=', 'dismantling_events.dismantling_order_id')
            ->where('dismantling_events.type', DismantlingEventType::Progress->value)
            ->where('dismantling_events.occurred_at', '>=', $from)
            ->when($ids !== null, fn ($q) => $q->whereIn('dismantling_orders.organization_id', $ids))
            ->groupByRaw("DATE_FORMAT(dismantling_events.occurred_at, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(dismantling_events.occurred_at, '%Y-%m') as month, SUM(dismantling_events.extracted_length_m) as length_m")
            ->pluck('length_m', 'month');

        $result = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $from->copy()->addMonths($i)->format('Y-m');
            $result[] = ['month' => $month, 'length_m' => $this->money($rows[$month] ?? 0)];
        }

        return $result;
    }

    private function money(mixed $value): string
    {
        return (string) Decimal::of($value === null ? '0' : (string) $value);
    }
}
