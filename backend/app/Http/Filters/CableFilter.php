<?php

declare(strict_types=1);

namespace App\Http\Filters;

use App\Enums\DismantlingStatus;
use App\Enums\RiskLevel;
use App\Enums\SegmentInspectionStatus;
use App\Enums\TechnicalCondition;
use App\Enums\UsageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shared registry / map filters. Applied to cable_lines (registry) or, through
 * the parent line, to cable_segments (map) so both views always agree.
 */
final class CableFilter
{
    public const SORTABLE = [
        'registry_number', 'name', 'laid_year', 'design_length_m', 'measured_length_m',
        'dismantling_status', 'risk_level', 'usage_status', 'created_at', 'updated_at',
    ];

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'organization_id' => ['nullable', 'integer'],
            'gas_pipeline_asset_id' => ['nullable', 'integer'],
            'dismantling_status' => ['nullable', 'array'],
            'dismantling_status.*' => [Rule::enum(DismantlingStatus::class)],
            'usage_status' => ['nullable', 'array'],
            'usage_status.*' => [Rule::enum(UsageStatus::class)],
            'technical_condition' => ['nullable', 'array'],
            'technical_condition.*' => [Rule::enum(TechnicalCondition::class)],
            'risk_level' => ['nullable', 'array'],
            'risk_level.*' => [Rule::enum(RiskLevel::class)],
            'inspection_status' => ['nullable', 'array'],
            'inspection_status.*' => [Rule::enum(SegmentInspectionStatus::class)],
            'laid_year_from' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'laid_year_to' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'length_min' => ['nullable', 'numeric', 'min:0'],
            'length_max' => ['nullable', 'numeric', 'min:0'],
            'has_geometry' => ['nullable', 'boolean'],
            'is_complex' => ['nullable', 'boolean'],
            'is_demo' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ];
    }

    /** @param array<string, mixed> $filters */
    public static function applyToLines(Builder $query, array $filters): Builder
    {
        $table = $query->getModel()->getTable();
        $col = fn (string $c) => "{$table}.{$c}";

        $query
            ->when($filters['organization_id'] ?? null, fn ($q, $v) => $q->where($col('organization_id'), $v))
            ->when($filters['gas_pipeline_asset_id'] ?? null, fn ($q, $v) => $q->where($col('gas_pipeline_asset_id'), $v))
            ->when($filters['dismantling_status'] ?? null, fn ($q, $v) => $q->whereIn($col('dismantling_status'), $v))
            ->when($filters['usage_status'] ?? null, fn ($q, $v) => $q->whereIn($col('usage_status'), $v))
            ->when($filters['technical_condition'] ?? null, fn ($q, $v) => $q->whereIn($col('technical_condition'), $v))
            ->when($filters['risk_level'] ?? null, fn ($q, $v) => $q->whereIn($col('risk_level'), $v))
            ->when($filters['inspection_status'] ?? null, fn ($q, $v) => $q->whereIn($col('inspection_status'), $v))
            ->when($filters['laid_year_from'] ?? null, fn ($q, $v) => $q->where($col('laid_year'), '>=', $v))
            ->when($filters['laid_year_to'] ?? null, fn ($q, $v) => $q->where($col('laid_year'), '<=', $v))
            ->when(isset($filters['length_min']), fn ($q) => $q->where($col('design_length_m'), '>=', $filters['length_min']))
            ->when(isset($filters['length_max']), fn ($q) => $q->where($col('design_length_m'), '<=', $filters['length_max']))
            ->when(isset($filters['has_geometry']), fn ($q) => $q->where($col('has_geometry'), (bool) $filters['has_geometry']))
            ->when(isset($filters['is_complex']), fn ($q) => $q->where($col('is_complex'), (bool) $filters['is_complex']))
            ->when(isset($filters['is_demo']), fn ($q) => $q->where($col('is_demo'), (bool) $filters['is_demo']));

        if (filled($filters['q'] ?? null)) {
            $term = '%'.addcslashes(trim($filters['q']), '%_\\').'%';
            $query->where(function (Builder $q) use ($term, $col): void {
                $q->where($col('registry_number'), 'like', $term)
                    ->orWhere($col('cable_code'), 'like', $term)
                    ->orWhere($col('name'), 'like', $term)
                    ->orWhere($col('object_name'), 'like', $term)
                    ->orWhere($col('start_point'), 'like', $term)
                    ->orWhere($col('end_point'), 'like', $term)
                    ->orWhere($col('inventory_number'), 'like', $term)
                    ->orWhereHas('gasPipelineAsset', fn ($g) => $g->where('name', 'like', $term)->orWhere('code', 'like', $term))
                    ->orWhereHas('organization', fn ($o) => $o->where('name', 'like', $term)->orWhere('short_name', 'like', $term))
                    ->orWhereHas('segments', fn ($s) => $s->where('code', 'like', $term)->orWhere('location_description', 'like', $term)->orWhere('name', 'like', $term));
            });
        }

        return $query;
    }

    /**
     * Segment-level status filters apply to the segment itself; the rest go through the line.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function applyToSegments(Builder $query, array $filters): Builder
    {
        $segmentLevel = ['dismantling_status', 'usage_status', 'technical_condition', 'risk_level', 'inspection_status'];

        foreach ($segmentLevel as $field) {
            $query->when($filters[$field] ?? null, fn ($q, $v) => $q->whereIn("cable_segments.{$field}", $v));
        }
        $query->when(isset($filters['is_complex']) && $filters['is_complex'], fn ($q) => $q->complex());

        $lineFilters = array_diff_key($filters, array_flip([...$segmentLevel, 'is_complex', 'has_geometry', 'sort', 'direction', 'per_page', 'bbox']));
        if (array_filter($lineFilters, fn ($v) => $v !== null && $v !== '' && $v !== []) !== []) {
            $query->whereHas('cableLine', fn (Builder $l) => self::applyToLines($l, $lineFilters));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public static function fromRequest(Request $request): array
    {
        return $request->validate(self::rules());
    }
}
