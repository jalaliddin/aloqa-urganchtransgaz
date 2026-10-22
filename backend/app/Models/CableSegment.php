<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DismantlingStatus;
use App\Enums\GeometrySource;
use App\Enums\LayingType;
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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CableSegment extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $fillable = [
        'cable_line_id', 'organization_id', 'sequence', 'code', 'name', 'start_point', 'end_point', 'location_description',
        'laying_type', 'depth_m', 'design_length_m', 'measured_length_m',
        'geometry', 'geometry_length_m', 'bbox_min_lat', 'bbox_min_lng', 'bbox_max_lat', 'bbox_max_lng',
        'geometry_source', 'geometry_source_note', 'geometry_updated_at',
        'technical_condition', 'usage_status', 'risk_level', 'complexity_flags', 'inspection_status', 'dismantling_status',
        'notes', 'created_by', 'updated_by',
    ];

    /** Mirrors the column defaults so freshly created models are complete without a reload. */
    protected $attributes = [
        'laying_type' => 'underground',
        'technical_condition' => 'unknown',
        'usage_status' => 'unknown',
        'risk_level' => 'low',
        'inspection_status' => 'not_inspected',
        'dismantling_status' => 'not_planned',
    ];

    /** Geometry can be large; the audit log keeps derived values and the source instead. */
    protected array $auditExclude = ['geometry', 'bbox_min_lat', 'bbox_min_lng', 'bbox_max_lat', 'bbox_max_lng'];

    protected function casts(): array
    {
        return [
            'laying_type' => LayingType::class,
            'depth_m' => 'decimal:2',
            'design_length_m' => 'decimal:2',
            'measured_length_m' => 'decimal:2',
            'geometry' => 'array',
            'geometry_length_m' => 'decimal:2',
            'geometry_source' => GeometrySource::class,
            'geometry_updated_at' => 'datetime',
            'technical_condition' => TechnicalCondition::class,
            'usage_status' => UsageStatus::class,
            'risk_level' => RiskLevel::class,
            'complexity_flags' => 'array',
            'inspection_status' => SegmentInspectionStatus::class,
            'dismantling_status' => DismantlingStatus::class,
        ];
    }

    public function cableLine(): BelongsTo
    {
        return $this->belongsTo(CableLine::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function compositions(): MorphMany
    {
        return $this->morphMany(CableMetalComposition::class, 'scope')->latest('id');
    }

    public function massCalculations(): MorphMany
    {
        return $this->morphMany(MetalMassCalculation::class, 'subject')->latest('id');
    }

    public function inspectionItems(): HasMany
    {
        return $this->hasMany(InspectionItem::class);
    }

    public function dismantlingOrders(): BelongsToMany
    {
        return $this->belongsToMany(DismantlingOrder::class, 'dismantling_order_segments')
            ->withPivot('planned_length_m')
            ->withTimestamps();
    }

    public function hasGeometry(): bool
    {
        return ! empty($this->geometry);
    }

    /** Complex / hazardous: any complexity flag or high/critical risk. */
    public function isComplex(): bool
    {
        return ! empty($this->complexity_flags) || $this->risk_level?->isHazardous();
    }

    /** Book length used for comparisons: measured if known, otherwise design length. */
    public function bookLength(): ?string
    {
        return $this->measured_length_m ?? $this->design_length_m;
    }

    public function scopeComplex(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereIn('risk_level', [RiskLevel::High->value, RiskLevel::Critical->value])
            ->orWhereJsonLength('complexity_flags', '>', 0));
    }
}
