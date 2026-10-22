<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\User;
use App\Services\CableTypeStatistics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CableTypeSummaryReport extends Report
{
    public function __construct(private readonly CableTypeStatistics $statistics) {}

    public function key(): string
    {
        return 'by_cable_type';
    }

    public function title(): string
    {
        return 'Kabel turlari kesimida uzunlik, demontaj va rangli metall';
    }

    public function columns(User $user): array
    {
        return [
            ['key' => 'name', 'label' => 'Kabel turi', 'type' => 'text'],
            ['key' => 'lines_count', 'label' => 'Liniyalar soni', 'type' => 'number'],
            ['key' => 'segments_count', 'label' => 'Uchastkalar soni', 'type' => 'number'],
            ['key' => 'total_length_m', 'label' => 'Reyestrdagi uzunlik, m', 'type' => 'length'],
            ['key' => 'share_percent', 'label' => 'Reyestrdagi ulushi, %', 'type' => 'percent'],
            ['key' => 'inventoried_length_m', 'label' => 'Inventarizatsiya qilingan, m', 'type' => 'length'],
            ['key' => 'inventory_percent', 'label' => 'Inventarizatsiya, %', 'type' => 'percent'],
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
        return ['lines_count', 'segments_count', 'total_length_m', 'inventoried_length_m', 'planned_length_m', 'dismantled_length_m', 'aluminium_kg', 'lead_kg', 'copper_kg'];
    }

    public function source(User $user, array $filters): Builder|Collection
    {
        $organizationId = isset($filters['organization_id']) ? (int) $filters['organization_id'] : null;

        return collect($this->statistics->rows($user, $organizationId));
    }

    public function mapRow(mixed $row, User $user): array
    {
        return $row;
    }
}
