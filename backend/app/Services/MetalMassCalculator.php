<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CompositionStatus;
use App\Enums\DismantlingEventType;
use App\Enums\Metal;
use App\Enums\MetalCalculationTrigger;
use App\Models\CableLine;
use App\Models\CableMetalComposition;
use App\Models\CableSegment;
use App\Models\DismantlingOrder;
use App\Models\MaterialItem;
use App\Models\MetalMassCalculation;
use App\Models\User;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Theoretical non-ferrous metal mass: length_m × kg_per_m, per metal.
 *
 * Rules:
 * - The composition used is the most specific approved version: segment → line → cable type.
 *   A version is a complete set; NULL inside it means "unknown", never "0" and never "inherit".
 * - An unknown kg/m or an unknown length gives an unknown (null) mass; totals that contain
 *   unknown parts are returned as incomplete and must not be presented as reliable.
 * - Theoretical, dismantling-act and warehouse masses are separate figures; nothing here
 *   writes one into another.
 */
final class MetalMassCalculator
{
    private const MASS_SCALE = 3;

    /**
     * Effective approved composition for every given segment (bulk, 1 query).
     *
     * @param  Collection<int, CableSegment>  $segments  segments with cableLine loaded
     * @return array<int, array{composition: ?CableMetalComposition, scope: ?string}>
     */
    public function resolve(Collection $segments): array
    {
        if ($segments->isEmpty()) {
            return [];
        }

        $segments = new EloquentCollection($segments->all());
        $segments->loadMissing('cableLine:id,cable_type_id,organization_id');
        $lineIds = $segments->pluck('cable_line_id')->unique()->values();
        $typeIds = $segments->map(fn (CableSegment $s) => $s->cableLine?->cable_type_id)->filter()->unique()->values();

        $compositions = CableMetalComposition::query()
            ->where('status', CompositionStatus::Approved->value)
            ->where(function ($q) use ($segments, $lineIds, $typeIds): void {
                $q->where(fn ($w) => $w->where('scope_type', 'cable_segment')->whereIn('scope_id', $segments->pluck('id')))
                    ->orWhere(fn ($w) => $w->where('scope_type', 'cable_line')->whereIn('scope_id', $lineIds))
                    ->orWhere(fn ($w) => $w->where('scope_type', 'cable_type')->whereIn('scope_id', $typeIds));
            })
            ->get()
            ->keyBy(fn (CableMetalComposition $c) => $c->scope_type.':'.$c->scope_id);

        $result = [];
        foreach ($segments as $segment) {
            $typeId = $segment->cableLine?->cable_type_id;
            $candidates = [
                'cable_segment' => $compositions->get('cable_segment:'.$segment->id),
                'cable_line' => $compositions->get('cable_line:'.$segment->cable_line_id),
                'cable_type' => $typeId ? $compositions->get('cable_type:'.$typeId) : null,
            ];
            $scope = collect($candidates)->filter()->keys()->first();
            $result[$segment->id] = ['composition' => $scope ? $candidates[$scope] : null, 'scope' => $scope];
        }

        return $result;
    }

    /** Theoretical mass of one metal; null when either factor is unknown. */
    public function mass(?string $lengthM, ?string $kgPerMetre): ?string
    {
        if ($lengthM === null || $kgPerMetre === null) {
            return null;
        }

        return (string) BigDecimal::of($lengthM)->multipliedBy(BigDecimal::of($kgPerMetre))->toScale(self::MASS_SCALE, RoundingMode::HalfUp);
    }

    /**
     * @return array{length_m: ?string, composition_id: ?int, scope: ?string, complete: bool,
     *               metals: array<string, array{kg_per_m: ?string, theoretical_kg: ?string, known: bool}>}
     */
    public function forSegment(CableSegment $segment, ?CableMetalComposition $composition, ?string $scope, ?string $lengthM = null): array
    {
        $length = $lengthM ?? $segment->bookLength();
        $metals = [];

        foreach (Metal::cases() as $metal) {
            $kg = $composition?->kgPerMetre($metal);
            $mass = $this->mass($length, $kg);
            $metals[$metal->value] = ['kg_per_m' => $kg, 'theoretical_kg' => $mass, 'known' => $mass !== null];
        }

        return [
            'length_m' => $length,
            'composition_id' => $composition?->id,
            'scope' => $scope,
            'complete' => collect($metals)->every(fn ($m) => $m['known']),
            'metals' => $metals,
        ];
    }

    /**
     * Segment rows and per-metal totals for a line.
     *
     * @return array{segments: list<array<string, mixed>>, totals: array<string, array{known_kg: string, complete: bool, unknown_segments: list<string>}>, complete: bool}
     */
    public function forLine(CableLine $line): array
    {
        $segments = $line->segments()->with('cableLine:id,cable_type_id,organization_id')->get();

        return $this->summarise($segments);
    }

