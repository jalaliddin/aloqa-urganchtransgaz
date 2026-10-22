<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\ApprovalType;
use App\Enums\DismantlingEventType;
use App\Enums\DismantlingOrderStatus;
use App\Enums\DismantlingStatus;
use App\Enums\DocumentType;
use App\Enums\Metal;
use App\Enums\MetalCalculationTrigger;
use App\Enums\SegmentInspectionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Approval;
use App\Models\CableLine;
use App\Models\CableSegment;
use App\Models\DismantlingEvent;
use App\Models\DismantlingOrder;
use App\Models\Document;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Format;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dismantling planning and tracking.
 *
 * Safety boundary: the system never grants a work permit and never chooses an
 * excavation method. It only records reviews, agreements and permits entered by
 * authorised people, and refuses status changes that lack them.
 */
final class DismantlingWorkflow
{
    /** Order fields editable while the order is not finished. */
    private const EDITABLE = [
        'work_plan_number', 'order_number', 'order_date', 'brigade_name', 'work_supervisor_id', 'work_supervisor_name',
        'planned_start', 'planned_end', 'notes',
    ];

    /** Approval type → permission required to record it. */
    private const APPROVAL_PERMISSIONS = [
        'safety_agreement' => 'approvals.record_safety',
        'operation_agreement' => 'approvals.record_safety',
        'specialist_review' => 'approvals.record_specialist',
        'leave_in_place_decision' => 'dismantling.authorize',
    ];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly CableLineAggregator $aggregator,
        private readonly AlertService $alerts,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): DismantlingOrder
    {
        $line = CableLine::query()->findOrFail($data['cable_line_id']);
        $segments = $this->resolveSegments($line, $data['segments']);
        $this->assertNoDuplicates($segments);

        return DB::transaction(function () use ($data, $user, $line, $segments): DismantlingOrder {
            $order = DismantlingOrder::query()->create([
                ...Arr::only($data, self::EDITABLE),
                'number' => $this->sequences->next('DM', 4),
                'organization_id' => $line->organization_id,
                'cable_line_id' => $line->id,
                'status' => DismantlingOrderStatus::Draft,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->syncSegments($order, $segments);
            $this->event($order, DismantlingEventType::StatusChange, $user, ['to_status' => DismantlingOrderStatus::Draft]);

            return $order->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(DismantlingOrder $order, array $data, User $user): DismantlingOrder
    {
        if ($order->status->isTerminal()) {
            throw new BusinessRuleException("Yakunlangan topshiriqni tahrirlab bo'lmaydi.", rule: 'invalid_status');
        }

        $segments = null;
        if (array_key_exists('segments', $data)) {
            if (! in_array($order->status, [DismantlingOrderStatus::Draft, DismantlingOrderStatus::PendingReview], true)) {
                throw new BusinessRuleException("Uchastkalar tarkibini faqat qoralama yoki ko'rik bosqichida o'zgartirish mumkin.", rule: 'invalid_status');
            }
            $segments = $this->resolveSegments($order->cableLine, $data['segments']);
            $this->assertNoDuplicates($segments, $order->id);
        }

        return DB::transaction(function () use ($order, $data, $user, $segments): DismantlingOrder {
            $order->fill([...Arr::only($data, self::EDITABLE), 'updated_by' => $user->id])->save();

            if ($segments !== null) {
                $this->syncSegments($order, $segments);
            }

            return $order->refresh();
        });
    }

    public function transition(DismantlingOrder $order, DismantlingOrderStatus $target, User $user, ?string $reason = null): DismantlingOrder
    {
        $from = $order->status;

        if (! $from->canTransitionTo($target)) {
            $this->flagAttempt($order, $user, $target, ["'{$from->label()}' holatidan '{$target->label()}' holatiga o'tish mumkin emas."]);

            throw new BusinessRuleException(
                sprintf("'%s' holatidan '%s' holatiga o'tish mumkin emas.", $from->label(), $target->label()),
                ['status' => ['Ruxsat etilmagan holat o\'zgarishi.']],
                'invalid_transition',
            );
        }

        if (in_array($target, [DismantlingOrderStatus::Approved, DismantlingOrderStatus::LeftInPlace], true)
            && ! $user->can('dismantling.authorize')) {
            $this->flagAttempt($order, $user, $target, ['Foydalanuvchida ruxsat berish vakolati yo\'q.']);

            throw new AuthorizationException;
        }

        $missing = $this->missingPreconditions($order, $target, $reason);
        if ($missing !== []) {
            if (in_array($target, [DismantlingOrderStatus::Approved, DismantlingOrderStatus::InProgress, DismantlingOrderStatus::Completed, DismantlingOrderStatus::LeftInPlace], true)) {
                $this->flagAttempt($order, $user, $target, $missing);
            }

            throw new BusinessRuleException(
                sprintf("'%s' holatiga o'tish uchun shartlar bajarilmagan.", $target->label()),
                ['status' => $missing],
                'preconditions_not_met',
            );
        }

        DB::transaction(function () use ($order, $from, $target, $user, $reason): void {
            $changes = ['status' => $target, 'updated_by' => $user->id];

            if ($target === DismantlingOrderStatus::InProgress && $order->actual_start === null) {
                $changes['actual_start'] = now()->toDateString();
            }
            if (in_array($target, [DismantlingOrderStatus::Completed, DismantlingOrderStatus::LeftInPlace], true)) {
                $changes['actual_end'] = now()->toDateString();
            }
            if ($target === DismantlingOrderStatus::Suspended) {
                $changes['suspension_reason'] = $reason;
            }

            $order->update($changes);
            $this->applySegmentStatus($order, $target);
            $this->event($order, DismantlingEventType::StatusChange, $user, [
                'from_status' => $from, 'to_status' => $target, 'description' => $reason,
            ]);
            $this->audit->log('status_changed', $order, ['status' => $from->value], ['status' => $target->value], $reason);
        });

        if ($target === DismantlingOrderStatus::Completed) {
            $this->checkCompletedLength($order);
        }

        return $order->refresh();
    }

    /** @param array<string, mixed> $data */
    public function recordApproval(DismantlingOrder $order, array $data, User $user): Approval
    {
        $type = ApprovalType::from($data['type']);

        if (! $user->can(self::APPROVAL_PERMISSIONS[$type->value])) {
            throw new AuthorizationException;
        }
        if ($order->status->isTerminal()) {
            throw new BusinessRuleException("Yakunlangan topshiriqqa kelishuv qo'shib bo'lmaydi.", rule: 'invalid_status');
        }
        if (! empty($data['document_id'])) {
            $belongs = Document::query()->whereKey($data['document_id'])
                ->where('documentable_type', $order->getMorphClass())
                ->where('documentable_id', $order->id)
                ->whereNull('voided_at')
                ->exists();
            if (! $belongs) {
                throw ValidationException::withMessages(['document_id' => ['Hujjat ushbu topshiriqqa biriktirilmagan.']]);
            }
        }

        $approval = $order->approvals()->create([
            ...Arr::only($data, ['type', 'decision', 'reference_number', 'reference_date', 'external_party', 'comment', 'document_id']),
            'decided_by' => $user->id,
            'decided_at' => now(),
        ]);
        $this->audit->log('approval_recorded', $order, null, [
            'approval_id' => $approval->id, 'type' => $type->value, 'decision' => $approval->decision->value,
        ], $approval->comment);

        return $approval;
    }

    public function revokeApproval(Approval $approval, string $reason, User $user): Approval
    {
        if (! $user->can(self::APPROVAL_PERMISSIONS[$approval->type->value]) || $approval->revoked_at !== null) {
            throw new AuthorizationException;
        }

        $approval->update(['revoked_at' => now(), 'revoked_by' => $user->id, 'revoke_reason' => $reason]);
        $order = $approval->approvable;
        $this->audit->log('approval_revoked', $order, null, ['approval_id' => $approval->id, 'type' => $approval->type->value], $reason);

        if ($order instanceof DismantlingOrder && in_array($order->status, [DismantlingOrderStatus::Approved, DismantlingOrderStatus::InProgress, DismantlingOrderStatus::PartiallyCompleted], true)) {
            $this->alerts->raise(
                AlertType::UnauthorizedStatusChange,
                sprintf("%s: '%s' bekor qilindi, ammo topshiriq '%s' holatida. Ishni davom ettirish asosini tekshirish talab qilinadi.", $order->number, $approval->type->label(), $order->status->label()),
                $order,
                AlertSeverity::Critical,
                ['approval_id' => $approval->id],
                fingerprintSuffix: 'revoked:'.$approval->id,
            );
        }

        return $approval;
    }

    /** @param array<string, mixed> $data */
    public function recordEvent(DismantlingOrder $order, array $data, User $user): DismantlingEvent
    {
        $type = DismantlingEventType::from($data['type']);

        if ($type === DismantlingEventType::StatusChange) {
            throw ValidationException::withMessages(['type' => ["Holat o'zgarishi alohida amal orqali bajariladi."]]);
        }

        if ($type === DismantlingEventType::Progress) {
            if (! $order->status->acceptsProgress()) {
                throw new BusinessRuleException('Bajarilgan ish faqat "Jarayonda" yoki "Qisman bajarilgan" holatida kiritiladi.', rule: 'invalid_status');
            }
            if (! empty($data['cable_segment_id']) && ! $order->segments()->whereKey($data['cable_segment_id'])->exists()) {
                throw ValidationException::withMessages(['cable_segment_id' => ['Uchastka ushbu topshiriqqa kiritilmagan.']]);
            }
        }

        $event = DB::transaction(function () use ($order, $type, $data, $user): DismantlingEvent {
            $event = $this->event($order, $type, $user, Arr::only($data, ['occurred_at', 'cable_segment_id', 'extracted_length_m', 'description']));

            if ($type === DismantlingEventType::Progress) {
                $order->update([
                    'actual_length_m' => Decimal::sum($order->events()->where('type', DismantlingEventType::Progress->value)->pluck('extracted_length_m')),
                    'updated_by' => $user->id,
                ]);
            }

            return $event;
        });

        if ($type === DismantlingEventType::Progress) {
            $order->refresh();
            if (Decimal::of($order->actual_length_m)->isGreaterThan(Decimal::of($order->planned_length_m))
                && Decimal::exceedsTolerance($order->planned_length_m, $order->actual_length_m, (float) config('aloqa.tolerance.length_m'), (float) config('aloqa.tolerance.length_percent'))) {
                $this->alerts->raise(
                    AlertType::LengthDiscrepancy,
                    sprintf('%s: amalda chiqarilgan uzunlik (%s) rejalashtirilgan (%s) dan ortiq. Tekshirish talab qilinadi.', $order->number, Format::metres($order->actual_length_m), Format::metres($order->planned_length_m)),
                    $order,
                    data: ['planned_length_m' => $order->planned_length_m, 'actual_length_m' => $order->actual_length_m],
                    fingerprintSuffix: 'progress',
                );
            }
        }

        return $event;
    }

    /**
     * Masses actually recovered according to the dismantling act. Stored separately from the
     * theoretical figure; a correction of earlier values needs a reason and stays in the audit log.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordActMasses(DismantlingOrder $order, array $data, User $user): DismantlingOrder
    {
        $allowed = [DismantlingOrderStatus::InProgress, DismantlingOrderStatus::PartiallyCompleted, DismantlingOrderStatus::Completed];
        if (! in_array($order->status, $allowed, true)) {
            throw new BusinessRuleException('Dalolatnoma massasi faqat bajarilayotgan yoki bajarilgan topshiriq uchun kiritiladi.', rule: 'invalid_status');
        }

        $document = Document::query()->whereKey($data['document_id'])
            ->where('documentable_type', $order->getMorphClass())
            ->where('documentable_id', $order->id)
            ->where('type', DocumentType::DismantlingAct->value)
            ->whereNull('voided_at')
            ->first();
        if ($document === null) {
            throw ValidationException::withMessages(['document_id' => ['Ushbu topshiriqqa biriktirilgan demontaj dalolatnomasini tanlang.']]);
        }

        $columns = array_map(fn (Metal $m) => $m->actColumn(), Metal::cases());
        $isCorrection = $order->act_masses_recorded_at !== null;
        if ($isCorrection && blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => ['Avval kiritilgan massani tuzatish uchun sabab kiritilishi shart.']]);
        }

        $old = Arr::only($order->getAttributes(), $columns);
        DB::transaction(function () use ($order, $data, $user, $document, $columns, $old, $isCorrection): void {
            $order->forceFill([
                'act_aluminium_kg' => $data['aluminium_kg'] ?? null,
                'act_lead_kg' => $data['lead_kg'] ?? null,
                'act_copper_kg' => $data['copper_kg'] ?? null,
                'act_masses_document_id' => $document->id,
                'act_masses_recorded_by' => $user->id,
                'act_masses_recorded_at' => now(),
                'updated_by' => $user->id,
            ])->saveQuietly();

            $this->audit->log(
                $isCorrection ? 'act_masses_corrected' : 'act_masses_recorded',
                $order,
                $isCorrection ? $old : null,
                Arr::only($order->getAttributes(), [...$columns, 'act_masses_document_id']),
                $data['reason'] ?? null,
            );
            app(MetalMassCalculator::class)->snapshotOrder($order, MetalCalculationTrigger::ActRecorded, $user);
        });

        $this->checkMetalBalance($order->refresh());

        return $order;
    }

    /** Raises a review alert for every metal whose act mass differs from theory beyond the tolerance. */
    public function checkMetalBalance(DismantlingOrder $order): void
    {
        $balance = app(MetalMassCalculator::class)->orderBalance($order);

        foreach (Metal::cases() as $metal) {
            $row = $balance['metals'][$metal->value];
            foreach (['act_vs_theoretical' => ['act_kg', 'theoretical_kg', 'dalolatnoma', 'nazariy'], 'warehouse_vs_act' => ['warehouse_kg', 'act_kg', 'ombor', 'dalolatnoma']] as $key => [$a, $b, $labelA, $labelB]) {
                if ($row[$key]['exceeds'] !== true) {
                    continue;
                }
                $this->alerts->raise(
                    AlertType::MetalDiscrepancy,
                    sprintf(
                        '%s, %s: %s bo\'yicha %s kg, %s bo\'yicha %s kg (farq %s kg, %s%%). Tekshirish talab qilinadi.',
                        $order->number, $metal->label(), $labelA, Format::number($row[$a], 3), $labelB, Format::number($row[$b], 3),
                        Format::number($row[$key]['kg'], 3), $row[$key]['percent'] === null ? '—' : Format::number($row[$key]['percent'], 2),
                    ),
                    $order,
                    data: ['metal' => $metal->value, 'comparison' => $key, ...$row[$key]],
                    fingerprintSuffix: $metal->value.':'.$key,
                );
            }
        }
    }

    /**
     * Preconditions for entering $target. Returns human-readable missing items.
     *
     * @return list<string>
     */
    public function missingPreconditions(DismantlingOrder $order, DismantlingOrderStatus $target, ?string $reason = null): array
    {
        $missing = [];
        $needsReason = [
            DismantlingOrderStatus::Suspended, DismantlingOrderStatus::Cancelled, DismantlingOrderStatus::LeaveInPlacePending,
        ];

        if (in_array($target, $needsReason, true) && blank($reason)) {
            $missing[] = 'Sabab (izoh) kiritilishi shart.';
        }

        if ($target === DismantlingOrderStatus::PendingAgreement) {
            $notInspected = $order->segments()->where('inspection_status', '!=', SegmentInspectionStatus::Inspected->value)->pluck('code');
            if ($notInspected->isNotEmpty()) {
                $missing[] = 'Uchastkalar tasdiqlangan inventarizatsiyadan o\'tmagan: '.$notInspected->join(', ');
            }
        }

        $needsAgreements = $target === DismantlingOrderStatus::Approved
            || ($target === DismantlingOrderStatus::InProgress && $order->status === DismantlingOrderStatus::Suspended);

        if ($needsAgreements) {
            foreach ($this->requiredApprovals($order) as $type) {
                if (! $order->hasValidApproval($type)) {
                    $missing[] = "Tasdiqlangan \"{$type->label()}\" mavjud emas.";
                }
            }
            if (! $order->hasActiveDocument(DocumentType::WorkPermit->value)) {
                $missing[] = 'Ishga ruxsat hujjati (naryad-dopusk) biriktirilmagan.';
            }
        }

        if ($target === DismantlingOrderStatus::Completed) {
            if (Decimal::of($order->actual_length_m)->isZero()) {
                $missing[] = 'Amalda chiqarilgan uzunlik kiritilmagan.';
            }
            if (! $order->hasActiveDocument(DocumentType::DismantlingAct->value)) {
                $missing[] = 'Demontaj dalolatnomasi biriktirilmagan.';
            }
        }

        if ($target === DismantlingOrderStatus::LeftInPlace) {
            if (! $order->hasValidApproval(ApprovalType::LeaveInPlaceDecision)) {
                $missing[] = "Tasdiqlangan \"Joyida qoldirish to'g'risidagi qaror\" mavjud emas.";
            }
            if (! $order->hasActiveDocument(DocumentType::LeaveInPlaceDecision->value)) {
                $missing[] = 'Joyida qoldirish qarori va asosi hujjati biriktirilmagan.';
            }
        }

        return $missing;
    }

    /** @return list<ApprovalType> */
    public function requiredApprovals(DismantlingOrder $order): array
    {
        $required = [ApprovalType::SafetyAgreement, ApprovalType::OperationAgreement];
        if ($order->is_complex) {
            $required[] = ApprovalType::SpecialistReview;
        }

        return $required;
    }

    /**
     * @param  list<array{id: int, planned_length_m?: string|float|null}>  $input
     * @return Collection<int, array{segment: CableSegment, planned_length_m: string}>
     */
    private function resolveSegments(CableLine $line, array $input): Collection
    {
        $byId = collect($input)->keyBy(fn ($row) => (int) $row['id']);
        $segments = $line->segments()->whereIn('id', $byId->keys())->get();

        if ($segments->count() !== $byId->count()) {
            throw ValidationException::withMessages(['segments' => ['Uchastkalar tanlangan liniyaga tegishli bo\'lishi kerak.']]);
        }

        $finished = $segments->filter(fn (CableSegment $s) => in_array($s->dismantling_status, [DismantlingStatus::Completed, DismantlingStatus::LeftInPlace], true));
        if ($finished->isNotEmpty()) {
            throw ValidationException::withMessages(['segments' => ['Demontaji yakunlangan uchastkalar: '.$finished->pluck('code')->join(', ')]]);
        }

        return $segments->map(fn (CableSegment $segment) => [
            'segment' => $segment,
            'planned_length_m' => (string) ($byId[$segment->id]['planned_length_m'] ?? $segment->bookLength() ?? '0'),
        ])->values();
    }

    /** One segment may belong to only one active order. */
    private function assertNoDuplicates(Collection $segments, ?int $exceptOrderId = null): void
    {
        $ids = $segments->pluck('segment.id');

        $conflicts = DismantlingOrder::query()
            ->active()
            ->when($exceptOrderId, fn ($q) => $q->whereKeyNot($exceptOrderId))
            ->whereHas('segments', fn ($q) => $q->whereIn('cable_segments.id', $ids))
            ->with(['segments' => fn ($q) => $q->whereIn('cable_segments.id', $ids)])
            ->get();

        if ($conflicts->isEmpty()) {
            return;
        }

        $details = $conflicts->map(fn (DismantlingOrder $o) => $o->number.': '.$o->segments->pluck('code')->join(', '))->join('; ');

        foreach ($segments->pluck('segment')->whereIn('id', $conflicts->flatMap->segments->pluck('id')) as $segment) {
            $this->alerts->raise(
                AlertType::DuplicateDismantlingOrder,
                "{$segment->code}: uchastka uchun takroriy demontaj topshirig'i yaratishga urinish qayd etildi ({$details}).",
                $segment,
                data: ['active_orders' => $conflicts->pluck('number')],
            );
        }

        throw ValidationException::withMessages(['segments' => ["Uchastkalar boshqa faol demontaj topshirig'iga kiritilgan: {$details}"]]);
    }

    /** @param Collection<int, array{segment: CableSegment, planned_length_m: string}> $segments */
    private function syncSegments(DismantlingOrder $order, Collection $segments): void
    {
        $previous = $order->segments()->get();

        $order->segments()->sync($segments->mapWithKeys(fn ($row) => [
            $row['segment']->id => ['planned_length_m' => $row['planned_length_m']],
        ])->all());

        $order->update([
            'planned_length_m' => Decimal::sum($segments->pluck('planned_length_m')),
            'is_complex' => $segments->contains(fn ($row) => $row['segment']->isComplex()),
        ]);

        foreach ($previous->whereNotIn('id', $segments->pluck('segment.id')) as $removed) {
            $removed->update(['dismantling_status' => DismantlingStatus::NotPlanned]);
        }

        $this->applySegmentStatus($order, $order->status);
    }

    private function applySegmentStatus(DismantlingOrder $order, DismantlingOrderStatus $status): void
    {
        foreach ($order->segments()->get() as $segment) {
            $segment->update(['dismantling_status' => $status->segmentStatus()]);
        }

        $this->aggregator->refresh($order->cableLine()->first());
    }

    /** @param array<string, mixed> $attributes */
    private function event(DismantlingOrder $order, DismantlingEventType $type, User $user, array $attributes): DismantlingEvent
    {
        return $order->events()->create([
            'type' => $type,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
            'user_id' => $user->id,
            ...Arr::except($attributes, ['occurred_at']),
        ]);
    }

    /** @param list<string> $missing */
    private function flagAttempt(DismantlingOrder $order, User $user, DismantlingOrderStatus $target, array $missing): void
    {
        $this->alerts->raise(
            AlertType::UnauthorizedStatusChange,
            sprintf("%s: '%s' holatiga shartlarsiz o'tishga urinish qayd etildi. %s", $order->number, $target->label(), implode(' ', $missing)),
            $order,
            $target === DismantlingOrderStatus::Approved ? AlertSeverity::Critical : AlertSeverity::Warning,
            ['target' => $target->value, 'missing' => $missing, 'user_id' => $user->id],
            fingerprintSuffix: $target->value,
        );
    }

    private function checkCompletedLength(DismantlingOrder $order): void
    {
        $book = Decimal::sum($order->segments()->get()->map(fn (CableSegment $s) => $s->bookLength()));

        if (Decimal::exceedsTolerance($book, $order->actual_length_m, (float) config('aloqa.tolerance.length_m'), (float) config('aloqa.tolerance.length_percent'))) {
            $this->alerts->raise(
                AlertType::LengthDiscrepancy,
                sprintf('%s: hisobdagi uzunlik %s, amalda chiqarilgan %s (farq %s). Tekshirish talab qilinadi.', $order->number, Format::metres($book), Format::metres($order->actual_length_m), Format::metres(Decimal::diff($order->actual_length_m, $book))),
                $order,
                data: ['book_length_m' => $book, 'actual_length_m' => $order->actual_length_m],
                fingerprintSuffix: 'completed',
            );
        }
    }
}
