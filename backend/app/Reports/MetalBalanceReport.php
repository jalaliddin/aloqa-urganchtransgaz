<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\Metal;
use App\Models\DismantlingOrder;
use App\Models\User;
use App\Services\MetalMassCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Per dismantling order and metal: theoretical vs. dismantling act vs. warehouse — three separate figures. */
final class MetalBalanceReport extends Report
{
    public function __construct(private readonly MetalMassCalculator $calculator) {}

    public function key(): string
    {
        return 'metal_balance';
    }

    public function title(): string
    {
        return 'Rangli metall balansi: nazariy, dalolatnoma va ombor';
    }

    public function statusFilter(): ?string
    {
        return 'dismantling_order_status';
    }

    public function dateLabel(): ?string
    {
        return 'Demontaj tugagan sana';
    }

    public function columns(User $user): array
    {
        $columns = [
            ['key' => 'number', 'label' => "Demontaj topshirig'i", 'type' => 'text'],
            ['key' => 'organization', 'label' => 'MGQB', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Holat', 'type' => 'enum:dismantling_order_status'],
            ['key' => 'extracted_length_m', 'label' => 'Chiqarilgan uzunlik, m', 'type' => 'length'],
        ];
        foreach (Metal::cases() as $metal) {
            $label = $metal->label();
            array_push(
                $columns,
                ['key' => "{$metal->value}_theoretical", 'label' => "{$label}: nazariy, kg", 'type' => 'mass'],
                ['key' => "{$metal->value}_act", 'label' => "{$label}: dalolatnoma, kg", 'type' => 'mass'],
                ['key' => "{$metal->value}_warehouse", 'label' => "{$label}: ombor, kg", 'type' => 'mass'],
                ['key' => "{$metal->value}_act_diff_kg", 'label' => "{$label}: dalolatnoma − nazariy, kg", 'type' => 'mass'],
                ['key' => "{$metal->value}_act_diff_pct", 'label' => "{$label}: dalolatnoma − nazariy, %", 'type' => 'percent'],
                ['key' => "{$metal->value}_wh_diff_kg", 'label' => "{$label}: ombor − dalolatnoma, kg", 'type' => 'mass'],
                ['key' => "{$metal->value}_wh_diff_pct", 'label' => "{$label}: ombor − dalolatnoma, %", 'type' => 'percent'],
            );
        }

        return $columns;
    }

    public function source(User $user, array $filters): Builder|Collection
    {
        $query = DismantlingOrder::query()->visibleTo($user)
            ->where(fn ($q) => $q->where('actual_length_m', '>', 0)->orWhereNotNull('act_masses_recorded_at'))
            ->with('organization:id,name,short_name')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id');
        $this->applyOrganization($query, $filters);

        return $this->applyDateRange($query, 'actual_end', $filters);
    }

    public function mapRow(mixed $row, User $user): array
    {
        /** @var DismantlingOrder $row */
        $balance = $this->calculator->orderBalance($row);
        $data = [
            'number' => $row->number,
            'organization' => $row->organization?->short_name ?? $row->organization?->name,
            'status' => $row->status->value,
            'extracted_length_m' => $balance['extracted_length_m'],
        ];

        foreach (Metal::cases() as $metal) {
            $m = $balance['metals'][$metal->value];
            $data += [
                "{$metal->value}_theoretical" => $m['theoretical_kg'],
                "{$metal->value}_act" => $m['act_kg'],
                "{$metal->value}_warehouse" => $m['warehouse_kg'],
                "{$metal->value}_act_diff_kg" => $m['act_vs_theoretical']['kg'],
                "{$metal->value}_act_diff_pct" => $m['act_vs_theoretical']['percent'],
                "{$metal->value}_wh_diff_kg" => $m['warehouse_vs_act']['kg'],
                "{$metal->value}_wh_diff_pct" => $m['warehouse_vs_act']['percent'],
            ];
        }

        return $data;
    }

    public function totalColumns(): array
    {
        $columns = [];
        foreach (Metal::cases() as $metal) {
            array_push($columns, "{$metal->value}_theoretical", "{$metal->value}_act", "{$metal->value}_warehouse");
        }

        return $columns;
    }
}
