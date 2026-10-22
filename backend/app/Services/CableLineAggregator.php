<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DismantlingStatus;
use App\Enums\RiskLevel;
use App\Enums\SegmentInspectionStatus;
use App\Enums\TechnicalCondition;
use App\Enums\UsageStatus;
use App\Models\CableLine;
use App\Models\CableSegment;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * Line-level statuses are derived from the line's segments (single source of truth)
 * and persisted only to make registry filtering fast.
 */
final class CableLineAggregator
{
    public function refresh(CableLine $line): CableLine
    {
        /** @var Collection<int, CableSegment> $segments */
        $segments = $line->segments()->get();

        $line->forceFill($this->aggregate($segments))->saveQuietly();

        return $line;
    }

    /**
     * @param  Collection<int, CableSegment>  $segments
     * @return array<string, mixed>
     */
    public function aggregate(Collection $segments): array
    {
        if ($segments->isEmpty()) {
            return [
                'technical_condition' => TechnicalCondition::Unknown,
                'usage_status' => UsageStatus::Unknown,
                'risk_level' => RiskLevel::Low,
                'inspection_status' => SegmentInspectionStatus::NotInspected,
                'dismantling_status' => DismantlingStatus::NotPlanned,
                'has_geometry' => false,
                'is_complex' => false,
                'measured_length_m' => null,
            ];
        }

        $measured = $segments->pluck('measured_length_m')->filter(fn ($v) => $v !== null);

        return [
            'technical_condition' => $this->technicalCondition($segments),
            'usage_status' => $this->usageStatus($segments),
            'risk_level' => $segments->sortByDesc(fn (CableSegment $s) => $s->risk_level->rank())->first()->risk_level,
            'inspection_status' => $this->inspectionStatus($segments),
            'dismantling_status' => $this->dismantlingStatus($segments),
            'has_geometry' => $segments->contains(fn (CableSegment $s) => $s->hasGeometry()),
            'is_complex' => $segments->contains(fn (CableSegment $s) => $s->isComplex()),
            'measured_length_m' => $measured->isEmpty() ? null : Decimal::sum($measured),
        ];
    }

    /** Worst known condition; unknown only when nothing is known. */
    private function technicalCondition(Collection $segments): TechnicalCondition
    {
        return $segments->pluck('technical_condition')
            ->sortByDesc(fn (TechnicalCondition $c) => $c->rank())
            ->first();
    }

    private function usageStatus(Collection $segments): UsageStatus
    {
        $statuses = $segments->pluck('usage_status')->unique(fn (UsageStatus $s) => $s->value);

        if ($statuses->count() === 1) {
            return $statuses->first();
        }

        $known = $statuses->reject(fn (UsageStatus $s) => $s === UsageStatus::Unknown);
        if ($known->count() === 1 && $known->first() !== UsageStatus::NotInUse) {
            return $known->first();
        }

        // Mixed in-use / not-in-use (or some unknown next to not-in-use) — never claim "not in use" for the whole line
        return UsageStatus::PartiallyUsed;
    }

    private function inspectionStatus(Collection $segments): SegmentInspectionStatus
    {
        $statuses = $segments->pluck('inspection_status');

        return match (true) {
            $statuses->every(fn ($s) => $s === SegmentInspectionStatus::Inspected) => SegmentInspectionStatus::Inspected,
            $statuses->contains(SegmentInspectionStatus::RequiresReinspection) => SegmentInspectionStatus::RequiresReinspection,
            $statuses->contains(SegmentInspectionStatus::Scheduled) => SegmentInspectionStatus::Scheduled,
            default => SegmentInspectionStatus::NotInspected,
        };
    }

    private function dismantlingStatus(Collection $segments): DismantlingStatus
    {
        $statuses = $segments->pluck('dismantling_status')->unique(fn (DismantlingStatus $s) => $s->value);

        if ($statuses->count() === 1) {
            return $statuses->first();
        }

        $finished = [DismantlingStatus::Completed, DismantlingStatus::PartiallyCompleted, DismantlingStatus::LeftInPlace];

        return match (true) {
            $statuses->contains(DismantlingStatus::InProgress) => DismantlingStatus::InProgress,
            $statuses->contains(DismantlingStatus::Suspended) => DismantlingStatus::Suspended,
            $statuses->contains(fn ($s) => in_array($s, $finished, true)) => DismantlingStatus::PartiallyCompleted,
            // Otherwise the least advanced state describes the line
            default => $statuses->sortBy(fn (DismantlingStatus $s) => $s->progress())->first(),
        };
    }
}
