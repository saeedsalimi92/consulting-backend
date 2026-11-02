<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_sms_reminder_rules', function (Blueprint $t) {
            $t->id(); $t->string('name',120); $t->string('timing_type',20); $t->unsignedInteger('minutes_before')->nullable();
            $t->time('local_time')->nullable(); $t->json('recipients'); $t->json('modes')->nullable(); $t->text('template');
            $t->time('quiet_start')->nullable(); $t->time('quiet_end')->nullable(); $t->unsignedTinyInteger('max_attempts')->default(3);
            $t->unsignedInteger('retry_delay_seconds')->default(300); $t->boolean('is_active')->default(false); $t->timestamps();
            $t->index(['is_active','timing_type']);
        });
        Schema::create('booking_sms_reminders', function (Blueprint $t) {
            $t->id(); $t->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $t->foreignId('rule_id')->constrained('booking_sms_reminder_rules')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete(); $t->timestamp('appointment_starts_at');
            $t->timestamp('scheduled_at'); $t->timestamp('available_at')->nullable(); $t->string('recipient_type',20); $t->string('status',20)->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0); $t->unsignedTinyInteger('retry_attempts')->default(0); $t->timestamp('last_attempt_at')->nullable();
            $t->timestamp('sent_at')->nullable(); $t->string('provider',40)->nullable(); $t->string('provider_reference',180)->nullable();
            $t->text('last_error')->nullable(); $t->json('provider_response')->nullable(); $t->timestamps();
            $t->unique(['booking_id','rule_id','user_id','appointment_starts_at'],'booking_sms_reminder_once');
            $t->index(['status','scheduled_at']);
        });
        Schema::create('user_notification_preferences', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $t->boolean('sms_enabled')->default(false); $t->timestamp('sms_consent_at')->nullable(); $t->timestamps();
        });
        Schema::create('booking_sms_delivery_attempts',function(Blueprint $t){$t->id();$t->foreignId('reminder_id')->constrained('booking_sms_reminders')->cascadeOnDelete();$t->unsignedTinyInteger('attempt_number');$t->string('provider',40)->nullable();$t->string('status',20);$t->string('provider_reference',180)->nullable();$t->json('provider_response')->nullable();$t->string('error_code',120)->nullable();$t->timestamp('attempted_at');$t->timestamp('completed_at')->nullable();$t->timestamps();$t->unique(['reminder_id','attempt_number']);});
        Schema::create('appointment_room_unavailability', function (Blueprint $t) {
            $t->id(); $t->foreignId('room_id')->constrained('appointment_rooms')->cascadeOnDelete();
            $t->timestamp('starts_at'); $t->timestamp('ends_at'); $t->string('reason',255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps();
            $t->index(['room_id','starts_at','ends_at']);
        });
        Schema::create('center_holidays', function (Blueprint $t) {
            $t->id(); $t->date('holiday_date')->unique(); $t->string('title',180)->nullable();
            $t->boolean('is_open')->default(false); $t->time('opens_at')->nullable(); $t->time('closes_at')->nullable(); $t->timestamps();
        });
        DB::table('booking_sms_reminder_rules')->insert([
            ['name'=>'۲۴ ساعت قبل','timing_type'=>'relative','minutes_before'=>1440,'local_time'=>null,'recipients'=>'["client"]','modes'=>null,'template'=>'یادآوری نوبت {{appointment_date}} ساعت {{appointment_time}} با {{counselor_name}}. کد پیگیری {{booking_reference}}','is_active'=>false,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'صبح روز نوبت','timing_type'=>'fixed_local','minutes_before'=>null,'local_time'=>'08:00:00','recipients'=>'["client"]','modes'=>null,'template'=>'یادآوری نوبت امروز ساعت {{appointment_time}} با {{counselor_name}}. کد پیگیری {{booking_reference}}','is_active'=>false,'created_at'=>now(),'updated_at'=>now()],
        ]);
    }
    public function down(): void
    {
        Schema::dropIfExists('center_holidays'); Schema::dropIfExists('appointment_room_unavailability');Schema::dropIfExists('booking_sms_delivery_attempts');
        Schema::dropIfExists('user_notification_preferences'); Schema::dropIfExists('booking_sms_reminders');
        Schema::dropIfExists('booking_sms_reminder_rules');
    }
};
