<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\DismantlingEventType;
use App\Enums\DismantlingOrderStatus;
use App\Enums\DocumentType;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\CableLine;
use App\Models\CableMetalComposition;
use App\Models\CableType;
use App\Models\DismantlingOrder;
use App\Models\MetalMassCalculation;
use App\Models\Organization;
use App\Models\User;
use App\Services\DismantlingWorkflow;
use App\Services\MetalCompositionService;
use Illuminate\Database\Eloquent\Model;

/** Non-ferrous metal content (kg/m), theoretical masses and the three-way metal balance (spec 4.2, 4.6, 8, 11). */
class MetalCompositionTest extends FeatureTestCase
{
    private Organization $org;

    private User $at;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->org();
        $this->at = $this->user('at_service');
        $this->approver = $this->user('management');
    }

    private function type(): CableType
    {
        return CableType::query()->create(['code' => 'T-'.uniqid(), 'name' => 'TEST MKSB 4x4x1.2', 'is_demo' => true]);
    }

    /** @param array<string, ?string> $kg */
    private function approved(Model $scope, array $kg): CableMetalComposition
    {
        $service = app(MetalCompositionService::class);
        $composition = $service->submit($scope, [...$kg, 'source_type' => 'brand_normative', 'source_reference' => 'TEST normativ'], $this->at);

        return $service->approve($composition, $this->approver, null);
    }

    private function lineOfType(CableType $type, string $length = '1234.56', int $segments = 1): CableLine
    {
        $rows = array_fill(0, $segments, ['laying_type' => 'underground', 'design_length_m' => $length]);

        return $this->line($this->org, $this->at, $rows, ['cable_type_id' => $type->id]);
    }

    private function payload(Model $scope, array $overrides = []): array
    {
        return [
            'scope_type' => $scope->getMorphClass(),
            'scope_id' => $scope->getKey(),
            'aluminium_kg_per_m' => '0.4215',
            'lead_kg_per_m' => null,
            'copper_kg_per_m' => '0',
            'source_type' => 'cable_passport',
            'source_reference' => 'Kabel pasporti № 17',
            ...$overrides,
        ];
    }

    public function test_kg_per_metre_values_are_validated(): void
    {
        $type = $this->type();
        $this->login($this->at);

        $this->postJson('/api/metal-compositions', $this->payload($type, ['aluminium_kg_per_m' => '-0.1']))
            ->assertStatus(422)->assertJsonValidationErrors('aluminium_kg_per_m');
        $this->postJson('/api/metal-compositions', $this->payload($type, ['aluminium_kg_per_m' => '0.1234567']))
            ->assertStatus(422)->assertJsonValidationErrors('aluminium_kg_per_m');
        $this->postJson('/api/metal-compositions', $this->payload($type, ['copper_kg_per_m' => '250']))
            ->assertStatus(422)->assertJsonValidationErrors('copper_kg_per_m');
        $this->postJson('/api/metal-compositions', $this->payload($type, ['copper_kg_per_m' => 'abc']))
            ->assertStatus(422)->assertJsonValidationErrors('copper_kg_per_m');
        $this->postJson('/api/metal-compositions', $this->payload($type, ['aluminium_kg_per_m' => null, 'copper_kg_per_m' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('aluminium_kg_per_m');
        $this->postJson('/api/metal-compositions', $this->payload($type, ['source_reference' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('source_reference');
        $this->postJson('/api/metal-compositions', $this->payload($type, ['source_type' => 'supporting_document']))
            ->assertStatus(422)->assertJsonValidationErrors('document_id');

        // Four decimals are accepted; an empty field stays NULL ("unknown") and 0 stays 0
        $response = $this->postJson('/api/metal-compositions', $this->payload($type))->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.aluminium_kg_per_m', '0.421500')
            ->assertJsonPath('data.lead_kg_per_m', null)
            ->assertJsonPath('data.copper_kg_per_m', '0.000000');
        $this->assertNull(CableMetalComposition::query()->find($response->json('data.id'))->lead_kg_per_m);
    }

    public function test_source_document_must_belong_to_the_record(): void
    {
        $line = $this->lineOfType($this->type());
        $foreign = $this->attachDocument($this->line($this->org, $this->at), DocumentType::TechnicalPassport, $this->at);
        $own = $this->attachDocument($line, DocumentType::TechnicalPassport, $this->at);
        $this->login($this->at);

        $this->postJson('/api/metal-compositions', $this->payload($line, ['source_type' => 'supporting_document', 'document_id' => $foreign->id]))
            ->assertStatus(422)->assertJsonValidationErrors('document_id');
        $this->postJson('/api/metal-compositions', $this->payload($line, ['source_type' => 'supporting_document', 'document_id' => $own->id]))
            ->assertCreated()->assertJsonPath('data.document_id', $own->id);
    }

    public function test_theoretical_mass_is_length_times_kg_per_metre(): void
    {
        $type = $this->type();
        $this->approved($type, ['aluminium_kg_per_m' => '0.4215', 'lead_kg_per_m' => '1.85', 'copper_kg_per_m' => '0']);
        $line = $this->lineOfType($type, '1234.56', 2);
        $this->login($this->at);

        $data = $this->getJson("/api/cable-lines/{$line->id}/metal-content")->assertOk()->json('data');

        // 1234.56 m × 0.4215 kg/m = 520.36704 kg → 520.367; exact decimal maths, no float drift
        $this->assertSame('520.367', $data['segments'][0]['metals']['aluminium']['theoretical_kg']);
        $this->assertSame('2283.936', $data['segments'][0]['metals']['lead']['theoretical_kg']);
        $this->assertSame('0.000', $data['segments'][0]['metals']['copper']['theoretical_kg']);
        $this->assertSame('cable_type', $data['segments'][0]['scope']);
        $this->assertSame('1040.734', $data['totals']['aluminium']['known_kg']);
        $this->assertTrue($data['totals']['aluminium']['complete']);
        $this->assertTrue($data['complete']);
    }

    public function test_unknown_values_are_never_treated_as_zero(): void
    {
        $type = $this->type();
        $this->approved($type, ['aluminium_kg_per_m' => null, 'lead_kg_per_m' => '1.2', 'copper_kg_per_m' => '0']);
        $line = $this->lineOfType($type, '1000');
        $withoutType = $this->line($this->org, $this->at);
        $this->login($this->at);

        $data = $this->getJson("/api/cable-lines/{$line->id}/metal-content")->json('data');
        $aluminium = $data['segments'][0]['metals']['aluminium'];
        $this->assertNull($aluminium['kg_per_m']);
        $this->assertNull($aluminium['theoretical_kg']);
        $this->assertFalse($aluminium['known']);
        $this->assertFalse($data['totals']['aluminium']['complete']);
        $this->assertFalse($data['complete']);
        // Copper 0 is a verified zero, not unknown
        $this->assertSame('0.000', $data['segments'][0]['metals']['copper']['theoretical_kg']);
        $this->assertTrue($data['segments'][0]['metals']['copper']['known']);

        $none = $this->getJson("/api/cable-lines/{$withoutType->id}/metal-content")->json('data');
        $this->assertNull($none['segments'][0]['composition_id']);
        $this->assertFalse($none['totals']['lead']['complete']);

        // Reports show "unknown" instead of a misleading partial figure
        $this->login($this->user('auditor'));
        $rows = collect($this->getJson('/api/reports/metal_content')->assertOk()->json('data'))->keyBy('registry_number');
        $this->assertNull($rows[$line->registry_number]['aluminium_kg']);
        $this->assertSame('1200.000', $rows[$line->registry_number]['lead_kg']);
        $this->assertFalse($rows[$line->registry_number]['complete']);
    }

    public function test_line_and_segment_values_override_the_type_only_after_approval(): void
    {
        $type = $this->type();
        $this->approved($type, ['aluminium_kg_per_m' => '0.4', 'lead_kg_per_m' => '0', 'copper_kg_per_m' => '0.5']);
        $line = $this->lineOfType($type, '100', 2);
        [$first, $second] = $line->segments()->orderBy('sequence')->get()->all();
        $this->approved($line, ['aluminium_kg_per_m' => '0.45', 'lead_kg_per_m' => '0', 'copper_kg_per_m' => '0.5']);

        $service = app(MetalCompositionService::class);
        $pending = $service->submit($second, ['aluminium_kg_per_m' => '0.6', 'lead_kg_per_m' => '0', 'copper_kg_per_m' => '0.5', 'source_type' => 'measurement', 'source_reference' => 'TEST'], $this->at);
        $this->login($this->at);

        $rows = collect($this->getJson("/api/cable-lines/{$line->id}/metal-content")->json('data.segments'))->keyBy('code');
        $this->assertSame('cable_line', $rows[$first->code]['scope']);
        $this->assertSame('45.000', $rows[$first->code]['metals']['aluminium']['theoretical_kg']);
        $this->assertSame('cable_line', $rows[$second->code]['scope'], 'pending segment value must not apply yet');

        $service->approve($pending, $this->approver, 'Tortish natijasi tasdiqlandi');
        $rows = collect($this->getJson("/api/cable-lines/{$line->id}/metal-content")->json('data.segments'))->keyBy('code');
        $this->assertSame('cable_segment', $rows[$second->code]['scope']);
        $this->assertSame('60.000', $rows[$second->code]['metals']['aluminium']['theoretical_kg']);
    }

    public function test_approval_needs_a_second_person_and_keeps_previous_versions(): void
    {
        $type = $this->type();
        $first = $this->approved($type, ['aluminium_kg_per_m' => '0.4', 'lead_kg_per_m' => '0', 'copper_kg_per_m' => '0.5']);
        $this->at->givePermissionTo('metal_composition.approve');
        $this->login($this->at->fresh());

        $id = $this->postJson('/api/metal-compositions', $this->payload($type))->assertCreated()->json('data.id');
        $this->postJson("/api/metal-compositions/{$id}/approve")->assertStatus(422)->assertJsonPath('code', 'segregation_of_duties');
        $this->assertTrue(Alert::query()->where('type', AlertType::SegregationOfDuties->value)->exists());

        $this->login($this->user('mgqb_manager', $this->org));
        $this->postJson("/api/metal-compositions/{$id}/approve")->assertForbidden();

        $this->login($this->approver);
        $this->postJson("/api/metal-compositions/{$id}/approve", ['comment' => 'Pasport bilan solishtirildi'])->assertOk()->assertJsonPath('data.status', 'approved');

        $first->refresh();
        $this->assertSame('superseded', $first->status->value);
        $this->assertSame($id, $first->superseded_by_id);
        $this->assertSame('0.400000', $first->aluminium_kg_per_m, 'old values are never overwritten');
        $this->assertTrue(AuditLog::query()->where('event', 'composition_approved')->where('auditable_id', $id)->where('reason', 'Pasport bilan solishtirildi')->exists());
        $this->getJson("/api/metal-compositions?scope_type=cable_type&scope_id={$type->id}")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_calculation_history_is_kept_when_composition_or_length_changes(): void
    {
        $type = $this->type();
        $line = $this->lineOfType($type, '1000');
        $segment = $line->segments()->first();
        $this->approved($type, ['aluminium_kg_per_m' => '0.5', 'lead_kg_per_m' => '0', 'copper_kg_per_m' => '0.25']);

        $first = MetalMassCalculation::query()->where('subject_id', $segment->id)->sole();
        $this->assertSame('composition_approved', $first->trigger->value);
        $this->assertSame('500.000', $first->aluminium_kg);

        $this->login($this->at);
        $this->putJson("/api/cable-segments/{$segment->id}", ['design_length_m' => '1200'])->assertOk();

        $history = MetalMassCalculation::query()->where('subject_id', $segment->id)->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertSame('500.000', $history[0]->aluminium_kg, 'earlier result is preserved');
        $this->assertSame('length_changed', $history[1]->trigger->value);
        $this->assertSame('600.000', $history[1]->aluminium_kg);
        $this->assertSame('1000.00', $history[1]->details['previous']['design_length_m']);

        $this->getJson("/api/cable-lines/{$line->id}/metal-content")->assertJsonCount(2, 'data.history');
    }

    public function test_override_for_another_organisation_is_refused(): void
    {
        $foreignLine = $this->line($this->org(), $this->at);
        $this->login($this->user('mgqb_manager', $this->org));

        $this->postJson('/api/metal-compositions', $this->payload($foreignLine))->assertForbidden();
        $this->getJson("/api/cable-lines/{$foreignLine->id}/metal-content")->assertForbidden();
    }

    /** Completed order on a line of $type with $extracted metres recorded on its segment. */
    private function completedOrder(CableType $type, string $extracted): DismantlingOrder
    {
        $workflow = app(DismantlingWorkflow::class);
        $manager = $this->user('mgqb_manager', $this->org);
        $line = $this->lineOfType($type, '1000');
        $this->inspectLine($line, $manager, $this->user('tech_commission', $this->org));
        $this->login($manager);
        $segmentId = $line->segments()->value('id');

        $order = $workflow->create(['cable_line_id' => $line->id, 'segments' => [['id' => $segmentId]]], $manager);
        $workflow->transition($order, DismantlingOrderStatus::PendingReview, $manager);
        $workflow->transition($order, DismantlingOrderStatus::PendingAgreement, $manager);
        $safety = $this->user('safety', $this->org);
        foreach (['safety_agreement', 'operation_agreement'] as $kind) {
            $workflow->recordApproval($order, ['type' => $kind, 'decision' => 'approved'], $safety);
        }
        $this->attachDocument($order, DocumentType::WorkPermit, $manager);
        $workflow->transition($order, DismantlingOrderStatus::Approved, $this->approver);
        $workflow->transition($order, DismantlingOrderStatus::InProgress, $manager);
        $workflow->recordEvent($order, ['type' => DismantlingEventType::Progress->value, 'extracted_length_m' => $extracted, 'cable_segment_id' => $segmentId], $this->user('mgqb_manager', $this->org));
        $this->attachDocument($order, DocumentType::DismantlingAct, $manager);

        return $workflow->transition($order->refresh(), DismantlingOrderStatus::Completed, $manager);
    }

    public function test_theoretical_act_and_warehouse_masses_are_three_separate_figures(): void
    {
        $type = $this->type();
        $this->approved($type, ['aluminium_kg_per_m' => '0.4', 'lead_kg_per_m' => null, 'copper_kg_per_m' => '0.5']);
        $order = $this->completedOrder($type, '1000.00');
        $act = $order->documents()->where('type', 'dismantling_act')->first();
        $this->login($this->user('mgqb_manager', $this->org));

        // Before anything is recorded: theory known (lead unknown), act and warehouse unknown
        $balance = $this->getJson("/api/dismantling-orders/{$order->id}")->assertOk()->json('data.metal_balance.metals');
        $this->assertSame('400.000', $balance['aluminium']['theoretical_kg']);
        $this->assertNull($balance['lead']['theoretical_kg']);
        $this->assertNull($balance['aluminium']['act_kg']);
        $this->assertNull($balance['aluminium']['warehouse_kg']);

        $this->putJson("/api/dismantling-orders/{$order->id}/act-masses", ['aluminium_kg' => '390', 'copper_kg' => '480'])
            ->assertStatus(422)->assertJsonValidationErrors('document_id');
        $this->putJson("/api/dismantling-orders/{$order->id}/act-masses", ['aluminium_kg' => '390', 'lead_kg' => '0', 'copper_kg' => '480', 'document_id' => $act->id])
            ->assertOk()
            ->assertJsonPath('data.act_masses.aluminium_kg', '390.000')
            ->assertJsonPath('data.metal_balance.metals.aluminium.theoretical_kg', '400.000')
            ->assertJsonPath('data.metal_balance.metals.aluminium.act_vs_theoretical.kg', '-10.000')
            ->assertJsonPath('data.metal_balance.metals.aluminium.act_vs_theoretical.percent', -2.5)
            ->assertJsonPath('data.metal_balance.metals.copper.act_vs_theoretical.exceeds', true);
        $this->assertTrue(Alert::query()->where('type', AlertType::MetalDiscrepancy->value)->where('fingerprint', 'like', '%copper:act_vs_theoretical')->exists());

        // A correction needs a reason and stays in the audit trail
        $this->putJson("/api/dismantling-orders/{$order->id}/act-masses", ['aluminium_kg' => '395', 'lead_kg' => '0', 'copper_kg' => '480', 'document_id' => $act->id])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->putJson("/api/dismantling-orders/{$order->id}/act-masses", ['aluminium_kg' => '395', 'lead_kg' => '0', 'copper_kg' => '480', 'document_id' => $act->id, 'reason' => 'Tarozi qayta tekshirildi'])->assertOk();
        $correction = AuditLog::query()->where('event', 'act_masses_corrected')->sole();
        $this->assertSame('390.000', $correction->old_values['act_aluminium_kg']);

        // Warehouse intake of metals (kg) is compared with the act, without changing either figure
        $keeper = $this->user('warehouse_keeper', $this->org);
        $this->login($keeper);
        $this->postJson('/api/material-receipts', [
            'dismantling_order_id' => $order->id, 'number' => 'OK-M1', 'receipt_date' => now()->toDateString(), 'warehouse_name' => 'Ombor',
            'handed_over_by' => $order->work_supervisor_id ?? $this->user('mgqb_manager', $this->org)->id,
            'items' => [
                ['material_type' => 'aluminium', 'unit' => 'kg', 'handed_over_quantity' => '395', 'accepted_quantity' => '360', 'measured_on' => now()->toDateString()],
                ['material_type' => 'copper', 'unit' => 'kg', 'handed_over_quantity' => '480', 'accepted_quantity' => '480', 'measured_on' => now()->toDateString()],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'discrepancy');

        $this->login($this->approver);
        $balance = $this->getJson("/api/dismantling-orders/{$order->id}")->json('data.metal_balance.metals');
        $this->assertSame('400.000', $balance['aluminium']['theoretical_kg']);
        $this->assertSame('395.000', $balance['aluminium']['act_kg']);
        $this->assertSame('360.000', $balance['aluminium']['warehouse_kg']);
        $this->assertSame('-35.000', $balance['aluminium']['warehouse_vs_act']['kg']);
        $this->assertSame(-8.86, $balance['aluminium']['warehouse_vs_act']['percent']);
        $this->assertTrue($balance['aluminium']['warehouse_vs_act']['exceeds']);
        $this->assertFalse($balance['copper']['warehouse_vs_act']['exceeds']);
        $this->assertSame('0.000', $balance['lead']['warehouse_kg']);
        $this->assertNull($balance['lead']['warehouse_vs_theoretical']['kg'], 'unknown theory gives no difference');

        $row = collect($this->getJson('/api/reports/metal_balance')->assertOk()->json('data'))->firstWhere('number', $order->number);
        $this->assertSame('400.000', $row['aluminium_theoretical']);
        $this->assertSame('395.000', $row['aluminium_act']);
        $this->assertSame('360.000', $row['aluminium_warehouse']);
    }

    public function test_metals_are_received_in_kilograms_only(): void
    {
        $type = $this->type();
        $order = $this->completedOrder($type, '500.00');
        $this->login($this->user('warehouse_keeper', $this->org));

        $this->postJson('/api/material-receipts', [
            'dismantling_order_id' => $order->id, 'number' => 'OK-M2', 'receipt_date' => now()->toDateString(), 'warehouse_name' => 'Ombor',
            'handed_over_by' => $this->user('mgqb_manager', $this->org)->id,
            'items' => [['material_type' => 'copper', 'unit' => 'm', 'handed_over_quantity' => '10', 'accepted_quantity' => '10', 'measured_on' => now()->toDateString()]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.unit');
    }

    public function test_metal_report_export_has_a_totals_row_that_flags_unknown_parts(): void
    {
        $known = $this->type();
        $partial = $this->type();
        $this->approved($known, ['aluminium_kg_per_m' => '0.5', 'lead_kg_per_m' => '1', 'copper_kg_per_m' => '0']);
        $this->approved($partial, ['aluminium_kg_per_m' => null, 'lead_kg_per_m' => '1', 'copper_kg_per_m' => '0']);
        $this->lineOfType($known, '100');
        $this->lineOfType($partial, '200');
        $this->login($this->user('accountant'));

        $totals = $this->getJson('/api/reports/metal_content')->assertOk()->json('totals');
        $this->assertSame('300.000', $totals['values']['lead_kg']);
        $this->assertContains('aluminium_kg', $totals['incomplete']);
        $this->assertNotContains('lead_kg', $totals['incomplete']);

        $csv = $this->get('/api/reports/metal_content/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("noma'lum", $csv);
        $this->assertStringContainsString('Jami', $csv);
        $this->assertStringContainsString("≥ 50.000 (to'liq emas)", $csv);
    }

    public function test_cable_types_are_managed_by_authorised_roles(): void
    {
        $this->login($this->user('auditor'));
        $this->getJson('/api/cable-types')->assertOk();
        $this->postJson('/api/cable-types', ['code' => 'X', 'name' => 'X'])->assertForbidden();

        $this->login($this->at);
        $this->postJson('/api/cable-types', ['code' => 'MKSB-4', 'name' => 'MKSB 4x4x1.2', 'brand' => 'MKSB'])->assertCreated();
        $this->postJson('/api/cable-types', ['code' => 'MKSB-4', 'name' => 'Takror'])->assertStatus(422)->assertJsonValidationErrors('code');
    }
}
