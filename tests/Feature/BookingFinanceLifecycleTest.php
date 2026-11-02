<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingFinanceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function paidBooking(): array
    {
        config(['booking.payment_driver'=>'fake_bank']);
        DB::table('booking_gateway_settings')->updateOrInsert(['provider'=>'fake_bank'],[
            'enabled'=>true,'mode'=>'sandbox','created_at'=>now(),'updated_at'=>now(),
        ]);
        $client=User::create(['name'=>'Finance client','first_name'=>'Finance','last_name'=>'Client','phone'=>'+989122222222','phone_verified_at'=>now(),'email'=>'finance-client@example.test','password'=>'password-123456']);
        $counselorUser=User::create(['name'=>'Finance counselor','email'=>'finance-counselor@example.test','password'=>'password-123456']);
        $counselor=DB::table('counselors')->insertGetId(['name'=>'Finance counselor','slug'=>'finance-counselor','user_id'=>$counselorUser->id,
            'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $service=DB::table('booking_services')->insertGetId(['title'=>'Finance session','duration_minutes'=>60,'price_irr'=>1000000,
            'deposit_irr'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('counselor_booking_services')->insert(['counselor_id'=>$counselor,'booking_service_id'=>$service,
            'commission_type'=>'percentage','commission_value'=>25]);
        $date=now('Asia/Tehran')->addDays(4)->startOfDay();
        DB::table('counselor_working_hours')->insert(['counselor_id'=>$counselor,'weekday'=>$date->dayOfWeek,'starts_at'=>'09:00:00',
            'ends_at'=>'11:00:00','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $clientToken=$client->createToken('client',['client'])->plainTextToken;
        $booking=$this->withSanctumToken($clientToken)->postJson('/api/booking/reservations',[
            'counselor_id'=>$counselor,'service_id'=>$service,'starts_at'=>$date->copy()->addHours(9)->toIso8601String(),
            'mode'=>'online','payment_option'=>'full',
        ])->assertCreated()->json('data.id');
        $init=$this->withSanctumToken($clientToken)->postJson("/api/booking/my-reservations/$booking/payments",[
            'amount_irr'=>1000000,'idempotency_key'=>'finance-payment',
        ])->assertCreated()->json('data');
        $this->postJson("/api/booking/fake-bank/payments/{$init['payment_id']}/result",[
            'token'=>$init['checkout_token'],'result'=>'success',
        ])->assertOk();
        return [$client,$counselorUser,$counselor,$booking,$clientToken];
    }

    public function test_payment_receipt_commission_settlement_and_over_settlement_guard(): void
    {
        [$client,$counselorUser,$counselor,$booking]=$this->paidBooking();
        $this->assertSame(1000000,(int)DB::table('bookings')->where('id',$booking)->value('paid_irr'));
        $this->assertSame(250000,(int)DB::table('booking_ledger_entries')->where('booking_id',$booking)
            ->where('account','counselor_payable')->where('direction','credit')->sum('amount_irr'));
        $this->assertSame(750000,(int)DB::table('booking_ledger_entries')->where('booking_id',$booking)
            ->where('account','center_revenue')->where('direction','credit')->sum('amount_irr'));
        $receipt=DB::table('booking_receipts')->where('booking_id',$booking)->first();
        $this->assertNotNull($receipt);
        $this->assertSame('fake_bank',$receipt->provider);
        $this->assertSame(1000000,(int)$receipt->amount_irr);

        $counselorToken=$counselorUser->createToken('counselor',['counselor'])->plainTextToken;
        $this->withSanctumToken($counselorToken)->getJson('/api/counselor/appointments')->assertOk()
            ->assertJsonPath('data.data.0.id',$booking);
        $this->withSanctumToken($counselorToken)->getJson('/api/counselor/finances')->assertOk()
            ->assertJsonPath('data.earned_irr',250000)->assertJsonPath('data.payable_irr',250000);
        $admin=User::create(['name'=>'Finance admin','email'=>'finance-admin@example.test','password'=>'password-123456','is_admin'=>true]);
        $adminToken=$admin->createToken('admin',['admin-panel'])->plainTextToken;
        $this->withSanctumToken($adminToken)->getJson('/api/admin/booking/gateways')->assertOk()
            ->assertJsonPath('active_provider','fake_bank')->assertJsonPath('data.0.implemented',true)
            ->assertJsonPath('data.1.implemented',false);
        $this->withSanctumToken($adminToken)->putJson('/api/admin/booking/gateways',[
            'active_provider'=>'zarinpal','enabled'=>true,'mode'=>'sandbox',
        ])->assertStatus(422);
        $this->withSanctumToken($adminToken)->getJson('/api/admin/booking/reports?counselor_id='.$counselor.'&provider=fake_bank&group=day')
            ->assertOk()->assertJsonPath('data.bookings',1)->assertJsonPath('data.collected_irr',1000000);
        $this->withSanctumToken($adminToken)->getJson('/api/admin/booking/finance/dashboard?counselor_id='.$counselor)
            ->assertOk()->assertJsonPath('data.total_revenue_irr',1000000)
            ->assertJsonPath('data.center_revenue_irr',750000)->assertJsonPath('data.counselor_payable_irr',250000)
            ->assertJsonPath('data.service_revenue.0.revenue_irr',1000000)
            ->assertJsonPath('data.counselor_revenue.0.revenue_irr',1000000);
        $this->withSanctumToken($adminToken)->postJson('/api/admin/booking/settlements',[
            'counselor_id'=>$counselor,'amount_irr'=>250000,'reference'=>'SETTLE-FULL-1',
        ])->assertCreated();
        $this->withSanctumToken($adminToken)->getJson("/api/admin/booking/counselors/$counselor/payable")
            ->assertOk()->assertJsonPath('data.payable_irr',0)->assertJsonPath('data.settled_irr',250000);
        $this->withSanctumToken($adminToken)->postJson('/api/admin/booking/settlements',[
            'counselor_id'=>$counselor,'amount_irr'=>1,'reference'=>'SETTLE-OVER-1',
        ])->assertStatus(422);
        $this->assertSame(1,DB::table('booking_settlements')->where('counselor_id',$counselor)->count());
        $this->assertSame(1,DB::table('booking_ledger_entries')->where('event_key','like','settlement:%')->where('account','cash')->count());
    }

    public function test_refund_after_settlement_posts_balanced_recovery_entries_once(): void
    {
        [$client,,$counselor,$booking,$clientToken]=$this->paidBooking();
        $admin=User::create(['name'=>'Refund admin','email'=>'refund-admin@example.test','password'=>'password-123456','is_admin'=>true]);
        $adminToken=$admin->createToken('admin',['admin-panel'])->plainTextToken;
        $this->withSanctumToken($adminToken)->postJson('/api/admin/booking/settlements',[
            'counselor_id'=>$counselor,'amount_irr'=>250000,'reference'=>'SETTLE-BEFORE-REFUND',
        ])->assertCreated();
        $refund=$this->withSanctumToken($clientToken)->postJson("/api/booking/my-reservations/$booking/cancellation-request",[
            'reason'=>'Cancellation by client',
        ])->assertCreated()->json('data');
        $this->assertSame(900000,(int)$refund['refund_amount_irr']);
        $this->withSanctumToken($adminToken)->patchJson("/api/admin/booking/refunds/{$refund['id']}",[
            'decision'=>'approved',
        ])->assertOk();
        $this->withSanctumToken($adminToken)->postJson("/api/admin/booking/refunds/{$refund['id']}/record-transfer",[
            'reference'=>'FAKE-REFUND-1',
        ])->assertOk()->assertJsonPath('data.status','refunded');
        $this->withSanctumToken($adminToken)->postJson("/api/admin/booking/refunds/{$refund['id']}/record-transfer",[
            'reference'=>'FAKE-REFUND-REPLAY',
        ])->assertStatus(409);
        $event='refund:'.$refund['id'];
        $this->assertSame(900000,(int)DB::table('booking_ledger_entries')->where('event_key',$event)
            ->where('direction','debit')->sum('amount_irr'));
        $this->assertSame(900000,(int)DB::table('booking_ledger_entries')->where('event_key',$event)
            ->where('direction','credit')->sum('amount_irr'));
        $this->assertSame(225000,(int)DB::table('booking_ledger_entries')->where('event_key',$event)
            ->where('account','counselor_recoverable')->sum('amount_irr'));
        $this->assertSame(675000,(int)DB::table('booking_ledger_entries')->where('event_key',$event)
            ->where('account','center_revenue')->where('direction','debit')->sum('amount_irr'));
        $this->assertSame('cancelled',DB::table('bookings')->where('id',$booking)->value('status'));
        $this->assertSame(0,(int)DB::table('booking_ledger_entries')->where('event_key',$event)
            ->where('account','counselor_payable')->sum('amount_irr'));
        $this->assertSame(1,DB::table('booking_refunds')->where('id',$refund['id'])->where('status','refunded')->count());
    }
}
