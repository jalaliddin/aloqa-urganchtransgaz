<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'documentable_type', 'documentable_id', 'type', 'title', 'number', 'document_date',
        'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'description', 'uploaded_by',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected $hidden = ['disk', 'path'];

    protected array $auditExclude = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'document_date' => 'date',
            'size_bytes' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** Organisation scope, plus cable-type documents (shared reference data such as cable passports). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = $user->visibleOrganizationIds();

        return $ids === null
            ? $query
            : $query->where(fn (Builder $q) => $q->whereIn('organization_id', $ids)->orWhere('documentable_type', 'cable_type'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }
}
