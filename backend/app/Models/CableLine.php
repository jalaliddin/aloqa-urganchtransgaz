<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DismantlingStatus;
use App\Enums\LaidYearPrecision;
use App\Enums\RiskLevel;
use App\Enums\SegmentInspectionStatus;
use App\Enums\TechnicalCondition;
use App\Enums\UsageStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CableLine extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, SoftDeletes;

    public const FINANCIAL_FIELDS = ['inventory_number', 'initial_cost', 'accumulated_depreciation', 'residual_value'];

    protected $fillable = [
        'registry_number', 'cable_code', 'name', 'cable_type', 'cable_type_id', 'brand', 'core_count', 'laid_year', 'laid_year_precision',
        'balance_holder_id', 'organization_id', 'gas_pipeline_asset_id', 'object_name', 'start_point', 'end_point',
        'design_length_m', 'measured_length_m',
        'inventory_number', 'initial_cost', 'accumulated_depreciation', 'residual_value',
        'technical_condition', 'usage_status', 'risk_level', 'inspection_status', 'dismantling_status',
        'has_geometry', 'is_complex', 'notes', 'is_demo', 'created_by', 'updated_by',
    ];

    protected $attributes = [
        'laid_year_precision' => 'unknown',
        'technical_condition' => 'unknown',
        'usage_status' => 'unknown',
        'risk_level' => 'low',
        'inspection_status' => 'not_inspected',
        'dismantling_status' => 'not_planned',
        'has_geometry' => false,
        'is_complex' => false,
        'is_demo' => false,
    ];

    protected function casts(): array
    {
        return [
            'laid_year_precision' => LaidYearPrecision::class,
            'design_length_m' => 'decimal:2',
            'measured_length_m' => 'decimal:2',
            'initial_cost' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'residual_value' => 'decimal:2',
            'technical_condition' => TechnicalCondition::class,
            'usage_status' => UsageStatus::class,
            'risk_level' => RiskLevel::class,
            'inspection_status' => SegmentInspectionStatus::class,
            'dismantling_status' => DismantlingStatus::class,
            'has_geometry' => 'boolean',
            'is_complex' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }

    public function balanceHolder(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'balance_holder_id');
    }

    public function cableType(): BelongsTo
    {
        return $this->belongsTo(CableType::class);
    }

    public function compositions(): MorphMany
    {
        return $this->morphMany(CableMetalComposition::class, 'scope')->latest('id');
    }

    public function gasPipelineAsset(): BelongsTo
    {
        return $this->belongsTo(GasPipelineAsset::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(CableSegment::class)->orderBy('sequence');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function dismantlingOrders(): HasMany
    {
        return $this->hasMany(DismantlingOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Human readable laying year, e.g. "1960-yillar" or "Noma'lum". */
    public function laidYearLabel(): string
    {
        if ($this->laid_year === null || $this->laid_year_precision === LaidYearPrecision::Unknown) {
            return __('enums.laid_year_precision.unknown');
        }

        return match ($this->laid_year_precision) {
            LaidYearPrecision::Decade => intdiv($this->laid_year, 10) * 10 .'-yillar',
            LaidYearPrecision::Approximate => '~'.$this->laid_year,
            default => (string) $this->laid_year,
        };
    }

    /** Lines missing at least one mandatory registry document. */
    public function scopeWithIncompleteDocuments(Builder $query): Builder
    {
        $required = config('aloqa.documents.required.cable_line', []);

        return $query->where(function (Builder $q) use ($required): void {
            foreach ($required as $type) {
                $q->orWhereDoesntHave('documents', fn (Builder $d) => $d->where('type', $type)->whereNull('voided_at'));
            }
        });
    }
}
