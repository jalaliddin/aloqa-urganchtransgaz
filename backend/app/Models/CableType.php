<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompositionStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Cable brand / type dictionary (e.g. "MKSB 4x4x1.2"). Organisation-independent reference data. */
class CableType extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = ['code', 'name', 'brand', 'core_count', 'description', 'is_active', 'is_demo', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_demo' => 'boolean'];
    }

    public function compositions(): MorphMany
    {
        return $this->morphMany(CableMetalComposition::class, 'scope')->latest('id');
    }

    public function currentComposition(): MorphOne
    {
        return $this->morphOne(CableMetalComposition::class, 'scope')
            ->where('status', CompositionStatus::Approved->value);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CableLine::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
