<?php

namespace Tests\Feature;

use App\Models\CableLine;

/** Organisation separation and role permissions are enforced on the server (spec 5). */
class OrganizationScopeTest extends FeatureTestCase
{
    public function test_mgqb_user_sees_only_own_organisation_lines(): void
    {
        $own = $this->org('TEST Own');
        $other = $this->org('TEST Other');
        $at = $this->user('at_service');
        $mine = $this->line($own, $at);
        $foreign = $this->line($other, $at);

        $this->login($this->user('mgqb_manager', $own));

        $ids = collect($this->getJson('/api/cable-lines')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($foreign->id));

        // Changing the URL / parameters does not help
        $this->getJson("/api/cable-lines/{$foreign->id}")->assertForbidden();
        $this->putJson("/api/cable-lines/{$foreign->id}", ['name' => 'X'])->assertForbidden();
        $this->getJson('/api/cable-lines?organization_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/map/features?organization_id='.$other->id)->assertOk()->assertJsonCount(0, 'features');
        $segment = $foreign->segments()->first();
        $this->putJson("/api/cable-segments/{$segment->id}/geometry", ['geometry' => $this->lineString(), 'source' => 'manual'])->assertForbidden();
    }

    public function test_user_cannot_create_records_for_another_organisation(): void
    {
        $own = $this->org();
        $other = $this->org();
        $this->login($this->user('mgqb_manager', $own));

        $this->postJson('/api/cable-lines', [
            'name' => 'TEST', 'laid_year_precision' => 'unknown',
            'organization_id' => $other->id, 'balance_holder_id' => $other->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['organization_id', 'balance_holder_id']);

        $this->assertSame(0, CableLine::query()->count());
    }

    public function test_central_roles_see_all_organisations(): void
    {
        $at = $this->user('at_service');
        $this->line($this->org(), $at);
        $this->line($this->org(), $at);

        $this->login($this->user('auditor'));
        $this->getJson('/api/cable-lines')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_read_only_auditor_cannot_modify(): void
    {
        $org = $this->org();
        $line = $this->line($org, $this->user('at_service'));
        $this->login($this->user('auditor'));

        $this->postJson('/api/cable-lines', ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/cable-lines/{$line->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/cable-lines/{$line->id}", ['reason' => 'TEST sabab'])->assertForbidden();
        $this->getJson('/api/audit-logs')->assertOk();
    }

    public function test_financial_fields_are_visible_only_with_permission(): void
    {
        $org = $this->org();
        $line = $this->line($org, $this->user('at_service'), overrides: [
            'initial_cost' => '1000000.00', 'accumulated_depreciation' => '900000.00', 'residual_value' => '100000.00',
        ]);

        $this->login($this->user('mgqb_manager', $org));
        $this->getJson("/api/cable-lines/{$line->id}")->assertOk()->assertJsonMissingPath('data.initial_cost');

        $this->login($this->user('accountant'));
        $this->getJson("/api/cable-lines/{$line->id}")->assertOk()->assertJsonPath('data.initial_cost', '1000000.00');
    }

    public function test_financial_fields_cannot_be_written_without_permission(): void
    {
        $org = $this->org();
        $line = $this->line($org, $this->user('at_service'));
        $this->login($this->user('mgqb_manager', $org));

        $this->putJson("/api/cable-lines/{$line->id}", ['initial_cost' => '5'])
            ->assertStatus(422)->assertJsonValidationErrors('initial_cost');
    }

    public function test_accountant_may_change_only_financial_fields(): void
    {
        $line = $this->line($this->org(), $this->user('at_service'));
        $this->login($this->user('accountant'));

        $this->putJson("/api/cable-lines/{$line->id}", ['initial_cost' => '2500000.00', 'accumulated_depreciation' => '100.00'])
            ->assertOk()->assertJsonPath('data.initial_cost', '2500000.00');
        $this->putJson("/api/cable-lines/{$line->id}", ['name' => 'Yangi nom'])->assertForbidden();
    }

    public function test_users_endpoint_requires_users_manage(): void
    {
        $this->login($this->user('management'));
        $this->getJson('/api/users')->assertForbidden();

        $this->login($this->user('super_admin'));
        $this->getJson('/api/users')->assertOk();
    }

    public function test_non_super_admin_cannot_grant_super_admin(): void
    {
        $admin = $this->user('super_admin');
        $admin->syncRoles([]);
        $admin->givePermissionTo('users.manage', 'organizations.view_all');
        $this->login($admin->fresh());

        $this->postJson('/api/users', [
            'name' => 'TEST', 'email' => 'x@test.local', 'organization_id' => $this->headOrg()->id,
            'password' => 'Parol12345', 'roles' => ['super_admin'],
        ])->assertStatus(422)->assertJsonValidationErrors('roles.0');
    }
}
