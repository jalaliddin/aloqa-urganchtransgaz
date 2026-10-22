<?php

namespace Tests\Feature;

use App\Enums\MetalCalculationTrigger;
use App\Models\CableLine;
use App\Models\CableType;
use App\Models\MetalMassCalculation;
use App\Models\Organization;
use App\Models\User;
use App\Services\DismantlingWorkflow;
use App\Services\MetalCompositionService;

class CableTypeStatisticsTest extends FeatureTestCase
{
    private Organization $own;

    private Organization $other;

    private User $at;

    private CableType $mksb;

    private CableType $tzg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->own = $this->org();
        $this->other = $this->org();
        $this->at = $this->user('at_service');
        $this->mksb = $this->type('TEST MKSB 4x4x1.2', 'MKSB');
        $this->tzg = $this->type('TEST TZG 7x4x1.2', 'TZG');

        // Pb 1 kg/m and Cu 0.5 kg/m approved for MKSB; TZG has no composition (unknown, not zero)
        $service = app(MetalCompositionService::class);
        $composition = $service->submit($this->mksb, [
            'aluminium_kg_per_m' => '0', 'lead_kg_per_m' => '1', 'copper_kg_per_m' => '0.5',
            'source_type' => 'brand_normative', 'source_reference' => 'TEST normativ',
        ], $this->at);
        $service->approve($composition, $this->user('management'), null);
    }

    private function type(string $name, string $brand, bool $active = true): CableType
    {
        return CableType::query()->create(['code' => 'T-'.uniqid(), 'name' => $name, 'brand' => $brand, 'is_active' => $active, 'is_demo' => true]);
    }

    private function lineOf(Organization $org, ?CableType $type, string $length): CableLine
    {
        return $this->line($org, $this->at, [['design_length_m' => $length, 'laying_type' => 'underground']], [
            'design_length_m' => $length, 'cable_type_id' => $type?->id,
        ]);
    }

    /** @return array<string, array<string, mixed>> report rows keyed by type name */
    private function report(): array
    {
        $rows = $this->getJson('/api/reports/by_cable_type?per_page=100')->assertOk()->json('data');

        return array_column($rows, null, 'name');
    }

    public function test_figures_per_type_follow_the_registry_and_stay_within_the_organisation(): void
    {
        $ownMksb = $this->lineOf($this->own, $this->mksb, '1000.00');
        $this->lineOf($this->own, $this->tzg, '500.00');
        $this->lineOf($this->own, null, '300.00');
        $this->lineOf($this->other, $this->mksb, '2000.00');

        // Draft order plans the whole MKSB line; 250 m already extracted
        $manager = $this->user('mgqb_manager', $this->own);
        $order = app(DismantlingWorkflow::class)->create(['cable_line_id' => $ownMksb->id, 'segments' => [['id' => $ownMksb->segments()->value('id')]]], $manager);
        $order->forceFill(['actual_length_m' => '250.00'])->saveQuietly();

        $this->login($this->user('management'));
        $rows = $this->report();
        $this->assertSame(['TEST MKSB 4x4x1.2', 'TEST TZG 7x4x1.2', "Turi ko'rsatilmagan"], array_keys($rows), 'longest first, untyped last');

        $mksb = $rows['TEST MKSB 4x4x1.2'];
        $this->assertSame(2, $mksb['lines_count']);
        $this->assertSame('3000.00', $mksb['total_length_m']);
        $this->assertEqualsWithDelta(78.9, $mksb['share_percent'], 0.001);
        $this->assertSame('1000.00', $mksb['planned_length_m']);
        $this->assertSame('250.00', $mksb['dismantled_length_m']);
        $this->assertEqualsWithDelta(25.0, $mksb['dismantling_percent'], 0.001);
        $this->assertSame('3000.000', $mksb['lead_kg']);
        $this->assertSame('1500.000', $mksb['copper_kg']);
        $this->assertSame('0.000', $mksb['aluminium_kg']);

        // No approved composition: the mass is unknown, never reported as 0
        $tzg = $rows['TEST TZG 7x4x1.2'];
        $this->assertNull($tzg['lead_kg']);
        $this->assertSame(1, $tzg['metal_unknown_segments']);

        $totals = $this->getJson('/api/reports/by_cable_type')->json('totals');
        $this->assertSame('3800.000', $totals['values']['total_length_m']);

        // An MGQB user only sees its own lines in the figures, while the dictionary itself is shared
        $this->login($manager);
        $rows = $this->report();
        $this->assertSame(1, $rows['TEST MKSB 4x4x1.2']['lines_count']);
        $this->assertSame('1000.00', $rows['TEST MKSB 4x4x1.2']['total_length_m']);

        $types = collect($this->getJson('/api/cable-types')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(1, $types['TEST MKSB 4x4x1.2']['lines_count']);
        $this->assertSame('1000.00', $types['TEST MKSB 4x4x1.2']['stats']['total_length_m']);

        $dashboard = collect($this->getJson('/api/dashboard')->assertOk()->json('data.by_cable_type'))->keyBy('name');
        $this->assertSame('1000.00', $dashboard['TEST MKSB 4x4x1.2']['total_length_m']);
        $this->assertArrayNotHasKey('lead_kg', $dashboard['TEST MKSB 4x4x1.2']);
    }

    public function test_registry_can_be_filtered_and_searched_by_cable_type(): void
    {
        $this->lineOf($this->own, $this->mksb, '100.00');
        $this->lineOf($this->own, $this->tzg, '100.00');
        $this->login($this->user('management'));

        $this->getJson("/api/cable-lines?cable_type_id={$this->tzg->id}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cable_type_ref.name', 'TEST TZG 7x4x1.2')
            ->assertJsonPath('data.0.cable_type_ref.brand', 'TZG');
        $this->getJson('/api/cable-lines?q=MKSB')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cable_type_must_be_chosen_from_the_active_dictionary(): void
    {
        $this->login($this->user('mgqb_manager', $this->own));
        $payload = ['name' => 'TEST', 'laid_year_precision' => 'unknown', 'organization_id' => $this->own->id, 'balance_holder_id' => $this->own->id];

        $this->postJson('/api/cable-lines', $payload)->assertStatus(422)
            ->assertJsonValidationErrors(['cable_type_id' => "Kabel turini ma'lumotnomadan tanlang."]);

        $retired = $this->type('TEST eski tur', 'OLD', active: false);
        $this->postJson('/api/cable-lines', [...$payload, 'cable_type_id' => $retired->id])->assertStatus(422)->assertJsonValidationErrors('cable_type_id');

        $line = CableLine::query()->findOrFail($this->postJson('/api/cable-lines', [...$payload, 'cable_type_id' => $this->mksb->id])
            ->assertCreated()->assertJsonPath('data.cable_type_ref.name', 'TEST MKSB 4x4x1.2')->json('data.id'));

        // The type cannot be removed from a line…
        $this->putJson("/api/cable-lines/{$line->id}", ['cable_type_id' => null])->assertStatus(422)->assertJsonValidationErrors('cable_type_id');

        // …but a line keeps its current type after that type is deactivated
        $this->mksb->update(['is_active' => false]);
        $this->putJson("/api/cable-lines/{$line->id}", ['name' => 'TEST yangi', 'cable_type_id' => $this->mksb->id])->assertOk();
    }

    public function test_changing_the_type_keeps_the_theoretical_mass_history(): void
    {
        $line = $this->lineOf($this->own, $this->mksb, '200.00');
        $this->login($this->user('mgqb_manager', $this->own));

        $this->putJson("/api/cable-lines/{$line->id}", ['cable_type_id' => $this->tzg->id])->assertOk();

        $snapshot = MetalMassCalculation::query()->where('trigger', MetalCalculationTrigger::CableTypeChanged->value)->sole();
        $this->assertFalse($snapshot->is_complete, 'TZG has no composition: the new mass is unknown');
        $this->assertNull($snapshot->lead_kg);
        $this->assertSame($this->mksb->id, $snapshot->details['previous_cable_type_id']);
        $this->assertSame($this->tzg->id, $snapshot->details['cable_type_id']);
    }
}
