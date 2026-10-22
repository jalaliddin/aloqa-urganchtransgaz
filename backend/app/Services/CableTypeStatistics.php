<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DismantlingOrderStatus;
use App\Enums\SegmentInspectionStatus;
use App\Models\CableLine;
use App\Models\CableSegment;
use App\Models\CableType;
use App\Models\DismantlingOrder;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Registry figures per cable type, within the user's organisation scope. Lengths follow the
 * MGQB summary so totals agree across reports: registry = line design length, inventoried =
 * measured length of inspected segments, dismantling = orders' planned / actual length.
 */
final class CableTypeStatistics
{
    /** Bucket for lines entered before the type became mandatory. */
    public const UNTYPED = 'none';

    public function __construct(private readonly MetalMassCalculator $calculator) {}

    /**
     * One row per type in use or active, longest first; untyped lines come last.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(User $user, ?int $organizationId = null, bool $withMetals = true): array
    {
        $lines = CableLine::query()->visibleTo($user)
            ->when($organizationId, fn ($q, $id) => $q->where('organization_id', $id));
        $typeOf = (clone $lines)->pluck('cable_type_id', 'id')->map(fn ($id) => $id === null ? self::UNTYPED : (string) $id);
        $lineIds = (clone $lines)->select('id');

        $perLine = fn (Builder $query, string $aggregate) => $query->whereIn('cable_line_id', clone $lineIds)
            ->groupBy('cable_line_id')
            ->selectRaw("cable_line_id, {$aggregate} as value")
            ->pluck('value', 'cable_line_id');

        $byType = [
            'design' => (clone $lines)->pluck('design_length_m', 'id'),
            'segments' => $perLine(CableSegment::query(), 'COUNT(*)'),
            'inventoried' => $perLine(
                CableSegment::query()->where('inspection_status', SegmentInspectionStatus::Inspected->value),
                'COALESCE(SUM(measured_length_m), 0)',
            ),
            'planned' => $perLine(
                DismantlingOrder::query()->whereNotIn('status', array_map(fn ($s) => $s->value, DashboardService::PLAN_EXCLUDED)),
                'COALESCE(SUM(planned_length_m), 0)',
            ),
            'dismantled' => $perLine(
                DismantlingOrder::query()->where('status', '!=', DismantlingOrderStatus::Cancelled->value),
                'COALESCE(SUM(actual_length_m), 0)',
            ),
        ];
        $grouped = array_map(fn (Collection $values) => $values->groupBy(fn ($v, $lineId) => $typeOf[$lineId] ?? self::UNTYPED, preserveKeys: true), $byType);

        $metals = $withMetals ? $this->metals($lineIds) : collect();
        $usedTypeIds = $typeOf->reject(fn ($k) => $k === self::UNTYPED)->unique()->values()->all();
        $types = CableType::withTrashed()
            ->where(fn ($q) => $q->whereIn('id', $usedTypeIds)->orWhere(fn ($a) => $a->where('is_active', true)->whereNull('deleted_at')))
            ->get()
            ->keyBy(fn (CableType $t) => (string) $t->id);

        $keys = $types->keys();
        if ($typeOf->contains(self::UNTYPED)) {
            $keys->push(self::UNTYPED);
        }

        $grandTotal = Decimal::sum($byType['design']->all());

        $rows = $keys->map(function (string $key) use ($types, $typeOf, $grouped, $metals, $grandTotal, $withMetals): array {
            $type = $types[$key] ?? null;
            $sum = fn (string $measure) => Decimal::sum(($grouped[$measure][$key] ?? collect())->all());
            $total = $sum('design');
            $inventoried = $sum('inventoried');
            $planned = $sum('planned');
            $dismantled = $sum('dismantled');

            $row = [
                'key' => $key,
                'cable_type_id' => $type?->id,
                'code' => $type?->code,
                'name' => $type?->name ?? "Turi ko'rsatilmagan",
                'brand' => $type?->brand,
                'is_active' => $type ? $type->is_active && ! $type->trashed() : null,
                'is_demo' => (bool) $type?->is_demo,
                'lines_count' => $typeOf->filter(fn ($k) => $k === $key)->count(),
                'segments_count' => (int) ($grouped['segments'][$key] ?? collect())->sum(),
                'total_length_m' => $total,
                'share_percent' => Decimal::percent($total, $grandTotal),
                'inventoried_length_m' => $inventoried,
                'inventory_percent' => Decimal::percent($inventoried, $total),
                'planned_length_m' => $planned,
                'dismantled_length_m' => $dismantled,
                'dismantling_percent' => Decimal::percent($dismantled, $planned),
            ];

            if ($withMetals) {
                // A total with unknown parts is not shown as a figure (NULL = unknown, never 0)
                $summary = $metals[$key] ?? null;
                $metal = fn (string $m) => $summary === null ? '0.000' : ($summary['totals'][$m]['complete'] ? $summary['totals'][$m]['known_kg'] : null);
                $row += [
                    'aluminium_kg' => $metal('aluminium'),
                    'lead_kg' => $metal('lead'),
                    'copper_kg' => $metal('copper'),
                    'metal_unknown_segments' => $summary === null ? 0 : collect($summary['totals'])->flatMap(fn ($t) => $t['unknown_segments'])->unique()->count(),
                ];
            }

            return $row;
        });

        return $rows
            ->sortBy([
                fn ($a, $b) => ($a['key'] === self::UNTYPED) <=> ($b['key'] === self::UNTYPED),
                fn ($a, $b) => Decimal::of($b['total_length_m'])->compareTo(Decimal::of($a['total_length_m'])),
                fn ($a, $b) => strcmp($a['name'], $b['name']),
            ])
            ->values()
            ->all();
    }

    /** @return Collection<string, array<string, mixed>> theoretical metal summary per type key */
    private function metals(Builder $lineIds): Collection
    {
        return CableSegment::query()->whereIn('cable_line_id', clone $lineIds)
            ->with('cableLine:id,cable_type_id,organization_id')
            ->get()
            ->groupBy(fn (CableSegment $s) => $s->cableLine?->cable_type_id === null ? self::UNTYPED : (string) $s->cableLine->cable_type_id)
            ->map(fn ($group) => $this->calculator->summarise($group));
    }
}
