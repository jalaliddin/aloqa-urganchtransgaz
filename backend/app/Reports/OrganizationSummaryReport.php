<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\TechnicalCondition;
use App\Enums\UsageStatus;
use App\Models\CableSegment;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\MetalMassCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class OrganizationSummaryReport extends Report
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly MetalMassCalculator $calculator,
    ) {}

    public function key(): string
    {
        return 'by_organization';
    }

    public function title(): string
    {
        return 'MGQB kesimida kabel uzunligi va holati';
    }

    public function columns(User $user): array
    {
        return [
            ['key' => 'name', 'label' => 'MGQB', 'type' => 'text'],
            ['key' => 'lines_count', 'label' => 'Liniyalar soni', 'type' => 'number'],
            ['key' => 'total_length_m', 'label' => 'Reyestrdagi uzunlik, m', 'type' => 'length'],
            ['key' => 'inventoried_length_m', 'label' => 'Inventarizatsiya qilingan, m', 'type' => 'length'],
            ['key' => 'inventory_percent', 'label' => 'Inventarizatsiya, %', 'type' => 'percent'],
            ['key' => 'not_in_use_segments', 'label' => 'Ishlatilmaydigan uchastkalar', 'type' => 'number'],
            ['key' => 'poor_condition_segments', 'label' => 'Yomon/avariya holatidagi uchastkalar', 'type' => 'number'],
            ['key' => 'planned_length_m', 'label' => 'Demontaj rejasi, m', 'type' => 'length'],
            ['key' => 'dismantled_length_m', 'label' => 'Demontaj qilingan, m', 'type' => 'length'],
            ['key' => 'dismantling_percent', 'label' => 'Bajarilish, %', 'type' => 'percent'],
            ['key' => 'aluminium_kg', 'label' => 'Alyuminiy (nazariy), kg', 'type' => 'mass'],
            ['key' => 'lead_kg', 'label' => "Qo'rg'oshin (nazariy), kg", 'type' => 'mass'],
            ['key' => 'copper_kg', 'label' => 'Mis (nazariy), kg', 'type' => 'mass'],
            ['key' => 'metal_unknown_segments', 'label' => "Tarkibi noma'lum uchastkalar", 'type' => 'number'],
        ];
    }

    public function totalColumns(): array
    {
        return ['lines_count', 'total_length_m', 'inventoried_length_m', 'planned_length_m', 'dismantled_length_m', 'aluminium_kg', 'lead_kg', 'copper_kg'];
    }

    public function source(User $user, array $filters): Builder|Collection
    {
        $rows = collect($this->dashboard->summary($user)['by_organization'])
            ->when($filters['organization_id'] ?? null, fn ($c, $id) => $c->where('organization_id', (int) $id));

        $counts = CableSegment::query()->visibleTo($user)
            ->groupBy('organization_id')
            ->selectRaw('organization_id')
            ->selectRaw('SUM(CASE WHEN usage_status = ? THEN 1 ELSE 0 END) as not_in_use', [UsageStatus::NotInUse->value])
            ->selectRaw('SUM(CASE WHEN technical_condition IN (?, ?) THEN 1 ELSE 0 END) as poor', [TechnicalCondition::Poor->value, TechnicalCondition::Critical->value])
            ->toBase()->get()->keyBy('organization_id');

        // Theoretical metal mass per MGQB; a total with unknown parts is not shown as a figure
        $segments = CableSegment::query()->visibleTo($user)->with('cableLine:id,cable_type_id,organization_id')->get()->groupBy('organization_id');
        $metals = $segments->map(fn ($group) => $this->calculator->summarise($group));

        return $rows->map(function (array $row) use ($counts, $metals): array {
            $summary = $metals[$row['organization_id']] ?? null;
            $metal = fn (string $key) => $summary === null ? '0.000' : ($summary['totals'][$key]['complete'] ? $summary['totals'][$key]['known_kg'] : null);

            return [
                ...$row,
                'not_in_use_segments' => (int) ($counts[$row['organization_id']]->not_in_use ?? 0),
                'poor_condition_segments' => (int) ($counts[$row['organization_id']]->poor ?? 0),
                'aluminium_kg' => $metal('aluminium'),
                'lead_kg' => $metal('lead'),
                'copper_kg' => $metal('copper'),
                'metal_unknown_segments' => $summary === null ? 0 : collect($summary['totals'])->flatMap(fn ($t) => $t['unknown_segments'])->unique()->count(),
            ];
        })->values();
    }

    public function mapRow(mixed $row, User $user): array
    {
        return $row;
    }
}
