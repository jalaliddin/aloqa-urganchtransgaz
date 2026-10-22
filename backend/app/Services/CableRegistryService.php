<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\GeometrySource;
use App\Enums\UsageStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CableLine;
use App\Models\CableSegment;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Format;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Registry: cable lines and their segments. Workflow-driven fields
 * (inspection / dismantling status, measured length, "not in use") are never
 * edited directly here — they change only through inspections and dismantling.
 */
final class CableRegistryService
{
    /** Segment fields that only workflows may change. */
    private const WORKFLOW_FIELDS = ['inspection_status', 'dismantling_status', 'measured_length_m'];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly CableLineAggregator $aggregator,
        private readonly GeometryService $geometry,
        private readonly AlertService $alerts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated line data, optional "segments" list
     */
    public function createLine(array $data, User $user): CableLine
    {
        return DB::transaction(function () use ($data, $user): CableLine {
            $segments = Arr::pull($data, 'segments') ?: [[
                'name' => '1-uchastka',
                'start_point' => $data['start_point'] ?? null,
                'end_point' => $data['end_point'] ?? null,
                'design_length_m' => $data['design_length_m'] ?? null,
            ]];

            $line = CableLine::query()->create([
                ...$data,
                'registry_number' => $this->sequences->next('KL'),
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            foreach (array_values($segments) as $segmentData) {
                $this->createSegment($line, $segmentData, $user, refresh: false);
            }

            return $this->aggregator->refresh($line->refresh());
        });
    }

    /** @param array<string, mixed> $data */
    public function updateLine(CableLine $line, array $data, User $user): CableLine
    {
        return DB::transaction(function () use ($line, $data, $user): CableLine {
            $oldDesign = $line->design_length_m;
            $line->fill([...$data, 'updated_by' => $user->id])->save();

            if ($line->wasChanged('organization_id')) {
                $line->segments()->update(['organization_id' => $line->organization_id]);
            }

            if ($line->wasChanged('design_length_m') && $oldDesign !== null && $line->design_length_m !== null
                && Decimal::exceedsTolerance($oldDesign, $line->design_length_m, (float) config('aloqa.tolerance.length_m'), (float) config('aloqa.tolerance.length_percent'))) {
                $this->alerts->raise(
                    AlertType::SuspiciousLengthChange,
                    sprintf("%s: loyiha bo'yicha uzunlik %s dan %s ga o'zgartirildi. Asosni tekshirish tavsiya etiladi.", $line->registry_number, Format::metres($oldDesign), Format::metres($line->design_length_m)),
                    $line,
                    data: ['old' => $oldDesign, 'new' => $line->design_length_m],
                );
            }

            return $line;
        });
    }

    /** Lines with inspection / dismantling history are never deleted — only lines entered by mistake. */
    public function deleteLine(CableLine $line, string $reason, User $user): void
    {
        $hasHistory = $line->dismantlingOrders()->exists()
            || $line->segments()->whereHas('inspectionItems')->exists();

        if ($hasHistory) {
            throw new BusinessRuleException(
                "Liniyada inventarizatsiya yoki demontaj tarixi bor — uni o'chirib bo'lmaydi. Holatni o'zgartirish ish oqimlari orqali amalga oshiriladi.",
                rule: 'has_history',
            );
        }

        DB::transaction(function () use ($line, $reason): void {
            $line->segments()->get()->each->delete();
            $line->delete();
            $this->audit->log('line_deleted', $line, null, null, $reason);
        });
    }

    /** @param array<string, mixed> $data */
    public function createSegment(CableLine $line, array $data, User $user, bool $refresh = true): CableSegment
    {
        $this->guardUsageStatus(null, $data, $line);

        return DB::transaction(function () use ($line, $data, $user, $refresh): CableSegment {
            $sequence = (int) $line->segments()->withTrashed()->max('sequence') + 1;
            $geometry = Arr::pull($data, 'geometry');
            $source = Arr::pull($data, 'geometry_source');

            $segment = new CableSegment([
                ...Arr::except($data, self::WORKFLOW_FIELDS),
                'cable_line_id' => $line->id,
                'organization_id' => $line->organization_id,
                'sequence' => $sequence,
                'code' => sprintf('%s-U%02d', $line->registry_number, $sequence),
                'complexity_flags' => $this->flags($data['complexity_flags'] ?? null),
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            if (! empty($geometry)) {
                $segment->fill([
                    ...$this->geometry->derivedAttributes($this->geometry->normalize($geometry)),
                    'geometry_source' => $source ?? GeometrySource::Manual->value,
                    'geometry_updated_at' => now(),
                ]);
            }

            $segment->save();

            if ($refresh) {
                $this->aggregator->refresh($line);
            }

            return $segment;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateSegment(CableSegment $segment, array $data, User $user): CableSegment
    {
        $this->guardWorkflowFields($segment, $data);
        $this->guardUsageStatus($segment, $data, $segment->cableLine);

        return DB::transaction(function () use ($segment, $data, $user): CableSegment {
            if (array_key_exists('complexity_flags', $data)) {
                $data['complexity_flags'] = $this->flags($data['complexity_flags']);
            }

            $segment->fill([...Arr::except($data, [...self::WORKFLOW_FIELDS, 'geometry']), 'updated_by' => $user->id])->save();
            $this->aggregator->refresh($segment->cableLine);

            return $segment;
        });
    }

    public function deleteSegment(CableSegment $segment, string $reason, User $user): void
    {
        if ($segment->inspectionItems()->exists() || $segment->dismantlingOrders()->exists()) {
            throw new BusinessRuleException("Uchastkada inventarizatsiya yoki demontaj tarixi bor — uni o'chirib bo'lmaydi.", rule: 'has_history');
        }

        $line = $segment->cableLine;
        if ($line->segments()->count() <= 1) {
            throw new BusinessRuleException('Liniyada kamida bitta uchastka qolishi kerak.', rule: 'last_segment');
        }

        DB::transaction(function () use ($segment, $line, $reason): void {
            $segment->delete();
            $this->audit->log('segment_deleted', $segment, null, null, $reason);
            $this->aggregator->refresh($line);
        });
    }

    /**
     * @param  array<string, mixed>  $geometryInput  GeoJSON geometry / feature
     */
    public function updateGeometry(CableSegment $segment, array $geometryInput, GeometrySource $source, ?string $note, User $user): CableSegment
    {
        $geometry = $this->geometry->normalize($geometryInput);
        $comparison = $this->geometry->compare($segment->geometry, $geometry);
        $oldSummary = $this->geometrySummary($segment);

        return DB::transaction(function () use ($segment, $geometry, $source, $note, $user, $comparison, $oldSummary): CableSegment {
            $segment->forceFill([
                ...$this->geometry->derivedAttributes($geometry),
                'geometry_source' => $source,
                'geometry_source_note' => $note,
                'geometry_updated_at' => now(),
                'updated_by' => $user->id,
            ])->saveQuietly();

            $this->audit->log('geometry_updated', $segment, $oldSummary, $this->geometrySummary($segment), $note);

            if ($comparison['suspicious']) {
                $this->alerts->raise(
                    AlertType::SuspiciousGeometryChange,
                    sprintf(
                        "%s: geometriya sezilarli o'zgardi (uzunlik o'zgarishi: %s%%, siljish: %s m). Manbani tekshirish talab qilinadi.",
                        $segment->code,
                        $comparison['length_change_percent'] ?? '—',
                        $comparison['shift_m'] ?? '—',
                    ),
                    $segment,
                    data: $comparison,
                );
            }

            $this->aggregator->refresh($segment->cableLine);

            return $segment;
        });
    }

    public function clearGeometry(CableSegment $segment, string $reason, User $user): CableSegment
    {
        $old = $this->geometrySummary($segment);

        $segment->forceFill([
            'geometry' => null, 'geometry_length_m' => null, 'geometry_source' => null, 'geometry_source_note' => null,
            'bbox_min_lat' => null, 'bbox_min_lng' => null, 'bbox_max_lat' => null, 'bbox_max_lng' => null,
            'geometry_updated_at' => now(), 'updated_by' => $user->id,
        ])->saveQuietly();

        $this->audit->log('geometry_cleared', $segment, $old, null, $reason);
        $this->aggregator->refresh($segment->cableLine);

        return $segment;
    }

    /** @return array<string, mixed>|null */
    private function geometrySummary(CableSegment $segment): ?array
    {
        if (! $segment->hasGeometry()) {
            return null;
        }

        return [
            'type' => $segment->geometry['type'] ?? null,
            'vertices' => $this->geometry->vertexCount($segment->geometry),
            'length_m' => $segment->geometry_length_m,
            'bbox' => [$segment->bbox_min_lng, $segment->bbox_min_lat, $segment->bbox_max_lng, $segment->bbox_max_lat],
            'source' => $segment->geometry_source?->value,
        ];
    }

    /** @param array<string, mixed> $data */
    private function guardWorkflowFields(CableSegment $segment, array $data): void
    {
        foreach (self::WORKFLOW_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $current = $segment->getAttribute($field);
            $current = $current instanceof \BackedEnum ? $current->value : $current;

            if ((string) $current !== (string) $data[$field]) {
                $this->alerts->raise(
                    AlertType::UnauthorizedStatusChange,
                    sprintf("%s: '%s' maydonini ish oqimidan tashqari o'zgartirishga urinish qayd etildi.", $segment->code, $field),
                    $segment,
                    data: ['field' => $field, 'current' => $current, 'attempted' => $data[$field]],
                    fingerprintSuffix: $field,
                );

                throw new BusinessRuleException(
                    "Bu maydon faqat inventarizatsiya yoki demontaj jarayoni orqali o'zgaradi.",
                    [$field => ["Bu maydon faqat inventarizatsiya yoki demontaj jarayoni orqali o'zgaradi."]],
                    'workflow_field',
                );
            }
        }
    }

    /**
     * "Not in use" requires evidence and an authorised approval, so it can only
     * come from an approved inspection.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardUsageStatus(?CableSegment $segment, array $data, CableLine $line): void
    {
        $requested = $data['usage_status'] ?? null;
        $current = $segment?->usage_status?->value;

        if ($requested === UsageStatus::NotInUse->value && $current !== UsageStatus::NotInUse->value) {
            if ($segment !== null) {
                $this->alerts->raise(
                    AlertType::UnauthorizedStatusChange,
                    sprintf('%s: "Aloqa tizimida ishlatilmaydi" holatini dalil va tasdiqsiz belgilashga urinish qayd etildi.', $segment->code),
                    $segment,
                    data: ['field' => 'usage_status', 'attempted' => $requested],
                    fingerprintSuffix: 'usage_status',
                );
            }

            throw new BusinessRuleException(
                '"Aloqa tizimida ishlatilmaydi" holati faqat dalil va tasdiq bilan — tasdiqlangan inventarizatsiya natijasi orqali belgilanadi.',
                ['usage_status' => ['Bu holat faqat tasdiqlangan inventarizatsiya orqali belgilanadi.']],
                'usage_requires_inspection',
            );
        }
    }

    /** @return list<string>|null */
    private function flags(mixed $flags): ?array
    {
        $flags = array_values(array_unique(array_filter((array) $flags)));

        return $flags === [] ? null : $flags;
    }
}
