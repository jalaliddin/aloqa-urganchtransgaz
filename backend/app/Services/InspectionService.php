<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\InspectionMemberRole;
use App\Enums\InspectionStatus;
use App\Enums\SegmentInspectionStatus;
use App\Enums\UsageStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CableLine;
use App\Models\CableSegment;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Format;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inventory / technical inspection workflow:
 * draft → in_progress → submitted → approved | rejected (→ in_progress again) | cancelled.
 * Findings reach the registry only after approval by an authorised second person.
 */
final class InspectionService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly CableLineAggregator $aggregator,
        private readonly AlertService $alerts,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): Inspection
    {
        $segments = $this->assignableSegments((int) $data['organization_id'], $data['segment_ids']);

        return DB::transaction(function () use ($data, $user, $segments): Inspection {
            $inspection = Inspection::query()->create([
                ...Arr::only($data, ['organization_id', 'title', 'description', 'planned_start', 'planned_end']),
                'number' => $this->sequences->next('INV', 4),
                'status' => InspectionStatus::Draft,
                'created_by' => $user->id,
            ]);

            $this->syncMembers($inspection, $data['members'] ?? []);
            $this->addSegments($inspection, $segments);

            return $inspection;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Inspection $inspection, array $data, User $user): Inspection
    {
        $this->assertEditable($inspection);

        return DB::transaction(function () use ($inspection, $data): Inspection {
            $inspection->update(Arr::only($data, ['title', 'description', 'planned_start', 'planned_end']));

            if (array_key_exists('members', $data)) {
                $this->syncMembers($inspection, $data['members']);
            }

            if (array_key_exists('segment_ids', $data)) {
                $wanted = collect($data['segment_ids'])->map(fn ($id) => (int) $id);
                $current = $inspection->items()->pluck('cable_segment_id');

                $toRemove = $inspection->items()->whereIn('cable_segment_id', $current->diff($wanted))->get();
                if ($toRemove->contains(fn (InspectionItem $item) => $item->isRecorded())) {
                    throw new BusinessRuleException("Natijasi kiritilgan uchastkani topshiriqdan olib tashlab bo'lmaydi.", rule: 'item_recorded');
                }
                foreach ($toRemove as $item) {
                    $segment = $item->segment;
                    $item->delete();
                    $this->restoreSegmentStatus($segment);
                }

                $newIds = $wanted->diff($current)->all();
                if ($newIds !== []) {
                    $this->addSegments($inspection, $this->assignableSegments($inspection->organization_id, $newIds));
                }
            }

            return $inspection->refresh();
        });
    }

    public function start(Inspection $inspection, User $user): Inspection
    {
        if ($inspection->status !== InspectionStatus::Draft) {
            throw new BusinessRuleException('Faqat qoralama holatidagi topshiriqni boshlash mumkin.', rule: 'invalid_status');
        }

        $inspection->update(['status' => InspectionStatus::InProgress]);
        $this->audit->log('inspection_started', $inspection);

        return $inspection;
    }

    /** @param array<string, mixed> $data */
    public function recordItem(InspectionItem $item, array $data, User $user): InspectionItem
    {
        $inspection = $item->inspection;
        if (! in_array($inspection->status, [InspectionStatus::InProgress, InspectionStatus::Rejected], true)) {
            throw new BusinessRuleException("Natijalar faqat \"Ko'rik jarayonda\" yoki qaytarilgan topshiriqda kiritiladi.", rule: 'invalid_status');
        }

        $difference = Decimal::diff($data['measured_length_m'], $item->recorded_length_m);
        $item->fill([
            ...Arr::only($data, [
                'inspected_on', 'measured_length_m', 'difference_reason', 'technical_condition', 'usage_status', 'usage_evidence',
                'risk_level', 'laying_type', 'depth_m', 'findings',
            ]),
            'complexity_flags' => array_values(array_unique($data['complexity_flags'] ?? [])) ?: null,
            'length_difference_m' => $item->recorded_length_m !== null ? $difference : null,
        ]);

        if ($item->exceedsLengthTolerance() && blank($item->difference_reason)) {
            throw ValidationException::withMessages([
                'difference_reason' => [sprintf(
                    'Hisobdagi (%s) va joyida aniqlangan (%s) uzunlik farqi %s — ruxsat etilgan chegaradan katta. Farq sababini kiriting.',
                    Format::metres($item->recorded_length_m), Format::metres($item->measured_length_m), Format::metres($difference),
                )],
            ]);
        }

        $item->fill(['recorded_by' => $user->id, 'recorded_at' => now()])->save();

        return $item;
    }

    public function submit(Inspection $inspection, User $user): Inspection
    {
        if (! in_array($inspection->status, [InspectionStatus::InProgress, InspectionStatus::Rejected], true)) {
            throw new BusinessRuleException("Topshiriqni bu holatda tasdiqlashga yuborib bo'lmaydi.", rule: 'invalid_status');
        }

        $inspection->load(['items.segment', 'items.documents', 'members']);
        $errors = [];

        if (! $inspection->members->contains(fn ($m) => $m->role === InspectionMemberRole::Chair)) {
            $errors['members'][] = 'Komissiya raisi kiritilmagan.';
        }

        foreach ($inspection->items as $item) {
            $key = 'items.'.$item->id;
            $code = $item->segment->code;
            $hasDocument = $item->documents->whereNull('voided_at')->isNotEmpty();

            if (! $item->isRecorded()) {
                $errors[$key][] = "{$code}: tekshiruv natijasi kiritilmagan.";

                continue;
            }
            if ($item->exceedsLengthTolerance() && ! $hasDocument) {
                $errors[$key][] = "{$code}: uzunlik farqi uchun tasdiqlovchi hujjat biriktirilmagan.";
            }
            if ($item->usage_status === UsageStatus::NotInUse && (blank($item->usage_evidence) || ! $hasDocument)) {
                $errors[$key][] = "{$code}: \"Aloqa tizimida ishlatilmaydi\" xulosasi uchun dalil (izoh va hujjat/foto) talab qilinadi.";
            }
        }

        if ($errors !== []) {
            throw new BusinessRuleException("Topshiriqni yuborishdan oldin quyidagilarni to'ldiring.", $errors, 'incomplete_inspection');
        }

        DB::transaction(function () use ($inspection, $user): void {
            $inspection->update([
                'status' => InspectionStatus::Submitted,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ]);
            $this->audit->log('inspection_submitted', $inspection);
        });

        foreach ($inspection->items->filter->exceedsLengthTolerance() as $item) {
            $this->alerts->raise(
                AlertType::LengthDiscrepancy,
                sprintf(
                    '%s: hisobdagi uzunlik %s, joyida aniqlangan %s (farq %s). Sabab: %s',
                    $item->segment->code, Format::metres($item->recorded_length_m), Format::metres($item->measured_length_m), Format::metres($item->length_difference_m), $item->difference_reason,
                ),
                $item->segment,
                data: [
                    'inspection' => $inspection->number,
                    'recorded_length_m' => $item->recorded_length_m,
                    'measured_length_m' => $item->measured_length_m,
                    'difference_m' => $item->length_difference_m,
                ],
                fingerprintSuffix: 'inspection:'.$inspection->id,
            );
        }

        return $inspection;
    }

    public function approve(Inspection $inspection, User $user, ?string $comment): Inspection
    {
        $this->assertDecidable($inspection, $user);

        DB::transaction(function () use ($inspection, $user, $comment): void {
            $inspection->load('items.segment');
            $lines = collect();

            foreach ($inspection->items as $item) {
                $segment = $item->segment;
                $segment->fill(array_filter([
                    'measured_length_m' => $item->measured_length_m,
                    'technical_condition' => $item->technical_condition,
                    'usage_status' => $item->usage_status,
                    'risk_level' => $item->risk_level,
                    'laying_type' => $item->laying_type,
                    'depth_m' => $item->depth_m,
                ], fn ($v) => $v !== null));
                $segment->complexity_flags = $item->complexity_flags;
                $segment->inspection_status = SegmentInspectionStatus::Inspected;
                $segment->save();
                $lines->put($segment->cable_line_id, true);
            }

            $inspection->update([
                'status' => InspectionStatus::Approved,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
            ]);
            $this->audit->log('inspection_approved', $inspection, null, ['status' => InspectionStatus::Approved->value], $comment);

            CableLine::query()->whereIn('id', $lines->keys())->get()->each(fn (CableLine $line) => $this->aggregator->refresh($line));
        });

        return $inspection->refresh();
    }

    public function reject(Inspection $inspection, User $user, string $reason): Inspection
    {
        $this->assertDecidable($inspection, $user);

        $inspection->update([
            'status' => InspectionStatus::Rejected,
            'decided_by' => $user->id,
            'decided_at' => now(),
            'decision_comment' => $reason,
        ]);
        $this->audit->log('inspection_rejected', $inspection, null, ['status' => InspectionStatus::Rejected->value], $reason);

        return $inspection;
    }

    public function cancel(Inspection $inspection, User $user, string $reason): Inspection
    {
        if (! $inspection->status->isOpen()) {
            throw new BusinessRuleException("Tasdiqlangan yoki bekor qilingan topshiriqni bekor qilib bo'lmaydi.", rule: 'invalid_status');
        }

        DB::transaction(function () use ($inspection, $reason): void {
            $inspection->update(['status' => InspectionStatus::Cancelled, 'decision_comment' => $reason]);
            $this->audit->log('inspection_cancelled', $inspection, null, null, $reason);
            $inspection->load('items.segment');
            foreach ($inspection->items as $item) {
                $this->restoreSegmentStatus($item->segment);
            }
        });

        return $inspection;
    }

    private function assertEditable(Inspection $inspection): void
    {
        if (! $inspection->status->isEditable()) {
            throw new BusinessRuleException("Topshiriqni bu holatda tahrirlab bo'lmaydi.", rule: 'invalid_status');
        }
    }

    private function assertDecidable(Inspection $inspection, User $user): void
    {
        if ($inspection->status !== InspectionStatus::Submitted) {
            throw new BusinessRuleException("Faqat tasdiqlashga yuborilgan topshiriq bo'yicha qaror qabul qilinadi.", rule: 'invalid_status');
        }

        if ($inspection->submitted_by === $user->id) {
            $this->alerts->raise(
                AlertType::SegregationOfDuties,
                sprintf("%s: natijani yuborgan foydalanuvchi uni o'zi tasdiqlashga urindi.", $inspection->number),
                $inspection,
                data: ['user_id' => $user->id, 'action' => 'inspection_decision'],
                fingerprintSuffix: 'user:'.$user->id,
            );

            throw new BusinessRuleException(
                "Natijani yuborgan shaxs uni o'zi tasdiqlay olmaydi — boshqa vakolatli foydalanuvchi qaror qabul qilishi kerak.",
                rule: 'segregation_of_duties',
            );
        }
    }

    /**
     * @param  array<int, int|string>  $segmentIds
     * @return Collection<int, CableSegment>
     */
    private function assignableSegments(int $organizationId, array $segmentIds): Collection
    {
        $segments = CableSegment::query()->whereIn('id', $segmentIds)->get();

        if ($segments->count() !== count(array_unique($segmentIds))) {
            throw ValidationException::withMessages(['segment_ids' => ['Tanlangan uchastkalardan ba\'zilari topilmadi.']]);
        }

        $foreign = $segments->where('organization_id', '!=', $organizationId);
        if ($foreign->isNotEmpty()) {
            throw ValidationException::withMessages(['segment_ids' => ['Uchastkalar tanlangan MGQBga tegishli emas: '.$foreign->pluck('code')->join(', ')]]);
        }

        $busy = InspectionItem::query()
            ->whereIn('cable_segment_id', $segments->pluck('id'))
            ->whereHas('inspection', fn ($q) => $q->whereNotIn('status', [InspectionStatus::Approved->value, InspectionStatus::Cancelled->value]))
            ->with('segment', 'inspection')
            ->get();

        if ($busy->isNotEmpty()) {
            throw ValidationException::withMessages(['segment_ids' => [
                'Uchastkalar boshqa ochiq inventarizatsiya topshirig\'ida: '.$busy->map(fn ($i) => $i->segment->code.' ('.$i->inspection->number.')')->join(', '),
            ]]);
        }

        return $segments;
    }

    /** @param Collection<int, CableSegment> $segments */
    private function addSegments(Inspection $inspection, Collection $segments): void
    {
        foreach ($segments as $segment) {
            $inspection->items()->create([
                'cable_segment_id' => $segment->id,
                'recorded_length_m' => $segment->bookLength(),
            ]);
            $segment->update(['inspection_status' => SegmentInspectionStatus::Scheduled]);
        }

        CableLine::query()->whereIn('id', $segments->pluck('cable_line_id')->unique())->get()
            ->each(fn (CableLine $line) => $this->aggregator->refresh($line));
    }

    private function restoreSegmentStatus(CableSegment $segment): void
    {
        $everApproved = $segment->inspectionItems()
            ->whereHas('inspection', fn ($q) => $q->where('status', InspectionStatus::Approved->value))
            ->exists();

        $segment->update([
            'inspection_status' => $everApproved ? SegmentInspectionStatus::Inspected : SegmentInspectionStatus::NotInspected,
        ]);
        $this->aggregator->refresh($segment->cableLine);
    }

    /** @param list<array<string, mixed>> $members */
    private function syncMembers(Inspection $inspection, array $members): void
    {
        $inspection->members()->delete();
        foreach ($members as $member) {
            $inspection->members()->create(Arr::only($member, ['user_id', 'full_name', 'position', 'role']));
        }
    }
}
