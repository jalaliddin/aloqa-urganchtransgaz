<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InspectionStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Inspection extends Model
{
    use Auditable, BelongsToOrganization, HasFactory;

    protected $fillable = [
        'number', 'organization_id', 'title', 'description', 'planned_start', 'planned_end', 'status',
        'created_by', 'submitted_by', 'submitted_at', 'decided_by', 'decided_at', 'decision_comment', 'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'status' => InspectionStatus::class,
            'planned_start' => 'date',
            'planned_end' => 'date',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InspectionItem::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(InspectionMember::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
