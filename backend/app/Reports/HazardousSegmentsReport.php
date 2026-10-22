<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\ComplexityFlag;
use App\Models\CableSegment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class HazardousSegmentsReport extends Report
{
    public function key(): string
    {
        return 'hazardous_segments';
    }

    public function title(): string
    {
        return 'Xavfli / murakkab uchastkalar';
    }

    public function statusFilter(): ?string
    {
        return 'dismantling_status';
    }

    public function columns(User $user): array
    {
        return [
            ['key' => 'code', 'label' => 'Uchastka', 'type' => 'text'],
            ['key' => 'line', 'label' => 'Liniya', 'type' => 'text'],
            ['key' => 'organization', 'label' => 'MGQB', 'type' => 'text'],
            ['key' => 'location', 'label' => 'Manzil / joylashuv', 'type' => 'text'],
            ['key' => 'laying_type', 'label' => 'Yotqizish turi', 'type' => 'enum:laying_type'],
            ['key' => 'depth_m', 'label' => 'Chuqurlik, m', 'type' => 'number'],
            ['key' => 'risk_level', 'label' => 'Xavf darajasi', 'type' => 'enum:risk_level'],
            ['key' => 'flags', 'label' => 'Murakkablik belgilari', 'type' => 'text'],
            ['key' => 'dismantling_status', 'label' => 'Demontaj holati', 'type' => 'enum:dismantling_status'],
            ['key' => 'has_geometry', 'label' => 'Geometriya', 'type' => 'bool'],
        ];
    }

    public function source(User $user, array $filters): Builder|Collection
    {
        $query = CableSegment::query()->visibleTo($user)->complex()
            ->with(['cableLine:id,registry_number,name', 'organization:id,name,short_name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('dismantling_status', $s))
            ->orderBy('code');

        return $this->applyOrganization($query, $filters);
    }

    public function mapRow(mixed $row, User $user): array
    {
        /** @var CableSegment $row */
        return [
            'code' => $row->code,
            'line' => $row->cableLine?->name,
            'organization' => $row->organization?->short_name ?? $row->organization?->name,
            'location' => $row->location_description,
            'laying_type' => $row->laying_type->value,
            'depth_m' => $row->depth_m,
            'risk_level' => $row->risk_level->value,
            'flags' => collect($row->complexity_flags ?? [])->map(fn ($f) => ComplexityFlag::tryFrom($f)?->label() ?? $f)->join(', '),
            'dismantling_status' => $row->dismantling_status->value,
            'has_geometry' => $row->hasGeometry(),
        ];
    }
}
