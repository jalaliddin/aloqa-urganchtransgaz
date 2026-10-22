<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetalCalculationTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Immutable snapshot of a theoretical-mass calculation (calculation history). */
class MetalMassCalculation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'subject_type', 'subject_id', 'composition_id', 'trigger', 'length_m', 'aluminium_kg', 'lead_kg', 'copper_kg',
        'is_complete', 'details', 'calculated_by',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => MetalCalculationTrigger::class,
            'length_m' => 'decimal:2',
            'aluminium_kg' => 'decimal:3',
            'lead_kg' => 'decimal:3',
            'copper_kg' => 'decimal:3',
            'is_complete' => 'boolean',
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function composition(): BelongsTo
    {
        return $this->belongsTo(CableMetalComposition::class, 'composition_id');
    }

    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }
}
