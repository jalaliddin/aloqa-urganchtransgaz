<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'email', 'phone', 'position', 'password', 'is_active', 'locale',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** @var list<string> */
    protected array $auditExclude = ['last_login_at', 'last_login_ip'];

    /** @var list<int>|null|false false = not resolved yet */
    private array|null|false $visibleOrganizationIdsCache = false;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function canViewAllOrganizations(): bool
    {
        return $this->hasRole('super_admin') || $this->hasPermissionTo('organizations.view_all');
    }

    /**
     * Organisation ids whose data this user may see; null means "all".
     * A user sees their own organisation and its direct sub-units.
     *
     * @return list<int>|null
     */
    public function visibleOrganizationIds(): ?array
    {
        if ($this->visibleOrganizationIdsCache !== false) {
            return $this->visibleOrganizationIdsCache;
        }

        if ($this->canViewAllOrganizations()) {
            return $this->visibleOrganizationIdsCache = null;
        }

        if ($this->organization_id === null) {
            return $this->visibleOrganizationIdsCache = [];
        }

        $children = Organization::query()->where('parent_id', $this->organization_id)->pluck('id')->all();

        return $this->visibleOrganizationIdsCache = array_values(array_unique([$this->organization_id, ...$children]));
    }

    public function canAccessOrganization(?int $organizationId): bool
    {
        $ids = $this->visibleOrganizationIds();

        return $ids === null || ($organizationId !== null && in_array($organizationId, $ids, true));
    }
}
