<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompositionSourceType;
use App\Enums\CompositionStatus;
use App\Enums\Metal;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One version of kg-per-metre values for a cable type, line or segment.
 * Values are a complete set: NULL means "unknown" for this scope (not "inherit"),
 * 0 means "checked, none". Only one approved version per scope is current.
 */
class CableMetalComposition extends Model
{
    use Auditable;

    protected $fillable = [
        'scope_type', 'scope_id', 'aluminium_kg_per_m', 'lead_kg_per_m', 'copper_kg_per_m', 'source_type', 'source_reference',
        'document_id', 'note', 'status', 'entered_by', 'entered_at', 'decided_by', 'decided_at', 'decision_comment',
        'superseded_at', 'superseded_by_id', 'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'aluminium_kg_per_m' => 'decimal:6',
            'lead_kg_per_m' => 'decimal:6',
            'copper_kg_per_m' => 'decimal:6',
            'source_type' => CompositionSourceType::class,
            'status' => CompositionStatus::class,
            'entered_at' => 'datetime',
            'decided_at' => 'datetime',
            'superseded_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    public function scope(): MorphTo
    {
        return $this->morphTo();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function kgPerMetre(Metal $metal): ?string
    {
        return $this->getAttribute($metal->compositionColumn());
    }

    /** Organisation of the scoped record; cable types are shared reference data (null). */
    public function organizationId(): ?int
    {
        $scope = $this->scope;

        return $scope instanceof CableType ? null : $scope?->organization_id;
    }
}
