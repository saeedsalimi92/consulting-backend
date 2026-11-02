<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_inspect_session_change_password_and_revoke_all_tokens(): void
    {
        $user=User::create(['name'=>'Security client','email'=>'security@example.test','password'=>'old-password-123']);
        $token=$user->createToken('client',['client'])->plainTextToken;
        $user->createToken('second-device',['client']);

        $this->withToken($token)->getJson('/api/client/session')->assertOk()
            ->assertJsonPath('data.user.id',$user->id)->assertJsonPath('data.token.name','client');
        $this->withToken($token)->putJson('/api/client/password',[
            'current_password'=>'old-password-123','password'=>'new-password-123','password_confirmation'=>'new-password-123',
        ])->assertOk();
        $this->assertSame(0,$user->tokens()->count());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-password-123',$user->fresh()->password));
    }

    public function test_openapi_endpoint_lists_registered_routes_and_bearer_scheme(): void
    {
        $this->getJson('/api/docs/openapi.json')->assertOk()
            ->assertJsonPath('openapi','3.0.3')
            ->assertJsonPath('components.securitySchemes.sanctum.scheme','bearer')
            ->assertJsonStructure(['paths'=>['/admin/login'=>['post'],'/booking/reservations'=>['post']]]);
    }
}
