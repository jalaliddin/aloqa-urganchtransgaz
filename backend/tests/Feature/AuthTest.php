<?php

namespace Tests\Feature;

use App\Models\AuditLog;

class AuthTest extends FeatureTestCase
{
    private function spa(): static
    {
        return $this->withHeader('Referer', 'http://localhost/');
    }

    public function test_user_can_log_in_and_receives_profile_with_permissions(): void
    {
        $user = $this->user('mgqb_manager', $this->org());

        $response = $this->spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.roles.0.name', 'mgqb_manager')
            ->assertJsonPath('data.can_view_all_organizations', false);
        $this->assertContains('cable_lines.create', $response->json('data.permissions'));
        $this->assertNotContains('users.manage', $response->json('data.permissions'));
        $this->assertTrue(AuditLog::query()->where('event', 'login')->where('user_id', $user->id)->exists());
    }

    public function test_wrong_password_is_rejected_and_audited(): void
    {
        $user = $this->user('auditor');

        $this->spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertTrue(AuditLog::query()->where('event', 'login_failed')->exists());
        $this->assertGuest('web');
    }

    public function test_unknown_email_gets_the_same_error(): void
    {
        $this->spa()->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', __('auth.failed'));
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        $user = $this->user('auditor');

        for ($i = 0; $i < 5; $i++) {
            $this->spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'bad'])->assertStatus(422);
        }

        $this->spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
    }

    public function test_inactive_user_cannot_log_in_or_keep_using_the_api(): void
    {
        $user = $this->user('auditor');
        $user->update(['is_active' => false]);

        $this->spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(422);

        $this->login($user);
        $this->getJson('/api/auth/me')->assertForbidden()->assertJsonPath('code', 'inactive');
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/cable-lines')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/dashboard')->assertUnauthorized();

        // A plain browser request (e.g. a download link opened after the session expired) gets 401, not a redirect error
        $this->get('/api/meta')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    public function test_passwords_are_stored_hashed(): void
    {
        $user = $this->user('auditor');

        $this->assertNotSame('password', $user->getAttributes()['password']);
        $this->assertStringStartsWith('$2y$', $user->getAttributes()['password']);
    }
}
