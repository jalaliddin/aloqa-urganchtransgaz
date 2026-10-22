<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\DismantlingOrderStatus;
use App\Enums\DocumentType;
use App\Models\Alert;
use App\Models\CableLine;
use App\Models\DismantlingOrder;
use App\Models\MaterialReceipt;
use App\Services\AlertService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Raises "missing mandatory document" alerts (spec 6) and resolves them once the
 * document has been attached. Runs from the scheduler; safe to run repeatedly.
 */
class ScanMissingDocumentsCommand extends Command
{
    protected $signature = 'app:scan-missing-documents {--grace-days=3 : Days a new record may stay without its documents}';

    protected $description = "Majburiy hujjatlari yetishmayotgan yozuvlar bo'yicha nomuvofiqliklarni yangilaydi";

    public function handle(AlertService $alerts): int
    {
        $before = now()->subDays((int) $this->option('grace-days'));
        $raised = 0;
        $flagged = [];

        $checks = [
            [CableLine::query()->where('created_at', '<', $before), config('aloqa.documents.required.cable_line', []), fn (CableLine $l) => $l->registry_number],
            [DismantlingOrder::query()->where('status', DismantlingOrderStatus::Completed->value), [DocumentType::DismantlingAct->value], fn (DismantlingOrder $o) => $o->number],
            [DismantlingOrder::query()->where('status', DismantlingOrderStatus::LeftInPlace->value), [DocumentType::LeaveInPlaceDecision->value], fn (DismantlingOrder $o) => $o->number],
            [MaterialReceipt::query()->where('created_at', '<', $before), [DocumentType::WarehouseReceipt->value], fn (MaterialReceipt $r) => $r->number],
        ];

        foreach ($checks as [$query, $types, $label]) {
            foreach ($types as $type) {
                /** @var Builder $query */
                (clone $query)
                    ->whereDoesntHave('documents', fn (Builder $d) => $d->where('type', $type)->whereNull('voided_at'))
                    ->each(function (Model $record) use ($alerts, $type, $label, &$raised, &$flagged): void {
                        $alerts->raise(
                            AlertType::MissingDocument,
                            sprintf('%s: majburiy hujjat — "%s" biriktirilmagan.', $label($record), DocumentType::from($type)->label()),
                            $record,
                            AlertSeverity::Warning,
                            ['document_type' => $type],
                            fingerprintSuffix: $type,
                        );
                        $flagged[] = $record->getMorphClass().'|'.$record->getKey().'|'.$type;
                        $raised++;
                    });
            }
        }

        // Close alerts whose document has since been attached
        $resolved = 0;
        Alert::query()
            ->where('type', AlertType::MissingDocument->value)
            ->where('status', '!=', AlertStatus::Resolved->value)
            ->get()
            ->reject(fn (Alert $a) => in_array($a->subject_type.'|'.$a->subject_id.'|'.($a->data['document_type'] ?? ''), $flagged, true))
            ->each(function (Alert $alert) use (&$resolved): void {
                $alert->update([
                    'status' => AlertStatus::Resolved,
                    'resolved_at' => now(),
                    'resolution_note' => 'Hujjat biriktirilgan — avtomatik yopildi.',
                ]);
                $resolved++;
            });

        $this->info("Yetishmayotgan hujjatlar: {$raised}, avtomatik yopilgan: {$resolved}.");

        return self::SUCCESS;
    }
}
