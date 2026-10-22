<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\MaterialReceiptStatus;
use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Models\DismantlingOrder;
use App\Models\MaterialItem;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class MaterialsReport extends Report
{
    public function key(): string
    {
        return 'materials';
    }

    public function title(): string
    {
        return 'Materiallar: demontaj qilingan va omborga qabul qilingan';
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
        return [
            ['key' => 'number', 'label' => 'Demontaj topshirig\'i', 'type' => 'text'],
            ['key' => 'organization', 'label' => 'MGQB', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Holat', 'type' => 'enum:dismantling_order_status'],
            ['key' => 'dismantled_m', 'label' => 'Demontaj qilingan kabel, m', 'type' => 'length'],
            ['key' => 'handed_over_cable_m', 'label' => 'Topshirilgan kabel, m', 'type' => 'length'],
            ['key' => 'accepted_cable_m', 'label' => 'Omborga qabul qilingan kabel, m', 'type' => 'length'],
            ['key' => 'difference_m', 'label' => 'Farq (qabul − demontaj), m', 'type' => 'length'],
            ['key' => 'aluminium_kg', 'label' => 'Alyuminiy (ombor), kg', 'type' => 'number'],
            ['key' => 'lead_kg', 'label' => "Qo'rg'oshin (ombor), kg", 'type' => 'number'],
            ['key' => 'copper_kg', 'label' => 'Mis (ombor), kg', 'type' => 'number'],
            ['key' => 'scrap_kg', 'label' => 'Boshqa metall chiqindi, kg', 'type' => 'number'],
            ['key' => 'receipts_count', 'label' => 'Kirim hujjatlari', 'type' => 'number'],
            ['key' => 'open_discrepancies', 'label' => 'Hal qilinmagan farqlar', 'type' => 'number'],
        ];
    }

    public function source(User $user, array $filters): Builder|Collection
    {
        $query = DismantlingOrder::query()->visibleTo($user)
            ->where(fn ($q) => $q->whereHas('materialReceipts')->orWhere('actual_length_m', '>', 0))
            ->with(['organization:id,name,short_name', 'materialReceipts.items'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id');
        $this->applyOrganization($query, $filters);

        return $this->applyDateRange($query, 'actual_end', $filters);
    }

    public function mapRow(mixed $row, User $user): array
    {
        /** @var DismantlingOrder $row */
        $items = $row->materialReceipts->flatMap->items;
        $cable = $items->filter(fn (MaterialItem $i) => in_array($i->material_type, [MaterialType::CableWhole, MaterialType::CablePiece], true) && $i->unit === MaterialUnit::M);
        $accepted = Decimal::sum($cable->pluck('accepted_quantity'));

        return [
            'number' => $row->number,
            'organization' => $row->organization?->short_name ?? $row->organization?->name,
            'status' => $row->status->value,
            'dismantled_m' => $row->actual_length_m,
            'handed_over_cable_m' => Decimal::sum($cable->pluck('handed_over_quantity')),
            'accepted_cable_m' => $accepted,
            'difference_m' => Decimal::diff($accepted, $row->actual_length_m),
            'aluminium_kg' => Decimal::sum($items->filter(fn (MaterialItem $i) => $i->material_type === MaterialType::Aluminium && $i->unit === MaterialUnit::Kg)->pluck('accepted_quantity'), 3),
            'lead_kg' => Decimal::sum($items->filter(fn (MaterialItem $i) => $i->material_type === MaterialType::Lead && $i->unit === MaterialUnit::Kg)->pluck('accepted_quantity'), 3),
            'copper_kg' => Decimal::sum($items->filter(fn (MaterialItem $i) => $i->material_type === MaterialType::Copper && $i->unit === MaterialUnit::Kg)->pluck('accepted_quantity'), 3),
            'scrap_kg' => Decimal::sum($items->filter(fn (MaterialItem $i) => $i->material_type === MaterialType::ScrapMetal && $i->unit === MaterialUnit::Kg)->pluck('accepted_quantity'), 3),
            'receipts_count' => $row->materialReceipts->count(),
            'open_discrepancies' => $row->materialReceipts->where('status', MaterialReceiptStatus::Discrepancy)->count(),
        ];
    }
}
