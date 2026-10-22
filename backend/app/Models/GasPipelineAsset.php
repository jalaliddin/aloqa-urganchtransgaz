<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GasPipelineAsset extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $fillable = ['organization_id', 'code', 'name', 'diameter_mm', 'length_m', 'description', 'is_active', 'is_demo'];

    protected function casts(): array
    {
        return [
            'length_m' => 'decimal:2',
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }
}
