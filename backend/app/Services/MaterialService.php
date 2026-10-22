<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\DismantlingEventType;
use App\Enums\DismantlingOrderStatus;
use App\Enums\MaterialReceiptStatus;
use App\Enums\MaterialType;
use App\Enums\MaterialUnit;
use App\Enums\Metal;
use App\Enums\WarehouseTransactionStatus;
use App\Enums\WarehouseTransactionType;
use App\Exceptions\BusinessRuleException;
use App\Models\DismantlingOrder;
use App\Models\MaterialItem;
use App\Models\MaterialReceipt;
use App\Models\User;
use App\Models\WarehouseTransaction;
use App\Support\Decimal;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Warehouse intake of dismantled materials. Quantities are never silently
 * "equalised": discrepancies are shown, explained and decided by a second person,
 * and corrections are separate, approved transactions.
 */
final class MaterialService
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function receive(array $data, User $receiver): MaterialReceipt
    {
        $order = DismantlingOrder::query()->findOrFail($data['dismantling_order_id']);

        $allowed = [DismantlingOrderStatus::InProgress, DismantlingOrderStatus::PartiallyCompleted, DismantlingOrderStatus::Completed];
        if (! in_array($order->status, $allowed, true)) {
            throw new BusinessRuleException('Materiallar faqat bajarilayotgan yoki bajarilgan demontaj topshirig\'i bo\'yicha qabul qilinadi.', rule: 'invalid_status');
        }

        $this->assertSegregation($order, (int) $data['handed_over_by'], $receiver);

        $segmentIds = collect($data['items'])->pluck('cable_segment_id')->filter()->unique();
        if ($segmentIds->isNotEmpty() && $order->segments()->whereIn('cable_segments.id', $segmentIds)->count() !== $segmentIds->count()) {
            throw ValidationException::withMessages(['items' => ['Material uchastkasi ushbu demontaj topshirig\'iga tegishli emas.']]);
        }

        $receipt = DB::transaction(function () use ($data, $order, $receiver): MaterialReceipt {
            $receipt = MaterialReceipt::query()->create([
                ...Arr::only($data, ['number', 'receipt_date', 'warehouse_name', 'handed_over_by', 'notes']),
                'organization_id' => $order->organization_id,
                'dismantling_order_id' => $order->id,
                'received_by' => $receiver->id,
                'status' => MaterialReceiptStatus::Received,
            ]);

            foreach ($data['items'] as $itemData) {
                $item = $receipt->items()->create(Arr::only($itemData, [
                    'cable_segment_id', 'material_type', 'description', 'unit', 'handed_over_quantity', 'accepted_quantity',
                    'measured_on', 'storage_location', 'notes',
                ]));

                $item->transactions()->create([
                    'organization_id' => $order->organization_id,
                    'type' => WarehouseTransactionType::Receipt,
                    'quantity' => $item->accepted_quantity,
                    'status' => WarehouseTransactionStatus::Posted,
                    'document_number' => $receipt->number,
                    'document_date' => $receipt->receipt_date,
                    'storage_location' => $item->storage_location ?? $receipt->warehouse_name,
                    'created_by' => $receiver->id,
                ]);
            }

            return $receipt;
        });

        $discrepancies = $this->discrepancies($receipt->load('items', 'dismantlingOrder'));
        app(DismantlingWorkflow::class)->checkMetalBalance($receipt->dismantlingOrder);
        if ($discrepancies !== []) {
            $receipt->update(['status' => MaterialReceiptStatus::Discrepancy]);
            $this->alerts->raise(
                AlertType::MaterialDiscrepancy,
                sprintf('%s: miqdorlarda farq aniqlandi — tushuntirish va tasdiq talab qilinadi. %s', $receipt->number, implode(' ', array_column($discrepancies, 'message'))),
                $receipt,
                data: ['discrepancies' => $discrepancies],
            );
        }

        return $receipt->refresh();
    }

    /**
     * Handed-over vs accepted per item, and dismantled length vs. cable metres accepted
     * for the whole order (all receipts).
     *
     * @return list<array{kind: string, message: string, expected: string, actual: string, difference: string}>
     */
    public function discrepancies(MaterialReceipt $receipt): array
    {
        $percent = (float) config('aloqa.tolerance.material_percent');
        $result = [];

        foreach ($receipt->items as $item) {
            if (Decimal::exceedsTolerance($item->handed_over_quantity, $item->accepted_quantity, 0, $percent, 3)) {
                $result[] = [
                    'kind' => 'item',
                    'item_id' => $item->id,
                    'message' => sprintf(
                        '%s: topshirilgan %s %s, qabul qilingan %s %s.',
                        $item->material_type->label(), Format::number($item->handed_over_quantity, 3), $item->unit->label(), Format::number($item->accepted_quantity, 3), $item->unit->label(),
                    ),
                    'expected' => (string) $item->handed_over_quantity,
                    'actual' => (string) $item->accepted_quantity,
                    'difference' => $item->difference(),
                ];
            }
        }

        $order = $receipt->dismantlingOrder;
        if ($order->status === DismantlingOrderStatus::Completed) {
            $acceptedCable = $this->acceptedCableMeters($order);
            if (Decimal::exceedsTolerance($order->actual_length_m, $acceptedCable, (float) config('aloqa.tolerance.length_m'), $percent)) {
                $result[] = [
                    'kind' => 'order',
                    'message' => sprintf('Demontaj qilingan uzunlik %s, omborga qabul qilingan kabel jami %s.', Format::metres($order->actual_length_m), Format::metres($acceptedCable)),
                    'expected' => (string) $order->actual_length_m,
                    'actual' => $acceptedCable,
                    'difference' => Decimal::diff($acceptedCable, $order->actual_length_m),
                ];
            }
        }

        // Non-ferrous metals: warehouse mass (all receipts of the order) vs. the dismantling act
        $balance = app(MetalMassCalculator::class)->orderBalance($order);
        foreach (Metal::cases() as $metal) {
            $row = $balance['metals'][$metal->value];
            if ($row['warehouse_vs_act']['exceeds'] === true) {
                $result[] = [
                    'kind' => 'metal',
                    'metal' => $metal->value,
                    'message' => sprintf(
                        "%s: dalolatnoma bo'yicha %s kg, omborga qabul qilingan %s kg (farq %s kg).",
                        $metal->label(), Format::number($row['act_kg'], 3), Format::number($row['warehouse_kg'], 3), Format::number($row['warehouse_vs_act']['kg'], 3),
                    ),
                    'expected' => $row['act_kg'],
                    'actual' => $row['warehouse_kg'],
                    'difference' => $row['warehouse_vs_act']['kg'],
                ];
            }
        }

        return $result;
    }

    public function acceptedCableMeters(DismantlingOrder $order): string
    {
        return Decimal::sum(
            MaterialItem::query()
                ->whereHas('receipt', fn ($q) => $q->where('dismantling_order_id', $order->id))
                ->whereIn('material_type', [MaterialType::CableWhole->value, MaterialType::CablePiece->value])
                ->where('unit', MaterialUnit::M->value)
                ->pluck('accepted_quantity'),
        );
    }

    public function decideDiscrepancy(MaterialReceipt $receipt, bool $approve, string $explanation, User $user): MaterialReceipt
    {
        if ($receipt->status !== MaterialReceiptStatus::Discrepancy) {
            throw new BusinessRuleException('Bu kirim bo\'yicha qaror talab qilinmaydi.', rule: 'invalid_status');
        }

        if (in_array($user->id, [$receipt->received_by, $receipt->handed_over_by], true)) {
            $this->flagSegregation($receipt, $user, 'material_discrepancy_decision');

            throw new BusinessRuleException('Materialni topshirgan yoki qabul qilgan shaxs farq bo\'yicha qaror qabul qila olmaydi.', rule: 'segregation_of_duties');
        }

        $status = $approve ? MaterialReceiptStatus::DiscrepancyApproved : MaterialReceiptStatus::DiscrepancyRejected;
        $receipt->update([
            'status' => $status,
            'discrepancy_explanation' => $explanation,
            'discrepancy_decided_by' => $user->id,
            'discrepancy_decided_at' => now(),
        ]);
        $this->audit->log('material_discrepancy_decided', $receipt, null, ['status' => $status->value], $explanation);

        return $receipt;
    }

    /** @param array<string, mixed> $data */
    public function move(MaterialItem $item, array $data, User $user): WarehouseTransaction
    {
        $type = WarehouseTransactionType::from($data['type']);
        if ($type === WarehouseTransactionType::Receipt) {
            throw ValidationException::withMessages(['type' => ['Kirim faqat ombor kirim hujjati orqali yaratiladi.']]);
        }

        $quantity = Decimal::of($data['quantity'], 3);
        if ($type !== WarehouseTransactionType::Adjustment) {
            $quantity = $quantity->abs()->negated();
            $balance = Decimal::of($item->balance(), 3);
            if ($balance->plus($quantity)->isNegative()) {
                throw ValidationException::withMessages(['quantity' => ['Miqdor ombordagi qoldiqdan ('.Format::number((string) $balance, 3).' '.$item->unit->label().') ortiq.']]);
            }
        }

        $transaction = $item->transactions()->create([
            ...Arr::only($data, ['document_number', 'document_date', 'counterparty', 'storage_location', 'reason']),
            'organization_id' => $item->receipt->organization_id,
            'type' => $type,
            'quantity' => (string) $quantity,
            // Corrections never apply silently — they wait for a second person's approval
            'status' => $type === WarehouseTransactionType::Adjustment ? WarehouseTransactionStatus::PendingApproval : WarehouseTransactionStatus::Posted,
            'created_by' => $user->id,
        ]);

        return $transaction;
    }

    public function decideTransaction(WarehouseTransaction $transaction, bool $approve, string $comment, User $user): WarehouseTransaction
    {
        if ($transaction->status !== WarehouseTransactionStatus::PendingApproval) {
            throw new BusinessRuleException('Bu harakat bo\'yicha qaror talab qilinmaydi.', rule: 'invalid_status');
        }

        if ($transaction->created_by === $user->id) {
            $this->flagSegregation($transaction, $user, 'warehouse_adjustment_decision');

            throw new BusinessRuleException('Tuzatishni so\'ragan shaxs uni o\'zi tasdiqlay olmaydi.', rule: 'segregation_of_duties');
        }

        if ($approve) {
            $balance = Decimal::of($transaction->item->balance(), 3)->plus(Decimal::of($transaction->quantity, 3));
            if ($balance->isNegative()) {
                throw new BusinessRuleException('Tuzatish natijasida qoldiq manfiy bo\'lib qoladi.', rule: 'negative_balance');
            }
        }

        $status = $approve ? WarehouseTransactionStatus::Posted : WarehouseTransactionStatus::Rejected;
        $transaction->update([
            'status' => $status,
            'decided_by' => $user->id,
            'decided_at' => now(),
            'decision_comment' => $comment,
        ]);
        $this->audit->log('warehouse_adjustment_decided', $transaction, null, ['status' => $status->value], $comment);

        return $transaction;
    }

    /**
     * The person who carried out (or supervised) the dismantling cannot be the
     * one accepting the material into the warehouse.
     */
    private function assertSegregation(DismantlingOrder $order, int $handedOverBy, User $receiver): void
    {
        $violations = [];

        if ($handedOverBy === $receiver->id) {
            $violations[] = 'Topshiruvchi va qabul qiluvchi bir shaxs bo\'la olmaydi.';
        }
        if ($order->work_supervisor_id === $receiver->id || $order->created_by === $receiver->id) {
            $violations[] = 'Demontaj ishiga rahbarlik qilgan / topshiriqni yaratgan shaxs materialni qabul qila olmaydi.';
        }
        if ($order->events()->where('type', DismantlingEventType::Progress->value)->where('user_id', $receiver->id)->exists()) {
            $violations[] = 'Demontaj bajarilishini qayd etgan shaxs materialni qabul qila olmaydi.';
        }

        if ($violations !== []) {
            $this->flagSegregation($order, $receiver, 'material_receipt', $violations);

            throw new BusinessRuleException(implode(' ', $violations), ['received_by' => $violations], 'segregation_of_duties');
        }
    }

    /** @param list<string> $details */
    private function flagSegregation(Model $subject, User $user, string $action, array $details = []): void
    {
        $this->alerts->raise(
            AlertType::SegregationOfDuties,
            sprintf("%s amalni bajarishga urindi (%s). Vazifalarni ajratish qoidasi bo'yicha tekshirish talab qilinadi. %s", $user->name, $action, implode(' ', $details)),
            $subject,
            data: ['user_id' => $user->id, 'action' => $action],
            fingerprintSuffix: $action.':'.$user->id,
        );
    }
}
