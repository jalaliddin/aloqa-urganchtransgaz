<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\User;
use App\Notifications\AlertRaised;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * Records discrepancies / situations that need review. Wording is always neutral
 * ("tekshiruv talab qilinadi"); the system never concludes theft or guilt.
 * An open alert with the same fingerprint is refreshed instead of duplicated.
 */
final class AlertService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function raise(
        AlertType $type,
        string $message,
        ?Model $subject = null,
        AlertSeverity $severity = AlertSeverity::Warning,
        array $data = [],
        ?int $organizationId = null,
        ?string $fingerprintSuffix = null,
    ): Alert {
        $subjectType = $subject ? (array_search($subject::class, Relation::morphMap(), true) ?: $subject::class) : null;
        $fingerprint = implode('|', array_filter([$type->value, $subjectType, $subject?->getKey(), $fingerprintSuffix], fn ($v) => $v !== null));
        $organizationId ??= $subject?->getAttribute('organization_id');

        $existing = Alert::query()
            ->where('fingerprint', $fingerprint)
            ->where('status', '!=', AlertStatus::Resolved->value)
            ->first();

        if ($existing !== null) {
            $existing->update(['message' => $message, 'data' => $data, 'severity' => $severity]);

            return $existing;
        }

        $alert = Alert::query()->create([
            'organization_id' => $organizationId,
            'type' => $type,
            'severity' => $severity,
            'status' => AlertStatus::Open,
            'subject_type' => $subjectType,
            'subject_id' => $subject?->getKey(),
            'fingerprint' => mb_substr($fingerprint, 0, 191),
            'title' => $type->label(),
            'message' => $message,
            'data' => $data,
            'triggered_by' => Auth::id(),
        ]);

        $this->notify($alert);

        return $alert;
    }

    public function acknowledge(Alert $alert, User $user, ?string $note = null): Alert
    {
        $alert->update(['status' => AlertStatus::Acknowledged, 'resolution_note' => $note]);

        return $alert;
    }

    public function resolve(Alert $alert, User $user, string $note): Alert
    {
        $alert->update([
            'status' => AlertStatus::Resolved,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ]);
        $this->audit->log('alert_resolved', $alert, null, ['status' => AlertStatus::Resolved->value], $note);

        return $alert;
    }

    private function notify(Alert $alert): void
    {
        $recipients = User::permission('alerts.view')
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => $user->canAccessOrganization($alert->organization_id));

        $admins = User::role('super_admin')->where('is_active', true)->get();

        Notification::send($recipients->merge($admins)->unique('id'), new AlertRaised($alert));
    }
}
