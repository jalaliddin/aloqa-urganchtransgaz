<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\DismantlingEventType;
use App\Enums\DismantlingOrderStatus;
use App\Enums\DocumentType;
use App\Models\Alert;
use App\Models\DismantlingOrder;
use App\Models\MaterialReceipt;
use App\Models\Organization;
use App\Models\User;
use App\Services\DismantlingWorkflow;
use Illuminate\Testing\TestResponse;

class MaterialWarehouseTest extends FeatureTestCase
{
    private Organization $org;

    private User $manager;

    private User $supervisor;

    private User $keeper;

    private DismantlingOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->org();
        $this->manager = $this->user('mgqb_manager', $this->org);
        $this->supervisor = $this->user('mgqb_manager', $this->org);
        $this->keeper = $this->user('warehouse_keeper', $this->org);
        $this->order = $this->completedOrder('1000.00');
    }

    /** Dismantling order completed through the real workflow, with $extracted metres recorded. */
    private function completedOrder(string $extracted): DismantlingOrder
    {
        $workflow = app(DismantlingWorkflow::class);
        $line = $this->line($this->org, $this->manager, [['laying_type' => 'underground', 'design_length_m' => '1000.00']]);
        $this->inspectLine($line, $this->manager, $this->user('tech_commission', $this->org));
        $this->login($this->manager);

        $order = $workflow->create([
            'cable_line_id' => $line->id, 'segments' => [['id' => $line->segments()->value('id')]],
            'work_supervisor_id' => $this->supervisor->id,
        ], $this->manager);
        $workflow->transition($order, DismantlingOrderStatus::PendingReview, $this->manager);
        $workflow->transition($order, DismantlingOrderStatus::PendingAgreement, $this->manager);
        $safety = $this->user('safety', $this->org);
        foreach (['safety_agreement', 'operation_agreement'] as $type) {
            $workflow->recordApproval($order, ['type' => $type, 'decision' => 'approved'], $safety);
        }
        $this->attachDocument($order, DocumentType::WorkPermit, $this->manager);
        $workflow->transition($order, DismantlingOrderStatus::Approved, $this->user('management'));
        $workflow->transition($order, DismantlingOrderStatus::InProgress, $this->manager);
        $workflow->recordEvent($order, ['type' => DismantlingEventType::Progress->value, 'extracted_length_m' => $extracted], $this->supervisor);
        $this->attachDocument($order, DocumentType::DismantlingAct, $this->manager);

        return $workflow->transition($order->refresh(), DismantlingOrderStatus::Completed, $this->manager);
    }

    private function receive(User $handedOver, array $items, string $number = 'OK-1'): TestResponse
    {
        return $this->postJson('/api/material-receipts', [
            'dismantling_order_id' => $this->order->id,
            'number' => $number,
            'receipt_date' => now()->toDateString(),
            'warehouse_name' => 'Markaziy ombor',
            'handed_over_by' => $handedOver->id,
            'items' => $items,
        ]);
    }

    private function cable(string $handedOver, string $accepted): array
    {
        return ['material_type' => 'cable_whole', 'unit' => 'm', 'handed_over_quantity' => $handedOver, 'accepted_quantity' => $accepted, 'measured_on' => now()->toDateString()];
    }

    public function test_receipt_without_discrepancy_is_received(): void
    {
        $this->login($this->keeper);

        $this->receive($this->supervisor, [$this->cable('1000.000', '1000.000')])
            ->assertCreated()->assertJsonPath('data.status', 'received')->assertJsonPath('data.items.0.balance', '1000.000')
            ->assertJsonPath('data.received_by_id', $this->keeper->id);
    }

    public function test_discrepancies_are_shown_not_equalised(): void
    {
        $this->login($this->keeper);

        $response = $this->receive($this->supervisor, [$this->cable('1000.000', '930.000')])
            ->assertCreated()->assertJsonPath('data.status', 'discrepancy');

        // Quantities are kept exactly as entered
        $response->assertJsonPath('data.items.0.handed_over_quantity', '1000.000')
            ->assertJsonPath('data.items.0.accepted_quantity', '930.000')
            ->assertJsonPath('data.items.0.difference', '-70.000');
        $this->assertCount(2, $response->json('data.discrepancies')); // item-level and order-level
        $this->assertTrue(Alert::query()->where('type', AlertType::MaterialDiscrepancy->value)->exists());
    }

    public function test_the_person_who_did_the_dismantling_cannot_receive_the_material(): void
    {
        $this->supervisor->assignRole('warehouse_keeper');
        $this->login($this->supervisor->fresh());

        $this->receive($this->keeper, [$this->cable('1000', '1000')])
            ->assertStatus(422)->assertJsonPath('code', 'segregation_of_duties');
        $this->assertSame(0, MaterialReceipt::query()->count());
        $this->assertTrue(Alert::query()->where('type', AlertType::SegregationOfDuties->value)->exists());
    }

    public function test_hand_over_and_receive_cannot_be_the_same_person(): void
    {
        $this->login($this->keeper);

        $this->receive($this->keeper, [$this->cable('1000', '1000')])->assertStatus(422)->assertJsonPath('code', 'segregation_of_duties');
    }

    public function test_only_warehouse_role_can_receive(): void
    {
        $this->login($this->manager);

        $this->receive($this->supervisor, [$this->cable('1000', '1000')])->assertForbidden();
    }

    public function test_discrepancy_decision_by_an_independent_person(): void
    {
        $this->login($this->keeper);
        $id = $this->receive($this->supervisor, [$this->cable('1000.000', '930.000')])->json('data.id');

        // Receiver may not decide on their own discrepancy, even with the right
        $this->keeper->givePermissionTo('materials.approve_discrepancy');
        $this->login($this->keeper->fresh());
        $this->postJson("/api/material-receipts/{$id}/decision", ['approve' => true, 'explanation' => "Tarozi xatosi aniqlandi va qayta o'lchandi"])
            ->assertStatus(422)->assertJsonPath('code', 'segregation_of_duties');

        $this->login($this->user('accountant'));
        $this->postJson("/api/material-receipts/{$id}/decision", ['approve' => false, 'explanation' => 'Asos yetarli emas, qayta sanash kerak'])
            ->assertOk()->assertJsonPath('data.status', 'discrepancy_rejected')
            ->assertJsonPath('data.items.0.accepted_quantity', '930.000');
    }

    public function test_warehouse_movements_and_approved_adjustments(): void
    {
        $this->login($this->keeper);
        $receipt = $this->receive($this->supervisor, [$this->cable('1000.000', '1000.000')])->json('data');
        $itemId = $receipt['items'][0]['id'];

        $this->postJson("/api/material-items/{$itemId}/transactions", ['type' => 'sale', 'quantity' => '1500', 'document_number' => 'R-1', 'document_date' => now()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->postJson("/api/material-items/{$itemId}/transactions", ['type' => 'sale', 'quantity' => '400', 'document_number' => 'R-1', 'document_date' => now()->toDateString()])
            ->assertOk()->assertJsonPath('data.items.0.balance', '600.000');

        // Corrections never apply silently
        $this->postJson("/api/material-items/{$itemId}/transactions", ['type' => 'adjustment', 'quantity' => '-20'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $response = $this->postJson("/api/material-items/{$itemId}/transactions", ['type' => 'adjustment', 'quantity' => '-20', 'reason' => 'Qayta o\'lchov'])
            ->assertOk()->assertJsonPath('data.items.0.balance', '600.000');
        $adjustmentId = collect($response->json('data.items.0.transactions'))->firstWhere('type', 'adjustment')['id'];

        $this->login($this->user('accountant'));
        $this->postJson("/api/warehouse-transactions/{$adjustmentId}/decision", ['approve' => true, 'comment' => 'Dalolatnoma asosida'])
            ->assertOk()->assertJsonPath('data.items.0.balance', '580.000');
    }

    public function test_receipts_of_other_organisations_are_not_visible(): void
    {
        $this->login($this->keeper);
        $id = $this->receive($this->supervisor, [$this->cable('1000', '1000')])->json('data.id');

        $this->login($this->user('mgqb_manager', $this->org()));
        $this->getJson("/api/material-receipts/{$id}")->assertForbidden();
        $this->getJson('/api/material-receipts')->assertOk()->assertJsonCount(0, 'data');
    }
}
