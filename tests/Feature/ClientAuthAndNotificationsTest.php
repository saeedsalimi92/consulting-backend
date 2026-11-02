<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BookingNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientAuthAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_registration_is_deprecated_and_password_login_profile_logout_remain_compatible(): void
    {
        $this->postJson('/api/client/register',[
            'name'=>'New client','email'=>'new-client-auth@example.test','phone'=>'09121112223',
            'password'=>'secure-password-123','password_confirmation'=>'secure-password-123',
        ])->assertStatus(410)->assertJsonPath('code','OTP_REGISTRATION_REQUIRED');
        $this->assertDatabaseMissing('users',['email'=>'new-client-auth@example.test']);
        $user=User::create(['name'=>'Existing client','email'=>'new-client-auth@example.test','phone'=>'+989121112223',
            'password'=>'secure-password-123','is_active'=>true]);
        $token=$user->createToken('client',['client'])->plainTextToken;
        $this->withSanctumToken($token)->getJson('/api/client/me')->assertOk()
            ->assertJsonPath('data.name','Existing client');
        $this->patchJson('/api/client/me',['address'=>'Tehran'])->assertOk()
            ->assertJsonPath('data.address','Tehran');
        $this->postJson('/api/client/login',['email'=>'new-client-auth@example.test','password'=>'incorrect-password'])
            ->assertStatus(422);
        $login=$this->postJson('/api/client/login',['email'=>'new-client-auth@example.test','password'=>'secure-password-123'])
            ->assertOk()->json('token');
        $this->withSanctumToken($login)->postJson('/api/client/logout')->assertNoContent();
        $this->withSanctumToken($login)->getJson('/api/client/me')->assertUnauthorized();
    }

    public function test_api_authentication_errors_are_json_without_an_accept_header(): void
    {
        $this->get('/api/client/me')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    public function test_notifications_are_private_to_their_recipient_and_read_state_is_owned(): void
    {
        $client=User::create(['name'=>'Notification owner','email'=>'notification-owner@example.test','password'=>'password-123456']);
        $other=User::create(['name'=>'Other user','email'=>'notification-other@example.test','password'=>'password-123456']);
        $counselor=DB::table('counselors')->insertGetId(['name'=>'Notification counselor','slug'=>'notification-counselor',
            'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $service=DB::table('booking_services')->insertGetId(['title'=>'Notice session','duration_minutes'=>60,'price_irr'=>100000,
            'deposit_irr'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $booking=DB::table('bookings')->insertGetId(['user_id'=>$client->id,'created_by'=>$client->id,'counselor_id'=>$counselor,
            'booking_service_id'=>$service,'mode'=>'in_person','session_mode'=>'in_person','starts_at'=>now()->addDays(2),
            'ends_at'=>now()->addDays(2)->addHour(),'status'=>'confirmed','payment_option'=>'full','total_irr'=>100000,
            'required_irr'=>100000,'paid_irr'=>0,'created_at'=>now(),'updated_at'=>now()]);
        app(BookingNotices::class)->queue($booking,'confirmation-private-test','client');
        $notification=DB::table('booking_in_app_notifications')->where('user_id',$client->id)->first();
        $this->assertNotNull($notification);
        $ownerToken=$client->createToken('client',['client'])->plainTextToken;
        $otherToken=$other->createToken('client',['client'])->plainTextToken;
        $this->withSanctumToken($ownerToken)->getJson('/api/client/notifications')->assertOk()
            ->assertJsonPath('data.data.0.event_key','confirmation-private-test');
        $this->withSanctumToken($otherToken)->getJson('/api/client/notifications')->assertOk()
            ->assertJsonCount(0,'data.data');
        $this->withSanctumToken($otherToken)->postJson('/api/client/notifications/'.$notification->id.'/read')->assertNotFound();
        $this->withSanctumToken($ownerToken)->postJson('/api/client/notifications/'.$notification->id.'/read')->assertNoContent();
        $this->assertNotNull(DB::table('booking_in_app_notifications')->where('id',$notification->id)->value('read_at'));
    }
}
