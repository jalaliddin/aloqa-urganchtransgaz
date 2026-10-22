<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LayingType;
use App\Enums\RiskLevel;
use App\Enums\TechnicalCondition;
use App\Enums\UsageStatus;
use App\Models\Concerns\Auditable;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InspectionItem extends Model
{
    use Auditable;

    protected $fillable = [
        'inspection_id', 'cable_segment_id', 'inspected_on', 'recorded_length_m', 'measured_length_m', 'length_difference_m',
        'difference_reason', 'technical_condition', 'usage_status', 'usage_evidence', 'risk_level', 'complexity_flags',
        'laying_type', 'depth_m', 'findings', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'inspected_on' => 'date',
            'recorded_length_m' => 'decimal:2',
            'measured_length_m' => 'decimal:2',
            'length_difference_m' => 'decimal:2',
            'technical_condition' => TechnicalCondition::class,
            'usage_status' => UsageStatus::class,
            'risk_level' => RiskLevel::class,
            'complexity_flags' => 'array',
            'laying_type' => LayingType::class,
            'depth_m' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(CableSegment::class, 'cable_segment_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isRecorded(): bool
    {
        return $this->recorded_at !== null;
    }

    public function exceedsLengthTolerance(): bool
    {
        if ($this->measured_length_m === null || $this->recorded_length_m === null) {
            return false;
        }

        return Decimal::exceedsTolerance(
            $this->recorded_length_m,
            $this->measured_length_m,
            (float) config('aloqa.tolerance.length_m'),
            (float) config('aloqa.tolerance.length_percent'),
        );
    }
}
