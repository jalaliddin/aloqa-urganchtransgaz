<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\Metal;
use App\Models\CableLine;
use App\Models\User;
use App\Services\MetalMassCalculator;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Theoretical aluminium / lead / copper mass per line: length_m × kg/m, with grand totals. */
final class MetalContentReport extends Report
{
    public function __construct(private readonly MetalMassCalculator $calculator) {}

    public function key(): string
    {
        return 'metal_content';
    }

    public function title(): string
    {
        return 'Rangli metall tarkibi: nazariy massa';
    }

    public function statusFilter(): ?string
    {
        return 'dismantling_status';
    }

    public function columns(User $user): array
    {
        $columns = [
            ['key' => 'registry_number', 'label' => 'Reyestr raqami', 'type' => 'text'],
            ['key' => 'name', 'label' => 'Liniya', 'type' => 'text'],
            ['key' => 'cable_type', 'label' => 'Kabel turi', 'type' => 'text'],
            ['key' => 'organization', 'label' => 'MGQB', 'type' => 'text'],
            ['key' => 'length_m', 'label' => 'Hisob uzunligi, m', 'type' => 'length'],
        ];
        foreach (Metal::cases() as $metal) {
            $columns[] = ['key' => $metal->value.'_kg_per_m', 'label' => $metal->label().', kg/m', 'type' => 'kg_per_m'];
        }
        foreach (Metal::cases() as $metal) {
            $columns[] = ['key' => $metal->value.'_kg', 'label' => $metal->label().' (nazariy), kg', 'type' => 'mass'];
        }
        $columns[] = ['key' => 'complete', 'label' => "Hisob to'liq", 'type' => 'bool'];
        $columns[] = ['key' => 'unknown', 'label' => "Tarkibi noma'lum uchastkalar", 'type' => 'text'];

        return $columns;
    }

    public function source(User $user, array $filters): Builder|Collection
    {
        $query = CableLine::query()->visibleTo($user)
            ->with(['organization:id,name,short_name', 'cableType:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('dismantling_status', $s))
            ->orderBy('registry_number');

        return $this->applyOrganization($query, $filters);
    }

    public function mapRow(mixed $row, User $user): array
    {
        /** @var CableLine $row */
        $summary = $this->calculator->forLine($row);
        $segments = collect($summary['segments']);
        $lengths = $segments->pluck('length_m');

        $data = [
            'registry_number' => $row->registry_number,
            'name' => $row->name,
            'cable_type' => $row->cableType?->name ?? $row->brand,
            'organization' => $row->organization?->short_name ?? $row->organization?->name,
            'length_m' => $lengths->contains(null) ? null : Decimal::sum($lengths),
        ];

        foreach (Metal::cases() as $metal) {
            $values = $segments->map(fn ($s) => $s['metals'][$metal->value]['kg_per_m'])->unique()->values();
            // A single value for the whole line, "varies" when segments differ, null when unknown
            $data[$metal->value.'_kg_per_m'] = $values->count() > 1 ? 'turlicha (uchastka bo\'yicha)' : $values->first();
        }
        foreach (Metal::cases() as $metal) {
            $total = $summary['totals'][$metal->value];
            // Never present a partial sum as the line's mass
            $data[$metal->value.'_kg'] = $total['complete'] ? $total['known_kg'] : null;
        }
        $data['complete'] = $summary['complete'];
        $data['unknown'] = collect($summary['totals'])
            ->filter(fn ($t) => ! $t['complete'])
            ->map(fn ($t, $metal) => Metal::from($metal)->label().': '.implode(', ', $t['unknown_segments']))
            ->join('; ');

        return $data;
    }

    public function totalColumns(): array
    {
        return ['length_m', ...array_map(fn (Metal $m) => $m->value.'_kg', Metal::cases())];
    }
}
