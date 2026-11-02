<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminAuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_issues_scoped_token_and_logout_revokes_it(): void
    {
        User::create([
            'name' => 'Test Administrator',
            'email' => 'admin-auth@example.test',
            'password' => 'test-password-123',
            'is_admin' => true,
        ]);

        $login = $this->postJson('/api/admin/login', [
            'email' => 'admin-auth@example.test',
            'password' => 'test-password-123',
        ])->assertOk()
            ->assertJsonPath('user.email', 'admin-auth@example.test')
            ->assertJsonPath('user.is_admin', true)
            ->assertJsonStructure(['token', 'user']);

        $token = $login->json('token');

        $this->withToken($token)->getJson('/api/admin/me')->assertOk()
            ->assertJsonPath('user.email', 'admin-auth@example.test');

        $this->withToken($token)->postJson('/api/admin/logout')->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => (int) explode('|', $token, 2)[0],
        ]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_admin_login_rejects_wrong_password_unknown_user_and_non_admin(): void
    {
        User::create([
            'name' => 'Test Administrator',
            'email' => 'admin-auth@example.test',
            'password' => 'test-password-123',
            'is_admin' => true,
        ]);
        User::create([
            'name' => 'Test Client',
            'email' => 'client-auth@example.test',
            'password' => 'test-password-123',
            'is_admin' => false,
        ]);

        $this->postJson('/api/admin/login', [
            'email' => 'admin-auth@example.test',
            'password' => 'wrong-password',
        ])->assertUnprocessable();

        $this->postJson('/api/admin/login', [
            'email' => 'unknown-auth@example.test',
            'password' => 'test-password-123',
        ])->assertUnprocessable();

        $this->postJson('/api/admin/login', [
            'email' => 'client-auth@example.test',
            'password' => 'test-password-123',
        ])->assertUnprocessable();
    }
}
