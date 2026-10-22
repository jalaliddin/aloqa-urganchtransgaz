<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\InspectionItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class InventoryResultsReport extends Report
{
    public function key(): string
    {
        return 'inventory_results';
    }

    public function title(): string
    {
        return 'Xatlov (inventarizatsiya) natijalari va farqlar';
    }

    public function statusFilter(): ?string
    {
        return 'inspection_status';
    }

    public function dateLabel(): ?string
    {
        return "Ko'rik sanasi";
    }

    public function columns(User $user): array
    {
        return [
            ['key' => 'inspection', 'label' => 'Topshiriq', 'type' => 'text'],
            ['key' => 'inspection_status', 'label' => 'Topshiriq holati', 'type' => 'enum:inspection_status'],
            ['key' => 'organization', 'label' => 'MGQB', 'type' => 'text'],
            ['key' => 'segment', 'label' => 'Uchastka', 'type' => 'text'],
            ['key' => 'line', 'label' => 'Liniya', 'type' => 'text'],
            ['key' => 'inspected_on', 'label' => "Ko'rik sanasi", 'type' => 'date'],
            ['key' => 'recorded_length_m', 'label' => 'Hisobdagi, m', 'type' => 'length'],
            ['key' => 'measured_length_m', 'label' => 'Joyida aniqlangan, m', 'type' => 'length'],
            ['key' => 'length_difference_m', 'label' => 'Farq, m', 'type' => 'length'],
            ['key' => 'exceeds_tolerance', 'label' => 'Chegaradan oshgan', 'type' => 'bool'],
            ['key' => 'difference_reason', 'label' => 'Farq sababi', 'type' => 'text'],
            ['key' => 'technical_condition', 'label' => 'Texnik holat', 'type' => 'enum:technical_condition'],
            ['key' => 'usage_status', 'label' => 'Foydalanish', 'type' => 'enum:usage_status'],
        ];
    }

    public function source(User $user, array $filters): Builder|Collection
    {
        $query = InspectionItem::query()
            ->whereNotNull('recorded_at')
            ->whereHas('inspection', function (Builder $q) use ($user, $filters): void {
                $q->visibleTo($user)
                    ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
                    ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
            })
            ->with(['inspection.organization:id,name,short_name', 'segment.cableLine:id,registry_number,name'])
            ->when($filters['only_discrepancies'] ?? false, fn ($q) => $q->where('length_difference_m', '!=', 0))
            ->orderByDesc('inspected_on');

        return $this->applyDateRange($query, 'inspected_on', $filters);
    }

    public function mapRow(mixed $row, User $user): array
    {
        /** @var InspectionItem $row */
        return [
            'inspection' => $row->inspection->number,
            'inspection_status' => $row->inspection->status->value,
            'organization' => $row->inspection->organization?->short_name ?? $row->inspection->organization?->name,
            'segment' => $row->segment->code,
            'line' => $row->segment->cableLine?->name,
            'inspected_on' => $row->inspected_on?->toDateString(),
            'recorded_length_m' => $row->recorded_length_m,
            'measured_length_m' => $row->measured_length_m,
            'length_difference_m' => $row->length_difference_m,
            'exceeds_tolerance' => $row->exceedsLengthTolerance(),
            'difference_reason' => $row->difference_reason,
            'technical_condition' => $row->technical_condition?->value,
            'usage_status' => $row->usage_status?->value,
        ];
    }
}
