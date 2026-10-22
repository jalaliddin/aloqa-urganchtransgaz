<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\CableLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class RegistryReport extends Report
{
    public function key(): string
    {
        return 'registry';
    }

    public function title(): string
    {
        return 'Kabel liniyalari reyestri';
    }

    public function statusFilter(): ?string
    {
        return 'dismantling_status';
    }

    public function dateLabel(): ?string
    {
        return 'Reyestrga kiritilgan sana';
    }

    public function columns(User $user): array
    {
        $columns = [
            ['key' => 'registry_number', 'label' => 'Reyestr raqami', 'type' => 'text'],
            ['key' => 'cable_code', 'label' => 'Kabel ID', 'type' => 'text'],
            ['key' => 'name', 'label' => 'Nomi', 'type' => 'text'],
            ['key' => 'cable_type', 'label' => 'Kabel turi', 'type' => 'text'],
            ['key' => 'brand', 'label' => 'Markasi', 'type' => 'text'],
            ['key' => 'organization', 'label' => "Mas'ul MGQB", 'type' => 'text'],
            ['key' => 'balance_holder', 'label' => 'Balans saqlovchi', 'type' => 'text'],
            ['key' => 'gas_pipeline', 'label' => 'Gaz quvuri', 'type' => 'text'],
            ['key' => 'laid_year', 'label' => 'Yotqizilgan yil', 'type' => 'text'],
            ['key' => 'segments_count', 'label' => 'Uchastkalar', 'type' => 'number'],
            ['key' => 'design_length_m', 'label' => "Loyiha bo'yicha, m", 'type' => 'length'],
            ['key' => 'measured_length_m', 'label' => 'Aniqlangan, m', 'type' => 'length'],
            ['key' => 'usage_status', 'label' => 'Foydalanish holati', 'type' => 'enum:usage_status'],
            ['key' => 'technical_condition', 'label' => 'Texnik holat', 'type' => 'enum:technical_condition'],
            ['key' => 'risk_level', 'label' => 'Xavf darajasi', 'type' => 'enum:risk_level'],
            ['key' => 'inspection_status', 'label' => 'Tekshiruv holati', 'type' => 'enum:segment_inspection_status'],
            ['key' => 'dismantling_status', 'label' => 'Demontaj holati', 'type' => 'enum:dismantling_status'],
            ['key' => 'has_geometry', 'label' => 'Geometriya', 'type' => 'bool'],
            ['key' => 'is_demo', 'label' => 'DEMO', 'type' => 'bool'],
        ];

        if ($user->can('cable_lines.view_financial')) {
            array_push(
                $columns,
                ['key' => 'inventory_number', 'label' => 'Inventar raqami', 'type' => 'text'],
                ['key' => 'initial_cost', 'label' => "Boshlang'ich qiymat", 'type' => 'money'],
                ['key' => 'accumulated_depreciation', 'label' => "Jamg'arilgan amortizatsiya", 'type' => 'money'],
                ['key' => 'residual_value', 'label' => 'Qoldiq qiymat', 'type' => 'money'],
            );
        }

        return $columns;
    }

    public function source(User $user, array $filters): Builder|Collection
    {
        $query = CableLine::query()->visibleTo($user)
            ->with(['organization:id,name,short_name', 'balanceHolder:id,name,short_name', 'gasPipelineAsset:id,name', 'cableType:id,name,brand'])
            ->withCount('segments')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('dismantling_status', $s))
            ->orderBy('registry_number');

        $this->applyOrganization($query, $filters);

        return $this->applyDateRange($query, 'created_at', $filters);
    }

    public function mapRow(mixed $row, User $user): array
    {
        /** @var CableLine $row */
        $data = [
            'registry_number' => $row->registry_number,
            'cable_code' => $row->cable_code,
            'name' => $row->name,
            // Dictionary first; free text only on lines entered before the dictionary existed
            'cable_type' => $row->cableType?->name ?? $row->cable_type,
            'brand' => $row->cableType?->brand ?? $row->brand,
            'organization' => $row->organization?->short_name ?? $row->organization?->name,
            'balance_holder' => $row->balanceHolder?->short_name ?? $row->balanceHolder?->name,
            'gas_pipeline' => $row->gasPipelineAsset?->name,
            'laid_year' => $row->laidYearLabel(),
            'segments_count' => $row->segments_count,
            'design_length_m' => $row->design_length_m,
            'measured_length_m' => $row->measured_length_m,
            'usage_status' => $row->usage_status->value,
            'technical_condition' => $row->technical_condition->value,
            'risk_level' => $row->risk_level->value,
            'inspection_status' => $row->inspection_status->value,
            'dismantling_status' => $row->dismantling_status->value,
            'has_geometry' => $row->has_geometry,
            'is_demo' => $row->is_demo,
        ];

        if ($user->can('cable_lines.view_financial')) {
            $data += [
                'inventory_number' => $row->inventory_number,
                'initial_cost' => $row->initial_cost,
                'accumulated_depreciation' => $row->accumulated_depreciation,
                'residual_value' => $row->residual_value,
            ];
        }

        return $data;
    }
}
