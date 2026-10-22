<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InspectionMemberRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionMember extends Model
{
    protected $fillable = ['inspection_id', 'user_id', 'full_name', 'position', 'role'];

    protected function casts(): array
    {
        return ['role' => InspectionMemberRole::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
