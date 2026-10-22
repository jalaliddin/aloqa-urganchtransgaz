<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\ApprovalType;
use App\Enums\DismantlingOrderStatus;
use App\Enums\DocumentType;
use App\Models\DismantlingOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class LeftInPlaceReport extends Report
{
    public function key(): string
    {
        return 'left_in_place';
    }

    public function title(): string
    {
        return 'Joyida qoldirilgan kabel va qaror asoslari';
    }

    public function columns(User $user): array
    {
        return [
            ['key' => 'number', 'label' => 'Topshiriq', 'type' => 'text'],
            ['key' => 'organization', 'label' => 'MGQB', 'type' => 'text'],
            ['key' => 'line', 'label' => 'Liniya', 'type' => 'text'],
            ['key' => 'segments', 'label' => 'Uchastkalar', 'type' => 'text'],
            ['key' => 'planned_length_m', 'label' => 'Uzunlik, m', 'type' => 'length'],
            ['key' => 'status', 'label' => 'Holat', 'type' => 'enum:dismantling_order_status'],
            ['key' => 'decision', 'label' => 'Qaror (raqam, sana)', 'type' => 'text'],
            ['key' => 'decided_by', 'label' => 'Qaror qayd etgan', 'type' => 'text'],
            ['key' => 'basis', 'label' => 'Asos / izoh', 'type' => 'text'],
            ['key' => 'has_document', 'label' => 'Qaror hujjati', 'type' => 'bool'],
        ];
    }

    public function source(User $user, array $filters): Builder|Collection
    {
        $query = DismantlingOrder::query()->visibleTo($user)
            ->whereIn('status', [DismantlingOrderStatus::LeaveInPlacePending->value, DismantlingOrderStatus::LeftInPlace->value])
            ->with(['organization:id,name,short_name', 'cableLine:id,registry_number,name', 'segments:id,code', 'approvals.decider:id,name', 'documents', 'events'])
            ->orderByDesc('updated_at');

        return $this->applyOrganization($query, $filters);
    }

    public function mapRow(mixed $row, User $user): array
    {
        /** @var DismantlingOrder $row */
        $decision = $row->approvals->where('type', ApprovalType::LeaveInPlaceDecision)->whereNull('revoked_at')->sortByDesc('decided_at')->first();

        return [
            'number' => $row->number,
            'organization' => $row->organization?->short_name ?? $row->organization?->name,
            'line' => $row->cableLine?->name,
            'segments' => $row->segments->pluck('code')->join(', '),
            'planned_length_m' => $row->planned_length_m,
            'status' => $row->status->value,
            'decision' => $decision ? trim(($decision->reference_number ?? '').' '.($decision->reference_date?->toDateString() ?? '')) : null,
            'decided_by' => $decision?->decider?->name,
            'basis' => $decision?->comment ?? $row->events->last()?->description,
            'has_document' => $row->documents->where('type', DocumentType::LeaveInPlaceDecision)->whereNull('voided_at')->isNotEmpty(),
        ];
    }
}
