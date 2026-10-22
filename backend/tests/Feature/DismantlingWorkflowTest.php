<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\DocumentType;
use App\Models\Alert;
use App\Models\CableLine;
use App\Models\DismantlingOrder;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Testing\TestResponse;

class DismantlingWorkflowTest extends FeatureTestCase
{
    private Organization $org;

    private User $manager;

    private User $commission;

    private User $safety;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->org();
        $this->manager = $this->user('mgqb_manager', $this->org);
        $this->commission = $this->user('tech_commission', $this->org);
        $this->safety = $this->user('safety', $this->org);
        $this->director = $this->user('management');
    }

    private function inspectedLine(bool $complex = false): CableLine
    {
        $line = $this->line($this->org, $this->manager, [[
            'laying_type' => $complex ? 'near_building' : 'underground',
            'design_length_m' => '800.00',
            'risk_level' => $complex ? 'high' : 'low',
            'complexity_flags' => $complex ? ['under_building'] : null,
        ]]);
        $this->inspectLine($line, $this->manager, $this->commission);

        return $line->refresh();
    }

    private function createOrder(CableLine $line): int
    {
        $this->login($this->manager);

        return $this->postJson('/api/dismantling-orders', [
            'cable_line_id' => $line->id,
            'segments' => $line->segments()->pluck('id')->map(fn ($id) => ['id' => $id])->all(),
            'work_plan_number' => 'IR-1', 'order_number' => 'B-12', 'order_date' => now()->toDateString(),
            'brigade_name' => 'Brigada 1', 'planned_start' => now()->toDateString(), 'planned_end' => now()->addMonth()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    }

    private function transition(int $id, string $status, ?string $reason = null): TestResponse
    {
        return $this->postJson("/api/dismantling-orders/{$id}/transition", array_filter(['status' => $status, 'reason' => $reason]));
    }

    private function agree(int $id, string $type, User $as): void
    {
        $this->login($as);
        $this->postJson("/api/dismantling-orders/{$id}/approvals", [
            'type' => $type, 'decision' => 'approved', 'reference_number' => 'K-'.$type, 'reference_date' => now()->toDateString(),
        ])->assertOk();
    }

    public function test_happy_path_from_draft_to_completed(): void
    {
        $line = $this->inspectedLine();
        $id = $this->createOrder($line);
        $order = DismantlingOrder::query()->findOrFail($id);
        $this->assertSame('800.00', $order->planned_length_m);
        $this->assertSame('awaiting_review', $line->refresh()->dismantling_status->value);

        $this->transition($id, 'pending_review')->assertOk();
        $this->transition($id, 'pending_agreement')->assertOk()->assertJsonPath('data.status', 'pending_agreement');

        $this->agree($id, 'safety_agreement', $this->safety);
        $this->agree($id, 'operation_agreement', $this->safety);
        $this->attachDocument($order, DocumentType::WorkPermit, $this->manager);

        $this->login($this->director);
        $this->transition($id, 'approved')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('approved', $line->refresh()->dismantling_status->value);

        $this->login($this->manager);
        $this->transition($id, 'in_progress')->assertOk()->assertJsonPath('data.actual_start', now()->toDateString());
        $this->postJson("/api/dismantling-orders/{$id}/events", ['type' => 'progress', 'extracted_length_m' => '500.00'])->assertOk();
        $this->postJson("/api/dismantling-orders/{$id}/events", ['type' => 'progress', 'extracted_length_m' => '295.50'])->assertOk()
            ->assertJsonPath('data.actual_length_m', '795.50');

        // Completion needs the dismantling act
        $this->transition($id, 'completed')->assertStatus(422)->assertJsonPath('code', 'preconditions_not_met');
        $this->attachDocument($order, DocumentType::DismantlingAct, $this->manager);
        $this->transition($id, 'completed')->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $line->refresh()->dismantling_status->value);
        $this->get("/api/dismantling-orders/{$id}/act")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_agreement_stage_requires_an_approved_inspection(): void
    {
        $line = $this->line($this->org, $this->manager);
        $id = $this->createOrder($line);

        $this->transition($id, 'pending_review')->assertOk();
        $response = $this->transition($id, 'pending_agreement')->assertStatus(422);
        $this->assertStringContainsString('inventarizatsiyadan', $response->json('errors.status.0'));
    }

    public function test_work_cannot_be_authorised_without_agreements_and_attempt_is_recorded(): void
    {
        $line = $this->inspectedLine();
        $id = $this->createOrder($line);
        $this->transition($id, 'pending_review')->assertOk();
        $this->transition($id, 'pending_agreement')->assertOk();

        // MGQB manager has no authority to authorise at all
        $this->transition($id, 'approved')->assertForbidden();

        $this->login($this->director);
        $this->transition($id, 'approved')->assertStatus(422)->assertJsonPath('code', 'preconditions_not_met');
        $this->assertTrue(Alert::query()->where('type', AlertType::UnauthorizedStatusChange->value)->where('subject_id', $id)->exists());
        $this->assertSame('pending_agreement', DismantlingOrder::query()->find($id)->status->value);
    }

    public function test_complex_segment_requires_specialist_review(): void
    {
        $line = $this->inspectedLine(complex: true);
        $id = $this->createOrder($line);
        $order = DismantlingOrder::query()->findOrFail($id);
        $this->assertTrue($order->is_complex);
        $this->transition($id, 'pending_review')->assertOk();
        $this->transition($id, 'pending_agreement')->assertOk();
        $this->agree($id, 'safety_agreement', $this->safety);
        $this->agree($id, 'operation_agreement', $this->safety);
        $this->attachDocument($order, DocumentType::WorkPermit, $this->manager);

        $this->login($this->director);
        $response = $this->transition($id, 'approved')->assertStatus(422);
        $this->assertStringContainsString("Mutaxassislar ko'rigi", implode(' ', $response->json('errors.status')));

        // Safety officer may not record the specialist review
        $this->login($this->safety);
        $this->postJson("/api/dismantling-orders/{$id}/approvals", ['type' => 'specialist_review', 'decision' => 'approved'])->assertForbidden();

        $this->agree($id, 'specialist_review', $this->commission);
        $this->login($this->director);
        $this->transition($id, 'approved')->assertOk();
    }

    public function test_revoked_agreement_no_longer_counts(): void
    {
        $line = $this->inspectedLine();
        $id = $this->createOrder($line);
        $this->transition($id, 'pending_review')->assertOk();
        $this->transition($id, 'pending_agreement')->assertOk();
        $this->agree($id, 'safety_agreement', $this->safety);
        $this->agree($id, 'operation_agreement', $this->safety);
        $this->attachDocument(DismantlingOrder::query()->find($id), DocumentType::WorkPermit, $this->manager);

        $approvalId = DismantlingOrder::query()->find($id)->approvals()->where('type', 'safety_agreement')->value('id');
        $this->login($this->safety);
        $this->postJson("/api/dismantling-orders/{$id}/approvals/{$approvalId}/revoke", ['reason' => 'Muddati tugadi'])->assertOk();

        $this->login($this->director);
        $this->transition($id, 'approved')->assertStatus(422);
    }

    public function test_duplicate_order_for_the_same_segment_is_refused_and_flagged(): void
    {
        $line = $this->inspectedLine();
        $this->createOrder($line);

        $this->postJson('/api/dismantling-orders', [
            'cable_line_id' => $line->id,
            'segments' => $line->segments()->pluck('id')->map(fn ($id) => ['id' => $id])->all(),
        ])->assertStatus(422)->assertJsonValidationErrors('segments');

        $this->assertSame(1, DismantlingOrder::query()->count());
        $this->assertTrue(Alert::query()->where('type', AlertType::DuplicateDismantlingOrder->value)->exists());
    }

    public function test_invalid_transition_is_refused(): void
    {
        $line = $this->inspectedLine();
        $id = $this->createOrder($line);

        $this->transition($id, 'completed')->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
        $this->postJson("/api/dismantling-orders/{$id}/events", ['type' => 'progress', 'extracted_length_m' => '10'])->assertStatus(422);
    }

    public function test_suspension_requires_a_reason(): void
    {
        $line = $this->inspectedLine();
        $id = $this->createOrder($line);
        $this->transition($id, 'pending_review')->assertOk();
        $this->transition($id, 'pending_agreement')->assertOk();
        $this->agree($id, 'safety_agreement', $this->safety);
        $this->agree($id, 'operation_agreement', $this->safety);
        $this->attachDocument(DismantlingOrder::query()->find($id), DocumentType::WorkPermit, $this->manager);
        $this->login($this->director);
        $this->transition($id, 'approved')->assertOk();
        $this->login($this->manager);

        $this->transition($id, 'suspended')->assertStatus(422);
        $this->transition($id, 'suspended', "Gaz quvuri yaqinida noma'lum kommunikatsiya topildi")->assertOk()
            ->assertJsonPath('data.suspension_reason', "Gaz quvuri yaqinida noma'lum kommunikatsiya topildi");
        $this->postJson("/api/dismantling-orders/{$id}/events", ['type' => 'incident', 'description' => 'Hodisa qayd etildi'])->assertOk();
    }

    public function test_leave_in_place_requires_decision_and_document(): void
    {
        $line = $this->line($this->org, $this->manager, [['laying_type' => 'near_building', 'risk_level' => 'critical', 'complexity_flags' => ['under_building']]]);
        $id = $this->createOrder($line);
        $order = DismantlingOrder::query()->findOrFail($id);
        $this->transition($id, 'pending_review')->assertOk();
        $this->transition($id, 'leave_in_place_pending', 'Bino ostidan o\'tadi')->assertOk();

        $this->login($this->director);
        $this->transition($id, 'left_in_place')->assertStatus(422);

        $document = $this->attachDocument($order, DocumentType::LeaveInPlaceDecision, $this->director);
        $this->postJson("/api/dismantling-orders/{$id}/approvals", [
            'type' => 'leave_in_place_decision', 'decision' => 'approved', 'comment' => 'Poydevor xavfi', 'document_id' => $document->id,
        ])->assertOk();
        $this->transition($id, 'left_in_place')->assertOk();
        $this->assertSame('left_in_place', $line->refresh()->dismantling_status->value);

        $this->getJson('/api/reports/left_in_place')->assertOk()->assertJsonPath('data.0.basis', 'Poydevor xavfi');
    }

    public function test_cancelling_releases_segments(): void
    {
        $line = $this->inspectedLine();
        $id = $this->createOrder($line);

        $this->transition($id, 'cancelled')->assertStatus(422);
        $this->transition($id, 'cancelled', 'Reja o\'zgardi')->assertOk();
        $this->assertSame('not_planned', $line->refresh()->dismantling_status->value);

        // A new order is now allowed
        $this->createOrder($line);
    }
}
