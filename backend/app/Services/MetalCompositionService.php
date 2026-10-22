<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\CompositionStatus;
use App\Enums\MetalCalculationTrigger;
use App\Exceptions\BusinessRuleException;
use App\Models\CableLine;
use App\Models\CableMetalComposition;
use App\Models\CableSegment;
use App\Models\CableType;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * kg/m composition versions. A new value is entered as "pending" and becomes effective
 * only after a second authorised person approves it; the previous approved version is
 * kept as "superseded" (never overwritten). Every approval stores calculation snapshots
 * for the affected segments, so earlier theoretical masses remain traceable.
 */
final class MetalCompositionService
{
    public const SCOPES = [
        'cable_type' => CableType::class,
        'cable_line' => CableLine::class,
        'cable_segment' => CableSegment::class,
    ];

    public function __construct(
        private readonly MetalMassCalculator $calculator,
        private readonly AlertService $alerts,
        private readonly AuditLogger $audit,
    ) {}

    public function resolveScope(string $type, int $id): Model
    {
        $class = self::SCOPES[$type] ?? null;
        if ($class === null) {
            throw ValidationException::withMessages(['scope_type' => ["Noto'g'ri obyekt turi."]]);
        }

        return $class::query()->findOrFail($id);
    }

    /** @param array<string, mixed> $data validated input */
    public function submit(Model $scope, array $data, User $user): CableMetalComposition
    {
        if (! empty($data['document_id'])) {
            $this->assertDocumentBelongs((int) $data['document_id'], $scope);
        }

        $composition = $scope->compositions()->create([
            ...Arr::only($data, ['aluminium_kg_per_m', 'lead_kg_per_m', 'copper_kg_per_m', 'source_type', 'source_reference', 'document_id', 'note']),
            'status' => CompositionStatus::Pending,
            'entered_by' => $user->id,
            'entered_at' => now(),
        ]);
        $this->audit->log('composition_submitted', $composition, null, $this->values($composition), $data['source_reference'] ?? null, $composition->organizationId());

        return $composition;
    }

    public function approve(CableMetalComposition $composition, User $user, ?string $comment): CableMetalComposition
    {
        $this->assertDecidable($composition, $user);

        DB::transaction(function () use ($composition, $user, $comment): void {
            $previous = CableMetalComposition::query()
                ->where('scope_type', $composition->scope_type)
                ->where('scope_id', $composition->scope_id)
                ->where('status', CompositionStatus::Approved->value)
                ->lockForUpdate()
                ->get();

            $composition->update([
                'status' => CompositionStatus::Approved,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
            ]);

            foreach ($previous as $old) {
                $old->update(['status' => CompositionStatus::Superseded, 'superseded_at' => now(), 'superseded_by_id' => $composition->id]);
            }

            $this->audit->log(
                'composition_approved',
                $composition,
                $previous->first() ? $this->values($previous->first()) : null,
                $this->values($composition),
                $comment,
                $composition->organizationId(),
            );

            foreach ($this->affectedSegments($composition) as $segment) {
                $this->calculator->snapshotSegment($segment, MetalCalculationTrigger::CompositionApproved, $user, [
                    'composition_scope' => $composition->scope_type,
                ]);
            }
        });

        return $composition->refresh();
    }

    public function reject(CableMetalComposition $composition, User $user, string $reason): CableMetalComposition
    {
        $this->assertDecidable($composition, $user);

        $composition->update([
            'status' => CompositionStatus::Rejected,
            'decided_by' => $user->id,
            'decided_at' => now(),
            'decision_comment' => $reason,
        ]);
        $this->audit->log('composition_rejected', $composition, null, null, $reason, $composition->organizationId());

        return $composition;
    }

    /**
     * Segments whose effective composition is the given (just approved) version.
     *
     * @return Collection<int, CableSegment>
     */
    public function affectedSegments(CableMetalComposition $composition): Collection
    {
        $query = CableSegment::query()->with('cableLine:id,cable_type_id,organization_id');

        $segments = match ($composition->scope_type) {
            'cable_segment' => $query->whereKey($composition->scope_id)->get(),
            'cable_line' => $query->where('cable_line_id', $composition->scope_id)->get(),
            'cable_type' => $query->whereHas('cableLine', fn ($q) => $q->where('cable_type_id', $composition->scope_id))->get(),
            default => collect(),
        };

        $resolved = $this->calculator->resolve($segments);

        return $segments->filter(fn (CableSegment $s) => $resolved[$s->id]['composition']?->id === $composition->id)->values();
    }

    private function assertDecidable(CableMetalComposition $composition, User $user): void
    {
        if ($composition->status !== CompositionStatus::Pending) {
            throw new BusinessRuleException("Faqat tasdiq kutilayotgan qiymat bo'yicha qaror qabul qilinadi.", rule: 'invalid_status');
        }

        if ($composition->entered_by === $user->id) {
            $this->alerts->raise(
                AlertType::SegregationOfDuties,
                sprintf("Metall tarkibi qiymatini kiritgan foydalanuvchi (%s) uni o'zi tasdiqlashga urindi.", $user->name),
                $composition,
                data: ['user_id' => $user->id, 'action' => 'composition_decision'],
                organizationId: $composition->organizationId(),
                fingerprintSuffix: 'user:'.$user->id,
            );

            throw new BusinessRuleException("Qiymatni kiritgan shaxs uni o'zi tasdiqlay olmaydi — boshqa vakolatli foydalanuvchi tasdiqlashi kerak.", rule: 'segregation_of_duties');
        }
    }

    /** The supporting document must be attached to the scope (or, for a segment, to its line). */
    private function assertDocumentBelongs(int $documentId, Model $scope): void
    {
        $document = Document::query()->whereKey($documentId)->whereNull('voided_at')->first();
        $owners = [[$scope->getMorphClass(), $scope->getKey()]];
        if ($scope instanceof CableSegment) {
            $owners[] = ['cable_line', $scope->cable_line_id];
        }

        $ok = $document !== null && collect($owners)->contains(fn ($o) => $document->documentable_type === $o[0] && (int) $document->documentable_id === (int) $o[1]);
        if (! $ok) {
            throw ValidationException::withMessages(['document_id' => ['Manba hujjati ushbu obyektga biriktirilmagan.']]);
        }
    }

    /** @return array<string, mixed> */
    private function values(CableMetalComposition $composition): array
    {
        return [
            'scope' => $composition->scope_type.':'.$composition->scope_id,
            'aluminium_kg_per_m' => $composition->aluminium_kg_per_m,
            'lead_kg_per_m' => $composition->lead_kg_per_m,
            'copper_kg_per_m' => $composition->copper_kg_per_m,
            'source_type' => $composition->source_type->value,
            'source_reference' => $composition->source_reference,
            'document_id' => $composition->document_id,
        ];
    }
}
