<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaterialReceiptStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MaterialReceipt extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'number', 'receipt_date', 'organization_id', 'dismantling_order_id', 'warehouse_name', 'handed_over_by',
        'received_by', 'status', 'discrepancy_explanation', 'discrepancy_decided_by', 'discrepancy_decided_at', 'notes', 'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'status' => MaterialReceiptStatus::class,
            'discrepancy_decided_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    public function dismantlingOrder(): BelongsTo
    {
        return $this->belongsTo(DismantlingOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialItem::class);
    }

    public function handedOverBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_over_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function discrepancyDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discrepancy_decided_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
