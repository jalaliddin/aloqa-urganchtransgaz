<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = ['parent_id', 'type', 'code', 'name', 'short_name', 'address', 'is_active', 'is_demo'];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function cableLines(): HasMany
    {
        return $this->hasMany(CableLine::class);
    }
}
