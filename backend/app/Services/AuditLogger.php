<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(
        string $event,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
        ?int $organizationId = null,
    ): AuditLog {
        $user = Auth::user();
        $request = app()->bound('request') ? request() : null;
        $hasSession = $request !== null && $request->hasSession();

        return AuditLog::query()->create([
            'user_id' => $user?->getAuthIdentifier(),
            'user_name' => $user?->name,
            'organization_id' => $organizationId ?? $this->organizationIdOf($subject) ?? $user?->organization_id,
            'event' => $event,
            'auditable_type' => $subject ? $this->morphAlias($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'auditable_label' => $subject && method_exists($subject, 'auditLabel') ? mb_substr($subject->auditLabel(), 0, 255) : null,
            'old_values' => $old !== null ? $this->normalize($old) : null,
            'new_values' => $new !== null ? $this->normalize($new) : null,
            'reason' => $reason,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'session_hash' => $hasSession ? hash('sha256', $request->session()->getId()) : null,
            'method' => $request?->method(),
            'url' => $request ? mb_substr($request->path(), 0, 512) : null,
        ]);
    }

    private function organizationIdOf(?Model $subject): ?int
    {
        if ($subject === null) {
            return null;
        }

        $id = $subject->getAttribute('organization_id');

        return $id === null ? null : (int) $id;
    }

    private function morphAlias(Model $model): string
    {
        return array_search($model::class, Relation::morphMap(), true) ?: $model::class;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalize(array $values): array
    {
        return array_map(static fn ($value) => match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => $value,
        }, $values);
    }
}