    /**
     * @param  Collection<int, CableSegment>  $segments
     * @return array{segments: list<array<string, mixed>>, totals: array<string, array{known_kg: string, complete: bool, unknown_segments: list<string>}>, complete: bool}
     */
    public function summarise(Collection $segments): array
    {
        $resolved = $this->resolve($segments);
        $rows = [];
        $totals = [];
        foreach (Metal::cases() as $metal) {
            $totals[$metal->value] = ['known_kg' => '0.000', 'complete' => true, 'unknown_segments' => []];
        }

        foreach ($segments as $segment) {
            $calc = $this->forSegment($segment, $resolved[$segment->id]['composition'], $resolved[$segment->id]['scope']);
            $rows[] = ['segment_id' => $segment->id, 'code' => $segment->code, ...$calc];

            foreach ($calc['metals'] as $metal => $values) {
                if ($values['known']) {
                    $totals[$metal]['known_kg'] = Decimal::sum([$totals[$metal]['known_kg'], $values['theoretical_kg']], self::MASS_SCALE);
                } else {
                    $totals[$metal]['complete'] = false;
                    $totals[$metal]['unknown_segments'][] = $segment->code;
                }
            }
        }

        return [
            'segments' => $rows,
            'totals' => $totals,
            'complete' => collect($totals)->every(fn ($t) => $t['complete']),
        ];
    }

    /**
     * Theoretical (for the extracted length), dismantling-act and warehouse masses of an order,
     * with differences in kg and % per metal. Missing figures stay null.
     *
     * @return array<string, mixed>
     */
    public function orderBalance(DismantlingOrder $order): array
    {
        $segments = $order->segments()->with('cableLine:id,cable_type_id,organization_id')->get();
        $resolved = $this->resolve($segments);
        $tolerance = app(SettingsService::class)->metalTolerancePercent();

        // Extracted length per segment from progress records; the rest is unassigned
        $assigned = $order->events()
            ->where('type', DismantlingEventType::Progress->value)
            ->whereNotNull('cable_segment_id')
            ->get()
            ->groupBy('cable_segment_id')
            ->map(fn ($events) => Decimal::sum($events->pluck('extracted_length_m')));
        $unassigned = Decimal::diff($order->actual_length_m, Decimal::sum($assigned->values()));
        if (Decimal::of($unassigned)->isNegative()) {
            $unassigned = '0.00';
        }

        $warehouse = $this->warehouseMasses($order);
        $metals = [];

        foreach (Metal::cases() as $metal) {
            $planned = $this->sumKnown($segments->map(fn (CableSegment $s) => $this->mass(
                $s->pivot->planned_length_m !== null ? (string) $s->pivot->planned_length_m : $s->bookLength(),
                $resolved[$s->id]['composition']?->kgPerMetre($metal),
            ) ?? false));

            // Extracted: assigned lengths per segment, plus unassigned length only if all segments share the same known kg/m
            $parts = $segments->map(fn (CableSegment $s) => isset($assigned[$s->id])
                ? ($this->mass($assigned[$s->id], $resolved[$s->id]['composition']?->kgPerMetre($metal)) ?? false)
                : '0.000');
            $kgValues = $segments->map(fn (CableSegment $s) => $resolved[$s->id]['composition']?->kgPerMetre($metal))->unique();
            if (! Decimal::of($unassigned)->isZero()) {
                $parts->push($kgValues->count() === 1 && $kgValues->first() !== null
                    ? $this->mass($unassigned, $kgValues->first())
                    : false);
            }
            $extracted = $this->sumKnown($parts);

            $act = $order->getAttribute($metal->actColumn());
            $act = $act === null ? null : (string) $act;
            $stock = $warehouse[$metal->value];

            $metals[$metal->value] = [
                'theoretical_planned_kg' => $planned['complete'] ? $planned['kg'] : null,
                'theoretical_kg' => $extracted['complete'] ? $extracted['kg'] : null,
                'theoretical_known_part_kg' => $extracted['kg'],
                'theoretical_complete' => $extracted['complete'],
                'act_kg' => $act,
                'warehouse_kg' => $stock,
                'act_vs_theoretical' => $this->difference($act, $extracted['complete'] ? $extracted['kg'] : null, $tolerance),
                'warehouse_vs_act' => $this->difference($stock, $act, $tolerance),
                'warehouse_vs_theoretical' => $this->difference($stock, $extracted['complete'] ? $extracted['kg'] : null, $tolerance),
            ];
        }

        return [
            'extracted_length_m' => (string) $order->actual_length_m,
            'unassigned_length_m' => $unassigned,
            'tolerance_percent' => $tolerance,
            'act_recorded_at' => $order->act_masses_recorded_at?->toIso8601String(),
            'metals' => $metals,
        ];
    }

