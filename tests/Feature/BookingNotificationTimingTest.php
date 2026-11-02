<?php

namespace Tests\Feature;

use App\Models\User;
use App\Jobs\SendBookingNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingNotificationTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function confirmedMeetBooking(): array
    {
        Carbon::setTestNow(Carbon::parse('2032-10-01 08:59:00','Asia/Tehran'));
        config(['booking.connection_release_minutes'=>10,'booking.sms_driver'=>'log']);
        $client=User::create(['name'=>'Notify client','email'=>'notify@example.test','password'=>'password-123456']);
        $counselorUser=User::create(['name'=>'Notify counselor','email'=>'notify-c@example.test','password'=>'password-123456']);
        $admin=User::create(['name'=>'Notify reception','email'=>'notify-admin@example.test','password'=>'password-123456',
            'is_admin'=>true]);
        $counselor=DB::table('counselors')->insertGetId(['name'=>'Notify counselor','slug'=>'notify-counselor','user_id'=>$counselorUser->id,
            'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $service=DB::table('booking_services')->insertGetId(['title'=>'Notify service','duration_minutes'=>60,'price_irr'=>100000,
            'deposit_irr'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $start=Carbon::parse('2032-10-01 09:10:00','Asia/Tehran');
        DB::table('counselor_booking_services')->insert(['counselor_id'=>$counselor,'booking_service_id'=>$service,
            'commission_type'=>'percentage','commission_value'=>25]);
        DB::table('counselor_working_hours')->insert(['counselor_id'=>$counselor,'weekday'=>$start->dayOfWeek,
            'starts_at'=>'09:10:00','ends_at'=>'11:10:00','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $booking=DB::table('bookings')->insertGetId(['user_id'=>$client->id,'created_by'=>$client->id,'counselor_id'=>$counselor,
            'booking_service_id'=>$service,'mode'=>'online','session_mode'=>'meet','session_connection'=>'https://meet.google.com/old-room',
            'session_instructions'=>'Use the waiting room','starts_at'=>$start->copy()->utc()->toDateTimeString(),
            'ends_at'=>$start->copy()->addHour()->utc()->toDateTimeString(),'status'=>'confirmed','payment_option'=>'full',
            'total_irr'=>100000,'required_irr'=>100000,'paid_irr'=>0,'commission_total_irr'=>25000,
            'duration_minutes'=>60,'hold_expires_at'=>null,'created_at'=>now(),'updated_at'=>now()]);
        return [$client,$counselorUser,$admin,$counselor,$booking,$start];
    }

    public function test_meet_connection_is_hidden_until_release_and_scheduler_is_idempotent(): void
    {
        [$client,$counselorUser,,,$booking,$start]=$this->confirmedMeetBooking();
        $clientToken=$client->createToken('client',['client'])->plainTextToken;
        $detail="/api/booking/my-reservations/$booking";
        $this->withSanctumToken($clientToken)->getJson($detail)->assertOk()
            ->assertJsonPath('data.connection_available',false)
            ->assertJsonMissingPath('data.session_connection')
            ->assertJsonMissingPath('data.session_instructions');
        $this->assertSame(0,DB::table('booking_in_app_notifications')->where('event_key','like','connection-release-%')->count());

        // At 09:00 Tehran the configured release threshold is exactly 09:10.
        Carbon::setTestNow(Carbon::parse('2032-10-01 09:00:00','Asia/Tehran'));
        $this->artisan('booking:maintain')->assertSuccessful();
        $this->withSanctumToken($clientToken)->getJson($detail)->assertOk()
            ->assertJsonPath('data.connection_available',true)
            ->assertJsonPath('data.session_connection','https://meet.google.com/old-room');
        $this->artisan('booking:maintain')->assertSuccessful();
        $this->assertSame(2,DB::table('booking_in_app_notifications')->where('event_key','like','connection-release-%')->count());
        $notification=DB::table('booking_in_app_notifications')->where('user_id',$client->id)
            ->where('event_key','like','connection-release-%')->first();
        $this->assertSame('https://meet.google.com/old-room',json_decode($notification->payload,true)['connection']);

        $counselorToken=$counselorUser->createToken('counselor',['counselor'])->plainTextToken;
        $this->withSanctumToken($counselorToken)->patchJson("/api/counselor/appointments/$booking/session-details",[
            'session_mode'=>'meet','connection'=>'https://meet.google.com/new-room','instructions'=>'Updated room',
        ])->assertOk();
        $this->assertSame(0,DB::table('booking_in_app_notifications')->where('event_key','like','connection-release-%')->count());
        $this->artisan('booking:maintain')->assertSuccessful();
        $notification=DB::table('booking_in_app_notifications')->where('user_id',$client->id)
            ->where('event_key','like','connection-release-%')->first();
        $this->assertSame('https://meet.google.com/new-room',json_decode($notification->payload,true)['connection']);

        $this->withSanctumToken($counselorToken)->patchJson("/api/counselor/appointments/$booking/session-details",[
            'session_mode'=>'voice_call','connection'=>'+982112345678','instructions'=>'Call the client',
        ])->assertOk();
        $this->assertSame(0,DB::table('booking_in_app_notifications')->where('event_key','like','connection-release-%')->count());
        $this->artisan('booking:maintain')->assertSuccessful();
        $notification=DB::table('booking_in_app_notifications')->where('user_id',$client->id)
            ->where('event_key','like','connection-release-%')->first();
        $payload=json_decode($notification->payload,true);
        $this->assertSame('voice_call',$payload['mode']);
        $this->assertSame('+982112345678',$payload['connection']);

        $clientToken=$client->createToken('client',['client'])->plainTextToken;
        $newStart=Carbon::parse('2032-10-01 10:10:00','Asia/Tehran');
        $this->withSanctumToken($clientToken)->patchJson("/api/booking/my-reservations/$booking/reschedule",[
            'starts_at'=>$newStart->toIso8601String(),
        ])->assertOk();
        $this->assertSame($newStart->copy()->utc()->toDateTimeString(),DB::table('bookings')->where('id',$booking)->value('starts_at'));
        $this->assertSame(0,DB::table('booking_in_app_notifications')->where('event_key','like','connection-release-%')->count());
        $this->assertSame('not_configured',DB::table('booking_notification_logs')->where('booking_id',$booking)
            ->where('channel','sms')->value('status'));
    }

    public function test_connection_release_still_runs_after_reception_check_in(): void
    {
        $fixture=$this->confirmedMeetBooking();
        $booking=$fixture[4];
        DB::table('bookings')->where('id',$booking)->update(['status'=>'checked_in']);
        Carbon::setTestNow(Carbon::parse('2032-10-01 09:00:00','Asia/Tehran'));
        $this->artisan('booking:maintain')->assertSuccessful();
        $this->assertSame(2,DB::table('booking_in_app_notifications')->where('event_key','like','connection-release-%')->count());
        $this->assertNotNull(DB::table('bookings')->where('id',$booking)->value('connection_release_notified_at'));
    }

    public function test_cancellation_removes_released_connection_notification_and_unrelated_users_cannot_read_it(): void
    {
        [$client,,$admin,,$booking]=$this->confirmedMeetBooking();
        Carbon::setTestNow(Carbon::parse('2032-10-01 09:00:00','Asia/Tehran'));
        $this->artisan('booking:maintain')->assertSuccessful();
        $foreign=User::create(['name'=>'Foreign client','email'=>'foreign@example.test','password'=>'password-123456']);
        $this->withSanctumToken($foreign->createToken('client',['client'])->plainTextToken)
            ->getJson("/api/booking/my-reservations/$booking")->assertNotFound();
        $adminToken=$admin->createToken('admin',['admin-panel'])->plainTextToken;
        $this->withSanctumToken($adminToken)->postJson("/api/admin/booking/reservations/$booking/cancel",['reason'=>'Client request'])
            ->assertOk();
        $this->assertSame(0,DB::table('booking_in_app_notifications')->where('event_key','like','connection-release-%')->count());
        $this->assertSame('cancelled',DB::table('bookings')->where('id',$booking)->value('status'));
    }

    public function test_failed_email_delivery_can_be_retried_without_marking_sms_delivered(): void
    {
        [$client,,,, $booking]=$this->confirmedMeetBooking();
        config(['mail.default'=>'array','booking.sms_driver'=>'log']);
        $notice=DB::table('booking_notification_logs')->insertGetId(['booking_id'=>$booking,'channel'=>'email',
            'recipient'=>'counselor','event'=>'delivery-retry-test','status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
        try { (new SendBookingNotice($notice))->handle(); } catch(\RuntimeException) {}
        $this->assertSame('failed',DB::table('booking_notification_logs')->where('id',$notice)->value('status'));
        DB::table('booking_notification_logs')->where('id',$notice)->update(['recipient'=>'client']);
        (new SendBookingNotice($notice))->handle();
        $this->assertSame('sent',DB::table('booking_notification_logs')->where('id',$notice)->value('status'));

        app(\App\Services\BookingNotices::class)->queue($booking,'sms-not-configured-test','client');
        $this->assertSame('not_configured',DB::table('booking_notification_logs')->where('booking_id',$booking)
            ->where('event','sms-not-configured-test')->value('status'));
    }

    public function test_missing_online_connection_alerts_reception_but_in_person_does_not(): void
    {
        [,$counselorUser,$admin,,$booking]=$this->confirmedMeetBooking();
        Carbon::setTestNow(Carbon::parse('2032-10-01 09:00:00','Asia/Tehran'));
        DB::table('bookings')->where('id',$booking)->update(['session_connection'=>null]);
        $this->artisan('booking:maintain')->assertSuccessful();
        $alerts=DB::table('booking_in_app_notifications')->where('user_id',$admin->id)
            ->where('event_key','like','missing-connection-%')->get();
        $this->assertCount(1,$alerts);
        $this->artisan('booking:maintain')->assertSuccessful();
        $this->assertSame(1,DB::table('booking_in_app_notifications')->where('user_id',$admin->id)
            ->where('event_key','like','missing-connection-%')->count());

        DB::table('booking_in_app_notifications')->where('event_key','like','missing-connection-%')->delete();
        DB::table('bookings')->where('id',$booking)->update(['session_mode'=>'in_person','connection_release_notified_at'=>null]);
        $this->artisan('booking:maintain')->assertSuccessful();
        $this->assertSame(0,DB::table('booking_in_app_notifications')->where('event_key','like','missing-connection-%')->count());
    }
}
