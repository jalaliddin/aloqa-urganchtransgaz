<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\DocumentType;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\CableLine;
use App\Models\InspectionItem;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Testing\TestResponse;

class InspectionWorkflowTest extends FeatureTestCase
{
    /** @return array{0: Organization, 1: User, 2: CableLine, 3: int} */
    private function startedInspection(): array
    {
        $org = $this->org();
        $manager = $this->user('mgqb_manager', $org);
        $line = $this->line($org, $manager, [
            ['name' => 'A', 'laying_type' => 'underground', 'design_length_m' => '1000.00'],
            ['name' => 'B', 'laying_type' => 'duct', 'design_length_m' => '500.00'],
        ]);
        $this->login($manager);

        $id = $this->postJson('/api/inspections', [
            'organization_id' => $org->id,
            'title' => 'TEST xatlov',
            'planned_start' => now()->toDateString(),
            'segment_ids' => $line->segments()->pluck('id')->all(),
            'members' => [
                ['full_name' => 'Rais', 'role' => 'chair'],
                ['full_name' => "A'zo", 'role' => 'member', 'position' => 'muhandis'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.items.0.recorded_length_m', '1000.00')
            ->json('data.id');

        $this->assertSame('scheduled', $line->refresh()->inspection_status->value);
        $this->postJson("/api/inspections/{$id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');

        return [$org, $manager, $line, $id];
    }

    private function record(int $inspectionId, InspectionItem $item, array $overrides = []): TestResponse
    {
        return $this->putJson("/api/inspections/{$inspectionId}/items/{$item->id}", [
            'inspected_on' => now()->toDateString(),
            'measured_length_m' => $item->recorded_length_m,
            'technical_condition' => 'poor',
            'usage_status' => 'in_use',
            'risk_level' => 'low',
            ...$overrides,
        ]);
    }

    public function test_full_inventory_flow_updates_the_registry_after_approval(): void
    {
        [$org, $manager, $line, $id] = $this->startedInspection();
        [$a, $b] = InspectionItem::query()->where('inspection_id', $id)->orderBy('id')->get()->all();

        // Length difference above tolerance needs a reason…
        $this->record($id, $a, ['measured_length_m' => '900.00'])->assertStatus(422)->assertJsonValidationErrors('difference_reason');
        $this->record($id, $a, ['measured_length_m' => '900.00', 'difference_reason' => 'Bir qismi avval olingan'])
            ->assertOk()->assertJsonPath('data.length_difference_m', '-100.00')->assertJsonPath('data.exceeds_tolerance', true);

        // "Not in use" needs evidence text
        $this->record($id, $b, ['usage_status' => 'not_in_use'])->assertStatus(422)->assertJsonValidationErrors('usage_evidence');
        $this->record($id, $b, ['usage_status' => 'not_in_use', 'usage_evidence' => 'Uchlari ajratilgan', 'measured_length_m' => '502.00'])->assertOk();

        // …and supporting documents before submission
        $this->postJson("/api/inspections/{$id}/submit")->assertStatus(422)->assertJsonPath('code', 'incomplete_inspection')
            ->assertJsonStructure(['errors' => ["items.{$a->id}", "items.{$b->id}"]]);

        $this->attachDocument($a, DocumentType::CommissionProtocol, $manager);
        $this->attachDocument($b, DocumentType::Photo, $manager);
        $this->postJson("/api/inspections/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertTrue(Alert::query()->where('type', AlertType::LengthDiscrepancy->value)->where('subject_id', $a->cable_segment_id)->exists());

        // Registry is untouched until an authorised second person approves
        $this->assertNull($a->segment->refresh()->measured_length_m);

        $this->postJson("/api/inspections/{$id}/approve")->assertForbidden(); // mgqb manager has no approve right
        $this->login($this->user('tech_commission', $org));
        $this->postJson("/api/inspections/{$id}/approve", ['comment' => 'Tasdiqlandi'])->assertOk()->assertJsonPath('data.status', 'approved');

        $segmentA = $a->segment->refresh();
        $segmentB = $b->segment->refresh();
        $this->assertSame('900.00', $segmentA->measured_length_m);
        $this->assertSame('inspected', $segmentA->inspection_status->value);
        $this->assertSame('not_in_use', $segmentB->usage_status->value);
        $line->refresh();
        $this->assertSame('inspected', $line->inspection_status->value);
        $this->assertSame('partially_used', $line->usage_status->value);
        $this->assertSame('1402.00', $line->measured_length_m);
        $this->assertTrue(AuditLog::query()->where('event', 'inspection_approved')->where('reason', 'Tasdiqlandi')->exists());
    }

    public function test_submitter_cannot_approve_own_results(): void
    {
        $org = $this->org();
        $commission = $this->user('tech_commission', $org);
        $line = $this->line($org, $commission);
        $this->login($commission);

        $id = $this->postJson('/api/inspections', [
            'organization_id' => $org->id, 'title' => 'TEST', 'segment_ids' => $line->segments()->pluck('id')->all(),
            'members' => [['full_name' => 'Rais', 'role' => 'chair']],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/inspections/{$id}/start")->assertOk();
        $this->record($id, InspectionItem::query()->where('inspection_id', $id)->first())->assertOk();
        $this->postJson("/api/inspections/{$id}/submit")->assertOk();

        $this->postJson("/api/inspections/{$id}/approve")->assertStatus(422)->assertJsonPath('code', 'segregation_of_duties');
        $this->assertTrue(Alert::query()->where('type', AlertType::SegregationOfDuties->value)->exists());
    }

    public function test_rejected_inspection_returns_for_correction(): void
    {
        [$org, $manager, , $id] = $this->startedInspection();
        foreach (InspectionItem::query()->where('inspection_id', $id)->get() as $item) {
            $this->record($id, $item)->assertOk();
        }
        $this->postJson("/api/inspections/{$id}/submit")->assertOk();

        $this->login($this->user('management'));
        $this->postJson("/api/inspections/{$id}/reject")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/inspections/{$id}/reject", ['reason' => "O'lchovlar qayta tekshirilsin"])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->login($manager);
        $item = InspectionItem::query()->where('inspection_id', $id)->first();
        $this->record($id, $item, ['technical_condition' => 'critical'])->assertOk();
        $this->postJson("/api/inspections/{$id}/submit")->assertOk();
    }

    public function test_segment_cannot_be_in_two_open_inspections(): void
    {
        [$org, , $line] = $this->startedInspection();

        $this->postJson('/api/inspections', [
            'organization_id' => $org->id, 'title' => 'Takroriy', 'segment_ids' => $line->segments()->pluck('id')->all(),
            'members' => [['full_name' => 'Rais', 'role' => 'chair']],
        ])->assertStatus(422)->assertJsonValidationErrors('segment_ids');
    }

    public function test_segments_of_another_organisation_cannot_be_assigned(): void
    {
        $own = $this->org();
        $foreignLine = $this->line($this->org(), $this->user('at_service'));
        $this->login($this->user('mgqb_manager', $own));

        $this->postJson('/api/inspections', [
            'organization_id' => $own->id, 'title' => 'X', 'segment_ids' => $foreignLine->segments()->pluck('id')->all(),
            'members' => [['full_name' => 'Rais', 'role' => 'chair']],
        ])->assertStatus(422)->assertJsonValidationErrors('segment_ids');
    }

    public function test_cancelling_restores_segment_status(): void
    {
        [, , $line, $id] = $this->startedInspection();

        $this->postJson("/api/inspections/{$id}/cancel", ['reason' => 'Rejadan chiqarildi'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('not_inspected', $line->refresh()->inspection_status->value);
    }

    public function test_protocol_pdf_is_generated(): void
    {
        [, , , $id] = $this->startedInspection();

        $response = $this->get("/api/inspections/{$id}/protocol")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
