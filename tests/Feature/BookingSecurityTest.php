<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['booking.payment_driver'=>'fake_bank','queue.default'=>'sync']);
        $client=User::create(['name'=>'Test client','first_name'=>'Test','last_name'=>'Client','phone'=>'+989123333333','phone_verified_at'=>now(),'email'=>'client@example.test','password'=>'long-password-123']);
        $staff=User::create(['name'=>'Test staff','email'=>'staff@example.test','password'=>'long-password-123',
            'admin_permissions'=>['appointments.manage']]);
        $counselor=DB::table('counselors')->insertGetId(['name'=>'Test counselor','slug'=>'test-counselor',
            'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $service=DB::table('booking_services')->insertGetId(['title'=>'Test session','duration_minutes'=>60,
            'price_irr'=>1000000,'deposit_irr'=>300000,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('counselor_booking_services')->insert(['counselor_id'=>$counselor,'booking_service_id'=>$service,
            'commission_type'=>'percentage','commission_value'=>25]);
        $day=now('Asia/Tehran')->addDays(3)->startOfDay();
        DB::table('counselor_working_hours')->insert(['counselor_id'=>$counselor,'weekday'=>$day->dayOfWeek,
            'starts_at'=>'09:00:00','ends_at'=>'12:00:00','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $booking=DB::table('bookings')->insertGetId(['user_id'=>$client->id,'created_by'=>$client->id,
            'counselor_id'=>$counselor,'booking_service_id'=>$service,'mode'=>'online',
            'starts_at'=>$day->copy()->addHours(9)->utc()->toDateTimeString(),
            'ends_at'=>$day->copy()->addHours(10)->utc()->toDateTimeString(),
            'status'=>'held','payment_option'=>'full','total_irr'=>1000000,'required_irr'=>1000000,
            'commission_total_irr'=>250000,'duration_minutes'=>60,
            'hold_expires_at'=>now()->addMinutes(10),'created_at'=>now(),'updated_at'=>now()]);
        return [$client,$staff,$booking,$day];
    }

    public function test_full_payment_replay_returns_existing_attempt(): void
    {
        [$client,,$booking]=$this->fixture();
        $token=$client->createToken('client',['client'])->plainTextToken;
        $path="/api/booking/my-reservations/$booking/payments";
        $payload=['amount_irr'=>1000000,'idempotency_key'=>'full-payment-test'];
        $created=$this->withToken($token)->postJson($path,$payload)->assertCreated()->json('data');
        $this->postJson("/api/booking/fake-bank/payments/{$created['payment_id']}/result",[
            'token'=>$created['checkout_token'],'result'=>'success'])->assertOk();
        $this->postJson($path,$payload)->assertOk()->assertJsonPath('data.payment_id',$created['payment_id']);
        $this->assertSame(1,DB::table('booking_payments')->count());
    }

    public function test_reschedule_rejects_expired_hold_and_pending_cancellation(): void
    {
        [$client,$staff,$booking,$day]=$this->fixture();
        $token=$staff->createToken('admin-panel',['admin-panel'])->plainTextToken;
        $path="/api/admin/booking/reservations/$booking/reschedule";
        $payload=['starts_at'=>$day->copy()->addHours(10)->toIso8601String()];
        DB::table('bookings')->where('id',$booking)->update(['hold_expires_at'=>now()->subMinute()]);
        $this->withToken($token)->patchJson($path,$payload)->assertStatus(409);
        DB::table('bookings')->where('id',$booking)->update(['status'=>'confirmed','hold_expires_at'=>null]);
        DB::table('booking_refunds')->insert(['booking_id'=>$booking,'requested_by'=>$client->id,
            'base_amount_irr'=>0,'penalty_irr'=>0,'refund_amount_irr'=>0,'status'=>'requested',
            'created_at'=>now(),'updated_at'=>now()]);
        $this->patchJson($path,$payload)->assertStatus(409);
    }

    public function test_cash_replay_requires_same_method_and_reference(): void
    {
        [,$staff,$booking]=$this->fixture();
        $token=$staff->createToken('admin-panel',['admin-panel'])->plainTextToken;
        $path="/api/admin/booking/reservations/$booking/payments/in-person";
        $payload=['amount_irr'=>1000000,'idempotency_key'=>'cash-payment-test',
            'payment_method'=>'cash','reference'=>'receipt-1'];
        $this->withToken($token)->postJson($path,$payload)->assertStatus(409);
        DB::table('bookings')->where('id',$booking)->update(['mode'=>'in_person']);
        $this->withToken($token)->postJson($path,$payload)->assertCreated();
        $this->postJson($path,$payload)->assertOk();
        $this->postJson($path,[...$payload,'payment_method'=>'pos'])->assertStatus(409);
        $this->postJson($path,[...$payload,'reference'=>'receipt-2'])->assertStatus(409);
        $this->assertSame(1,DB::table('booking_payments')->count());
    }
}
