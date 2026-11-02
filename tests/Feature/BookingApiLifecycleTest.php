<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingApiLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function setupBookingData(): array
    {
        $client = User::create(['name'=>'Booking client','first_name'=>'Booking','last_name'=>'Client','phone'=>'+989121111111','phone_verified_at'=>now(),'email'=>'booking-client@example.test','password'=>'password-123456']);
        $counselorUser = User::create(['name'=>'Counselor user','email'=>'counselor@example.test','password'=>'password-123456']);
        $counselor = DB::table('counselors')->insertGetId(['name'=>'Counselor A','slug'=>'counselor-a','user_id'=>$counselorUser->id,
            'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $service = DB::table('booking_services')->insertGetId(['title'=>'Session 60','duration_minutes'=>60,
            'price_irr'=>800000,'deposit_irr'=>200000,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('counselor_booking_services')->insert(['counselor_id'=>$counselor,'booking_service_id'=>$service,
            'price_irr'=>1200000,'commission_type'=>'percentage','commission_value'=>25]);
        $date=now('Asia/Tehran')->addDays(5)->startOfDay();
        DB::table('counselor_working_hours')->insert(['counselor_id'=>$counselor,'weekday'=>$date->dayOfWeek,
            'starts_at'=>'09:00:00','ends_at'=>'11:00:00','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        return [$client,$counselorUser,$counselor,$service,$date];
    }

    public function test_availability_uses_timezone_service_duration_and_counselor_price(): void
    {
        [$client,,$counselor,$service,$date]=$this->setupBookingData();
        $this->getJson('/api/booking/availability?counselor_id='.$counselor.'&service_id='.$service.'&date='.$date->toDateString())
            ->assertOk()->assertJsonPath('timezone','Asia/Tehran')->assertJsonCount(2,'slots')
            ->assertJsonPath('slots.0.available',true);

        $token=$client->createToken('client',['client'])->plainTextToken;
        $response=$this->withToken($token)->postJson('/api/booking/reservations',[
            'counselor_id'=>$counselor,'service_id'=>$service,'starts_at'=>$date->copy()->addHours(9)->toIso8601String(),
            'mode'=>'online','payment_option'=>'full',
        ])->assertCreated()->assertJsonPath('data.total_irr',1200000)->json('data');
        $this->assertSame(60,(int)Carbon::parse($response['starts_at'])->diffInMinutes(Carbon::parse($response['ends_at'])));
        $this->assertSame(300000,(int)DB::table('bookings')->find($response['id'])->commission_total_irr);

        $slots=$this->getJson('/api/booking/availability?counselor_id='.$counselor.'&service_id='.$service.'&date='.$date->toDateString())
            ->assertOk()->json('slots');
        $this->assertFalse($slots[0]['available']);
        $this->assertTrue($slots[1]['available']);
    }

    public function test_expired_holds_release_availability_and_maintenance_expires_them(): void
    {
        [$client,,$counselor,$service,$date]=$this->setupBookingData();
        $token=$client->createToken('client',['client'])->plainTextToken;
        $booking=$this->withToken($token)->postJson('/api/booking/reservations',[
            'counselor_id'=>$counselor,'service_id'=>$service,'starts_at'=>$date->copy()->addHours(9)->toIso8601String(),
            'mode'=>'online','payment_option'=>'full',
        ])->assertCreated()->json('data.id');
        DB::table('bookings')->where('id',$booking)->update(['hold_expires_at'=>now()->subSecond()]);
        $this->getJson('/api/booking/availability?counselor_id='.$counselor.'&service_id='.$service.'&date='.$date->toDateString())
            ->assertJsonPath('slots.0.available',true);
        $this->artisan('booking:maintain')->assertSuccessful();
        $this->assertSame('expired',DB::table('bookings')->where('id',$booking)->value('status'));
    }

    public function test_client_rescheduling_rechecks_slot_and_writes_history_and_notices(): void
    {
        [$client,,$counselor,$service,$date]=$this->setupBookingData();
        $token=$client->createToken('client',['client'])->plainTextToken;
        $booking=$this->withSanctumToken($token)->postJson('/api/booking/reservations',[
            'counselor_id'=>$counselor,'service_id'=>$service,'starts_at'=>$date->copy()->addHours(9)->toIso8601String(),
            'mode'=>'online','payment_option'=>'full',
        ])->assertCreated()->json('data.id');
        $newStart=$date->copy()->addHours(10);
        $this->withSanctumToken($token)->patchJson("/api/booking/my-reservations/$booking/reschedule",[
            'starts_at'=>$newStart->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.status','held');
        $this->assertSame($newStart->copy()->utc()->toDateTimeString(),DB::table('bookings')->where('id',$booking)->value('starts_at'));
        $this->assertSame('booking.rescheduled_by_client',DB::table('booking_audit_events')->where('booking_id',$booking)->value('action'));
        $this->assertSame(2,DB::table('booking_in_app_notifications')->where('booking_id',$booking)->count());
        $this->getJson('/api/booking/availability?counselor_id='.$counselor.'&service_id='.$service.'&date='.$date->toDateString())
            ->assertJsonPath('slots.0.available',true)->assertJsonPath('slots.1.available',false);
    }

    public function test_time_off_blocks_slots_and_out_of_scope_roles_are_forbidden(): void
    {
        [$client,$counselorUser,$counselor,$service,$date]=$this->setupBookingData();
        $admin=User::create(['name'=>'Reception','email'=>'reception@example.test','password'=>'password-123456',
            'admin_permissions'=>['appointments.manage']]);
        $adminToken=$admin->createToken('admin',['admin-panel'])->plainTextToken;
        $starts=$date->copy()->addHours(9);
        $this->withSanctumToken($adminToken)->postJson('/api/admin/booking/counselors/'.$counselor.'/time-off',[
            'starts_at'=>$starts->toIso8601String(),'ends_at'=>$starts->copy()->addHour()->toIso8601String(),'reason'=>'Leave',
        ])->assertCreated();
        $this->getJson('/api/booking/availability?counselor_id='.$counselor.'&service_id='.$service.'&date='.$date->toDateString())
            ->assertJsonPath('slots.0.available',false);
        $clientToken=$client->createToken('client',['client'])->plainTextToken;
        $this->withSanctumToken($clientToken)->getJson('/api/counselor/appointments')->assertForbidden();
        $counselorToken=$counselorUser->createToken('counselor',['counselor'])->plainTextToken;
        $this->withSanctumToken($counselorToken)->getJson('/api/admin/booking/calendar?date='.$date->toDateString())->assertForbidden();
    }

    public function test_only_a_client_scoped_non_admin_can_create_a_client_booking(): void
    {
        [$client,$counselorUser,$counselor,$service,$date]=$this->setupBookingData();
        $start=$date->copy()->addHours(9)->toIso8601String();
        $payload=[
            'counselor_id'=>$counselor,
            'service_id'=>$service,
            'starts_at'=>$start,
            'mode'=>'in_person',
            'session_mode'=>'in_person',
            'payment_option'=>'full',
        ];

        $this->postJson('/api/booking/reservations',$payload)->assertUnauthorized();

        $admin=User::create(['name'=>'Admin booking auth','email'=>'admin-booking-auth@example.test',
            'password'=>'password-123456','is_admin'=>true]);
        $this->postJson('/api/client/login',['email'=>$admin->email,'password'=>'password-123456'])
            ->assertUnprocessable()->assertJsonPath('message','Invalid credentials');
        $adminToken=$admin->createToken('admin',['admin-panel'])->plainTextToken;
        $this->withSanctumToken($adminToken)->postJson('/api/booking/reservations',$payload)
            ->assertForbidden()->assertJsonPath('message','A client account is required for this action.')
            ->assertJsonMissingPath('exception')->assertJsonMissingPath('trace');

        $this->postJson('/api/client/login',['email'=>$counselorUser->email,'password'=>'password-123456'])
            ->assertUnprocessable()->assertJsonPath('message','Invalid credentials');
        $counselorToken=$counselorUser->createToken('counselor',['counselor'])->plainTextToken;
        $this->withSanctumToken($counselorToken)->postJson('/api/booking/reservations',$payload)
            ->assertForbidden()->assertJsonPath('message','A client account is required for this action.')
            ->assertJsonMissingPath('exception')->assertJsonMissingPath('trace');

        $clientToken=$client->createToken('client',['client'])->plainTextToken;
        $created=$this->withSanctumToken($clientToken)->postJson('/api/booking/reservations',$payload)
            ->assertCreated()->assertJsonPath('data.status','held')->json('data');
        $this->assertDatabaseHas('bookings',['id'=>$created['id'],'user_id'=>$client->id,'counselor_id'=>$counselor]);
        $this->assertSame(1,DB::table('bookings')->count());
    }

    public function test_counselor_can_request_time_off_for_themselves_without_selecting_another_counselor(): void
    {
        [, $counselorUser,$counselor,, $date]=$this->setupBookingData();
        $token=$counselorUser->createToken('counselor',['counselor'])->plainTextToken;
        $start=$date->copy()->addDay()->setTime(13,0);
        $this->withSanctumToken($token)->postJson('/api/counselor/time-off',[
            'starts_at'=>$start->toIso8601String(),'ends_at'=>$start->copy()->addHour()->toIso8601String(),'reason'=>'Personal leave',
        ])->assertCreated();
        $this->assertSame(1,DB::table('counselor_time_off')->where('counselor_id',$counselor)->count());
    }

    public function test_reception_can_create_and_search_booking_and_counselor_can_mark_session(): void
    {
        [$client,$counselorUser,$counselor,$service,$date]=$this->setupBookingData();
        $admin=User::create(['name'=>'Reception','email'=>'reception-2@example.test','password'=>'password-123456',
            'admin_permissions'=>['appointments.manage']]);
        $adminToken=$admin->createToken('admin',['admin-panel'])->plainTextToken;
        $this->withSanctumToken($adminToken)->postJson('/api/admin/booking/clients',[
            'name'=>'Reception CRM client','email'=>'reception-crm@example.test','phone'=>'09129998877',
        ])->assertCreated()->assertJsonMissingPath('data.password');
        $this->withSanctumToken($adminToken)->getJson('/api/admin/booking/clients?q=Reception%20CRM')
            ->assertOk()->assertJsonPath('data.0.name','Reception CRM client');
        $created=$this->withSanctumToken($adminToken)->postJson('/api/admin/booking/reservations',[
            'user_id'=>$client->id,'counselor_id'=>$counselor,'service_id'=>$service,
            'starts_at'=>$date->copy()->addHours(9)->toIso8601String(),'mode'=>'in_person','payment_option'=>'full',
        ])->assertCreated()->json('data');
        $this->withSanctumToken($adminToken)->getJson('/api/admin/booking/reservations?q=Booking%20client')
            ->assertOk()->assertJsonPath('data.data.0.id',$created['id']);
        $this->withSanctumToken($adminToken)->getJson('/api/admin/booking/calendar?date='.$date->toDateString().'&view=day')
            ->assertOk()->assertJsonPath('data.0.id',$created['id'])->assertJsonPath('range.view','day');
        $this->withSanctumToken($adminToken)->getJson('/api/admin/booking/calendar?date='.$date->toDateString().'&view=week')
            ->assertOk()->assertJsonPath('data.0.id',$created['id'])->assertJsonPath('range.view','week');
        $this->withSanctumToken($adminToken)->getJson('/api/admin/booking/calendar?date='.$date->toDateString().'&view=month')
            ->assertOk()->assertJsonPath('data.0.id',$created['id'])->assertJsonPath('range.view','month');
        $this->withSanctumToken($adminToken)->patchJson('/api/admin/booking/reservations/'.$created['id'].'/status',['status'=>'completed'])
            ->assertStatus(409);
        $this->withSanctumToken($adminToken)->postJson('/api/admin/booking/reservations/'.$created['id'].'/payments/in-person',[
            'amount_irr'=>1200000,'payment_method'=>'cash','idempotency_key'=>'manual-cash-1','reference'=>'cash-receipt-1',
        ])->assertCreated();
        $counselorToken=$counselorUser->createToken('counselor',['counselor'])->plainTextToken;
        $this->withSanctumToken($counselorToken)->patchJson('/api/counselor/appointments/'.$created['id'].'/status',['status'=>'completed'])
            ->assertOk()->assertJsonPath('data.status','completed');
        $this->withSanctumToken($counselorToken)->postJson('/api/counselor/appointments/'.$created['id'].'/notes',['body'=>'Private session note'])
            ->assertCreated();
        $this->withSanctumToken($client->createToken('client',['client'])->plainTextToken)
            ->getJson('/api/counselor/appointments/'.$created['id'].'/notes')->assertForbidden();
        $otherCounselorUser=User::create(['name'=>'Other counselor','email'=>'other-counselor-api@example.test','password'=>'password-123456']);
        DB::table('counselors')->insert(['name'=>'Other counselor','slug'=>'other-counselor-api','user_id'=>$otherCounselorUser->id,
            'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $otherCounselorToken=$otherCounselorUser->createToken('counselor',['counselor'])->plainTextToken;
        $this->withSanctumToken($otherCounselorToken)->getJson('/api/counselor/appointments')->assertOk()
            ->assertJsonCount(0,'data.data');
        $this->withSanctumToken($otherCounselorToken)->getJson('/api/counselor/appointments/'.$created['id'].'/notes')->assertNotFound();

        $second=$this->withSanctumToken($adminToken)->postJson('/api/admin/booking/reservations',[
            'user_id'=>$client->id,'counselor_id'=>$counselor,'service_id'=>$service,
            'starts_at'=>$date->copy()->addHours(10)->toIso8601String(),'mode'=>'in_person','payment_option'=>'full',
        ])->assertCreated()->json('data');
        $this->withSanctumToken($adminToken)->postJson('/api/admin/booking/reservations/'.$second['id'].'/payments/in-person',[
            'amount_irr'=>1200000,'payment_method'=>'cash','idempotency_key'=>'manual-cash-2','reference'=>'cash-receipt-2',
        ])->assertCreated();
        $this->withSanctumToken($counselorToken)->patchJson('/api/counselor/appointments/'.$second['id'].'/status',['status'=>'no_show'])
            ->assertOk()->assertJsonPath('data.status','no_show');
    }
}
