<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CounselorLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_revokes_current_counselor_token(): void
    {
        $user = User::create(['name' => 'Counselor', 'email' => 'logout-counselor@example.test', 'password' => 'password-123456']);
        DB::table('counselors')->insert(['name' => 'Counselor', 'slug' => 'logout-counselor', 'user_id' => $user->id,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $token = $user->createToken('counselor-portal', ['counselor'])->plainTextToken;

        $this->withSanctumToken($token)->postJson('/api/counselor/logout')->assertNoContent();
        $this->withSanctumToken($token)->getJson('/api/counselor/appointments')->assertUnauthorized();
    }
}
