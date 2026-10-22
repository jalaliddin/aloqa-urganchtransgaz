<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DismantlingEventType;
use App\Enums\DismantlingOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DismantlingEvent extends Model
{
    protected $fillable = [
        'dismantling_order_id', 'type', 'from_status', 'to_status', 'occurred_at', 'cable_segment_id',
        'extracted_length_m', 'description', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => DismantlingEventType::class,
            'from_status' => DismantlingOrderStatus::class,
            'to_status' => DismantlingOrderStatus::class,
            'occurred_at' => 'datetime',
            'extracted_length_m' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(DismantlingOrder::class, 'dismantling_order_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(CableSegment::class, 'cable_segment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
