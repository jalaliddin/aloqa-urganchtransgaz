<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WarehouseTransactionStatus;
use App\Enums\WarehouseTransactionType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class WarehouseTransaction extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'material_item_id', 'organization_id', 'type', 'quantity', 'status', 'document_number', 'document_date',
        'counterparty', 'storage_location', 'reason', 'created_by', 'decided_by', 'decided_at', 'decision_comment',
    ];

    protected function casts(): array
    {
        return [
            'type' => WarehouseTransactionType::class,
            'status' => WarehouseTransactionStatus::class,
            'quantity' => 'decimal:3',
            'document_date' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MaterialItem::class, 'material_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