    /**
     * Accepted warehouse mass per metal (kg) for an order; null when nothing has been received yet.
     *
     * @return array<string, ?string>
     */
    public function warehouseMasses(DismantlingOrder $order): array
    {
        $hasReceipts = $order->materialReceipts()->exists();
        $items = MaterialItem::query()
            ->whereHas('receipt', fn ($q) => $q->where('dismantling_order_id', $order->id))
            ->where('unit', 'kg')
            ->get(['material_type', 'accepted_quantity']);

        $result = [];
        foreach (Metal::cases() as $metal) {
            $result[$metal->value] = $hasReceipts
                ? Decimal::sum($items->filter(fn ($i) => $i->material_type === $metal->materialType())->pluck('accepted_quantity'), self::MASS_SCALE)
                : null;
        }

        return $result;
    }

    /**
     * Difference "actual − reference" in kg and % of the reference; null when either is unknown.
     *
     * @return array{kg: ?string, percent: ?float, exceeds: ?bool}
     */
    public function difference(?string $actual, ?string $reference, float $tolerancePercent): array
    {
        if ($actual === null || $reference === null) {
            return ['kg' => null, 'percent' => null, 'exceeds' => null];
        }

        $kg = Decimal::diff($actual, $reference, self::MASS_SCALE);
        $percent = Decimal::of($reference, self::MASS_SCALE)->isZero() ? null : Decimal::percent($kg, $reference, 2);
        $exceeds = Decimal::of($reference, self::MASS_SCALE)->isZero()
            ? ! Decimal::of($kg, self::MASS_SCALE)->isZero()
            : Decimal::exceedsTolerance($reference, $actual, 0, $tolerancePercent, self::MASS_SCALE);

        return ['kg' => $kg, 'percent' => $percent, 'exceeds' => $exceeds];
    }

    /** Stores a calculation snapshot for a segment (history when length or composition changes). */
    public function snapshotSegment(CableSegment $segment, MetalCalculationTrigger $trigger, ?User $user = null, array $details = []): MetalMassCalculation
    {
        $resolved = $this->resolve(collect([$segment]))[$segment->id];
        $calc = $this->forSegment($segment, $resolved['composition'], $resolved['scope']);

        return $segment->massCalculations()->create([
            'composition_id' => $calc['composition_id'],
            'trigger' => $trigger,
            'length_m' => $calc['length_m'],
            'aluminium_kg' => $calc['metals']['aluminium']['theoretical_kg'],
            'lead_kg' => $calc['metals']['lead']['theoretical_kg'],
            'copper_kg' => $calc['metals']['copper']['theoretical_kg'],
            'is_complete' => $calc['complete'],
            'details' => ['scope' => $calc['scope'], 'kg_per_m' => collect($calc['metals'])->map(fn ($m) => $m['kg_per_m'])->all(), ...$details],
            'calculated_by' => ($user ?? Auth::user())?->id,
        ]);
    }

    /** History entry when a segment's book length changes (only if a composition applies to it). */
    public function recordLengthChange(CableSegment $segment): void
    {
        $segment->loadMissing('cableLine:id,cable_type_id,organization_id');
        if ($this->resolve(collect([$segment]))[$segment->id]['composition'] === null) {
            return;
        }

        $this->snapshotSegment($segment, MetalCalculationTrigger::LengthChanged, null, [
            'previous' => [
                'design_length_m' => $segment->getOriginal('design_length_m') !== null ? (string) $segment->getOriginal('design_length_m') : null,
                'measured_length_m' => $segment->getOriginal('measured_length_m') !== null ? (string) $segment->getOriginal('measured_length_m') : null,
            ],
        ]);
    }

    public function snapshotOrder(DismantlingOrder $order, MetalCalculationTrigger $trigger, ?User $user = null): MetalMassCalculation
    {
        $balance = $this->orderBalance($order);

        return $order->massCalculations()->create([
            'trigger' => $trigger,
            'length_m' => $order->actual_length_m,
            'aluminium_kg' => $balance['metals']['aluminium']['theoretical_kg'],
            'lead_kg' => $balance['metals']['lead']['theoretical_kg'],
            'copper_kg' => $balance['metals']['copper']['theoretical_kg'],
            'is_complete' => collect($balance['metals'])->every(fn ($m) => $m['theoretical_complete']),
            'details' => ['balance' => $balance['metals']],
            'calculated_by' => ($user ?? Auth::user())?->id,
        ]);
    }

    /**
     * @param  Collection<int, string|false>  $parts  false marks an unknown part
     * @return array{kg: string, complete: bool}
     */
    private function sumKnown(Collection $parts): array
    {
        return [
            'kg' => Decimal::sum($parts->filter(fn ($p) => $p !== false), self::MASS_SCALE),
            'complete' => ! $parts->contains(false),
        ];
    }
}
