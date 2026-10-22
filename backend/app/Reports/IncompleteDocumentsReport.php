<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\DismantlingOrderStatus;
use App\Enums\DocumentType;
use App\Models\CableLine;
use App\Models\DismantlingOrder;
use App\Models\MaterialReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class IncompleteDocumentsReport extends Report
{
    public function key(): string
    {
        return 'incomplete_documents';
    }

    public function title(): string
    {
        return "Hujjatlari to'liq bo'lmagan yozuvlar";
    }

    public function columns(User $user): array
    {
        return [
            ['key' => 'object_type', 'label' => 'Obyekt turi', 'type' => 'text'],
            ['key' => 'number', 'label' => 'Raqami', 'type' => 'text'],
            ['key' => 'name', 'label' => 'Nomi', 'type' => 'text'],
            ['key' => 'organization', 'label' => 'MGQB', 'type' => 'text'],
            ['key' => 'missing', 'label' => 'Yetishmayotgan hujjat', 'type' => 'text'],
        ];
    }

    public function source(User $user, array $filters): Builder|Collection
    {
        $orgFilter = fn (Builder $q) => $q->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id));
        $missing = fn (Builder $q, string $type) => $q->whereDoesntHave('documents', fn ($d) => $d->where('type', $type)->whereNull('voided_at'));
        $orgName = fn ($model) => $model->organization?->short_name ?? $model->organization?->name;
        $rows = collect();

        foreach (config('aloqa.documents.required.cable_line') as $type) {
            $missing($orgFilter(CableLine::query()->visibleTo($user)->with('organization')), $type)->get()
                ->each(fn (CableLine $l) => $rows->push([
                    'object_type' => 'Kabel liniyasi', 'number' => $l->registry_number, 'name' => $l->name,
                    'organization' => $orgName($l), 'missing' => DocumentType::from($type)->label(),
                ]));
        }

        $orderChecks = [
            [DismantlingOrderStatus::Completed, DocumentType::DismantlingAct],
            [DismantlingOrderStatus::LeftInPlace, DocumentType::LeaveInPlaceDecision],
        ];
        foreach ($orderChecks as [$status, $type]) {
            $missing($orgFilter(DismantlingOrder::query()->visibleTo($user)->where('status', $status->value)->with('organization', 'cableLine')), $type->value)->get()
                ->each(fn (DismantlingOrder $o) => $rows->push([
                    'object_type' => 'Demontaj topshirig\'i', 'number' => $o->number, 'name' => $o->cableLine?->name,
                    'organization' => $orgName($o), 'missing' => $type->label(),
                ]));
        }

        $missing($orgFilter(MaterialReceipt::query()->visibleTo($user)->with('organization')), DocumentType::WarehouseReceipt->value)->get()
            ->each(fn (MaterialReceipt $r) => $rows->push([
                'object_type' => 'Ombor kirimi', 'number' => $r->number, 'name' => $r->warehouse_name,
                'organization' => $orgName($r), 'missing' => DocumentType::WarehouseReceipt->label(),
            ]));

        return $rows->values();
    }

    public function mapRow(mixed $row, User $user): array
    {
        return $row;
    }
}
