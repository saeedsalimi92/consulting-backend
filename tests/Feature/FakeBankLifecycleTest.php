<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BookingPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FakeBankLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function clientAndSlot(?array $taxRule = null): array
    {
        config(['booking.payment_driver' => 'fake_bank']);
        DB::table('booking_gateway_settings')->updateOrInsert(['provider' => 'fake_bank'], [
            'enabled' => true, 'mode' => 'sandbox', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $client = User::create(['name' => 'Lifecycle client','first_name'=>'Lifecycle','last_name'=>'Client','phone'=>'+989124444444','phone_verified_at'=>now(), 'email' => 'lifecycle@example.test', 'password' => 'long-password-123']);
        $counselorUser=User::create(['name'=>'Lifecycle counselor account','email'=>'lifecycle-counselor@example.test','password'=>'long-password-123']);
        User::create(['name'=>'Lifecycle admin','email'=>'lifecycle-admin@example.test','password'=>'long-password-123','is_admin'=>true]);
        $counselor = DB::table('counselors')->insertGetId(['name' => 'Lifecycle counselor', 'slug' => 'lifecycle-counselor','user_id'=>$counselorUser->id,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $service = DB::table('booking_services')->insertGetId(['title' => 'Lifecycle service', 'duration_minutes' => 60,
            'price_irr' => 1000000, 'deposit_irr' => 300000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('counselor_booking_services')->insert(['counselor_id' => $counselor, 'booking_service_id' => $service,
            'commission_type' => 'percentage', 'commission_value' => 25]);
        if($taxRule) DB::table('booking_service_tax_rules')->insert([
            'booking_service_id'=>$service,'rate_basis_points'=>$taxRule['rate_basis_points'],'enabled'=>$taxRule['enabled']??true,
            'inclusive'=>$taxRule['inclusive'],'effective_from'=>now('Asia/Tehran')->toDateString(),
            'effective_to'=>null,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $day = now('Asia/Tehran')->addDays(3)->startOfDay();
        DB::table('counselor_working_hours')->insert(['counselor_id' => $counselor, 'weekday' => $day->dayOfWeek,
            'starts_at' => '09:00:00', 'ends_at' => '12:00:00', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $token = $client->createToken('client', ['client'])->plainTextToken;
        $booking = $this->withToken($token)->postJson('/api/booking/reservations', [
            'counselor_id' => $counselor, 'service_id' => $service,
            'starts_at' => $day->copy()->addHours(9)->toIso8601String(), 'mode' => 'online',
            'payment_option' => 'full',
        ])->assertCreated()->json('data.id');
        return [$client, $token, $booking, $service];
    }

    private function beginCheckout(string $token, int $booking, string $key, int $amount = 1000000): array
    {
        return $this->withToken($token)->postJson("/api/booking/my-reservations/$booking/payments", [
            'amount_irr' => $amount, 'idempotency_key' => $key,
        ])->assertCreated()->json('data');
    }

    public function test_success_is_persisted_once_and_replay_returns_same_receipt(): void
    {
        [, $token, $booking] = $this->clientAndSlot();
        $payment = $this->beginCheckout($token, $booking, 'success-once');
        $this->get('/api/booking/fake-bank/checkout/'.$payment['payment_id'].'?token='.$payment['checkout_token'])
            ->assertOk()->assertSee('پرداخت موفق')->assertSee('پرداخت ناموفق')->assertSee('پرداخت آزمایشی');
        $this->getJson("/api/booking/fake-bank/payments/{$payment['payment_id']}?token={$payment['checkout_token']}")
            ->assertOk()->assertJsonPath('data.label','TEST PAYMENT')->assertJsonPath('data.amount_irr',1000000);
        $payload = ['token' => $payment['checkout_token'], 'result' => 'success','amount_irr'=>1];
        $result = $this->postJson("/api/booking/fake-bank/payments/{$payment['payment_id']}/result", $payload)
            ->assertOk()->assertJsonPath('data.status', 'verified')->json('data');
        $this->postJson("/api/booking/fake-bank/payments/{$payment['payment_id']}/result", $payload)
            ->assertOk()->assertJsonPath('data.receipt', $result['receipt']);
        $this->assertSame('confirmed', DB::table('bookings')->where('id', $booking)->value('status'));
        $this->assertSame(0,(int)DB::table('bookings')->where('id',$booking)->value('tax_irr'));
        $this->assertSame(1, DB::table('booking_receipts')->where('booking_id', $booking)->count());
        $this->assertSame(3,DB::table('booking_in_app_notifications')->where('booking_id',$booking)
            ->where('event_key','payment-success-'.$payment['payment_id'])->count());
        $this->assertSame(3, DB::table('booking_ledger_entries')->where('event_key', 'payment:'.$payment['payment_id'])->count());
        $this->assertSame(1, DB::table('booking_ledger_entries')->where('event_key', 'payment:'.$payment['payment_id'])
            ->where('account', 'cash')->count());
        $this->assertSame(1000000, (int) DB::table('booking_ledger_entries')->where('event_key', 'payment:'.$payment['payment_id'])
            ->where('direction', 'debit')->sum('amount_irr'));
        $this->assertSame(1000000, (int) DB::table('booking_ledger_entries')->where('event_key', 'payment:'.$payment['payment_id'])
            ->where('direction', 'credit')->sum('amount_irr'));
        $this->assertSame(250000, (int) DB::table('booking_ledger_entries')->where('event_key', 'payment:'.$payment['payment_id'])
            ->where('account', 'counselor_payable')->sum('amount_irr'));
        $this->withSanctumToken($token)->postJson("/api/booking/my-reservations/$booking/payments",[
            'amount_irr'=>1,'idempotency_key'=>'already-paid-attempt',
        ])->assertStatus(422);
        $this->withToken($token)->getJson("/api/booking/my-reservations/$booking/payments")->assertOk()
            ->assertJsonPath('data.0.status','verified');
        $this->withToken($token)->getJson("/api/booking/my-reservations/$booking/receipts")->assertOk()
            ->assertJsonPath('data.0.receipt_number',$result['receipt']);
        $this->withSanctumToken($token)->getJson('/api/booking/my-reservations')->assertOk()
            ->assertJsonPath('data.0.id',$booking);
    }

    public function test_failure_creates_no_paid_entries_and_a_new_attempt_can_succeed(): void
    {
        [, $token, $booking] = $this->clientAndSlot();
        $failed = $this->beginCheckout($token, $booking, 'failure-first');
        $this->postJson("/api/booking/fake-bank/payments/{$failed['payment_id']}/result", [
            'token' => $failed['checkout_token'], 'result' => 'failure',
        ])->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame('held', DB::table('bookings')->where('id', $booking)->value('status'));
        $this->assertSame(0, DB::table('booking_ledger_entries')->where('booking_id', $booking)->count());
        $success = $this->beginCheckout($token, $booking, 'retry-success');
        $this->postJson("/api/booking/fake-bank/payments/{$success['payment_id']}/result", [
            'token' => $success['checkout_token'], 'result' => 'success',
        ])->assertOk();
        $this->assertSame(1, DB::table('booking_receipts')->where('booking_id', $booking)->count());
        $this->assertSame(1, DB::table('booking_ledger_entries')->where('booking_id', $booking)->where('account', 'cash')->count());
    }

    public function test_checkout_rejects_bad_or_expired_token_and_payment_after_hold_expiration(): void
    {
        [, $token, $booking] = $this->clientAndSlot();
        $payment = $this->beginCheckout($token, $booking, 'token-boundary');
        $path="/api/booking/fake-bank/payments/{$payment['payment_id']}";
        $this->getJson($path.'?token='.str_repeat('x',64))->assertNotFound();
        $this->postJson($path.'/result',['token'=>str_repeat('x',64),'result'=>'success'])->assertNotFound();
        DB::table('booking_payments')->where('id',$payment['payment_id'])->update(['checkout_expires_at'=>now()->subSecond()]);
        $this->getJson($path.'?token='.$payment['checkout_token'])->assertStatus(410);
        $this->postJson($path.'/result',['token'=>$payment['checkout_token'],'result'=>'success'])->assertStatus(410);

        $fresh=$this->beginCheckout($token,$booking,'expired-hold-payment');
        DB::table('bookings')->where('id',$booking)->update(['hold_expires_at'=>now()->subSecond()]);
        $this->postJson("/api/booking/fake-bank/payments/{$fresh['payment_id']}/result",[
            'token'=>$fresh['checkout_token'],'result'=>'success'])->assertStatus(409);
        $this->artisan('booking:maintain')->assertSuccessful();
        $this->assertSame('expired',DB::table('bookings')->where('id',$booking)->value('status'));
        $this->assertSame('failed',DB::table('booking_payments')->where('id',$fresh['payment_id'])->value('status'));
        $this->assertSame(0,DB::table('booking_ledger_entries')->count());
    }

    public function test_inclusive_tax_snapshot_posts_balanced_tax_and_ignores_later_rule_changes(): void
    {
        [$client,$token,$booking,$service]=$this->clientAndSlot(['rate_basis_points'=>1000,'inclusive'=>true]);
        $otherService=DB::table('booking_services')->insertGetId(['title'=>'Tax exempt service','duration_minutes'=>60,
            'price_irr'=>1000000,'deposit_irr'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $exempt=BookingPricing::snapshot($otherService,1000000,'percentage','25','full',0);
        $this->assertSame(0,$exempt['tax_irr']);
        $snapshot=DB::table('bookings')->find($booking);
        $this->assertSame(1000000,(int)$snapshot->total_irr);
        $this->assertSame(90909,(int)$snapshot->tax_irr);
        DB::table('booking_service_tax_rules')->where('booking_service_id',$service)->update(['rate_basis_points'=>2000]);
        $payment=$this->beginCheckout($token,$booking,'tax-snapshot');
        $this->postJson("/api/booking/fake-bank/payments/{$payment['payment_id']}/result",[
            'token'=>$payment['checkout_token'],'result'=>'success'])->assertOk();
        $entries=DB::table('booking_ledger_entries')->where('event_key','payment:'.$payment['payment_id'])->get();
        $this->assertSame(90909,(int)$entries->where('account','tax_liability')->sum('amount_irr'));
        $this->assertSame(1000000,(int)$entries->where('direction','debit')->sum('amount_irr'));
        $this->assertSame(1000000,(int)$entries->where('direction','credit')->sum('amount_irr'));
    }

    public function test_exclusive_tax_adds_to_payable_price_and_failed_result_is_idempotent(): void
    {
        [$client,$token,$booking]=$this->clientAndSlot(['rate_basis_points'=>1000,'inclusive'=>false]);
        $snapshot=DB::table('bookings')->find($booking);
        $this->assertSame(1100000,(int)$snapshot->total_irr);
        $this->assertSame(100000,(int)$snapshot->tax_irr);
        $failed=$this->beginCheckout($token,$booking,'failure-idempotent',1100000);
        $path="/api/booking/fake-bank/payments/{$failed['payment_id']}/result";
        $payload=['token'=>$failed['checkout_token'],'result'=>'failure'];
        $this->postJson($path,$payload)->assertOk()->assertJsonPath('data.status','failed');
        $this->postJson($path,$payload)->assertOk()->assertJsonPath('data.status','failed');
        $this->assertSame(0,DB::table('booking_ledger_entries')->count());
        $this->assertSame(1,DB::table('booking_payments')->where('status','failed')->count());
    }

    public function test_configured_sms_rule_is_scheduled_once_and_uses_fake_provider(): void
    {
        [$client,$token,$booking]=$this->clientAndSlot();
        $client->update(['phone'=>'+989121234567']);
        $payment=$this->beginCheckout($token,$booking,'confirm-for-sms');
        $this->postJson("/api/booking/fake-bank/payments/{$payment['payment_id']}/result",['token'=>$payment['checkout_token'],'result'=>'success'])->assertOk();
        $starts=now()->utc()->addMinutes(30)->startOfMinute();
        DB::table('bookings')->where('id',$booking)->update(['starts_at'=>$starts->toDateTimeString(),'ends_at'=>$starts->copy()->addHour()->toDateTimeString()]);
        DB::table('user_notification_preferences')->insert(['user_id'=>$client->id,'sms_enabled'=>true,'sms_consent_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('booking_sms_reminder_rules')->insert(['name'=>'One hour','timing_type'=>'relative','minutes_before'=>60,'local_time'=>null,
            'recipients'=>'["client"]','modes'=>null,'template'=>'{{client_name}} {{appointment_time}}','max_attempts'=>2,'retry_delay_seconds'=>60,
            'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        config(['booking.sms_driver'=>'fake']);
        $this->artisan('booking:schedule-sms-reminders')->assertSuccessful();
        $row=DB::table('booking_sms_reminders')->where('booking_id',$booking)->first();
        $this->assertNotNull($row);
        $this->assertSame('simulated',$row->status);
        $this->assertSame(1,(int)$row->attempts);
        $this->artisan('booking:schedule-sms-reminders')->assertSuccessful();
        $this->assertSame(1,DB::table('booking_sms_reminders')->where('booking_id',$booking)->count());
    }
}
