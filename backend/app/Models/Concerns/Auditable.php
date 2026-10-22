<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\AuditLogger;

/**
 * Writes created / updated / deleted / restored events of the model to audit_logs
 * with old and new values. Business actions (approvals, transitions) log separately
 * with their reason through AuditLogger.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            app(AuditLogger::class)->log('created', $model, null, $model->auditableAttributes($model->getAttributes()));
        });

        static::updated(function (self $model): void {
            $changes = $model->auditableAttributes($model->getChanges());
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($model->auditableAttributes($model->getPrevious()), $changes);
            app(AuditLogger::class)->log('updated', $model, $old, $changes);
        });

        static::deleted(function (self $model): void {
            $event = method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting() ? 'soft_deleted' : 'deleted';
            app(AuditLogger::class)->log($event, $model, $model->auditableAttributes($model->getAttributes()), null);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (self $model): void {
                app(AuditLogger::class)->log('restored', $model, null, null);
            });
        }
    }

    /** @param array<string, mixed> $attributes */
    public function auditableAttributes(array $attributes): array
    {
        $excluded = array_merge(
            ['created_at', 'updated_at', 'password', 'remember_token'],
            property_exists($this, 'auditExclude') ? $this->auditExclude : [],
        );

        return array_diff_key($attributes, array_flip($excluded));
    }

    public function auditLabel(): string
    {
        foreach (['registry_number', 'number', 'code', 'name', 'title'] as $attribute) {
            if (! empty($this->getAttribute($attribute))) {
                return (string) $this->getAttribute($attribute);
            }
        }

        return '#'.$this->getKey();
    }
}
