<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\DismantlingOrder;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class DismantlingPlanFactReport extends Report
{
    public function key(): string
    {
        return 'dismantling_plan_fact';
    }

    public function title(): string
    {
        return 'Demontaj: reja va fakt';
    }

    public function statusFilter(): ?string
    {
        return 'dismantling_order_status';
    }

    public function dateLabel(): ?string
    {
        return 'Rejalashtirilgan boshlanish sanasi';
    }

    public function columns(User $user): array
    {
        return [
            ['key' => 'number', 'label' => 'Topshiriq', 'type' => 'text'],
            ['key' => 'organization', 'label' => 'MGQB', 'type' => 'text'],
            ['key' => 'line', 'label' => 'Liniya', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Holat', 'type' => 'enum:dismantling_order_status'],
            ['key' => 'is_complex', 'label' => 'Murakkab', 'type' => 'bool'],
            ['key' => 'planned_start', 'label' => 'Reja: boshlanish', 'type' => 'date'],
            ['key' => 'planned_end', 'label' => 'Reja: tugash', 'type' => 'date'],
            ['key' => 'actual_start', 'label' => 'Fakt: boshlanish', 'type' => 'date'],
            ['key' => 'actual_end', 'label' => 'Fakt: tugash', 'type' => 'date'],
            ['key' => 'planned_length_m', 'label' => 'Reja, m', 'type' => 'length'],
            ['key' => 'actual_length_m', 'label' => 'Fakt, m', 'type' => 'length'],
            ['key' => 'difference_m', 'label' => 'Farq, m', 'type' => 'length'],
            ['key' => 'percent', 'label' => 'Bajarilish, %', 'type' => 'percent'],
        ];
    }

    public function source(User $user, array $filters): Builder|Collection
    {
        $query = DismantlingOrder::query()->visibleTo($user)
            ->with(['organization:id,name,short_name', 'cableLine:id,registry_number,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('planned_start');
        $this->applyOrganization($query, $filters);

        return $this->applyDateRange($query, 'planned_start', $filters);
    }

    public function mapRow(mixed $row, User $user): array
    {
        /** @var DismantlingOrder $row */
        return [
            'number' => $row->number,
            'organization' => $row->organization?->short_name ?? $row->organization?->name,
            'line' => $row->cableLine?->registry_number.' — '.$row->cableLine?->name,
            'status' => $row->status->value,
            'is_complex' => $row->is_complex,
            'planned_start' => $row->planned_start?->toDateString(),
            'planned_end' => $row->planned_end?->toDateString(),
            'actual_start' => $row->actual_start?->toDateString(),
            'actual_end' => $row->actual_end?->toDateString(),
            'planned_length_m' => $row->planned_length_m,
            'actual_length_m' => $row->actual_length_m,
            'difference_m' => Decimal::diff($row->actual_length_m, $row->planned_length_m),
            'percent' => Decimal::percent($row->actual_length_m, $row->planned_length_m),
        ];
    }
}
