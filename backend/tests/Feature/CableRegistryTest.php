<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\CableLine;
use App\Models\CableSegment;

class CableRegistryTest extends FeatureTestCase
{
    public function test_line_is_created_with_segments_and_automatic_registry_number(): void
    {
        $org = $this->org();
        $this->login($this->user('mgqb_manager', $org));

        $response = $this->postJson('/api/cable-lines', [
            'name' => 'TEST liniya',
            'cable_code' => 'TEST-K-1',
            'brand' => 'MKSB 4x4x1.2',
            'core_count' => 16,
            'laid_year' => 1960,
            'laid_year_precision' => 'decade',
            'organization_id' => $org->id,
            'balance_holder_id' => $org->id,
            'design_length_m' => '2500.50',
            'segments' => [
                ['name' => 'A', 'design_length_m' => '1500.00', 'laying_type' => 'underground'],
                ['name' => 'B', 'design_length_m' => '1000.50', 'laying_type' => 'road_crossing', 'risk_level' => 'high', 'complexity_flags' => ['under_road']],
            ],
        ])->assertCreated();

        $number = $response->json('data.registry_number');
        $this->assertMatchesRegularExpression('/^KL-\d{4}-\d{5}$/', $number);
        $response->assertJsonPath('data.laid_year_label', '1960-yillar')
            ->assertJsonPath('data.segments_count', 2)
            ->assertJsonPath('data.segments.1.code', $number.'-U02')
            ->assertJsonPath('data.risk_level', 'high')
            ->assertJsonPath('data.is_complex', true)
            ->assertJsonPath('data.has_geometry', false)
            ->assertJsonPath('data.design_length_m', '2500.50');

        $line = CableLine::query()->firstOrFail();
        $this->assertTrue(AuditLog::query()->where('event', 'created')->where('auditable_type', 'cable_line')->where('auditable_id', $line->id)->exists());
    }

    public function test_line_without_segments_gets_a_default_segment(): void
    {
        $org = $this->org();
        $line = $this->line($org, $this->user('at_service'), segments: []);

        $this->assertSame(1, $line->segments()->count());
    }

    public function test_validation_errors_are_structured(): void
    {
        $org = $this->org();
        $this->login($this->user('mgqb_manager', $org));

        $this->postJson('/api/cable-lines', ['laid_year' => 1800, 'design_length_m' => -5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'organization_id', 'balance_holder_id', 'laid_year', 'design_length_m', 'laid_year_precision']);
    }

    public function test_update_is_audited_with_old_and_new_values(): void
    {
        $org = $this->org();
        $user = $this->user('mgqb_manager', $org);
        $line = $this->line($org, $user);
        $this->login($user);

        $this->putJson("/api/cable-lines/{$line->id}", ['name' => 'Yangi nom', 'notes' => 'izoh'])->assertOk()->assertJsonPath('data.name', 'Yangi nom');

        $log = AuditLog::query()->where('event', 'updated')->where('auditable_id', $line->id)->where('auditable_type', 'cable_line')->latest('id')->firstOrFail();
        $this->assertSame('Yangi nom', $log->new_values['name']);
        $this->assertNotSame('Yangi nom', $log->old_values['name']);
        $this->assertSame($user->id, $log->user_id);
    }

