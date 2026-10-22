<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalType;
use App\Enums\DismantlingOrderStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DismantlingOrder extends Model
{
    use Auditable, BelongsToOrganization, HasFactory;

    protected $fillable = [
        'number', 'organization_id', 'cable_line_id', 'work_plan_number', 'order_number', 'order_date', 'brigade_name',
        'work_supervisor_id', 'work_supervisor_name', 'planned_start', 'planned_end', 'actual_start', 'actual_end',
        'planned_length_m', 'actual_length_m', 'status', 'is_complex', 'suspension_reason', 'notes', 'is_demo',
        'act_aluminium_kg', 'act_lead_kg', 'act_copper_kg', 'act_masses_document_id', 'act_masses_recorded_by', 'act_masses_recorded_at',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'planned_start' => 'date',
            'planned_end' => 'date',
            'actual_start' => 'date',
            'actual_end' => 'date',
            'planned_length_m' => 'decimal:2',
            'actual_length_m' => 'decimal:2',
            'act_aluminium_kg' => 'decimal:3',
            'act_lead_kg' => 'decimal:3',
            'act_copper_kg' => 'decimal:3',
            'act_masses_recorded_at' => 'datetime',
            'status' => DismantlingOrderStatus::class,
            'is_complex' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }

    public function cableLine(): BelongsTo
    {
        return $this->belongsTo(CableLine::class);
    }

    public function segments(): BelongsToMany
    {
        return $this->belongsToMany(CableSegment::class, 'dismantling_order_segments')
            ->withPivot('planned_length_m')
            ->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(DismantlingEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function materialReceipts(): HasMany
    {
        return $this->hasMany(MaterialReceipt::class);
    }

    public function actMassesRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'act_masses_recorded_by');
    }

    public function massCalculations(): MorphMany
    {
        return $this->morphMany(MetalMassCalculation::class, 'subject')->latest('id');
    }

    public function workSupervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'work_supervisor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasValidApproval(ApprovalType $type): bool
    {
        return $this->approvals()
            ->where('type', $type->value)
            ->where('decision', ApprovalDecision::Approved->value)
            ->whereNull('revoked_at')
            ->exists();
    }

    public function hasActiveDocument(string $type): bool
    {
        return $this->documents()->where('type', $type)->whereNull('voided_at')->exists();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            DismantlingOrderStatus::Completed->value,
            DismantlingOrderStatus::LeftInPlace->value,
            DismantlingOrderStatus::Cancelled->value,
        ]);
    }
}
