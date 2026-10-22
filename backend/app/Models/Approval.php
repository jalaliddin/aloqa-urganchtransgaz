<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Approval extends Model
{
    use Auditable;

    protected $fillable = [
        'approvable_type', 'approvable_id', 'type', 'decision', 'reference_number', 'reference_date', 'external_party',
        'comment', 'document_id', 'decided_by', 'decided_at', 'revoked_at', 'revoked_by', 'revoke_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => ApprovalType::class,
            'decision' => ApprovalDecision::class,
            'reference_date' => 'date',
            'decided_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