    public function test_registry_filters_sorting_and_pagination(): void
    {
        $org = $this->org();
        $at = $this->user('at_service');
        foreach (range(1, 7) as $i) {
            $this->line($org, $at, overrides: ['name' => "TEST filtr {$i}", 'laid_year' => 1960 + $i, 'laid_year_precision' => 'exact', 'design_length_m' => (string) ($i * 100)]);
        }
        $special = $this->line($org, $at, [['name' => 'X', 'laying_type' => 'near_building', 'risk_level' => 'critical', 'design_length_m' => '50']], ['name' => 'Maxsus liniya', 'cable_code' => 'QIDIRUV-77', 'laid_year' => null, 'laid_year_precision' => 'unknown', 'design_length_m' => '50']);
        $this->login($at);

        $this->getJson('/api/cable-lines?per_page=5')->assertOk()
            ->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 8)->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/cable-lines?q=QIDIRUV')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $special->id);
        $this->getJson('/api/cable-lines?q='.urlencode($special->segments->first()->code))->assertOk()->assertJsonPath('data.0.id', $special->id);
        $this->getJson('/api/cable-lines?risk_level[]=critical')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/cable-lines?laid_year_from=1965&laid_year_to=1967')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/cable-lines?length_min=500&length_max=700')->assertOk()->assertJsonCount(3, 'data');

        $sorted = $this->getJson('/api/cable-lines?sort=design_length_m&direction=desc&per_page=100')->assertOk()->json('data');
        $this->assertSame('700.00', $sorted[0]['design_length_m']);

        $this->getJson('/api/cable-lines?sort=password')->assertStatus(422);
        $this->getJson('/api/cable-lines?dismantling_status[]=stolen')->assertStatus(422);
    }

    public function test_segment_crud_and_line_aggregation(): void
    {
        $org = $this->org();
        $user = $this->user('mgqb_manager', $org);
        $line = $this->line($org, $user);
        $this->login($user);

        $segmentId = $this->postJson("/api/cable-lines/{$line->id}/segments", [
            'name' => 'Yangi', 'laying_type' => 'duct', 'design_length_m' => '300', 'risk_level' => 'high', 'technical_condition' => 'critical',
        ])->assertCreated()->json('data.id');

        $line->refresh();
        $this->assertSame('high', $line->risk_level->value);
        $this->assertSame('critical', $line->technical_condition->value);

        $this->putJson("/api/cable-segments/{$segmentId}", ['risk_level' => 'low', 'technical_condition' => 'good'])->assertOk();
        $this->assertSame('low', $line->refresh()->risk_level->value);

        $this->deleteJson("/api/cable-segments/{$segmentId}", ['reason' => 'Xato kiritilgan'])->assertOk();
        $this->assertSoftDeleted('cable_segments', ['id' => $segmentId]);
    }

    public function test_not_in_use_cannot_be_set_without_inspection_evidence(): void
    {
        $org = $this->org();
        $user = $this->user('mgqb_manager', $org);
        $segment = $this->line($org, $user)->segments()->first();
        $this->login($user);

        $this->putJson("/api/cable-segments/{$segment->id}", ['usage_status' => 'not_in_use'])
            ->assertStatus(422)->assertJsonPath('code', 'usage_requires_inspection');

        $this->assertSame('unknown', $segment->refresh()->usage_status->value);
        $this->assertTrue(Alert::query()->where('type', AlertType::UnauthorizedStatusChange->value)->exists());
    }

    public function test_workflow_statuses_cannot_be_edited_directly(): void
    {
        $org = $this->org();
        $user = $this->user('mgqb_manager', $org);
        $segment = $this->line($org, $user)->segments()->first();
        $this->login($user);

        $this->putJson("/api/cable-segments/{$segment->id}", ['dismantling_status' => 'completed'])
            ->assertStatus(422)->assertJsonPath('code', 'workflow_field');
        $this->putJson("/api/cable-segments/{$segment->id}", ['measured_length_m' => '1.00'])->assertStatus(422);

        $this->assertSame('not_planned', $segment->refresh()->dismantling_status->value);
        $this->assertSame(1, Alert::query()->where('type', AlertType::UnauthorizedStatusChange->value)->where('subject_id', $segment->id)->where('fingerprint', 'like', '%dismantling_status')->count());
    }

    public function test_line_with_history_cannot_be_deleted_but_a_mistaken_one_can(): void
    {
        $org = $this->org();
        $manager = $this->user('mgqb_manager', $org);
        $admin = $this->user('super_admin');
        $withHistory = $this->line($org, $manager);
        $this->inspectLine($withHistory, $manager, $this->user('tech_commission', $org));
        $mistake = $this->line($org, $manager);
        $this->login($admin);

        $this->deleteJson("/api/cable-lines/{$withHistory->id}", ['reason' => 'TEST sabab'])->assertStatus(422)->assertJsonPath('code', 'has_history');
        $this->deleteJson("/api/cable-lines/{$mistake->id}")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->deleteJson("/api/cable-lines/{$mistake->id}", ['reason' => 'Ikki marta kiritilgan'])->assertOk();

        $this->assertSoftDeleted('cable_lines', ['id' => $mistake->id]);
        $this->assertSame(0, CableSegment::query()->where('cable_line_id', $mistake->id)->count());
        $this->assertTrue(AuditLog::query()->where('event', 'line_deleted')->where('reason', 'Ikki marta kiritilgan')->exists());
    }
}
