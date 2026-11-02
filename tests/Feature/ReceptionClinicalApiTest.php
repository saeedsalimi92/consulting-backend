<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SendBookingNotice;
use Tests\TestCase;

class ReceptionClinicalApiTest extends TestCase
{
    use RefreshDatabase;

    private function setupClientBooking(): array
    {
        $client = User::create(['name' => 'Clinical Client','first_name'=>'Clinical','last_name'=>'Client','phone'=>'+989125555555','phone_verified_at'=>now(), 'email' => 'clinical-client@example.test', 'password' => 'long-password-123']);
        $admin = User::create(['name' => 'Reception', 'email' => 'reception@example.test', 'password' => 'long-password-123',
            'admin_permissions' => ['appointments.manage']]);
        $clinicalAdmin = User::create(['name' => 'Clinical Admin', 'email' => 'clinical-admin@example.test', 'password' => 'long-password-123',
            'admin_permissions' => ['notes.manage']]);
        $counselorUser = User::create(['name' => 'Counselor Account', 'email' => 'counselor@example.test', 'password' => 'long-password-123']);
        $counselor = DB::table('counselors')->insertGetId(['name' => 'Clinical Counselor', 'slug' => 'clinical-counselor',
            'is_active' => true, 'user_id' => $counselorUser->id, 'created_at' => now(), 'updated_at' => now()]);
        $service = DB::table('booking_services')->insertGetId(['title' => 'Clinical Service', 'duration_minutes' => 60,
            'price_irr' => 1000000, 'deposit_irr' => 300000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('counselor_booking_services')->insert(['counselor_id' => $counselor, 'booking_service_id' => $service,
            'commission_type' => 'percentage', 'commission_value' => 25]);
        $day = now('Asia/Tehran')->addDays(5)->startOfDay();
        DB::table('counselor_working_hours')->insert(['counselor_id' => $counselor, 'weekday' => $day->dayOfWeek,
            'starts_at' => '09:00:00', 'ends_at' => '15:00:00', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $start = $day->copy()->addHours(10)->utc();
        $booking = DB::table('bookings')->insertGetId(['user_id' => $client->id, 'created_by' => $client->id,
            'counselor_id' => $counselor, 'booking_service_id' => $service, 'mode' => 'online', 'session_mode' => 'meet',
            'starts_at' => $start->toDateTimeString(), 'ends_at' => $start->copy()->addHour()->toDateTimeString(),
            'status' => 'confirmed', 'payment_option' => 'full', 'total_irr' => 1000000, 'required_irr' => 1000000,
            'paid_irr' => 1000000, 'commission_total_irr' => 250000, 'duration_minutes' => 60, 'created_at' => now(), 'updated_at' => now()]);
        return compact('client', 'admin', 'clinicalAdmin', 'counselorUser', 'counselor', 'service', 'day', 'start', 'booking');
    }

    public function test_room_conflicts_and_reception_check_in_lifecycle(): void
    {
        config(['booking.payment_driver' => 'fake_bank']);
        $data = $this->setupClientBooking();
        $admin = $data['admin'];
        $token = $admin->createToken('admin-panel', ['admin-panel'])->plainTextToken;
        $room = DB::table('appointment_rooms')->insertGetId(['name' => 'Room 1', 'capacity' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $secondCounselor = DB::table('counselors')->insertGetId(['name' => 'Second Counselor', 'slug' => 'second-counselor',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('counselor_booking_services')->insert(['counselor_id' => $secondCounselor, 'booking_service_id' => $data['service'],
            'commission_type' => 'percentage', 'commission_value' => 20]);
        DB::table('counselor_working_hours')->insert(['counselor_id' => $secondCounselor, 'weekday' => $data['day']->dayOfWeek,
            'starts_at' => '09:00:00', 'ends_at' => '15:00:00', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['user_id' => $data['client']->id, 'counselor_id' => $data['counselor'], 'service_id' => $data['service'],
            'starts_at' => $data['day']->copy()->addHours(12)->toIso8601String(), 'mode' => 'in_person', 'room_id' => $room, 'payment_option' => 'full'];
        $this->withSanctumToken($token)->postJson('/api/admin/booking/reservations', [...$payload,'attendee_count'=>2])->assertUnprocessable();
        $localStart=$data['day']->copy()->addHours(11)->addMinutes(50);DB::table('appointment_room_unavailability')->insert(['room_id'=>$room,'starts_at'=>$localStart->copy()->utc()->toDateTimeString(),
            'ends_at'=>$localStart->copy()->addMinutes(90)->utc()->toDateTimeString(),'reason'=>'maintenance','created_at'=>now(),'updated_at'=>now()]);
        $this->withSanctumToken($token)->postJson('/api/admin/booking/reservations',$payload)->assertUnprocessable();
        DB::table('appointment_room_unavailability')->where('room_id',$room)->delete();
        $created = $this->withSanctumToken($token)->postJson('/api/admin/booking/reservations', $payload)->assertCreated()->json('data');
        $conflicting = [...$payload, 'counselor_id' => $secondCounselor];
        $this->withSanctumToken($token)->postJson('/api/admin/booking/reservations', $conflicting)->assertUnprocessable();
        DB::table('bookings')->where('id', $created['id'])->update(['status' => 'confirmed']);
        $this->withSanctumToken($token)->postJson('/api/admin/booking/reservations/'.$created['id'].'/check-in')->assertOk()
            ->assertJsonPath('data.status', 'checked_in');
        $this->withSanctumToken($token)->patchJson('/api/admin/booking/reservations/'.$created['id'].'/status', ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
        $calendar = $this->withSanctumToken($token)->getJson('/api/admin/booking/calendar?date='.$data['day']->toDateString().'&room_id='.$room)
            ->assertOk()->json('data');
        $this->assertCount(1, $calendar);
    }

    public function test_room_working_hours_are_enforced_and_change_preserves_bookings():void
    {
        $data=$this->setupClientBooking();$token=$data['admin']->createToken('admin',['admin-panel'])->plainTextToken;
        $room=DB::table('appointment_rooms')->insertGetId(['name'=>'Scheduled room','capacity'=>2,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $path='/api/admin/booking/rooms/'.$room.'/working-hours';
        $this->withToken($token)->putJson($path,['hours'=>[['weekday'=>$data['day']->dayOfWeek,'opens_at'=>'09:00','closes_at'=>'15:00']]])->assertOk();
        DB::table('bookings')->where('id',$data['booking'])->update(['room_id'=>$room,'mode'=>'in_person','session_mode'=>'in_person']);
        $outside=$data['day']->copy()->setTime(16,0)->toIso8601String();$outsideEnd=$data['day']->copy()->setTime(17,0)->toIso8601String();
        $this->withToken($token)->getJson('/api/admin/booking/rooms/'.$room.'/availability?starts_at='.urlencode($outside).'&ends_at='.urlencode($outsideEnd))
            ->assertOk()->assertJsonPath('data.outside_working_hours',true)->assertJsonPath('data.available',false);
        $this->withToken($token)->putJson($path,['hours'=>[['weekday'=>$data['day']->dayOfWeek,'opens_at'=>'12:00','closes_at'=>'15:00']]])->assertStatus(409);
    }

    public function test_intake_form_answers_consent_and_reception_clinical_isolation(): void
    {
        $data = $this->setupClientBooking();
        $clinicalToken = $data['clinicalAdmin']->createToken('admin-panel', ['admin-panel'])->plainTextToken;
        $receptionToken = $data['admin']->createToken('admin-panel', ['admin-panel'])->plainTextToken;
        $form = $this->withSanctumToken($clinicalToken)->postJson('/api/admin/booking/clinical/forms', ['title' => 'Intake', 'key' => 'initial-intake',
            'schema' => [['key' => 'concern', 'label' => 'What brings you here?', 'type' => 'textarea', 'required' => true]]])
            ->assertCreated()->json('data.id');
        $clientToken = $data['client']->createToken('client', ['client'])->plainTextToken;
        $this->withSanctumToken($clientToken)->postJson('/api/booking/intake-forms/'.$form.'/answers', ['answers' => ['concern' => 'Anxiety']])->assertCreated();
        $this->withSanctumToken($clientToken)->postJson('/api/client/consents', ['consent_key' => 'privacy', 'version' => 1, 'accepted' => true])->assertCreated();
        $this->withSanctumToken($clinicalToken)->getJson('/api/admin/booking/clinical/clients/'.$data['client']->id.'/intake-answers')->assertOk();
        $this->withSanctumToken($clinicalToken)->getJson('/api/admin/booking/clinical/clients/'.$data['client']->id.'/consents')->assertOk();
        $this->withSanctumToken($receptionToken)->getJson('/api/admin/booking/clinical/clients/'.$data['client']->id)->assertForbidden();
    }

    public function test_session_notes_and_private_attachments_are_scoped_to_assigned_counselor(): void
    {
        $data = $this->setupClientBooking();
        $counselorToken = $data['counselorUser']->createToken('counselor', ['counselor'])->plainTextToken;
        $note = $this->withSanctumToken($counselorToken)->postJson('/api/counselor/appointments/'.$data['booking'].'/notes', [
            'body' => 'Session draft', 'status' => 'draft', 'outcomes' => ['Initial assessment'], 'treatment_progress' => 'Getting started',
        ])->assertCreated()->json('data');
        $this->assertSame('draft', $note['status']);
        $this->withSanctumToken($counselorToken)->getJson('/api/counselor/session-notes/'.$note['id'].'/versions')->assertOk()
            ->assertJsonPath('data.0.version', 1)->assertJsonPath('data.0.author_id', $data['counselorUser']->id);
        $this->withSanctumToken($counselorToken)->patchJson('/api/counselor/session-notes/'.$note['id'], ['body' => 'Updated draft'])->assertOk();
        $this->withSanctumToken($counselorToken)->getJson('/api/counselor/session-notes/'.$note['id'].'/versions/2')->assertOk()
            ->assertJsonPath('data.snapshot.body', 'Updated draft')->assertJsonPath('data.changed_fields.0', 'body');
        $this->withSanctumToken($counselorToken)->patchJson('/api/counselor/session-notes/'.$note['id'], ['status' => 'finalized'])->assertOk();
        $this->withSanctumToken($counselorToken)->patchJson('/api/counselor/session-notes/'.$note['id'], ['body' => 'Late edit'])->assertStatus(409);
        Storage::fake('local');
        $attachment = $this->withSanctumToken($counselorToken)->post('/api/counselor/clients/'.$data['client']->id.'/attachments', [
            'file' => UploadedFile::fake()->create('intake.pdf', 20, 'application/pdf'),
        ])->assertCreated()->json('data');
        $this->withSanctumToken($counselorToken)->get('/api/counselor/clinical/attachments/'.$attachment['id'])->assertOk();

        $otherUser = User::create(['name' => 'Other counselor', 'email' => 'other@example.test', 'password' => 'long-password-123']);
        DB::table('counselors')->insert(['name' => 'Other Counselor', 'slug' => 'other-counselor', 'user_id' => $otherUser->id,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $otherToken = $otherUser->createToken('counselor', ['counselor'])->plainTextToken;
        $this->withSanctumToken($otherToken)->getJson('/api/counselor/appointments/'.$data['booking'].'/notes')->assertNotFound();
        $this->withSanctumToken($otherToken)->get('/api/counselor/clinical/attachments/'.$attachment['id'])->assertNotFound();
    }

    public function test_promo_redemption_is_snapshotted_and_cannot_be_reused_by_same_client(): void
    {
        $data = $this->setupClientBooking();
        $finance = User::create(['name' => 'Finance', 'email' => 'finance-promo@example.test', 'password' => 'long-password-123',
            'admin_permissions' => ['finance.manage']]);
        $financeToken = $finance->createToken('admin-panel', ['admin-panel'])->plainTextToken;
        $promo = $this->withSanctumToken($financeToken)->postJson('/api/admin/booking/promo-codes', [
            'code' => 'welcome10', 'discount_type' => 'percentage', 'discount_value' => 1000,
            'max_redemptions' => 10, 'max_per_client' => 1,
        ])->assertCreated()->json('data');
        $clientToken = $data['client']->createToken('client', ['client'])->plainTextToken;
        $payload = ['counselor_id' => $data['counselor'], 'service_id' => $data['service'],
            'starts_at' => $data['day']->copy()->addHours(11)->toIso8601String(), 'mode' => 'online',
            'payment_option' => 'full', 'promo_code' => strtolower($promo['code'])];
        $created = $this->withSanctumToken($clientToken)->postJson('/api/booking/reservations', $payload)->assertCreated()->json('data');
        $this->assertSame(900000, (int) $created['total_irr']);
        $this->assertSame(100000, (int) $created['discount_irr']);
        $this->withSanctumToken($clientToken)->postJson('/api/booking/reservations', [...$payload,
            'starts_at' => $data['day']->copy()->addHours(12)->toIso8601String()])->assertUnprocessable();
    }

    public function test_partial_refund_does_not_cancel_booking(): void
    {
        $data = $this->setupClientBooking();
        $finance = User::create(['name' => 'Refund Staff', 'email' => 'finance-refund@example.test', 'password' => 'long-password-123',
            'admin_permissions' => ['finance.manage']]);
        $token = $finance->createToken('admin-panel', ['admin-panel'])->plainTextToken;
        $refund = $this->withSanctumToken($token)->postJson('/api/admin/booking/reservations/'.$data['booking'].'/refunds', [
            'amount_irr' => 300000, 'reason' => 'Partial service refund',
        ])->assertCreated()->json('data');
        $this->withSanctumToken($token)->patchJson('/api/admin/booking/refunds/'.$refund['id'], ['decision' => 'approved'])->assertOk();
        $this->withSanctumToken($token)->postJson('/api/admin/booking/refunds/'.$refund['id'].'/record-transfer', ['reference' => 'partial-refund-1'])->assertOk();
        $this->assertSame('confirmed', DB::table('bookings')->where('id', $data['booking'])->value('status'));
        $this->assertSame(300000, (int) DB::table('booking_refunds')->where('id', $refund['id'])->value('refund_amount_irr'));
    }

    public function test_waitlist_notification_can_be_assigned_to_a_real_reservation(): void
    {
        $data = $this->setupClientBooking();
        $waitlistId = DB::table('booking_waitlist')->insertGetId(['user_id' => $data['client']->id,
            'counselor_id' => $data['counselor'], 'booking_service_id' => $data['service'],
            'starts_at' => $data['day']->copy()->addHours(12)->utc()->toDateTimeString(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('booking:maintain')->assertExitCode(0);
        $this->assertSame('notified', DB::table('booking_waitlist')->where('id', $waitlistId)->value('status'));
        $token = $data['admin']->createToken('admin-panel', ['admin-panel'])->plainTextToken;
        $assigned = $this->withSanctumToken($token)->patchJson('/api/admin/booking/waitlist/'.$waitlistId, [])->assertCreated()->json('data');
        $this->assertSame($assigned['id'], (int) DB::table('booking_waitlist')->where('id', $waitlistId)->value('assigned_booking_id'));
        $this->assertSame('assigned', DB::table('booking_waitlist')->where('id', $waitlistId)->value('status'));
    }

    public function test_failed_email_can_be_retried_and_templates_are_managed_without_real_sms(): void
    {
        $data = $this->setupClientBooking();
        $token = $data['admin']->createToken('admin-panel', ['admin-panel'])->plainTextToken;
        $this->withSanctumToken($token)->postJson('/api/admin/booking/notification-templates', [
            'event' => 'appointment.reminder', 'channel' => 'email', 'subject' => 'Reminder {{booking_id}}',
            'body' => 'Starts {{starts_at}} {{timezone}}',
        ])->assertCreated();
        $this->withSanctumToken($token)->postJson('/api/admin/booking/notification-templates', [
            'event' => 'appointment.reminder', 'channel' => 'sms', 'body' => 'SMS disabled',
        ])->assertCreated();
        $notice = DB::table('booking_notification_logs')->insertGetId(['booking_id' => $data['booking'], 'channel' => 'email',
            'recipient' => 'client', 'event' => 'appointment.reminder', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
        Queue::fake();
        $this->withSanctumToken($token)->postJson('/api/admin/booking/notifications/'.$notice.'/retry')->assertAccepted();
        Queue::assertPushed(SendBookingNotice::class);
        $this->assertSame('pending', DB::table('booking_notification_logs')->where('id', $notice)->value('status'));
    }

    public function test_capacity_report_uses_tehran_dates_and_csv_export(): void
    {
        $data = $this->setupClientBooking();
        $finance = User::create(['name' => 'Report Reader', 'email' => 'report-reader@example.test', 'password' => 'long-password-123',
            'admin_permissions' => ['finance.view']]);
        $token = $finance->createToken('admin-panel', ['admin-panel'])->plainTextToken;
        $date = $data['day']->toDateString();
        $report = $this->withSanctumToken($token)->getJson('/api/admin/booking/reports?from='.$date.'&to='.$date.'&counselor_id='.$data['counselor'])
            ->assertOk()->json('data');
        $this->assertSame(360, (int) $report['scheduled_capacity_minutes']);
        $this->assertSame(16.67, (float) $report['capacity_utilization_percent']);
        $this->withSanctumToken($token)->get('/api/admin/booking/reports/export?from='.$date.'&to='.$date)->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_room_calendar_deactivation_and_delete_preserve_booking_history(): void
    {
        $data = $this->setupClientBooking();
        $token = $data['admin']->createToken('admin-panel', ['admin-panel'])->plainTextToken;
        $room = DB::table('appointment_rooms')->insertGetId(['name' => 'History Room', 'capacity' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bookings')->where('id', $data['booking'])->update(['room_id' => $room]);
        $date = $data['day']->toDateString();
        $this->withSanctumToken($token)->getJson('/api/admin/booking/rooms/'.$room.'/calendar?from='.$date.'&to='.$date)->assertOk()
            ->assertJsonPath('data.data.0.booking_id', $data['booking']);
        $this->withSanctumToken($token)->getJson('/api/admin/booking/rooms/'.$room.'/availability?starts_at='.rawurlencode($data['start']->toIso8601String()).'&ends_at='.rawurlencode($data['start']->copy()->addHour()->toIso8601String()))
            ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.conflict', true);
        $this->withSanctumToken($token)->patchJson('/api/admin/booking/rooms/'.$room, ['is_active' => false])->assertStatus(409);
        $this->withSanctumToken($token)->deleteJson('/api/admin/booking/rooms/'.$room)->assertStatus(409);
        $this->assertDatabaseHas('appointment_rooms', ['id' => $room]);
    }

    public function test_operational_capacity_excludes_time_off(): void
    {
        $data = $this->setupClientBooking();
        DB::table('counselor_time_off')->insert(['counselor_id' => $data['counselor'],
            'starts_at' => $data['day']->copy()->addHours(11)->utc()->toDateTimeString(),
            'ends_at' => $data['day']->copy()->addHours(12)->utc()->toDateTimeString(), 'reason' => 'Leave', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('appointment_rooms')->insert(['name' => 'Capacity room', 'capacity' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $token = $data['admin']->createToken('admin-panel', ['admin-panel'])->plainTextToken;
        $date = $data['day']->toDateString();
        $this->withSanctumToken($token)->getJson('/api/admin/booking/reports/operational?from='.$date.'&to='.$date)->assertOk()
            ->assertJsonPath('data.counselor_capacity_minutes', 300)
            ->assertJsonPath('data.room_constrained_capacity_minutes', 300)
            ->assertJsonPath('data.utilized_minutes', 60)
            ->assertJsonPath('data.counselors.0.utilization_percent', 20);
    }

    public function test_private_cms_media_is_public_only_when_linked_to_published_content(): void
    {
        $root = User::create(['name' => 'CMS admin', 'email' => 'cms-media@example.test', 'password' => 'long-password-123', 'is_admin' => true]);
        Storage::fake('local');
        $token = $root->createToken('admin-panel', ['admin-panel'])->plainTextToken;
        $upload = $this->withSanctumToken($token)->post('/api/admin/gallery/upload', ['image' => UploadedFile::fake()->image('banner.png')])
            ->assertCreated()->json('data');
        $this->get('/api/content-media/'.$upload['id'])->assertNotFound();
        $draft = $this->withSanctumToken($token)->postJson('/api/admin/content/banners', [
            'title' => 'Draft banner', 'slug' => 'draft-banner', 'image' => $upload['path'], 'status' => 'draft',
        ])->assertCreated()->json('data');
        $this->get('/api/content-media/'.$upload['id'])->assertNotFound();
        $this->withSanctumToken($token)->putJson('/api/admin/content/banners/'.$draft['id'], ['status' => 'published'])->assertOk();
        $this->get('/api/content-media/'.$upload['id'])->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        Storage::disk('local')->assertExists(DB::table('cms_private_media')->where('id', $upload['id'])->value('path'));
    }
}
