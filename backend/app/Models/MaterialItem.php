<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Enums\WarehouseTransactionStatus;
use App\Models\Concerns\Auditable;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialItem extends Model
{
    use Auditable;

    protected $fillable = [
        'material_receipt_id', 'cable_segment_id', 'material_type', 'description', 'unit',
        'handed_over_quantity', 'accepted_quantity', 'measured_on', 'storage_location', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'material_type' => MaterialType::class,
            'unit' => MaterialUnit::class,
            'handed_over_quantity' => 'decimal:3',
            'accepted_quantity' => 'decimal:3',
            'measured_on' => 'date',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(MaterialReceipt::class, 'material_receipt_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(CableSegment::class, 'cable_segment_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WarehouseTransaction::class);
    }

    public function difference(): string
    {
        return Decimal::diff($this->accepted_quantity, $this->handed_over_quantity, 3);
    }

    /** Current stock: sum of posted transactions (the receipt itself is a posted +transaction). */
    public function balance(): string
    {
        return Decimal::sum(
            $this->transactions()->where('status', WarehouseTransactionStatus::Posted->value)->pluck('quantity'),
            3,
        );
    }
}
